<?php
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\AuditLogEntry;
use OCA\Verein\Db\AuditLogMapper;
use OCA\Verein\Service\AuditLogService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditLogServiceTest extends TestCase {
    private AuditLogMapper&MockObject $mapper;
    private IUserSession&MockObject $userSession;
    private AuditLogService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(AuditLogMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->service = new AuditLogService($this->mapper, $this->userSession);
    }

    public function testDiffOnlyReportsChangedFields(): void {
        $changes = $this->service->diff(
            ['name' => 'Muster', 'email' => 'a@example.org', 'iban' => 'DE00'],
            ['name' => 'Muster', 'email' => 'b@example.org', 'iban' => 'DE00']
        );

        $this->assertSame(['email' => ['old' => 'a@example.org', 'new' => 'b@example.org']], $changes);
    }

    public function testDiffTreatsAFieldPresentOnOnlyOneSideAsChanged(): void {
        $changes = $this->service->diff(['a' => 1], ['a' => 1, 'b' => 2]);

        $this->assertSame(['b' => ['old' => null, 'new' => 2]], $changes);
    }

    public function testDiffOfIdenticalDataIsEmpty(): void {
        $this->assertSame([], $this->service->diff(['a' => 1], ['a' => 1]));
    }

    public function testRecordStoresActionEntityAndActor(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('max');
        $user->method('getDisplayName')->willReturn('Max Mustermann');
        $this->userSession->method('getUser')->willReturn($user);

        $stored = null;
        $this->mapper->expects($this->once())->method('insert')->willReturnCallback(function (AuditLogEntry $e) use (&$stored) {
            $stored = $e;
            return $e;
        });

        $this->service->record(3, 'member', 42, 'update', ['name' => ['old' => 'A', 'new' => 'B']]);

        $this->assertSame(3, $stored->getClubId());
        $this->assertSame('member', $stored->getEntityType());
        $this->assertSame(42, $stored->getEntityId());
        $this->assertSame('update', $stored->getAction());
        $this->assertSame('max', $stored->getActorUserId());
        $this->assertSame('Max Mustermann', $stored->getActorDisplayName());
        $this->assertSame(['name' => ['old' => 'A', 'new' => 'B']], json_decode($stored->getChanges(), true));
    }

    public function testRecordWithoutALoggedInUserLeavesTheActorEmpty(): void {
        $this->userSession->method('getUser')->willReturn(null);

        $stored = null;
        $this->mapper->method('insert')->willReturnCallback(function (AuditLogEntry $e) use (&$stored) {
            $stored = $e;
            return $e;
        });

        $this->service->record(null, 'club', 1, 'create');

        $this->assertNull($stored->getActorUserId());
        $this->assertNull($stored->getChanges());
    }
}
