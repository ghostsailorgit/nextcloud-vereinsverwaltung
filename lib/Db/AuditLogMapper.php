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
     * Deletes entries created before $cutoff. With $types the deletion is limited to
     * those entity types ($include = true) or spares them ($include = false).
     *
     * @param string[]|null $types
     * @return int number of deleted entries
     */
    public function deleteBefore(string $cutoff, ?array $types = null, bool $include = true): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff)));
        if ($types !== null && $types !== []) {
            $in = $qb->createNamedParameter($types, IQueryBuilder::PARAM_STR_ARRAY);
            $qb->andWhere($include ? $qb->expr()->in('entity_type', $in) : $qb->expr()->notIn('entity_type', $in));
        }
        return $qb->executeStatement();
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
