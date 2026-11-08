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
 * Fees outlive a member's removal from the club; the name is kept with them so the record stays readable.
 */
class Version020013Date20261108010000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_fees.member_name';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $fees = $schema->getTable('verein_fees');
        if (!$fees->hasColumn('member_name')) {
            $fees->addColumn('member_name', 'string', ['length' => 255, 'notnull' => false]);
        }

        return $schema;
    }
}
