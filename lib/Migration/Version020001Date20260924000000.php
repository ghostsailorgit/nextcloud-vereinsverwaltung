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
 * Drops verein_members.member_number - the member's database id is used as
 * the member number instead (see Member::jsonSerialize(), which already
 * exposes 'id'), so a separate manually-assigned number was redundant.
 * Safe to drop outright: the column was never populated for any real
 * member.
 */
class Version020001Date20260924000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Drop verein_members.member_number - the id column is used as the member number now';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('verein_members');
        if ($table->hasColumn('member_number')) {
            $table->dropColumn('member_number');
        }

        return $schema;
    }
}
