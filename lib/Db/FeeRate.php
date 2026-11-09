<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A membership fee category of a club (e.g. "Erwachsene", "Kinder").
 *
 * @method int getClubId()
 * @method void setClubId(int $clubId)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?float getAmount()
 * @method void setAmount(float $amount)
 * @method bool getIsDefault()
 * @method void setIsDefault(bool $isDefault)
 * @method ?string getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method ?float getJoinFee()
 * @method void setJoinFee(?float $joinFee)
 * @method ?string getFeeText()
 * @method void setFeeText(?string $feeText)
 * @method string getBillingInterval()
 * @method void setBillingInterval(string $billingInterval)
 * @method ?string getAgeTiers()
 * @method void setAgeTiers(?string $ageTiers)
 * @method ?string getAgeCutoff()
 * @method void setAgeCutoff(?string $ageCutoff)
 */
class FeeRate extends Entity implements JsonSerializable {
    protected int $clubId = 0;
    protected string $name = '';
    // Deliberately null, not 0.0: Entity only marks a field for INSERT when the value
    // differs from the current one, so a fee-free category (0.00) would otherwise be
    // left out of the INSERT and fail on the NOT NULL column.
    protected ?float $amount = null;
    protected bool $isDefault = false;
    protected ?string $createdAt = null;
    // one-time admission fee of members who join in this category; null = none
    protected ?float $joinFee = null;
    // own payment text with {period}/{zeitraum} as a placeholder; null = the standard text
    protected ?string $feeText = null;
    // how often the category is paid; the amount applies to each payment (see FeePeriod::INTERVALS)
    protected string $billingInterval = 'yearly';
    // JSON list of {fromAge, amount}, ascending and starting at 0; null = one amount for everybody
    protected ?string $ageTiers = null;
    // MM-DD: the day of the fee year the age counts on
    protected ?string $ageCutoff = null;

    public function __construct() {
        $this->addType('clubId', 'integer');
        $this->addType('amount', 'float');
        $this->addType('joinFee', 'float');
        $this->addType('isDefault', 'bool');
    }

    /** @return array<int, array{fromAge: int, amount: float}> */
    public function getAgeTierList(): array {
        $tiers = json_decode((string)$this->ageTiers, true);
        return is_array($tiers) ? $tiers : [];
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'clubId' => $this->clubId,
            'name' => $this->name,
            'amount' => (float)$this->amount,
            'isDefault' => $this->isDefault,
            'joinFee' => $this->joinFee === null ? null : (float)$this->joinFee,
            'feeText' => $this->feeText,
            'billingInterval' => $this->billingInterval,
            'ageTiers' => $this->getAgeTierList(),
            'ageCutoff' => $this->ageCutoff,
        ];
    }
}
