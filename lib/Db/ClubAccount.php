<?php
namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A bank account of a club, with the SEPA creditor identifier used for
 * direct debits collected on it.
 *
 * @method int getClubId()
 * @method void setClubId(int $clubId)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getIban()
 * @method void setIban(string $iban)
 * @method string getBic()
 * @method void setBic(string $bic)
 * @method string getCreditorId()
 * @method void setCreditorId(string $creditorId)
 * @method bool getIsDefault()
 * @method void setIsDefault(bool $isDefault)
 * @method ?string getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 */
class ClubAccount extends Entity implements JsonSerializable {
    protected int $clubId = 0;
    protected string $label = '';
    protected string $iban = '';
    protected string $bic = '';
    protected string $creditorId = '';
    protected bool $isDefault = false;
    protected ?string $createdAt = null;

    public function __construct() {
        $this->addType('clubId', 'integer');
        $this->addType('isDefault', 'bool');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'clubId' => $this->clubId,
            'label' => $this->label,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'creditorId' => $this->creditorId,
            'isDefault' => $this->isDefault,
        ];
    }
}
