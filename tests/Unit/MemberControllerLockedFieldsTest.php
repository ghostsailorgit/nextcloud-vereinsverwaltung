<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Controller\MemberController;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\Membership;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\MemberScope;
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
 * Which member fields someone may change (security audit 11/2026, H1 and M1-M3): a person is one record for all of
 * their clubs, a linked account gets rights in each of them, and department heads do not touch fees.
 */
class MemberControllerLockedFieldsTest extends TestCase {
    private const CLUB = 3;
    private const OTHER = 4;

    private MemberService&MockObject $memberService;
    private RoleService&MockObject $roleService;
    private MemberScope&MockObject $scope;
    /** @var array<string, mixed> */
    private array $params = [];
    /** @var array<int, string[]> club id => permissions of the user */
    private array $rights = [];
    /** @var int[]|null groups the user manages in CLUB, null = whole club */
    private ?array $scopedGroups = null;
    private ?array $seen = null;

    protected function setUp(): void {
        $this->memberService = $this->createMock(MemberService::class);
        $this->roleService = $this->createMock(RoleService::class);
        $this->roleService->method('userHasPermission')->willReturnCallback(
            fn (string $uid, string $permission, int $clubId) => in_array($permission, $this->rights[$clubId] ?? [], true)
        );
        $this->scope = $this->createMock(MemberScope::class);
        $this->scope->method('groups')->willReturnCallback(fn (int $clubId) => $clubId === self::CLUB ? $this->scopedGroups : null);
        $this->scope->method('covers')->willReturn(true);
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('secretary');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $this->memberService->method('update')->willReturnCallback(function (int $club, int $id, array $data) {
            $this->seen = $data;
            return $this->stored();
        });
        $this->memberService->method('create')->willReturnCallback(function (int $club, array $data) {
            $this->seen = $data;
            return $this->stored();
        });

        $this->controller = new MemberController(
            'verein',
            $request,
            $this->memberService,
            new ValidationService(l10n: SourceL10n::fromAppLanguage('de')),
            $this->roleService,
            $this->createMock(ClubMapper::class),
            $session,
            $this->createMock(IUserManager::class),
            $this->createMock(SelfServiceService::class),
            l10n: SourceL10n::fromAppLanguage('de'),
            scope: $this->scope
        );

        $this->params = [
            'clubId' => (string)self::CLUB, 'name' => 'Muster', 'email' => 'new@example.org', 'role' => 'member',
            'iban' => 'DE02120300000000202051', 'leaveDate' => '2026-01-31', 'deceased' => true,
            'feeRateId' => '7', 'honoraryMember' => true, 'mandateReference' => 'NEW-1',
        ];
    }

    private MemberController $controller;

    private function stored(?string $userId = null): Member {
        $member = new Member();
        $member->setId(9);
        $member->setName('Muster');
        $member->setEmail('old@example.org');
        $member->setIban('DE89370400440532013000');
        $member->setUserId($userId);
        $ms = new Membership();
        $ms->setMemberId(9);
        $ms->setClubId(self::CLUB);
        $ms->setRole('member');
        $ms->setMandateReference('OLD-1');
        $member->setMembership($ms);
        return $member;
    }

    /** @param int[] $clubs */
    private function person(array $clubs, ?string $userId = null): void {
        $this->memberService->method('find')->willReturn($this->stored($userId));
        $this->memberService->method('clubIdsOf')->willReturn($clubs);
    }

    public function testH1LinkingAnAccountNeedsRoleManagementInEveryClubOfThePerson(): void {
        $this->rights = [self::CLUB => ['verein.member.manage', 'verein.role.manage'], self::OTHER => ['verein.member.manage']];
        $this->person([self::CLUB, self::OTHER]);
        $this->params['userId'] = 'someone';

        $response = $this->controller->update(9);

        $this->assertSame(403, $response->getStatus());
        $this->assertNull($this->seen, 'nothing was saved');
    }

    public function testH1RoleManagementEverywhereMayLink(): void {
        $this->rights = [self::CLUB => ['verein.member.manage', 'verein.role.manage'], self::OTHER => ['verein.member.manage', 'verein.role.manage']];
        $this->person([self::CLUB, self::OTHER]);
        $this->params['userId'] = 'someone';

        $this->controller->update(9);

        $this->assertSame('someone', $this->seen['userId']);
    }

    public function testM1SharedDataStaysWithoutMemberManagementInTheOtherClub(): void {
        $this->rights = [self::CLUB => ['verein.member.manage', 'verein.role.manage']];
        $this->person([self::CLUB, self::OTHER]);

        $this->controller->update(9);

        $this->assertSame('old@example.org', $this->seen['email']);
        $this->assertSame('DE89370400440532013000', $this->seen['iban']);
        $this->assertFalse($this->seen['deceased']);
        // data of this club only is still changed
        $this->assertSame('2026-01-31', $this->seen['leaveDate']);
    }

    public function testM2LeavingNeedsRoleManagementOnlyWithALinkedAccount(): void {
        $this->rights = [self::CLUB => ['verein.member.manage']];
        $this->person([self::CLUB], 'linked-account');

        $this->controller->update(9);

        $this->assertNull($this->seen['leaveDate']);
        $this->assertFalse($this->seen['deceased']);
        $this->assertSame('new@example.org', $this->seen['email']);
    }

    public function testM2WithoutLinkedAccountLeavingStaysOpen(): void {
        $this->rights = [self::CLUB => ['verein.member.manage']];
        $this->person([self::CLUB]);

        $this->controller->update(9);

        $this->assertSame('2026-01-31', $this->seen['leaveDate']);
        $this->assertTrue((bool)$this->seen['deceased']);
    }

    public function testM3DepartmentHeadsKeepOffFeesAndNeedTheBankRight(): void {
        $this->rights = [self::CLUB => ['verein.member.manage']];
        $this->scopedGroups = [5];
        $this->person([self::CLUB]);

        $this->controller->update(9);

        $this->assertNull($this->seen['feeRateId'], 'null = unchanged');
        $this->assertFalse($this->seen['honoraryMember']);
        $this->assertSame('DE89370400440532013000', $this->seen['iban']);
        $this->assertSame('OLD-1', $this->seen['mandateReference']);
    }

    public function testM3BankRightOpensBankDetailsButNotFees(): void {
        $this->rights = [self::CLUB => ['verein.member.manage', 'verein.member.bank']];
        $this->scopedGroups = [5];
        $this->person([self::CLUB]);

        $this->controller->update(9);

        $this->assertSame('DE02120300000000202051', $this->seen['iban']);
        $this->assertSame('NEW-1', $this->seen['mandateReference']);
        $this->assertNull($this->seen['feeRateId']);
    }

    public function testM3WholeClubMemberManagementNeedsNoBankRight(): void {
        $this->rights = [self::CLUB => ['verein.member.manage']];
        $this->person([self::CLUB]);

        $this->controller->update(9);

        $this->assertSame('DE02120300000000202051', $this->seen['iban']);
        $this->assertSame('7', (string)$this->seen['feeRateId']);
    }
}
