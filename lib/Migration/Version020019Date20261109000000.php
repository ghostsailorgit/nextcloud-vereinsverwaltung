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
 * A one-time admission fee and an own payment text per fee category.
 */
class Version020019Date20261109000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_fee_rates.join_fee and fee_text';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $rates = $schema->getTable('verein_fee_rates');
        if (!$rates->hasColumn('join_fee')) {
            $rates->addColumn('join_fee', 'decimal', ['precision' => 10, 'scale' => 2, 'notnull' => false]);
        }
        if (!$rates->hasColumn('fee_text')) {
            $rates->addColumn('fee_text', 'string', ['notnull' => false, 'length' => 140]);
        }

        return $schema;
    }
}
