<?php

namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\Verein\Service\StatisticsService;

class StatisticsController extends Controller {
    use RespondsWithErrors;

    private StatisticsService $statisticsService;

    public function __construct(
        string $appName,
        IRequest $request,
        StatisticsService $statisticsService
    ) {
        parent::__construct($appName, $request);
        $this->statisticsService = $statisticsService;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.member.view')]
    public function getMemberStatistics(int $clubId): JSONResponse {
        try {
            $stats = $this->statisticsService->getMemberStatistics($clubId);
            return new JSONResponse([
                'status' => 'ok',
                'data' => $stats
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.finance.read')]
    public function getFeeStatistics(int $clubId): JSONResponse {
        try {
            $stats = $this->statisticsService->getFeeStatistics($clubId);
            return new JSONResponse([
                'status' => 'ok',
                'data' => $stats
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }
}
