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
 * Reminder letters by email: the sender name and the reply-to address a club's emails carry. The mail server itself
 * is Nextcloud's (administration settings), the app stores no mail credentials.
 */
class Version020010Date20261003000000 extends SimpleMigrationStep {
    #[\Override]
    public function name(): string {
        return 'Add verein_clubs.mail_sender_name and mail_reply_to';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $clubs = $schema->getTable('verein_clubs');
        if (!$clubs->hasColumn('mail_sender_name')) {
            $clubs->addColumn('mail_sender_name', 'string', ['notnull' => false, 'length' => 100]);
        }
        if (!$clubs->hasColumn('mail_reply_to')) {
            $clubs->addColumn('mail_reply_to', 'string', ['notnull' => false, 'length' => 254]);
        }

        return $schema;
    }
}
