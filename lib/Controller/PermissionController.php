<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class PermissionController extends ApiController {
    private RoleService $roleService;

    public function __construct(
        string $appName,
        IRequest $request,
        RoleService $roleService
    ) {
        parent::__construct($appName, $request);
        $this->roleService = $roleService;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.role.manage', clubScoped: false)]
    public function index(): DataResponse {
        return new DataResponse([
            'permissions' => $this->roleService->getAvailablePermissions(),
        ]);
    }
}
