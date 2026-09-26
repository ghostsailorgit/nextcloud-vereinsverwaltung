<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Controller\AuditLogController;
use OCA\Verein\Db\AuditLogEntry;
use OCA\Verein\Db\AuditLogMapper;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The change log view pages backwards through a club's entries. The club comes from the (permission-checked)
 * request, the page size is fixed on the server, and "hasMore" tells the UI whether an older page exists.
 */
class AuditLogControllerTest extends TestCase {
    private AuditLogMapper&MockObject $mapper;
    private AuditLogController $controller;

    /** @var array<string, mixed> */
    private array $params = ['clubId' => '3'];

    protected function setUp(): void {
        $this->mapper = $this->createMock(AuditLogMapper::class);
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            fn (string $key, $default = null) => $this->params[$key] ?? $default
        );
        $this->controller = new AuditLogController('verein', $request, $this->mapper);
    }

    /** @return AuditLogEntry[] */
    private function entries(int $count, int $firstId): array {
        $list = [];
        for ($i = 0; $i < $count; $i++) {
            $e = new AuditLogEntry();
            $e->setId($firstId - $i);
            $e->setClubId(3);
            $e->setEntityType('fee');
            $e->setEntityId(1);
            $e->setAction('create');
            $list[] = $e;
        }
        return $list;
    }

    public function testFirstPageAsksForOneMoreThanThePageSizeAndReportsOlderEntries(): void {
        $this->mapper->expects($this->once())->method('findByClub')
            ->with(3, null, null, AuditLogController::PAGE_SIZE + 1, null)
            ->willReturn($this->entries(AuditLogController::PAGE_SIZE + 1, 500));

        $data = $this->controller->index()->getData();

        $this->assertTrue($data['hasMore']);
        $this->assertCount(AuditLogController::PAGE_SIZE, $data['data']);
        $this->assertSame(500, $data['data'][0]['id']);
        $this->assertContains('role', $data['safeFields']['member']);
        $this->assertNotContains('name', $data['safeFields']['member']);
    }

    public function testBeforeIdAndFiltersArePassedOnAndTheLastPageHasNoMore(): void {
        $this->params += ['beforeId' => '401', 'entityType' => 'member', 'entityId' => '17'];
        $this->mapper->expects($this->once())->method('findByClub')
            ->with(3, 'member', 17, AuditLogController::PAGE_SIZE + 1, 401)
            ->willReturn($this->entries(2, 400));

        $data = $this->controller->index()->getData();

        $this->assertFalse($data['hasMore']);
        $this->assertCount(2, $data['data']);
    }

    public function testADatabaseErrorDoesNotLeakItsText(): void {
        $this->mapper->method('findByClub')->willThrowException(new \RuntimeException('SQLSTATE secret table oc_x'));

        $response = $this->controller->index();

        $this->assertSame(500, $response->getStatus());
        $this->assertStringNotContainsString('SQLSTATE', json_encode($response->getData()));
    }
}
