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

    public function testHonoraryMembersPayNothingUnlessTheyChooseTo(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->member(10, 'Ehren', 'Frei');
        $this->memberList[0]->getMembership()->setHonoraryMember(true);
        $this->member(11, 'Ehren', 'Zahlt');
        $this->memberList[1]->getMembership()->setHonoraryMember(true);
        $this->memberList[1]->getMembership()->setHonoraryPaysFee(true);
        $this->member(12, 'Normal', 'Mitglied');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertSame(['Normal Mitglied', 'Ehren Zahlt'], $this->names($plan['included']));
        $this->assertSame(['Ehren Frei' => 'Ehrenmitglied'], array_column($plan['skipped'], 'reason', 'name'));
        $amounts = array_column($plan['included'], 'amount', 'name');
        $this->assertSame(24.0, $amounts['Ehren Zahlt']);
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

    private function tieredRate(int $id, string $name, array $tiers, ?string $cutoff = '12-31', bool $default = true): void {
        $this->rate($id, $name, 0.0, $default);
        $this->rateList[count($this->rateList) - 1]->setAgeTiers(json_encode($tiers));
        $this->rateList[count($this->rateList) - 1]->setAgeCutoff($cutoff);
    }

    private const TIERS = [['fromAge' => 0, 'amount' => 0.0], ['fromAge' => 6, 'amount' => 10.0], ['fromAge' => 14, 'amount' => 15.0], ['fromAge' => 18, 'amount' => 25.0]];

    private function born(int $index, ?string $birthDate): void {
        $this->memberList[$index]->setBirthDate($birthDate);
    }

    public function testTheAgeOnDecember31OfTheFeeYearPicksTheTier(): void {
        $this->tieredRate(1, 'Verein', self::TIERS);
        $this->member(10, 'Klein', 'Kind');
        $this->born(0, '2021-12-31');    // 5 -> 0 €, exempt
        $this->member(11, 'Sechs', 'Kind');
        $this->born(1, '2020-12-31');    // turns 6 on the last day
        $this->member(12, 'Dreizehn', 'Kind');
        $this->born(2, '2013-01-01');    // 13
        $this->member(13, 'Vierzehn', 'Kind');
        $this->born(3, '2012-12-31');    // 14
        $this->member(14, 'Erst', 'Erwachsen');
        $this->born(4, '2008-12-31');    // 18
        $this->member(15, 'Alt', 'Erwachsen');
        $this->born(5, '1950-06-15');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertEquals([
            'Sechs Kind' => 10.0, 'Dreizehn Kind' => 10.0, 'Vierzehn Kind' => 15.0, 'Erst Erwachsen' => 25.0, 'Alt Erwachsen' => 25.0,
        ], array_column($plan['included'], 'amount', 'name'));
        $this->assertSame(['Klein Kind' => 'beitragsfrei (Verein)'], array_column($plan['skipped'], 'reason', 'name'));
        $this->assertSame(85.0, $plan['total']);
    }

    public function testAnotherCutOffDayCountsOnlyBirthdaysUpToThatDay(): void {
        $this->tieredRate(1, 'Verein', self::TIERS, '01-01');
        $this->member(10, 'Neujahr', 'Kind');
        $this->born(0, '2008-01-01');    // already 18 on January 1
        $this->member(11, 'Zweiter', 'Kind');
        $this->born(1, '2008-01-02');    // still 17

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertSame(['Neujahr Kind' => 25.0, 'Zweiter Kind' => 15.0], array_column($plan['included'], 'amount', 'name'));
    }

    public function testWithoutABirthDateAByAgeCategoryReportsInsteadOfGuessing(): void {
        $this->tieredRate(1, 'Verein', self::TIERS);
        $this->member(10, 'Ohne', 'Datum');
        $this->member(11, 'Kaputt', 'Datum');
        $this->born(1, '0000-00-00');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertSame([], $plan['included']);
        $this->assertCount(2, $plan['skipped']);
        $this->assertStringContainsString('Geburtsdatum', $plan['skipped'][0]['reason']);
        $this->assertStringContainsString('Geburtsdatum', $plan['skipped'][1]['reason']);
    }

    public function testAFlatCategoryDoesNotNeedABirthDate(): void {
        $this->rate(1, 'Erwachsene', 24.0, true);
        $this->member(10, 'Ohne', 'Datum');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertSame(24.0, $plan['included'][0]['amount']);
        $this->assertArrayNotHasKey('ageFrom', $plan['included'][0]);
    }

    public function testThePreviewShowsWhoMovedToAnotherTier(): void {
        $this->tieredRate(1, 'Verein', self::TIERS);
        $this->member(10, 'Wechselt', 'Jetzt');
        $this->born(0, '2008-05-05');    // 18 in 2026, 17 in 2025
        $this->member(11, 'Bleibt', 'Gleich');
        $this->born(1, '2000-05-05');
        $this->member(12, 'Neu', 'Eingetreten', null, '2026-02-01');
        $this->born(2, '2008-05-05');    // moves up too, but paid nothing before

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $byName = array_column($plan['included'], null, 'name');
        $this->assertSame(18, $byName['Wechselt Jetzt']['ageFrom']);
        $this->assertSame(14, $byName['Wechselt Jetzt']['previousAgeFrom']);
        $this->assertSame(15.0, $byName['Wechselt Jetzt']['previousAmount']);
        $this->assertArrayNotHasKey('previousAgeFrom', $byName['Bleibt Gleich']);
        $this->assertArrayNotHasKey('previousAgeFrom', $byName['Neu Eingetreten']);
    }

    public function testProrataAppliesToTheTierAmount(): void {
        $this->tieredRate(1, 'Verein', self::TIERS);
        $this->member(10, 'Okt', 'Mitglied', null, '2026-10-15');
        $this->born(0, '2000-01-01');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31', null, true);

        $this->assertSame(6.25, $plan['included'][0]['amount']);
        $this->assertSame(25.0, $plan['included'][0]['fullAmount']);
    }

    public function testRunWritesTheTierAmount(): void {
        $this->tieredRate(1, 'Verein', self::TIERS);
        $this->member(10, 'Vierzehn', 'Kind');
        $this->born(0, '2012-03-03');
        $inserted = [];
        $this->fees->method('insert')->willReturnCallback(function (Fee $f) use (&$inserted) {
            $inserted[] = $f;
            return $f;
        });

        $this->service->run(self::CLUB, 2026, '2026-03-31');

        $this->assertSame(15.0, $inserted[0]->getAmount());
    }

    private function interval(int $rateIndex, string $interval): void {
        $this->rateList[$rateIndex]->setBillingInterval($interval);
    }

    public function testTheAmountAppliesToEachPaymentOfTheMonth(): void {
        $this->rate(1, 'Monatlich', 5.0, true);
        $this->interval(0, 'monthly');
        $this->member(10, 'Anna', 'A');
        $inserted = [];
        $this->fees->method('insert')->willReturnCallback(function (Fee $f) use (&$inserted) {
            $inserted[] = $f;
            return $f;
        });

        $this->service->run(self::CLUB, 2026, '2026-03-31', null, false, 3);

        $this->assertSame(5.0, $inserted[0]->getAmount());
        $this->assertSame('2026-03', $inserted[0]->getPeriod());
        $this->assertSame('Mitgliedsbeitrag 03/2026', $inserted[0]->getDescription());
    }

    public function testTheMonthDefaultsToTheOneOfTheDueDate(): void {
        $this->rate(1, 'Monatlich', 5.0, true);
        $this->interval(0, 'monthly');
        $this->member(10, 'Anna', 'A');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-11-15');

        $this->assertSame('2026-11', $plan['included'][0]['period']);
    }

    /** @dataProvider periodsOfIntervals */
    public function testTheMonthFallsIntoThePeriodOfTheInterval(string $interval, int $month, string $period, string $description): void {
        $this->rate(1, 'Kategorie', 30.0, true);
        $this->interval(0, $interval);
        $this->member(10, 'Anna', 'A');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31', null, false, $month);

        $this->assertSame($period, $plan['included'][0]['period']);
        $this->assertSame(30.0, $plan['included'][0]['amount']);
        $this->assertSame($description, $plan['included'][0]['description']);
    }

    public static function periodsOfIntervals(): array {
        return [
            'yearly' => ['yearly', 7, '2026', 'Mitgliedsbeitrag 2026'],
            'first half' => ['half_yearly', 6, '2026-H1', 'Mitgliedsbeitrag 1. Halbjahr 2026'],
            'second half' => ['half_yearly', 7, '2026-H2', 'Mitgliedsbeitrag 2. Halbjahr 2026'],
            'first quarter' => ['quarterly', 3, '2026-Q1', 'Mitgliedsbeitrag 1. Quartal 2026'],
            'second quarter' => ['quarterly', 4, '2026-Q2', 'Mitgliedsbeitrag 2. Quartal 2026'],
            'last quarter' => ['quarterly', 12, '2026-Q4', 'Mitgliedsbeitrag 4. Quartal 2026'],
            'january' => ['monthly', 1, '2026-01', 'Mitgliedsbeitrag 01/2026'],
            'december' => ['monthly', 12, '2026-12', 'Mitgliedsbeitrag 12/2026'],
        ];
    }

    public function testACustomCommentIsUsedForEveryInterval(): void {
        $this->rate(1, 'Monatlich', 5.0, true);
        $this->interval(0, 'monthly');
        $this->member(10, 'Anna', 'A');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31', 'Sonderlauf', false, 3);

        $this->assertSame('Sonderlauf', $plan['included'][0]['description']);
    }

    public function testEachPeriodIsBilledOnce(): void {
        $this->rate(1, 'Quartal', 15.0, true);
        $this->interval(0, 'quarterly');
        $this->member(10, 'Anna', 'A');
        $this->existingFee(10, '2026-Q1');

        $sameQuarter = $this->service->plan(self::CLUB, 2026, '2026-03-31', null, false, 3);
        $nextQuarter = $this->service->plan(self::CLUB, 2026, '2026-06-30', null, false, 4);

        $this->assertSame([], $sameQuarter['included']);
        $this->assertStringContainsString('2026-Q1', $sameQuarter['skipped'][0]['reason']);
        $this->assertSame('2026-Q2', $nextQuarter['included'][0]['period']);
    }

    public function testACancelledPeriodCanBeBilledAgain(): void {
        $this->rate(1, 'Monatlich', 5.0, true);
        $this->interval(0, 'monthly');
        $this->member(10, 'Anna', 'A');
        $this->existingFee(10, '2026-03', 'cancelled');

        $this->assertCount(1, $this->service->plan(self::CLUB, 2026, '2026-03-31', null, false, 3)['included']);
    }

    /** @dataProvider overlappingFees */
    public function testAChangeOfIntervalCannotBillTheSameMonthsTwice(string $existing, string $interval, int $month, bool $billed): void {
        $this->rate(1, 'Kategorie', 5.0, true);
        $this->interval(0, $interval);
        $this->member(10, 'Anna', 'A');
        $this->existingFee(10, $existing);

        $this->assertSame($billed, $this->service->plan(self::CLUB, 2026, '2026-03-31', null, false, $month)['included'] !== []);
    }

    public static function overlappingFees(): array {
        return [
            'paid the year, now monthly' => ['2026', 'monthly', 5, false],
            'paid a month, now yearly' => ['2026-03', 'yearly', 5, false],
            'paid a month, now that quarter' => ['2026-03', 'quarterly', 2, false],
            'paid a month, now another quarter' => ['2026-03', 'quarterly', 4, true],
            'paid the first half, now July' => ['2026-H1', 'monthly', 7, true],
            'paid the second quarter, now February' => ['2026-Q2', 'monthly', 2, true],
            'paid the second quarter, now May' => ['2026-Q2', 'monthly', 5, false],
            'paid the second half, now March' => ['2026-H2', 'monthly', 3, true],
            'paid November, now November' => ['2026-11', 'monthly', 11, false],
            'paid the first half, now June' => ['2026-H1', 'monthly', 6, false],
            'last year does not count' => ['2025', 'monthly', 5, true],
            'last year\'s month does not count' => ['2025-05', 'monthly', 5, true],
            'a manual fee with another text does not count' => ['Sonderbeitrag', 'yearly', 5, true],
        ];
    }

    public function testARunCanLeaveOutPaymentIntervals(): void {
        $this->rate(1, 'Jahr', 60.0, true);
        $this->rate(2, 'Monat', 5.0);
        $this->interval(1, 'monthly');
        $this->member(10, 'Jahres', 'Zahler', 1);
        $this->member(11, 'Monats', 'Zahler', 2);

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31', null, false, 3, ['monthly']);

        $this->assertSame(['Monats Zahler'], $this->names($plan['included']));
        $this->assertStringContainsString('Zahlungsintervall', $plan['skipped'][0]['reason']);
        $this->assertSame(2, count($this->service->plan(self::CLUB, 2026, '2026-03-31', null, false, 3)['included']), 'no filter = all');
    }

    public function testSomebodyWhoJoinsAfterThePeriodIsNotBilled(): void {
        $this->rate(1, 'Monatlich', 5.0, true);
        $this->interval(0, 'monthly');
        $this->member(10, 'Spaeter', 'Eintritt', null, '2026-05-10');
        $this->member(11, 'Im', 'Monat', null, '2026-03-20');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31', null, false, 3);

        $this->assertSame(['Im Monat'], $this->names($plan['included']));
        $this->assertStringContainsString('2026-03', $plan['skipped'][0]['reason']);
    }

    public function testProrataCountsTheMonthsOfThePeriod(): void {
        $this->rate(1, 'Quartal', 30.0, true);
        $this->interval(0, 'quarterly');
        $this->member(10, 'Februar', 'Eintritt', null, '2026-02-15');   // Feb + Mar of Q1
        $this->member(11, 'Januar', 'Eintritt', null, '2026-01-15');    // whole quarter
        $this->member(12, 'Vorjahr', 'Eintritt', null, '2025-02-15');   // whole quarter

        $secondQuarter = $this->service->plan(self::CLUB, 2026, '2026-06-30', null, true, 4);
        $this->assertSame(30.0, array_column($secondQuarter['included'], 'amount', 'name')['Februar Eintritt'], 'joined before this period');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31', null, true, 3);

        $byName = array_column($plan['included'], null, 'name');
        $this->assertSame(20.0, $byName['Februar Eintritt']['amount']);
        $this->assertSame(2, $byName['Februar Eintritt']['months']);
        $this->assertSame(3, $byName['Februar Eintritt']['periodMonths']);
        $this->assertSame(30.0, $byName['Januar Eintritt']['amount']);
        $this->assertArrayNotHasKey('months', $byName['Januar Eintritt']);
        $this->assertSame(30.0, $byName['Vorjahr Eintritt']['amount']);
    }

    public function testAgeTiersAreAmountsPerPayment(): void {
        $this->tieredRate(1, 'Verein', self::TIERS);
        $this->interval(0, 'monthly');
        $this->member(10, 'Vierzehn', 'Kind');
        $this->born(0, '2012-03-03');

        $this->assertSame(15.0, $this->service->plan(self::CLUB, 2026, '2026-03-31', null, false, 3)['included'][0]['amount']);
    }

    public function testInvalidMonthOrIntervalIsRefused(): void {
        foreach ([[13, null, 'Monat'], [0, null, 'Monat'], [3, ['weekly'], 'Zahlungsintervall']] as [$month, $intervals, $expected]) {
            try {
                $this->service->plan(self::CLUB, 2026, '2026-03-31', null, false, $month, $intervals);
                $this->fail('accepted');
            } catch (ValidationException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    private function joinFee(int $rateIndex, float $fee): void {
        $this->rateList[$rateIndex]->setJoinFee($fee);
    }

    private function entriesOf(array $plan, string $name): array {
        return array_values(array_filter($plan['included'], fn ($e) => $e['name'] === $name));
    }

    public function testWhoJoinsInTheFeeYearPaysTheAdmissionFeeOnceBesideTheFee(): void {
        $this->rate(1, 'Erwachsene', 60.0, true);
        $this->joinFee(0, 20.0);
        $this->member(10, 'Neu', 'Mitglied', null, '2026-02-01');
        $this->member(11, 'Alt', 'Mitglied', null, '2015-01-01');
        $this->member(12, 'Ohne', 'Datum', null, null);
        $inserted = [];
        $this->fees->method('insert')->willReturnCallback(function (Fee $f) use (&$inserted) {
            $inserted[] = $f;
            return $f;
        });

        $result = $this->service->run(self::CLUB, 2026, '2026-03-31');

        $this->assertSame(200.0, $result['total']);
        $this->assertSame(4, $result['created']);
        $this->assertCount(2, $this->entriesOf($result, 'Neu Mitglied'));
        $this->assertCount(1, $this->entriesOf($result, 'Alt Mitglied'));
        $this->assertCount(1, $this->entriesOf($result, 'Ohne Datum'));
        $join = array_values(array_filter($inserted, fn (Fee $f) => $f->getPeriod() === 'join'));
        $this->assertCount(1, $join);
        $this->assertSame(20.0, $join[0]->getAmount());
        $this->assertSame('Aufnahmegebühr', $join[0]->getDescription());
        $this->assertSame('2026-03-31 00:00:00', $join[0]->getDueDate(), 'same due date, so SEPA collects both in one debit');
    }

    public function testTheAdmissionFeeIsNotBilledTwice(): void {
        $this->rate(1, 'Erwachsene', 60.0, true);
        $this->joinFee(0, 20.0);
        $this->member(10, 'Neu', 'Mitglied', null, '2026-02-01');
        $this->member(11, 'Storniert', 'Mitglied', null, '2026-02-01');
        $this->existingFee(10, 'join');
        $this->existingFee(11, 'join', 'cancelled');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertCount(1, $this->entriesOf($plan, 'Neu Mitglied'));
        $this->assertCount(2, $this->entriesOf($plan, 'Storniert Mitglied'));
    }

    public function testAnAdmissionFeeDoesNotCountAsAFeeForAnyPeriod(): void {
        $this->rate(1, 'Monatlich', 5.0, true);
        $this->interval(0, 'monthly');
        $this->member(10, 'Neu', 'Mitglied', null, '2026-02-01');
        $this->existingFee(10, 'join');

        $this->assertSame(5.0, $this->service->plan(self::CLUB, 2026, '2026-03-31', null, false, 3)['included'][0]['amount']);
    }

    public function testAnAdmissionFeeIsDueEvenIfTheFeeItselfIsZeroOrAlreadyBilled(): void {
        $this->tieredRate(1, 'Verein', self::TIERS);
        $this->joinFee(0, 20.0);
        $this->member(10, 'Klein', 'Kind', null, '2026-02-01');
        $this->born(0, '2024-01-01');    // 0 € tier

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertSame([20.0], array_column($plan['included'], 'amount'));
        $this->assertStringContainsString('beitragsfrei', $plan['skipped'][0]['reason']);
    }

    public function testTheAdmissionFeeWaitsForTheRunOfThePeriodTheMemberJoined(): void {
        $this->rate(1, 'Monatlich', 5.0, true);
        $this->interval(0, 'monthly');
        $this->joinFee(0, 20.0);
        $this->member(10, 'Neu', 'Mitglied', null, '2026-05-01');

        $this->assertSame([], $this->service->plan(self::CLUB, 2026, '2026-03-31', null, false, 3)['included']);
        $this->assertCount(2, $this->service->plan(self::CLUB, 2026, '2026-05-31', null, false, 5)['included']);
    }

    public function testExemptJoinYearAndHonoraryMembersPayNoAdmissionFee(): void {
        $this->rate(1, 'Erwachsene', 60.0, true);
        $this->joinFee(0, 20.0);
        $this->member(10, 'Frei', 'Erstes', null, '2026-02-01', null, false, true);
        $this->member(11, 'Ehren', 'Frei', null, '2026-02-01');
        $this->memberList[1]->getMembership()->setHonoraryMember(true);

        $this->assertSame([], $this->service->plan(self::CLUB, 2026, '2026-03-31')['included']);
    }

    public function testTheCategoryTextReplacesTheStandardText(): void {
        $this->rate(1, 'Monatlich', 5.0, true);
        $this->interval(0, 'monthly');
        $this->rateList[0]->setFeeText('Vereinsbeitrag {Zeitraum} / {period}');
        $this->member(10, 'Anna', 'A');

        $this->assertSame('Vereinsbeitrag 03/2026 / 03/2026', $this->service->plan(self::CLUB, 2026, '2026-03-31', null, false, 3)['included'][0]['description']);
    }

    public function testTheCommentOfTheRunBeatsTheCategoryText(): void {
        $this->rate(1, 'Jahr', 60.0, true);
        $this->rateList[0]->setFeeText('Vereinsbeitrag');
        $this->member(10, 'Anna', 'A');

        $this->assertSame('Sonderlauf', $this->service->plan(self::CLUB, 2026, '2026-03-31', 'Sonderlauf')['included'][0]['description']);
    }

    public function testThePreviewAnnouncesTierChangesOfNextYearEvenIfThisYearIsBilled(): void {
        $this->tieredRate(1, 'Verein', self::TIERS);
        $this->member(10, 'Wird', 'Achtzehn');
        $this->born(0, '2009-06-01');    // 17 in 2026, 18 in 2027
        $this->member(11, 'Bleibt', 'Gleich');
        $this->born(1, '2000-06-01');
        $this->member(12, 'Ohne', 'Datum');
        $this->member(13, 'Schon', 'Bezahlt');
        $this->born(3, '2009-06-01');
        $this->existingFee(13, '2026');

        $plan = $this->service->plan(self::CLUB, 2026, '2026-03-31');

        $this->assertSame(['Schon Bezahlt', 'Wird Achtzehn'], $this->sortedNames($plan['upcomingTierChanges']));
        $entry = array_column($plan['upcomingTierChanges'], null, 'name')['Wird Achtzehn'];
        $this->assertSame([15.0, 25.0, 18], [$entry['amount'], $entry['nextAmount'], $entry['nextAgeFrom']]);
    }

    private function sortedNames(array $entries): array {
        $names = array_column($entries, 'name');
        sort($names);
        return $names;
    }

    public function testNoUpcomingChangesWithoutAgeTiersOrForLeavers(): void {
        $this->rate(1, 'Erwachsene', 60.0, true);
        $this->member(10, 'Anna', 'A');
        $this->born(0, '2009-06-01');

        $this->assertSame([], $this->service->plan(self::CLUB, 2026, '2026-03-31')['upcomingTierChanges']);
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
