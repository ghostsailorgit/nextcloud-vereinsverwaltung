<?php
namespace OCA\Verein\Controller;

use OCA\Verein\Attributes\RequirePermission;
use OCA\Verein\Db\AuditLogMapper;
use OCA\Verein\Service\AuditLogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read-only view of the change log (see AuditLogService). Club-scoped like
 * everything else here - entries not tied to a club (e.g. global role
 * definitions) are not exposed through this endpoint.
 */
class AuditLogController extends Controller {
    use RespondsWithErrors;

    /** Entries per page; one more is fetched to know whether older ones exist. */
    public const PAGE_SIZE = 100;

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
        try {
            $clubId = (int)$this->request->getParam('clubId', 0);
            $entityType = $this->request->getParam('entityType');
            $entityId = $this->request->getParam('entityId');
            $beforeId = $this->request->getParam('beforeId');
            $entries = $this->mapper->findByClub(
                $clubId,
                $entityType !== null && $entityType !== '' ? (string)$entityType : null,
                $entityId !== null && $entityId !== '' ? (int)$entityId : null,
                self::PAGE_SIZE + 1,
                $beforeId !== null && $beforeId !== '' ? (int)$beforeId : null
            );
            $hasMore = count($entries) > self::PAGE_SIZE;
            return new JSONResponse([
                'status' => 'ok',
                'data' => array_map(fn ($e) => $e->jsonSerialize(), array_slice($entries, 0, self::PAGE_SIZE)),
                'hasMore' => $hasMore,
                // entity type => fields logged in plain text; every other field of these types is redacted
                'safeFields' => AuditLogService::safeFields(),
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }
}
