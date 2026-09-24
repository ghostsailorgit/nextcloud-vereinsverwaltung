<?php
namespace OCA\Verein\BackgroundJob;

use OCA\Verein\Service\AuditLogService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily removal of change-log entries past their retention period
 * (10 years for entries about people, 30 days for the rest - see AuditLogService).
 */
class AuditLogCleanupJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private AuditLogService $auditLog,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        try {
            $this->auditLog->prune();
        } catch (\Throwable $e) {
            $this->logger->error('Verein: Aufräumen des Änderungsprotokolls fehlgeschlagen', ['exception' => $e]);
        }
    }
}
