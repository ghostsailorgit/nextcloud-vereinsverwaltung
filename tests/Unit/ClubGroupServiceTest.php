<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\ClubGroup;
use OCA\Verein\Db\ClubGroupMapper;
use OCA\Verein\Db\ClubGroupMember;
use OCA\Verein\Db\ClubGroupMemberMapper;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Exception\NotFoundException;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\ClubGroupService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class ClubGroupServiceTest extends TestCase {
    private const CLUB = 1;
    private const OTHER_CLUB = 2;

    /** @var array<int, ClubGroup> */
    private array $groups = [];
    /** @var array<int, ClubGroupMember> */
    private array $periods = [];
    /** @var array<int, Member[]> */
    private array $members = [self::CLUB => [], self::OTHER_CLUB => []];
    private int $nextId = 1;
    private ClubGroupService $service;

    protected function setUp(): void {
        $groups = $this->createMock(ClubGroupMapper::class);
        $groups->method('find')->willReturnCallback(fn (int $id) => $this->groups[$id] ?? throw new DoesNotExistException('none'));
        $groups->method('findByClub')->willReturnCallback(fn (int $c) => array_values(array_filter($this->groups, fn ($g) => $g->getClubId() === $c)));
        $groups->method('insert')->willReturnCallback(function (ClubGroup $g) {
            $g->setId($this->nextId++);
            return $this->groups[$g->getId()] = $g;
        });
        $groups->method('update')->willReturnArgument(0);
        $groups->method('delete')->willReturnCallback(function (ClubGroup $g) {
            unset($this->groups[$g->getId()]);
            return $g;
        });

        $periods = $this->createMock(ClubGroupMemberMapper::class);
        $periods->method('find')->willReturnCallback(fn (int $id) => $this->periods[$id] ?? throw new DoesNotExistException('none'));
        $periods->method('findByClub')->willReturnCallback(fn (int $c) => array_values(array_filter($this->periods, fn ($p) => $p->getClubId() === $c)));
        $periods->method('findByGroup')->willReturnCallback(fn (int $g) => array_values(array_filter($this->periods, fn ($p) => $p->getGroupId() === $g)));
        $periods->method('findByMember')->willReturnCallback(fn (int $m) => array_values(array_filter($this->periods, fn ($p) => $p->getMemberId() === $m)));
        $periods->method('insert')->willReturnCallback(function (ClubGroupMember $p) {
            $p->setId($this->nextId++);
            return $this->periods[$p->getId()] = $p;
        });
        $periods->method('update')->willReturnArgument(0);
        $periods->method('delete')->willReturnCallback(function (ClubGroupMember $p) {
            unset($this->periods[$p->getId()]);
            return $p;
        });
        $periods->method('deleteByGroup')->willReturnCallback(function (int $g) {
            $this->periods = array_filter($this->periods, fn ($p) => $p->getGroupId() !== $g);
        });

        $members = $this->createMock(MemberMapper::class);
        $members->method('findByClub')->willReturnCallback(fn (int $c) => $this->members[$c]);

        $this->member(self::CLUB, 10, 'Anna', 'Aktiv');
        $this->member(self::CLUB, 11, 'Bert', 'Beispiel');
        $this->member(self::CLUB, 12, 'Ella', 'Ehemalig', '2020-12-31');
        $this->member(self::OTHER_CLUB, 20, 'Fremd', 'Mitglied');

        $this->service = new ClubGroupService($groups, $periods, $members, $this->createMock(ClubMapper::class), l10n: SourceL10n::fromAppLanguage('de'));
    }

    private function member(int $club, int $id, string $first, string $name, ?string $left = null): void {
        $m = new Member();
        $m->setId($id);
        $m->setFirstName($first);
        $m->setName($name);
        $ms = new Membership();
        $ms->setMemberId($id);
        $ms->setClubId($club);
        $ms->setJoinDate('2010-01-01');
        $ms->setLeaveDate($left);
        $m->setMembership($ms);
        $this->members[$club][] = $m;
    }

    private function group(string $name = 'Judo Kinder', int $club = self::CLUB): ClubGroup {
        return $this->service->create($club, ['name' => $name, 'description' => '']);
    }

    public function testAGroupNeedsAUniqueNameWithinTheClub(): void {
        $this->group('Judo');
        try {
            $this->service->create(self::CLUB, ['name' => ' ']);
            $this->fail('empty');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Name', $e->getMessage());
        }
        try {
            $this->service->create(self::CLUB, ['name' => 'judo']);
            $this->fail('duplicate');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('gibt es schon', $e->getMessage());
        }
        $this->assertSame('Judo', $this->group('Judo', self::OTHER_CLUB)->getName(), 'another club may use the name');
    }

    public function testAMemberCanBeInSeveralGroupsAndCurrentMembersAreCounted(): void {
        $a = $this->group('A-Gruppe');
        $b = $this->group('B-Gruppe');
        $this->service->addMember(self::CLUB, $a->getId(), 10, '2025-01-01');
        $this->service->addMember(self::CLUB, $b->getId(), 10, '2025-02-01');
        $this->service->addMember(self::CLUB, $a->getId(), 11, '2024-01-01', '2024-12-31');

        $counts = array_column($this->service->list(self::CLUB), 'currentMembers', 'name');

        $this->assertSame(['A-Gruppe' => 1, 'B-Gruppe' => 1], $counts);
        $this->assertSame([10 => ['A-Gruppe', 'B-Gruppe']], $this->service->currentGroupNames(self::CLUB));
    }

    public function testJoiningAgainKeepsTheEarlierPeriod(): void {
        $g = $this->group();
        $first = $this->service->addMember(self::CLUB, $g->getId(), 10, '2024-01-01');
        $this->service->updatePeriod(self::CLUB, $first->getId(), '2024-01-01', '2024-06-30');
        $this->service->addMember(self::CLUB, $g->getId(), 10, '2025-03-01');

        $rows = $this->service->periodsOfGroup(self::CLUB, $g->getId());

        $this->assertSame([['2025-03-01', null], ['2024-01-01', '2024-06-30']], array_map(fn ($r) => [$r['joinedOn'], $r['leftOn']], $rows));
    }

    public function testPeriodsMustNotOverlapButMayMeetOnOneDay(): void {
        $g = $this->group();
        $this->service->addMember(self::CLUB, $g->getId(), 10, '2024-01-01', '2024-06-30');

        foreach ([['2024-03-01', '2024-04-01'], ['2023-01-01', null], ['2023-01-01', '2024-02-01'], ['2024-06-01', null]] as [$from, $to]) {
            try {
                $this->service->addMember(self::CLUB, $g->getId(), 10, $from, $to);
                $this->fail("$from accepted");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('überschneidet', $e->getMessage());
            }
        }
        $this->assertSame('2024-06-30', $this->service->addMember(self::CLUB, $g->getId(), 10, '2024-06-30', '2024-07-31')->getJoinedOn());
    }

    public function testOnlyOneOpenPeriodPerMemberAndGroup(): void {
        $g = $this->group();
        $this->service->addMember(self::CLUB, $g->getId(), 10, '2024-01-01');

        $this->expectException(ValidationException::class);
        $this->service->addMember(self::CLUB, $g->getId(), 10, '2025-01-01');
    }

    public function testAPeriodDoesNotCollideWithItselfWhenCorrected(): void {
        $g = $this->group();
        $p = $this->service->addMember(self::CLUB, $g->getId(), 10, '2024-01-01');

        $this->assertSame('2024-02-01', $this->service->updatePeriod(self::CLUB, $p->getId(), '2024-02-01', '')->getJoinedOn());
    }

    /** @dataProvider invalidPeriods */
    public function testInvalidDatesAreRefused(mixed $from, mixed $to, string $expected): void {
        $g = $this->group();
        try {
            $this->service->addMember(self::CLUB, $g->getId(), 10, $from, $to);
            $this->fail('accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    public static function invalidPeriods(): array {
        return [
            'no start' => ['', null, 'Eintrittsdatum'],
            'German format' => ['01.02.2024', null, 'Eintrittsdatum'],
            'impossible day' => ['2024-02-30', null, 'Eintrittsdatum'],
            'array' => [['2024-01-01'], null, 'Eintrittsdatum'],
            'bad end' => ['2024-01-01', '2024-13-01', 'Austrittsdatum'],
            'end before start' => ['2024-02-01', '2024-01-31', 'nicht vor'],
        ];
    }

    public function testSomebodyWhoLeftTheClubIsNotCurrentButStaysOnRecord(): void {
        $g = $this->group();
        $this->service->addMember(self::CLUB, $g->getId(), 12, '2019-01-01');

        $this->assertSame(0, $this->service->list(self::CLUB)[0]['currentMembers']);
        $this->assertSame([], $this->service->currentGroupNames(self::CLUB));
        $row = $this->service->periodsOfGroup(self::CLUB, $g->getId())[0];
        $this->assertTrue($row['memberFormer']);
        $this->assertNull($row['leftOn']);
        $this->assertSame('Ella Ehemalig', $row['memberName']);
    }

    public function testDeletingAGroupRemovesItsPeriods(): void {
        $g = $this->group();
        $other = $this->group('Andere');
        $this->service->addMember(self::CLUB, $g->getId(), 10, '2024-01-01');
        $kept = $this->service->addMember(self::CLUB, $other->getId(), 10, '2024-01-01');

        $this->service->delete(self::CLUB, $g->getId());

        $this->assertSame([$kept->getId()], array_keys($this->periods));
        $this->assertArrayNotHasKey($g->getId(), $this->groups);
    }

    public function testNothingCrossesClubBoundaries(): void {
        $own = $this->group();
        $foreign = $this->group('Fremd', self::OTHER_CLUB);
        $period = $this->service->addMember(self::OTHER_CLUB, $foreign->getId(), 20, '2024-01-01');

        $attempts = [
            'group of another club' => fn () => $this->service->addMember(self::CLUB, $foreign->getId(), 10, '2024-01-01'),
            'member of another club' => fn () => $this->service->addMember(self::CLUB, $own->getId(), 20, '2024-01-01'),
            'unknown member' => fn () => $this->service->addMember(self::CLUB, $own->getId(), 999, '2024-01-01'),
            'read' => fn () => $this->service->periodsOfGroup(self::CLUB, $foreign->getId()),
            'rename' => fn () => $this->service->update(self::CLUB, $foreign->getId(), ['name' => 'x']),
            'delete' => fn () => $this->service->delete(self::CLUB, $foreign->getId()),
            'change period' => fn () => $this->service->updatePeriod(self::CLUB, $period->getId(), '2024-01-01', null),
            'delete period' => fn () => $this->service->deletePeriod(self::CLUB, $period->getId()),
            'unknown group' => fn () => $this->service->periodsOfGroup(self::CLUB, 999),
        ];
        foreach ($attempts as $what => $attempt) {
            try {
                $attempt();
                $this->fail("$what was allowed");
            } catch (NotFoundException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
