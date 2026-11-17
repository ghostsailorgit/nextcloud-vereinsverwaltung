<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<SepaRun> */
class SepaRunMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'verein_sepa_runs', SepaRun::class);
    }

    public function findInClub(int $id, int $clubId): SepaRun {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /** @return SepaRun[] newest first */
    public function findByClub(int $clubId, int $limit = 20): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /** @return SepaRun[] the club's collections created after the given one */
    public function findNewerInClub(int $clubId, int $id): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    public function deleteByClub(int $clubId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
