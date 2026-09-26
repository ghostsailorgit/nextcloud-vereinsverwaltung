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
 * A Nextcloud account may be linked to at most one person. NULLs (unlinked
 * members) don't collide in a unique index, so only real links are unique.
 */
class Version020003Date20260926000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Unique index on verein_members.user_id (one Nextcloud account per person)';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('verein_members');

        if (!$table->hasIndex('vn_members_user_idx')) {
            $table->addUniqueIndex(['user_id'], 'vn_members_user_idx');
        }

        return $schema;
    }
}
