<?php
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Controller\MemberController;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\Membership;
use OCA\Verein\Service\MemberService;
use OCA\Verein\Service\RBAC\RoleService;
use OCA\Verein\Service\SelfServiceService;
use OCA\Verein\Service\ValidationService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The membership role and the linked Nextcloud account decide which rights a
 * member gets automatically. Whoever may only edit member data (not manage
 * roles) must therefore not be able to change either - otherwise they could
 * make themselves a board member.
 */
class MemberControllerRoleGuardTest extends TestCase {
    private MemberService&MockObject $memberService;
    private RoleService&MockObject $roleService;
    private IRequest&MockObject $request;
    private MemberController $controller;

    /** @var array<string, mixed> */
    private array $params = [];

    protected function setUp(): void {
        $this->memberService = $this->createMock(MemberService::class);
        $this->roleService = $this->createMock(RoleService::class);
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getParam')->willReturnCallback(
            fn (string $key, $default = null) => $this->params[$key] ?? $default
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('secretary');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $this->controller = new MemberController(
            'verein',
            $this->request,
            $this->memberService,
            new ValidationService(),
            $this->roleService,
            $this->createMock(ClubMapper::class),
            $session,
            $this->createMock(IUserManager::class),
            $this->createMock(SelfServiceService::class)
        );

        $this->params = ['clubId' => '3', 'name' => 'Muster', 'email' => '', 'role' => 'admin', 'userId' => 'evil-account'];
    }

    private function roleManagement(bool $allowed): void {
        $this->roleService->method('userHasPermission')
            ->with('secretary', 'verein.role.manage', 3)
            ->willReturn($allowed);
    }

    private function existing(string $role): Member {
        $member = new Member();
        $member->setId(9);
        $member->setName('Muster');
        $ms = new Membership();
        $ms->setMemberId(9);
        $ms->setClubId(3);
        $ms->setRole($role);
        $member->setMembership($ms);
        return $member;
    }

    public function testCreateWithoutRoleManagementForcesPlainMemberAndNoLink(): void {
        $this->roleManagement(false);
        $seen = null;
        $this->memberService->method('create')->willReturnCallback(function (int $club, array $data) use (&$seen) {
            $seen = $data;
            return $this->existing('member');
        });

        $this->controller->create();

        $this->assertSame('member', $seen['role']);
        $this->assertNull($seen['userId']);
    }

    public function testUpdateWithoutRoleManagementKeepsTheCurrentRoleAndLink(): void {
        $this->roleManagement(false);
        $this->memberService->method('find')->with(3, 9)->willReturn($this->existing('treasurer'));
        $seen = null;
        $this->memberService->method('update')->willReturnCallback(function (int $club, int $id, array $data) use (&$seen) {
            $seen = $data;
            return $this->existing('treasurer');
        });

        $this->controller->update(9);

        $this->assertSame('treasurer', $seen['role'], 'the request tried to set admin');
        $this->assertNull($seen['userId'], 'null = leave the link untouched');
    }

    public function testRoleManagersMayChangeRoleAndLink(): void {
        $this->roleManagement(true);
        $seen = null;
        $this->memberService->method('update')->willReturnCallback(function (int $club, int $id, array $data) use (&$seen) {
            $seen = $data;
            return $this->existing('admin');
        });

        $this->controller->update(9);

        $this->assertSame('admin', $seen['role']);
        $this->assertSame('evil-account', $seen['userId']);
    }
}
