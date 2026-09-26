<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Service\MemberImportService;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Member import from CSV: preview (writes nothing) and import of chosen lines. The CSV text is sent as a
 * form field (the browser reads the file and converts it to UTF-8), the same text again with every chunk.
 */
class MemberImportController extends Controller {
    use RespondsWithErrors;

    public function __construct(
        $AppName,
        IRequest $request,
        private MemberImportService $import,
        private RoleService $roleService,
        private IUserSession $userSession
    ) {
        parent::__construct($AppName, $request);
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function preview(): JSONResponse {
        try {
            $plan = $this->import->plan($this->clubId(), $this->csv(), $this->mayAssignRoles());
            // the parsed values stay on the server: the browser has the file anyway and needs only the verdict
            $plan['rows'] = array_map(function (array $row) {
                unset($row['data']);
                return $row;
            }, $plan['rows']);
            return new JSONResponse(['status' => 'ok'] + $plan);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function run(): JSONResponse {
        try {
            $lines = array_filter(array_map('intval', explode(',', (string)$this->request->getParam('lines', ''))));
            return new JSONResponse(['status' => 'ok'] + $this->import->import($this->clubId(), $this->csv(), $this->mayAssignRoles(), $lines));
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /** Only holders of verein.role.manage may import a role other than "member" (rule 3 in CLAUDE.md). */
    private function mayAssignRoles(): bool {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        return $this->roleService->userHasPermission($uid, 'verein.role.manage', $this->clubId());
    }

    private function csv(): string {
        return (string)$this->request->getParam('csv', '');
    }

    private function clubId(): int {
        return (int)$this->request->getParam('clubId', 0);
    }
}
