<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Controller;

use OCA\Verein\Service\BackupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

/**
 * Backups cover all clubs, so every action is reserved for Nextcloud
 * administrators (no @NoAdminRequired - Nextcloud itself rejects everybody else).
 */
class BackupController extends Controller {
    use RespondsWithErrors;

    private IL10N $l;

    public function __construct(string $appName, IRequest $request, private BackupService $backups, ?IL10N $l10n = null) {
        $this->l = $l10n ?? new SourceL10n();
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
            return $this->errorResponse($e);
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
            return new JSONResponse(['status' => 'error', 'message' => $this->l->t('Backup not found')], Http::STATUS_NOT_FOUND);
        }
    }
}
