<?php
namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCP\IRequest;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\ApiController;
use OCA\Verein\Service\SepaService;

/**
 * SEPA Controller for generating SEPA-XML files for direct debit
 */
class SepaController extends ApiController {
    private SepaService $service;

    public function __construct(
        string $appName,
        IRequest $request,
        SepaService $service
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
    public function export(int $clubId, ?int $accountId = null): Response {
        try {
            $result = $this->service->generateSepaXml($clubId, $accountId);
        } catch (\Exception $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }

        // StreamResponse expects a file path/resource, not raw string content
        // (that's what silently 404'd here); DataDownloadResponse is the
        // class for handing back generated content as a download, same as
        // ExportController's CSV/PDF endpoints already do.
        $response = new DataDownloadResponse(
            $result['xml'],
            'sepa_export_' . date('Y-m-d') . '.xml',
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
    public function preview(int $clubId, ?int $accountId = null): DataResponse {
        try {
            $preview = $this->service->previewSepaExport($clubId, $accountId);
        } catch (\Exception $e) {
            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }

        return new DataResponse($preview);
    }
}
