<?php

declare(strict_types=1);

namespace OCA\Verein\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Deactivating a member is a property of the membership (one club), not of the
 * person. An earlier draft stored a person-level "locked" flag; that column is
 * dropped if it exists (it was never released, so there is nothing to carry over).
 */
class Version020007Date20260930000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_memberships.deactivated, drop the draft verein_members.locked';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $memberships = $schema->getTable('verein_memberships');
        if (!$memberships->hasColumn('deactivated')) {
            $memberships->addColumn('deactivated', 'boolean', ['notnull' => true, 'default' => false]);
        }

        $members = $schema->getTable('verein_members');
        if ($members->hasColumn('locked')) {
            $members->dropColumn('locked');
        }

        return $schema;
    }
}
