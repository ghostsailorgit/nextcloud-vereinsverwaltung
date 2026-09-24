<?php
namespace OCA\Verein\Service;

use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Backups of the club tables.
 *
 * Deliberately plain PHP (no mysqldump, no shell): it works on every database
 * Nextcloud supports and needs nothing from the host. The tables are written
 * as gzipped JSON into the app's data folder, which is not reachable through
 * Nextcloud Files or the web.
 */
class BackupService {
    /** Every table of the app. BackupServiceTest checks this against the migrations. */
    public const TABLES = [
        'verein_clubs',
        'verein_club_accounts',
        'verein_members',
        'verein_memberships',
        'verein_fee_rates',
        'verein_fees',
        'verein_roles',
        'verein_user_roles',
    ];

    /** Backups older than this are removed. */
    public const RETENTION_DAYS = 30;
    /** Never prune below this many backups, so a failing job cannot empty the folder. */
    public const KEEP_AT_LEAST = 7;
    /** Upper bound in case somebody clicks "back up now" all day. */
    public const KEEP_AT_MOST = 100;

    private const FOLDER = 'backups';
    private const NAME_PATTERN = '/^verein-backup-(\d{8})-(\d{6})\.json\.gz$/';

    private IAppData $appData;

    public function __construct(
        private IDBConnection $db,
        IAppDataFactory $appDataFactory,
        private IAppManager $appManager,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {
        // by app id: the injected IAppData depends on the current request's app, which a cron job does not have
        $this->appData = $appDataFactory->get('verein');
    }

    public static function isValidName(string $name): bool {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /** UTC time encoded in a backup file name, or null for foreign files. */
    public static function timeFromName(string $name): ?int {
        if (!preg_match(self::NAME_PATTERN, $name, $m)) {
            return null;
        }
        $t = \DateTimeImmutable::createFromFormat('YmdHis', $m[1] . $m[2], new \DateTimeZone('UTC'));
        return $t === false ? null : $t->getTimestamp();
    }

    /**
     * Which of the given backup names should be deleted.
     *
     * Everything older than $retentionDays goes, but the newest $atLeast are
     * always kept and no more than $atMost are kept. Names that are not ours
     * are never touched.
     *
     * @param string[] $names
     * @return string[]
     */
    public static function selectExpired(
        array $names,
        int $now,
        int $retentionDays = self::RETENTION_DAYS,
        int $atLeast = self::KEEP_AT_LEAST,
        int $atMost = self::KEEP_AT_MOST,
    ): array {
        $dated = [];
        foreach ($names as $name) {
            $t = self::timeFromName($name);
            if ($t !== null) {
                $dated[$name] = $t;
            }
        }
        arsort($dated); // newest first
        $expired = [];
        $rank = 0;
        foreach ($dated as $name => $t) {
            $rank++;
            $tooOld = $t < $now - $retentionDays * 86400;
            if ($rank > $atMost || ($tooOld && $rank > $atLeast)) {
                $expired[] = (string)$name;
            }
        }
        return $expired;
    }

    /**
     * Writes a backup of all club tables.
     *
     * @return array{name: string, size: int, created: int}
     */
    public function createBackup(): array {
        $tables = [];
        foreach (self::TABLES as $table) {
            $tables[$table] = $this->dumpTable($table);
        }
        $now = $this->time->getTime();
        $payload = json_encode([
            'app' => 'verein',
            'appVersion' => $this->appManager->getAppVersion('verein'),
            'created' => gmdate('c', $now),
            'tables' => $tables,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $data = gzencode($payload, 9);
        if ($data === false) {
            throw new \RuntimeException('Sicherung konnte nicht komprimiert werden');
        }

        $name = 'verein-backup-' . gmdate('Ymd-His', $now) . '.json.gz';
        $folder = $this->folder();
        if ($folder->fileExists($name)) {
            $folder->getFile($name)->putContent($data);
        } else {
            $folder->newFile($name, $data);
        }
        return ['name' => $name, 'size' => strlen($data), 'created' => $now];
    }

    /**
     * Deletes expired backups.
     *
     * @return string[] names of the deleted backups
     */
    public function prune(): array {
        $folder = $this->folder();
        $names = [];
        foreach ($folder->getDirectoryListing() as $file) {
            $names[] = $file->getName();
        }
        $deleted = [];
        foreach (self::selectExpired($names, $this->time->getTime()) as $name) {
            try {
                $folder->getFile($name)->delete();
                $deleted[] = $name;
            } catch (\Throwable $e) {
                $this->logger->warning('Verein: alte Sicherung konnte nicht gelöscht werden: ' . $name, ['exception' => $e]);
            }
        }
        return $deleted;
    }

    /**
     * @return array<int, array{name: string, size: int, created: int}> newest first
     */
    public function listBackups(): array {
        $result = [];
        foreach ($this->folder()->getDirectoryListing() as $file) {
            $t = self::timeFromName($file->getName());
            if ($t === null) {
                continue;
            }
            $result[] = ['name' => $file->getName(), 'size' => $file->getSize(), 'created' => $t];
        }
        usort($result, static fn ($a, $b) => $b['created'] <=> $a['created']);
        return $result;
    }

    /**
     * @throws NotFoundException
     */
    public function getContent(string $name): string {
        if (!self::isValidName($name)) {
            throw new NotFoundException('Sicherung nicht gefunden');
        }
        $folder = $this->folder();
        if (!$folder->fileExists($name)) {
            throw new NotFoundException('Sicherung nicht gefunden');
        }
        return $folder->getFile($name)->getContent();
    }

    /**
     * Reads a backup either from the app data folder (by name) or from a local file
     * (a downloaded backup, e.g. when moving to a fresh instance).
     *
     * @throws NotFoundException
     */
    public function readBackup(string $nameOrPath): string {
        if (self::isValidName($nameOrPath)) {
            return $this->getContent($nameOrPath);
        }
        if (is_file($nameOrPath) && is_readable($nameOrPath)) {
            $data = file_get_contents($nameOrPath);
            if ($data !== false) {
                return $data;
            }
        }
        throw new NotFoundException('Sicherung nicht gefunden: ' . $nameOrPath);
    }

    /**
     * Decodes and validates the content of a backup file. Nothing is written.
     *
     * @return array{created: string, appVersion: string, tables: array<string, array<int, array<string, mixed>>>}
     * @throws \InvalidArgumentException if the file is not a usable backup of this app
     */
    public static function parse(string $gzipped): array {
        $json = @gzdecode($gzipped);
        if ($json === false) {
            throw new \InvalidArgumentException('Die Datei ist keine gültige (gzip-komprimierte) Sicherung');
        }
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['app'] ?? null) !== 'verein' || !is_array($data['tables'] ?? null)) {
            throw new \InvalidArgumentException('Die Datei ist keine Sicherung der Vereinsverwaltung');
        }
        foreach (self::TABLES as $table) {
            if (!isset($data['tables'][$table]) || !is_array($data['tables'][$table])) {
                throw new \InvalidArgumentException('In der Sicherung fehlt die Tabelle ' . $table);
            }
            foreach ($data['tables'][$table] as $row) {
                if (!is_array($row) || $row === []) {
                    throw new \InvalidArgumentException('Ungültige Zeile in Tabelle ' . $table);
                }
                foreach ($row as $column => $value) {
                    if (!is_string($column) || preg_match('/^[a-z][a-z0-9_]*$/', $column) !== 1 || is_array($value) || is_object($value)) {
                        throw new \InvalidArgumentException('Ungültige Spalte in Tabelle ' . $table);
                    }
                }
            }
        }
        return [
            'created' => (string)($data['created'] ?? ''),
            'appVersion' => (string)($data['appVersion'] ?? ''),
            'tables' => array_intersect_key($data['tables'], array_flip(self::TABLES)),
        ];
    }

    /**
     * Number of rows currently in each club table.
     *
     * @return array<string, int>
     */
    public function currentCounts(): array {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = count($this->dumpTable($table));
        }
        return $counts;
    }

    /**
     * Replaces the content of all club tables with the given (already parsed) backup.
     *
     * A fresh backup of the current state is written first, so a restore can be undone.
     * The replacement itself is one transaction: it either fully succeeds or changes nothing.
     * Calendars and their shares are not part of the backup; they refresh when a member is saved.
     *
     * @param array{tables: array<string, array<int, array<string, mixed>>>} $backup result of parse()
     * @return array{safetyBackup: string, tables: array<string, int>}
     */
    public function restore(array $backup): array {
        $safety = $this->createBackup();

        $this->db->beginTransaction();
        try {
            foreach (self::TABLES as $table) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($table)->executeStatement();
            }
            foreach (self::TABLES as $table) {
                foreach ($backup['tables'][$table] as $row) {
                    $this->insertRow($table, $row);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Verein: Wiederherstellung fehlgeschlagen, nichts wurde geändert', ['exception' => $e]);
            throw $e;
        }

        $this->realignSequences();

        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = count($backup['tables'][$table]);
        }
        return ['safetyBackup' => $safety['name'], 'tables' => $counts];
    }

    private function insertRow(string $table, array $row): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert($table);
        foreach ($row as $column => $value) {
            if ($value === null) {
                $qb->setValue($column, $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
            } elseif (is_bool($value)) {
                $qb->setValue($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_BOOL));
            } elseif (is_int($value)) {
                $qb->setValue($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT));
            } else {
                $qb->setValue($column, $qb->createNamedParameter((string)$value));
            }
        }
        $qb->executeStatement();
    }

    /**
     * PostgreSQL does not advance its id counters when rows are inserted with explicit ids,
     * so the next new row would collide. Other databases do this by themselves.
     */
    private function realignSequences(): void {
        if (!method_exists($this->db, 'getDatabaseProvider')
            || !defined(IDBConnection::class . '::PLATFORM_POSTGRES')
            || $this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_POSTGRES) {
            return;
        }
        foreach (self::TABLES as $table) {
            try {
                $this->db->executeQuery(
                    "SELECT setval(pg_get_serial_sequence('*PREFIX*$table', 'id'), COALESCE((SELECT MAX(id) FROM *PREFIX*$table), 1))"
                )->closeCursor();
            } catch (\Throwable $e) {
                $this->logger->warning('Verein: ID-Zähler von ' . $table . ' konnte nicht angeglichen werden', ['exception' => $e]);
            }
        }
    }

    private function dumpTable(string $table): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($table);
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    private function folder(): ISimpleFolder {
        try {
            return $this->appData->getFolder(self::FOLDER);
        } catch (NotFoundException $e) {
            return $this->appData->newFolder(self::FOLDER);
        }
    }
}
