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
 * How often a fee category is paid. The amount of the category applies to each payment.
 */
class Version020018Date20261108060000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_fee_rates.billing_interval';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $rates = $schema->getTable('verein_fee_rates');
        if (!$rates->hasColumn('billing_interval')) {
            $rates->addColumn('billing_interval', 'string', ['notnull' => true, 'length' => 16, 'default' => 'yearly']);
        }

        return $schema;
    }
}
