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
 * Honorary members pay no annual fee unless they choose to keep paying.
 */
class Version020014Date20261108020000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_memberships.honorary_member and honorary_pays_fee';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $memberships = $schema->getTable('verein_memberships');
        foreach (['honorary_member', 'honorary_pays_fee'] as $column) {
            if (!$memberships->hasColumn($column)) {
                $memberships->addColumn($column, 'boolean', ['notnull' => true, 'default' => false]);
            }
        }

        return $schema;
    }
}
