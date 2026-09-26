<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Calendar\ClubCalendar;
use OCA\Verein\Calendar\ClubCalendarProvider;
use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\Membership;
use OCP\Constants;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;

class ClubCalendarTest extends TestCase {
    private MemberMapper&MockObject $members;
    /** @var Member[] */
    private array $memberList = [];

    protected function setUp(): void {
        $this->members = $this->createMock(MemberMapper::class);
        $this->members->method('findByClub')->willReturnCallback(fn () => $this->memberList);
    }

    private function club(int $id, string $name, array $groups): Club {
        $c = new Club();
        $c->setId($id);
        $c->setName($name);
        $c->setCalendarGroups(json_encode($groups));
        return $c;
    }

    private function member(int $id, string $first, string $name, ?string $birth, ?string $join, ?string $leave = null, bool $deactivated = false, bool $deceased = false): Member {
        $m = new Member();
        $m->setId($id);
        $m->setFirstName($first);
        $m->setName($name);
        $m->setBirthDate($birth);
        $m->setDeceased($deceased);
        $ms = new Membership();
        $ms->setMemberId($id);
        $ms->setClubId(7);
        $ms->setJoinDate($join);
        $ms->setLeaveDate($leave);
        $ms->setDeactivated($deactivated);
        $m->setMembership($ms);
        $this->memberList[] = $m;
        return $m;
    }

    private function calendar(): ClubCalendar {
        return new ClubCalendar($this->club(7, 'Musterverein', ['vorstand']), $this->members, l10n: SourceL10n::fromAppLanguage('de'));
    }

    public function testOnlyActiveMembersWithDatesGetEvents(): void {
        $this->member(1, 'Anna', 'Aktiv', '1980-02-01', '2010-05-01');
        $this->member(2, 'Ohne', 'Datum', null, null);
        $this->member(3, 'Ex', 'Mitglied', '1970-01-01', '2000-01-01', '2020-12-31');
        $this->member(4, 'Pause', 'Mitglied', '1970-01-01', '2000-01-01', null, true);
        $this->member(5, 'Tot', 'Mitglied', '1940-01-01', '1960-01-01', null, false, true);
        $anon = $this->member(6, '', 'Anonymisiert', '1950-01-01', '1990-01-01');
        $anon->setAnonymizedAt('2026-01-01 00:00:00');

        $events = $this->calendar()->search('');

        $this->assertSame(
            ['verein-club-7-member-1-birthday', 'verein-club-7-member-1-anniversary'],
            array_map(fn ($e) => (string)$e->UID, $events)
        );
        $this->assertSame('🎂 Geburtstag: Anna Aktiv', (string)$events[0]->SUMMARY);
        $this->assertSame('🎉 Vereinsjubiläum: Anna Aktiv', (string)$events[1]->SUMMARY);
    }

    public function testEventsAreYearlyAllDayAndSerializeLikeTheCalDavLayerDoes(): void {
        $this->member(1, 'Anna', 'Aktiv', '1980-02-29', null);

        $events = $this->calendar()->search('');
        // what OCA\DAV\CalDAV\AppCalendar\CalendarObject does with the search result
        $ics = (new VCalendar($events))->serialize();

        $this->assertStringContainsString("DTSTART;VALUE=DATE:19800229\r\n", $ics);
        $this->assertStringContainsString("DTEND;VALUE=DATE:19800301\r\n", $ics);
        $this->assertStringContainsString("RRULE:FREQ=YEARLY\r\n", $ics);
        $this->assertStringContainsString("UID:verein-club-7-member-1-birthday\r\n", $ics);
        $this->assertSame([], (new VCalendar($events))->validate(), 'valid iCalendar');
    }

