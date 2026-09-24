<?php

declare(strict_types=1);

namespace OCA\Verein\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A generic audit log for who changed what, when. (The member deactivation flag
 * lives in Version020007.)
 */
class Version020006Date20260929000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add the verein_audit_log table';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('verein_audit_log')) {
            $table = $schema->createTable('verein_audit_log');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            // Null for entries not tied to one club (e.g. global role definitions)
            $table->addColumn('club_id', 'integer', ['notnull' => false]);
            $table->addColumn('entity_type', 'string', ['length' => 40, 'notnull' => true]);
            $table->addColumn('entity_id', 'integer', ['notnull' => true]);
            $table->addColumn('action', 'string', ['length' => 20, 'notnull' => true]);
            // Uid at the time of the change; kept as a display name too since
            // the Nextcloud account can be deleted or renamed afterwards
            $table->addColumn('actor_user_id', 'string', ['length' => 64, 'notnull' => false]);
            $table->addColumn('actor_display_name', 'string', ['length' => 200, 'notnull' => false]);
            // JSON: {field: {old: ..., new: ...}} for update, {field: value} for create
            $table->addColumn('changes', 'text', ['notnull' => false]);
            $table->addColumn('created_at', 'datetime', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['club_id', 'created_at'], 'vn_audit_club_idx');
            $table->addIndex(['entity_type', 'entity_id'], 'vn_audit_entity_idx');
        }

        return $schema;
    }
}
