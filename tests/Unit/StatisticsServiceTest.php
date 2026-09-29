<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Service\Clock;
use OCA\Verein\Service\StatisticsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's member curve counts members by the day they joined the club (and stops at the day they left),
 * not by the day the record was created in the app: that made the curve jump from 0 to everyone in the month a
 * club entered or imported its members.
 */
class StatisticsServiceTest extends TestCase {
    /** 2026-09-15 12:00 in Europe/Berlin */
    private const NOW = 1789466400;

    private function member(int $id, ?string $joined, ?string $left = null, string $created = '2026-09-01 10:00:00', bool $deceased = false): Member {
        $m = new Member();
        $m->setId($id);
        $m->setName('Muster' . $id);
        $m->setCreatedAt($created);
        $m->setDeceased($deceased);
        $ms = new Membership();
        $ms->setMemberId($id);
        $ms->setClubId(1);
        $ms->setJoinDate($joined);
        $ms->setLeaveDate($left);
        $m->setMembership($ms);
        return $m;
    }

    public function testCurveAndCountsFollowJoinAndLeaveDates(): void {
        $members = [
            $this->member(1, '2004-10-01', null, '2026-09-10 08:00:00'), // entered this month, member for decades
            $this->member(2, '2026-06-10'),
            $this->member(3, '2010-01-01', '2026-05-31'),                 // left on the last day of May
            $this->member(4, null, null, '2026-08-20 10:00:00'),          // no join date: the record's date
            $this->member(5, '2000-01-01', null, '2026-09-01 10:00:00', true), // deceased, date unknown
            $this->member(6, '2026-09-30'),                               // joins on the last day of the month
        ];
        $mapper = $this->createMock(MemberMapper::class);
        $mapper->method('findByClub')->willReturn($members);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        $tz = $this->createMock(IDateTimeZone::class);
        $tz->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));

        $stats = (new StatisticsService($mapper, $this->createMock(FeeMapper::class), new Clock($time, $tz)))->getMemberStatistics(1);

        $this->assertSame(['Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep'], $stats['growthByMonth']['labels']);
        $this->assertSame([2, 2, 2, 2, 3, 4], $stats['growthByMonth']['data']);
        $this->assertSame(6, $stats['total']);
        $this->assertSame(4, $stats['active'], 'the dashboard shows the members that are not former, like the member list');
        $this->assertSame(1, $stats['newThisMonth'], 'joined this month - not: entered into the app this month');
    }
}
