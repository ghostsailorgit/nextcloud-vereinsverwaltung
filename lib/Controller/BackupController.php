<?php
namespace OCA\Verein\Controller;

use OCA\Verein\Service\BackupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;

/**
 * Backups cover all clubs, so every action is reserved for Nextcloud
 * administrators (no @NoAdminRequired - Nextcloud itself rejects everybody else).
 */
class BackupController extends Controller {
    public function __construct(string $appName, IRequest $request, private BackupService $backups) {
        parent::__construct($appName, $request);
    }

    public function index(): JSONResponse {
        return new JSONResponse([
            'status' => 'ok',
            'backups' => $this->backups->listBackups(),
            'retentionDays' => BackupService::RETENTION_DAYS,
        ]);
    }

    public function create(): JSONResponse {
        try {
            $backup = $this->backups->createBackup();
            $this->backups->prune();
            return new JSONResponse(['status' => 'ok', 'backup' => $backup], Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return new JSONResponse(['status' => 'error', 'message' => 'Sicherung fehlgeschlagen'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Plain file download from a link, read-only.
     *
     * @NoCSRFRequired
     */
    public function download(string $name) {
        try {
            return new DataDownloadResponse($this->backups->getContent($name), $name, 'application/gzip');
        } catch (NotFoundException $e) {
            return new JSONResponse(['status' => 'error', 'message' => 'Sicherung nicht gefunden'], Http::STATUS_NOT_FOUND);
        }
    }
}
