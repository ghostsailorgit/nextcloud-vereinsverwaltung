<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One SEPA file as it was exported: its fees went to the status "collecting" (im Einzug), so another export does not
 * contain them again. Marking them paid or open again works on the whole collection.
 *
 * @method int getClubId()
 * @method void setClubId(int $clubId)
 * @method string getMsgId()
 * @method void setMsgId(string $msgId)
 * @method ?string getCollectionDate()
 * @method void setCollectionDate(?string $collectionDate)
 * @method ?string getFeeIds()
 * @method void setFeeIds(?string $feeIds)
 * @method int getFeeCount()
 * @method void setFeeCount(int $feeCount)
 * @method int getTotalCents()
 * @method void setTotalCents(int $totalCents)
 * @method ?string getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method ?string getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 */
class SepaRun extends Entity implements JsonSerializable {
    protected int $clubId = 0;
    protected string $msgId = '';
    protected ?string $collectionDate = null;
    protected ?string $feeIds = null;
    // null defaults: a 0 would be left out of the INSERT (CLAUDE.md rule 7); the columns have DB defaults anyway
    protected ?int $feeCount = null;
    protected ?int $totalCents = null;
    protected ?string $createdBy = null;
    protected ?string $createdAt = null;

    public function __construct() {
        $this->addType('clubId', 'integer');
        $this->addType('feeCount', 'integer');
        $this->addType('totalCents', 'integer');
    }

    /** @return int[] */
    public function getFeeIdList(): array {
        $ids = json_decode((string)$this->feeIds, true);
        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'clubId' => $this->clubId,
            'msgId' => $this->msgId,
            'collectionDate' => $this->collectionDate,
            'feeCount' => (int)$this->feeCount,
            'total' => ((int)$this->totalCents) / 100,
            'createdBy' => $this->createdBy,
            'createdAt' => $this->createdAt,
        ];
    }
}
