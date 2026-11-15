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
 * A member's own fee category in a group (e.g. passive, spouse free), instead of the group's.
 */
class Version020025Date20261115010000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_group_members.fee_rate_id';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $periods = $schema->getTable('verein_group_members');
        if (!$periods->hasColumn('fee_rate_id')) {
            $periods->addColumn('fee_rate_id', 'integer', ['notnull' => false]);
        }

        return $schema;
    }
}
