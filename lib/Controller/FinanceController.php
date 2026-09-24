<?php
namespace OCA\Verein\Controller;

use Exception;
use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Service\FeeService;
use OCA\Verein\Service\ValidationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class FinanceController extends Controller {
    private FeeService $feeService;
    private ValidationService $validationService;

    public function __construct(
        $AppName,
        IRequest $request,
        FeeService $feeService,
        ValidationService $validationService
    ) {
        parent::__construct($AppName, $request);
        $this->feeService = $feeService;
        $this->validationService = $validationService;
    }

    /** The club the request is about - authorization already checked by AuthorizationMiddleware. */
    private function clubId(): int {
        return (int)$this->request->getParam('clubId', 0);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.finance.read')]
    public function index() {
        try {
            $fees = $this->feeService->findAll($this->clubId());
            return new JSONResponse([
                'status' => 'ok',
                'fees' => $fees
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
    #[RequirePermission('verein.finance.write')]
    public function create() {
        try {
            $memberId = (int)$this->request->getParam('memberId');
            $amount = (float)$this->request->getParam('amount');
            $status = $this->request->getParam('status', 'open');
            $dueDate = (string)$this->request->getParam('dueDate', '');
            $description = $this->request->getParam('description');

            // Validierung
            $validation = $this->validationService->validateFee($memberId, $amount, $dueDate, $description);
            if (!$validation['valid']) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => 'Validierung fehlgeschlagen',
                    'errors' => $validation['errors']
                ], 400);
            }

            // Status validieren
            if (!$this->validationService->validateFeeStatus($status)) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => 'Ungültiger Status',
                    'errors' => ['Status muss open, paid, overdue oder cancelled sein']
                ], 400);
            }

            $fee = $this->feeService->create($this->clubId(), $memberId, $amount, $status, $dueDate, $description);
            return new JSONResponse([
                'status' => 'ok',
                'data' => $fee
            ], 201);
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
    #[RequirePermission('verein.finance.write')]
    public function update($id) {
        try {
            $memberId = (int)$this->request->getParam('memberId', 0);
            $amount = (float)$this->request->getParam('amount');
            $dueDate = (string)$this->request->getParam('dueDate', '');
            $description = $this->request->getParam('description');
            $status = $this->request->getParam('status', 'open');

            // Validierung Amount und Description
            $validation = $this->validationService->validateFee($memberId, $amount, $dueDate, $description);
            if (!$validation['valid']) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => 'Validierung fehlgeschlagen',
                    'errors' => $validation['errors']
                ], 400);
            }

            // Status validieren wenn angegeben
            if ($status && !$this->validationService->validateFeeStatus($status)) {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => 'Ungültiger Status',
                    'errors' => ['Status muss open, paid, overdue oder cancelled sein']
                ], 400);
            }

            $fee = $this->feeService->update($this->clubId(), (int)$id, $memberId, $amount, $status, $dueDate, $description);
            return new JSONResponse([
                'status' => 'ok',
                'data' => $fee
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
    #[RequirePermission('verein.finance.delete')]
    public function destroy($id) {
        try {
            $this->feeService->delete($this->clubId(), (int)$id);
            return new JSONResponse([
                'status' => 'ok',
                'message' => 'Gebühr gelöscht'
            ]);
        } catch (Exception $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 404);
        }
    }
}
