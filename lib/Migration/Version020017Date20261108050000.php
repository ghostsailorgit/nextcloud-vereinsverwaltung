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
 * Groups inside a club (e.g. training groups) and each member's periods in them.
 */
class Version020017Date20261108050000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_groups and verein_group_members';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('verein_groups')) {
            $table = $schema->createTable('verein_groups');
            $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('club_id', 'bigint', ['notnull' => true]);
            $table->addColumn('name', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('description', 'string', ['notnull' => false, 'length' => 255]);
            $table->addColumn('created_at', 'string', ['notnull' => false, 'length' => 19]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['club_id'], 'verein_groups_club');
        }

        if (!$schema->hasTable('verein_group_members')) {
            $table = $schema->createTable('verein_group_members');
            $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('club_id', 'bigint', ['notnull' => true]);
            $table->addColumn('group_id', 'bigint', ['notnull' => true]);
            $table->addColumn('member_id', 'bigint', ['notnull' => true]);
            $table->addColumn('joined_on', 'string', ['notnull' => true, 'length' => 10]);
            $table->addColumn('left_on', 'string', ['notnull' => false, 'length' => 10]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['club_id'], 'verein_group_mem_club');
            $table->addIndex(['group_id'], 'verein_group_mem_group');
            $table->addIndex(['member_id'], 'verein_group_mem_member');
        }

        return $schema;
    }
}
