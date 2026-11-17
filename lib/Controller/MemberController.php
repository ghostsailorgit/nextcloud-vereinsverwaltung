<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Controller;

use Exception;
use OCA\Verein\Attributes\RequirePermission;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Service\ClubGroupService;
use OCA\Verein\Service\MemberService;
use OCA\Verein\Service\RBAC\RoleService;
use OCA\Verein\Service\SelfServiceService;
use OCA\Verein\Service\ValidationService;
use OCA\Verein\Service\WelcomeMailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCA\Verein\Service\CustomFieldService;
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
        ?IL10N $l10n = null,
        private ?ClubGroupService $clubGroups = null,
        private ?WelcomeMailService $welcomeMail = null,
        private ?CustomFieldService $customFields = null,
        private ?\OCA\Verein\Service\MemberScope $scope = null
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

    /** @var array{visible: int[]|null, editable: int[]|null}|null */
    private ?array $customAccess = null;

    /**
     * Which own fields the user sees and may change (sections with their own roles, CustomFieldService::access()).
     *
     * @return array{visible: int[]|null, editable: int[]|null}
     */
    private function customAccess(): array {
        if ($this->customAccess === null) {
            $uid = $this->userSession->getUser()?->getUID() ?? '';
            $this->customAccess = $this->customFields?->access($this->clubId(), $this->roleService->sectionRoleIds($uid, $this->clubId()))
                ?? ['visible' => [], 'editable' => []];
        }
        return $this->customAccess;
    }

    /**
     * A department head (role limited to groups, see MemberScope) reaches only the members of their groups:
     * anyone else is "not found".
     *
     * @throws \OCA\Verein\Exception\NotFoundException
     */
    private function checkScope(int $memberId, string $permission = 'verein.member.view'): void {
        $this->scope?->check($this->clubId(), $memberId, $permission);
    }

    /** @throws \OCA\Verein\Exception\PermissionDeniedException */
    private function requireWholeClub(): void {
        $this->scope?->requireWholeClub($this->clubId());
    }

    /** Fields that decide who gets rights automatically (RoleService::derivedRoles()). */
    private const RIGHTS_FIELDS = ['leaveDate', 'leaveUnknown', 'deceased'];
    /** Personal data stored once per person and used by every club the person belongs to. */
    private const SHARED_FIELDS = ['iban', 'bic', 'email', 'deceased'];
    /** What a member pays: not for department heads (members of some groups only). */
    private const FEE_FIELDS = ['feeRateId', 'honoraryMember', 'honoraryPaysFee', 'feeExemptJoinYear', 'payerMemberId'];
    /** Bank details and mandate: department heads need verein.member.bank for them. */
    private const BANK_FIELDS = ['iban', 'bic', 'mandateReference', 'mandateDate', 'mandateFile', 'mandateOnPaper'];

    private function uid(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }

    /** Whether the user reaches this person with the permission in that club (whole club, or one of their groups). */
    private function reaches(int $clubId, int $memberId, string $permission): bool {
        if (!$this->roleService->userHasPermission($this->uid(), $permission, $clubId)) {
            return false;
        }
        return $this->scope === null || $this->scope->covers($clubId, $memberId, $permission);
    }

    /**
     * The member fields the user may not change for this person (null = a new member). A locked field keeps its
     * stored value (a new member: the default), whatever the request sends; the form shows it read-only.
     *
     * - role: only with verein.role.manage (rule 3).
     * - userId: verein.role.manage in this club and in every other club of the person - a linked account gets the
     *   rights the person has in each of them (RoleService::derivedRoles()).
     * - leave date, "date unknown", deceased: they end automatic rights too, so with a linked account only with
     *   verein.role.manage.
     * - IBAN/BIC, email, deceased: one record for every club of the person, so only with verein.member.manage in
     *   each of them.
     * - fee fields: never for department heads; bank details and mandate only with verein.member.bank.
     *
     * @return string[]
     */
    private function lockedFields(?\OCA\Verein\Db\Member $member): array {
        $clubId = $this->clubId();
        $uid = $this->uid();
        $locked = [];
        $mayManageRoles = $this->canManageRoles();
        if (!$mayManageRoles) {
            $locked[] = 'role';
            $locked[] = 'userId';
        }
        $otherClubs = $member === null ? [] : array_values(array_diff($this->memberService->clubIdsOf((int)$member->getId()), [$clubId]));
        if ($mayManageRoles) {
            foreach ($otherClubs as $other) {
                if (!$this->roleService->userHasPermission($uid, 'verein.role.manage', $other)) {
                    $locked[] = 'userId';
                    break;
                }
            }
        }
        if ($member !== null && !$mayManageRoles && (string)$member->getUserId() !== '') {
            array_push($locked, ...self::RIGHTS_FIELDS);
        }
        foreach ($otherClubs as $other) {
            if (!$this->reaches($other, (int)$member->getId(), 'verein.member.manage')) {
                array_push($locked, ...self::SHARED_FIELDS);
                break;
            }
        }
        if ($this->scope?->groups($clubId, 'verein.member.manage') !== null) {
            array_push($locked, ...self::FEE_FIELDS);
            $bank = $member === null
                ? $this->roleService->userHasPermission($uid, 'verein.member.bank', $clubId)
                : $this->reaches($clubId, (int)$member->getId(), 'verein.member.bank');
            if (!$bank) {
                array_push($locked, ...self::BANK_FIELDS);
            }
        }
        return array_values(array_unique($locked));
    }

    /**
     * Replaces locked fields in the submitted data with the stored values (a new member: the defaults).
     *
     * @param string[] $locked
     */
    private function keepLocked(array $data, array $locked, ?\OCA\Verein\Db\Member $member): array {
        $stored = $member?->jsonSerialize() ?? [];
        foreach ($locked as $field) {
            $data[$field] = match ($field) {
                // null = "leave as it is" for these (MemberService::applyUserLink()/applyFeeRate()/applyPayer())
                'userId', 'feeRateId', 'payerMemberId' => null,
                'role' => $stored['role'] ?? 'member',
                default => $stored[$field] ?? (in_array($field, ['leaveUnknown', 'deceased', 'honoraryMember', 'honoraryPaysFee', 'feeExemptJoinYear', 'mandateOnPaper'], true) ? false : null),
            };
        }
        return $data;
    }

    /**
     * The member who pays someone's fee must be one the user reaches too.
     *
     * @throws \OCA\Verein\Exception\NotFoundException
     */
    private function checkPayerScope(array $data): void {
        $payer = (int)($data['payerMemberId'] ?? 0);
        if ($payer > 0) {
            $this->checkScope($payer, 'verein.member.manage');
        }
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
            $members = $this->scope?->filter($this->clubId(), $members) ?? $members;
            $groups = $this->clubGroups?->currentGroupNames($this->clubId()) ?? [];
            // one query for the whole club, not one per member
            $custom = $this->customFields?->valuesForClub($this->clubId(), $this->customAccess()['visible']) ?? [];
            // whom the user may edit, where that is fewer than they see (member permissions limited to some groups)
            $editable = $this->scope?->memberIds($this->clubId(), 'verein.member.manage');
            return new JSONResponse([
                'status' => 'ok',
                'members' => array_map(fn ($m) => $this->present($m, $custom[$m->getId()] ?? [], false) + [
                    'groups' => $groups[$m->getId()] ?? [],
                    'editable' => $editable === null || in_array((int)$m->getId(), $editable, true),
                ], $members)
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
        try {
            $this->requireWholeClub();
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
        $q = trim((string)$this->request->getParam('query', ''));
        if (mb_strlen($q) < 2) {
            return new JSONResponse(['status' => 'ok', 'users' => []]);
        }
        $users = [];
        foreach ($this->userManager->searchDisplayName($q, 10) as $user) {
            $linked = $this->memberService->findByLinkedUser($user->getUID());
            // who the account belongs to is only named for persons of this club; otherwise it is just "taken"
            $here = $linked !== null && in_array($this->clubId(), $this->memberService->clubIdsOf($linked->getId()), true);
            $users[] = [
                'id' => $user->getUID(),
                'user' => $user->getUID(),
                'displayName' => $user->getDisplayName(),
                'subname' => $user->getUID(),
                'linked' => $linked !== null,
                'linkedTo' => $here ? $linked->getFullName() : null,
                'linkedToId' => $here ? $linked->getId() : null,
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
            $this->requireWholeClub();
            $q = (string)$this->request->getParam('query', '');
            [$visible, $alsoMembers] = $this->reachableElsewhere();
            $members = $this->memberService->lookupInOtherClubs($this->clubId(), $q, $visible, $alsoMembers);
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
            $this->requireWholeClub();
            $memberId = (int)$this->request->getParam('memberId', 0);
            if ($memberId <= 0) {
                return new JSONResponse(['status' => 'error', 'message' => $this->l->t('memberId is required')], 400);
            }
            [$visible, $alsoMembers] = $this->reachableElsewhere();
            if (!$this->memberService->isMemberOfAny($memberId, $visible) && !in_array($memberId, $alsoMembers, true)) {
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
            $this->checkScope($id);
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
            $data = $this->keepLocked($this->readMemberParams(), $this->lockedFields(null), null);

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

            // the own fields are checked before anything is written
            $custom = $this->readCustomFields(true);
            $this->checkPayerScope($data);
            // a department head adds new members to one of their groups, or they would vanish from their view at once
            $groupId = $this->scopedGroupForNewMember();
            $member = $this->memberService->create($this->clubId(), $data);
            $this->customFields?->saveValues($this->clubId(), $member->getId(), $custom);
            if ($groupId !== null) {
                $joined = (string)($data['joinDate'] ?? '');
                $this->clubGroups?->addMember($this->clubId(), $groupId, $member->getId(), preg_match('/^\d{4}-\d{2}-\d{2}$/', $joined) ? $joined : date('Y-m-d'));
            }
            return new JSONResponse([
                'status' => 'ok',
                'data' => $this->present($member)
            ], 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * For someone who may manage members only in some groups: the group (form field groupId) a new member joins -
     * required, and one of theirs. Null for everyone else.
     *
     * @throws \OCA\Verein\Exception\ValidationException
     */
    private function scopedGroupForNewMember(): ?int {
        $groups = $this->scope?->groups($this->clubId(), 'verein.member.manage');
        if ($groups === null) {
            return null;
        }
        $groupId = (int)$this->request->getParam('groupId', 0);
        if (!in_array($groupId, $groups, true)) {
            throw new \OCA\Verein\Exception\ValidationException($this->l->t('Please choose one of your groups for the new member'));
        }
        return $groupId;
    }

    /**
     * The submitted values of the club's own fields, checked; nothing sent = nothing changes.
     *
     * @return array<int, ?string>
     * @throws \OCA\Verein\Exception\ValidationException
     */
    private function readCustomFields(bool $creating): array {
        $raw = $this->request->getParam('customFields');
        if ($this->customFields === null || ($raw === null && !$creating)) {
            return [];
        }
        return $this->customFields->validateValues($this->clubId(), $raw, $creating, $this->customAccess()['editable']);
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
            'phone' => $this->request->getParam('phone'),
            'gender' => $this->request->getParam('gender'),
            'iban' => $this->request->getParam('iban'),
            'bic' => $this->request->getParam('bic'),
            'role' => (string)$this->request->getParam('role', 'member'),
            'birthDate' => $this->request->getParam('birthDate'),
            'joinDate' => $this->request->getParam('joinDate'),
            'leaveDate' => $this->request->getParam('leaveDate'),
            'leaveUnknown' => $this->request->getParam('leaveUnknown', false),
            'foundingMember' => $this->request->getParam('foundingMember', false),
            'feeExemptJoinYear' => $this->request->getParam('feeExemptJoinYear', false),
            'honoraryMember' => $this->request->getParam('honoraryMember', false),
            'honoraryPaysFee' => $this->request->getParam('honoraryPaysFee', false),
            'mandateOnPaper' => $this->request->getParam('mandateOnPaper', false),
            'payerMemberId' => $this->request->getParam('payerMemberId'),
            'deceased' => $this->request->getParam('deceased', false),
            'mandateReference' => $this->request->getParam('mandateReference'),
            'memberNumber' => $this->request->getParam('memberNumber'),
            'office' => $this->request->getParam('office'),
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
            $this->checkScope((int)$id, 'verein.member.manage');
            $current = $this->memberService->find($this->clubId(), (int)$id);
            $locked = $this->lockedFields($current);
            // a new account link the user may not set is refused, not silently dropped
            $wantedUser = $this->request->getParam('userId');
            if (in_array('userId', $locked, true) && $this->canManageRoles() && $wantedUser !== null
                && trim((string)$wantedUser) !== (string)$current->getUserId()) {
                throw new \OCA\Verein\Exception\PermissionDeniedException($this->l->t('The person also belongs to a club in which you may not manage roles, so only someone who may do that there can link an account'));
            }
            $data = $this->keepLocked($this->readMemberParams(), $locked, $current);

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

            $custom = $this->readCustomFields(false);
            $this->checkPayerScope($data);
            $member = $this->memberService->update($this->clubId(), (int)$id, $data);
            $this->customFields?->saveValues($this->clubId(), $member->getId(), $custom);
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
            $this->checkScope((int)$id, 'verein.member.manage');
            $this->memberService->remove($this->clubId(), (int)$id);
            // the club's own fields belong to the membership
            $this->customFields?->deleteForMember($this->clubId(), (int)$id);
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
            $this->checkScope((int)$id);
            $member = $this->memberService->deactivate($this->clubId(), (int)$id);
            return new JSONResponse(['status' => 'ok', 'data' => $this->present($member)]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * The membership confirmation as a PDF letter, e.g. to print or hand over. A file download, so a GET.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function welcomePdf($id) {
        try {
            $this->checkScope((int)$id, 'verein.member.manage');
            $file = $this->welcomeMail->pdf($this->clubId(), (int)$id, $this->customAccess()['visible']);
            return new DataDownloadResponse($file['content'], $file['filename'], $file['mimeType']);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Sends the club's membership confirmation to this member. Only on request: creating a member sends nothing by itself.
     *
     * @NoAdminRequired
     */
    #[RequirePermission('verein.member.manage')]
    #[UserRateLimit(limit: 60, period: 3600)]
    public function sendWelcome($id) {
        try {
            $this->checkScope((int)$id, 'verein.member.manage');
            $this->welcomeMail->send($this->clubId(), (int)$id, $this->customAccess()['visible']);
            return new JSONResponse(['status' => 'ok']);
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
            $this->checkScope((int)$id);
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
            $this->checkScope((int)$id);
            $member = $this->memberService->anonymize((int)$id);
            $this->customFields?->deleteForPerson((int)$id);
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
            $this->checkScope((int)$id);
            // viewing members does not include seeing what they paid
            $uid = $this->userSession->getUser()?->getUID() ?? '';
            $mayReadFinances = $this->roleService->userHasPermission($uid, 'verein.finance.read', $this->clubId());
            // own fields only as far as the exporting user sees them
            $data = $this->selfService->forMemberId((int)$id, $this->clubId(), $mayReadFinances, $this->customAccess()['visible']);
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
    private function present($member, ?array $custom = null, bool $withLocks = true): array {
        $data = $member->jsonSerialize();
        // the club's own fields, field id => value (an object in JSON, also when empty)
        $data['custom'] = (object)($custom ?? $this->customFields?->valuesFor($this->clubId(), $member->getId(), $this->customAccess()['visible']) ?? []);
        // which fields the form shows read-only for this user (lockedFields()); computed per member only where cheap
        if ($withLocks) {
            $data['lockedFields'] = $this->lockedFields($member);
        }
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
     * Whom the user may look up in other clubs and take over: every member of the clubs where they manage members
     * as a whole, and in a club where they manage only some groups (a department head) just the members of those
     * groups - such a limited permission must not open the whole club.
     *
     * @return array{0: int[], 1: int[]} [club ids searched as a whole, single member ids]
     */
    private function reachableElsewhere(): array {
        $uid = $this->uid();
        $ids = $this->roleService->getClubIdsWithPermission($uid, 'verein.member.manage');
        if ($ids === null) {
            return [array_map(static fn ($c) => $c->getId(), $this->clubMapper->findAll()), []];
        }
        $whole = [];
        $members = [];
        foreach ($ids as $clubId) {
            $scoped = $this->scope?->memberIds($clubId, 'verein.member.manage');
            if ($scoped === null) {
                $whole[] = $clubId;
            } else {
                array_push($members, ...$scoped);
            }
        }
        return [$whole, array_values(array_unique($members))];
    }
}
