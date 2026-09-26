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
 * Gives verein_members.email a default of '' instead of none.
 *
 * Nextcloud's Entity::setter() no-ops when a setter is called with the same
 * value the property already has (Member::$email defaults to '' in PHP) -
 * so a brand-new Member whose email is left empty never marks that column
 * dirty, and QBMapper::insert() omits it from the INSERT entirely. Without
 * a column default that's a hard SQL error under strict mode ("Field
 * 'email' doesn't have a default value"), which is exactly what a bulk
 * historical-member import (no email addresses available) hit. Applied
 * live via ALTER TABLE during that import; this migration keeps the schema
 * definition in sync so a fresh install matches production.
 */
class Version020001Date20260924010000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return "Give verein_members.email a default value ('') so entities left at their PHP default insert cleanly";
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('verein_members');
        $table->getColumn('email')->setDefault('');

        return $schema;
    }
}
