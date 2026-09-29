<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\L10n\DocumentL10n;
use OCA\Verein\L10n\Formats;
use OCA\Verein\L10n\Greeting;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

/**
 * Advance notice of a SEPA direct debit (Vorabankündigung / pre-notification) by email - optional: a club can just as
 * well announce its collections in the fee invoice, the statutes or on paper, and the SEPA export never depends on it.
 *
 * It tells every member in the coming collection what will be collected when: amounts, collection date, creditor ID,
 * mandate reference and the last digits of their IBAN - taken from exactly the transactions the SEPA export would
 * contain for the same bank account and collection date (SepaService::previewSepaExport()), so notice and file agree
 * as long as the export uses the same collection date.
 *
 * Sent through ClubMailer (Nextcloud's mail server, the club's sender name and reply-to). Members without a usable
 * address or whose email failed are listed, so the club can inform them another way.
 */
class SepaPrenotificationService {
    private IL10N $l;
    private IL10N $doc;

    public function __construct(
        private SepaService $sepa,
        private MemberMapper $members,
        private ClubMapper $clubs,
        private ClubMailer $mail,
        private ?AuditLogService $auditLog = null,
        ?IL10N $l10n = null,
        ?DocumentL10n $documentL10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
        $this->doc = $documentL10n?->get() ?? $this->l;
    }

    /**
     * @return array{sent: array, withoutEmail: array, failed: array, collectionDate: string}
     * @throws ValidationException
     */
    public function send(int $clubId, ?int $accountId = null, ?string $collectionDate = null): array {
        $club = $this->clubs->find($clubId);
        $preview = $this->sepa->previewSepaExport($clubId, $accountId, $collectionDate);
        if ($preview['transactions'] === []) {
            throw new ValidationException($this->l->t('No unpaid or overdue fees for the SEPA export'));
        }
        if (trim((string)$preview['creditorId']) === '') {
            throw new ValidationException($this->l->t('No creditor ID is set for the bank account (“Club” tab)'));
        }

        $byMember = [];
        foreach ($preview['transactions'] as $txn) {
            $byMember[$txn['memberId']][] = $txn;
        }
        $members = [];
        foreach ($this->members->findByClub($clubId) as $member) {
            $members[$member->getId()] = $member;
        }

        $sent = [];
        $withoutEmail = [];
        $failed = [];
        foreach ($byMember as $memberId => $transactions) {
            $member = $members[$memberId] ?? null;
            if ($member === null) {
                continue;
            }
            $who = ['memberId' => $memberId, 'name' => $member->getFullName()];
            if (!$this->mail->isUsable((string)$member->getEmail())) {
                $withoutEmail[] = $who;
                continue;
            }
            $subject = $this->doc->t('Advance notice of your SEPA direct debit – %s', [$club->getName()]);
            $ok = $this->mail->send($club, (string)$member->getEmail(), $member->getFullName(), 'verein.sepa_notice', $subject,
                $this->paragraphs($club->getName(), (string)$preview['creditorId'], $member, $transactions));
            if ($ok) {
                $sent[] = $who;
            } else {
                $failed[] = $who;
            }
        }
        usort($withoutEmail, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($failed, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        if ($sent !== [] || $failed !== []) {
            // counts and the date only - who was notified is personal data the log does not need
            $this->auditLog?->record($clubId, 'fee', 0, 'sepa_notice', [
                'sent' => count($sent),
                'failed' => count($failed),
                'withoutEmail' => count($withoutEmail),
                'collectionDate' => $preview['collectionDate'],
            ]);
        }
        return ['sent' => $sent, 'withoutEmail' => $withoutEmail, 'failed' => $failed, 'collectionDate' => $preview['collectionDate']];
    }

    /** @return string[] */
    private function paragraphs(string $clubName, string $creditorId, Member $member, array $transactions): array {
        $money = fn (float $v): string => Formats::money($this->doc, $v);
        $lines = [];
        $total = 0.0;
        foreach ($transactions as $txn) {
            $total += (float)$txn['amount'];
            $lines[] = $this->doc->t('%1$s: %2$s on %3$s', [$txn['reference'], $money((float)$txn['amount']), Formats::date($this->doc, $txn['collectionDate'])]);
        }
        $iban = (string)$transactions[0]['iban'];
        $mandates = array_values(array_unique(array_column($transactions, 'mandateReference')));

        return [
            Greeting::of($member, $this->doc),
            $this->doc->t('We will collect the following amounts from your account by SEPA direct debit:'),
            implode("\n", $lines) . (count($lines) > 1 ? "\n" . $this->doc->t('Total: %s', [$money($total)]) : ''),
            $this->doc->t('Account: IBAN ending in %s', [substr($iban, -4)]) . "\n"
                . $this->doc->t('Creditor ID: %s', [$creditorId]) . "\n"
                . $this->doc->t('Mandate reference: %s', [implode(', ', $mandates)]),
            $this->doc->t('Please make sure your account has sufficient funds on that day. If anything is incorrect, please contact the board.'),
            $this->doc->t('Kind regards

The board
%s', [$clubName]),
        ];
    }
}
