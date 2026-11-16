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
 * A role assignment limited to some groups (department heads, issue #25).
 */
class Version020029Date20261116180000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_user_roles.group_ids';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('verein_user_roles');
        if (!$table->hasColumn('group_ids')) {
            // JSON list of group ids, null = the whole club
            $table->addColumn('group_ids', 'text', ['notnull' => false]);
        }

        return $schema;
    }
}
