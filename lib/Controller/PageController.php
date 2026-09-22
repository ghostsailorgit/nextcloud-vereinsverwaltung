<?php
namespace OCA\Verein\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class PageController extends Controller {
    public function __construct(
        string $AppName,
        IRequest $request
    ) {
        parent::__construct($AppName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        return new TemplateResponse('verein', 'main', [
            'id-app-content' => '#app-content',
            'id-app-navigation' => '#verein-navigation',
            'pageTitle' => 'Verein',
        ], TemplateResponse::RENDER_AS_USER);
    }
}

