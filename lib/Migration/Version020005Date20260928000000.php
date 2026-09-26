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
 * Fee rates per club (categories such as adults/children/family), the rate
 * a membership pays, and a period marker on fees so an annual fee run can
 * tell which members already got their fee for that year.
 */
class Version020005Date20260928000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_fee_rates, verein_memberships.fee_rate_id and verein_fees.period';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('verein_fee_rates')) {
            $table = $schema->createTable('verein_fee_rates');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('club_id', 'integer', ['notnull' => true]);
            $table->addColumn('name', 'string', ['length' => 100, 'notnull' => true]);
            $table->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2, 'notnull' => true]);
            // Applies to members without an explicit category
            $table->addColumn('is_default', 'boolean', ['notnull' => true, 'default' => false]);
            $table->addColumn('created_at', 'datetime', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['club_id', 'name'], 'vn_fee_rates_name_idx');
        }

        $memberships = $schema->getTable('verein_memberships');
        if (!$memberships->hasColumn('fee_rate_id')) {
            $memberships->addColumn('fee_rate_id', 'integer', ['notnull' => false]);
        }

        $fees = $schema->getTable('verein_fees');
        if (!$fees->hasColumn('period')) {
            // e.g. '2026' for the annual membership fee; NULL for one-off fees
            $fees->addColumn('period', 'string', ['length' => 20, 'notnull' => false]);
            $fees->addIndex(['club_id', 'period'], 'vn_fees_period_idx');
        }

        return $schema;
    }
}
