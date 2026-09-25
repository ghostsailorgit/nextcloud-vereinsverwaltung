<?php
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\AuditLogEntry;
use OCA\Verein\Db\FeeRate;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud's Entity only writes fields that were changed through a setter
 * to the INSERT. A value equal to the property default is not "changed",
 * so a NOT NULL column without a DB default would fail. These tests pin the
 * values that must always be part of an INSERT.
 */
class EntityInsertTest extends TestCase {
    public function testAFeeFreeCategoryStillWritesItsAmount(): void {
        $rate = new FeeRate();
        $rate->setClubId(1);
        $rate->setName('Ehrenmitglied');
        $rate->setAmount(0.0);

        $this->assertContains('amount', array_keys($rate->getUpdatedFields()));
        $this->assertContains('name', array_keys($rate->getUpdatedFields()));
    }

    /**
     * Bulk actions (fee run, mark paid, flag overdue, revoking all roles of a user) log entityId 0.
     * With a property default of 0 the column was left out and MySQL rejected the INSERT - the action
     * itself had already been committed, but the request answered 500 and nothing was logged.
     */
    public function testAnAuditEntryForABulkActionStillWritesEntityIdZero(): void {
        $entry = new AuditLogEntry();
        $entry->setClubId(1);
        $entry->setEntityType('fee_run');
        $entry->setEntityId(0);
        $entry->setAction('create');
        $entry->setCreatedAt('2026-01-01 00:00:00');

        $fields = array_keys($entry->getUpdatedFields());
        foreach (['entityId', 'entityType', 'action', 'createdAt'] as $notNull) {
            $this->assertContains($notNull, $fields, "$notNull must be part of the INSERT");
        }
    }
}
