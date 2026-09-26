<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getMemberId()
 * @method void setMemberId(int $memberId)
 * @method int getClubId()
 * @method void setClubId(int $clubId)
 * @method float getAmount()
 * @method void setAmount(float $amount)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getDueDate()
 * @method void setDueDate(string $dueDate)
 * @method ?string getPaidDate()
 * @method void setPaidDate(?string $paidDate)
 * @method ?string getPeriod()
 * @method void setPeriod(?string $period)
 * @method ?string getDescription()
 * @method void setDescription(?string $description)
 * @method ?string getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method ?string getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 * @method int getDunningLevel()
 * @method void setDunningLevel(int $dunningLevel)
 * @method ?string getLastDunnedAt()
 * @method void setLastDunnedAt(?string $lastDunnedAt)
 */
class Fee extends Entity implements JsonSerializable {
    protected int $memberId = 0;
    protected int $clubId = 0;
    protected float $amount = 0.0;
    protected string $status = 'open';
    protected string $dueDate = '';
    protected ?string $paidDate = null;
    protected ?string $description = null;
    protected ?string $period = null;
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;
    // 0 = never dunned, 1-3 = DunningService::levelLabel() (the column has DB default 0, so INSERT may omit it)
    protected int $dunningLevel = 0;
    protected ?string $lastDunnedAt = null;

    public function __construct() {
        $this->addType('dunningLevel', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'memberId' => $this->memberId,
            'clubId' => $this->clubId,
            'amount' => $this->amount,
            'status' => $this->status,
            'dueDate' => $this->dueDate,
            'paidDate' => $this->paidDate,
            'description' => $this->description,
            'period' => $this->period,
            'dunningLevel' => $this->dunningLevel,
            'lastDunnedAt' => $this->lastDunnedAt,
        ];
    }
}
