<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubAccountMapper;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Db\RoleMapper;
use OCA\Verein\Db\UserRoleMapper;
use OCA\Verein\Service\AuditLogService;
use OCA\Verein\Service\ClubService;
use OCA\Verein\Service\RBAC\RoleService;
use OCA\Verein\Service\ValidationService;
use PHPUnit\Framework\TestCase;

/**
 * Saving the "automatic rights" form unchanged must not fill the change log.
 */
class ClubServiceRoleMappingTest extends TestCase {
    private function service(Club $club, AuditLogService $audit): ClubService {
        $clubs = $this->createMock(ClubMapper::class);
        $clubs->method('find')->willReturn($club);
        $clubs->method('update')->willReturnArgument(0);
        return new ClubService($clubs, $this->createMock(ClubAccountMapper::class), $this->createMock(MembershipMapper::class),
            $this->createMock(UserRoleMapper::class), $this->createMock(RoleService::class), new ValidationService(),
            $this->createMock(RoleMapper::class), $this->createMock(FeeRateMapper::class), $audit);
    }

    private function club(?string $mapping): Club {
        $club = new Club();
        $club->setId(1);
        $club->setName('Musterverein');
        $club->setRoleMapping($mapping);
        return $club;
    }

    public function testAnUnchangedMappingIsNotLogged(): void {
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->never())->method('record');
        $this->service($this->club('{"admin":2}'), $audit)->setRoleMapping(1, ['admin' => '2', 'member' => '', 'treasurer' => '']);
    }

    public function testAChangedMappingIsLoggedWithOldAndNew(): void {
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('record')->with(1, 'club', 1, 'update', $this->callback(
            fn (array $c) => (array)$c['roleMapping']['old'] == ['admin' => 2] && (array)$c['roleMapping']['new'] == ['admin' => 2, 'member' => 2]
        ));
        $this->service($this->club('{"admin":2}'), $audit)->setRoleMapping(1, ['admin' => '2', 'member' => '2']);
    }
}
