<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ClubGroupMemberMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'verein_group_members', ClubGroupMember::class);
    }

    public function find(int $id): ClubGroupMember {
        return $this->findEntity($this->where('id', $id));
    }

    /** @return ClubGroupMember[] */
    public function findByClub(int $clubId): array {
        return $this->findEntities($this->where('club_id', $clubId));
    }

    /** @return ClubGroupMember[] */
    public function findByGroup(int $groupId): array {
        return $this->findEntities($this->where('group_id', $groupId));
    }

    /** @return ClubGroupMember[] */
    public function findByMember(int $memberId): array {
        return $this->findEntities($this->where('member_id', $memberId));
    }

    public function deleteByGroup(int $groupId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    public function deleteByMemberAndClub(int $memberId, int $clubId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    public function deleteByClub(int $clubId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    private function where(string $column, int $value): IQueryBuilder {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
        return $qb;
    }
}
