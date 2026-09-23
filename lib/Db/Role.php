<?php

namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class Role extends Entity implements JsonSerializable {
    protected ?string $name = null;
    protected ?string $description = null;
    protected ?string $permissions = null;
    protected ?string $clubType = null;
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('permissions', 'string');
        $this->addType('clubType', 'string');
        $this->addType('createdAt', 'string');
        $this->addType('updatedAt', 'string');
    }

    public function setPermissionsArray(array $permissions): void {
        // Must go through the generated setter (not a raw property write) so
        // Entity::getUpdatedFields() marks 'permissions' dirty - otherwise
        // QBMapper::insert()/update() silently omit the column entirely,
        // which fails on a NOT NULL column with no default.
        $this->setPermissions(json_encode(array_values($permissions)));
    }

    public function getPermissionsArray(): array {
        if ($this->permissions === null || $this->permissions === '') {
            return [];
        }

        $decoded = json_decode($this->permissions, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'clubType' => $this->clubType,
            'permissions' => $this->getPermissionsArray(),
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
