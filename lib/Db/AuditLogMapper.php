<?php
namespace OCA\Verein\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class AuditLogMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'verein_audit_log', AuditLogEntry::class);
    }

    /**
     * Entries for one club, newest first, optionally narrowed to one entity
     * type and/or one entity id.
     *
     * @return AuditLogEntry[]
     */
    public function findByClub(int $clubId, ?string $entityType = null, ?int $entityId = null, int $limit = 200): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit);

        if ($entityType !== null) {
            $qb->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)));
        }
        if ($entityId !== null) {
            $qb->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)));
        }

        return $this->findEntities($qb);
    }
}
