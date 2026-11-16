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
    // JSON list of group ids: the assignment covers only those groups' members (department heads); null = whole club
    protected ?string $groupIds = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('roleId', 'integer');
        $this->addType('clubId', 'integer');
        $this->addType('grantedBy', 'string');
        $this->addType('grantedAt', 'string');
        $this->addType('groupIds', 'string');
    }

    /** @return int[]|null the groups the assignment is limited to, null = the whole club */
    public function getGroupIdList(): ?array {
        $list = json_decode((string)$this->groupIds, true);
        if (!is_array($list) || $list === []) {
            return null;
        }
        return array_values(array_unique(array_map('intval', $list)));
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'roleId' => $this->roleId,
            'clubId' => $this->clubId,
            'grantedBy' => $this->grantedBy,
            'grantedAt' => $this->grantedAt,
            'groupIds' => $this->getGroupIdList(),
        ];
    }
}
