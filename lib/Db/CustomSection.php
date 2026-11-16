<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A group of a club's own fields (e.g. "Bank details", "Club internal", "Police certificate") with the roles that may
 * see it and the roles that may change it. Fields without a section are seen by everyone who sees members.
 *
 * @method int getClubId()
 * @method void setClubId(int $clubId)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getViewRoles()
 * @method void setViewRoles(?string $viewRoles)
 * @method ?string getEditRoles()
 * @method void setEditRoles(?string $editRoles)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method ?string getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 */
class CustomSection extends Entity implements JsonSerializable {
    protected int $clubId = 0;
    protected string $name = '';
    // null defaults, see the note on entity dirty tracking in CLAUDE.md (rule 7)
    protected ?string $viewRoles = null;
    protected ?string $editRoles = null;
    protected ?int $sortOrder = null;
    protected ?string $createdAt = null;

    public function __construct() {
        $this->addType('clubId', 'integer');
        $this->addType('sortOrder', 'integer');
    }

    /** @return int[] role ids that may see the section */
    public function getViewRoleIds(): array {
        return self::ids($this->viewRoles);
    }

    /** @return int[] role ids that may change the values in the section (and therefore see it) */
    public function getEditRoleIds(): array {
        return self::ids($this->editRoles);
    }

    /** @return int[] */
    private static function ids(?string $json): array {
        $list = json_decode((string)$json, true);
        return is_array($list) ? array_values(array_unique(array_map('intval', $list))) : [];
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'clubId' => $this->clubId,
            'name' => $this->name,
            'viewRoles' => $this->getViewRoleIds(),
            'editRoles' => $this->getEditRoleIds(),
            'sortOrder' => (int)$this->sortOrder,
        ];
    }
}
