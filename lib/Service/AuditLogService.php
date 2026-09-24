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
    /**
     * Entity types about people - their data, payments and access rights. Entries of these
     * types are kept for LONG_RETENTION_YEARS, everything else (clubs, bank accounts, fee
     * categories, fee runs, role definitions) only for SHORT_RETENTION_DAYS.
     */
    public const LONG_RETENTION_TYPES = ['member', 'membership', 'fee', 'user_role'];
    public const LONG_RETENTION_YEARS = 10;
    public const SHORT_RETENTION_DAYS = 30;

    /**
     * Fields whose value is never stored in the log, only the fact that they changed - the log is
     * itself personal data (Article 5(1)(c) GDPR: data minimisation), and unlike the live record it
     * has no "anonymize" of its own; scrubEntity() only re-applies this redaction to older entries
     * written before a field was added here or before anonymize() ran.
     */
    private const SENSITIVE_FIELDS = [
        'member' => ['name', 'firstName', 'salutation', 'address', 'street', 'postalCode', 'city',
            'email', 'iban', 'bic', 'birthDate', 'userId'],
    ];

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
        $changes = $this->redact($entityType, $changes);
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
     * Replaces the value of every field in SENSITIVE_FIELDS[$entityType] with a marker that only says
     * the field changed, not what to or from. Diff-shaped entries (['old' => ..., 'new' => ...]) become
     * ['redacted' => true]; a plain create-style value becomes true. Fields not on the list, and entity
     * types with no list at all, pass through untouched.
     */
    private function redact(string $entityType, array $changes): array {
        $sensitive = self::SENSITIVE_FIELDS[$entityType] ?? [];
        foreach ($sensitive as $field) {
            if (array_key_exists($field, $changes)) {
                $changes[$field] = is_array($changes[$field]) ? ['redacted' => true] : true;
            }
        }
        return $changes;
    }

    /**
     * Re-applies redact() to every existing log entry of one entity, in place - for data written
     * before anonymize() ran, or before a field was added to SENSITIVE_FIELDS. Entries with nothing
     * left to redact (no sensitive field was ever stored, e.g. a 'delete' or 'lock' action) are
     * skipped; the log is append-mostly, this is the one place that rewrites past rows.
     */
    public function scrubEntity(string $entityType, int $entityId): void {
        if (!isset(self::SENSITIVE_FIELDS[$entityType])) {
            return;
        }
        foreach ($this->mapper->findAllForEntity($entityType, $entityId) as $entry) {
            $raw = $entry->getChanges();
            if ($raw === null) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $redacted = $this->redact($entityType, $decoded);
            if ($redacted !== $decoded) {
                $entry->setChanges(json_encode($redacted, JSON_UNESCAPED_UNICODE));
                $this->mapper->update($entry);
            }
        }
    }

    /**
     * Cut-off timestamps ("Y-m-d H:i:s"): entries older than 'short' are deleted for the
     * short-lived entity types, entries older than 'long' for all types.
     *
     * @return array{short: string, long: string}
     */
    public static function cutoffs(int $now): array {
        $t = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return [
            'short' => $t->modify('-' . self::SHORT_RETENTION_DAYS . ' days')->format('Y-m-d H:i:s'),
            'long' => $t->modify('-' . self::LONG_RETENTION_YEARS . ' years')->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Deletes entries past their retention period (see LONG_RETENTION_TYPES).
     *
     * @return int number of deleted entries
     */
    public function prune(?int $now = null): int {
        $cut = self::cutoffs($now ?? time());
        $deleted = $this->mapper->deleteBefore($cut['short'], self::LONG_RETENTION_TYPES, false);
        $deleted += $this->mapper->deleteBefore($cut['long']);
        return $deleted;
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
