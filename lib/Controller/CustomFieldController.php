<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Db\RoleMapper;
use OCA\Verein\Service\CustomFieldService;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The fields a club defines for its members and the sections they are grouped in. Reading needs the permission to
 * see members (the form and the list show them) and returns only the fields the user may see, each marked whether
 * they may change it; defining fields and sections needs the one to manage the club. The values are written through
 * the member endpoints.
 */
class CustomFieldController extends Controller {
    use RespondsWithErrors;

    public function __construct(
        $AppName,
        IRequest $request,
        private CustomFieldService $fields,
        private RoleService $roles,
        private IUserSession $userSession,
        private RoleMapper $roleDefinitions
    ) {
        parent::__construct($AppName, $request);
    }

    private function clubId(): int {
        return (int)$this->request->getParam('clubId', 0);
    }

    /** @param string[] $keys */
    private function params(array $keys): array {
        $out = [];
        foreach ($keys as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private function respond(callable $action, int $status = 200): JSONResponse {
        try {
            return new JSONResponse(['status' => 'ok', 'data' => $action()], $status);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * The fields the user sees, each with 'editable' and the name of its section.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.view')]
    public function index(): JSONResponse {
        return $this->respond(function () {
            $clubId = $this->clubId();
            $access = $this->fields->access($clubId, $this->roles->sectionRoleIds($this->userSession->getUser()?->getUID() ?? '', $clubId));
            $sectionNames = [];
            foreach ($this->fields->sections($clubId) as $section) {
                $sectionNames[$section->getId()] = $section->getName();
            }
            return array_map(fn ($f) => $f->jsonSerialize() + [
                'sectionName' => $f->getSectionId() !== null ? ($sectionNames[$f->getSectionId()] ?? null) : null,
                'editable' => $access['editable'] === null || in_array($f->getId(), $access['editable'], true),
            ], $this->fields->fields($clubId, $access['visible']));
        });
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function create(): JSONResponse {
        return $this->respond(fn () => $this->fields->create($this->clubId(), $this->params(['label', 'type', 'options', 'required', 'showInList', 'sortOrder', 'sectionId'])), 201);
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function update(int $id): JSONResponse {
        return $this->respond(fn () => $this->fields->update($this->clubId(), $id, $this->params(['label', 'type', 'options', 'required', 'showInList', 'sortOrder', 'sectionId'])));
    }

    /**
     * Removes the field together with every member's value of it.
     *
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function destroy(int $id): JSONResponse {
        return $this->respond(function () use ($id) {
            $this->fields->delete($this->clubId(), $id);
            return null;
        });
    }

    /**
     * The sections with their roles, and the roles there are to choose from (names only).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function sections(): JSONResponse {
        return $this->respond(fn () => [
            'sections' => array_map(fn ($s) => $s->jsonSerialize(), $this->fields->sections($this->clubId())),
            'roles' => array_map(fn ($r) => ['id' => $r->getId(), 'name' => $r->getName()], $this->roleDefinitions->findAll()),
        ]);
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function createSection(): JSONResponse {
        return $this->respond(fn () => $this->fields->createSection($this->clubId(), $this->params(['name', 'viewRoles', 'editRoles'])), 201);
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function updateSection(int $id): JSONResponse {
        return $this->respond(fn () => $this->fields->updateSection($this->clubId(), $id, $this->params(['name', 'viewRoles', 'editRoles', 'sortOrder'])));
    }

    /**
     * Only an empty section can be deleted.
     *
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function destroySection(int $id): JSONResponse {
        return $this->respond(function () use ($id) {
            $this->fields->deleteSection($this->clubId(), $id);
            return null;
        });
    }
}
