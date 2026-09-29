<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubAccount;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;
use OCA\Verein\L10n\DocumentL10n;

/**
 * Service for generating SEPA-XML files for direct debit
 * Based on SEPA pain.008.001.02 format
 *
 * Works for one club at a time: the creditor (name, IBAN, BIC, creditor ID)
 * comes from one of the club's bank accounts, the debtors from the club's
 * open fees, and each debtor's mandate (reference + signature date) from
 * their membership in that club.
 */
class SepaService {
    private IL10N $l;
    private IL10N $doc;

    public function __construct(
        private FeeMapper $feeMapper,
        private MemberMapper $memberMapper,
        private MembershipMapper $membershipMapper,
        private ClubMapper $clubMapper,
        private ClubService $clubService,
        private ?Clock $clock = null,
        ?IL10N $l10n = null,
        ?DocumentL10n $documentL10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
        $this->doc = $documentL10n?->get() ?? $this->l;
        $this->validation = new ValidationService($l10n);
    }

    /** Characters the EPC allows in SEPA text fields (Latin character set of the SEPA rulebooks). */
    private const TEXT_ALLOWED = "/[^A-Za-z0-9\\/\\-?:().,'+ ]/";
    /** Identifiers (mandate reference) additionally must not start or end with "/" or contain "//". */
    private const ID_PATTERN = "#^(?!/)(?!.*//)[A-Za-z0-9/\\-?:().,'+ ]{1,35}(?<!/)$#";
    private const BIC_PATTERN = '/^[A-Z]{6}[A-Z2-9][A-NP-Z0-9]([A-Z0-9]{3})?$/';
    /** Business days (TARGET2) between today and the requested collection date: CORE needs D-1, banks often more. */
    private const LEAD_DAYS = 5;
    /**
     * Fees due up to this many calendar days after the earliest collection date are collected on their due date
     * (own payment block); later ones stay open for a later export - banks do not take collections far ahead.
     */
    private const MAX_DAYS_AHEAD = 14;

    private ValidationService $validation;

    /**
     * Generate SEPA-XML for all open fees of a club
     *
     * @param int|null $accountId Club bank account to collect on (default account if null)
     * @return array{xml: string, skippedCount: int, feeIds: int[]} XML, number of fees left out and ids of the fees in the file
     *   (no IBAN / no signed mandate / invalid data) - see previewSepaExport() for who and why
     */
    public function generateSepaXml(int $clubId, ?int $accountId = null): array {
        $club = $this->clubMapper->find($clubId);
        $account = $this->clubService->resolveAccount($clubId, $accountId);
        if (trim((string)$account->getCreditorId()) === '') {
            throw new ValidationException($this->l->t('No creditor ID is set for the bank account (“Club” tab)'));
        }
        if (!$this->validation->validateCreditorId(self::compact($account->getCreditorId()))) {
            throw new ValidationException($this->l->t('The creditor ID of the bank account is invalid (“Club” tab)'));
        }
        if (!$this->validation->validateIBAN(self::compact($account->getIban()))) {
            throw new ValidationException($this->l->t('The IBAN of the bank account is invalid (“Club” tab)'));
        }
        $bic = self::compact($account->getBic());
        if ($bic !== '' && !preg_match(self::BIC_PATTERN, $bic)) {
            throw new ValidationException($this->l->t('The BIC of the bank account is invalid (“Club” tab)'));
        }

        $collected = $this->collectFees($clubId);

        if (empty($collected['transactions'])) {
            if (!empty($collected['skipped'])) {
                $names = implode(', ', array_map(
                    fn($s) => $s['memberName'] . ' (' . $s['reason'] . ')',
                    $collected['skipped']
                ));
                throw new ValidationException($this->l->t('No fee can be exported. Not included: %s', [$names]));
            }
            throw new ValidationException($this->l->t('No unpaid or overdue fees for the SEPA export'));
        }

        return [
            'xml' => $this->buildSepaXml(
                $clubId,
                $club->getName(),
                $account,
                $collected['totalCents'],
                $collected['transactions']
            ),
            'skippedCount' => count($collected['skipped']),
            'feeIds' => array_column($collected['transactions'], 'feeId')
        ];
    }

