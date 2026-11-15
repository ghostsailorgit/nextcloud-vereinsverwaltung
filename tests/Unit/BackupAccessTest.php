<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Exception\PermissionDeniedException;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\BackupAccess;
use OCA\Verein\Service\RBAC\RoleService;
use PHPUnit\Framework\TestCase;

class BackupAccessTest extends TestCase {
    private function access(bool $admin, bool $permission, int $clubCount): BackupAccess {
        $roles = $this->createMock(RoleService::class);
        $roles->method('isNextcloudAdmin')->willReturn($admin);
        $roles->method('userHasPermission')->willReturnCallback(
            fn (string $uid, string $perm, ?int $clubId) => $permission && $perm === 'verein.backup.restore' && $clubId === 1
        );
        $clubs = $this->createMock(ClubMapper::class);
        $list = [];
        for ($i = 1; $i <= $clubCount; $i++) {
            $club = new Club();
            $club->setId($i);
            $list[] = $club;
        }
        $clubs->method('findAll')->willReturn($list);
        return new BackupAccess($roles, $clubs, SourceL10n::fromAppLanguage('de'));
    }

    public function testANextcloudAdministratorAlwaysMay(): void {
        $this->assertTrue($this->access(true, false, 3)->allows('zz-admin'));
    }

    public function testThePermissionCountsOnlyWithExactlyOneClub(): void {
        $this->assertTrue($this->access(false, true, 1)->allows('zz-vorstand'));
        $this->assertFalse($this->access(false, true, 2)->allows('zz-vorstand'), 'a backup would replace the other club too');
        $this->assertFalse($this->access(false, true, 0)->allows('zz-vorstand'));
    }

    public function testWithoutThePermissionOrAccountNothing(): void {
        $this->assertFalse($this->access(false, false, 1)->allows('zz-lesen'));
        $this->assertFalse($this->access(true, true, 1)->allows(''));
    }

    public function testRefusalNamesTheReason(): void {
        $this->expectException(PermissionDeniedException::class);
        $this->expectExceptionMessage('mehr als einen Verein');
        $this->access(false, true, 2)->check('zz-vorstand');
    }
}
