<?php
namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getStreet()
 * @method void setStreet(?string $street)
 * @method ?string getPostalCode()
 * @method void setPostalCode(?string $postalCode)
 * @method ?string getCity()
 * @method void setCity(?string $city)
 * @method ?string getDocumentsPath()
 * @method void setDocumentsPath(?string $documentsPath)
 * @method ?string getCalendarGroups()
 * @method void setCalendarGroups(?string $calendarGroups)
 * @method ?string getRoleMapping()
 * @method void setRoleMapping(?string $roleMapping)
 * @method ?string getCalendarUri()
 * @method void setCalendarUri(?string $calendarUri)
 * @method ?string getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method ?string getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class Club extends Entity implements JsonSerializable {
    protected string $name = '';
    protected ?string $street = null;
    protected ?string $postalCode = null;
    protected ?string $city = null;
    protected ?string $documentsPath = null;
    protected ?string $calendarGroups = null;
    protected ?string $calendarUri = null;
    protected ?string $roleMapping = null;
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;

    /** @return string[] */
    public function getCalendarGroupsArray(): array {
        $decoded = json_decode((string)$this->calendarGroups, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * Which app role (verein_roles id) a membership role gets automatically,
     * e.g. ['admin' => 3, 'treasurer' => 4]. Empty = nothing derived.
     *
     * @return array<string, int>
     */
    public function getRoleMappingArray(): array {
        $decoded = json_decode((string)$this->roleMapping, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $membershipRole => $roleId) {
            if (is_string($membershipRole) && is_numeric($roleId) && (int)$roleId > 0) {
                $result[$membershipRole] = (int)$roleId;
            }
        }
        return $result;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'street' => $this->street,
            'postalCode' => $this->postalCode,
            'city' => $this->city,
            'documentsPath' => $this->documentsPath,
            'calendarGroups' => $this->getCalendarGroupsArray(),
            'roleMapping' => (object)$this->getRoleMappingArray(),
        ];
    }
}
