<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Service;

use OCA\Verein\Exception\NotFoundException;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Db\Fee;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Db\MembershipMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Fees belong to one club and one of its members - every operation is scoped
 * to a club, so a fee of another club can never be read or changed through it.
 */
class FeeService {
    public function __construct(
        private FeeMapper $mapper,
        private MembershipMapper $membershipMapper,
        private ?AuditLogService $auditLog = null,
        private ?Clock $clock = null
    ) {
    }

    public function findAll(int $clubId): array {
        return $this->mapper->findByClub($clubId);
    }

    public function find(int $clubId, int $id): Fee {
        try {
            return $this->mapper->findInClub($id, $clubId);
        } catch (DoesNotExistException $e) {
            throw new NotFoundException('Beitrag nicht gefunden');
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
        $membership = $this->assertMemberOfClub($clubId, $memberId);
        if ($membership->getDeactivated()) {
            throw new ValidationException('Das Mitglied ist deaktiviert - für deaktivierte Mitglieder werden keine Beiträge angelegt');
        }

        $fee = new Fee();
        $fee->setClubId($clubId);
        $fee->setMemberId($memberId);
        $fee->setAmount($amount);
        $fee->setStatus($status);
        $fee->setDueDate($dueDate);
        $fee->setDescription($description);
        $fee->setCreatedAt(date('Y-m-d H:i:s'));
        $fee->setUpdatedAt(date('Y-m-d H:i:s'));
        $fee = $this->mapper->insert($fee);
        $this->auditLog?->record($clubId, 'fee', $fee->getId(), 'create', $fee->jsonSerialize());
        return $fee;
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
        $before = $fee->jsonSerialize();
        $this->assertMemberOfClub($clubId, $memberId);

        $fee->setMemberId($memberId);
        $fee->setAmount($amount);
        $fee->setStatus($status);
        $fee->setDueDate($dueDate);
        $fee->setDescription($description);
        $fee->setUpdatedAt(date('Y-m-d H:i:s'));
        $fee = $this->mapper->update($fee);
        if ($this->auditLog !== null) {
            $changes = $this->auditLog->diff($before, $fee->jsonSerialize());
            if ($changes !== []) {
                $this->auditLog->record($clubId, 'fee', $id, 'update', $changes);
            }
        }
        return $fee;
    }

    public function delete(int $clubId, int $id): Fee {
        $fee = $this->find($clubId, $id);
        $deleted = $this->mapper->delete($fee);
        $this->auditLog?->record($clubId, 'fee', $id, 'delete');
        return $deleted;
    }

    /**
     * Marks fees as paid (e.g. after they were collected by direct debit).
     * Only fees of this club that are still open or overdue are touched.
     *
     * @param int[] $ids
     * @return int number of fees marked paid
     */
    public function markPaid(int $clubId, array $ids): int {
        if ($ids === []) {
            return 0;
        }
        $count = $this->mapper->markPaidInClub($clubId, $ids, date('Y-m-d H:i:s'));
        if ($count > 0) {
            $this->auditLog?->record($clubId, 'fee', 0, 'mark_paid', ['ids' => $ids, 'count' => $count]);
        }
        return $count;
    }

    /**
     * Flags open fees whose due date has passed as overdue.
     *
     * @return int number of fees flagged
     */
    public function flagOverdue(int $clubId): int {
        $count = $this->mapper->flagOverdueInClub($clubId, Clock::todayOf($this->clock), date('Y-m-d H:i:s'));
        if ($count > 0) {
            $this->auditLog?->record($clubId, 'fee', 0, 'flag_overdue', ['count' => $count]);
        }
        return $count;
    }

    /**
     * @throws Exception if the person is not a member of the club
     */
    private function assertMemberOfClub(int $clubId, int $memberId): Membership {
        try {
            return $this->membershipMapper->findByMemberAndClub($memberId, $clubId);
        } catch (DoesNotExistException $e) {
            throw new ValidationException('Das Mitglied gehört nicht zu diesem Verein');
        }
    }
}
