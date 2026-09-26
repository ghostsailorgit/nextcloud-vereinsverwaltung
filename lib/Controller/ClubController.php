<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Controller;

use Exception;
use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Service\ClubService;
use OCA\Verein\Service\FeeRateService;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

/**
 * Clubs and their bank accounts. Creating and deleting a club is reserved
 * for Nextcloud administrators (no @NoAdminRequired); editing a club and
 * its accounts needs the 'verein.club.manage' permission in that club.
 */
class ClubController extends Controller {
    use RespondsWithErrors;

    private IL10N $l;

    public function __construct(
        $AppName,
        IRequest $request,
        private ClubService $clubService,
        private FeeRateService $feeRates,
        private RoleService $roleService,
        private IUserSession $userSession,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
        parent::__construct($AppName, $request);
    }

    /**
     * The clubs the current user can access, with their permissions in each.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        return new JSONResponse([
            'status' => 'ok',
            'isAdmin' => $this->roleService->isNextcloudAdmin($uid),
            'clubs' => $this->clubService->listForUser($uid),
        ]);
    }

    /**
     * Nextcloud administrators only.
     */
    public function create(): JSONResponse {
        return $this->guard(function () {
            $club = $this->clubService->create($this->clubParams());
            return new JSONResponse(['status' => 'ok', 'data' => $club], 201);
        });
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function update(int $clubId): JSONResponse {
        return $this->guard(function () use ($clubId) {
            $club = $this->clubService->update($clubId, $this->clubParams());
            return new JSONResponse(['status' => 'ok', 'data' => $club]);
        });
    }

    /**
     * Automatic rights: which app role a membership role (Mitglied /
     * Kassierer / Vorstand) gets. Needs the role-management permission,
     * since this decides who gets which rights.
     *
     * @NoAdminRequired
     */
    #[RequirePermission('verein.role.manage')]
    public function updateRoleMapping(int $clubId): JSONResponse {
        return $this->guard(function () use ($clubId) {
            $club = $this->clubService->setRoleMapping($clubId, [
                'member' => $this->request->getParam('member'),
                'treasurer' => $this->request->getParam('treasurer'),
                'admin' => $this->request->getParam('admin'),
            ]);
            return new JSONResponse(['status' => 'ok', 'data' => $club]);
        });
    }

    /**
     * Nextcloud administrators only.
     */
    public function destroy(int $clubId): JSONResponse {
        return $this->guard(function () use ($clubId) {
            $this->clubService->delete($clubId);
            return new JSONResponse(['status' => 'ok']);
        });
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function createAccount(int $clubId): JSONResponse {
        return $this->guard(function () use ($clubId) {
            $account = $this->clubService->createAccount($clubId, $this->accountParams());
            return new JSONResponse(['status' => 'ok', 'data' => $account], 201);
        });
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function updateAccount(int $clubId, int $accountId): JSONResponse {
        return $this->guard(function () use ($clubId, $accountId) {
            $account = $this->clubService->updateAccount($clubId, $accountId, $this->accountParams());
            return new JSONResponse(['status' => 'ok', 'data' => $account]);
        });
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function destroyAccount(int $clubId, int $accountId): JSONResponse {
        return $this->guard(function () use ($clubId, $accountId) {
            $this->clubService->deleteAccount($clubId, $accountId);
            return new JSONResponse(['status' => 'ok']);
        });
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function createFeeRate(int $clubId): JSONResponse {
        return $this->guard(function () use ($clubId) {
            $rate = $this->feeRates->create($clubId, $this->feeRateParams());
            return new JSONResponse(['status' => 'ok', 'data' => $rate], 201);
        });
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function updateFeeRate(int $clubId, int $rateId): JSONResponse {
        return $this->guard(function () use ($clubId, $rateId) {
            $rate = $this->feeRates->update($clubId, $rateId, $this->feeRateParams());
            return new JSONResponse(['status' => 'ok', 'data' => $rate]);
        });
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.club.manage')]
    public function destroyFeeRate(int $clubId, int $rateId): JSONResponse {
        return $this->guard(function () use ($clubId, $rateId) {
            $this->feeRates->delete($clubId, $rateId);
            return new JSONResponse(['status' => 'ok']);
        });
    }

    private function feeRateParams(): array {
        return [
            'name' => $this->request->getParam('name'),
            'amount' => $this->request->getParam('amount'),
            'isDefault' => $this->request->getParam('isDefault', false),
        ];
    }

    private function guard(callable $action): JSONResponse {
        try {
            return $action();
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['status' => 'error', 'message' => $this->l->t('Not found')], 404);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    private function clubParams(): array {
        return [
            'name' => $this->request->getParam('name'),
            'street' => $this->request->getParam('street'),
            'postalCode' => $this->request->getParam('postalCode'),
            'city' => $this->request->getParam('city'),
            'documentsPath' => $this->request->getParam('documentsPath'),
            'calendarGroups' => $this->request->getParam('calendarGroups', ''),
        ];
    }

    private function accountParams(): array {
        return [
            'label' => $this->request->getParam('label'),
            'iban' => $this->request->getParam('iban'),
            'bic' => $this->request->getParam('bic'),
            'creditorId' => $this->request->getParam('creditorId'),
            'isDefault' => $this->request->getParam('isDefault', false),
        ];
    }
}
