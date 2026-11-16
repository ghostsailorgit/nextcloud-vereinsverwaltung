<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */

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
        LoggerInterface $logger,
        private ?\OCA\Verein\Service\CustomFieldService $customFields = null,
        private ?\OCA\Verein\Service\RBAC\RoleService $roles = null,
        private ?\OCP\IUserSession $userSession = null,
        private ?\OCA\Verein\Service\MemberScope $scope = null
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
            // Get all members (a department head: those of their groups)
            $members = $this->memberService->findAll($clubId);
            $members = $this->scope?->filter($clubId, $members) ?? $members;

            // Format and export (works with empty array too)
            $formatted = $this->csvExporter->formatMembers($members);
            // the club's own fields as further columns, headed by their names (the import reads them back the same way);
            // only those the user sees - without the collaborators to tell, none
            if ($this->customFields !== null && $this->roles !== null && $this->userSession !== null) {
                $roleIds = $this->roles->sectionRoleIds($this->userSession->getUser()?->getUID() ?? '', $clubId);
                $visible = $this->customFields->access($clubId, $roleIds)['visible'];
                $fields = $this->customFields->fields($clubId, $visible);
                $values = $this->customFields->valuesForClub($clubId, $visible);
                foreach ($fields as $field) {
                    $formatted['headers'][] = $field->getLabel();
                }
                foreach ($members as $i => $member) {
                    foreach ($fields as $field) {
                        $formatted['data'][$i][] = $this->customFields->display($field, $values[$member->getId()][$field->getId()] ?? null);
                    }
                }
            }
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
            // Get all members (a department head: those of their groups)
            $members = $this->memberService->findAll($clubId);
            $members = $this->scope?->filter($clubId, $members) ?? $members;

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
