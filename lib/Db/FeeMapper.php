<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class FeeMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'verein_fees', Fee::class);
    }

    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());
        return $this->findEntities($qb);
    }

    public function find(int $id): Fee {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        return $this->findEntity($qb);
    }

    /**
     * @return Fee[]
     */
    public function findByClub(int $clubId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    public function findInClub(int $id, int $clubId): Fee {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * Marks the given fees of the club as paid in one statement. Only fees
     * that are still open or overdue are touched.
     *
     * @param int[] $ids
     * @return int number of fees changed
     */
    public function markPaidInClub(int $clubId, array $ids, string $now): int {
        $changed = 0;
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->update($this->getTableName())
                ->set('status', $qb->createNamedParameter('paid'))
                ->set('paid_date', $qb->createNamedParameter($now))
                ->set('updated_at', $qb->createNamedParameter($now))
                ->where($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->in('id', $qb->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['open', 'overdue'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
            $changed += $qb->executeStatement();
        }
        return $changed;
    }

    /**
     * Records a dunning step for the given fees of the club in one statement per chunk: sets the level and
     * the date, and an open fee becomes overdue. Paid and cancelled fees are never touched.
     *
     * @param int[] $ids
     * @return int number of fees changed
     */
    public function markDunnedInClub(int $clubId, array $ids, int $level, string $now): int {
        $changed = 0;
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->update($this->getTableName())
                ->set('dunning_level', $qb->createNamedParameter($level, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->set('last_dunned_at', $qb->createNamedParameter($now))
                ->set('status', $qb->createNamedParameter('overdue'))
                ->set('updated_at', $qb->createNamedParameter($now))
                ->where($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->in('id', $qb->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['open', 'overdue'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
            $changed += $qb->executeStatement();
        }
        return $changed;
    }

    /**
     * Open fees of the club that were due before $today become overdue.
     *
     * @return int number of fees changed
     */
    public function flagOverdueInClub(int $clubId, string $today, string $now): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('status', $qb->createNamedParameter('overdue'))
            ->set('updated_at', $qb->createNamedParameter($now))
            ->where($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
            ->andWhere($qb->expr()->lt('due_date', $qb->createNamedParameter($today . ' 00:00:00')));
        return $qb->executeStatement();
    }

    public function deleteByMemberInClub(int $memberId, int $clubId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * @return Fee[] all fees of one person, across clubs (self-service only)
     */
    public function findByMember(int $memberId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    public function findByStatus(string $status): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        return $this->findEntities($qb);
    }

    /**
     * @param string[] $statuses
     * @return Fee[]
     */
    public function findByStatusesInClub(array $statuses, int $clubId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('status', $qb->createNamedParameter($statuses, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }
}
