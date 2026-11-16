<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A field a club defines for its members itself (e.g. licence, pass number, instrument).
 *
 * @method int getClubId()
 * @method void setClubId(int $clubId)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getType()
 * @method void setType(string $type)
 * @method ?string getOptions()
 * @method void setOptions(?string $options)
 * @method ?bool getRequired()
 * @method void setRequired(?bool $required)
 * @method ?bool getShowInList()
 * @method void setShowInList(?bool $showInList)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method ?int getSectionId()
 * @method void setSectionId(?int $sectionId)
 * @method ?string getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 */
class CustomField extends Entity implements JsonSerializable {
    public const TYPES = ['text', 'number', 'date', 'select', 'checkbox'];

    protected int $clubId = 0;
    protected string $label = '';
    // null defaults, see the note on entity dirty tracking in CLAUDE.md (rule 7)
    protected ?string $type = null;
    protected ?string $options = null;
    protected ?bool $required = null;
    protected ?bool $showInList = null;
    protected ?int $sortOrder = null;
    // null = no section: seen by everyone who sees members
    protected ?int $sectionId = null;
    protected ?string $createdAt = null;

    public function __construct() {
        $this->addType('clubId', 'integer');
        $this->addType('required', 'bool');
        $this->addType('showInList', 'bool');
        $this->addType('sortOrder', 'integer');
        $this->addType('sectionId', 'integer');
    }

    /** @return string[] */
    public function getOptionList(): array {
        $list = json_decode((string)$this->options, true);
        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'clubId' => $this->clubId,
            'label' => $this->label,
            'type' => $this->type ?? 'text',
            'options' => $this->getOptionList(),
            'required' => (bool)$this->required,
            'showInList' => (bool)$this->showInList,
            'sortOrder' => (int)$this->sortOrder,
            'sectionId' => $this->sectionId,
        ];
    }
}
