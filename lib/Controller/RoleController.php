<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Db\RoleMapper;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

class RoleController extends ApiController {
    use RespondsWithErrors;

    private IL10N $l;

    private RoleService $roleService;
    private RoleMapper $roleMapper;
    private IUserManager $userManager;

    public function __construct(
        string $appName,
        IRequest $request,
        RoleService $roleService,
        RoleMapper $roleMapper,
        IUserManager $userManager,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
        parent::__construct($appName, $request);
        $this->roleService = $roleService;
        $this->roleMapper = $roleMapper;
        $this->userManager = $userManager;
    }

    /**
     * Search real Nextcloud accounts for the "assign role" picker.
     * (Role assignments are keyed on the Nextcloud user ID, not on our
     * own Member records, since permission checks compare against
     * IUserSession::getUser()->getUID().)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.role.manage', clubScoped: false)]
    public function searchUsers(string $query = ''): JSONResponse {
        if (trim($query) === '') {
            return new JSONResponse([]);
        }

        $users = $this->userManager->search($query, 10);
        $result = array_map(static function ($user) {
            return [
                'id' => $user->getUID(),
                'user' => $user->getUID(),
                'displayName' => $user->getDisplayName(),
                'subname' => $user->getUID(),
            ];
        }, array_values($users));

        return new JSONResponse($result);
    }

    /**
     * Who holds a role in the current club.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.role.manage')]
    public function clubAssignments(): JSONResponse {
        $clubId = (int)$this->request->getParam('clubId', 0);
        $result = array_map(function (array $entry) {
            $user = $this->userManager->get($entry['userId']);
            $entry['displayName'] = $user !== null ? $user->getDisplayName() : $entry['userId'];
            return $entry;
        }, $this->roleService->getClubAssignments($clubId));
        return new JSONResponse($result);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.role.manage', clubScoped: false)]
    public function index(): JSONResponse {
        try {
            $roles = $this->roleMapper->findAll();
            return new JSONResponse(array_map(fn($r) => $r->jsonSerialize(), $roles));
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }
    
    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.role.manage', clubScoped: false)]
    
    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.role.manage', clubScoped: false)]
    public function show(int $id): JSONResponse {
        try {
            $role = $this->roleMapper->find($id);
            return new JSONResponse($role->jsonSerialize());
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }
    
    /**
     * The role definitions are shared by all clubs, so changing them is
     * reserved for Nextcloud administrators (no @NoAdminRequired) - a board
     * member of one club must not be able to change what another club's
     * roles allow.
     *
     */
    public function store(): JSONResponse {
        try {
            $name = $this->request->getParam('name');
            $clubType = $this->request->getParam('clubType') ?? 'music';
            $description = $this->request->getParam('description');
            $permissionInput = $this->request->getParam('permissions') ?? [];
            $permissions = is_array($permissionInput)
                ? $permissionInput
                : array_filter(array_map('trim', explode(',', (string)$permissionInput)));
            
            if (!$name) {
                return new JSONResponse(['status' => 'error', 'message' => $this->l->t('Name is required')], 400);
            }
            
            $role = $this->roleService->createRole($name, $clubType, $description, $permissions);
            return new JSONResponse($role->jsonSerialize(), 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }
    
    /**
     * The role definitions are shared by all clubs, so changing them is
     * reserved for Nextcloud administrators (no @NoAdminRequired) - a board
     * member of one club must not be able to change what another club's
     * roles allow.
     *
     */
    public function update(int $id): JSONResponse {
        try {
            $name = $this->request->getParam('name');
            $description = $this->request->getParam('description');
            $permissionInput = $this->request->getParam('permissions');
            $permissions = null;
            if ($permissionInput !== null) {
                $permissions = is_array($permissionInput)
                    ? $permissionInput
                    : array_filter(array_map('trim', explode(',', (string)$permissionInput)));
            }
            
            $role = $this->roleService->updateRole($id, $name, $description, $permissions);
            return new JSONResponse($role->jsonSerialize());
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }
    
    /**
     * The role definitions are shared by all clubs, so changing them is
     * reserved for Nextcloud administrators (no @NoAdminRequired) - a board
     * member of one club must not be able to change what another club's
     * roles allow.
     *
     */
    public function destroy(int $id): JSONResponse {
        try {
            $this->roleService->deleteRole($id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }
    
    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.role.manage')]
    public function getUserRoles(string $userId, int $clubId = 0): JSONResponse {
        try {
            $clubIdParam = $this->request->getParam('clubId');
            $clubIdToUse = $clubIdParam !== null ? (int)$clubIdParam : $clubId;
            $roles = $this->roleService->getUserRoles($userId, $clubIdToUse);
            return new JSONResponse($roles);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }
    
    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.role.manage')]
    public function assignRole(): JSONResponse {
        try {
            $userId = $this->request->getParam('userId');
            $roleId = (int)$this->request->getParam('roleId');
            $clubId = (int)($this->request->getParam('clubId') ?? 0);
            
            if (!$userId || !$roleId || $clubId <= 0) {
                return new JSONResponse(['status' => 'error', 'message' => $this->l->t('userId and roleId are required')], 400);
            }
            
            // rights must not be pre-assigned to an account name that does not exist (yet)
            if (!$this->userManager->userExists((string)$userId)) {
                return new JSONResponse(['status' => 'error', 'message' => $this->l->t('The Nextcloud account does not exist')], 400);
            }

            $userRole = $this->roleService->assignRole($userId, $roleId, $clubId);
            return new JSONResponse($userRole->jsonSerialize(), 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }
    
    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.role.manage')]
    public function removeRoles(): JSONResponse {
        try {
            $userId = $this->request->getParam('userId');
            $clubId = (int)$this->request->getParam('clubId', 0);
            
            if (!$userId || $clubId <= 0) {
                return new JSONResponse(['status' => 'error', 'message' => $this->l->t('userId is required')], 400);
            }
            
            $this->roleService->removeUserRoles($userId, $clubId);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }
}
