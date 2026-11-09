<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\AssociationReportService;
use PHPUnit\Framework\TestCase;

class AssociationReportServiceTest extends TestCase {
    private const DATE = '2026-01-01';

    /** @var Member[] */
    private array $list = [];
    private AssociationReportService $service;

    protected function setUp(): void {
        $members = $this->createMock(MemberMapper::class);
        $members->method('findByClub')->willReturnCallback(fn () => $this->list);
        $clubs = $this->createMock(ClubMapper::class);
        $club = new Club();
        $club->setName('Turnverein <Muster> & Co');
        $clubs->method('find')->willReturn($club);
        $this->service = new AssociationReportService($members, $clubs, SourceL10n::fromAppLanguage('de'));
    }

    private function member(string $name, ?string $birth, ?string $gender = null, ?string $title = null, ?string $join = '2015-01-01', ?string $leave = null, bool $deceased = false): void {
        $m = new Member();
        $m->setName($name);
        $m->setFirstName('X');
        $m->setBirthDate($birth);
        $m->setGender($gender);
        $m->setSalutation($title);
        $m->setDeceased($deceased);
        $ms = new Membership();
        $ms->setJoinDate($join);
        $ms->setLeaveDate($leave);
        $m->setMembership($ms);
        $this->list[] = $m;
    }

    public function testCountsByBirthYearAndGenderInAscendingOrder(): void {
        $this->member('A', '1990-05-05', 'M');
        $this->member('B', '1990-12-31', 'W');
        $this->member('C', '1980-01-01', 'D');
        $this->member('D', '1980-02-02', 'O');

        $report = $this->service->report(1, self::DATE);

        $this->assertSame([
            ['year' => 1980, 'M' => 0, 'W' => 0, 'D' => 1, 'O' => 1],
            ['year' => 1990, 'M' => 1, 'W' => 1, 'D' => 0, 'O' => 0],
        ], $report['rows']);
        $this->assertSame(4, $report['total']);
        $this->assertSame(1, $report['genderUnknown']);
    }

    public function testTheTitleDecidesOnlyWithoutAStoredGender(): void {
        $this->member('A', '1990-01-01', null, 'Herr');
        $this->member('B', '1990-01-01', null, 'Frau');
        $this->member('C', '1990-01-01', null, 'Divers');
        $this->member('D', '1990-01-01', null, 'Firma');
        $this->member('E', '1990-01-01', null, null);
        $this->member('F', '1990-01-01', 'W', 'Herr');
        $this->member('G', '1990-01-01', 'O', 'Herr');

        $report = $this->service->report(1, self::DATE);

        $this->assertSame(['year' => 1990, 'M' => 1, 'W' => 2, 'D' => 1, 'O' => 3], $report['rows'][0]);
        $this->assertSame(3, $report['genderFromTitle']);
        $this->assertSame(3, $report['genderUnknown']);
    }

    public function testCountsWhoIsAMemberOnTheReferenceDate(): void {
        $this->member('Tag', '1990-01-01', 'M', null, self::DATE);
        $this->member('Letzter', '1990-01-01', 'M', null, '2015-01-01', self::DATE);
        $this->member('Vorher', '1990-01-01', 'M', null, '2015-01-01', '2025-12-31');
        $this->member('Spaeter', '1990-01-01', 'M', null, '2026-01-02');
        $this->member('Verstorben', '1990-01-01', 'M', null, '2015-01-01', null, true);
        $this->member('OhneEintritt', '1990-01-01', 'M', null, null);

        $this->assertSame(3, $this->service->report(1, self::DATE)['total']);
    }

    public function testMembersWithoutABirthDateAreReportedNotCounted(): void {
        $this->member('Ohne', null, 'M');
        $this->member('Kaputt', '0000-00-00', 'M');
        $this->member('Weg', null, 'M', null, '2015-01-01', '2020-01-01');

        $report = $this->service->report(1, self::DATE);

        $this->assertSame(['X Kaputt', 'X Ohne'], $report['missingBirthDate']);
        $this->assertSame(0, $report['total']);
        $this->assertSame([], $report['rows']);
    }

    private function xpath(string $xml): \DOMXPath {
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        return new \DOMXPath($dom);
    }

    public function testTheFileFollowsTheInterface(): void {
        $this->member('A', '1990-05-05', 'M');
        $this->member('B', '1972-05-05', 'W');

        $x = $this->xpath($this->service->xml(1, self::DATE, ' 123456 ', 'Kai <Muster>', '59'));

        $this->assertSame('Nextcloud-Vereinsverwaltung', $x->evaluate('string(/Mitglieder/Software/Schluessel)'));
        $this->assertSame('123456', $x->evaluate('string(/Mitglieder/Verein/Nummer)'));
        $this->assertSame('Turnverein <Muster> & Co', $x->evaluate('string(/Mitglieder/Verein/Bezeichnung)'), 'special characters are escaped');
        $this->assertSame('Kai <Muster>', $x->evaluate('string(/Mitglieder/Verein/Ansprechpartner)'));
        $rows = [];
        foreach ($x->query('/Mitglieder/Zahlen') as $z) {
            $rows[] = array_map(fn ($child) => $child->nodeName . '=' . $child->textContent, array_values(array_filter(iterator_to_array($z->childNodes), fn ($c) => $c instanceof \DOMElement)));
        }
        $this->assertSame([
            ['Typ=A', 'Fachverband=', 'Jahrgang=1972', 'AnzahlM=0', 'AnzahlW=1', 'AnzahlD=0', 'AnzahlO=0'],
            ['Typ=A', 'Fachverband=', 'Jahrgang=1990', 'AnzahlM=1', 'AnzahlW=0', 'AnzahlD=0', 'AnzahlO=0'],
            ['Typ=B', 'Fachverband=59', 'Jahrgang=1972', 'AnzahlM=0', 'AnzahlW=1', 'AnzahlD=0', 'AnzahlO=0'],
            ['Typ=B', 'Fachverband=59', 'Jahrgang=1990', 'AnzahlM=1', 'AnzahlW=0', 'AnzahlD=0', 'AnzahlO=0'],
        ], $rows);
    }

    public function testWithoutACodeOnlyTotalsAreReported(): void {
        $this->member('A', '1990-05-05', 'M');

        $x = $this->xpath($this->service->xml(1, self::DATE, '1', '', ''));

        $this->assertSame(1.0, $x->evaluate('count(/Mitglieder/Zahlen)'));
        $this->assertSame('A', $x->evaluate('string(/Mitglieder/Zahlen/Typ)'));
    }

    /** @dataProvider invalidInput */
    public function testInvalidInputIsRefused(string $date, string $number, string $contact, string $code, string $expected): void {
        try {
            $this->service->xml(1, $date, $number, $contact, $code);
            $this->fail('accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    public static function invalidInput(): array {
        return [
            'no number' => [self::DATE, ' ', '', '', 'Vereinsnummer'],
            'long number' => [self::DATE, str_repeat('1', 21), '', '', 'Vereinsnummer'],
            'long contact' => [self::DATE, '1', str_repeat('x', 101), '', 'Ansprechperson'],
            'code with letters' => [self::DATE, '1', '', '5a', 'Verbandscode'],
            'german date' => ['01.01.2026', '1', '', '', 'Stichtag'],
            'impossible date' => ['2026-02-30', '1', '', '', 'Stichtag'],
        ];
    }
}
