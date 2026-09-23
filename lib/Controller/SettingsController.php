<?php

namespace OCA\Verein\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\Verein\Service\AppSettingsService;
use OCA\Verein\Service\MemberCalendarService;

class SettingsController extends Controller {
    private AppSettingsService $settingsService;
    private MemberCalendarService $calendarService;

    public function __construct(
        string $appName,
        IRequest $request,
        AppSettingsService $settingsService,
        MemberCalendarService $calendarService
    ) {
        parent::__construct($appName, $request);
        $this->settingsService = $settingsService;
        $this->calendarService = $calendarService;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getAppSettings(): JSONResponse {
        $data = $this->settingsService->getAppSettings();
        $data['calendar_owner_user'] = $this->calendarService->getOwnerUser();
        return new JSONResponse([
            'status' => 'ok',
            'data' => $data
        ]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function setCalendarOwner(string $user = ''): JSONResponse {
        $user = $this->request->getParam('user', $user);
        $this->calendarService->setOwnerUser($user);
        return new JSONResponse([
            'status' => 'ok',
            'data' => ['calendar_owner_user' => $this->calendarService->getOwnerUser()]
        ]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function setChartsEnabled(string $enabled = ''): JSONResponse {
        // Accept POST body or param
        $param = $this->request->getParam('enabled', $enabled);
        $flag = $param === '1' || $param === 'true';
        $this->settingsService->setChartsEnabled($flag);
        return new JSONResponse([
            'status' => 'ok',
            'data' => ['enable_charts' => $this->settingsService->isChartsEnabled()]
        ]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function setDocumentsPath(string $path = ''): JSONResponse {
        $path = $this->request->getParam('path', $path);
        $this->settingsService->setDocumentsPath($path);
        return new JSONResponse([
            'status' => 'ok',
            'data' => ['documents_path' => $this->settingsService->getDocumentsPath()]
        ]);
    }
}
