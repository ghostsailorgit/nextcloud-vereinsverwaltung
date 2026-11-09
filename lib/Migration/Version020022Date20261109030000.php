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
 * The address of the logo shown in the club's emails.
 */
class Version020022Date20261109030000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_clubs.mail_logo_url';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $clubs = $schema->getTable('verein_clubs');
        if (!$clubs->hasColumn('mail_logo_url')) {
            $clubs->addColumn('mail_logo_url', 'string', ['notnull' => false, 'length' => 500]);
        }

        return $schema;
    }
}
