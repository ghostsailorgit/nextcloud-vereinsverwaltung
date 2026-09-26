<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One person's membership in one club: everything that differs per club
 * (role, join/leave date, founding member, SEPA mandate).
 *
 * @method int getMemberId()
 * @method void setMemberId(int $memberId)
 * @method int getClubId()
 * @method void setClubId(int $clubId)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method ?string getJoinDate()
 * @method void setJoinDate(?string $joinDate)
 * @method ?string getLeaveDate()
 * @method void setLeaveDate(?string $leaveDate)
 * @method bool getDeactivated()
 * @method void setDeactivated(bool $deactivated)
 * @method bool getFoundingMember()
 * @method void setFoundingMember(bool $foundingMember)
 * @method ?string getMandateReference()
 * @method void setMandateReference(?string $mandateReference)
 * @method ?string getMandateDate()
 * @method void setMandateDate(?string $mandateDate)
 * @method ?int getFeeRateId()
 * @method void setFeeRateId(?int $feeRateId)
 * @method ?string getMandateFile()
 * @method void setMandateFile(?string $mandateFile)
 * @method ?string getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method ?string getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class Membership extends Entity implements JsonSerializable {
    protected int $memberId = 0;
    protected int $clubId = 0;
    protected string $role = 'member';
    protected ?string $joinDate = null;
    protected ?string $leaveDate = null;
    protected bool $foundingMember = false;
    // Deactivated: no payments (fee run, SEPA), no calendar reminders, no derived rights; nothing is deleted
    protected bool $deactivated = false;
    protected ?string $mandateReference = null;
    protected ?string $mandateDate = null;
    protected ?string $mandateFile = null;
    protected ?int $feeRateId = null;
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;

    /** See Member::__construct() - bools need an explicit type or a true->false UPDATE fails. */
    public function __construct() {
        $this->addType('memberId', 'integer');
        $this->addType('clubId', 'integer');
        $this->addType('foundingMember', 'bool');
        $this->addType('deactivated', 'bool');
        $this->addType('feeRateId', 'integer');
    }

    /**
     * The mandate reference sent to the bank: the stored one, or a stable
     * generated fallback (unique per creditor, which is what SEPA requires).
     */
    public function getEffectiveMandateReference(): string {
        $ref = trim((string)$this->mandateReference);
        return $ref !== '' ? $ref : 'M' . $this->clubId . '-' . $this->memberId;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'memberId' => $this->memberId,
            'clubId' => $this->clubId,
            'role' => $this->role,
            'joinDate' => $this->joinDate,
            'leaveDate' => $this->leaveDate,
            'foundingMember' => $this->foundingMember,
            'deactivated' => $this->deactivated,
            'mandateReference' => $this->mandateReference,
            'mandateDate' => $this->mandateDate,
            'mandateFile' => $this->mandateFile,
            'feeRateId' => $this->feeRateId,
        ];
    }
}
