<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

/**
 * Member import from CSV (the app's own member export, or a spreadsheet saved as CSV).
 *
 * plan() parses and checks every row without writing anything; import() creates the rows the caller
 * picks (by line number) that are still valid - the frontend sends them in small chunks, because each
 * new member also gets calendar entries and the slowest hosts need seconds per person.
 *
 * Duplicates are only looked for inside the importing club (and inside the file): whether a person
 * exists in another club is not revealed here - the existing "lookup" with its two-club permission
 * check is the way to take over a person from another club.
 */
class MemberImportService {
    private IL10N $l;

    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_ROWS = 2000;

    /** normalised header => field; see normaliseHeader() */
    private const COLUMNS = [
        'anrede' => 'salutation', 'salutation' => 'salutation', 'title' => 'salutation',
        'vorname' => 'firstName', 'firstname' => 'firstName',
        'name' => 'name', 'nachname' => 'name', 'familienname' => 'name', 'lastname' => 'name', 'surname' => 'name',
        'strasse' => 'street', 'strassehausnummer' => 'street', 'strasseundhausnummer' => 'street', 'anschrift' => 'street', 'street' => 'street',
        'plz' => 'postalCode', 'postleitzahl' => 'postalCode', 'postalcode' => 'postalCode', 'zip' => 'postalCode',
        'ort' => 'city', 'wohnort' => 'city', 'stadt' => 'city', 'city' => 'city',
        'email' => 'email', 'mail' => 'email', 'emailadresse' => 'email',
        'iban' => 'iban', 'bic' => 'bic',
        'geburtsdatum' => 'birthDate', 'geburtstag' => 'birthDate', 'birthdate' => 'birthDate', 'dateofbirth' => 'birthDate',
        'eintritt' => 'joinDate', 'eintrittsdatum' => 'joinDate', 'mitgliedseit' => 'joinDate', 'joindate' => 'joinDate', 'datejoined' => 'joinDate', 'joined' => 'joinDate',
        'austritt' => 'leaveDate', 'austrittsdatum' => 'leaveDate', 'leavedate' => 'leaveDate', 'dateleft' => 'leaveDate', 'left' => 'leaveDate',
        'rolle' => 'role', 'funktion' => 'role', 'role' => 'role', 'position' => 'role',
        'beitragskategorie' => 'feeRate', 'kategorie' => 'feeRate', 'feerate' => 'feeRate', 'feecategory' => 'feeRate', 'category' => 'feeRate',
        'mandatsreferenz' => 'mandateReference', 'mandatereference' => 'mandateReference',
        'mandatsdatum' => 'mandateDate', 'mandatunterschriebenam' => 'mandateDate', 'mandatedate' => 'mandateDate', 'dateofsignature' => 'mandateDate',
        'grundungsmitglied' => 'foundingMember', 'gruendungsmitglied' => 'foundingMember', 'foundingmember' => 'foundingMember',
        'eintrittsjahrbeitragsfrei' => 'feeExemptJoinYear', 'beitragsfreiimeintrittsjahr' => 'feeExemptJoinYear', 'feeexemptintheyearjoined' => 'feeExemptJoinYear',
        'verstorben' => 'deceased', 'deceased' => 'deceased',
    ];

    /** columns of the app's own export that carry nothing to import */
    private const IGNORED = ['id', 'alter', 'mitgliedseitjahre', 'erstelltam', 'nr', 'age', 'membersinceyears', 'createdon', 'memberid', 'yearsofmembership'];

    private const ROLES = [
        'mitglied' => 'member', 'member' => 'member', '' => 'member',
        'vorstand' => 'admin', 'admin' => 'admin', 'board' => 'admin',
        'kassierer' => 'treasurer', 'kassiererin' => 'treasurer', 'kassenwart' => 'treasurer', 'kassenwartin' => 'treasurer', 'treasurer' => 'treasurer',
    ];

    public function __construct(
        private MemberService $members,
        private MemberMapper $memberMapper,
        private FeeRateMapper $rates,
        private ClubMapper $clubs,
        private ValidationService $validation,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
    }

