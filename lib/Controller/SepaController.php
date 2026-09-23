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
     * Generate SEPA-XML file for open fees
     * 
     * @param string $creditorName Name of the creditor
     * @param string $creditorIban IBAN of the creditor
     * @param string $creditorBic BIC of the creditor
     * @param string $creditorId Creditor ID for SEPA
     * @return StreamResponse
     */
    #[RequirePermission('verein.sepa.export')]
    public function export(
        string $creditorName,
        string $creditorIban,
        string $creditorBic,
        string $creditorId
    ): Response {
        try {
            $xml = $this->service->generateSepaXml(
                $creditorName,
                $creditorIban,
                $creditorBic,
                $creditorId
            );
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
        return new DataDownloadResponse(
            $xml,
            'sepa_export_' . date('Y-m-d') . '.xml',
            'application/xml'
        );
    }

    /**
     * @NoAdminRequired
     * 
     * Preview SEPA export (without downloading)
     */
    #[RequirePermission('verein.sepa.export')]
    public function preview(
        string $creditorName,
        string $creditorIban,
        string $creditorBic,
        string $creditorId
    ): DataResponse {
        try {
            $preview = $this->service->previewSepaExport(
                $creditorName,
                $creditorIban,
                $creditorBic,
                $creditorId
            );
        } catch (\Exception $e) {
            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }

        return new DataResponse($preview);
    }
}
