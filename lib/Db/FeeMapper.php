<?php
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
