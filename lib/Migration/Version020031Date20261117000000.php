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
 * SEPA collections (one row per exported file: which fees are in it, so a second export cannot collect them again)
 * and, per section of own fields, whether the member sees those fields under "My data".
 */
class Version020031Date20261117000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_sepa_runs and verein_custom_sections.member_visible';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('verein_sepa_runs')) {
            $table = $schema->createTable('verein_sepa_runs');
            $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('club_id', 'bigint', ['notnull' => true]);
            $table->addColumn('msg_id', 'string', ['notnull' => true, 'length' => 35]);
            $table->addColumn('collection_date', 'string', ['notnull' => false, 'length' => 10]);
            // JSON list of the fee ids in the file
            $table->addColumn('fee_ids', 'text', ['notnull' => false]);
            $table->addColumn('fee_count', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('total_cents', 'bigint', ['notnull' => true, 'default' => 0]);
            $table->addColumn('created_by', 'string', ['notnull' => false, 'length' => 64]);
            $table->addColumn('created_at', 'string', ['notnull' => false, 'length' => 19]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['club_id'], 'verein_sepa_runs_club');
        }

        $sections = $schema->getTable('verein_custom_sections');
        if (!$sections->hasColumn('member_visible')) {
            $sections->addColumn('member_visible', 'boolean', ['notnull' => false, 'default' => true]);
        }

        return $schema;
    }
}
