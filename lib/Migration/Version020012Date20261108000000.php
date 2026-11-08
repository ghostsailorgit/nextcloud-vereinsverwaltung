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
 * A person's phone number (optional, free text).
 */
class Version020012Date20261108000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_members.phone';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $members = $schema->getTable('verein_members');
        if (!$members->hasColumn('phone')) {
            $members->addColumn('phone', 'string', ['length' => 50, 'notnull' => false]);
        }

        return $schema;
    }
}
