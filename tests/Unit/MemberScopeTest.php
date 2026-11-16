<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\ClubGroupMember;
use OCA\Verein\Db\ClubGroupMemberMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Exception\NotFoundException;
use OCA\Verein\Exception\PermissionDeniedException;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\MemberScope;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A department head reaches the people who are in one of their groups now.
 */
class MemberScopeTest extends TestCase {
    private ?array $groups = [5];

    private function scope(): MemberScope {
        $roles = $this->createMock(RoleService::class);
        $roles->method('memberGroupScope')->willReturnCallback(fn () => $this->groups);
        $period = function (int $group, int $member, ?string $left) {
            $p = new ClubGroupMember();
            $p->setGroupId($group);
            $p->setMemberId($member);
            $p->setJoinedOn('2020-01-01');
            $p->setLeftOn($left);
            return $p;
        };
        $periods = $this->createMock(ClubGroupMemberMapper::class);
        $periods->method('findByClub')->willReturn([
            $period(5, 1, null),
            $period(5, 2, '2000-01-01'),   // left the group long ago
            $period(5, 3, '2999-12-31'),   // leaves in the future: still in
            $period(6, 4, null),           // another group
        ]);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('head');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return new MemberScope($roles, $periods, $session, null, SourceL10n::fromAppLanguage('de'));
    }

    private function member(int $id): Member {
        $m = new Member();
        $m->setId($id);
        return $m;
    }

    public function testOnlyCurrentMembersOfTheGroups(): void {
        $scope = $this->scope();
        $this->assertSame([1, 3], $scope->memberIds(1));
        $this->assertSame([1, 3], array_map(fn ($m) => $m->getId(), $scope->filter(1, array_map(fn ($i) => $this->member($i), [1, 2, 3, 4]))));
        $this->assertTrue($scope->covers(1, 3));
        $this->assertFalse($scope->covers(1, 4));

        $this->expectException(NotFoundException::class);
        $scope->check(1, 2);
    }

    public function testWholeClubAndNoGroups(): void {
        $this->groups = null;
        $scope = $this->scope();
        $this->assertNull($scope->memberIds(1));
        $scope->requireWholeClub(1);
        $scope->check(1, 99);

        $this->groups = [];
        $this->assertSame([], $this->scope()->memberIds(1), 'no groups: nobody');
    }

    public function testThingsForTheWholeClubAreRefused(): void {
        $this->expectException(PermissionDeniedException::class);
        $this->expectExceptionMessage('ganzen Verein');
        $this->scope()->requireWholeClub(1);
    }
}
