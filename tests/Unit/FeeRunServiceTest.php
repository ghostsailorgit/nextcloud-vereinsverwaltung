<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Fee;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\FeeRate;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Service\FeeRunService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FeeRunServiceTest extends TestCase {
    private const CLUB = 4;

    private FeeRateMapper&MockObject $rates;
    private MemberMapper&MockObject $members;
    private FeeMapper&MockObject $fees;
    private IDBConnection&MockObject $db;
    private FeeRunService $service;

    /** @var FeeRate[] */
    private array $rateList = [];
    /** @var Member[] */
    private array $memberList = [];
    /** @var Fee[] */
    private array $existingFees = [];

    protected function setUp(): void {
        $this->rates = $this->createMock(FeeRateMapper::class);
        $this->members = $this->createMock(MemberMapper::class);
        $this->fees = $this->createMock(FeeMapper::class);
        $this->db = $this->createMock(IDBConnection::class);
        $clubs = $this->createMock(ClubMapper::class);

        $this->rates->method('findByClub')->willReturnCallback(fn () => $this->rateList);
        $this->members->method('findByClub')->willReturnCallback(fn () => $this->memberList);
        $this->fees->method('findByClub')->willReturnCallback(fn () => $this->existingFees);

        $this->service = new FeeRunService($this->rates, $this->members, $this->fees, $clubs, $this->db, l10n: SourceL10n::fromAppLanguage('de'));
    }

    private function rate(int $id, string $name, float $amount, bool $default = false): void {
        $r = new FeeRate();
        $r->setId($id);
        $r->setClubId(self::CLUB);
        $r->setName($name);
        $r->setAmount($amount);
        $r->setIsDefault($default);
        $this->rateList[] = $r;
    }

    private function member(int $id, string $first, string $name, ?int $rateId = null, ?string $join = '2015-01-01', ?string $leave = null, bool $deceased = false, bool $feeExemptJoinYear = false): void {
        $m = new Member();
        $m->setId($id);
        $m->setFirstName($first);
        $m->setName($name);
        $m->setDeceased($deceased);
        $ms = new Membership();
        $ms->setMemberId($id);
        $ms->setClubId(self::CLUB);
        $ms->setFeeRateId($rateId);
        $ms->setJoinDate($join);
        $ms->setLeaveDate($leave);
        $ms->setFeeExemptJoinYear($feeExemptJoinYear);
        $m->setMembership($ms);
        $this->memberList[] = $m;
    }

    private function existingFee(int $memberId, string $period, string $status = 'open'): void {
        $f = new Fee();
        $f->setMemberId($memberId);
        $f->setPeriod($period);
        $f->setStatus($status);
        $this->existingFees[] = $f;
    }

    private function names(array $entries): array {
        return array_column($entries, 'name');
    }

    public function testEveryActiveMemberGetsTheirCategoryOrTheDefault(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->rate(2, 'Kinder', 8.5);
        $this->member(10, 'Anna', 'Adult');            // no category -> default
        $this->member(11, 'Kim', 'Kind', 2);           // explicit
        $this->member(12, 'Bea', 'Adult', 1);

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $byName = array_column($plan['included'], 'amount', 'name');
        $this->assertSame(24.0, $byName['Anna Adult']);
        $this->assertSame(8.5, $byName['Kim Kind']);
        $this->assertSame(24.0, $byName['Bea Adult']);
        $this->assertSame(56.5, $plan['total']);
        $this->assertSame('Mitgliedsbeitrag 2026', $plan['description']);
        $this->assertSame([], $plan['skipped']);
    }

    public function testSkipsWithAReasonForEverySpecialCase(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->rate(2, 'Ehrenmitglied', 0.0);
        $this->member(10, 'Ok', 'Aktiv');
        $this->member(11, 'Ex', 'Mitglied', null, '2010-01-01', '2025-12-31');
        $this->member(12, 'Tot', 'Mitglied', null, '2010-01-01', null, true);
        $this->member(13, 'Neu', 'Mitglied', null, '2027-02-01');
        $this->member(14, 'Ehren', 'Mitglied', 2);
        $this->member(15, 'Schon', 'Bezahlt');
        $this->existingFee(15, '2026');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertSame(['Ok Aktiv'], $this->names($plan['included']));
        $reasons = array_column($plan['skipped'], 'reason', 'name');
        $this->assertStringContainsString('ausgetreten', $reasons['Ex Mitglied']);
        $this->assertStringContainsString('verstorben', $reasons['Tot Mitglied']);
        $this->assertStringContainsString('2026', $reasons['Neu Mitglied']);
        $this->assertStringContainsString('beitragsfrei', $reasons['Ehren Mitglied']);
        $this->assertStringContainsString('schon vorhanden', $reasons['Schon Bezahlt']);
    }

    public function testDeactivatedMembersGetNoFee(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->member(10, 'Ok', 'Aktiv');
        $this->member(11, 'Pausiert', 'Mitglied');
        $this->memberList[1]->getMembership()->setDeactivated(true);

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertSame(['Ok Aktiv'], $this->names($plan['included']));
        $this->assertSame(['Pausiert Mitglied' => 'deaktiviert'], array_column($plan['skipped'], 'reason', 'name'));
    }

    public function testMembersWithoutCategoryAreSkippedWhenNoDefaultExists(): void {
        $this->rate(2, 'Kinder', 8.5);
        $this->member(10, 'Anna', 'Ohne');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertSame([], $plan['included']);
        $this->assertSame('keine Beitragskategorie', $plan['skipped'][0]['reason']);
    }

    public function testACancelledFeeOrAnotherYearDoesNotBlockTheRun(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->member(10, 'Anna', 'A');
        $this->member(11, 'Bert', 'B');
        $this->existingFee(10, '2026', 'cancelled');
        $this->existingFee(11, '2025');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertCount(2, $plan['included']);
    }

    public function testPlanWritesNothing(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->member(10, 'Anna', 'A');
        $this->fees->expects($this->never())->method('insert');
        $this->db->expects($this->never())->method('beginTransaction');

        $this->service->plan(self::CLUB, 2026, '2026-03-31');
    }

    public function testRunCreatesOpenFeesWithPeriodDueDateAndDescriptionInOneTransaction(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->member(10, 'Anna', 'A');
        $this->member(11, 'Bert', 'B');
        $inserted = [];
        $this->fees->method('insert')->willReturnCallback(function (Fee $f) use (&$inserted) {
            $inserted[] = $f;
            return $f;
        });
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $result = $this->service->run(self::CLUB, 2026, '2026-03-31', 'Jahresbeitrag');

        $this->assertSame(2, $result['created']);
        $this->assertCount(2, $inserted);
        $fee = $inserted[0];
        $this->assertSame(self::CLUB, $fee->getClubId());
        $this->assertSame(24.0, $fee->getAmount());
        $this->assertSame('open', $fee->getStatus());
        $this->assertSame('2026-03-31 00:00:00', $fee->getDueDate());
        $this->assertSame('2026', $fee->getPeriod());
        $this->assertSame('Jahresbeitrag', $fee->getDescription());
    }

    public function testRunRollsBackWhenAnInsertFails(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->member(10, 'Anna', 'A');
        $this->fees->method('insert')->willThrowException(new \RuntimeException('db down'));
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->service->run(self::CLUB, 2026, '2026-03-31');
    }

    public function testWithoutProrataMidYearJoinersPayTheFullAmount(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->member(10, 'Neu', 'Mitglied', null, '2026-10-15');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertSame(24.0, $plan['included'][0]['amount']);
        $this->assertArrayNotHasKey('months', $plan['included'][0]);
        $this->assertFalse($plan['prorata']);
    }

    public function testProrataChargesFromTheJoinMonthOfTheFeeYearOnly(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->rate(2, 'Jugend', 10.0);
        $this->member(10, 'Alt', 'Mitglied', null, '2015-06-01');   // earlier year -> full
        $this->member(11, 'Jan', 'Mitglied', null, '2026-01-20');   // January -> full, not marked
        $this->member(12, 'Okt', 'Mitglied', null, '2026-10-15');   // Oct-Dec = 3 months
        $this->member(13, 'Dez', 'Jugend', 2, '2026-12-31');        // 1 month of 10 € -> 0.83
        $this->member(14, 'Ohne', 'Datum', null, null);             // unknown join -> full

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31', null, true);

        $byName = [];
        foreach ($plan['included'] as $e) {
            $byName[$e['name']] = $e;
        }
        $this->assertSame(24.0, $byName['Alt Mitglied']['amount']);
        $this->assertSame(24.0, $byName['Jan Mitglied']['amount']);
        $this->assertArrayNotHasKey('months', $byName['Jan Mitglied']);
        $this->assertSame(6.0, $byName['Okt Mitglied']['amount']);
        $this->assertSame(3, $byName['Okt Mitglied']['months']);
        $this->assertSame(24.0, $byName['Okt Mitglied']['fullAmount']);
        $this->assertSame(0.83, $byName['Dez Jugend']['amount']);
        $this->assertSame(1, $byName['Dez Jugend']['months']);
        $this->assertSame(24.0, $byName['Ohne Datum']['amount']);
        $this->assertSame(78.83, $plan['total']);
        $this->assertTrue($plan['prorata']);
    }

    public function testProrataAmountRoundingToZeroIsSkippedNotBilled(): void {
        $this->rate(1, 'Symbolisch', 0.05, true);
        $this->member(10, 'Dez', 'Mitglied', null, '2026-12-01');   // 0.05 / 12 -> 0.00

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31', null, true);

        $this->assertSame([], $plan['included']);
        $this->assertStringContainsString('anteilig', $plan['skipped'][0]['reason']);
    }

    public function testRunWritesTheProrataAmount(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->member(10, 'Okt', 'Mitglied', null, '2026-10-15');
        $inserted = [];
        $this->fees->method('insert')->willReturnCallback(function (Fee $f) use (&$inserted) {
            $inserted[] = $f;
            return $f;
        });

        $this->service->run(self::CLUB, 2026, '2026-03-31', null, true);

        $this->assertSame(6.0, $inserted[0]->getAmount());
    }

    public function testFeeExemptJoinYearSkipsOnlyTheYearJoined(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        // joined this fee year, exempt -> skipped; joined an earlier year, exempt -> billed normally
        $this->member(10, 'Neu', 'Mitglied', null, '2026-06-01', null, false, true);
        $this->member(11, 'Alt', 'Mitglied', null, '2015-01-01', null, false, true);

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertSame(['Alt Mitglied'], $this->names($plan['included']));
        $reasons = array_column($plan['skipped'], 'reason', 'name');
        $this->assertStringContainsString('beitragsfrei', $reasons['Neu Mitglied']);
    }

    public function testFeeExemptJoinYearTakesPrecedenceOverProrata(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->member(10, 'Neu', 'Mitglied', null, '2026-06-01', null, false, true);

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31', null, true);

        $this->assertSame([], $plan['included']);
        $this->assertStringContainsString('beitragsfrei', $plan['skipped'][0]['reason']);
    }

    public function testRunWritesNoFeeForAFeeExemptJoinYear(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->member(10, 'Neu', 'Mitglied', null, '2026-06-01', null, false, true);
        $this->fees->expects($this->never())->method('insert');

        $result = $this->service->run(self::CLUB, 2026, '2026-03-31');

        $this->assertSame(0, $result['created']);
    }

    public function testInvalidYearOrDateIsRejected(): void {
        try {
            $this->service->plan(self::CLUB, 1999, '2026-03-31');
            $this->fail('year');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Jahr', $e->getMessage());
        }
        try {
            $this->service->plan(self::CLUB, 2026, '31.03.2026');
            $this->fail('date');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Fälligkeitsdatum', $e->getMessage());
        }
        try {
            $this->service->plan(self::CLUB, 2026, '2026-02-30');
            $this->fail('impossible date');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Fälligkeitsdatum', $e->getMessage());
        }
    }
}
