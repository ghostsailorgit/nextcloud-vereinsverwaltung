<?php
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Exception\ValidationException;

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
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_ROWS = 2000;

    /** normalised header => field; see normaliseHeader() */
    private const COLUMNS = [
        'anrede' => 'salutation', 'salutation' => 'salutation',
        'vorname' => 'firstName', 'firstname' => 'firstName',
        'name' => 'name', 'nachname' => 'name', 'familienname' => 'name', 'lastname' => 'name', 'surname' => 'name',
        'strasse' => 'street', 'strassehausnummer' => 'street', 'strasseundhausnummer' => 'street', 'anschrift' => 'street', 'street' => 'street',
        'plz' => 'postalCode', 'postleitzahl' => 'postalCode', 'postalcode' => 'postalCode', 'zip' => 'postalCode',
        'ort' => 'city', 'wohnort' => 'city', 'stadt' => 'city', 'city' => 'city',
        'email' => 'email', 'mail' => 'email', 'emailadresse' => 'email',
        'iban' => 'iban', 'bic' => 'bic',
        'geburtsdatum' => 'birthDate', 'geburtstag' => 'birthDate', 'birthdate' => 'birthDate',
        'eintritt' => 'joinDate', 'eintrittsdatum' => 'joinDate', 'mitgliedseit' => 'joinDate', 'joindate' => 'joinDate',
        'austritt' => 'leaveDate', 'austrittsdatum' => 'leaveDate', 'leavedate' => 'leaveDate',
        'rolle' => 'role', 'funktion' => 'role', 'role' => 'role',
        'beitragskategorie' => 'feeRate', 'kategorie' => 'feeRate', 'feerate' => 'feeRate',
        'mandatsreferenz' => 'mandateReference', 'mandatereference' => 'mandateReference',
        'mandatsdatum' => 'mandateDate', 'mandatunterschriebenam' => 'mandateDate', 'mandatedate' => 'mandateDate',
        'grundungsmitglied' => 'foundingMember', 'gruendungsmitglied' => 'foundingMember', 'foundingmember' => 'foundingMember',
        'verstorben' => 'deceased', 'deceased' => 'deceased',
    ];

    /** columns of the app's own export that carry nothing to import */
    private const IGNORED = ['id', 'alter', 'mitgliedseitjahre', 'erstelltam', 'nr'];

    private const ROLES = [
        'mitglied' => 'member', 'member' => 'member', '' => 'member',
        'vorstand' => 'admin', 'admin' => 'admin',
        'kassierer' => 'treasurer', 'kassiererin' => 'treasurer', 'kassenwart' => 'treasurer', 'kassenwartin' => 'treasurer', 'treasurer' => 'treasurer',
    ];

    public function __construct(
        private MemberService $members,
        private MemberMapper $memberMapper,
        private FeeRateMapper $rates,
        private ClubMapper $clubs,
        private ValidationService $validation
    ) {
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
                    $errors[] = 'ist schon Mitglied in diesem Verein';
                } else {
                    foreach ($seen[$key] ?? [] as [$otherLine, $otherBirth]) {
                        if ($this->samePerson([$otherBirth], $birth)) {
                            $status = 'duplicate';
                            $errors[] = 'steht schon in Zeile ' . $otherLine . ' dieser Datei';
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
            throw new ValidationException('Bitte 1 bis 100 Zeilen auf einmal importieren');
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
                $failed[] = ['line' => $row['line'], 'name' => $row['name'], 'reason' => 'interner Fehler'];
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
            throw new ValidationException('Die Datei ist zu groß (höchstens 2 MB)');
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (!mb_check_encoding($csv, 'UTF-8')) {
            // spreadsheets on Windows often save CSV as Windows-1252
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        if (trim($csv) === '') {
            throw new ValidationException('Die Datei ist leer');
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
            throw new ValidationException('Die erste Zeile muss die Spaltenüberschriften enthalten');
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
            throw new ValidationException('Es fehlt eine Spalte „Name“ (oder „Nachname“)');
        }

        $records = [];
        $line = 1;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map(fn ($c) => trim((string)$c), $cells)) === '') {
                continue;
            }
            if (count($records) >= self::MAX_ROWS) {
                throw new ValidationException('Zu viele Zeilen (höchstens ' . self::MAX_ROWS . ')');
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
            throw new ValidationException('Die Datei enthält keine Datenzeilen');
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
            'divers' => 'Divers', 'firma' => 'Firma'];
        if ($salutation === '') {
            $data['salutation'] = null;
        } elseif (isset($salutations[mb_strtolower($salutation)])) {
            $data['salutation'] = $salutations[mb_strtolower($salutation)];
        } else {
            $data['salutation'] = null;
            $warnings[] = 'Anrede „' . $salutation . '“ unbekannt, wird weggelassen';
        }

        foreach (['birthDate' => 'Geburtsdatum', 'joinDate' => 'Eintrittsdatum', 'leaveDate' => 'Austrittsdatum', 'mandateDate' => 'Mandatsdatum'] as $field => $label) {
            $raw = trim($record[$field] ?? '');
            $date = $this->parseDate($raw);
            if ($raw !== '' && $date === null) {
                $errors[] = $label . ' „' . $raw . '“ ist kein Datum (TT.MM.JJJJ)';
            }
            $data[$field] = $date;
        }

        foreach (['foundingMember' => 'Gründungsmitglied', 'deceased' => 'Verstorben'] as $field => $label) {
            $raw = mb_strtolower(trim($record[$field] ?? ''));
            if (in_array($raw, ['', 'nein', 'no', 'n', '0', 'false', 'falsch', '-'], true)) {
                $data[$field] = false;
            } elseif (in_array($raw, ['ja', 'yes', 'j', 'y', '1', 'true', 'wahr', 'x'], true)) {
                $data[$field] = true;
            } else {
                $errors[] = $label . ' „' . $record[$field] . '“: bitte Ja oder Nein';
                $data[$field] = false;
            }
        }

        $roleRaw = mb_strtolower(trim($record['role'] ?? ''));
        if (!isset(self::ROLES[$roleRaw])) {
            $errors[] = 'Funktion „' . ($record['role'] ?? '') . '“ unbekannt (Mitglied, Kassierer oder Vorstand)';
        } elseif (self::ROLES[$roleRaw] !== 'member' && !$mayAssignRoles) {
            $warnings[] = 'Funktion „' . $record['role'] . '“ nicht übernommen (nur mit dem Recht „Rollen verwalten“), angelegt als Mitglied';
        } else {
            $data['role'] = self::ROLES[$roleRaw];
        }

        $rate = trim($record['feeRate'] ?? '');
        if ($rate !== '') {
            if (isset($ratesByName[mb_strtolower($rate)])) {
                $data['feeRateId'] = $ratesByName[mb_strtolower($rate)];
            } else {
                $errors[] = 'Beitragskategorie „' . $rate . '“ gibt es in diesem Verein nicht';
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