    /**
     * Preview SEPA export without generating XML
     */
    public function previewSepaExport(int $clubId, ?int $accountId = null): array {
        $club = $this->clubMapper->find($clubId);
        $account = $this->clubService->resolveAccount($clubId, $accountId);
        $collected = $this->collectFees($clubId);

        return [
            'creditorName' => $club->getName(),
            'creditorIban' => $account->getIban(),
            'creditorBic' => $account->getBic(),
            'creditorId' => $account->getCreditorId(),
            'collectionDate' => $collected['earliestDate'],
            'totalAmount' => $collected['totalCents'] / 100,
            'transactionCount' => count($collected['transactions']),
            'transactions' => array_map(function (array $t) {
                unset($t['cents']);
                return $t;
            }, $collected['transactions']),
            'skipped' => $collected['skipped']
        ];
    }

    /**
     * Collect all fees of the club that still need to be debited (open and
     * overdue - overdue is a manually-set status, not an automatic
     * transition, so it still represents money owed that hasn't been
     * debited yet).
     *
     * A fee can only be debited if the member has a valid IBAN and a signed
     * mandate (signature date on their membership) and the data fits what
     * the banks accept. The others are returned in 'skipped' with the
     * reason, so callers can tell the user instead of silently dropping
     * them - a single bad record would otherwise make the bank reject the
     * whole file.
     *
     * A fee is never collected before it is due: if its due date is after the
     * earliest possible collection date, it is collected on its due date (or the
     * next TARGET2 day), as long as that is at most MAX_DAYS_AHEAD days later;
     * fees due even later are skipped as "not due yet".
     *
     * @return array{transactions: array, skipped: array, totalCents: int, earliestDate: string}
     */
    private function collectFees(int $clubId): array {
        $totalCents = 0;
        $transactions = [];
        $skipped = [];
        $today = Clock::todayOf($this->clock);
        $earliest = $this->collectionDate();
        $latestDue = (new \DateTimeImmutable($earliest))->modify('+' . self::MAX_DAYS_AHEAD . ' days')->format('Y-m-d');

        foreach ($this->feeMapper->findByStatusesInClub(['open', 'overdue'], $clubId) as $fee) {
            $member = $this->memberMapper->find($fee->getMemberId());

            try {
                $membership = $this->membershipMapper->findByMemberAndClub($fee->getMemberId(), $clubId);
            } catch (DoesNotExistException $e) {
                $membership = null;
            }

            $iban = self::compact($member->getIban());
            $bic = self::compact($member->getBic());
            $cents = (int)round(((float)$fee->getAmount()) * 100);
            $mandateDate = $membership !== null ? substr(trim((string)$membership->getMandateDate()), 0, 10) : '';
            // missing or unreadable due date: collect as soon as possible, as before
            $due = substr(trim((string)$fee->getDueDate()), 0, 10);
            $collectionDate = (self::isDate($due) && $due > $earliest) ? self::targetDayFrom($due) : $earliest;

            $reason = null;
            if ($membership !== null && $membership->getDeactivated()) {
                $reason = $this->l->t('member deactivated');
            } elseif ($cents <= 0) {
                $reason = $this->l->t('amount is zero or negative');
            } elseif ($iban === '') {
                $reason = $this->l->t('no IBAN recorded');
            } elseif (!$this->validation->validateIBAN($iban)) {
                $reason = $this->l->t('IBAN is invalid');
            } elseif ($bic !== '' && !preg_match(self::BIC_PATTERN, $bic)) {
                $reason = $this->l->t('BIC is invalid');
            } elseif ($membership === null || $mandateDate === '') {
                $reason = $this->l->t('no signed SEPA mandate recorded');
            } elseif (!self::isDate($mandateDate)) {
                $reason = $this->l->t('date of signature of the mandate is invalid');
            } elseif ($mandateDate > $today) {
                $reason = $this->l->t('date of signature of the mandate is in the future');
            } elseif (!preg_match(self::ID_PATTERN, $membership->getEffectiveMandateReference())) {
                $reason = $this->l->t('mandate reference contains characters not allowed in SEPA (max. 35 letters, digits and simple special characters)');
            } elseif ($due > $latestDue && self::isDate($due)) {
                $reason = $this->l->t('not due yet');
            }

            if ($reason !== null) {
                $skipped[] = [
                    'memberName' => $member->getFullName(),
                    'amount' => $fee->getAmount(),
                    'dueDate' => $fee->getDueDate(),
                    'reason' => $reason
                ];
                continue;
            }

            $totalCents += $cents;
            $transactions[] = [
                'name' => $member->getFullName(),
                'memberName' => $member->getFullName(),
                'iban' => $iban,
                'bic' => $bic,
                'amount' => $cents / 100,
                'cents' => $cents,
                'dueDate' => $fee->getDueDate(),
                'collectionDate' => $collectionDate,
                'mandateReference' => $membership->getEffectiveMandateReference(),
                'mandateDate' => $mandateDate,
                'reference' => $this->paymentReference($fee->getDescription(), $fee->getDueDate()),
                'feeId' => $fee->getId()
            ];
        }

        return ['transactions' => $transactions, 'skipped' => $skipped, 'totalCents' => $totalCents, 'earliestDate' => $earliest];
    }

