<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Service\AssociationReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * The membership report to a sports association. The file is requested by POST: it carries the name of a contact person,
 * which does not belong in a URL.
 */
class AssociationReportController extends Controller {
    use RespondsWithErrors;

    public function __construct(
        $AppName,
        IRequest $request,
        private AssociationReportService $reports
    ) {
        parent::__construct($AppName, $request);
    }

    private function clubId(): int {
        return (int)$this->request->getParam('clubId', 0);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.view')]
    public function show(): JSONResponse {
        try {
            return new JSONResponse(['status' => 'ok', 'data' => $this->reports->report($this->clubId(), (string)$this->request->getParam('date', ''))]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.view')]
    public function xml(): Response {
        try {
            $date = (string)$this->request->getParam('date', '');
            $xml = $this->reports->xml(
                $this->clubId(),
                $date,
                (string)$this->request->getParam('number', ''),
                (string)$this->request->getParam('contact', ''),
                (string)$this->request->getParam('sportCode', '')
            );
            return new DataDownloadResponse($xml, 'bestandserhebung-' . substr($date, 0, 4) . '.xml', 'application/xml');
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }
}
