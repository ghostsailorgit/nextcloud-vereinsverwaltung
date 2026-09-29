<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Service\DunningService;
use OCA\Verein\Service\Export\PdfExporter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

/**
 * Dunning (Mahnwesen): preview, run (raises the dunning level) and the letters as PDF.
 * All of it needs the finance write permission - the letters carry names and addresses.
 */
class DunningController extends Controller {
    use RespondsWithErrors;

    private IL10N $l;

    public function __construct(
        $AppName,
        IRequest $request,
        private DunningService $dunning,
        private PdfExporter $pdf,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
        parent::__construct($AppName, $request);
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.finance.write')]
    public function preview(): JSONResponse {
        try {
            return new JSONResponse(['status' => 'ok'] + $this->dunning->plan($this->clubId(), $this->days('overdueDays'), $this->days('intervalDays')));
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.finance.write')]
    public function run(): JSONResponse {
        try {
            return new JSONResponse(['status' => 'ok'] + $this->dunning->run($this->clubId(), $this->days('overdueDays'), $this->days('intervalDays')));
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.finance.write')]
    public function letters(): Response {
        try {
            $ids = array_filter(array_map('intval', explode(',', (string)$this->request->getParam('feeIds', ''))));
            $deadline = (int)$this->request->getParam('deadlineDays', 14);
            $data = $this->dunning->letters($this->clubId(), $ids, $deadline);
            if ($data['letters'] === []) {
                return new JSONResponse(['status' => 'error', 'message' => $this->l->t('There are no reminder letters for these fees (paid, canceled or no reminder sent yet)')], 400);
            }
            $file = $this->pdf->exportDunningLetters($data);
            return new DataDownloadResponse($file['content'], $file['filename'], $file['mimeType']);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    private function clubId(): int {
        return (int)$this->request->getParam('clubId', 0);
    }

    private function days(string $key): int {
        return (int)$this->request->getParam($key, 14);
    }
}
