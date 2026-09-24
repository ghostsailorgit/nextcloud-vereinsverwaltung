<?php
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Fee;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Service\SelfServiceService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SelfServiceServiceTest extends TestCase {
    private MemberMapper&MockObject $members;
    private MembershipMapper&MockObject $memberships;
    private ClubMapper&MockObject $clubs;
    private FeeMapper&MockObject $fees;
    private SelfServiceService $service;

    protected function setUp(): void {
        $this->members = $this->createMock(MemberMapper::class);
        $this->memberships = $this->createMock(MembershipMapper::class);
        $this->clubs = $this->createMock(ClubMapper::class);
        $this->fees = $this->createMock(FeeMapper::class);
        $this->service = new SelfServiceService($this->members, $this->memberships, $this->clubs, $this->fees);
    }

    private function person(): Member {
        $m = new Member();
        $m->setId(5);
        $m->setName('Mustermann');
        $m->setFirstName('Max');
        $m->setBirthDate('1990-01-01');
        $m->setIban('DE89370400440532013000');
        $m->setUserId('maxmuster');
        return $m;
    }

    private function membership(int $clubId, string $role, ?string $leave = null, ?string $mandate = null): Membership {
        $ms = new Membership();
        $ms->setMemberId(5);
        $ms->setClubId($clubId);
        $ms->setRole($role);
        $ms->setJoinDate('2015-03-01');
        $ms->setLeaveDate($leave);
        $ms->setMandateDate($mandate);
        return $ms;
    }

    private function club(int $id, string $name): Club {
        $c = new Club();
        $c->setId($id);
        $c->setName($name);
        return $c;
    }

    public function testAccountWithoutLinkedPersonSeesNothing(): void {
        $this->members->method('findByUserId')->with('stranger')->willReturn(null);
        $this->memberships->expects($this->never())->method('findByMember');

        $this->assertSame(['linked' => false], $this->service->forUser('stranger'));
    }

    public function testShowsOnlyTheLinkedPersonAcrossTheirClubs(): void {
        $this->members->method('findByUserId')->with('maxmuster')->willReturn($this->person());
        $this->memberships->method('findByMember')->with(5)->willReturn([
            $this->membership(1, 'admin', null, '2021-01-05'),
            $this->membership(2, 'member', '2020-12-31'),
        ]);
        $this->clubs->method('find')->willReturnCallback(fn (int $id) => match ($id) {
            1 => $this->club(1, 'Verein A'),
            2 => $this->club(2, 'Verein B'),
            default => throw new DoesNotExistException('x'),
        });
        $this->fees->method('findByMember')->with(5)->willReturn([]);

        $data = $this->service->forUser('maxmuster');

        $this->assertTrue($data['linked']);
        $this->assertSame('Max Mustermann', $data['person']['fullName']);
        $this->assertSame('DE89370400440532013000', $data['person']['iban']);
        $this->assertCount(2, $data['memberships']);

        $a = $data['memberships'][0];
        $this->assertSame('Verein A', $a['club']['name']);
        $this->assertSame('admin', $a['role']);
        $this->assertFalse($a['isFormer']);
        $this->assertSame('2021-01-05', $a['mandate']['date']);
        $this->assertSame('M1-5', $a['mandate']['reference'], 'generated fallback reference');
        $this->assertFalse($a['mandate']['signedCopyOnFile']);

        $b = $data['memberships'][1];
        $this->assertTrue($b['isFormer'], 'left club B');
    }

    public function testFeesAreListedNewestFirstWithTheClubName(): void {
        $this->members->method('findByUserId')->willReturn($this->person());
        $this->memberships->method('findByMember')->willReturn([$this->membership(1, 'member')]);
        $this->clubs->method('find')->willReturn($this->club(1, 'Verein A'));

        $old = new Fee();
        $old->setClubId(1);
        $old->setAmount(10.0);
        $old->setStatus('paid');
        $old->setDueDate('2025-01-01 00:00:00');
        $new = new Fee();
        $new->setClubId(1);
        $new->setAmount(12.0);
        $new->setStatus('open');
        $new->setDueDate('2026-01-01 00:00:00');
        $this->fees->method('findByMember')->willReturn([$old, $new]);

        $fees = $this->service->forUser('maxmuster')['fees'];

        $this->assertSame([12.0, 10.0], array_column($fees, 'amount'));
        $this->assertSame('Verein A', $fees[0]['club']);
    }

    public function testDoesNotLeakInternalFields(): void {
        $this->members->method('findByUserId')->willReturn($this->person());
        $this->memberships->method('findByMember')->willReturn([]);
        $this->fees->method('findByMember')->willReturn([]);

        $person = $this->service->forUser('maxmuster')['person'];

        $this->assertArrayNotHasKey('id', $person);
        $this->assertArrayNotHasKey('userId', $person);
        $this->assertArrayNotHasKey('createdAt', $person);
    }
}
