<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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
     * type and/or one entity id. $beforeId pages backwards: only entries
     * older than that id (the last id of the previous page).
     *
     * @return AuditLogEntry[]
     */
    public function findByClub(int $clubId, ?string $entityType = null, ?int $entityId = null, int $limit = 200, ?int $beforeId = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit);

        if ($beforeId !== null) {
            $qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
        }

        if ($entityType !== null) {
            $qb->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)));
        }
        if ($entityId !== null) {
            $qb->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)));
        }

        return $this->findEntities($qb);
    }

    /**
     * Every entry of one entity, across all clubs and with no limit - used by
     * AuditLogService::scrubEntity() to redact history, not for display.
     *
     * @return AuditLogEntry[]
     */
    public function findAllForEntity(string $entityType, int $entityId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
            ->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }
}
