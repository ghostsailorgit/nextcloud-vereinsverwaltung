<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Service\BackupService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BackupServiceTest extends TestCase {
    private const DAY = 86400;

    private function fileName(int $ts): string {
        return 'verein-backup-' . gmdate('Ymd-His', $ts) . '.json.gz';
    }

    /** @return string[] one backup per day, newest first, $days back from $now */
    private function daily(int $now, int $days): array {
        $names = [];
        for ($i = 0; $i < $days; $i++) {
            $names[] = $this->fileName($now - $i * self::DAY);
        }
        return $names;
    }

    public function testKeepsThirtyDaysAndDeletesOlder(): void {
        $now = gmmktime(3, 0, 0, 9, 25, 2026);
        $names = $this->daily($now, 40); // 40 daily backups, i = 0..39 days old

        $expired = BackupService::selectExpired($names, $now);

        // day 30 is exactly at the limit and still kept; days 31..39 go
        $this->assertCount(9, $expired);
        $this->assertContains($this->fileName($now - 31 * self::DAY), $expired);
        $this->assertContains($this->fileName($now - 39 * self::DAY), $expired);
        $this->assertNotContains($this->fileName($now - 30 * self::DAY), $expired);
        $this->assertNotContains($this->fileName($now), $expired);
    }

    public function testNothingIsDeletedWhileEverythingIsRecent(): void {
        $now = gmmktime(3, 0, 0, 9, 25, 2026);
        $this->assertSame([], BackupService::selectExpired($this->daily($now, 30), $now));
    }

    public function testTheNewestBackupsSurviveEvenWhenTheJobHasBeenFailingForMonths(): void {
        $now = gmmktime(3, 0, 0, 9, 25, 2026);
        // last successful backups are 100..106 days old
        $names = [];
        for ($i = 100; $i < 110; $i++) {
            $names[] = $this->fileName($now - $i * self::DAY);
        }

        $expired = BackupService::selectExpired($names, $now);

        $this->assertCount(3, $expired, '10 backups, the newest 7 are always kept');
        $this->assertNotContains($this->fileName($now - 100 * self::DAY), $expired);
        $this->assertContains($this->fileName($now - 109 * self::DAY), $expired);
    }

    public function testUpperLimitCapsManualBackups(): void {
        $now = gmmktime(12, 0, 0, 9, 25, 2026);
        $names = [];
        for ($i = 0; $i < 120; $i++) {
            $names[] = $this->fileName($now - $i * 60); // one per minute, all recent
        }

        $expired = BackupService::selectExpired($names, $now);

        $this->assertCount(20, $expired);
        $this->assertContains($this->fileName($now - 119 * 60), $expired, 'oldest goes first');
    }

    public function testForeignFilesAreNeverTouched(): void {
        $now = gmmktime(3, 0, 0, 9, 25, 2026);
        $names = array_merge(['notes.txt', 'verein-backup-2020.json', '../verein-backup-20200101-000000.json.gz'], $this->daily($now, 3));

        $this->assertSame([], BackupService::selectExpired($names, $now));
        $this->assertNull(BackupService::timeFromName('notes.txt'));
    }

    public function testNameValidationBlocksPathTricks(): void {
        $this->assertTrue(BackupService::isValidName('verein-backup-20260925-030000.json.gz'));
        $this->assertFalse(BackupService::isValidName('../verein-backup-20260925-030000.json.gz'));
        $this->assertFalse(BackupService::isValidName('verein-backup-20260925-030000.json.gz/../x'));
        $this->assertFalse(BackupService::isValidName('verein-backup-20260925-030000.json'));
        $this->assertFalse(BackupService::isValidName(''));
    }

    public function testTimeFromNameIsUtc(): void {
        $this->assertSame(gmmktime(3, 4, 5, 9, 25, 2026), BackupService::timeFromName('verein-backup-20260925-030405.json.gz'));
    }

    public function testEveryTableCreatedByAMigrationIsBackedUp(): void {
        $created = [];
        foreach (glob(__DIR__ . '/../../lib/Migration/*.php') as $file) {
            if (preg_match_all("/createTable\('([a-z_]+)'/", file_get_contents($file), $m)) {
                array_push($created, ...$m[1]);
            }
        }
        $this->assertNotEmpty($created);
        $this->assertEqualsCanonicalizing(array_unique($created), BackupService::TABLES, 'add new tables to BackupService::TABLES');
    }

    private function backupFile(array $overrides = [], ?array $tables = null): string {
        $tables ??= array_fill_keys(BackupService::TABLES, []);
        $tables['verein_fee_rates'] = [['id' => 1, 'club_id' => 1, 'name' => 'Erwachsene', 'amount' => '24.00', 'is_default' => 1]];
        return gzencode(json_encode(array_merge(['app' => 'verein', 'appVersion' => '0.10.0-beta', 'created' => '2026-09-25T03:00:00+00:00', 'tables' => $tables], $overrides)));
    }

    public function testParseAcceptsAValidBackup(): void {
        $parsed = BackupService::parse($this->backupFile());
        $this->assertSame('2026-09-25T03:00:00+00:00', $parsed['created']);
        $this->assertCount(1, $parsed['tables']['verein_fee_rates']);
        $this->assertSame(BackupService::TABLES, array_keys($parsed['tables']));
    }

    public function testAnOlderBackupWithoutTheAuditLogStillParsesAndLeavesThatTableAlone(): void {
        $tables = array_fill_keys(BackupService::TABLES, []);
        unset($tables['verein_audit_log']);
        $parsed = BackupService::parse($this->backupFile([], $tables));
        $this->assertArrayNotHasKey('verein_audit_log', $parsed['tables']);

        $service = $this->serviceWithFailingWrite(null, $db);
        $result = $service->restore($parsed);

        $this->assertArrayNotHasKey('verein_audit_log', $result['tables']);
        $this->assertCount(count(BackupService::TABLES) - 1, $result['tables']);
    }

    /**
     * @dataProvider brokenBackups
     */
    public function testParseRejectsBrokenBackups(string $content): void {
        $this->expectException(\InvalidArgumentException::class);
        BackupService::parse($content);
    }

    public static function brokenBackups(): array {
        $tables = array_fill_keys(BackupService::TABLES, []);
        $gz = static fn (array $d) => gzencode(json_encode($d));
        $missing = $tables;
        unset($missing['verein_fees']);
        return [
            'not gzip' => ['{"app":"verein"}'],
            'not json' => [gzencode('nope')],
            'other app' => [$gz(['app' => 'other', 'tables' => $tables])],
            'table missing' => [$gz(['app' => 'verein', 'tables' => $missing])],
            'empty row' => [$gz(['app' => 'verein', 'tables' => array_merge($tables, ['verein_fees' => [[]]])])],
            'bad column name' => [$gz(['app' => 'verein', 'tables' => array_merge($tables, ['verein_fees' => [['id; DROP TABLE x' => 1]]])])],
            'nested value' => [$gz(['app' => 'verein', 'tables' => array_merge($tables, ['verein_fees' => [['id' => [1]]]])])],
        ];
    }

    /** A service whose query builder accepts everything and throws on the Nth write statement. */
    private function serviceWithFailingWrite(?int $failOnStatement, ?\OCP\IDBConnection &$db = null): BackupService {
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetchAll')->willReturn([]);
        $calls = 0;
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        foreach (['select', 'from', 'delete', 'insert', 'setValue'] as $m) {
            $qb->method($m)->willReturnSelf();
        }
        $qb->method('createNamedParameter')->willReturn(':p');
        $qb->method('executeQuery')->willReturn($result);
        $qb->method('executeStatement')->willReturnCallback(function () use (&$calls, $failOnStatement) {
            $calls++;
            if ($failOnStatement !== null && $calls === $failOnStatement) {
                throw new \RuntimeException('boom');
            }
            return 1;
        });
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);

        $folder = $this->createMock(\OCP\Files\SimpleFS\ISimpleFolder::class);
        $folder->method('fileExists')->willReturn(false);
        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturn($folder);
        $factory = $this->createMock(IAppDataFactory::class);
        $factory->method('get')->willReturn($appData);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1790000000);
        return new BackupService($db, $factory, $this->createMock(IAppManager::class), $time, $this->createMock(LoggerInterface::class));
    }

    public function testRestoreCommitsInOneTransactionAndWritesASafetyBackupFirst(): void {
        $service = $this->serviceWithFailingWrite(null, $db);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollBack');

        $result = $service->restore(BackupService::parse($this->backupFile()));

        $this->assertSame('verein-backup-' . gmdate('Ymd-His', 1790000000) . '.json.gz', $result['safetyBackup']);
        $this->assertSame(1, $result['tables']['verein_fee_rates']);
        $this->assertSame(0, $result['tables']['verein_members']);
    }

    public function testFailedRestoreRollsBackAndRethrows(): void {
        // statements 1-8 delete the tables, 9 is the first insert
        $service = $this->serviceWithFailingWrite(9, $db);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollBack');

        $this->expectException(\RuntimeException::class);
        $service->restore(BackupService::parse($this->backupFile()));
    }

    public function testDownloadRejectsInvalidAndUnknownNames(): void {
        $folder = $this->createMock(\OCP\Files\SimpleFS\ISimpleFolder::class);
        $folder->method('fileExists')->willReturn(false);
        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturn($folder);
        $factory = $this->createMock(IAppDataFactory::class);
        $factory->method('get')->with('verein')->willReturn($appData);
        $service = new BackupService(
            $this->createMock(IDBConnection::class),
            $factory,
            $this->createMock(IAppManager::class),
            $this->createMock(ITimeFactory::class),
            $this->createMock(LoggerInterface::class),
        );

        foreach (['../../config/config.php', 'verein-backup-20260925-030000.json.gz'] as $name) {
            try {
                $service->getContent($name);
                $this->fail("$name should not be readable");
            } catch (NotFoundException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
