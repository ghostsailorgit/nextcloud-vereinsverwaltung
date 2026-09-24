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

        // 'status' is not on member's SENSITIVE_FIELDS list, unlike e.g. 'name' or 'iban'
        $this->service->record(3, 'member', 42, 'update', ['status' => ['old' => 'A', 'new' => 'B']]);

        $this->assertSame(3, $stored->getClubId());
        $this->assertSame('member', $stored->getEntityType());
        $this->assertSame(42, $stored->getEntityId());
        $this->assertSame('update', $stored->getAction());
        $this->assertSame('max', $stored->getActorUserId());
        $this->assertSame('Max Mustermann', $stored->getActorDisplayName());
        $this->assertSame(['status' => ['old' => 'A', 'new' => 'B']], json_decode($stored->getChanges(), true));
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

    public function testPeopleRelatedEntityTypesAreKeptLong(): void {
        foreach (['member', 'membership', 'fee', 'user_role'] as $type) {
            $this->assertContains($type, AuditLogService::LONG_RETENTION_TYPES, $type);
        }
        foreach (['club', 'club_account', 'fee_rate', 'fee_run', 'role'] as $type) {
            $this->assertNotContains($type, AuditLogService::LONG_RETENTION_TYPES, $type);
        }
    }

    public function testCutoffsAreThirtyDaysAndTenYearsBack(): void {
        $now = strtotime('2026-09-30 12:00:00');

        $cut = AuditLogService::cutoffs($now);

        $this->assertSame('2026-08-31 12:00:00', $cut['short']);
        $this->assertSame('2016-09-30 12:00:00', $cut['long']);
    }

    public function testPruneDeletesShortLivedTypesAfterThirtyDaysAndEverythingAfterTenYears(): void {
        $now = strtotime('2026-09-30 12:00:00');
        $calls = [];
        $this->mapper->method('deleteBefore')->willReturnCallback(function (string $cutoff, ?array $types = null, bool $include = true) use (&$calls) {
            $calls[] = [$cutoff, $types, $include];
            return count($calls) === 1 ? 5 : 2;
        });

        $deleted = $this->service->prune($now);

        $this->assertSame(7, $deleted);
        $this->assertSame([
            ['2026-08-31 12:00:00', AuditLogService::LONG_RETENTION_TYPES, false],
            ['2016-09-30 12:00:00', null, true],
        ], $calls);
    }

    // --- redaction of sensitive fields (member data)

    public function testRecordRedactsSensitiveMemberFieldsButKeepsOthers(): void {
        $stored = null;
        $this->mapper->method('insert')->willReturnCallback(function (AuditLogEntry $e) use (&$stored) {
            $stored = $e;
            return $e;
        });

        $this->service->record(1, 'member', 8, 'update', [
            'iban' => ['old' => 'DE89370400440532013000', 'new' => 'DE02120300000000202051'],
            'deceased' => ['old' => false, 'new' => true],
        ]);

        $changes = json_decode($stored->getChanges(), true);
        $this->assertSame(['redacted' => true], $changes['iban']);
        $this->assertSame(['old' => false, 'new' => true], $changes['deceased'], 'not on the sensitive list');
    }

    public function testRecordRedactsSensitiveFieldsOfACreatePayloadTooWherePlainValuesAreStored(): void {
        $stored = null;
        $this->mapper->method('insert')->willReturnCallback(function (AuditLogEntry $e) use (&$stored) {
            $stored = $e;
            return $e;
        });

        $this->service->record(1, 'member', 8, 'create', ['name' => 'Mustermann', 'deceased' => false]);

        $changes = json_decode($stored->getChanges(), true);
        $this->assertTrue($changes['name']);
        $this->assertFalse($changes['deceased']);
    }

    public function testRecordDoesNotRedactEntityTypesWithNoSensitiveFieldsList(): void {
        $stored = null;
        $this->mapper->method('insert')->willReturnCallback(function (AuditLogEntry $e) use (&$stored) {
            $stored = $e;
            return $e;
        });

        $this->service->record(1, 'club', 1, 'update', ['iban' => ['old' => 'a', 'new' => 'b']]);

        $this->assertSame(['iban' => ['old' => 'a', 'new' => 'b']], json_decode($stored->getChanges(), true));
    }

    public function testScrubEntityRewritesOnlyEntriesThatStillHoldSensitiveValues(): void {
        $clean = new AuditLogEntry();
        $clean->setId(1);
        $clean->setChanges(json_encode(['iban' => ['redacted' => true]]));
        $dirty = new AuditLogEntry();
        $dirty->setId(2);
        $dirty->setChanges(json_encode(['iban' => ['old' => 'DE00', 'new' => 'DE11'], 'deceased' => ['old' => false, 'new' => true]]));
        $noChanges = new AuditLogEntry();
        $noChanges->setId(3);
        $noChanges->setChanges(null);

        $this->mapper->method('findAllForEntity')->with('member', 8)->willReturn([$clean, $dirty, $noChanges]);
        $updated = [];
        $this->mapper->expects($this->once())->method('update')->willReturnCallback(function (AuditLogEntry $e) use (&$updated) {
            $updated[] = $e;
            return $e;
        });

        $this->service->scrubEntity('member', 8);

        $this->assertCount(1, $updated, 'only the entry that still had a real IBAN needed rewriting');
        $this->assertSame(2, $updated[0]->getId());
        $this->assertSame(
            ['iban' => ['redacted' => true], 'deceased' => ['old' => false, 'new' => true]],
            json_decode($updated[0]->getChanges(), true)
        );
    }

    public function testScrubEntityDoesNothingForEntityTypesWithNoSensitiveFieldsList(): void {
        $this->mapper->expects($this->never())->method('findAllForEntity');

        $this->service->scrubEntity('club', 1);
    }
}
