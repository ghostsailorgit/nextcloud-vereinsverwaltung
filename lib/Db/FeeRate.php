<?php
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

    public function __construct() {
        $this->addType('clubId', 'integer');
        $this->addType('amount', 'float');
        $this->addType('isDefault', 'bool');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'clubId' => $this->clubId,
            'name' => $this->name,
            'amount' => (float)$this->amount,
            'isDefault' => $this->isDefault,
        ];
    }
}
