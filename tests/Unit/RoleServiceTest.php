<?php
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Db\Role;
use OCA\Verein\Db\RoleMapper;
use OCA\Verein\Db\UserRole;
use OCA\Verein\Db\UserRoleMapper;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Role assignments are per club: holding a permission in club 1 must never
 * grant it in club 2.
 */
class RoleServiceTest extends TestCase {
    private RoleMapper&MockObject $roleMapper;
    private UserRoleMapper&MockObject $userRoleMapper;
    private IGroupManager&MockObject $groupManager;
    private MemberMapper&MockObject $memberMapper;
    private MembershipMapper&MockObject $membershipMapper;
    private ClubMapper&MockObject $clubMapper;
    private RoleService $service;

    /** @var array<string, Member> linked person by Nextcloud uid */
    private array $people = [];
    /** @var array<int, Membership[]> */
    private array $membershipsByMember = [];
    /** @var array<int, Club> */
    private array $clubs = [];

    /** @var array<int, Role> */
    private array $roles = [];
    /** @var UserRole[] */
    private array $assignments = [];

    protected function setUp(): void {
        $this->roleMapper = $this->createMock(RoleMapper::class);
        $this->userRoleMapper = $this->createMock(UserRoleMapper::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->memberMapper = $this->createMock(MemberMapper::class);
        $this->membershipMapper = $this->createMock(MembershipMapper::class);
        $this->clubMapper = $this->createMock(ClubMapper::class);
        $this->memberMapper->method('findByUserId')->willReturnCallback(fn (string $uid) => $this->people[$uid] ?? null);
        $this->membershipMapper->method('findByMember')->willReturnCallback(fn (int $id) => $this->membershipsByMember[$id] ?? []);
        $this->clubMapper->method('find')->willReturnCallback(fn (int $id) => $this->clubs[$id] ?? throw new DoesNotExistException('no club'));

        $this->roleMapper->method('find')->willReturnCallback(function (int $id) {
            return $this->roles[$id] ?? throw new DoesNotExistException('no role');
        });
        $this->userRoleMapper->method('findByUserId')->willReturnCallback(
            fn (string $uid) => array_values(array_filter($this->assignments, fn (UserRole $a) => $a->getUserId() === $uid))
        );
        $this->userRoleMapper->method('findByUserAndClub')->willReturnCallback(
            fn (string $uid, int $club) => array_values(array_filter(
                $this->assignments,
                fn (UserRole $a) => $a->getUserId() === $uid && $a->getClubId() === $club
            ))
        );

        $this->service = new RoleService(
            $this->roleMapper,
            $this->userRoleMapper,
            $this->groupManager,
            $this->createMock(IUserSession::class),
            $this->createMock(LoggerInterface::class),
            $this->memberMapper,
            $this->membershipMapper,
            $this->clubMapper
        );
    }

    private function role(int $id, array $permissions): void {
        $role = new Role();
        $role->setId($id);
        $role->setName('role' . $id);
        $role->setPermissionsArray($permissions);
        $this->roles[$id] = $role;
    }

    private function assign(string $uid, int $roleId, int $clubId): void {
        $a = new UserRole();
        $a->setUserId($uid);
        $a->setRoleId($roleId);
        $a->setClubId($clubId);
        $this->assignments[] = $a;
    }

    public function testPermissionIsScopedToTheClubOfTheAssignment(): void {
        $this->role(1, ['verein.member.view', 'verein.member.manage']);
        $this->assign('anna', 1, 10);

        $this->assertTrue($this->service->userHasPermission('anna', 'verein.member.manage', 10));
        $this->assertFalse($this->service->userHasPermission('anna', 'verein.member.manage', 11));
    }

    public function testPermissionNotInTheRoleIsDenied(): void {
        $this->role(1, ['verein.member.view']);
        $this->assign('anna', 1, 10);

        $this->assertTrue($this->service->userHasPermission('anna', 'verein.member.view', 10));
        $this->assertFalse($this->service->userHasPermission('anna', 'verein.member.manage', 10));
    }

    public function testAnyClubCheckWithoutClubId(): void {
        $this->role(1, ['verein.role.manage']);
        $this->assign('anna', 1, 11);

        $this->assertTrue($this->service->userHasPermission('anna', 'verein.role.manage', null));
        $this->assertFalse($this->service->userHasPermission('bob', 'verein.role.manage', null));
    }

    public function testUserWithoutAnyRoleHasNothing(): void {
        $this->assertFalse($this->service->userHasPermission('nobody', 'verein.member.view', 1));
        $this->assertFalse($this->service->userHasPermission('nobody', 'verein.member.view', null));
        $this->assertSame([], $this->service->getAccessibleClubIds('nobody'));
    }

    public function testNextcloudAdminHasEveryPermissionInEveryClub(): void {
        $this->groupManager->method('isAdmin')->with('root')->willReturn(true);

        $this->assertTrue($this->service->userHasPermission('root', 'verein.club.manage', 99));
        $this->assertNull($this->service->getAccessibleClubIds('root'));
        $this->assertNull($this->service->getClubIdsWithPermission('root', 'verein.member.manage'));
        $this->assertContains('verein.sepa.export', $this->service->getPermissionsForClub('root', 99));
    }

    public function testRoleWithUnknownIdIsIgnored(): void {
        $this->assign('anna', 42, 10); // role 42 does not exist

        $this->assertFalse($this->service->userHasPermission('anna', 'verein.member.view', 10));
    }

    public function testClubIdsWithPermissionListsOnlyMatchingClubs(): void {
        $this->role(1, ['verein.member.manage']);
        $this->role(2, ['verein.member.view']);
        $this->assign('anna', 1, 10);
        $this->assign('anna', 2, 11);

        $this->assertSame([10], $this->service->getClubIdsWithPermission('anna', 'verein.member.manage'));
        $this->assertEqualsCanonicalizing([10, 11], $this->service->getAccessibleClubIds('anna'));
    }

    public function testPermissionsForClubAreTheUnionOfTheRolesInThatClub(): void {
        $this->role(1, ['verein.member.view']);
        $this->role(2, ['verein.finance.read', 'verein.member.view']);
        $this->assign('anna', 1, 10);
        $this->assign('anna', 2, 10);
        $this->assign('anna', 2, 11);

        $perms = $this->service->getPermissionsForClub('anna', 10);
        sort($perms);
        $this->assertSame(['verein.finance.read', 'verein.member.view'], $perms);
        $this->assertSame([], $this->service->getPermissionsForClub('anna', 12));
    }

    public function testAssigningWithoutClubIsRejected(): void {
        $this->role(1, ['verein.member.view']);

        $this->expectException(ValidationException::class);
        $this->service->assignRole('anna', 1, 0);
    }

    public function testClubAssignmentsGroupRolesByUser(): void {
        $this->role(1, []);
        $this->role(2, []);
        $this->assign('anna', 1, 10);
        $this->assign('anna', 2, 10);
        $this->assign('bob', 1, 10);
        $this->userRoleMapper->method('findByClubId')->with(10)->willReturn($this->assignments);

        $result = $this->service->getClubAssignments(10);

        $this->assertCount(2, $result);
        $byUser = array_column($result, 'roles', 'userId');
        $this->assertSame(['role1', 'role2'], $byUser['anna']);
        $this->assertSame(['role1'], $byUser['bob']);
    }

    // --- automatic rights derived from the membership

    private function person(int $id, ?string $userId, bool $deceased = false): Member {
        $m = new Member();
        $m->setId($id);
        $m->setName('P' . $id);
        $m->setUserId($userId);
        $m->setDeceased($deceased);
        return $m;
    }

    private function membershipIn(int $memberId, int $clubId, string $role, ?string $leaveDate = null): Membership {
        $ms = new Membership();
        $ms->setMemberId($memberId);
        $ms->setClubId($clubId);
        $ms->setRole($role);
        $ms->setLeaveDate($leaveDate);
        return $ms;
    }

    /** @param array<string,int> $mapping */
    private function club(int $id, array $mapping): void {
        $club = new Club();
        $club->setId($id);
        $club->setName('Club ' . $id);
        $club->setRoleMapping($mapping === [] ? null : json_encode($mapping));
        $this->clubs[$id] = $club;
    }

    private function linkedPerson(string $uid, Member $person, Membership ...$memberships): void {
        $this->people[$uid] = $person;
        $this->membershipsByMember[$person->getId()] = $memberships;
    }

    public function testBoardMemberGetsTheMappedRoleAutomaticallyInTheirClubOnly(): void {
        $this->role(1, ['verein.member.manage']);
        $this->club(10, ['admin' => 1]);
        $this->club(11, ['admin' => 1]);
        $this->linkedPerson('max', $this->person(5, 'max'), $this->membershipIn(5, 10, 'admin'));

        $this->assertTrue($this->service->userHasPermission('max', 'verein.member.manage', 10));
        $this->assertFalse($this->service->userHasPermission('max', 'verein.member.manage', 11), 'not a member of club 11');
        $this->assertSame([10], $this->service->getClubIdsWithPermission('max', 'verein.member.manage'));
        $this->assertSame([10], $this->service->getAccessibleClubIds('max'));
        $this->assertSame(['verein.member.manage'], $this->service->getPermissionsForClub('max', 10));
    }

    public function testNothingIsDerivedWithoutAMappingForThatMembershipRole(): void {
        $this->role(1, ['verein.member.manage']);
        $this->club(10, ['admin' => 1]); // only the board is mapped
        $this->linkedPerson('anna', $this->person(6, 'anna'), $this->membershipIn(6, 10, 'member'));

        $this->assertFalse($this->service->userHasPermission('anna', 'verein.member.manage', 10));
        $this->assertSame([], $this->service->getAccessibleClubIds('anna'));
    }

    public function testNothingIsDerivedWhenTheClubHasNoMappingAtAll(): void {
        $this->role(1, ['verein.member.manage']);
        $this->club(10, []);
        $this->linkedPerson('max', $this->person(5, 'max'), $this->membershipIn(5, 10, 'admin'));

        $this->assertFalse($this->service->userHasPermission('max', 'verein.member.manage', 10));
    }

    public function testRightsEndWithTheMembership(): void {
        $this->role(1, ['verein.member.manage']);
        $this->club(10, ['admin' => 1]);
        $this->linkedPerson('left', $this->person(5, 'left'), $this->membershipIn(5, 10, 'admin', '2025-06-30'));
        $this->linkedPerson('dead', $this->person(6, 'dead', true), $this->membershipIn(6, 10, 'admin'));

        $this->assertFalse($this->service->userHasPermission('left', 'verein.member.manage', 10), 'left the club');
        $this->assertFalse($this->service->userHasPermission('dead', 'verein.member.manage', 10), 'deceased');
    }

    public function testLockingAMemberSuspendsDerivedRightsWithoutTouchingExplicitAssignments(): void {
        $this->role(1, ['verein.member.manage']);
        $this->role(2, ['verein.finance.read']);
        $this->club(10, ['admin' => 1]);
        $locked = $this->person(6, 'locked');
        $locked->setLocked(true);
        $this->linkedPerson('locked', $locked, $this->membershipIn(6, 10, 'admin'));
        $this->assignments[] = (function () {
            $a = new UserRole();
            $a->setUserId('locked');
            $a->setRoleId(2);
            $a->setClubId(10);
            return $a;
        })();

        $this->assertFalse($this->service->userHasPermission('locked', 'verein.member.manage', 10), 'derived right suspended while locked');
        $this->assertTrue($this->service->userHasPermission('locked', 'verein.finance.read', 10), 'explicit assignment still applies');
    }

    public function testMappingToAMissingRoleGrantsNothing(): void {
        $this->club(10, ['admin' => 99]);
        $this->linkedPerson('max', $this->person(5, 'max'), $this->membershipIn(5, 10, 'admin'));

        $this->assertFalse($this->service->userHasPermission('max', 'verein.member.manage', 10));
    }

    public function testExplicitAndAutomaticRolesAddUp(): void {
        $this->role(1, ['verein.member.manage']);
        $this->role(2, ['verein.finance.read']);
        $this->club(10, ['admin' => 1]);
        $this->linkedPerson('max', $this->person(5, 'max'), $this->membershipIn(5, 10, 'admin'));
        $this->assign('max', 2, 10);

        $perms = $this->service->getPermissionsForClub('max', 10);
        sort($perms);
        $this->assertSame(['verein.finance.read', 'verein.member.manage'], $perms);
    }

    public function testAccountWithoutLinkedPersonGetsNothingAutomatic(): void {
        $this->role(1, ['verein.member.manage']);
        $this->club(10, ['admin' => 1]);

        $this->assertFalse($this->service->userHasPermission('stranger', 'verein.member.manage', 10));
    }

    public function testClubAssignmentsListAutomaticRolesSeparately(): void {
        $this->role(1, ['verein.member.manage']);
        $this->role(2, ['verein.finance.read']);
        $this->club(10, ['admin' => 1]);
        $this->assign('max', 2, 10);
        $this->userRoleMapper->method('findByClubId')->with(10)->willReturn($this->assignments);

        $max = $this->person(5, 'max');
        $max->setMembership($this->membershipIn(5, 10, 'admin'));
        $this->memberMapper->method('findByClub')->with(10)->willReturn([$max]);

        $result = array_column($this->service->getClubAssignments(10), null, 'userId');

        $this->assertSame(['role2'], $result['max']['roles']);
        $this->assertSame(['role1'], $result['max']['automaticRoles']);
    }
}