    /**
     * Checks every row; writes nothing.
     *
     * @return array{columns: array, ignoredColumns: string[], rows: array, counts: array}
     * @throws ValidationException
     */
    public function plan(int $clubId, string $csv, bool $mayAssignRoles): array {
        $this->clubs->find($clubId);
        [$columns, $ignored, $records] = $this->parse($csv);

        $ratesByName = [];
        foreach ($this->rates->findByClub($clubId) as $rate) {
            $ratesByName[mb_strtolower(trim($rate->getName()))] = $rate->getId();
        }

        // name key => birth dates (null = unknown) of the club's members
        $existing = [];
        foreach ($this->memberMapper->findByClub($clubId) as $member) {
            $existing[$this->nameKey($member->getFirstName(), $member->getName())][] = $member->getBirthDate();
        }

        $seen = [];
        $rows = [];
        $counts = ['ok' => 0, 'error' => 0, 'duplicate' => 0];
        foreach ($records as $line => $record) {
            [$data, $errors, $warnings] = $this->toMemberData($record, $ratesByName, $mayAssignRoles);
            $label = trim(($data['firstName'] ?? '') . ' ' . ($data['name'] ?? ''));

            $status = 'ok';
            if ($errors === []) {
                $validation = $this->validation->validateMember($data);
                $errors = $validation['errors'];
            }
            if ($errors !== []) {
                $status = 'error';
            } else {
                $key = $this->nameKey($data['firstName'], $data['name']);
                $birth = $data['birthDate'];
                if ($this->samePerson($existing[$key] ?? [], $birth)) {
                    $status = 'duplicate';
                    $errors[] = $this->l->t('is already a member of this club');
                } else {
                    foreach ($seen[$key] ?? [] as [$otherLine, $otherBirth]) {
                        if ($this->samePerson([$otherBirth], $birth)) {
                            $status = 'duplicate';
                            $errors[] = $this->l->t('is already in line %s of this file', [$otherLine]);
                            break;
                        }
                    }
                }
                $seen[$key][] = [$line, $birth];
            }
            $counts[$status]++;
            $rows[] = ['line' => $line, 'name' => $label !== '' ? $label : '(ohne Namen)', 'status' => $status,
                'messages' => $errors, 'warnings' => $warnings, 'data' => $status === 'ok' ? $data : null];
        }

        return ['columns' => $columns, 'ignoredColumns' => $ignored, 'rows' => $rows, 'counts' => $counts];
    }

