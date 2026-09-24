<?php
namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Db\AuditLogMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read-only view of the change log (see AuditLogService). Club-scoped like
 * everything else here - entries not tied to a club (e.g. global role
 * definitions) are not exposed through this endpoint.
 */
class AuditLogController extends Controller {
    public function __construct(
        $AppName,
        IRequest $request,
        private AuditLogMapper $mapper
    ) {
        parent::__construct($AppName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[RequirePermission('verein.audit.view')]
    public function index(): JSONResponse {
        $clubId = (int)$this->request->getParam('clubId', 0);
        $entityType = $this->request->getParam('entityType');
        $entityId = $this->request->getParam('entityId');
        $entries = $this->mapper->findByClub(
            $clubId,
            $entityType !== null && $entityType !== '' ? (string)$entityType : null,
            $entityId !== null && $entityId !== '' ? (int)$entityId : null
        );
        return new JSONResponse([
            'status' => 'ok',
            'data' => array_map(fn ($e) => $e->jsonSerialize(), $entries),
        ]);
    }
}
