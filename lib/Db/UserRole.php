<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class UserRole extends Entity implements JsonSerializable {
    protected ?string $userId = null;
    protected ?int $roleId = null;
    protected ?int $clubId = null;
    protected ?string $grantedBy = null;
    protected ?string $grantedAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('roleId', 'integer');
        $this->addType('clubId', 'integer');
        $this->addType('grantedBy', 'string');
        $this->addType('grantedAt', 'string');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'roleId' => $this->roleId,
            'clubId' => $this->clubId,
            'grantedBy' => $this->grantedBy,
            'grantedAt' => $this->grantedAt,
        ];
    }
}
