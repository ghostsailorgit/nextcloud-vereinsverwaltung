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

    public function __construct(
        private FeeMapper $feeMapper,
        private MemberMapper $memberMapper,
        private MembershipMapper $membershipMapper,
        private ClubMapper $clubMapper,
        private ClubService $clubService,
        private ?Clock $clock = null,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
    }

    /**
     * Generate SEPA-XML for all open fees of a club
     *
     * @param int|null $accountId Club bank account to collect on (default account if null)
     * @return array{xml: string, skippedCount: int, feeIds: int[]} XML, number of fees left out and ids of the fees in the file
     *   (no IBAN / no signed mandate) - see previewSepaExport() for who and why
     */
    public function generateSepaXml(int $clubId, ?int $accountId = null): array {
        $club = $this->clubMapper->find($clubId);
        $account = $this->clubService->resolveAccount($clubId, $accountId);
        if ($account->getCreditorId() === '') {
            throw new ValidationException($this->l->t('No creditor ID is set for the bank account ("Club" tab)'));
        }

        $collected = $this->collectFees($clubId);

        if (empty($collected['transactions'])) {
            if (!empty($collected['skipped'])) {
                $names = implode(', ', array_map(
                    fn($s) => $s['memberName'] . ' (' . $s['reason'] . ')',
                    $collected['skipped']
                ));
                throw new ValidationException($this->l->t('No payment can be exported. Not included: %s', [$names]));
            }
            throw new ValidationException($this->l->t('No open or overdue payments found for the SEPA export'));
        }

        return [
            'xml' => $this->buildSepaXml(
                $club->getName(),
                $account,
                $collected['totalAmount'],
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
            'totalAmount' => $collected['totalAmount'],
            'transactionCount' => count($collected['transactions']),
            'transactions' => $collected['transactions'],
            'skipped' => $collected['skipped']
        ];
    }

    /**
     * Collect all fees of the club that still need to be debited (open and
     * overdue - overdue is a manually-set status, not an automatic
     * transition, so it still represents money owed that hasn't been
     * debited yet).
     *
     * A fee can only be debited if the member has an IBAN and a signed
     * mandate (signature date on their membership). The others are returned
     * in 'skipped' with the reason, so callers can tell the user instead of
     * silently dropping them.
     *
     * @return array{transactions: array, skipped: array, totalAmount: float}
     */
    private function collectFees(int $clubId): array {
        $totalAmount = 0;
        $transactions = [];
        $skipped = [];

        foreach ($this->feeMapper->findByStatusesInClub(['open', 'overdue'], $clubId) as $fee) {
            $member = $this->memberMapper->find($fee->getMemberId());

            try {
                $membership = $this->membershipMapper->findByMemberAndClub($fee->getMemberId(), $clubId);
            } catch (DoesNotExistException $e) {
                $membership = null;
            }

            $reason = null;
            if ($membership !== null && $membership->getDeactivated()) {
                $reason = $this->l->t('member deactivated');
            } elseif (empty($member->getIban())) {
                $reason = $this->l->t('no IBAN recorded');
            } elseif ($membership === null || empty($membership->getMandateDate())) {
                $reason = $this->l->t('no signed SEPA mandate recorded');
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

            $totalAmount += $fee->getAmount();
            $transactions[] = [
                'name' => $member->getFullName(),
                'memberName' => $member->getFullName(),
                'iban' => $member->getIban(),
                'bic' => $member->getBic(),
                'amount' => $fee->getAmount(),
                'dueDate' => $fee->getDueDate(),
                'mandateReference' => $membership->getEffectiveMandateReference(),
                'mandateDate' => $membership->getMandateDate(),
                'reference' => $this->paymentReference($fee->getDescription(), $fee->getDueDate()),
                'feeId' => $fee->getId()
            ];
        }

        return ['transactions' => $transactions, 'skipped' => $skipped, 'totalAmount' => $totalAmount];
    }

    private function paymentReference(?string $description, string $dueDate): string {
        $description = trim((string)$description);
        if ($description !== '') {
            return $description;
        }
        $year = substr($dueDate, 0, 4);
        return 'Mitgliedsbeitrag ' . (ctype_digit($year) ? $year : Clock::nowOf($this->clock)->format('Y'));
    }

    /**
     * BIC element, or the SEPA-conformant "not provided" marker: within the
     * SEPA area the BIC is optional (IBAN-only).
     */
    private function agentXml(string $bic): string {
        $bic = trim($bic);
        if ($bic === '') {
            return '<FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId>';
        }
        return '<FinInstnId><BIC>' . htmlspecialchars($bic) . '</BIC></FinInstnId>';
    }

    /**
     * SEPA restricts names/reference to 70 / 140 characters
     */
    private function text(string $value, int $max = 70): string {
        return htmlspecialchars(mb_substr($value, 0, $max), ENT_XML1);
    }

    /**
     * Build SEPA-XML content (pain.008.001.02 format)
     */
    private function buildSepaXml(
        string $creditorName,
        ClubAccount $account,
        float $totalAmount,
        array $transactions
    ): string {
        $now = Clock::nowOf($this->clock);
        $msgId = 'VEREIN-' . $now->format('YmdHis');
        $creationDateTime = $now->format('Y-m-d\TH:i:s');
        $collectionDate = $now->modify('+5 days')->format('Y-m-d');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.008.001.02" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
        $xml .= '  <CstmrDrctDbtInitn>' . "\n";

        // Group Header
        $xml .= '    <GrpHdr>' . "\n";
        $xml .= '      <MsgId>' . htmlspecialchars($msgId) . '</MsgId>' . "\n";
        $xml .= '      <CreDtTm>' . $creationDateTime . '</CreDtTm>' . "\n";
        $xml .= '      <NbOfTxs>' . count($transactions) . '</NbOfTxs>' . "\n";
        $xml .= '      <CtrlSum>' . number_format($totalAmount, 2, '.', '') . '</CtrlSum>' . "\n";
        $xml .= '      <InitgPty><Nm>' . $this->text($creditorName) . '</Nm></InitgPty>' . "\n";
        $xml .= '    </GrpHdr>' . "\n";

        // Payment Information
        $xml .= '    <PmtInf>' . "\n";
        $xml .= '      <PmtInfId>' . htmlspecialchars($msgId) . '-1</PmtInfId>' . "\n";
        $xml .= '      <PmtMtd>DD</PmtMtd>' . "\n";
        $xml .= '      <BtchBookg>true</BtchBookg>' . "\n";
        $xml .= '      <NbOfTxs>' . count($transactions) . '</NbOfTxs>' . "\n";
        $xml .= '      <CtrlSum>' . number_format($totalAmount, 2, '.', '') . '</CtrlSum>' . "\n";
        // RCUR for all: since the SEPA Core rulebook 2016 the FRST marker is optional
        $xml .= '      <PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl><LclInstrm><Cd>CORE</Cd></LclInstrm><SeqTp>RCUR</SeqTp></PmtTpInf>' . "\n";
        $xml .= '      <ReqdColltnDt>' . $collectionDate . '</ReqdColltnDt>' . "\n";

        // Creditor
        $xml .= '      <Cdtr><Nm>' . $this->text($creditorName) . '</Nm></Cdtr>' . "\n";
        $xml .= '      <CdtrAcct><Id><IBAN>' . htmlspecialchars($account->getIban()) . '</IBAN></Id></CdtrAcct>' . "\n";
        $xml .= '      <CdtrAgt>' . $this->agentXml($account->getBic()) . '</CdtrAgt>' . "\n";
        $xml .= '      <CdtrSchmeId><Id><PrvtId><Othr><Id>' . htmlspecialchars($account->getCreditorId()) . '</Id><SchmeNm><Prtry>SEPA</Prtry></SchmeNm></Othr></PrvtId></Id></CdtrSchmeId>' . "\n";

        // Transactions
        foreach ($transactions as $txn) {
            $xml .= '      <DrctDbtTxInf>' . "\n";
            $xml .= '        <PmtId><EndToEndId>FEE-' . $txn['feeId'] . '</EndToEndId></PmtId>' . "\n";
            $xml .= '        <InstdAmt Ccy="EUR">' . number_format($txn['amount'], 2, '.', '') . '</InstdAmt>' . "\n";
            $xml .= '        <DrctDbtTx><MndtRltdInf><MndtId>' . htmlspecialchars($txn['mandateReference']) . '</MndtId><DtOfSgntr>' . htmlspecialchars($txn['mandateDate']) . '</DtOfSgntr></MndtRltdInf></DrctDbtTx>' . "\n";
            $xml .= '        <DbtrAgt>' . $this->agentXml((string)$txn['bic']) . '</DbtrAgt>' . "\n";
            $xml .= '        <Dbtr><Nm>' . $this->text($txn['name']) . '</Nm></Dbtr>' . "\n";
            $xml .= '        <DbtrAcct><Id><IBAN>' . htmlspecialchars(str_replace(' ', '', (string)$txn['iban'])) . '</IBAN></Id></DbtrAcct>' . "\n";
            $xml .= '        <RmtInf><Ustrd>' . $this->text($txn['reference'], 140) . '</Ustrd></RmtInf>' . "\n";
            $xml .= '      </DrctDbtTxInf>' . "\n";
        }

        $xml .= '    </PmtInf>' . "\n";
        $xml .= '  </CstmrDrctDbtInitn>' . "\n";
        $xml .= '</Document>';

        return $xml;
    }
}
