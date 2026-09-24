<?php
namespace OCA\Verein\Tests\Unit;

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

        $this->service = new FeeRateService($this->rates, $this->memberships, $clubs);
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
}
