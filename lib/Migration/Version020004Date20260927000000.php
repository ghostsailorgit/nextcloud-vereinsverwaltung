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
 * Per club: which app role a membership role (Mitglied / Kassierer /
 * Vorstand) grants automatically to members with a linked Nextcloud account.
 * JSON like {"admin": 3, "treasurer": 4}; empty/NULL = nothing automatic.
 */
class Version020004Date20260927000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_clubs.role_mapping (membership role -> app role)';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('verein_clubs');

        if (!$table->hasColumn('role_mapping')) {
            $table->addColumn('role_mapping', 'text', ['notnull' => false]);
        }

        return $schema;
    }
}
