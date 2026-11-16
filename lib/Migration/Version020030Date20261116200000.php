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
 * Offices (1st chair, secretary, ...): the list a club defines and the office of a membership.
 */
class Version020030Date20261116200000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_clubs.offices and verein_memberships.office';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $memberships = $schema->getTable('verein_memberships');
        if (!$memberships->hasColumn('office')) {
            $memberships->addColumn('office', 'string', ['notnull' => false, 'length' => 100]);
        }
        $clubs = $schema->getTable('verein_clubs');
        if (!$clubs->hasColumn('offices')) {
            // JSON list of the office names
            $clubs->addColumn('offices', 'text', ['notnull' => false]);
        }

        return $schema;
    }
}
