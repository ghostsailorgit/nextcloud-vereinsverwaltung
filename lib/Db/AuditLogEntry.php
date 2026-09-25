<?php
namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One change-log line: who did what to which entity, when, and (for
 * create/update) the field-level diff. Written by AuditLogService, never
 * edited afterwards.
 *
 * @method ?int getClubId()
 * @method void setClubId(?int $clubId)
 * @method string getEntityType()
 * @method void setEntityType(string $entityType)
 * @method ?int getEntityId()
 * @method void setEntityId(int $entityId)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method ?string getActorUserId()
 * @method void setActorUserId(?string $actorUserId)
 * @method ?string getActorDisplayName()
 * @method void setActorDisplayName(?string $actorDisplayName)
 * @method ?string getChanges()
 * @method void setChanges(?string $changes)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class AuditLogEntry extends Entity implements JsonSerializable {
    protected ?int $clubId = null;
    protected string $entityType = '';
    // null, not 0: bulk actions (fee run, mark paid, flag overdue, revoke all roles) log entityId 0, and a
    // setter call with the property's default value is not written on INSERT (entity_id is NOT NULL, no default)
    protected ?int $entityId = null;
    protected string $action = '';
    protected ?string $actorUserId = null;
    protected ?string $actorDisplayName = null;
    protected ?string $changes = null;
    protected string $createdAt = '';

    public function __construct() {
        $this->addType('clubId', 'integer');
        $this->addType('entityId', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'clubId' => $this->clubId,
            'entityType' => $this->entityType,
            'entityId' => $this->entityId,
            'action' => $this->action,
            'actorUserId' => $this->actorUserId,
            'actorDisplayName' => $this->actorDisplayName,
            'changes' => $this->changes !== null ? json_decode($this->changes, true) : null,
            'createdAt' => $this->createdAt,
        ];
    }
}