    private function paymentReference(?string $description, string $dueDate): string {
        $description = trim((string)$description);
        if ($description !== '') {
            return $description;
        }
        $year = substr($dueDate, 0, 4);
        return $this->doc->t('Membership fee %s', [ctype_digit($year) ? $year : Clock::nowOf($this->clock)->format('Y')]);
    }

    /** IBAN, BIC and creditor ID as the bank expects them: no spaces, upper case. */
    private static function compact(?string $value): string {
        return strtoupper((string)preg_replace('/\s+/', '', (string)$value));
    }

    private static function isDate(string $value): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /**
     * Requested collection date: LEAD_DAYS TARGET2 business days from today (no weekends,
     * New Year, Good Friday, Easter Monday, 1 May, 25/26 December) - banks reject or
     * shift a date on which no settlement takes place.
     */
    private function collectionDate(): string {
        $day = new \DateTimeImmutable(Clock::todayOf($this->clock));
        for ($left = self::LEAD_DAYS; $left > 0;) {
            $day = $day->modify('+1 day');
            if (self::isTargetDay($day)) {
                $left--;
            }
        }
        return $day->format('Y-m-d');
    }

    /** The given date if it is a TARGET2 business day, otherwise the next one. */
    private static function targetDayFrom(string $date): string {
        $day = new \DateTimeImmutable($date);
        while (!self::isTargetDay($day)) {
            $day = $day->modify('+1 day');
        }
        return $day->format('Y-m-d');
    }

    private static function isTargetDay(\DateTimeImmutable $day): bool {
        if ((int)$day->format('N') >= 6) {
            return false;
        }
        $md = $day->format('m-d');
        if (in_array($md, ['01-01', '05-01', '12-25', '12-26'], true)) {
            return false;
        }
        $easter = self::easterSunday((int)$day->format('Y'));
        $date = $day->format('Y-m-d');
        return $date !== $easter->modify('-2 days')->format('Y-m-d')
            && $date !== $easter->modify('+1 day')->format('Y-m-d');
    }

