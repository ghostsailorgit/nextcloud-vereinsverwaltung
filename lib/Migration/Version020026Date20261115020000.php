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
 * Fields a club defines for its members itself (e.g. licence, pass number, instrument), and their values.
 */
class Version020026Date20261115020000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Create verein_custom_fields and verein_custom_values';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('verein_custom_fields')) {
            $table = $schema->createTable('verein_custom_fields');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('club_id', 'integer', ['notnull' => true]);
            $table->addColumn('label', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('type', 'string', ['notnull' => true, 'length' => 20, 'default' => 'text']);
            // the choices of a select field, as a JSON list
            $table->addColumn('options', 'text', ['notnull' => false]);
            $table->addColumn('required', 'boolean', ['notnull' => false, 'default' => false]);
            $table->addColumn('show_in_list', 'boolean', ['notnull' => false, 'default' => false]);
            $table->addColumn('sort_order', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('created_at', 'string', ['notnull' => false, 'length' => 20]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['club_id'], 'vn_cf_club_idx');
        }
        if (!$schema->hasTable('verein_custom_values')) {
            $table = $schema->createTable('verein_custom_values');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('club_id', 'integer', ['notnull' => true]);
            $table->addColumn('field_id', 'integer', ['notnull' => true]);
            $table->addColumn('member_id', 'integer', ['notnull' => true]);
            $table->addColumn('value', 'text', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['club_id', 'member_id'], 'vn_cv_member_idx');
            $table->addIndex(['field_id'], 'vn_cv_field_idx');
        }

        return $schema;
    }
}
