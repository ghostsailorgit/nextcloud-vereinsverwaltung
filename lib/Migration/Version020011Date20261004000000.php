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
 * Lets a membership skip the annual fee for the year it was joined in, instead of only the
 * (year-wide) pro-rata option.
 */
class Version020011Date20261004000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_memberships.fee_exempt_join_year';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $memberships = $schema->getTable('verein_memberships');
        if (!$memberships->hasColumn('fee_exempt_join_year')) {
            $memberships->addColumn('fee_exempt_join_year', 'boolean', ['notnull' => true, 'default' => false]);
        }

        return $schema;
    }
}
