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
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Service\ClubService;
use OCA\Verein\Service\RBAC\RoleService;
use OCA\Verein\Service\ValidationService;
use PHPUnit\Framework\TestCase;

/**
 * Sender name and reply-to address of the club's emails: stored as given, empty means "not set", nothing that could
 * break a mail header gets in.
 */
class ClubServiceMailSenderTest extends TestCase {
    private function service(): ClubService {
        $club = new Club();
        $club->setId(1);
        $club->setName('Musterverein');
        $clubs = $this->createMock(ClubMapper::class);
        $clubs->method('find')->willReturn($club);
        $clubs->method('findAll')->willReturn([$club]);
        $clubs->method('update')->willReturnArgument(0);
        return new ClubService($clubs, $this->createMock(ClubAccountMapper::class), $this->createMock(MembershipMapper::class),
            $this->createMock(UserRoleMapper::class), $this->createMock(RoleService::class), new ValidationService(),
            $this->createMock(RoleMapper::class), $this->createMock(FeeRateMapper::class));
    }

    public function testStoresSenderNameAndReplyTo(): void {
        $club = $this->service()->update(1, ['name' => 'Musterverein', 'mailSenderName' => ' Vorstand Musterverein ', 'mailReplyTo' => 'vorstand@example.org']);
        $this->assertSame('Vorstand Musterverein', $club->getMailSenderName());
        $this->assertSame('vorstand@example.org', $club->getMailReplyTo());
    }

    public function testEmptyMeansNotSet(): void {
        $club = $this->service()->update(1, ['name' => 'Musterverein', 'mailSenderName' => '', 'mailReplyTo' => '  ']);
        $this->assertNull($club->getMailSenderName());
        $this->assertNull($club->getMailReplyTo());
    }

    public function testRejectsAnInvalidReplyTo(): void {
        $this->expectException(ValidationException::class);
        $this->service()->update(1, ['name' => 'Musterverein', 'mailReplyTo' => 'vorstand at example.org']);
    }

    public function testRejectsLineBreaksInTheSenderName(): void {
        $this->expectException(ValidationException::class);
        $this->service()->update(1, ['name' => 'Musterverein', 'mailSenderName' => "Verein\r\nBcc: someone@example.org"]);
    }
}
