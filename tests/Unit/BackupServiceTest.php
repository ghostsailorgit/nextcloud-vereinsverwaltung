<?php
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
