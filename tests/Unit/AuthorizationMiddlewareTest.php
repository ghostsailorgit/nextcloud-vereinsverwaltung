<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Exception\PermissionDeniedException;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Middleware\AuthorizationMiddleware;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud ignores whatever beforeController() returns - the only way to
 * stop a request is to throw. These tests pin that behaviour: a denied
 * request must throw (and afterException() must turn it into a JSON error),
 * never just return a response.
 */
class AuthorizationMiddlewareTest extends TestCase {
    private RoleService&MockObject $roleService;
    private IUserSession&MockObject $userSession;
    private IRequest&MockObject $request;
    private AuthorizationMiddleware $middleware;

    protected function setUp(): void {
        $this->roleService = $this->createMock(RoleService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->request = $this->createMock(IRequest::class);

        $this->middleware = new AuthorizationMiddleware(
            $this->roleService,
            $this->userSession,
            $this->createMock(LoggerInterface::class),
            $this->request,
            l10n: SourceL10n::fromAppLanguage('de')
        );
    }

    private function loginAs(string $uid): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function clubIdParam(?int $clubId): void {
        $this->request->method('getParam')->willReturnCallback(
            fn (string $key, $default = null) => $key === 'clubId' && $clubId !== null ? (string)$clubId : $default
        );
    }

    private function controller(): object {
        return new class {
            #[RequirePermission('verein.member.view')]
            public function scoped() {}

            #[RequirePermission('verein.role.manage', clubScoped: false)]
            public function unscoped() {}

            public function open() {}
        };
    }

    public function testMethodWithoutAttributeIsNotChecked(): void {
        $this->roleService->expects($this->never())->method('userHasPermission');
        $this->middleware->beforeController($this->controller(), 'open');
        $this->addToAssertionCount(1);
    }

    public function testAllowsUserHoldingPermissionInThatClub(): void {
        $this->loginAs('anna');
        $this->clubIdParam(7);
        $this->roleService->expects($this->once())
            ->method('userHasPermission')
            ->with('anna', 'verein.member.view', 7)
            ->willReturn(true);

        $this->middleware->beforeController($this->controller(), 'scoped');
        $this->addToAssertionCount(1);
    }

    public function testDeniesUserWithoutPermissionByThrowing(): void {
        $this->loginAs('anna');
        $this->clubIdParam(7);
        $this->roleService->method('userHasPermission')->willReturn(false);

        $this->expectException(PermissionDeniedException::class);
        $this->middleware->beforeController($this->controller(), 'scoped');
    }

    public function testPermissionInAnotherClubDoesNotCount(): void {
        $this->loginAs('anna');
        $this->clubIdParam(2);
        // anna may only view club 1
        $this->roleService->method('userHasPermission')->willReturnCallback(
            fn (string $uid, string $perm, ?int $club) => $club === 1
        );

        $this->expectException(PermissionDeniedException::class);
        $this->middleware->beforeController($this->controller(), 'scoped');
    }

    public function testMissingClubIdIsRejected(): void {
        $this->loginAs('anna');
        $this->clubIdParam(null);
        $this->roleService->expects($this->never())->method('userHasPermission');

        $this->expectException(ValidationException::class);
        $this->middleware->beforeController($this->controller(), 'scoped');
    }

    public function testUnauthenticatedIsRejected(): void {
        $this->userSession->method('getUser')->willReturn(null);
        $this->clubIdParam(1);

        $this->expectException(PermissionDeniedException::class);
        $this->middleware->beforeController($this->controller(), 'scoped');
    }

    public function testClubIndependentPermissionIsCheckedWithoutClub(): void {
        $this->loginAs('anna');
        $this->clubIdParam(null);
        $this->roleService->expects($this->once())
            ->method('userHasPermission')
            ->with('anna', 'verein.role.manage', null)
            ->willReturn(true);

        $this->middleware->beforeController($this->controller(), 'unscoped');
        $this->addToAssertionCount(1);
    }

    public function testAfterExceptionTurnsPermissionErrorInto403Json(): void {
        $response = $this->middleware->afterException(
            $this->controller(),
            'scoped',
            new PermissionDeniedException('Missing permission: x')
        );

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(403, $response->getStatus());
        $this->assertSame('Missing permission: x', $response->getData()['message']);
    }

    public function testAfterExceptionTurnsValidationErrorInto400Json(): void {
        $response = $this->middleware->afterException(
            $this->controller(),
            'scoped',
            new ValidationException('Verein (clubId) fehlt')
        );

        $this->assertSame(400, $response->getStatus());
    }

    public function testAfterExceptionRethrowsForeignExceptions(): void {
        $this->expectException(\RuntimeException::class);
        $this->middleware->afterException($this->controller(), 'scoped', new \RuntimeException('boom'));
    }
}
