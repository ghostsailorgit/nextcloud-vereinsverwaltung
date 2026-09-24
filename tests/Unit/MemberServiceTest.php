<?php
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Service\MemberService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MemberServiceTest extends TestCase {
    private MemberMapper&MockObject $members;
    private MembershipMapper&MockObject $memberships;
    private FeeMapper&MockObject $fees;
    private ClubMapper&MockObject $clubs;
    private MemberService $service;

    protected function setUp(): void {
        $this->members = $this->createMock(MemberMapper::class);
        $this->memberships = $this->createMock(MembershipMapper::class);
        $this->fees = $this->createMock(FeeMapper::class);
        $this->clubs = $this->createMock(ClubMapper::class);
        $this->clubs->method('find')->willReturnCallback(function (int $id) {
            $club = new Club();
            $club->setId($id);
            return $club;
        });

        $this->service = new MemberService($this->members, $this->memberships, $this->fees, $this->clubs, null);
    }

    private function person(int $id, string $name = 'Mustermann'): Member {
        $m = new Member();
        $m->setId($id);
        $m->setName($name);
        return $m;
    }

    private function membership(int $memberId, int $clubId): Membership {
        $ms = new Membership();
        $ms->setId($memberId * 100 + $clubId);
        $ms->setMemberId($memberId);
        $ms->setClubId($clubId);
        return $ms;
    }

    public function testFindTranslatesMissingMembershipToNotFound(): void {
        $this->members->method('findInClub')->willThrowException(new DoesNotExistException('x'));

        $this->expectExceptionMessage('Member not found');
        $this->service->find(1, 5);
    }

    public function testCreateInsertsPersonAndMembershipInTheGivenClub(): void {
        $this->members->method('insert')->willReturnCallback(function (Member $m) {
            $m->setId(55);
            return $m;
        });
        $inserted = null;
        $this->memberships->method('insert')->willReturnCallback(function (Membership $ms) use (&$inserted) {
            $inserted = $ms;
            return $ms;
        });

        $member = $this->service->create(3, [
            'name' => 'Muster',
            'firstName' => 'Max',
            'email' => '',
            'joinDate' => '2020-05-01',
            'role' => 'treasurer',
            'foundingMember' => 'true',
            'mandateDate' => '2021-01-05',
            'mandateReference' => ' REF-1 ',
        ]);

        $this->assertSame(55, $member->getId());
        $this->assertSame(55, $inserted->getMemberId());
        $this->assertSame(3, $inserted->getClubId());
        $this->assertSame('treasurer', $inserted->getRole());
        $this->assertSame('2020-05-01', $inserted->getJoinDate());
        $this->assertTrue($inserted->getFoundingMember());
        $this->assertSame('2021-01-05', $inserted->getMandateDate());
        $this->assertSame('REF-1', $inserted->getMandateReference());
        // club data is readable through the member
        $this->assertSame('2020-05-01', $member->getJoinDate());
        $this->assertSame('treasurer', $member->getRole());
    }

    public function testEmptyOptionalFieldsBecomeNull(): void {
        $this->members->method('insert')->willReturnCallback(function (Member $m) {
            $m->setId(1);
            return $m;
        });
        $this->memberships->method('insert')->willReturnArgument(0);

        $member = $this->service->create(1, ['name' => 'Muster', 'street' => '  ', 'iban' => '', 'mandateFile' => '']);

        $this->assertNull($member->getStreet());
        $this->assertNull($member->getIban());
        $this->assertNull($member->getMembership()->getMandateFile());
    }

    public function testRemoveKeepsThePersonWhileAnotherClubStillHasThem(): void {
        $member = $this->person(8);
        $member->setMembership($this->membership(8, 1));
        $this->members->method('findInClub')->willReturn($member);
        $this->memberships->method('findByMember')->with(8)->willReturn([$this->membership(8, 2)]);

        $this->fees->expects($this->once())->method('deleteByMemberInClub')->with(8, 1);
        $this->memberships->expects($this->once())->method('delete')->with($member->getMembership());
        $this->members->expects($this->never())->method('delete');

        $this->service->remove(1, 8);
    }

    public function testRemoveDeletesThePersonWhenNoClubIsLeft(): void {
        $member = $this->person(8);
        $member->setMembership($this->membership(8, 1));
        $this->members->method('findInClub')->willReturn($member);
        $this->memberships->method('findByMember')->willReturn([]);

        $this->members->expects($this->once())->method('delete')->with($member);

        $this->service->remove(1, 8);
    }

    public function testAttachingSomeoneAlreadyInTheClubIsRejected(): void {
        $this->members->method('find')->willReturn($this->person(8));
        $this->memberships->method('findByMemberAndClub')->willReturn($this->membership(8, 1));
        $this->memberships->expects($this->never())->method('insert');

        $this->expectExceptionMessage('bereits Mitglied');
        $this->service->attachExisting(1, 8, []);
    }

    public function testAttachCreatesOnlyAMembership(): void {
        $this->members->method('find')->willReturn($this->person(8));
        $this->memberships->method('findByMemberAndClub')->willThrowException(new DoesNotExistException('x'));
        $this->memberships->method('insert')->willReturnArgument(0);
        $this->members->expects($this->never())->method('insert');
        $this->members->expects($this->never())->method('update');

        $member = $this->service->attachExisting(2, 8, ['joinDate' => '2019-03-04']);

        $this->assertSame(2, $member->getMembership()->getClubId());
        $this->assertSame('2019-03-04', $member->getJoinDate());
    }

    public function testLookupNeverOffersThePersonsOfTheOwnClubsAsCandidatesSource(): void {
        $this->members->expects($this->once())
            ->method('searchInOtherClubs')
            ->with('Muster', [2, 3], 1)
            ->willReturn([]);

        // club 1 is the target club and must be removed from the searched clubs
        $this->service->lookupInOtherClubs(1, 'Muster', [1, 2, 3]);
    }

    public function testLookupWithBlankQueryReturnsNothing(): void {
        $this->members->expects($this->never())->method('searchInOtherClubs');

        $this->assertSame([], $this->service->lookupInOtherClubs(1, '   ', [2]));
    }

    public function testIsMemberOfAny(): void {
        $this->memberships->method('findByMember')->with(8)->willReturn([$this->membership(8, 2)]);

        $this->assertTrue($this->service->isMemberOfAny(8, [2, 5]));
        $this->assertFalse($this->service->isMemberOfAny(8, [5, 6]));
        $this->assertFalse($this->service->isMemberOfAny(8, []));
    }

    public function testFormerMembershipComesFromTheLeaveDateOfThatClub(): void {
        $member = $this->person(8);
        $ms = $this->membership(8, 1);
        $ms->setLeaveDate('2020-01-01');
        $member->setMembership($ms);

        $this->assertTrue($member->isFormer());

        $other = clone $member;
        $active = $this->membership(8, 2);
        $other->setMembership($active);
        $this->assertFalse($other->isFormer());
    }
}
