<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One period of a member in a group. Whoever joins again gets a new row, so the history stays complete.
 *
 * @method int getClubId()
 * @method void setClubId(int $clubId)
 * @method int getGroupId()
 * @method void setGroupId(int $groupId)
 * @method int getMemberId()
 * @method void setMemberId(int $memberId)
 * @method string getJoinedOn()
 * @method void setJoinedOn(string $joinedOn)
 * @method ?string getLeftOn()
 * @method ?int getFeeRateId()
 * @method void setFeeRateId(?int $feeRateId)
 * @method void setLeftOn(?string $leftOn)
 */
class ClubGroupMember extends Entity implements JsonSerializable {
    protected int $clubId = 0;
    protected int $groupId = 0;
    protected int $memberId = 0;
    // YYYY-MM-DD as text: the same on every database, and it sorts by date
    protected string $joinedOn = '';
    protected ?string $leftOn = null;
    // the member's own fee category in this group instead of the group's; null = the group's
    protected ?int $feeRateId = null;

    public function __construct() {
        $this->addType('clubId', 'integer');
        $this->addType('groupId', 'integer');
        $this->addType('memberId', 'integer');
        $this->addType('feeRateId', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'groupId' => $this->groupId,
            'memberId' => $this->memberId,
            'joinedOn' => $this->joinedOn,
            'leftOn' => $this->leftOn,
            'feeRateId' => $this->feeRateId,
        ];
    }
}
