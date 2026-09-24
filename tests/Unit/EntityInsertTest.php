<?php
namespace OCA\Verein\Tests\Unit;

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
}
