<?php
namespace OCA\Verein\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class MembershipMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'verein_memberships', Membership::class);
    }

    /** @throws DoesNotExistException */
    public function findByMemberAndClub(int $memberId, int $clubId): Membership {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /** @return Membership[] */
    public function findByClub(int $clubId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    /** @return Membership[] */
    public function findByMember(int $memberId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    public function countByFeeRate(int $feeRateId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName())
            ->where($qb->expr()->eq('fee_rate_id', $qb->createNamedParameter($feeRateId, IQueryBuilder::PARAM_INT)));
        return (int)$qb->executeQuery()->fetchOne();
    }

    public function countByClub(int $clubId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName())
            ->where($qb->expr()->eq('club_id', $qb->createNamedParameter($clubId, IQueryBuilder::PARAM_INT)));
        return (int)$qb->executeQuery()->fetchOne();
    }
}
