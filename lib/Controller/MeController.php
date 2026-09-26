<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Controller;

use OCA\Verein\Service\SelfServiceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Self-service: the logged-in user's own record. No RequirePermission on
 * purpose - the data returned is always and only the one person linked to
 * the current Nextcloud account (see SelfServiceService).
 */
class MeController extends Controller {
    public function __construct(
        $AppName,
        IRequest $request,
        private SelfServiceService $selfService,
        private IUserSession $userSession
    ) {
        parent::__construct($AppName, $request);
    }

    private function uid(): ?string {
        return $this->userSession->getUser()?->getUID();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['status' => 'error', 'message' => 'Authentication required'], 401);
        }
        return new JSONResponse(['status' => 'ok'] + $this->selfService->forUser($uid));
    }

    /**
     * Machine-readable copy of the own record (data portability).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function export() {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['status' => 'error', 'message' => 'Authentication required'], 401);
        }
        $data = $this->selfService->forUser($uid);
        if (!$data['linked']) {
            return new JSONResponse(['status' => 'error', 'message' => 'Kein Mitglied mit Ihrem Konto verknüpft'], 404);
        }
        $data['exportedAt'] = date('c');

        return new DataDownloadResponse(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'meine-mitgliedsdaten.json',
            'application/json'
        );
    }
}
