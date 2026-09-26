<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Db\ClubAccountMapper;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Service\Clock;
use OCA\Verein\Service\DunningService;
use OCA\Verein\Service\FeeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud runs PHP in UTC: shortly after midnight in Germany date('Y-m-d') is still yesterday. "Today" for due
 * dates, the overdue flag and letters must be the local day.
 */
class ClockTest extends TestCase {
    /** 2030-03-31 23:30 UTC = 2030-04-01 01:30 in Berlin (summer time) - deliberately not a real "today", or date() would pass too */
    private const LATE_EVENING_UTC = 1901230200;

    private function clock(string $zone, int $timestamp = self::LATE_EVENING_UTC): Clock {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($timestamp);
        $tz = $this->createMock(IDateTimeZone::class);
        $tz->method('getTimeZone')->willReturn(new \DateTimeZone($zone));
        return new Clock($time, $tz);
    }

    public function testTodayIsTheLocalDayNotTheUtcDay(): void {
        $this->assertSame('2030-03-31 23:30', gmdate('Y-m-d H:i', self::LATE_EVENING_UTC));
        $this->assertSame('2030-04-01', $this->clock('Europe/Berlin')->today());
        $this->assertSame('01:30', $this->clock('Europe/Berlin')->now()->format('H:i'));
        $this->assertSame('2030-03-31', $this->clock('UTC')->today());
        $this->assertSame('2030-03-31', $this->clock('America/New_York')->today());
    }

    public function testTheOverdueFlagUsesTheLocalDay(): void {
        $fees = $this->createMock(FeeMapper::class);
        $fees->expects($this->once())->method('flagOverdueInClub')
            ->with(4, '2030-04-01', $this->anything())
            ->willReturn(0);
        $service = new FeeService($fees, $this->createMock(MembershipMapper::class), null, $this->clock('Europe/Berlin'), l10n: SourceL10n::fromAppLanguage('de'));

        $service->flagOverdue(4);
    }

    public function testDunningLettersAreDatedWithTheLocalDay(): void {
        $club = new \OCA\Verein\Db\Club();
        $club->setName('Musterverein');
        $clubs = $this->createMock(ClubMapper::class);
        $clubs->method('find')->willReturn($club);
        $fees = $this->createMock(FeeMapper::class);
        $fees->method('findByClub')->willReturn([]);
        $members = $this->createMock(MemberMapper::class);
        $members->method('findByClub')->willReturn([]);
        $accounts = $this->createMock(ClubAccountMapper::class);
        $accounts->method('findByClub')->willReturn([]);
        $service = new DunningService($fees, $members, $clubs, $accounts, $this->createMock(IDBConnection::class), null, $this->clock('Europe/Berlin'), l10n: SourceL10n::fromAppLanguage('de'));

        $data = $service->letters(4, [1], 14);

        $this->assertSame('01.04.2030', $data['date']);
        $this->assertSame('15.04.2030', $data['deadline']);
    }
}
