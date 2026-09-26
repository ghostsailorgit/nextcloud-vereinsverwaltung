<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Controller\MemberController;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Exception\NotFoundException;
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
 * anonymize() and export() must refuse a member id that belongs to a different club, even though
 * MemberService::anonymize() and SelfServiceService::forMemberId() look the person up globally -
 * regression test for a review finding: holding the permission in one club was enough to reach a
 * person who only ever belonged to another one.
 */
class MemberControllerClubScopeTest extends TestCase {
    private MemberService&MockObject $memberService;
    private SelfServiceService&MockObject $selfService;
    private IRequest&MockObject $request;
    private MemberController $controller;

    /** @var array<string, mixed> */
    private array $params = ['clubId' => '3'];

    protected function setUp(): void {
        $this->memberService = $this->createMock(MemberService::class);
        $this->selfService = $this->createMock(SelfServiceService::class);
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getParam')->willReturnCallback(
            fn (string $key, $default = null) => $this->params[$key] ?? $default
        );

        $roleService = $this->createMock(RoleService::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        $this->controller = new MemberController(
            'verein',
            $this->request,
            $this->memberService,
            new ValidationService(),
            $roleService,
            $this->createMock(ClubMapper::class),
            $session,
            $this->createMock(IUserManager::class),
            $this->selfService
        );
    }

    public function testAnonymizeChecksClubMembershipFirstAndRejectsAForeignId(): void {
        $this->memberService->method('find')->with(3, 9)->willThrowException(new NotFoundException('Mitglied nicht gefunden'));
        $this->memberService->expects($this->never())->method('anonymize');

        $response = $this->controller->anonymize(9);

        $this->assertSame(404, $response->getStatus());
    }

    public function testAnonymizeProceedsWhenThePersonIsAMemberOfThisClub(): void {
        $member = new Member();
        $member->setId(9);
        $this->memberService->method('find')->with(3, 9)->willReturn($member);
        $this->memberService->expects($this->once())->method('anonymize')->with(9)->willReturn($member);

        $response = $this->controller->anonymize(9);

        $this->assertSame(200, $response->getStatus());
    }

    public function testExportChecksClubMembershipFirstAndRejectsAForeignId(): void {
        $this->memberService->method('find')->with(3, 9)->willThrowException(new NotFoundException('Mitglied nicht gefunden'));
        $this->selfService->expects($this->never())->method('forMemberId');

        $response = $this->controller->export(9);

        $this->assertSame(404, $response->getStatus());
    }

    /**
     * Not asserting getStatus() === 200 here: constructing the real DataDownloadResponse on success
     * is not exercised by any other test in this suite either (no bootstrapped Nextcloud runtime in
     * a bare PHPUnit run), so it is out of scope for this regression test. What matters here - and
     * what a regression would break - is that find() passes and forMemberId() is actually called
     * with the club scope; verified two ways: the mock expectation below, and that the response is
     * not the 404 the ownership check would produce if it wrongly rejected an in-club person.
     */
    public function testExportRestrictsTheDataToThisClub(): void {
        $member = new Member();
        $member->setId(9);
        $this->memberService->method('find')->with(3, 9)->willReturn($member);
        $this->selfService->expects($this->once())->method('forMemberId')->with(9, 3)->willReturn([
            'linked' => true,
            'nextcloudAccount' => null,
            'person' => [],
            'memberships' => [],
            'fees' => [],
        ]);

        $response = $this->controller->export(9);

        $this->assertNotSame(404, $response->getStatus());
    }
}