    /**
     * Creates the members of the given lines that plan() still reports as "ok". Each row is created on
     * its own; a failing row is reported and does not stop the others.
     *
     * @param int[] $lines
     * @return array{created: array, failed: array, skipped: array}
     * @throws ValidationException
     */
    public function import(int $clubId, string $csv, bool $mayAssignRoles, array $lines): array {
        if ($lines === [] || count($lines) > 100) {
            throw new ValidationException($this->l->t('Please import 1 to 100 lines at a time'));
        }
        $plan = $this->plan($clubId, $csv, $mayAssignRoles);
        $wanted = array_flip(array_map('intval', $lines));
        $created = [];
        $failed = [];
        $skipped = [];
        foreach ($plan['rows'] as $row) {
            if (!isset($wanted[$row['line']])) {
                continue;
            }
            if ($row['status'] !== 'ok') {
                $skipped[] = ['line' => $row['line'], 'name' => $row['name'], 'reason' => implode('; ', $row['messages'])];
                continue;
            }
            try {
                $member = $this->members->create($clubId, $row['data']);
                $created[] = ['line' => $row['line'], 'name' => $row['name'], 'memberId' => $member->getId()];
            } catch (ValidationException $e) {
                $failed[] = ['line' => $row['line'], 'name' => $row['name'], 'reason' => $e->getMessage()];
            } catch (\Throwable $e) {
                // unexpected (e.g. database) errors are not shown verbatim - they can contain SQL
                $failed[] = ['line' => $row['line'], 'name' => $row['name'], 'reason' => $this->l->t('internal error')];
            }
        }
        return ['created' => $created, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * @return array{0: array<string, string>, 1: string[], 2: array<int, array<string, string>>}
     *   [header => field of the recognised columns, ignored headers, line number => field => value]
     * @throws ValidationException
     */
    private function parse(string $csv): array {
        if (strlen($csv) > self::MAX_BYTES) {
            throw new ValidationException($this->l->t('The file is too large (at most 2 MB)'));
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (!mb_check_encoding($csv, 'UTF-8')) {
            // spreadsheets on Windows often save CSV as Windows-1252
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        if (trim($csv) === '') {
            throw new ValidationException($this->l->t('The file is empty'));
        }

        $firstLine = strtok($csv, "\n");
        $delimiter = ';';
        $best = -1;
        foreach ([';', ',', "\t"] as $d) {
            if (substr_count((string)$firstLine, $d) > $best) {
                $best = substr_count((string)$firstLine, $d);
                $delimiter = $d;
            }
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, null, $delimiter, '"', '');
        if ($header === false || $header === [null]) {
            throw new ValidationException($this->l->t('The first line must contain the column headings'));
        }

        $columns = [];
        $fieldByIndex = [];
        $ignored = [];
        foreach ($header as $i => $title) {
            $title = trim((string)$title);
            $key = $this->normaliseHeader($title);
            $field = self::COLUMNS[$key] ?? null;
            if ($field !== null && !in_array($field, $fieldByIndex, true)) {
                $fieldByIndex[$i] = $field;
                $columns[$title] = $field;
            } elseif ($title !== '' && !in_array($key, self::IGNORED, true)) {
                $ignored[] = $title;
            }
        }
        if (!in_array('name', $fieldByIndex, true)) {
            throw new ValidationException($this->l->t('A column “Name” (or “Last name”) is missing'));
        }

        $records = [];
        $line = 1;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map(fn ($c) => trim((string)$c), $cells)) === '') {
                continue;
            }
            if (count($records) >= self::MAX_ROWS) {
                throw new ValidationException($this->l->t('Too many lines (at most %s)', [self::MAX_ROWS]));
            }
            $record = [];
            foreach ($fieldByIndex as $i => $field) {
                $value = trim((string)($cells[$i] ?? ''));
                // the app's own CSV export guards formula-like text with a leading apostrophe
                if (preg_match('/^\'[=+\-@]/', $value) === 1) {
                    $value = substr($value, 1);
                }
                $record[$field] = $value;
            }
            $records[$line] = $record;
        }
        fclose($stream);
        if ($records === []) {
            throw new ValidationException($this->l->t('The file contains no data lines'));
        }
        return [$columns, $ignored, $records];
    }

    /**
     * @param array<string, string> $record
     * @param array<string, int> $ratesByName
     * @return array{0: array, 1: string[], 2: string[]} [member data, errors, warnings]
     */
    private function toMemberData(array $record, array $ratesByName, bool $mayAssignRoles): array {
        $errors = [];
        $warnings = [];
        $data = [
            'name' => $record['name'] ?? '',
            'firstName' => $this->nullIfEmpty($record['firstName'] ?? null),
            'street' => $this->nullIfEmpty($record['street'] ?? null),
            'postalCode' => $this->nullIfEmpty($record['postalCode'] ?? null),
            'city' => $this->nullIfEmpty($record['city'] ?? null),
            'email' => $record['email'] ?? '',
            'bic' => $this->nullIfEmpty(isset($record['bic']) ? strtoupper(str_replace(' ', '', $record['bic'])) : null),
            'iban' => $this->nullIfEmpty(isset($record['iban']) ? strtoupper(str_replace(' ', '', $record['iban'])) : null),
            'mandateReference' => $this->nullIfEmpty($record['mandateReference'] ?? null),
            'userId' => null,
            'role' => 'member',
        ];

        $salutation = trim($record['salutation'] ?? '');
        $salutations = ['herr' => 'Herr', 'hr.' => 'Herr', 'hr' => 'Herr', 'frau' => 'Frau', 'fr.' => 'Frau', 'fr' => 'Frau',
            'divers' => 'Divers', 'firma' => 'Firma',
            // the English labels (and so an English export)
            'mr' => 'Herr', 'mr.' => 'Herr', 'ms' => 'Frau', 'ms.' => 'Frau', 'mrs' => 'Frau', 'mrs.' => 'Frau',
            'mx' => 'Divers', 'mx.' => 'Divers', 'company' => 'Firma'];
        if ($salutation === '') {
            $data['salutation'] = null;
        } elseif (isset($salutations[mb_strtolower($salutation)])) {
            $data['salutation'] = $salutations[mb_strtolower($salutation)];
        } else {
            $data['salutation'] = null;
            $warnings[] = $this->l->t('Title “%s” unknown, left empty', [$salutation]);
        }

        foreach (['birthDate' => $this->l->t('Date of birth'), 'joinDate' => $this->l->t('Date joined'), 'leaveDate' => $this->l->t('Date left'), 'mandateDate' => $this->l->t('Date of signature')] as $field => $label) {
            $raw = trim($record[$field] ?? '');
            $date = $this->parseDate($raw);
            if ($raw !== '' && $date === null) {
                $errors[] = $this->l->t('%1$s “%2$s” is not a valid date (use DD.MM.YYYY or YYYY-MM-DD)', [$label, $raw]);
            }
            $data[$field] = $date;
        }

        foreach (['foundingMember' => $this->l->t('Founding member'), 'feeExemptJoinYear' => $this->l->t('Fee-exempt in the year joined'), 'deceased' => $this->l->t('Deceased')] as $field => $label) {
            $raw = mb_strtolower(trim($record[$field] ?? ''));
            if (in_array($raw, ['', 'nein', 'no', 'n', '0', 'false', 'falsch', '-'], true)) {
                $data[$field] = false;
            } elseif (in_array($raw, ['ja', 'yes', 'j', 'y', '1', 'true', 'wahr', 'x'], true)) {
                $data[$field] = true;
            } else {
                $errors[] = $this->l->t('%1$s “%2$s”: please enter Yes or No', [$label, $record[$field]]);
                $data[$field] = false;
            }
        }

        $roleRaw = mb_strtolower(trim($record['role'] ?? ''));
        if (!isset(self::ROLES[$roleRaw])) {
            $errors[] = $this->l->t('Position “%s” unknown (Member, Treasurer or Board)', [$record['role'] ?? '']);
        } elseif (self::ROLES[$roleRaw] !== 'member' && !$mayAssignRoles) {
            $warnings[] = $this->l->t('Position “%s” not applied (requires the “manage roles” permission); imported as member', [$record['role']]);
        } else {
            $data['role'] = self::ROLES[$roleRaw];
        }

        $rate = trim($record['feeRate'] ?? '');
        if ($rate !== '') {
            if (isset($ratesByName[mb_strtolower($rate)])) {
                $data['feeRateId'] = $ratesByName[mb_strtolower($rate)];
            } else {
                $errors[] = $this->l->t('Fee category “%s” does not exist in this club', [$rate]);
            }
        }

        return [$data, $errors, $warnings];
    }

    private function parseDate(string $raw): ?string {
        if ($raw === '') {
            return null;
        }
        // explicit patterns: DateTime::createFromFormat('d.m.Y') would also take "31.12.99" as the year 99
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m) === 1) {
            [$day, $month, $year] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m) === 1) {
            [$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2})$/', $raw, $m) === 1) {
            [$day, $month, $year] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            // two-digit year: in the past century unless that would be more than 5 years ahead of today
            $year += ($year > (int)date('y') + 5) ? 1900 : 2000;
        } else {
            return null;
        }
        if ($year < 1900 || !checkdate($month, $day, $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** First and last name, ignoring case and extra blanks. */
    private function nameKey(?string $firstName, ?string $name): string {
        return (string)preg_replace('/\s+/u', ' ', mb_strtolower(trim((string)$firstName) . '|' . trim((string)$name)));
    }

    /**
     * Same name counts as the same person unless both birth dates are known and differ - so "Anna Muster"
     * without a birth date is reported against "Anna Muster, 1.2.1980" rather than created twice, while a
     * father and son with the same name but different birth dates are two people.
     *
     * @param array<?string> $knownBirthDates birth dates of the people with that name (null = unknown)
     */
    private function samePerson(array $knownBirthDates, ?string $birthDate): bool {
        foreach ($knownBirthDates as $known) {
            if ($known === null || $known === '' || $birthDate === null || substr((string)$known, 0, 10) === $birthDate) {
                return true;
            }
        }
        return false;
    }

    private function normaliseHeader(string $title): string {
        $t = mb_strtolower($title);
        $t = strtr($t, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e']);
        return preg_replace('/[^a-z0-9]/', '', $t);
    }

    private function nullIfEmpty(?string $v): ?string {
        return ($v === null || trim($v) === '') ? null : trim($v);
    }
}
