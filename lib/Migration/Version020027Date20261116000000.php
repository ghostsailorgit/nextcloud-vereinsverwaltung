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
 * A member who left at an unknown date (old lists often only say "left").
 */
class Version020027Date20261116000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_memberships.leave_unknown';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $memberships = $schema->getTable('verein_memberships');
        if (!$memberships->hasColumn('leave_unknown')) {
            $memberships->addColumn('leave_unknown', 'boolean', ['notnull' => false, 'default' => false]);
        }

        return $schema;
    }
}
