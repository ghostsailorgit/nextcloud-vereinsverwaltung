<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */

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
