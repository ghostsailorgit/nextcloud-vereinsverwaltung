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
 * A fee category can charge by age: the tiers as JSON and the day of the year the age counts on.
 */
class Version020016Date20261108040000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_fee_rates.age_tiers and age_cutoff';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $rates = $schema->getTable('verein_fee_rates');
        if (!$rates->hasColumn('age_tiers')) {
            $rates->addColumn('age_tiers', 'text', ['notnull' => false]);
        }
        if (!$rates->hasColumn('age_cutoff')) {
            $rates->addColumn('age_cutoff', 'string', ['notnull' => false, 'length' => 5]);
        }

        return $schema;
    }
}
