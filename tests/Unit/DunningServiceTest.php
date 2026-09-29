<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\L10n\DocumentL10n;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubAccount;
use OCA\Verein\Db\ClubAccountMapper;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Fee;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Service\AuditLogService;
use OCA\Verein\Service\DunningService;
use OCA\Verein\Service\Export\PdfExporter;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DunningServiceTest extends TestCase {
    private const CLUB = 4;
    private const TODAY = '2026-06-30';

    private FeeMapper&MockObject $fees;
    private IDBConnection&MockObject $db;
    private AuditLogService&MockObject $audit;
    private DunningService $service;
    /** constructor arguments before the optional ones */
    private array $deps;

    /** @var Fee[] */
    private array $feeList = [];
    /** @var Member[] */
    private array $memberList = [];
    /** @var ClubAccount[] */
    private array $accountList = [];

    protected function setUp(): void {
        $this->fees = $this->createMock(FeeMapper::class);
        $this->fees->method('findByClub')->willReturnCallback(fn () => $this->feeList);
        $members = $this->createMock(MemberMapper::class);
        $members->method('findByClub')->willReturnCallback(fn () => $this->memberList);
        $club = new Club();
        $club->setName('Musterverein');
        $club->setStreet('Vereinsweg 1');
        $club->setPostalCode('12345');
        $club->setCity('Musterstadt');
        $clubs = $this->createMock(ClubMapper::class);
        $clubs->method('find')->willReturn($club);
        $accounts = $this->createMock(ClubAccountMapper::class);
        $accounts->method('findByClub')->willReturnCallback(fn () => $this->accountList);
        $this->db = $this->createMock(IDBConnection::class);
        $this->audit = $this->createMock(AuditLogService::class);
        $this->deps = [$this->fees, $members, $clubs, $accounts, $this->db, $this->audit];
        $this->service = new DunningService(...$this->deps, l10n: SourceL10n::fromAppLanguage('de'));
    }

    private function member(int $id, string $first, string $name, bool $deactivated = false, ?string $salutation = null): Member {
        $m = new Member();
        $m->setId($id);
        $m->setFirstName($first);
        $m->setName($name);
        $m->setSalutation($salutation);
        $m->setStreet('Musterweg ' . $id);
        $m->setPostalCode('12345');
        $m->setCity('Musterstadt');
        $ms = new Membership();
        $ms->setMemberId($id);
        $ms->setClubId(self::CLUB);
        $ms->setDeactivated($deactivated);
        $m->setMembership($ms);
        $this->memberList[] = $m;
        return $m;
    }

    private function fee(int $id, int $memberId, float $amount, string $due, string $status = 'overdue', int $level = 0, ?string $lastDunned = null): void {
        $f = new Fee();
        $f->setId($id);
        $f->setMemberId($memberId);
        $f->setClubId(self::CLUB);
        $f->setAmount($amount);
        $f->setStatus($status);
        $f->setDueDate($due . ' 00:00:00');
        $f->setPeriod(substr($due, 0, 4));
        $f->setDescription('Mitgliedsbeitrag ' . substr($due, 0, 4));
        $f->setDunningLevel($level);
        $f->setLastDunnedAt($lastDunned);
        $this->feeList[] = $f;
    }

    private function byName(array $entries): array {
        return array_column($entries, null, 'name');
    }

    public function testOverdueFeesOfOnePersonBecomeOneLetterAtTheNextLevel(): void {
        $this->member(10, 'Anna', 'Adult');
        $this->fee(1, 10, 24, '2025-03-31');
        $this->fee(2, 10, 24, '2026-03-31', 'open');               // open but past due: counts too
        $this->fee(3, 10, 24, '2026-06-20');                        // due only 10 days ago: not yet (overdueDays 14)
        $this->fee(4, 10, 24, '2025-03-31', 'paid');
        $this->fee(5, 10, 24, '2025-03-31', 'cancelled');

        $plan = $this->service->plan(self::CLUB, 14, 14, self::TODAY);

        $this->assertCount(1, $plan['included']);
        $letter = $plan['included'][0];
        $this->assertSame([1, 2], array_column($letter['fees'], 'id'));
        $this->assertSame(48.0, $letter['total']);
        $this->assertSame(1, $letter['level']);
        $this->assertSame('Zahlungserinnerung', $letter['levelLabel']);
        $this->assertTrue($letter['hasAddress']);
        $this->assertSame(48.0, $plan['total']);
    }

    public function testTheLevelFollowsTheHighestLevelSoFarAndStopsAtTheFinalLetter(): void {
        $this->member(10, 'Einmal', 'Gemahnt');
        $this->member(11, 'Zweimal', 'Gemahnt');
        $this->member(12, 'Schon', 'Letzte');
        $this->member(13, 'Gemischt', 'Neu');
        $this->fee(1, 10, 24, '2025-03-31', 'overdue', 1, '2026-05-01');
        $this->fee(2, 11, 24, '2025-03-31', 'overdue', 2, '2026-05-01');
        $this->fee(3, 12, 24, '2025-03-31', 'overdue', 3, '2026-05-01');
        $this->fee(4, 13, 24, '2025-03-31', 'overdue', 3, '2026-05-01');
        $this->fee(5, 13, 24, '2026-03-31', 'overdue', 0);          // a new debt next to one at the final level

        $plan = $this->service->plan(self::CLUB, 14, 14, self::TODAY);

        $letters = $this->byName($plan['included']);
        $this->assertSame(2, $letters['Einmal Gemahnt']['level']);
        $this->assertSame(3, $letters['Zweimal Gemahnt']['level']);
        $this->assertSame(3, $letters['Gemischt Neu']['level']);
        $this->assertSame([4, 5], array_column($letters['Gemischt Neu']['fees'], 'id'));
        $skipped = array_column($plan['skipped'], 'reason', 'name');
        $this->assertStringContainsString('höchste Mahnstufe', $skipped['Schon Letzte']);
    }

    public function testNobodyGetsASecondLetterWithinTheInterval(): void {
        $this->member(10, 'Gerade', 'Gemahnt');
        $this->member(11, 'Lange', 'Her');
        $this->fee(1, 10, 24, '2025-03-31', 'overdue', 1, '2026-06-25 10:00:00');
        $this->fee(2, 11, 24, '2025-03-31', 'overdue', 1, '2026-06-01 10:00:00');

        $plan = $this->service->plan(self::CLUB, 14, 14, self::TODAY);

        $this->assertSame(['Lange Her'], array_column($plan['included'], 'name'));
        $this->assertSame(['Gerade Gemahnt' => 'zuletzt gemahnt am 25.06.2026'], array_column($plan['skipped'], 'reason', 'name'));
    }

    public function testDeactivatedAnonymizedAndRemovedPeopleAreSkippedWithAReason(): void {
        $this->member(10, 'Pausiert', 'Mitglied', true);
        $anon = $this->member(11, '', 'Anonymisiert');
        $anon->setAnonymizedAt('2026-01-01 00:00:00');
        $this->fee(1, 10, 24, '2025-03-31');
        $this->fee(2, 11, 24, '2025-03-31');
        $this->fee(3, 99, 24, '2025-03-31');                        // person no longer in this club

        $plan = $this->service->plan(self::CLUB, 14, 14, self::TODAY);

        $this->assertSame([], $plan['included']);
        $reasons = array_column($plan['skipped'], 'reason', 'memberId');
        $this->assertStringContainsString('deaktiviert', $reasons[10]);
        $this->assertSame('anonymisiert', $reasons[11]);
        $this->assertStringContainsString('nicht mehr Mitglied', $reasons[99]);
    }

    public function testInvalidDaysAreRejected(): void {
        $this->expectException(ValidationException::class);
        $this->service->plan(self::CLUB, -1, 14, self::TODAY);
    }

    public function testPlanWritesNothing(): void {
        $this->member(10, 'Anna', 'Adult');
        $this->fee(1, 10, 24, '2025-03-31');
        $this->fees->expects($this->never())->method('markDunnedInClub');
        $this->audit->expects($this->never())->method('record');

        $this->service->plan(self::CLUB, 14, 14, self::TODAY);
    }

    public function testRunRaisesEachLevelInOneStatementInsideATransactionAndLogsIt(): void {
        $this->member(10, 'Neu', 'Gemahnt');
        $this->member(11, 'Zweite', 'Stufe');
        $this->fee(1, 10, 24, '2025-03-31');
        $this->fee(2, 10, 12, '2026-03-31', 'open');
        $this->fee(3, 11, 24, '2025-03-31', 'overdue', 1, '2026-05-01');
        $calls = [];
        $this->fees->method('markDunnedInClub')->willReturnCallback(function ($club, $ids, $level, $now) use (&$calls) {
            $calls[$level] = [$club, $ids, substr($now, 0, 10)];
            return count($ids);
        });
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->audit->expects($this->once())->method('record')
            ->with(self::CLUB, 'fee', 0, 'dunning', $this->callback(fn ($c) => $c['letters'] === 2 && $c['count'] === 3 && $c['total'] === 60.0));

        $result = $this->service->run(self::CLUB, 14, 14, self::TODAY);

        $this->assertSame([self::CLUB, [1, 2], self::TODAY], $calls[1]);
        $this->assertSame([self::CLUB, [3], self::TODAY], $calls[2]);
        $this->assertSame(2, $result['dunned']);
        $this->assertEqualsCanonicalizing([1, 2, 3], $result['feeIds']);
    }

    public function testARunWithNothingToDoWritesAndLogsNothing(): void {
        $this->fees->expects($this->never())->method('markDunnedInClub');
        $this->audit->expects($this->never())->method('record');

        $result = $this->service->run(self::CLUB, 14, 14, self::TODAY);

        $this->assertSame(0, $result['dunned']);
    }

    public function testRunRollsBackWhenAnUpdateFails(): void {
        $this->member(10, 'Anna', 'Adult');
        $this->fee(1, 10, 24, '2025-03-31');
        $this->fees->method('markDunnedInClub')->willThrowException(new \RuntimeException('db down'));
        $this->db->expects($this->once())->method('rollBack');
        $this->audit->expects($this->never())->method('record');

        $this->expectException(\RuntimeException::class);
        $this->service->run(self::CLUB, 14, 14, self::TODAY);
    }

    public function testLettersUseTheCurrentLevelAddressGreetingAndTheDefaultAccount(): void {
        $this->member(10, 'Erika', 'Mustermann', false, 'Frau');
        $this->member(11, 'Kim', 'Muster');
        $this->fee(1, 10, 24, '2025-03-31', 'overdue', 2, '2026-06-01');
        $this->fee(2, 10, 12, '2026-03-31', 'overdue', 1, '2026-06-01');
        $this->fee(3, 11, 24, '2025-03-31', 'overdue', 1, '2026-06-01');
        $this->fee(4, 11, 24, '2025-03-31', 'paid', 1, '2026-06-01'); // paid meanwhile: not in the letter
        $this->fee(5, 11, 24, '2024-03-31', 'overdue', 0);             // never dunned: not in the letter
        $a = new ClubAccount();
        $a->setIban('DE89370400440532013000');
        $a->setBic('COBADEFFXXX');
        $a->setLabel('Hauptkonto');
        $a->setIsDefault(true);
        $this->accountList = [$a];

        $data = $this->service->letters(self::CLUB, [1, 2, 3, 4, 5, 999], 14, self::TODAY);

        $this->assertSame('Musterverein', $data['club']['name']);
        $this->assertSame(['Vereinsweg 1', '12345 Musterstadt'], $data['club']['address']);
        $this->assertSame('DE89370400440532013000', $data['account']['iban']);
        $this->assertSame('14.07.2026', $data['deadline']);
        $letters = array_column($data['letters'], null, 'memberId');
        $this->assertSame(2, $letters[10]['level']);
        $this->assertSame('1. Mahnung', $letters[10]['title']);
        $this->assertSame(['Frau', 'Erika Mustermann', 'Musterweg 10', '12345 Musterstadt'], $letters[10]['address']);
        $this->assertSame('Sehr geehrte Frau Mustermann,', $letters[10]['greeting']);
        $this->assertSame(36.0, $letters[10]['total']);
        $this->assertSame('Guten Tag Kim Muster,', $letters[11]['greeting']);
        $this->assertCount(1, $letters[11]['fees']);
        $this->assertSame('Beitrag 2025, 2026, Mitglied 10', $letters[10]['reference']);
        // sorted by last name for putting them into envelopes: Muster before Mustermann
        $this->assertSame([11, 10], array_column($data['letters'], 'memberId'));
    }

    public function testThePreviewSpeaksTheUsersLanguageTheLetterTheInstanceDefault(): void {
        // an English-speaking treasurer on a German instance: the screen is English, the letter German
        $factory = $this->createMock(IFactory::class);
        $factory->method('findGenericLanguage')->with('verein')->willReturn('de');
        $factory->method('get')->with('verein', 'de')->willReturn(SourceL10n::fromAppLanguage('de'));
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnMap([['force_language', false, false], ['default_language', false, 'de']]);
        $english = new SourceL10n();
        $service = new DunningService(...$this->deps, l10n: $english, documentL10n: new DocumentL10n($factory, $config, $english));
        $this->member(10, 'Erika', 'Mustermann', false, 'Frau');
        $this->fee(1, 10, 24, '2025-03-31', 'overdue', 1, '2026-01-01');

        $plan = $service->plan(self::CLUB, 14, 14, self::TODAY);
        $this->assertSame('Second reminder', $plan['included'][0]['levelLabel']);

        $letter = $service->letters(self::CLUB, [1], 14, self::TODAY)['letters'][0];
        $this->assertSame('Zahlungserinnerung', $letter['title']);
        $this->assertSame('Sehr geehrte Frau Mustermann,', $letter['greeting']);
        $this->assertSame('Frau', $letter['address'][0]);
        $this->assertSame('Beitrag 2025, Mitglied 10', $letter['reference']);
    }

    public function testLettersRejectAnEmptySelectionAndAnOddDeadline(): void {
        foreach ([[[], 14], [[1], 0], [[1], 91]] as [$ids, $days]) {
            try {
                $this->service->letters(self::CLUB, $ids, $days, self::TODAY);
                $this->fail('expected ValidationException');
            } catch (ValidationException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testTheLettersRenderAsAPdfWithOnePagePerPerson(): void {
        if (!class_exists('TCPDF')) {
            $this->markTestSkipped('TCPDF not installed');
        }
        $this->member(10, 'Erika', 'Mustermann', false, 'Frau');
        $this->member(11, 'Kim', 'Muster');
        $this->fee(1, 10, 24, '2025-03-31', 'overdue', 1, '2026-06-01');
        $this->fee(2, 11, 24, '2025-03-31', 'overdue', 3, '2026-06-01');

        $file = (new PdfExporter())->exportDunningLetters($this->service->letters(self::CLUB, [1, 2], 14, self::TODAY));

        $this->assertStringStartsWith('%PDF', $file['content']);
        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page[^s]/', $file['content']));
        $this->assertSame('application/pdf', $file['mimeType']);
    }
}
