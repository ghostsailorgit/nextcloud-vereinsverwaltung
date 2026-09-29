<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\BackgroundJob;

use OCA\Verein\Service\BackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily backup of the club tables, then removal of backups older than 30 days.
 * Registered in appinfo/info.xml (runs with Nextcloud's own cron/background job mechanism).
 */
class DailyBackupJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private BackupService $backups,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        try {
            $this->backups->createBackup();
        } catch (\Throwable $e) {
            // keep old backups if a new one could not be written
            $this->logger->error('Verein: automatic backup failed', ['exception' => $e]);
            return;
        }
        $this->backups->prune();
    }
}
