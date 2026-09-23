<?php

namespace OCA\Verein\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\Verein\Service\AppSettingsService;

class SettingsController extends Controller {
    private AppSettingsService $settingsService;

    public function __construct(string $appName, IRequest $request, AppSettingsService $settingsService) {
        parent::__construct($appName, $request);
        $this->settingsService = $settingsService;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getAppSettings(): JSONResponse {
        return new JSONResponse([
            'status' => 'ok',
            'data' => $this->settingsService->getAppSettings()
        ]);
    }
}
