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
 * A SEPA mandate that exists on paper, and one member paying the fees of others (partner, children) from one account.
 */
class Version020015Date20261108030000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_memberships.mandate_on_paper and payer_member_id';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $memberships = $schema->getTable('verein_memberships');
        if (!$memberships->hasColumn('mandate_on_paper')) {
            $memberships->addColumn('mandate_on_paper', 'boolean', ['notnull' => true, 'default' => false]);
        }
        if (!$memberships->hasColumn('payer_member_id')) {
            $memberships->addColumn('payer_member_id', 'bigint', ['notnull' => false]);
        }

        return $schema;
    }
}
