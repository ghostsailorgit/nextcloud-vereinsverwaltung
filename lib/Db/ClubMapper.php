<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ClubMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'verein_clubs', Club::class);
    }

    public function find(int $id): Club {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * Locks the club's row until the current transaction ends (a no-op update: portable to every database Nextcloud
     * supports; SQLite has no row locks and locks the whole database instead). Serializes runs that must not
     * overlap within one club, e.g. two fee runs started at the same time.
     */
    public function lockForUpdate(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('name', $qb->createFunction('name'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /** @return Club[] */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())->orderBy('name', 'ASC');
        return $this->findEntities($qb);
    }
}
