<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Service\ClubGroupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Groups inside a club and who is in them. Reading needs the same permission as the member list, changes the one for editing members.
 */
class ClubGroupController extends Controller {
    use RespondsWithErrors;

    public function __construct(
        $AppName,
        IRequest $request,
        private ClubGroupService $groups
    ) {
        parent::__construct($AppName, $request);
    }

    private function clubId(): int {
        return (int)$this->request->getParam('clubId', 0);
    }

    private function groupParams(): array {
        return ['name' => $this->request->getParam('name'), 'description' => $this->request->getParam('description')];
    }

    private function respond(callable $action, int $status = 200): JSONResponse {
        try {
            return new JSONResponse(['status' => 'ok', 'data' => $action()], $status);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.view')]
    public function index(): JSONResponse {
        return $this->respond(fn () => $this->groups->list($this->clubId()));
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function create(): JSONResponse {
        return $this->respond(fn () => $this->groups->create($this->clubId(), $this->groupParams()), 201);
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function update(int $id): JSONResponse {
        return $this->respond(fn () => $this->groups->update($this->clubId(), $id, $this->groupParams()));
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function destroy(int $id): JSONResponse {
        return $this->respond(function () use ($id) {
            $this->groups->delete($this->clubId(), $id);
            return null;
        });
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.view')]
    public function members(int $id): JSONResponse {
        return $this->respond(fn () => $this->groups->periodsOfGroup($this->clubId(), $id));
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function addMember(int $id): JSONResponse {
        return $this->respond(fn () => $this->groups->addMember(
            $this->clubId(),
            $id,
            (int)$this->request->getParam('memberId', 0),
            $this->request->getParam('joinedOn'),
            $this->request->getParam('leftOn')
        ), 201);
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function updateMember(int $periodId): JSONResponse {
        return $this->respond(fn () => $this->groups->updatePeriod(
            $this->clubId(),
            $periodId,
            $this->request->getParam('joinedOn'),
            $this->request->getParam('leftOn')
        ));
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function removeMember(int $periodId): JSONResponse {
        return $this->respond(function () use ($periodId) {
            $this->groups->deletePeriod($this->clubId(), $periodId);
            return null;
        });
    }
}
