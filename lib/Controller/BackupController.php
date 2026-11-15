<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Controller;

use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Service\BackupService;
use OCP\IUserSession;
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

    public function __construct(string $appName, IRequest $request, private BackupService $backups, private IUserSession $userSession, ?IL10N $l10n = null) {
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
     * What restoring this stored backup would change. Nothing is written.
     */
    public function previewRestore(string $name): JSONResponse {
        return $this->respond(fn () => $this->backups->summary($this->stored($name)));
    }

    /**
     * Replaces all club data (of every club) with this stored backup; the current state is backed up first.
     */
    public function restore(string $name): JSONResponse {
        return $this->respond(fn () => $this->restoreBackup($this->stored($name)));
    }

    /**
     * What restoring an uploaded backup file (e.g. one downloaded earlier) would change. Nothing is written.
     */
    public function previewUpload(): JSONResponse {
        return $this->respond(fn () => $this->backups->summary($this->uploaded()));
    }

    /**
     * Replaces all club data with an uploaded backup file; the current state is backed up first.
     */
    public function restoreUpload(): JSONResponse {
        return $this->respond(fn () => $this->restoreBackup($this->uploaded()));
    }

    private function respond(callable $action): JSONResponse {
        try {
            return new JSONResponse(['status' => 'ok'] + $action());
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /** @throws ValidationException */
    private function stored(string $name): array {
        try {
            return BackupService::parse($this->backups->getContent($name));
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException($this->l->t('The file is not a valid backup of this app'));
        }
    }

    /** @throws ValidationException */
    private function uploaded(): array {
        $file = $this->request->getUploadedFile('backup');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)) {
            throw new ValidationException($this->l->t('No backup file was uploaded'));
        }
        if ((int)($file['size'] ?? 0) > BackupService::MAX_UPLOAD_BYTES || filesize($file['tmp_name']) > BackupService::MAX_UPLOAD_BYTES) {
            throw new ValidationException($this->l->t('The file is too large for a backup of this app'));
        }
        $content = file_get_contents($file['tmp_name']);
        try {
            return BackupService::parse($content === false ? '' : $content);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException($this->l->t('The file is not a valid backup of this app'));
        }
    }

    /** @throws ValidationException */
    private function restoreBackup(array $backup): array {
        $this->backups->summary($backup); // refuses a backup of a newer app version
        try {
            return $this->backups->restore($backup, $this->userSession->getUser()?->getUID());
        } catch (\Throwable $e) {
            // the service rolled back and logged the details; they may contain SQL, so only this goes out
            throw new ValidationException($this->l->t('The restore failed, nothing was changed. The details are in the Nextcloud log.'));
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
