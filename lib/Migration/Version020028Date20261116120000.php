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
 * Sections for the own fields, each with the roles that may see and change it.
 */
class Version020028Date20261116120000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Create verein_custom_sections, add verein_custom_fields.section_id';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('verein_custom_sections')) {
            $table = $schema->createTable('verein_custom_sections');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('club_id', 'integer', ['notnull' => true]);
            $table->addColumn('name', 'string', ['notnull' => true, 'length' => 100]);
            // role ids as JSON lists
            $table->addColumn('view_roles', 'text', ['notnull' => false]);
            $table->addColumn('edit_roles', 'text', ['notnull' => false]);
            $table->addColumn('sort_order', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('created_at', 'string', ['notnull' => false, 'length' => 20]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['club_id'], 'vn_cs_club_idx');
        }
        $fields = $schema->getTable('verein_custom_fields');
        if (!$fields->hasColumn('section_id')) {
            $fields->addColumn('section_id', 'integer', ['notnull' => false]);
        }

        return $schema;
    }
}
