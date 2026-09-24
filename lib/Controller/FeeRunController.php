<?php
namespace OCA\Verein\Controller;

use Exception;
use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Service\FeeRunService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The annual fee run: preview (writes nothing) and the run itself.
 */
class FeeRunController extends Controller {
    public function __construct(
        $AppName,
        IRequest $request,
        private FeeRunService $feeRun
    ) {
        parent::__construct($AppName, $request);
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.finance.write')]
    public function preview(): JSONResponse {
        return $this->respond(fn () => $this->feeRun->plan(
            (int)$this->request->getParam('clubId', 0),
            (int)$this->request->getParam('year', 0),
            (string)$this->request->getParam('dueDate', ''),
            $this->request->getParam('description')
        ));
    }

    /**
     * @NoAdminRequired
     */
    #[RequirePermission('verein.finance.write')]
    public function run(): JSONResponse {
        return $this->respond(fn () => $this->feeRun->run(
            (int)$this->request->getParam('clubId', 0),
            (int)$this->request->getParam('year', 0),
            (string)$this->request->getParam('dueDate', ''),
            $this->request->getParam('description')
        ));
    }

    private function respond(callable $action): JSONResponse {
        try {
            return new JSONResponse(['status' => 'ok'] + $action());
        } catch (Exception $e) {
            return new JSONResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
