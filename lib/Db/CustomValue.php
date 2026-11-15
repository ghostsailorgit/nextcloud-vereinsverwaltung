<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The value of a club's own field for one member of that club.
 *
 * @method int getClubId()
 * @method void setClubId(int $clubId)
 * @method int getFieldId()
 * @method void setFieldId(int $fieldId)
 * @method int getMemberId()
 * @method void setMemberId(int $memberId)
 * @method ?string getValue()
 * @method void setValue(?string $value)
 */
class CustomValue extends Entity {
    protected int $clubId = 0;
    protected int $fieldId = 0;
    protected int $memberId = 0;
    protected ?string $value = null;

    public function __construct() {
        $this->addType('clubId', 'integer');
        $this->addType('fieldId', 'integer');
        $this->addType('memberId', 'integer');
    }
}
