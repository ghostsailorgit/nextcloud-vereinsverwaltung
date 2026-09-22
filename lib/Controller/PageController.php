<?php
namespace OCA\Verein\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

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
        // Nextcloud's own core/theming CSS+JS normally get registered
        // automatically while apps boot, before any controller runs - but for
        // this app's route that registration doesn't happen on its own
        // (root cause not fully understood, see project notes), leaving the
        // page without core styling and without window.OC/_oc_webroot. Force
        // them explicitly as a working fix.
        Util::addStyle('core', 'server');
        Util::addStyle('theming', 'default');
        Util::addScript('core', 'common');
        Util::addScript('core', 'main');

        return new TemplateResponse('verein', 'main', [
            'id-app-content' => '#app-content',
            'id-app-navigation' => '#verein-navigation',
            'pageTitle' => 'Verein',
        ], TemplateResponse::RENDER_AS_USER);
    }
}

