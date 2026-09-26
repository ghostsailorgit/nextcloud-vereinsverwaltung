<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Verein\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the app's database tables. appinfo/database.xml (the legacy schema
 * format) defines the same tables but is no longer processed automatically by
 * Nextcloud - this migration is the real, working equivalent.
 */
class Version020001Date20260922120000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Create verein_members, verein_fees, verein_roles, verein_user_roles tables';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('verein_members')) {
            $table = $schema->createTable('verein_members');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('name', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('address', 'string', ['length' => 500, 'notnull' => false]);
            $table->addColumn('email', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('iban', 'string', ['length' => 34, 'notnull' => false]);
            $table->addColumn('bic', 'string', ['length' => 11, 'notnull' => false]);
            $table->addColumn('role', 'string', ['length' => 50, 'notnull' => true, 'default' => 'member']);
            $table->addColumn('user_id', 'string', ['length' => 64, 'notnull' => false]);
            $table->addColumn('created_at', 'datetime', ['notnull' => true]);
            $table->addColumn('updated_at', 'datetime', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
        }

        if (!$schema->hasTable('verein_fees')) {
            $table = $schema->createTable('verein_fees');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('member_id', 'integer', ['notnull' => true]);
            $table->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2, 'notnull' => true]);
            $table->addColumn('status', 'string', ['length' => 20, 'notnull' => true, 'default' => 'open']);
            $table->addColumn('due_date', 'datetime', ['notnull' => true]);
            $table->addColumn('paid_date', 'datetime', ['notnull' => false]);
            $table->addColumn('description', 'string', ['length' => 500, 'notnull' => false]);
            $table->addColumn('created_at', 'datetime', ['notnull' => true]);
            $table->addColumn('updated_at', 'datetime', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['member_id'], 'vn_fees_member_idx');
        }

        if (!$schema->hasTable('verein_roles')) {
            $table = $schema->createTable('verein_roles');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('name', 'string', ['length' => 128, 'notnull' => true]);
            $table->addColumn('description', 'string', ['length' => 500, 'notnull' => false]);
            $table->addColumn('permissions', 'text', ['notnull' => true]);
            $table->addColumn('club_type', 'string', ['length' => 64, 'notnull' => true, 'default' => 'music']);
            $table->addColumn('created_at', 'datetime', ['notnull' => true]);
            $table->addColumn('updated_at', 'datetime', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['name', 'club_type'], 'vn_roles_name_club_idx');
        }

        if (!$schema->hasTable('verein_user_roles')) {
            $table = $schema->createTable('verein_user_roles');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('role_id', 'integer', ['notnull' => true]);
            $table->addColumn('club_id', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('granted_by', 'string', ['length' => 64, 'notnull' => false]);
            $table->addColumn('granted_at', 'datetime', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'vn_user_roles_user_idx');
            $table->addIndex(['role_id'], 'vn_user_roles_role_idx');
            $table->addUniqueIndex(['user_id', 'role_id', 'club_id'], 'vn_user_roles_unique');
        }

        return $schema;
    }
}
