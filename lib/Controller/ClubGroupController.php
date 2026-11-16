<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Service\ClubGroupService;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\IUserSession;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Groups inside a club and who is in them. Reading needs the same permission as the member list, changes the one for editing members.
 * Someone whose member permissions are limited to some groups (MemberScope) sees only those groups and changes only
 * who is in them; defining groups needs the permission for the whole club.
 */
class ClubGroupController extends Controller {
    use RespondsWithErrors;

    public function __construct(
        $AppName,
        IRequest $request,
        private ClubGroupService $groups,
        private RoleService $roles,
        private IUserSession $userSession,
        private ?\OCA\Verein\Service\MemberScope $scope = null,
        private ?\OCP\IL10N $l = null
    ) {
        parent::__construct($AppName, $request);
    }

    private function clubId(): int {
        return (int)$this->request->getParam('clubId', 0);
    }

    private function groupParams(): array {
        return ['name' => $this->request->getParam('name'), 'description' => $this->request->getParam('description')] + $this->feeParam();
    }

    /** What members are charged: only for those who may change fee categories, whoever else sends it changes nothing. */
    private function feeParam(): array {
        $feeRateId = $this->request->getParam('feeRateId', false);
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        if ($feeRateId !== false && $this->roles->userHasPermission($uid, 'verein.club.manage', $this->clubId())) {
            return ['feeRateId' => $feeRateId];
        }
        return [];
    }

    /**
     * A group outside the user's groups is "not found".
     *
     * @throws \OCA\Verein\Exception\NotFoundException
     */
    private function checkGroup(int $groupId, string $permission): void {
        $groups = $this->scope?->groups($this->clubId(), $permission);
        if ($groups !== null && !in_array($groupId, $groups, true)) {
            throw new \OCA\Verein\Exception\NotFoundException($this->l10n()->t('Group not found'));
        }
    }

    private function l10n(): \OCP\IL10N {
        return $this->l ?? new \OCA\Verein\L10n\SourceL10n();
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
        return $this->respond(function () {
            $list = $this->groups->list($this->clubId());
            $groups = $this->scope?->groups($this->clubId(), 'verein.member.view');
            return $groups === null ? $list : array_values(array_filter($list, fn (array $g) => in_array((int)$g['id'], $groups, true)));
        });
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function create(): JSONResponse {
        return $this->respond(function () {
            $this->scope?->requireWholeClub($this->clubId());
            return $this->groups->create($this->clubId(), $this->groupParams());
        }, 201);
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function update(int $id): JSONResponse {
        return $this->respond(function () use ($id) {
            $this->scope?->requireWholeClub($this->clubId());
            return $this->groups->update($this->clubId(), $id, $this->groupParams());
        });
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function destroy(int $id): JSONResponse {
        return $this->respond(function () use ($id) {
            $this->scope?->requireWholeClub($this->clubId());
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
        return $this->respond(function () use ($id) {
            $this->checkGroup($id, 'verein.member.view');
            return $this->groups->periodsOfGroup($this->clubId(), $id);
        });
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function addMember(int $id): JSONResponse {
        return $this->respond(function () use ($id) {
            $memberId = (int)$this->request->getParam('memberId', 0);
            // only into one of their groups, and only someone they already reach
            $this->checkGroup($id, 'verein.member.manage');
            $this->scope?->check($this->clubId(), $memberId, 'verein.member.manage');
            return $this->groups->addMember($this->clubId(), $id, $memberId, $this->request->getParam('joinedOn'), $this->request->getParam('leftOn'));
        }, 201);
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function updateMember(int $periodId): JSONResponse {
        return $this->respond(fn () => $this->checkPeriod($periodId) ?? $this->groups->updatePeriod(
            $this->clubId(),
            $periodId,
            $this->request->getParam('joinedOn'),
            $this->request->getParam('leftOn'),
            $this->feeParam()
        ));
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function removeMember(int $periodId): JSONResponse {
        return $this->respond(function () use ($periodId) {
            $this->checkPeriod($periodId);
            $this->groups->deletePeriod($this->clubId(), $periodId);
            return null;
        });
    }

    /**
     * A stay in a group outside the user's groups is "not found". Returns null so it can precede an expression.
     *
     * @throws \OCA\Verein\Exception\NotFoundException
     */
    private function checkPeriod(int $periodId): mixed {
        if ($this->scope?->groups($this->clubId(), 'verein.member.manage') !== null) {
            $this->checkGroup($this->groups->groupOfPeriod($this->clubId(), $periodId), 'verein.member.manage');
        }
        return null;
    }
}
