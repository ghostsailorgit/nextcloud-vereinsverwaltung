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
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

/**
 * The yearly membership report to a sports association: members by birth year and gender on a reference day, and the
 * XML file of the DOSB interface "Bestandsdaten" (SV-LSB, version 1.3).
 *
 * Without a stored gender the title decides (Herr, Frau, Divers), otherwise the member counts as "not specified".
 */
class AssociationReportService {
    public const GENDERS = ['M', 'W', 'D', 'O'];
    private const FROM_TITLE = ['Herr' => 'M', 'Frau' => 'W', 'Divers' => 'D'];
    private const SOFTWARE = 'Nextcloud-Vereinsverwaltung';
    private const MAX_NUMBER = 20;
    private const MAX_CONTACT = 100;

    private IL10N $l;

    public function __construct(
        private MemberMapper $members,
        private ClubMapper $clubs,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
    }

    /**
     * @return array{date: string, rows: array<int, array{year: int, M: int, W: int, D: int, O: int}>, total: int, missingBirthDate: string[], genderFromTitle: int, genderUnknown: int}
     * @throws ValidationException
     */
    public function report(int $clubId, string $date): array {
        $this->checkDate($date);
        $this->clubs->find($clubId);

        $byYear = [];
        $missing = [];
        $fromTitle = 0;
        $unknown = 0;
        $total = 0;
        foreach ($this->members->findByClub($clubId) as $member) {
            if (!$this->isMemberOn($member, $date)) {
                continue;
            }
            $year = $this->birthYear($member->getBirthDate());
            if ($year === null) {
                $missing[] = $member->getFullName();
                continue;
            }
            $gender = (string)$member->getGender();
            if (!in_array($gender, self::GENDERS, true) || $gender === 'O') {
                $derived = self::FROM_TITLE[(string)$member->getSalutation()] ?? null;
                if ($gender === '' && $derived !== null) {
                    $fromTitle++;
                    $gender = $derived;
                } else {
                    $unknown++;
                    $gender = 'O';
                }
            }
            $byYear[$year] ??= ['year' => $year, 'M' => 0, 'W' => 0, 'D' => 0, 'O' => 0];
            $byYear[$year][$gender]++;
            $total++;
        }
        ksort($byYear);
        sort($missing);

        return ['date' => $date, 'rows' => array_values($byYear), 'total' => $total, 'missingBirthDate' => $missing, 'genderFromTitle' => $fromTitle, 'genderUnknown' => $unknown];
    }

    /**
     * @param string $sportCode code of the sport or association ("Funktionscodes"); empty = totals only (type A)
     * @throws ValidationException
     */
    public function xml(int $clubId, string $date, string $number, string $contact, string $sportCode): string {
        $number = trim($number);
        $contact = trim($contact);
        $sportCode = trim($sportCode);
        if ($number === '' || mb_strlen($number) > self::MAX_NUMBER) {
            throw new ValidationException($this->l->t('The club number is required (at most 20 characters)'));
        }
        if (mb_strlen($contact) > self::MAX_CONTACT) {
            throw new ValidationException($this->l->t('The contact person is too long'));
        }
        if ($sportCode !== '' && !preg_match('/^\d{1,6}$/', $sportCode)) {
            throw new ValidationException($this->l->t('The federation code must consist of digits'));
        }
        $report = $this->report($clubId, $date);
        $club = $this->clubs->find($clubId);

        $dom = new \DOMDocument('1.0', 'utf-8');
        $dom->formatOutput = true;
        $root = $dom->appendChild($dom->createElement('Mitglieder'));
        $software = $root->appendChild($dom->createElement('Software'));
        $this->element($dom, $software, 'Schluessel', self::SOFTWARE);
        $verein = $root->appendChild($dom->createElement('Verein'));
        $this->element($dom, $verein, 'Nummer', $number);
        $this->element($dom, $verein, 'Bezeichnung', $club->getName());
        $this->element($dom, $verein, 'Ansprechpartner', $contact);

        foreach ($sportCode === '' ? ['A'] : ['A', 'B'] as $type) {
            foreach ($report['rows'] as $row) {
                $zahlen = $root->appendChild($dom->createElement('Zahlen'));
                $this->element($dom, $zahlen, 'Typ', $type);
                $this->element($dom, $zahlen, 'Fachverband', $type === 'B' ? $sportCode : '');
                $this->element($dom, $zahlen, 'Jahrgang', (string)$row['year']);
                foreach (self::GENDERS as $gender) {
                    $this->element($dom, $zahlen, 'Anzahl' . $gender, (string)$row[$gender]);
                }
            }
        }
        return (string)$dom->saveXML();
    }

    private function element(\DOMDocument $dom, \DOMNode $parent, string $name, string $text): void {
        // a text node, so that "&" and "<" in a name are escaped
        $parent->appendChild($dom->createElement($name))->appendChild($dom->createTextNode($text));
    }

    /** @throws ValidationException */
    private function checkDate(string $date): void {
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ValidationException($this->l->t('The reference date is invalid'));
        }
    }

    /** Whoever has joined by that day (or has no join date) and had not left before it. */
    private function isMemberOn(Member $member, string $date): bool {
        if ($member->getDeceased()) {
            return false;
        }
        $join = substr(trim((string)$member->getJoinDate()), 0, 10);
        $leave = substr(trim((string)$member->getLeaveDate()), 0, 10);
        return ($join === '' || $join <= $date) && ($leave === '' || $leave >= $date);
    }

    private function birthYear(?string $birthDate): ?int {
        if ($birthDate === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $birthDate, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        return (int)$m[1];
    }
}
