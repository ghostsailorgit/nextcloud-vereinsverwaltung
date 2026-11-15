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
 * The club's own member number, per membership. Unique per club is checked by MemberService: a unique index over a
 * nullable column behaves differently across the databases Nextcloud supports.
 */
class Version020024Date20261115000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_memberships.member_number';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $memberships = $schema->getTable('verein_memberships');
        if (!$memberships->hasColumn('member_number')) {
            $memberships->addColumn('member_number', 'string', ['notnull' => false, 'length' => 20]);
            $memberships->addIndex(['club_id', 'member_number'], 'vn_ms_number_idx');
        }

        return $schema;
    }
}
