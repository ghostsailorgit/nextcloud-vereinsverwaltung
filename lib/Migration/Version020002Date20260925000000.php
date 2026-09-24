<?php

declare(strict_types=1);

namespace OCA\Verein\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Multi-club support. Members (verein_members) become plain "persons" (name,
 * address, birth date, bank account); everything that belongs to one specific
 * club - join/leave date, founding member, role, SEPA mandate - moves to the
 * new verein_memberships table, so the same person can be in several clubs
 * with different join dates. Fees and role assignments get a club_id.
 *
 * All existing data is attached to one automatically created club (named
 * after the previous single-club setup). The now-unused columns on
 * verein_members are dropped by the follow-up migration.
 */
class Version020002Date20260925000000 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db,
        private IConfig $config
    ) {
    }

    #[\Override]
    public function name(): string {
        return 'Add clubs, club bank accounts and memberships; scope fees and role assignments to a club';
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('verein_clubs')) {
            $table = $schema->createTable('verein_clubs');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('name', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('street', 'string', ['length' => 255, 'notnull' => false]);
            $table->addColumn('postal_code', 'string', ['length' => 10, 'notnull' => false]);
            $table->addColumn('city', 'string', ['length' => 255, 'notnull' => false]);
            // Path of the club's team folder in Nextcloud Files (documents tab, mandate PDFs)
            $table->addColumn('documents_path', 'string', ['length' => 500, 'notnull' => false]);
            // JSON list of Nextcloud group ids that get read access to the club calendar
            $table->addColumn('calendar_groups', 'text', ['notnull' => false]);
            $table->addColumn('calendar_uri', 'string', ['length' => 100, 'notnull' => false]);
            $table->addColumn('created_at', 'datetime', ['notnull' => true]);
            $table->addColumn('updated_at', 'datetime', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['name'], 'vn_clubs_name_idx');
        }

        if (!$schema->hasTable('verein_club_accounts')) {
            $table = $schema->createTable('verein_club_accounts');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('club_id', 'integer', ['notnull' => true]);
            $table->addColumn('label', 'string', ['length' => 100, 'notnull' => true, 'default' => '']);
            $table->addColumn('iban', 'string', ['length' => 34, 'notnull' => true]);
            $table->addColumn('bic', 'string', ['length' => 11, 'notnull' => true, 'default' => '']);
            $table->addColumn('creditor_id', 'string', ['length' => 35, 'notnull' => true, 'default' => '']);
            $table->addColumn('is_default', 'boolean', ['notnull' => true, 'default' => false]);
            $table->addColumn('created_at', 'datetime', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['club_id'], 'vn_club_accts_club_idx');
        }

        if (!$schema->hasTable('verein_memberships')) {
            $table = $schema->createTable('verein_memberships');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('member_id', 'integer', ['notnull' => true]);
            $table->addColumn('club_id', 'integer', ['notnull' => true]);
            $table->addColumn('role', 'string', ['length' => 50, 'notnull' => true, 'default' => 'member']);
            $table->addColumn('join_date', 'date', ['notnull' => false]);
            $table->addColumn('leave_date', 'date', ['notnull' => false]);
            $table->addColumn('founding_member', 'boolean', ['notnull' => true, 'default' => false]);
            // SEPA mandate: reference (falls back to a generated one when empty),
            // signature date (no date = no valid mandate = not exported) and the
            // signed PDF's path in Nextcloud Files
            $table->addColumn('mandate_reference', 'string', ['length' => 35, 'notnull' => false]);
            $table->addColumn('mandate_date', 'date', ['notnull' => false]);
            $table->addColumn('mandate_file', 'string', ['length' => 500, 'notnull' => false]);
            $table->addColumn('created_at', 'datetime', ['notnull' => true]);
            $table->addColumn('updated_at', 'datetime', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['member_id', 'club_id'], 'vn_memberships_unique');
            $table->addIndex(['club_id'], 'vn_memberships_club_idx');
        }

        $fees = $schema->getTable('verein_fees');
        if (!$fees->hasColumn('club_id')) {
            $fees->addColumn('club_id', 'integer', ['notnull' => true, 'default' => 0]);
            $fees->addIndex(['club_id'], 'vn_fees_club_idx');
        }

        return $schema;
    }

    #[\Override]
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Idempotent: only migrate once, while there is no club yet
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))->from('verein_clubs');
        if ((int)$qb->executeQuery()->fetchOne() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $documentsPath = $this->config->getAppValue('verein', 'documents_path', '/Verein');
        $qb = $this->db->getQueryBuilder();
        $qb->insert('verein_clubs')->values([
            'name' => $qb->createNamedParameter('Vereinsverwaltung'),
            'documents_path' => $qb->createNamedParameter($documentsPath),
            'calendar_groups' => $qb->createNamedParameter(json_encode([
                'exec-board-read', 'exec-board-write',
                'board-read', 'board-write',
                'members-read', 'members-write',
            ])),
            // The calendar that already exists from before multi-club support
            'calendar_uri' => $qb->createNamedParameter('vereinstermine'),
            'created_at' => $qb->createNamedParameter($now),
        ])->executeStatement();
        $clubId = (int)$this->db->lastInsertId('*PREFIX*verein_clubs');

        $members = $this->db->getQueryBuilder();
        $members->select('id', 'role', 'join_date', 'leave_date', 'founding_member', 'created_at')
            ->from('verein_members');
        $result = $members->executeQuery();
        $count = 0;
        while ($row = $result->fetch()) {
            $ins = $this->db->getQueryBuilder();
            $ins->insert('verein_memberships')->values([
                'member_id' => $ins->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT),
                'club_id' => $ins->createNamedParameter($clubId, IQueryBuilder::PARAM_INT),
                'role' => $ins->createNamedParameter($row['role'] ?: 'member'),
                'join_date' => $ins->createNamedParameter($row['join_date'], $row['join_date'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
                'leave_date' => $ins->createNamedParameter($row['leave_date'], $row['leave_date'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
                'founding_member' => $ins->createNamedParameter((bool)$row['founding_member'], IQueryBuilder::PARAM_BOOL),
                'created_at' => $ins->createNamedParameter($row['created_at'] ?: $now),
            ])->executeStatement();
            $count++;
        }
        $result->closeCursor();

        $upd = $this->db->getQueryBuilder();
        $upd->update('verein_fees')
            ->set('club_id', $upd->createNamedParameter($clubId, IQueryBuilder::PARAM_INT))
            ->where($upd->expr()->eq('club_id', $upd->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        // Role assignments made before multi-club support (club_id 0) belong to the first club
        $upd = $this->db->getQueryBuilder();
        $upd->update('verein_user_roles')
            ->set('club_id', $upd->createNamedParameter($clubId, IQueryBuilder::PARAM_INT))
            ->where($upd->expr()->eq('club_id', $upd->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        $output->info("Created club #$clubId and $count memberships from existing members");
    }
}
