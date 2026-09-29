<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Controller;

use Exception;
use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Service\MemberService;
use OCA\Verein\Service\RBAC\RoleService;
use OCA\Verein\Service\SelfServiceService;
use OCA\Verein\Service\ValidationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

/**
 * All endpoints work in the context of one club (`clubId` request
 * parameter, checked against the user's permissions by
 * AuthorizationMiddleware before any method here runs).
 */
class MemberController extends Controller {
    use RespondsWithErrors;

    private IL10N $l;

    private MemberService $memberService;
    private ValidationService $validationService;

    public function __construct(
        $AppName,
        IRequest $request,
        MemberService $memberService,
        ValidationService $validationService,
        private RoleService $roleService,
        private ClubMapper $clubMapper,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private SelfServiceService $selfService,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
        parent::__construct($AppName, $request);
        $this->memberService = $memberService;
        $this->validationService = $validationService;
    }

    /**
     * A member's role and linked Nextcloud account decide which rights they
     * get automatically (see RoleService::derivedRoles()), so changing them
     * needs the role-management permission - otherwise someone who may only
     * edit member data could make themselves (or anyone) a board member.
     */
    private function canManageRoles(): bool {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        return $this->roleService->userHasPermission($uid, 'verein.role.manage', $this->clubId());
    }

