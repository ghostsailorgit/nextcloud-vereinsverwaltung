<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubAccount;
use OCA\Verein\Db\ClubAccountMapper;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\L10n\DocumentL10n;
use OCA\Verein\L10n\Formats;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

/**
 * The membership confirmation by email, with the club's own text and placeholders.
 *
 * Only ever sent on request for one member: creating or importing members sends nothing by itself. A member's IBAN appears
 * shortened only, because email is not encrypted; the club's own bank details are public anyway and appear in full.
 */
class WelcomeMailService {
    /** placeholder (lower case) => field; every field has an English and a German name */
    private const PLACEHOLDERS = [
        'firstname' => 'firstName', 'vorname' => 'firstName',
        'lastname' => 'lastName', 'nachname' => 'lastName',
        'fullname' => 'fullName', 'name' => 'fullName',
        'title' => 'title', 'anrede' => 'title',
        'street' => 'street', 'strasse' => 'street',
        'postalcode' => 'postalCode', 'plz' => 'postalCode',
        'city' => 'city', 'ort' => 'city',
        'email' => 'email',
        'phone' => 'phone', 'telefon' => 'phone',
        'birthdate' => 'birthDate', 'geburtsdatum' => 'birthDate',
        'joindate' => 'joinDate', 'eintritt' => 'joinDate',
        'membernumber' => 'memberNumber', 'mitgliedsnummer' => 'memberNumber',
        'feecategory' => 'feeCategory', 'beitragskategorie' => 'feeCategory',
        'iban' => 'iban',
        'mandatereference' => 'mandateReference', 'mandatsreferenz' => 'mandateReference',
        'club' => 'clubName', 'verein' => 'clubName',
        'clubstreet' => 'clubStreet', 'vereinsstrasse' => 'clubStreet',
        'clubpostalcode' => 'clubPostalCode', 'vereinsplz' => 'clubPostalCode',
        'clubcity' => 'clubCity', 'vereinsort' => 'clubCity',
        'clubiban' => 'clubIban', 'vereinsiban' => 'clubIban',
        'clubbic' => 'clubBic', 'vereinsbic' => 'clubBic',
        'creditorid' => 'creditorId', 'glaeubigerid' => 'creditorId',
    ];

    private IL10N $l;
    private IL10N $doc;

    public function __construct(
        private MemberService $members,
        private ClubMapper $clubs,
        private ClubAccountMapper $accounts,
        private FeeRateMapper $rates,
        private ClubMailer $mail,
        private ?AuditLogService $auditLog = null,
        ?IL10N $l10n = null,
        ?DocumentL10n $documentL10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
        $this->doc = $documentL10n?->get() ?? $this->l;
    }

    /**
     * @throws ValidationException if the club has no text, the member no usable address, or sending fails
     * @throws \OCA\Verein\Exception\NotFoundException if the person is not a member of this club
     */
    public function send(int $clubId, int $memberId): void {
        $member = $this->members->find($clubId, $memberId);
        $club = $this->clubs->find($clubId);
        $subject = trim((string)$club->getWelcomeSubject());
        $body = trim((string)$club->getWelcomeBody());
        if ($subject === '' || $body === '') {
            throw new ValidationException($this->l->t('The club has no text for the membership confirmation yet (“Club” tab)'));
        }
        $email = trim((string)$member->getEmail());
        if (!$this->mail->isUsable($email)) {
            throw new ValidationException($this->l->t('The member has no usable email address'));
        }

        $values = $this->values($member, $club);
        // one line, whatever a placeholder contains
        $renderedSubject = trim((string)preg_replace('/[\r\n]+/', ' ', $this->render($subject, $values)));
        $paragraphs = preg_split('/\R\s*\R/u', $this->render(str_replace("\r\n", "\n", $body), $values)) ?: [];

        if (!$this->mail->send($club, $email, $member->getFullName(), 'verein.welcome', $renderedSubject, $paragraphs)) {
            throw new ValidationException($this->l->t('The email could not be sent. The reason is in the Nextcloud log.'));
        }
        $this->auditLog?->record($clubId, 'member', $memberId, 'welcome_mail');
    }

    /** One pass over the template, so that text inside a value is never read as a placeholder. Unknown ones stay as they are. */
    private function render(string $template, array $values): string {
        return (string)preg_replace_callback('/\{([a-zA-Z]+)\}/', function (array $m) use ($values) {
            $field = self::PLACEHOLDERS[strtolower($m[1])] ?? null;
            return $field === null ? $m[0] : $values[$field];
        }, $template);
    }

    /** @return array<string, string> */
    private function values(Member $member, Club $club): array {
        $membership = $member->getMembership();
        $account = $this->account($club->getId());
        $titles = ['Herr' => $this->doc->t('Mr'), 'Frau' => $this->doc->t('Ms'), 'Divers' => $this->doc->t('Mx'), 'Firma' => $this->doc->t('Company')];
        $date = fn (?string $value) => $value === null || trim($value) === '' ? '' : Formats::date($this->doc, substr(trim($value), 0, 10));
        return [
            'firstName' => (string)$member->getFirstName(),
            'lastName' => (string)$member->getName(),
            'fullName' => $member->getFullName(),
            'title' => $titles[(string)$member->getSalutation()] ?? '',
            'street' => (string)$member->getStreet(),
            'postalCode' => (string)$member->getPostalCode(),
            'city' => (string)$member->getCity(),
            'email' => (string)$member->getEmail(),
            'phone' => (string)$member->getPhone(),
            'birthDate' => $date($member->getBirthDate()),
            'joinDate' => $date($member->getJoinDate()),
            'memberNumber' => (string)$member->getId(),
            'feeCategory' => $this->feeCategory($club->getId(), $membership?->getFeeRateId()),
            'iban' => self::shortenIban((string)$member->getIban()),
            'mandateReference' => (string)$membership?->getEffectiveMandateReference(),
            'clubName' => $club->getName(),
            'clubStreet' => (string)$club->getStreet(),
            'clubPostalCode' => (string)$club->getPostalCode(),
            'clubCity' => (string)$club->getCity(),
            'clubIban' => $account !== null ? (string)$account->getIban() : '',
            'clubBic' => $account !== null ? (string)$account->getBic() : '',
            'creditorId' => $account !== null ? (string)$account->getCreditorId() : '',
        ];
    }

    /** "DE89 … 3000": enough to recognize the account, too little to use it */
    public static function shortenIban(string $iban): string {
        $compact = (string)preg_replace('/\s+/', '', $iban);
        return strlen($compact) < 8 ? '' : substr($compact, 0, 4) . ' … ' . substr($compact, -4);
    }

    private function account(int $clubId): ?ClubAccount {
        $accounts = $this->accounts->findByClub($clubId);
        foreach ($accounts as $account) {
            if ($account->getIsDefault()) {
                return $account;
            }
        }
        return $accounts[0] ?? null;
    }

    private function feeCategory(int $clubId, ?int $rateId): string {
        $fallback = '';
        foreach ($this->rates->findByClub($clubId) as $rate) {
            if ($rate->getId() === $rateId) {
                return $rate->getName();
            }
            if ($rate->getIsDefault()) {
                $fallback = $rate->getName();
            }
        }
        return $fallback;
    }
}
