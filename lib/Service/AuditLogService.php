<?php
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\AuditLogEntry;
use OCA\Verein\Db\AuditLogMapper;
use OCP\IUserSession;

/**
 * Generic change log for the app: who changed what on which entity, when.
 * Services call record() with a field-level diff (see diff()); nothing here
 * enforces who may read the log back - that is the audit log controller's
 * #[RequirePermission('verein.audit.view')].
 *
 * Injected as an optional collaborator (like MemberCalendarService): a
 * service that has none simply logs nothing, so existing tests that
 * construct a service directly are unaffected.
 */
class AuditLogService {
    public function __construct(
        private AuditLogMapper $mapper,
        private IUserSession $userSession
    ) {
    }

    /**
     * @param array<string, array{old: mixed, new: mixed}>|array<string, mixed> $changes
     *   For 'update': field => ['old' => ..., 'new' => ...] (see diff()).
     *   For 'create': field => value. For 'delete': usually empty.
     */
    public function record(?int $clubId, string $entityType, int $entityId, string $action, array $changes = []): void {
        $user = $this->userSession->getUser();

        $entry = new AuditLogEntry();
        $entry->setClubId($clubId);
        $entry->setEntityType($entityType);
        $entry->setEntityId($entityId);
        $entry->setAction($action);
        $entry->setActorUserId($user?->getUID());
        $entry->setActorDisplayName($user?->getDisplayName());
        $entry->setChanges($changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE));
        $entry->setCreatedAt(date('Y-m-d H:i:s'));
        $this->mapper->insert($entry);
    }

    /**
     * Field-level diff between two jsonSerialize()-style arrays: only the
     * keys whose value actually changed, each as ['old' => ..., 'new' => ...].
     * Keys present in only one side count as changed too (old/new is null
     * for the missing side).
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function diff(array $before, array $after): array {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old !== $new) {
                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        }
        return $changes;
    }
}
