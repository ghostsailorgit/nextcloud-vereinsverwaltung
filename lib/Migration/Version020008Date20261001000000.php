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
 * Marks whether a person's personal data has been anonymized
 * (MemberService::anonymize()). Null = not anonymized.
 */
class Version020008Date20261001000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_members.anonymized_at';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $members = $schema->getTable('verein_members');
        if (!$members->hasColumn('anonymized_at')) {
            $members->addColumn('anonymized_at', 'datetime', ['notnull' => false]);
        }

        return $schema;
    }
}
