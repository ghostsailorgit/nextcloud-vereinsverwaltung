<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeRate;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\Service\FeeRateService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FeeRateServiceTest extends TestCase {
    private FeeRateMapper&MockObject $rates;
    private MembershipMapper&MockObject $memberships;
    private FeeRateService $service;

    /** @var array<int, FeeRate> */
    private array $store = [];
    private int $nextId = 1;

    protected function setUp(): void {
        $this->rates = $this->createMock(FeeRateMapper::class);
        $this->memberships = $this->createMock(MembershipMapper::class);
        $clubs = $this->createMock(ClubMapper::class);

        $this->rates->method('findByClub')->willReturnCallback(
            fn (int $club) => array_values(array_filter($this->store, fn (FeeRate $r) => $r->getClubId() === $club))
        );
        $this->rates->method('find')->willReturnCallback(
            fn (int $id) => $this->store[$id] ?? throw new DoesNotExistException('none')
        );
        $this->rates->method('insert')->willReturnCallback(function (FeeRate $r) {
            $r->setId($this->nextId++);
            $this->store[$r->getId()] = $r;
            return $r;
        });
        $this->rates->method('update')->willReturnArgument(0);
        $this->rates->method('delete')->willReturnCallback(function (FeeRate $r) {
            unset($this->store[$r->getId()]);
            return $r;
        });

        $this->service = new FeeRateService($this->rates, $this->memberships, $clubs, l10n: SourceL10n::fromAppLanguage('de'));
    }

    public function testTheFirstCategoryBecomesTheDefault(): void {
        $first = $this->service->create(1, ['name' => 'Erwachsene', 'amount' => '24']);
        $second = $this->service->create(1, ['name' => 'Kinder', 'amount' => '8,50']);

        $this->assertTrue($first->getIsDefault());
        $this->assertFalse($second->getIsDefault());
        $this->assertSame(8.5, $second->getAmount(), 'German decimal comma is accepted');
    }

    public function testMakingAnotherCategoryTheDefaultMovesTheFlag(): void {
        $a = $this->service->create(1, ['name' => 'Erwachsene', 'amount' => '24']);
        $b = $this->service->create(1, ['name' => 'Kinder', 'amount' => '8', 'isDefault' => 'true']);

        $this->assertTrue($b->getIsDefault());
        $this->assertFalse($this->store[$a->getId()]->getIsDefault());
    }

    public function testNamesAreUniquePerClubIgnoringCase(): void {
        $this->service->create(1, ['name' => 'Kinder', 'amount' => '8']);

        $this->expectException(ValidationException::class);
        $this->service->create(1, ['name' => 'kinder', 'amount' => '9']);
    }

    public function testTheSameNameIsFineInAnotherClub(): void {
        $this->service->create(1, ['name' => 'Kinder', 'amount' => '8']);
        $other = $this->service->create(2, ['name' => 'Kinder', 'amount' => '5']);

        $this->assertSame(2, $other->getClubId());
    }

    /** @dataProvider badAmounts */
    public function testInvalidAmountsAreRejected(string $amount): void {
        $this->expectException(ValidationException::class);
        $this->service->create(1, ['name' => 'X', 'amount' => $amount]);
    }

    public static function badAmounts(): array {
        return [[''], ['abc'], ['-1'], ['100001']];
    }

    public function testZeroIsAllowedForFeeFreeCategories(): void {
        $rate = $this->service->create(1, ['name' => 'Ehrenmitglied', 'amount' => '0']);

        $this->assertSame(0.0, $rate->getAmount());
    }

    public function testCategoryOfAnotherClubCannotBeChangedOrDeleted(): void {
        $rate = $this->service->create(1, ['name' => 'Kinder', 'amount' => '8']);

        $this->expectException(DoesNotExistException::class);
        $this->service->update(2, $rate->getId(), ['name' => 'Kinder', 'amount' => '1']);
    }

    public function testACategoryInUseCannotBeDeleted(): void {
        $rate = $this->service->create(1, ['name' => 'Kinder', 'amount' => '8']);
        $this->memberships->method('countByFeeRate')->willReturn(3);

        $this->expectExceptionMessage('noch von Mitgliedern verwendet');
        $this->service->delete(1, $rate->getId());
    }

    public function testDeletingTheDefaultPromotesAnotherCategory(): void {
        $a = $this->service->create(1, ['name' => 'Erwachsene', 'amount' => '24']);
        $b = $this->service->create(1, ['name' => 'Kinder', 'amount' => '8']);
        $this->memberships->method('countByFeeRate')->willReturn(0);

        $this->service->delete(1, $a->getId());

        $this->assertTrue($this->store[$b->getId()]->getIsDefault());
    }

    private const TIERS = [['fromAge' => '18', 'amount' => '25,50'], ['fromAge' => 0, 'amount' => '0'], ['fromAge' => '6', 'amount' => 10]];

    public function testAgeTiersAreSortedStoredAndReplaceTheAmount(): void {
        $rate = $this->service->create(1, ['name' => 'Verein', 'amount' => '', 'ageTiers' => self::TIERS, 'ageCutoff' => '']);

        $this->assertSame(0.0, $rate->getAmount());
        $this->assertSame('12-31', $rate->getAgeCutoff(), 'the cut-off defaults to the end of the fee year');
        $this->assertEquals([['fromAge' => 0, 'amount' => 0.0], ['fromAge' => 6, 'amount' => 10.0], ['fromAge' => 18, 'amount' => 25.5]], $rate->jsonSerialize()['ageTiers']);
    }

    public function testAnExistingCategoryCanGoBackToOneAmount(): void {
        $rate = $this->service->create(1, ['name' => 'Verein', 'ageTiers' => self::TIERS, 'ageCutoff' => '06-30']);

        $this->service->update(1, $rate->getId(), ['name' => 'Verein', 'amount' => '30', 'ageTiers' => []]);

        $this->assertSame(30.0, $rate->getAmount());
        $this->assertNull($rate->getAgeTiers());
        $this->assertNull($rate->getAgeCutoff());
    }

    /** @dataProvider invalidAgeSettings */
    public function testInvalidAgeSettingsAreRefused(array $tiers, mixed $cutoff, string $expected): void {
        try {
            $this->service->create(1, ['name' => 'Verein', 'amount' => '1', 'ageTiers' => $tiers, 'ageCutoff' => $cutoff]);
            $this->fail('accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    public static function invalidAgeSettings(): array {
        $ok = [['fromAge' => 0, 'amount' => 1]];
        return [
            'no tier from 0' => [[['fromAge' => 6, 'amount' => 1]], null, '0'],
            'same age twice' => [[['fromAge' => 0, 'amount' => 1], ['fromAge' => '0', 'amount' => 2]], null, 'Altersstufe'],
            'negative age' => [[['fromAge' => -1, 'amount' => 1]], null, 'Altersstufen'],
            'age is text' => [[['fromAge' => 'x', 'amount' => 1]], null, 'Altersstufen'],
            'not a list of tiers' => [['x'], null, 'Altersstufen'],
            'negative amount' => [[['fromAge' => 0, 'amount' => -1]], null, 'Betrag'],
            'missing amount' => [[['fromAge' => 0]], null, 'Betrag'],
            'array as amount' => [[['fromAge' => 0, 'amount' => [1]]], null, 'Betrag'],
            'too many tiers' => [array_map(fn ($a) => ['fromAge' => $a, 'amount' => 1], range(0, 20)), null, 'Altersstufen'],
            'impossible day' => [$ok, '02-30', 'Stichtag'],
            'wrong format' => [$ok, '31.12.', 'Stichtag'],
            'not text' => [$ok, ['12-31'], 'Stichtag'],
        ];
    }

    public function testTheWebInterfaceSendsTheTiersAsJsonText(): void {
        $rate = $this->service->create(1, ['name' => 'Verein', 'amount' => '', 'ageTiers' => '[{"fromAge":0,"amount":"5"},{"fromAge":"18","amount":"25.50"}]', 'ageCutoff' => '06-30']);

        $this->assertEquals([['fromAge' => 0, 'amount' => 5.0], ['fromAge' => 18, 'amount' => 25.5]], $rate->jsonSerialize()['ageTiers']);
    }

    public function testBrokenJsonTextIsRefused(): void {
        $this->expectException(ValidationException::class);
        $this->service->create(1, ['name' => 'Verein', 'amount' => '1', 'ageTiers' => '[object Object]']);
    }

    public function testFebruary29IsAPossibleCutOff(): void {
        $rate = $this->service->create(1, ['name' => 'Verein', 'ageTiers' => [['fromAge' => 0, 'amount' => 1]], 'ageCutoff' => '02-29']);

        $this->assertSame('02-29', $rate->getAgeCutoff());
    }

    public function testACategoryIsYearlyUnlessSaidOtherwise(): void {
        $this->assertSame('yearly', $this->service->create(1, ['name' => 'Jahr', 'amount' => '60'])->getBillingInterval());
        $this->assertSame('monthly', $this->service->create(1, ['name' => 'Monat', 'amount' => '5', 'billingInterval' => 'monthly'])->getBillingInterval());
    }

    public function testAnUpdateWithoutAnIntervalKeepsTheOldOne(): void {
        $rate = $this->service->create(1, ['name' => 'Monat', 'amount' => '5', 'billingInterval' => 'monthly']);

        $this->service->update(1, $rate->getId(), ['name' => 'Monat', 'amount' => '6']);

        $this->assertSame('monthly', $rate->getBillingInterval());
        $this->assertSame('monthly', $rate->jsonSerialize()['billingInterval']);
    }

    /** @dataProvider invalidIntervals */
    public function testAnUnknownIntervalIsRefused(mixed $interval): void {
        try {
            $this->service->create(1, ['name' => 'X', 'amount' => '5', 'billingInterval' => $interval]);
            $this->fail('accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Zahlungsintervall', $e->getMessage());
        }
    }

    public static function invalidIntervals(): array {
        return ['unknown' => ['weekly'], 'array' => [['monthly']], 'number' => [12]];
    }
}