    /**
     * OCA\DAV\CalDAV\AppCalendar\AppCalendar::getChildren() groups the search results with (string)$object['UID'].
     * A plain Sabre component answers array access with its own element list, so that produced an "Undefined array
     * key" warning per event on every CalDAV request (seen in the Nextcloud log of the test instance).
     */
    public function testResultsAnswerArrayAccessByPropertyNameLikeNextcloudsWrapperReadsThem(): void {
        $this->member(1, 'Anna', 'Aktiv', '1980-02-01', '2010-05-01');
        $warnings = [];
        set_error_handler(function (int $no, string $msg) use (&$warnings) {
            $warnings[] = $msg;
            return true;
        });
        try {
            $grouped = [];
            foreach ($this->calendar()->search('') as $object) {
                $uid = (string)$object['UID'] ?: 'random';
                $grouped[$uid][] = $object;
            }
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame(['verein-club-7-member-1-birthday', 'verein-club-7-member-1-anniversary'], array_keys($grouped));
        $this->assertTrue(isset($object['SUMMARY']));
        $this->assertFalse(isset($object['LOCATION']));
    }

    public function testLookupByFileNameAndUidIsExactAsTheCalDavLayerNeedsIt(): void {
        $this->member(1, 'Anna', 'Aktiv', '1980-02-01', '2010-05-01');
        $this->member(11, 'Bert', 'Beta', '1981-03-01', '2011-06-01');
        $cal = $this->calendar();

        $byFile = $cal->search('verein-club-7-member-1-birthday.ics', ['X-FILENAME']);
        $byUid = $cal->search('verein-club-7-member-1-birthday', ['UID']);

        $this->assertSame(['verein-club-7-member-1-birthday'], array_map(fn ($e) => (string)$e->UID, $byFile));
        $this->assertSame(['verein-club-7-member-1-birthday'], array_map(fn ($e) => (string)$e->UID, $byUid));
        $this->assertSame([], $cal->search('verein-club-7-member-1', ['UID']), 'no prefix match');
    }

    public function testPatternOptionsTypesAndPaging(): void {
        $this->member(1, 'Anna', 'Aktiv', '1980-02-01', '2010-05-01');
        $this->member(2, 'Bert', 'Beta', '1981-03-01', '2011-06-01');
        $cal = $this->calendar();

        $this->assertCount(2, $cal->search('geburtstag'));                       // SUMMARY, case-insensitive
        $this->assertCount(1, $cal->search('bert', ['SUMMARY'], [], 1));
        $this->assertCount(1, $cal->search('', [], ['uid' => 'verein-club-7-member-2-anniversary']));
        $this->assertSame([], $cal->search('', [], ['types' => ['VTODO']]));
        $this->assertCount(2, $cal->search('', [], [], 2, 1));
        $this->assertSame('verein-club-7', $cal->getUri());
        $this->assertSame(Constants::PERMISSION_READ, $cal->getPermissions());
        $this->assertFalse($cal->isDeleted());
    }

    public function testMembersAreLoadedOncePerCalendarInstance(): void {
        $this->member(1, 'Anna', 'Aktiv', '1980-02-01', '2010-05-01');
        $this->members->expects($this->once())->method('findByClub')->with(7);
        $cal = $this->calendar();
        $cal->search('');
        $cal->search('verein-club-7-member-1-birthday.ics', ['X-FILENAME']);
    }

    public function testProviderGivesACalendarOnlyToMembersOfTheConfiguredGroups(): void {
        $clubs = $this->createMock(ClubMapper::class);
        $clubs->method('findAll')->willReturn([
            $this->club(7, 'Musterverein', ['vorstand', 'mitglieder']),
            $this->club(8, 'Anderer Verein', ['andere']),
            $this->club(9, 'Ohne Kalender', []),
        ]);
        $user = $this->createMock(IUser::class);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(fn ($uid) => in_array($uid, ['anna', 'kim muster'], true) ? $user : null);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('getUserGroupIds')->willReturn(['mitglieder', 'sonstige']);
        $provider = new ClubCalendarProvider($clubs, $this->members, $users, $groups, $this->createMock(LoggerInterface::class));

        $uris = fn (array $cals) => array_map(fn ($c) => $c->getUri(), $cals);
        $this->assertSame(['verein-club-7'], $uris($provider->getCalendars('principals/users/anna')));
        $this->assertSame(['verein-club-7'], $uris($provider->getCalendars('principals/users/kim%20muster')));
        $this->assertSame([], $provider->getCalendars('principals/users/anna', ['verein-club-8']));
        $this->assertSame([], $provider->getCalendars('principals/users/unbekannt'));
        $this->assertSame([], $provider->getCalendars('principals/groups/mitglieder'));
    }

    public function testProviderFailureNeverBreaksTheUsersCalendars(): void {
        $clubs = $this->createMock(ClubMapper::class);
        $clubs->method('findAll')->willThrowException(new \RuntimeException('db down'));
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('getUserGroupIds')->willReturn(['mitglieder']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $provider = new ClubCalendarProvider($clubs, $this->members, $users, $groups, $logger);

        $this->assertSame([], $provider->getCalendars('principals/users/anna'));
    }
}
