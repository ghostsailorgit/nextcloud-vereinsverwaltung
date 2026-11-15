<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class CustomValueMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'verein_custom_values', CustomValue::class);
    }

    /** @return CustomValue[] */
    public function findByClub(int $clubId): array {
        return $this->findEntities($this->where(['club_id' => $clubId]));
    }

    /** @return CustomValue[] */
    public function findByMember(int $clubId, int $memberId): array {
        return $this->findEntities($this->where(['club_id' => $clubId, 'member_id' => $memberId]));
    }

    public function deleteByField(int $fieldId): void {
        $this->deleteWhere(['field_id' => $fieldId]);
    }

    public function deleteByMember(int $clubId, int $memberId): void {
        $this->deleteWhere(['club_id' => $clubId, 'member_id' => $memberId]);
    }

    /** Every club: used when a person is anonymized. */
    public function deleteByPerson(int $memberId): void {
        $this->deleteWhere(['member_id' => $memberId]);
    }

    public function deleteByClub(int $clubId): void {
        $this->deleteWhere(['club_id' => $clubId]);
    }

    /** @param array<string, int> $conditions */
    private function where(array $conditions): IQueryBuilder {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());
        foreach ($conditions as $column => $value) {
            $qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
        }
        return $qb;
    }

    /** @param array<string, int> $conditions */
    private function deleteWhere(array $conditions): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName());
        foreach ($conditions as $column => $value) {
            $qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
        }
        $qb->executeStatement();
    }
}
