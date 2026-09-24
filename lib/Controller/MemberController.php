<?php
namespace OCA\Verein\Controller;

use Exception;
use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Service\MemberService;
use OCA\Verein\Service\RBAC\RoleService;
use OCA\Verein\Service\ValidationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * All endpoints work in the context of one club (`clubId` request
 * parameter, checked against the user's permissions by
 * AuthorizationMiddleware before any method here runs).
 */
class MemberController extends Controller {
    private MemberService $memberService;
    private ValidationService $validationService;

    public function __construct(
        $AppName,
        IRequest $request,
        MemberService $memberService,
        ValidationService $validationService,
        private RoleService $roleService,
        private ClubMapper $clubMapper,
        private IUserSession $userSession
    ) {
        parent::__construct($AppName, $request);
        $this->memberService = $memberService;
        $this->validationService = $validationService;
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
                'members' => $members
            ]);
        } catch (Exception $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
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
        } catch (Exception $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Adds an existing person (from another club) to this club.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function attach() {
        try {
            $memberId = (int)$this->request->getParam('memberId', 0);
            if ($memberId <= 0) {
                return new JSONResponse(['status' => 'error', 'message' => 'memberId erforderlich'], 400);
            }
            if (!$this->memberService->isMemberOfAny($memberId, $this->visibleClubIds())) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => 'Diese Person ist keinem Verein zugeordnet, in dem Sie Mitglieder verwalten dürfen'
                ], 403);
            }

            $data = $this->readMemberParams();
            $validation = $this->validationService->validateMember(array_merge($data, ['name' => 'Platzhalter', 'email' => 'platzhalter@example.com']));
            if (!$validation['valid']) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => 'Validierung fehlgeschlagen',
                    'errors' => $validation['errors']
                ], 400);
            }
            if (!$this->validationService->validateRole($data['role'])) {
                return new JSONResponse(['status' => 'error', 'message' => 'Ungültige Rolle'], 400);
            }

            $member = $this->memberService->attachExisting($this->clubId(), $memberId, $data);
            return new JSONResponse(['status' => 'ok', 'data' => $member], 201);
        } catch (Exception $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
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
                'data' => $member
            ]);
        } catch (Exception $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function create() {
        try {
            $data = $this->readMemberParams();

            // Validierung
            $validation = $this->validationService->validateMember($data);
            if (!$validation['valid']) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => 'Validierung fehlgeschlagen',
                    'errors' => $validation['errors']
                ], 400);
            }

            // Rolle validieren
            if (!$this->validationService->validateRole($data['role'])) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => 'Ungültige Rolle',
                    'errors' => ['Rolle muss Mitglied, Kassierer oder Admin sein']
                ], 400);
            }

            $member = $this->memberService->create($this->clubId(), $data);
            return new JSONResponse([
                'status' => 'ok',
                'data' => $member
            ], 201);
        } catch (Exception $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
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
        ];
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function update($id) {
        try {
            $data = $this->readMemberParams();

            // Validierung
            $validation = $this->validationService->validateMember($data);
            if (!$validation['valid']) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => 'Validierung fehlgeschlagen',
                    'errors' => $validation['errors']
                ], 400);
            }

            // Rolle validieren wenn angegeben
            if ($data['role'] && !$this->validationService->validateRole($data['role'])) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => 'Ungültige Rolle',
                    'errors' => ['Rolle muss Mitglied, Kassierer oder Admin sein']
                ], 400);
            }

            $member = $this->memberService->update($this->clubId(), (int)$id, $data);
            return new JSONResponse([
                'status' => 'ok',
                'data' => $member
            ]);
        } catch (Exception $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Removes the member from this club (and deletes the person entirely if
     * no other club has them).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function destroy($id) {
        try {
            $this->memberService->remove($this->clubId(), (int)$id);
            return new JSONResponse([
                'status' => 'ok',
                'message' => 'Mitglied gelöscht'
            ]);
        } catch (Exception $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 404);
        }
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
