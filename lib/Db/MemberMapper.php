<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Persons (verein_members). Club-specific data lives in verein_memberships;
 * the club-scoped finders below attach the matching Membership to each
 * returned Member (see Member::setMembership()).
 */
class MemberMapper extends QBMapper {
    public function __construct(IDBConnection $db, private MembershipMapper $membershipMapper) {
        parent::__construct($db, 'verein_members', Member::class);
    }

    /**
     * All persons regardless of club - only for internal use, never hand
     * this to a user-facing endpoint.
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());
        return $this->findEntities($qb);
    }

    /**
     * Plain person lookup without club context. Callers exposing the result
     * to a user must have checked access (see findInClub()).
     */
    public function find(int $id): Member {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * The person linked to a Nextcloud account, if any (one account belongs
     * to at most one person).
     */
    public function findByUserId(string $userId): ?Member {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * All members of a club, each with their membership attached.
     *
     * @return Member[]
     */
    public function findByClub(int $clubId): array {
        $memberships = [];
        foreach ($this->membershipMapper->findByClub($clubId) as $membership) {
            $memberships[$membership->getMemberId()] = $membership;
        }
        return $this->loadWithMemberships($memberships);
    }

    /**
     * One member of a club.
     *
     * @throws DoesNotExistException if the person is not a member of that club
     */
    public function findInClub(int $id, int $clubId): Member {
        $membership = $this->membershipMapper->findByMemberAndClub($id, $clubId);
        $member = $this->find($id);
        $member->setMembership($membership);
        return $member;
    }

    /**
     * Search within one club (name, first name, email or exact id).
     *
     * @return Member[]
     */
    public function searchInClub(string $query, int $clubId, int $limit = 50): array {
        $memberships = [];
        foreach ($this->membershipMapper->findByClub($clubId) as $membership) {
            $memberships[$membership->getMemberId()] = $membership;
        }
        if ($memberships === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter(array_keys($memberships), IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($this->searchCondition($qb, $query))
            ->setMaxResults($limit);

        $members = $this->findEntities($qb);
        foreach ($members as $member) {
            $member->setMembership($memberships[$member->getId()]);
        }
        return $members;
    }

    /**
     * Persons belonging to any of $clubIds but not yet to $excludeClubId,
     * matching the query - used to offer "add existing person" without
     * duplicating anyone. The caller decides which clubs the current user
     * may look into.
     *
     * @param int[] $clubIds
     * @return Member[]
     */
    public function searchInOtherClubs(string $query, array $clubIds, int $excludeClubId, int $limit = 20): array {
        if ($clubIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('m.*')
            ->from($this->getTableName(), 'm')
            ->innerJoin('m', 'verein_memberships', 'ms', $qb->expr()->eq('ms.member_id', 'm.id'))
            ->where($qb->expr()->in('ms.club_id', $qb->createNamedParameter($clubIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($this->searchCondition($qb, $query, 'm.'))
            ->setMaxResults($limit);

        $alreadyIn = array_map(
            static fn (Membership $m) => $m->getMemberId(),
            $this->membershipMapper->findByClub($excludeClubId)
        );
        if ($alreadyIn !== []) {
            $qb->andWhere($qb->expr()->notIn('m.id', $qb->createNamedParameter($alreadyIn, IQueryBuilder::PARAM_INT_ARRAY)));
        }

        return $this->findEntities($qb);
    }

    private function searchCondition(IQueryBuilder $qb, string $query, string $prefix = '') {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
        $conditions = [
            $qb->expr()->like($prefix . 'name', $qb->createNamedParameter($like)),
            $qb->expr()->like($prefix . 'first_name', $qb->createNamedParameter($like)),
            $qb->expr()->like($prefix . 'email', $qb->createNamedParameter($like)),
        ];
        // The member's id doubles as its member number - allow searching by it directly
        if (ctype_digit($query)) {
            $conditions[] = $qb->expr()->eq($prefix . 'id', $qb->createNamedParameter((int)$query, IQueryBuilder::PARAM_INT));
        }
        return $qb->expr()->orX(...$conditions);
    }

    /**
     * @param array<int, Membership> $memberships keyed by member id
     * @return Member[]
     */
    private function loadWithMemberships(array $memberships): array {
        if ($memberships === []) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter(array_keys($memberships), IQueryBuilder::PARAM_INT_ARRAY)));
        $members = $this->findEntities($qb);
        foreach ($members as $member) {
            $member->setMembership($memberships[$member->getId()]);
        }
        return $members;
    }
}
