<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCP\IRequest;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\ApiController;
use OCP\App\IAppManager;
use OCA\Verein\Service\Export\CsvExporter;
use OCA\Verein\Service\SepaPrenotificationService;
use OCA\Verein\Service\SepaService;

/**
 * SEPA Controller for generating SEPA-XML files for direct debit
 */
class SepaController extends ApiController {
    use RespondsWithErrors;

    private SepaService $service;

    public function __construct(
        string $appName,
        IRequest $request,
        SepaService $service,
        private SepaPrenotificationService $notice,
        private CsvExporter $csv,
        private IAppManager $appManager,
        private ?\OCA\Verein\Service\Clock $clock = null
    ) {
        parent::__construct($appName, $request);
        $this->service = $service;
    }

    /**
     * @NoAdminRequired
     * 
     * Generate SEPA-XML file for the open fees of a club, collected on one
     * of the club's bank accounts (default account if none given)
     * 
     * @param int $clubId Club to export
     * @param int|null $accountId Club bank account (creditor data comes from it)
     * @return StreamResponse
     */
    #[RequirePermission('verein.sepa.export')]
    public function export(int $clubId, ?int $accountId = null, ?string $collectionDate = null): Response {
        try {
            $result = $this->service->generateSepaXml($clubId, $accountId, $collectionDate);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }

        // StreamResponse expects a file path/resource, not raw string content
        // (that's what silently 404'd here); DataDownloadResponse is the
        // class for handing back generated content as a download, same as
        // ExportController's CSV/PDF endpoints already do.
        $response = new DataDownloadResponse(
            $result['xml'],
            'sepa_export_' . \OCA\Verein\Service\Clock::todayOf($this->clock) . '.xml',
            'application/xml'
        );
        // Fees left out (no IBAN or no signed mandate); the frontend warns
        // about it (details are listed in the preview).
        $response->addHeader('X-Sepa-Skipped', (string)$result['skippedCount']);
        // Which fees are in the file, so the UI can offer to mark exactly those as paid
        $response->addHeader('X-Sepa-Fee-Ids', implode(',', $result['feeIds']));
        return $response;
    }

    /**
     * @NoAdminRequired
     * 
     * Preview SEPA export (without downloading)
     */
    #[RequirePermission('verein.sepa.export')]
    public function preview(int $clubId, ?int $accountId = null, ?string $collectionDate = null): JSONResponse {
        try {
            $preview = $this->service->previewSepaExport($clubId, $accountId, $collectionDate);
            // the hint to this separate app (by another developer) is pointless once it is in use
            $preview['bookkeepingAppEnabled'] = $this->appManager->isEnabledForUser('vereinsbuchhaltung');
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }

        return new JSONResponse($preview);
    }

    /**
     * @NoAdminRequired
     *
     * What the collection is made up of, without names, as a CSV file (same fees and dates as preview/export).
     */
    #[RequirePermission('verein.sepa.export')]
    public function bookingSummary(int $clubId, ?string $collectionDate = null): Response {
        try {
            $summary = $this->service->bookingSummary($clubId, $collectionDate);
            $file = $this->csv->export($summary['data'], $summary['headers'], 'booking_summary_' . \OCA\Verein\Service\Clock::todayOf($this->clock));
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
        return new DataDownloadResponse($file['content'], $file['filename'], $file['mimeType']);
    }

    /**
     * @NoAdminRequired
     *
     * Optional: advance notice of the coming collection by email to the members in it (same account and collection
     * date as preview/export). The export does not depend on it.
     */
    #[RequirePermission('verein.sepa.export')]
    public function notice(int $clubId, ?int $accountId = null, ?string $collectionDate = null): JSONResponse {
        try {
            return new JSONResponse(['status' => 'ok'] + $this->notice->send($clubId, $accountId, $collectionDate));
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }
}
