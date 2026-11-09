<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A group inside a club, e.g. a training group. Not a Nextcloud group: most members have no account.
 *
 * @method int getClubId()
 * @method void setClubId(int $clubId)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getDescription()
 * @method void setDescription(?string $description)
 * @method ?int getFeeRateId()
 * @method void setFeeRateId(?int $feeRateId)
 * @method ?string getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 */
class ClubGroup extends Entity implements JsonSerializable {
    protected int $clubId = 0;
    protected string $name = '';
    protected ?string $description = null;
    // fee category the members of the group pay in addition to their own; null = none
    protected ?int $feeRateId = null;
    protected ?string $createdAt = null;

    public function __construct() {
        $this->addType('clubId', 'integer');
        $this->addType('feeRateId', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'clubId' => $this->clubId,
            'name' => $this->name,
            'description' => $this->description,
            'feeRateId' => $this->feeRateId,
        ];
    }
}