    private function clubId(): int {
        return (int)$this->request->getParam('clubId', 0);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.view')]
    public function index() {
        try {
            $q = (string)$this->request->getParam('query', '');
            if ($q !== '') {
                $members = $this->memberService->search($this->clubId(), $q);
            } else {
                $members = $this->memberService->findAll($this->clubId());
            }
            return new JSONResponse([
                'status' => 'ok',
                'members' => array_map(fn ($m) => $this->present($m), $members)
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Nextcloud accounts to pick from when linking a member to an account,
     * with the person each one is already linked to (if any).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function searchUsers() {
        $q = trim((string)$this->request->getParam('query', ''));
        if (mb_strlen($q) < 2) {
            return new JSONResponse(['status' => 'ok', 'users' => []]);
        }
        $users = [];
        foreach ($this->userManager->searchDisplayName($q, 10) as $user) {
            $linked = $this->memberService->findByLinkedUser($user->getUID());
            $users[] = [
                'id' => $user->getUID(),
                'user' => $user->getUID(),
                'displayName' => $user->getDisplayName(),
                'subname' => $user->getUID(),
                'linkedTo' => $linked !== null ? $linked->getFullName() : null,
                'linkedToId' => $linked?->getId(),
            ];
        }
        return new JSONResponse(['status' => 'ok', 'users' => $users]);
    }

    /**
     * Persons that exist in other clubs and could be added to this one.
     * Only clubs where the current user may manage members are searched -
     * i.e. someone can only pull in a person they are entitled to see
     * because they are on the board of that other club too.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function lookup() {
        try {
            $q = (string)$this->request->getParam('query', '');
            $visible = $this->visibleClubIds();
            $members = $this->memberService->lookupInOtherClubs($this->clubId(), $q, $visible);
            return new JSONResponse([
                'status' => 'ok',
                // Deliberately just enough to recognise the person
                'members' => array_map(static fn ($m) => [
                    'id' => $m->getId(),
                    'fullName' => $m->getFullName(),
                    'birthDate' => $m->getBirthDate(),
                    'city' => $m->getCity(),
                ], $members)
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Adds an existing person (from another club) to this club.
     *
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function attach() {
        try {
            $memberId = (int)$this->request->getParam('memberId', 0);
            if ($memberId <= 0) {
                return new JSONResponse(['status' => 'error', 'message' => $this->l->t('memberId is required')], 400);
            }
            if (!$this->memberService->isMemberOfAny($memberId, $this->visibleClubIds())) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => $this->l->t('This person does not belong to any club in which you may manage members')
                ], 403);
            }

            $data = $this->readMemberParams();
            $data['role'] = 'member';
            $data['userId'] = null;
            $validation = $this->validationService->validateMember(array_merge($data, ['name' => 'Platzhalter', 'email' => 'platzhalter@example.com']));
            if (!$validation['valid']) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => $this->l->t('Validation failed'),
                    'errors' => $validation['errors']
                ], 400);
            }
            if (!$this->validationService->validateRole($data['role'])) {
                return new JSONResponse(['status' => 'error', 'message' => $this->l->t('Invalid role')], 400);
            }

            $member = $this->memberService->attachExisting($this->clubId(), $memberId, $data);
            return new JSONResponse(['status' => 'ok', 'data' => $member], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.view')]
    public function show(int $id) {
        try {
            $member = $this->memberService->find($this->clubId(), $id);
            return new JSONResponse([
                'status' => 'ok',
                'data' => $this->present($member)
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function create() {
        try {
            $data = $this->readMemberParams();
            if (!$this->canManageRoles()) {
                $data['role'] = 'member';
                $data['userId'] = null;
            }

            // Validierung
            $validation = $this->validationService->validateMember($data);
            if (!$validation['valid']) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => $this->l->t('Validation failed'),
                    'errors' => $validation['errors']
                ], 400);
            }

            // Rolle validieren
            if (!$this->validationService->validateRole($data['role'])) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => $this->l->t('Invalid role'),
                    'errors' => [$this->l->t('Position must be member, treasurer or board')]
                ], 400);
            }

            $member = $this->memberService->create($this->clubId(), $data);
            return new JSONResponse([
                'status' => 'ok',
                'data' => $this->present($member)
            ], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Reads all member fields from the request into the array shape
     * expected by ValidationService::validateMember() / MemberService.
     */
    private function readMemberParams(): array {
        return [
            'name' => (string)$this->request->getParam('name', ''),
            'firstName' => $this->request->getParam('firstName'),
            'salutation' => $this->request->getParam('salutation'),
            'address' => $this->request->getParam('address'),
            'street' => $this->request->getParam('street'),
            'postalCode' => $this->request->getParam('postalCode'),
            'city' => $this->request->getParam('city'),
            'email' => (string)$this->request->getParam('email', ''),
            'iban' => $this->request->getParam('iban'),
            'bic' => $this->request->getParam('bic'),
            'role' => (string)$this->request->getParam('role', 'member'),
            'birthDate' => $this->request->getParam('birthDate'),
            'joinDate' => $this->request->getParam('joinDate'),
            'leaveDate' => $this->request->getParam('leaveDate'),
            'foundingMember' => $this->request->getParam('foundingMember', false),
            'deceased' => $this->request->getParam('deceased', false),
            'mandateReference' => $this->request->getParam('mandateReference'),
            'mandateDate' => $this->request->getParam('mandateDate'),
            'mandateFile' => $this->request->getParam('mandateFile'),
            'userId' => $this->request->getParam('userId'),
            'feeRateId' => $this->request->getParam('feeRateId'),
        ];
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function update($id) {
        try {
            $data = $this->readMemberParams();
            if (!$this->canManageRoles()) {
                // keep the current role and account link untouched
                $data['role'] = $this->memberService->find($this->clubId(), (int)$id)->getRole();
                $data['userId'] = null;
            }

            // Validierung
            $validation = $this->validationService->validateMember($data);
            if (!$validation['valid']) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => $this->l->t('Validation failed'),
                    'errors' => $validation['errors']
                ], 400);
            }

            // Rolle validieren wenn angegeben
            if ($data['role'] && !$this->validationService->validateRole($data['role'])) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => $this->l->t('Invalid role'),
                    'errors' => [$this->l->t('Position must be member, treasurer or board')]
                ], 400);
            }

            $member = $this->memberService->update($this->clubId(), (int)$id, $data);
            return new JSONResponse([
                'status' => 'ok',
                'data' => $this->present($member)
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Removes the member from this club (and deletes the person entirely if
     * no other club has them).
     *
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function destroy($id) {
        try {
            $this->memberService->remove($this->clubId(), (int)$id);
            return new JSONResponse([
                'status' => 'ok',
                'message' => $this->l->t('Member deleted')
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Deactivating stops payments and birthday reminders and suspends the
     * automatic rights derived from the membership (RoleService::derivedRoles()).
     * Like changing a member's role or account link it can take rights away and
     * give them back, so it needs the same permission (see canManageRoles()) -
     * not just 'verein.member.manage'.
     *
     * Deliberately no @NoCSRFRequired: it changes data (RoutePermissionsTest).
     *
     * @NoAdminRequired
     */
    #[RequirePermission('verein.role.manage')]
    public function deactivate($id) {
        try {
            $member = $this->memberService->deactivate($this->clubId(), (int)$id);
            return new JSONResponse(['status' => 'ok', 'data' => $this->present($member)]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.role.manage')]
    public function activate($id) {
        try {
            $member = $this->memberService->activate($this->clubId(), (int)$id);
            return new JSONResponse(['status' => 'ok', 'data' => $this->present($member)]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Replaces the person's personal data with placeholders (see MemberService::anonymize()).
     * Same permission as deactivate()/activate(): it touches data across every club the person
     * is (or was) a member of, not just this one - but the person must be a member of *this* club
     * (find() throws 404 otherwise), so holding 'verein.role.manage' in one club is not enough to
     * anonymize a person who only ever belonged to a different one.
     *
     * Deliberately no @NoCSRFRequired: it changes data (RoutePermissionsTest).
     *
     * @NoAdminRequired
     */
    #[RequirePermission('verein.role.manage')]
    public function anonymize($id) {
        try {
            $this->memberService->find($this->clubId(), (int)$id);
            $member = $this->memberService->anonymize((int)$id);
            return new JSONResponse(['status' => 'ok', 'data' => $this->present($member)]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * A person's full record as a downloadable file, restricted to this club's membership and fees,
     * for an administrator to answer a data-access request (Art. 15 GDPR) for someone who cannot (or
     * no longer can) use the self-service export themselves. Same permission as show()/index() - it
     * exposes nothing that verein.member.view does not already show on screen. find() first confirms
     * the person is actually a member of this club; without it, holding verein.member.view in one
     * club would let someone pull the full record - every club, every fee - of a person who only
     * belongs to a different one.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.view')]
    public function export($id) {
        try {
            $this->memberService->find($this->clubId(), (int)$id);
            $data = $this->selfService->forMemberId((int)$id, $this->clubId());
            $data['status'] = 'ok';
            $data['exportedAt'] = date('c');
            return new DataDownloadResponse(
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $this->l->t('member-data-%s.json', [(int)$id]),
                'application/json'
            );
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * The member as JSON plus the display name of the linked Nextcloud
     * account (falls back to the uid if that account no longer exists).
     */
    private function present($member): array {
        $data = $member->jsonSerialize();
        $uid = $member->getUserId();
        if ($uid !== null && $uid !== '') {
            $user = $this->userManager->get($uid);
            $data['userDisplayName'] = $user !== null ? $user->getDisplayName() : $uid;
            $data['userExists'] = $user !== null;
        } else {
            $data['userDisplayName'] = null;
            $data['userExists'] = false;
        }
        return $data;
    }

    /**
     * Clubs whose members the current user may manage.
     *
     * @return int[]
     */
    private function visibleClubIds(): array {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        $ids = $this->roleService->getClubIdsWithPermission($uid, 'verein.member.manage');
        if ($ids === null) {
            return array_map(static fn ($c) => $c->getId(), $this->clubMapper->findAll());
        }
        return $ids;
    }
}
