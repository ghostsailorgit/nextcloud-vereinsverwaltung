<?php
namespace OCA\Verein\Service;

use Exception;
use OCA\Verein\Db\Fee;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\MembershipMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Fees belong to one club and one of its members - every operation is scoped
 * to a club, so a fee of another club can never be read or changed through it.
 */
class FeeService {
    public function __construct(
        private FeeMapper $mapper,
        private MembershipMapper $membershipMapper
    ) {
    }

    public function findAll(int $clubId): array {
        return $this->mapper->findByClub($clubId);
    }

    public function find(int $clubId, int $id): Fee {
        try {
            return $this->mapper->findInClub($id, $clubId);
        } catch (DoesNotExistException $e) {
            throw new Exception('Fee not found');
        }
    }

    public function create(
        int $clubId,
        int $memberId,
        float $amount,
        string $status,
        string $dueDate,
        ?string $description = null
    ): Fee {
        $this->assertMemberOfClub($clubId, $memberId);

        $fee = new Fee();
        $fee->setClubId($clubId);
        $fee->setMemberId($memberId);
        $fee->setAmount($amount);
        $fee->setStatus($status);
        $fee->setDueDate($dueDate);
        $fee->setDescription($description);
        $fee->setCreatedAt(date('Y-m-d H:i:s'));
        $fee->setUpdatedAt(date('Y-m-d H:i:s'));
        return $this->mapper->insert($fee);
    }

    public function update(
        int $clubId,
        int $id,
        int $memberId,
        float $amount,
        string $status,
        string $dueDate,
        ?string $description = null
    ): Fee {
        $fee = $this->find($clubId, $id);
        $this->assertMemberOfClub($clubId, $memberId);

        $fee->setMemberId($memberId);
        $fee->setAmount($amount);
        $fee->setStatus($status);
        $fee->setDueDate($dueDate);
        $fee->setDescription($description);
        $fee->setUpdatedAt(date('Y-m-d H:i:s'));
        return $this->mapper->update($fee);
    }

    public function delete(int $clubId, int $id): Fee {
        $fee = $this->find($clubId, $id);
        return $this->mapper->delete($fee);
    }

    /**
     * @throws Exception if the person is not a member of the club
     */
    private function assertMemberOfClub(int $clubId, int $memberId): void {
        try {
            $this->membershipMapper->findByMemberAndClub($memberId, $clubId);
        } catch (DoesNotExistException $e) {
            throw new Exception('Das Mitglied gehört nicht zu diesem Verein');
        }
    }
}
