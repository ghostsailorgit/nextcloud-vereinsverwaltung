<?php

declare(strict_types=1);

namespace OCA\Verein\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Dunning (Mahnwesen): how often a fee has been dunned and when last (DunningService).
 * 0 = never; 1 = payment reminder, 2 = first dunning letter, 3 = final dunning letter.
 */
class Version020009Date20261002000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_fees.dunning_level and last_dunned_at';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $fees = $schema->getTable('verein_fees');
        if (!$fees->hasColumn('dunning_level')) {
            $fees->addColumn('dunning_level', 'smallint', ['notnull' => true, 'default' => 0, 'unsigned' => true]);
        }
        if (!$fees->hasColumn('last_dunned_at')) {
            $fees->addColumn('last_dunned_at', 'datetime', ['notnull' => false]);
        }

        return $schema;
    }
}
