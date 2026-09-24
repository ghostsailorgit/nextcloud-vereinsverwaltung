<?php
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\Fee;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Service\FeeService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A fee belongs to one club: another club's fees must be unreachable, and a
 * fee can only be booked against a member of that same club.
 */
class FeeServiceTest extends TestCase {
    private FeeMapper&MockObject $fees;
    private MembershipMapper&MockObject $memberships;
    private FeeService $service;

    protected function setUp(): void {
        $this->fees = $this->createMock(FeeMapper::class);
        $this->memberships = $this->createMock(MembershipMapper::class);
        $this->service = new FeeService($this->fees, $this->memberships);
    }

    private function memberOf(int $memberId, int $clubId): void {
        $this->memberships->method('findByMemberAndClub')->willReturnCallback(
            function (int $m, int $c) use ($memberId, $clubId) {
                if ($m === $memberId && $c === $clubId) {
                    return new Membership();
                }
                throw new DoesNotExistException('not a member');
            }
        );
    }

    public function testFindAllOnlyAsksForTheGivenClub(): void {
        $this->fees->expects($this->once())->method('findByClub')->with(4)->willReturn([]);

        $this->assertSame([], $this->service->findAll(4));
    }

    public function testCreateStoresClubMemberAndAmount(): void {
        $this->memberOf(9, 4);
        $stored = null;
        $this->fees->method('insert')->willReturnCallback(function (Fee $f) use (&$stored) {
            $stored = $f;
            return $f;
        });

        $this->service->create(4, 9, 12.5, 'open', '2026-12-01', 'Jahresbeitrag');

        $this->assertSame(4, $stored->getClubId());
        $this->assertSame(9, $stored->getMemberId());
        $this->assertSame(12.5, $stored->getAmount());
        $this->assertSame('open', $stored->getStatus());
    }

    public function testCreateForSomeoneFromAnotherClubIsRejected(): void {
        $this->memberOf(9, 4);
        $this->fees->expects($this->never())->method('insert');

        $this->expectExceptionMessage('gehört nicht zu diesem Verein');
        $this->service->create(5, 9, 10.0, 'open', '2026-12-01');
    }

    public function testFeeOfAnotherClubIsNotFound(): void {
        $this->fees->method('findInClub')->with(3, 5)->willThrowException(new DoesNotExistException('x'));

        $this->expectExceptionMessage('Fee not found');
        $this->service->find(5, 3);
    }

    public function testUpdateAndDeleteOfAnotherClubsFeeAreImpossible(): void {
        $this->fees->method('findInClub')->willThrowException(new DoesNotExistException('x'));
        $this->fees->expects($this->never())->method('update');
        $this->fees->expects($this->never())->method('delete');

        try {
            $this->service->update(5, 3, 9, 1.0, 'open', '2026-12-01');
            $this->fail('update must fail');
        } catch (\Exception $e) {
            $this->assertSame('Fee not found', $e->getMessage());
        }
        try {
            $this->service->delete(5, 3);
            $this->fail('delete must fail');
        } catch (\Exception $e) {
            $this->assertSame('Fee not found', $e->getMessage());
        }
    }

    public function testUpdateCannotMoveTheFeeToAMemberOfAnotherClub(): void {
        $fee = new Fee();
        $fee->setClubId(4);
        $this->fees->method('findInClub')->willReturn($fee);
        $this->memberOf(9, 4);
        $this->fees->expects($this->never())->method('update');

        $this->expectExceptionMessage('gehört nicht zu diesem Verein');
        $this->service->update(4, 1, 77, 5.0, 'open', '2026-12-01');
    }

    // --- after a SEPA export / due date passed

    public function testMarkPaidAsksTheMapperForThatClubAndTheGivenFees(): void {
        $this->fees->expects($this->once())
            ->method('markPaidInClub')
            ->with(4, [1, 2, 3], $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'))
            ->willReturn(2);

        $this->assertSame(2, $this->service->markPaid(4, [1, 2, 3]));
    }

    public function testMarkPaidWithNoIdsDoesNothing(): void {
        $this->fees->expects($this->never())->method('markPaidInClub');

        $this->assertSame(0, $this->service->markPaid(4, []));
    }

    public function testFlagOverdueUsesTodayAndTheClub(): void {
        $this->fees->expects($this->once())
            ->method('flagOverdueInClub')
            ->with(4, date('Y-m-d'), $this->anything())
            ->willReturn(7);

        $this->assertSame(7, $this->service->flagOverdue(4));
    }
}
