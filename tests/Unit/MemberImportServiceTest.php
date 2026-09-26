<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeRate;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Service\Export\CsvExporter;
use OCA\Verein\Service\MemberImportService;
use OCA\Verein\Service\MemberService;
use OCA\Verein\Service\ValidationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MemberImportServiceTest extends TestCase {
    private const CLUB = 4;

    private MemberService&MockObject $members;
    private MemberImportService $service;
    /** @var Member[] */
    private array $existing = [];

    protected function setUp(): void {
        $this->members = $this->createMock(MemberService::class);
        $mapper = $this->createMock(MemberMapper::class);
        $mapper->method('findByClub')->willReturnCallback(fn () => $this->existing);
        $rates = $this->createMock(FeeRateMapper::class);
        $adult = new FeeRate();
        $adult->setId(7);
        $adult->setName('Erwachsene');
        $youth = new FeeRate();
        $youth->setId(8);
        $youth->setName('Jugend');
        $rates->method('findByClub')->willReturn([$adult, $youth]);
        $this->service = new MemberImportService($this->members, $mapper, $rates, $this->createMock(ClubMapper::class), new ValidationService(l10n: SourceL10n::fromAppLanguage('de')), l10n: SourceL10n::fromAppLanguage('de'));
    }

    private function existingMember(string $first, string $name, ?string $birth): void {
        $m = new Member();
        $m->setFirstName($first);
        $m->setName($name);
        $m->setBirthDate($birth);
        $this->existing[] = $m;
    }

    private function byLine(array $plan): array {
        return array_column($plan['rows'], null, 'line');
    }

    public function testTheAppsOwnExportCanBeImportedAgain(): void {
        // header and value formats exactly as CsvExporter::formatMembers() writes them
        $csv = "ID;Anrede;Vorname;Name;Straße;PLZ;Ort;Email;Rolle;IBAN;BIC;Geburtsdatum;Alter;Eintrittsdatum;Mitglied seit (Jahre);Austrittsdatum;Gründungsmitglied;Verstorben;Erstellt am\n"
            . "12;Frau;Erika;Mustermann;Musterweg 1;12345;Musterstadt;erika@example.org;member;DE89370400440532013000;COBADEFFXXX;1980-02-01;46;2010-05-01;16;;Ja;Nein;2026-01-01 10:00:00\n";

        $plan = $this->service->plan(self::CLUB, $csv, true);

        $this->assertSame([], $plan['ignoredColumns']);
        $this->assertSame(['ok' => 1, 'error' => 0, 'duplicate' => 0], $plan['counts']);
        $data = $plan['rows'][0]['data'];
        $this->assertSame('Frau', $data['salutation']);
        $this->assertSame('Erika', $data['firstName']);
        $this->assertSame('Mustermann', $data['name']);
        $this->assertSame('Musterweg 1', $data['street']);
        $this->assertSame('DE89370400440532013000', $data['iban']);
        $this->assertSame('1980-02-01', $data['birthDate']);
        $this->assertSame('2010-05-01', $data['joinDate']);
        $this->assertNull($data['leaveDate']);
        $this->assertTrue($data['foundingMember']);
        $this->assertFalse($data['deceased']);
        $this->assertNull($data['userId']);
        $this->assertSame(2, $plan['rows'][0]['line']);
    }

    public function testTheEnglishExportCanBeImportedAgainToo(): void {
        // headers exactly as CsvExporter writes them for an English-speaking user (no translation table = English)
        $export = (new CsvExporter())->formatMembers([[
            'id' => 12, 'salutation' => 'Frau', 'firstName' => 'Erika', 'name' => 'Mustermann', 'street' => 'Musterweg 1',
            'postalCode' => '12345', 'city' => 'Musterstadt', 'email' => 'erika@example.org', 'role' => 'member',
            'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'birthDate' => '1980-02-01', 'age' => 46,
            'joinDate' => '2010-05-01', 'membershipYears' => 16, 'leaveDate' => '', 'foundingMember' => true, 'deceased' => false,
            'createdAt' => '2026-01-01 10:00:00',
        ]]);
        $this->assertContains('Founding member', $export['headers']);
        $csv = implode(';', $export['headers']) . "\n" . implode(';', array_map('strval', $export['data'][0])) . "\n";

        $plan = $this->service->plan(self::CLUB, $csv, true);

        $this->assertSame([], $plan['ignoredColumns']);
        $this->assertSame(['ok' => 1, 'error' => 0, 'duplicate' => 0], $plan['counts']);
        $data = $plan['rows'][0]['data'];
        $this->assertSame('Frau', $data['salutation']);
        $this->assertSame('Erika', $data['firstName']);
        $this->assertSame('Musterweg 1', $data['street']);
        $this->assertSame('12345', $data['postalCode']);
        $this->assertSame('1980-02-01', $data['birthDate']);
        $this->assertSame('2010-05-01', $data['joinDate']);
        $this->assertTrue($data['foundingMember']);
        $this->assertFalse($data['deceased']);
    }

    public function testSpreadsheetStyleFileWithCommasGermanDatesAndOtherHeaderNames(): void {
        $csv = "\xEF\xBB\xBFNachname,Vorname,Geburtstag,Eintritt,E-Mail,IBAN,Beitragskategorie,Bemerkung\n"
            . "Muster,Kim,01.02.1980,1.5.2010,,DE89 3704 0044 0532 0130 00,jugend,\"Notiz mit, Komma\nund Zeilenumbruch\"\n"
            . "\n"
            . "Beispiel,Bo,31.12.99,,,,,\n";

        $plan = $this->service->plan(self::CLUB, $csv, false);

        $this->assertSame(['Bemerkung'], $plan['ignoredColumns']);
        $rows = $this->byLine($plan);
        $this->assertSame('ok', $rows[2]['status']);
        $this->assertSame('1980-02-01', $rows[2]['data']['birthDate']);
        $this->assertSame('2010-05-01', $rows[2]['data']['joinDate']);
        $this->assertSame('DE89370400440532013000', $rows[2]['data']['iban']);
        $this->assertSame(8, $rows[2]['data']['feeRateId']);
        // line numbers are spreadsheet rows: a quoted line break stays in its row, a blank row still counts
        $this->assertSame('ok', $rows[4]['status']);
        $this->assertSame('1999-12-31', $rows[4]['data']['birthDate']);
    }

    public function testWindows1252FilesAreConverted(): void {
        $csv = mb_convert_encoding("Vorname;Name;Ort\nJürgen;Müller;Köln\n", 'Windows-1252', 'UTF-8');

        $plan = $this->service->plan(self::CLUB, $csv, false);

        $this->assertSame('Müller', $plan['rows'][0]['data']['name']);
        $this->assertSame('Köln', $plan['rows'][0]['data']['city']);
    }

    public function testInvalidValuesAreReportedPerRow(): void {
        $csv = "Vorname;Name;IBAN;Geburtsdatum;Verstorben;Funktion;Beitragskategorie;Anrede\n"
            . "A;Gut;;;nein;Mitglied;;Hr.\n"
            . "B;Iban;DE00123;;;;;\n"
            . "C;Datum;;30.02.1990;;;;\n"
            . "C;Jahr;;01.01.0099;;;;\n"
            . "D;Bool;;;vielleicht;;;\n"
            . "E;Rolle;;;;Präsident;;\n"
            . "F;Kategorie;;;;;Senioren;\n"
            . "G;Xaver;;;;;;Dr.\n"
            . ";;;;;;;\n"
            . "H;;;;;;;\n";

        $plan = $this->service->plan(self::CLUB, $csv, true);
        $rows = $this->byLine($plan);

        $this->assertSame('ok', $rows[2]['status']);
        $this->assertSame('Herr', $rows[2]['data']['salutation']);
        $this->assertStringContainsString('IBAN', implode(' ', $rows[3]['messages']));
        $this->assertStringContainsString('Geburtsdatum', implode(' ', $rows[4]['messages']));
        $this->assertStringContainsString('0099', implode(' ', $rows[5]['messages']));
        $this->assertStringContainsString('Ja oder Nein', implode(' ', $rows[6]['messages']));
        $this->assertStringContainsString('Präsident', implode(' ', $rows[7]['messages']));
        $this->assertStringContainsString('Senioren', implode(' ', $rows[8]['messages']));
        $this->assertSame('ok', $rows[9]['status']);
        $this->assertStringContainsString('Dr.', implode(' ', $rows[9]['warnings']));
        $this->assertArrayNotHasKey(10, $rows, 'an empty line is skipped');
        $this->assertSame('error', $rows[11]['status']);
        $this->assertStringContainsString('Name', implode(' ', $rows[11]['messages']));
        $this->assertSame(['ok' => 2, 'error' => 7, 'duplicate' => 0], $plan['counts']);
        foreach ($plan['rows'] as $row) {
            $this->assertStringNotContainsString('DE00123', implode(' ', $row['messages']), 'no bank data echoed in messages');
        }
    }

    public function testRolesNeedTheRoleManagePermission(): void {
        $csv = "Vorname;Name;Funktion\nA;Vorstand;Vorstand\nB;Kasse;Kassenwart\nC;Normal;\n";

        $with = $this->byLine($this->service->plan(self::CLUB, $csv, true));
        $without = $this->byLine($this->service->plan(self::CLUB, $csv, false));

        $this->assertSame('admin', $with[2]['data']['role']);
        $this->assertSame('treasurer', $with[3]['data']['role']);
        $this->assertSame('member', $without[2]['data']['role']);
        $this->assertSame('member', $without[3]['data']['role']);
        $this->assertStringContainsString('Rollen verwalten', implode(' ', $without[2]['warnings']));
        $this->assertSame('member', $without[4]['data']['role']);
        $this->assertSame([], $without[4]['warnings']);
    }

    public function testDuplicatesInTheClubAndInTheFile(): void {
        $this->existingMember('Anna', 'Muster', '1980-02-01');
        $this->existingMember('Hans', 'Vater', '1950-01-01');
        $csv = "Vorname;Name;Geburtsdatum\n"
            . "anna ;MUSTER;\n"                  // same name, no birth date: duplicate of the member
            . "Hans;Vater;01.01.1985\n"          // same name, other birth date: a different person (the son)
            . "Neu;Person;01.01.1990\n"
            . "Neu;Person;01.01.1990\n"          // twice in the file
            . "Neu;Person;02.02.1992\n";         // same name, other birth date: fine

        $rows = $this->byLine($this->service->plan(self::CLUB, $csv, false));

        $this->assertSame('duplicate', $rows[2]['status']);
        $this->assertStringContainsString('schon Mitglied', $rows[2]['messages'][0]);
        $this->assertSame('ok', $rows[3]['status']);
        $this->assertSame('ok', $rows[4]['status']);
        $this->assertSame('duplicate', $rows[5]['status']);
        $this->assertStringContainsString('Zeile 4', $rows[5]['messages'][0]);
        $this->assertSame('ok', $rows[6]['status']);
    }

    /**
     * The linked Nextcloud account drives automatic rights (CLAUDE.md rule 3); an import must never set it,
     * whatever the column is called.
     */
    public function testAnAccountColumnIsNeverImported(): void {
        $csv = "Vorname;Name;userId;user_id;NC-Konto;Konto;Nextcloud-Konto\nA;Muster;admin;admin;admin;admin;admin\n";

        $plan = $this->service->plan(self::CLUB, $csv, true);

        $this->assertNull($plan['rows'][0]['data']['userId']);
        $this->assertSame(['userId', 'user_id', 'NC-Konto', 'Konto', 'Nextcloud-Konto'], $plan['ignoredColumns']);
    }

    public function testFormulaGuardOfTheExportIsRemoved(): void {
        $plan = $this->service->plan(self::CLUB, "Vorname;Name;Ort\nA;'-Strich;'=Stadt\n", false);

        $this->assertSame('-Strich', $plan['rows'][0]['data']['name']);
        $this->assertSame('=Stadt', $plan['rows'][0]['data']['city']);
    }

    public function testFileLevelProblemsAreRejected(): void {
        $cases = [
            '' => 'leer',
            "Vorname;Ort\nA;B\n" => 'Name',
            "Vorname;Name\n" => 'keine Datenzeilen',
            str_repeat('x', MemberImportService::MAX_BYTES + 1) => 'zu groß',
        ];
        foreach ($cases as $csv => $expected) {
            try {
                $this->service->plan(self::CLUB, (string)$csv, false);
                $this->fail('expected ValidationException for ' . $expected);
            } catch (ValidationException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function testTooManyRowsAreRejected(): void {
        $csv = "Vorname;Name\n" . str_repeat("A;Muster\n", MemberImportService::MAX_ROWS + 1);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Zu viele Zeilen');
        $this->service->plan(self::CLUB, $csv, false);
    }

    public function testImportCreatesOnlyTheRequestedValidLinesAndReportsTheRest(): void {
        $this->existingMember('Schon', 'Da', null);
        $csv = "Vorname;Name;IBAN\nA;Eins;\nB;Zwei;\nC;Kaputt;DE00\nSchon;Da;\nD;Fehlt;\n";
        $created = [];
        $this->members->method('create')->willReturnCallback(function (int $club, array $data) use (&$created) {
            if ($data['name'] === 'Zwei') {
                throw new \RuntimeException('SQLSTATE[23000] secret');
            }
            $created[] = [$club, $data['name']];
            $m = new Member();
            $m->setId(100 + count($created));
            return $m;
        });

        $result = $this->service->import(self::CLUB, $csv, false, [2, 3, 4, 5]);

        $this->assertSame([[self::CLUB, 'Eins']], $created);
        $this->assertSame([['line' => 2, 'name' => 'A Eins', 'memberId' => 101]], $result['created']);
        $this->assertSame([['line' => 3, 'name' => 'B Zwei', 'reason' => 'interner Fehler']], $result['failed']);
        $this->assertSame([4, 5], array_column($result['skipped'], 'line'));
    }

    public function testImportAcceptsOnlySmallChunks(): void {
        foreach ([[], range(2, 102)] as $lines) {
            try {
                $this->service->import(self::CLUB, "Vorname;Name\nA;B\n", false, $lines);
                $this->fail('expected ValidationException');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('100', $e->getMessage());
            }
        }
    }
}
