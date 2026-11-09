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
 * Fees of a group (a department of the club): the fee category of the group, and which group a fee belongs to.
 */
class Version020023Date20261109040000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_groups.fee_rate_id and verein_fees.group_id';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $groups = $schema->getTable('verein_groups');
        if (!$groups->hasColumn('fee_rate_id')) {
            $groups->addColumn('fee_rate_id', 'integer', ['notnull' => false]);
        }
        $fees = $schema->getTable('verein_fees');
        if (!$fees->hasColumn('group_id')) {
            $fees->addColumn('group_id', 'integer', ['notnull' => false]);
        }

        return $schema;
    }
}
