<?php
namespace OCA\Verein\Controller;

use Exception;
use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Service\MemberService;
use OCA\Verein\Service\ValidationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class MemberController extends Controller {
    private MemberService $memberService;
    private ValidationService $validationService;

    public function __construct(
        $AppName,
        IRequest $request,
        MemberService $memberService,
        ValidationService $validationService
    ) {
        parent::__construct($AppName, $request);
        $this->memberService = $memberService;
        $this->validationService = $validationService;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index() {
        try {
            $q = (string)$this->request->getParam('query', '');
            if ($q !== '') {
                $members = $this->memberService->search($q);
            } else {
                $members = $this->memberService->findAll();
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
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.view')]
    public function show(int $id) {
        try {
            $member = $this->memberService->find($id);
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

            $member = $this->memberService->create($data);
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
            'memberNumber' => $this->request->getParam('memberNumber'),
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

            $member = $this->memberService->update((int)$id, $data);
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
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.manage')]
    public function destroy($id) {
        try {
            $this->memberService->delete($id);
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
}
