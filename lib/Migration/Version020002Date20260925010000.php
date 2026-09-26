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
 * Drops the club-specific columns from verein_members - after
 * Version020002Date20260925000000 copied them into verein_memberships they
 * live there, and the member table only holds the person itself.
 */
class Version020002Date20260925010000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Drop role/join_date/leave_date/founding_member from verein_members (moved to verein_memberships)';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('verein_members');

        foreach (['role', 'join_date', 'leave_date', 'founding_member'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }

        return $schema;
    }
}
