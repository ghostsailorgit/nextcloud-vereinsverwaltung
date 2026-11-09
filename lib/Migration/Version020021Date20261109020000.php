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
 * The club's own text for the membership confirmation by email.
 */
class Version020021Date20261109020000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_clubs.welcome_subject and welcome_body';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $clubs = $schema->getTable('verein_clubs');
        if (!$clubs->hasColumn('welcome_subject')) {
            $clubs->addColumn('welcome_subject', 'string', ['notnull' => false, 'length' => 200]);
        }
        if (!$clubs->hasColumn('welcome_body')) {
            $clubs->addColumn('welcome_body', 'text', ['notnull' => false]);
        }

        return $schema;
    }
}
