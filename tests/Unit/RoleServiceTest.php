<?php
namespace OCA\Verein\Tests\Unit;

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
    private RoleService $service;

    /** @var array<int, Role> */
    private array $roles = [];
    /** @var UserRole[] */
    private array $assignments = [];

    protected function setUp(): void {
        $this->roleMapper = $this->createMock(RoleMapper::class);
        $this->userRoleMapper = $this->createMock(UserRoleMapper::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

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
            $this->createMock(LoggerInterface::class)
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
}
