<?php

namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCA\Verein\Service\MemberService;
use OCA\Verein\Service\FeeService;
use OCA\Verein\Service\Export\CsvExporter;
use OCA\Verein\Service\Export\PdfExporter;
use Psr\Log\LoggerInterface;

/**
 * Export Controller
 * Provides CSV and PDF export endpoints for members and fees
 */
class ExportController extends Controller {
    use RespondsWithErrors;

    private MemberService $memberService;
    private FeeService $feeService;
    private CsvExporter $csvExporter;
    private PdfExporter $pdfExporter;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        MemberService $memberService,
        FeeService $feeService,
        CsvExporter $csvExporter,
        PdfExporter $pdfExporter,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->memberService = $memberService;
        $this->feeService = $feeService;
        $this->csvExporter = $csvExporter;
        $this->pdfExporter = $pdfExporter;
        $this->logger = $logger;
    }

    /**
     * Export members as CSV
     *
     * @NoCSRFRequired
     * @NoAdminRequired
     * @return Response the file, or a JSON error response
     */
    #[RequirePermission('verein.member.view')]
    public function exportMembersAsCsv(int $clubId): Response {
        try {
            // Get all members
            $members = $this->memberService->findAll($clubId);

            // Format and export (works with empty array too)
            $formatted = $this->csvExporter->formatMembers($members);
            $result = $this->csvExporter->export($formatted['data'], $formatted['headers'], 'members');

            $response = new DataDownloadResponse(
                $result['content'],
                $result['filename'],
                $result['mimeType']
            );

            // Set Content-Disposition header for proper download
            $response->addHeader(
                'Content-Disposition',
                'attachment; filename="' . $result['filename'] . '"'
            );

            return $response;
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Export members as PDF
     *
     * @NoCSRFRequired
     * @NoAdminRequired
     * @return Response the file, or a JSON error response
     */
    #[RequirePermission('verein.member.view')]
    public function exportMembersAsPdf(int $clubId): Response {
        try {
            // Get all members
            $members = $this->memberService->findAll($clubId);

            // Export to PDF
            $result = $this->pdfExporter->exportMembers($members);

            $response = new DataDownloadResponse(
                $result['content'],
                $result['filename'],
                $result['mimeType']
            );

            // Set Content-Disposition header for proper download
            $response->addHeader(
                'Content-Disposition',
                'attachment; filename="' . $result['filename'] . '"'
            );

            return $response;
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Export fees as CSV
     *
     * @NoCSRFRequired
     * @NoAdminRequired
     * @return Response the file, or a JSON error response
     */
    #[RequirePermission('verein.finance.read')]
    public function exportFeesAsCsv(int $clubId): Response {
        try {
            // Get all fees
            $fees = $this->feeService->findAll($clubId);

            // Format and export (works with empty array too)
            $formatted = $this->csvExporter->formatFees($fees);
            $result = $this->csvExporter->export($formatted['data'], $formatted['headers'], 'fees');

            $response = new DataDownloadResponse(
                $result['content'],
                $result['filename'],
                $result['mimeType']
            );

            // Set Content-Disposition header for proper download
            $response->addHeader(
                'Content-Disposition',
                'attachment; filename="' . $result['filename'] . '"'
            );

            return $response;
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Export fees as PDF
     *
     * @NoCSRFRequired
     * @NoAdminRequired
     * @return Response the file, or a JSON error response
     */
    #[RequirePermission('verein.finance.read')]
    public function exportFeesAsPdf(int $clubId): Response {
        try {
            // Get all fees
            $fees = $this->feeService->findAll($clubId);

            // Export to PDF
            $result = $this->pdfExporter->exportFees($fees);

            $response = new DataDownloadResponse(
                $result['content'],
                $result['filename'],
                $result['mimeType']
            );

            // Set Content-Disposition header for proper download
            $response->addHeader(
                'Content-Disposition',
                'attachment; filename="' . $result['filename'] . '"'
            );

            return $response;
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }
}
