<?php

declare(strict_types=1);

namespace OCA\Verein\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Extends verein_members with the fields needed for a real membership
 * register: member number, salutation, split first/last name, a structured
 * address (street/PLZ/Ort instead of one free-text field), birth/join/leave
 * dates, and founding-member/deceased flags. The old `address` column is
 * left in place (unused going forward) so no existing data is discarded.
 */
class Version020001Date20260923000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add extended member fields (number, salutation, name split, address split, dates, founding/deceased flags)';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('verein_members');

        if (!$table->hasColumn('member_number')) {
            $table->addColumn('member_number', 'string', ['length' => 50, 'notnull' => false]);
        }
        if (!$table->hasColumn('salutation')) {
            $table->addColumn('salutation', 'string', ['length' => 20, 'notnull' => false]);
        }
        if (!$table->hasColumn('first_name')) {
            $table->addColumn('first_name', 'string', ['length' => 255, 'notnull' => false]);
        }
        if (!$table->hasColumn('street')) {
            $table->addColumn('street', 'string', ['length' => 255, 'notnull' => false]);
        }
        if (!$table->hasColumn('postal_code')) {
            $table->addColumn('postal_code', 'string', ['length' => 10, 'notnull' => false]);
        }
        if (!$table->hasColumn('city')) {
            $table->addColumn('city', 'string', ['length' => 255, 'notnull' => false]);
        }
        if (!$table->hasColumn('birth_date')) {
            $table->addColumn('birth_date', 'date', ['notnull' => false]);
        }
        if (!$table->hasColumn('join_date')) {
            $table->addColumn('join_date', 'date', ['notnull' => false]);
        }
        if (!$table->hasColumn('leave_date')) {
            $table->addColumn('leave_date', 'date', ['notnull' => false]);
        }
        if (!$table->hasColumn('founding_member')) {
            $table->addColumn('founding_member', 'boolean', ['notnull' => true, 'default' => false]);
        }
        if (!$table->hasColumn('deceased')) {
            $table->addColumn('deceased', 'boolean', ['notnull' => true, 'default' => false]);
        }

        return $schema;
    }
}
