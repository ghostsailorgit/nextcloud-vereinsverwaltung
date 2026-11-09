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
 * A member's gender, for the membership statistics sports associations ask for.
 */
class Version020020Date20261109010000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_members.gender';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $members = $schema->getTable('verein_members');
        if (!$members->hasColumn('gender')) {
            $members->addColumn('gender', 'string', ['notnull' => false, 'length' => 1]);
        }

        return $schema;
    }
}
