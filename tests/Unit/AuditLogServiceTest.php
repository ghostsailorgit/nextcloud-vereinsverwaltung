<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\AuditLogEntry;
use OCA\Verein\Db\AuditLogMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\Membership;
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

    public function testEqualObjectsAndArraysAreNoChange(): void {
        // Club::jsonSerialize() casts roleMapping to an object - a fresh instance on every call
        $this->assertSame([], $this->service->diff(['m' => (object)['admin' => 2], 'g' => ['a']], ['m' => (object)['admin' => 2], 'g' => ['a']]));
        $changes = $this->service->diff(['m' => (object)['admin' => 2]], ['m' => (object)['admin' => 3]]);
        $this->assertSame(['m'], array_keys($changes));
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

        // 'role' is on member's SAFE_FIELDS allow-list, unlike e.g. 'name' or 'iban'
        $this->service->record(3, 'member', 42, 'update', ['role' => ['old' => 'member', 'new' => 'treasurer']]);

        $this->assertSame(3, $stored->getClubId());
        $this->assertSame('member', $stored->getEntityType());
        $this->assertSame(42, $stored->getEntityId());
        $this->assertSame('update', $stored->getAction());
        $this->assertSame('max', $stored->getActorUserId());
        $this->assertSame('Max Mustermann', $stored->getActorDisplayName());
        $this->assertSame(['role' => ['old' => 'member', 'new' => 'treasurer']], json_decode($stored->getChanges(), true));
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
        $this->assertSame(['old' => false, 'new' => true], $changes['deceased'], 'on the safe list');
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

    /**
     * The regression this guards against: a deny-list of "known sensitive" fields missed derived/joined
     * ones (fullName, mandateReference, mandateFile - the path often contains the name, age), so a full
     * Member::jsonSerialize() payload leaked them into the log verbatim. Every field of a fully populated
     * member goes through record() here; only the SAFE_FIELDS allow-list may survive as an actual value.
     */
    public function testRecordOfAFullyPopulatedMemberLeaksNoPersonalValueAtAll(): void {
        $stored = null;
        $this->mapper->method('insert')->willReturnCallback(function (AuditLogEntry $e) use (&$stored) {
            $stored = $e;
            return $e;
        });

        $member = new Member();
        $member->setId(1);
        $member->setSalutation('Herr');
        $member->setName('Mustermann');
        $member->setFirstName('Max');
        $member->setAddress('Musterstr. 1');
        $member->setStreet('Musterstr. 1');
        $member->setPostalCode('12345');
        $member->setCity('Musterstadt');
        $member->setEmail('max@example.org');
        $member->setIban('DE89370400440532013000');
        $member->setBic('COBADEFFXXX');
        $member->setBirthDate('1990-01-01');
        $member->setUserId('maxmuster');

        $membership = new Membership();
        $membership->setId(9);
        $membership->setClubId(1);
        $membership->setRole('member');
        $membership->setJoinDate('2020-01-01');
        $membership->setMandateReference('MAND-Mustermann-1');
        $membership->setMandateFile('/Verein/Mandate/Mustermann_Max.pdf');
        $member->setMembership($membership);

        $this->service->record(1, 'member', 1, 'create', $member->jsonSerialize());

        $raw = (string)$stored->getChanges();
        foreach ([
            'Herr', 'Mustermann', 'Max', 'Musterstr', '12345', 'Musterstadt', 'max@example.org',
            'DE89370400440532013000', 'COBADEFFXXX', '1990-01-01', 'maxmuster',
            'MAND-Mustermann-1', 'Mustermann_Max.pdf',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, "leaked into the log: $needle");
        }

        // Kept in sync with AuditLogService::SAFE_FIELDS['member'] by hand (it is private on purpose -
        // this test exists so a mismatch shows up as a leaked value above, not as a silent drift here).
        $safeFields = ['id', 'role', 'joinDate', 'leaveDate', 'foundingMember', 'feeExemptJoinYear', 'deactivated', 'deceased', 'clubId'];
        $decoded = json_decode($raw, true);
        $payload = $member->jsonSerialize();
        foreach ($safeFields as $field) {
            $this->assertSame($payload[$field], $decoded[$field], "$field should have been logged as-is");
        }
        foreach (array_diff(array_keys($payload), $safeFields) as $field) {
            $this->assertNotSame($payload[$field], $decoded[$field] ?? null, "$field should have been redacted");
        }
    }
}