    /** Gregorian Easter Sunday (anonymous Gregorian algorithm; no dependency on ext-calendar). */
    private static function easterSunday(int $year): \DateTimeImmutable {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $dayOfMonth = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $dayOfMonth));
    }

    /**
     * BIC element, or the SEPA-conformant "not provided" marker: within the
     * SEPA area the BIC is optional (IBAN-only).
     */
    private function agentXml(string $bic): string {
        if ($bic === '') {
            return '<FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId>';
        }
        return '<FinInstnId><BIC>' . htmlspecialchars($bic, ENT_XML1) . '</BIC></FinInstnId>';
    }

    /**
     * Free text (names, remittance information) in the Latin character set of the
     * SEPA rulebooks, 70 / 140 characters: umlauts are written out (ä -> ae, ß -> ss),
     * other accents dropped, "&" becomes "+", anything else a space. Banks reject
     * files with other characters or convert them unpredictably.
     */
    private function text(string $value, int $max = 70, string $fallback = '-'): string {
        $value = strtr($value, [
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '&' => '+',
        ]);
        if (class_exists(\Transliterator::class)) {
            $ascii = \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($value);
            if (is_string($ascii)) {
                $value = $ascii;
            }
        }
        $value = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace(self::TEXT_ALLOWED, ' ', $value)));
        $value = rtrim(substr($value, 0, $max));
        return htmlspecialchars($value !== '' ? $value : $fallback, ENT_XML1);
    }

    private static function amount(int $cents): string {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * Build SEPA-XML content (pain.008.001.02 format)
     */
    private function buildSepaXml(
        int $clubId,
        string $creditorName,
        ClubAccount $account,
        int $totalCents,
        array $transactions
    ): string {
        $now = Clock::nowOf($this->clock);
        // unique per file: banks reject a message id they have already seen
        $msgId = substr('VEREIN-' . $clubId . '-' . $now->format('YmdHis') . '-' . bin2hex(random_bytes(2)), 0, 32);
        $creationDateTime = $now->format('Y-m-d\TH:i:s');
        $count = count($transactions);
        $total = self::amount($totalCents);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.008.001.02" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
        $xml .= '  <CstmrDrctDbtInitn>' . "\n";

        // Group Header
        $xml .= '    <GrpHdr>' . "\n";
        $xml .= '      <MsgId>' . $msgId . '</MsgId>' . "\n";
        $xml .= '      <CreDtTm>' . $creationDateTime . '</CreDtTm>' . "\n";
        $xml .= '      <NbOfTxs>' . $count . '</NbOfTxs>' . "\n";
        $xml .= '      <CtrlSum>' . $total . '</CtrlSum>' . "\n";
        $xml .= '      <InitgPty><Nm>' . $this->text($creditorName) . '</Nm></InitgPty>' . "\n";
        $xml .= '    </GrpHdr>' . "\n";

        // One payment block per collection date (fees due later are collected on their due date)
        $groups = [];
        foreach ($transactions as $txn) {
            $groups[$txn['collectionDate']][] = $txn;
        }
        ksort($groups);
        $n = 0;
        foreach ($groups as $collectionDate => $group) {
            $groupCents = array_sum(array_column($group, 'cents'));
            $xml .= '    <PmtInf>' . "\n";
            $xml .= '      <PmtInfId>' . $msgId . '-' . (++$n) . '</PmtInfId>' . "\n";
            $xml .= '      <PmtMtd>DD</PmtMtd>' . "\n";
            $xml .= '      <BtchBookg>true</BtchBookg>' . "\n";
            $xml .= '      <NbOfTxs>' . count($group) . '</NbOfTxs>' . "\n";
            $xml .= '      <CtrlSum>' . self::amount($groupCents) . '</CtrlSum>' . "\n";
            // RCUR for all: since the SEPA Core rulebook 2016 the FRST marker is optional
            $xml .= '      <PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl><LclInstrm><Cd>CORE</Cd></LclInstrm><SeqTp>RCUR</SeqTp></PmtTpInf>' . "\n";
            $xml .= '      <ReqdColltnDt>' . $collectionDate . '</ReqdColltnDt>' . "\n";

            // Creditor
            $xml .= '      <Cdtr><Nm>' . $this->text($creditorName) . '</Nm></Cdtr>' . "\n";
            $xml .= '      <CdtrAcct><Id><IBAN>' . htmlspecialchars(self::compact($account->getIban()), ENT_XML1) . '</IBAN></Id></CdtrAcct>' . "\n";
            $xml .= '      <CdtrAgt>' . $this->agentXml(self::compact($account->getBic())) . '</CdtrAgt>' . "\n";
            // charges shared, the only value the SEPA rulebooks allow
            $xml .= '      <ChrgBr>SLEV</ChrgBr>' . "\n";
            $xml .= '      <CdtrSchmeId><Id><PrvtId><Othr><Id>' . htmlspecialchars(self::compact($account->getCreditorId()), ENT_XML1) . '</Id><SchmeNm><Prtry>SEPA</Prtry></SchmeNm></Othr></PrvtId></Id></CdtrSchmeId>' . "\n";

            foreach ($group as $txn) {
                $xml .= '      <DrctDbtTxInf>' . "\n";
                $xml .= '        <PmtId><EndToEndId>FEE-' . (int)$txn['feeId'] . '</EndToEndId></PmtId>' . "\n";
                $xml .= '        <InstdAmt Ccy="EUR">' . self::amount($txn['cents']) . '</InstdAmt>' . "\n";
                $xml .= '        <DrctDbtTx><MndtRltdInf><MndtId>' . htmlspecialchars($txn['mandateReference'], ENT_XML1) . '</MndtId><DtOfSgntr>' . $txn['mandateDate'] . '</DtOfSgntr></MndtRltdInf></DrctDbtTx>' . "\n";
                $xml .= '        <DbtrAgt>' . $this->agentXml($txn['bic']) . '</DbtrAgt>' . "\n";
                $xml .= '        <Dbtr><Nm>' . $this->text($txn['name']) . '</Nm></Dbtr>' . "\n";
                $xml .= '        <DbtrAcct><Id><IBAN>' . htmlspecialchars($txn['iban'], ENT_XML1) . '</IBAN></Id></DbtrAcct>' . "\n";
                $xml .= '        <RmtInf><Ustrd>' . $this->text($txn['reference'], 140) . '</Ustrd></RmtInf>' . "\n";
                $xml .= '      </DrctDbtTxInf>' . "\n";
            }
            $xml .= '    </PmtInf>' . "\n";
        }
        $xml .= '  </CstmrDrctDbtInitn>' . "\n";
        $xml .= '</Document>';

        return $xml;
    }
}
