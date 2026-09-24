<?php
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubAccount;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Fee;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Service\ClubService;
use OCA\Verein\Service\SepaService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SepaServiceTest extends TestCase {
    private const CLUB = 4;

    private FeeMapper&MockObject $fees;
    private MemberMapper&MockObject $members;
    private MembershipMapper&MockObject $memberships;
    private ClubService&MockObject $clubService;
    private SepaService $service;

    /** @var array<int, Member> */
    private array $personsById = [];
    /** @var array<int, Membership> */
    private array $membershipsByMember = [];
    /** @var Fee[] */
    private array $openFees = [];
    private ClubAccount $account;

    protected function setUp(): void {
        $this->fees = $this->createMock(FeeMapper::class);
        $this->members = $this->createMock(MemberMapper::class);
        $this->memberships = $this->createMock(MembershipMapper::class);
        $clubs = $this->createMock(ClubMapper::class);
        $this->clubService = $this->createMock(ClubService::class);

        $club = new Club();
        $club->setId(self::CLUB);
        $club->setName('Musterverein & Söhne');
        $clubs->method('find')->willReturn($club);

        $this->account = new ClubAccount();
        $this->account->setClubId(self::CLUB);
        $this->account->setIban('DE89370400440532013000');
        $this->account->setBic('COBADEFFXXX');
        $this->account->setCreditorId('DE98ZZZ09999999999');
        $this->clubService->method('resolveAccount')->willReturn($this->account);

        $this->fees->method('findByStatusesInClub')->willReturnCallback(fn () => $this->openFees);
        $this->members->method('find')->willReturnCallback(fn (int $id) => $this->personsById[$id]);
        $this->memberships->method('findByMemberAndClub')->willReturnCallback(
            function (int $memberId, int $clubId) {
                return $this->membershipsByMember[$memberId] ?? throw new DoesNotExistException('none');
            }
        );

        $this->service = new SepaService($this->fees, $this->members, $this->memberships, $clubs, $this->clubService);
    }

    private function fee(int $id, int $memberId, float $amount, ?string $description = null): void {
        $fee = new Fee();
        $fee->setId($id);
        $fee->setMemberId($memberId);
        $fee->setAmount($amount);
        $fee->setDueDate('2026-12-01 00:00:00');
        $fee->setDescription($description);
        $this->openFees[] = $fee;
    }

    private function person(int $id, string $first, string $name, ?string $iban, ?string $bic = null): void {
        $m = new Member();
        $m->setId($id);
        $m->setFirstName($first);
        $m->setName($name);
        $m->setIban($iban);
        $m->setBic($bic);
        $this->personsById[$id] = $m;
    }

    private function mandate(int $memberId, ?string $date, ?string $reference = null): void {
        $ms = new Membership();
        $ms->setMemberId($memberId);
        $ms->setClubId(self::CLUB);
        $ms->setMandateDate($date);
        $ms->setMandateReference($reference);
        $this->membershipsByMember[$memberId] = $ms;
    }

    public function testOnlyMembersWithIbanAndMandateAreCollectedTheRestIsReportedWithReason(): void {
        $this->person(1, 'Anna', 'Ok', 'DE02120300000000202051');
        $this->mandate(1, '2021-01-05', 'ANNA-1');
        $this->fee(10, 1, 12.5);
        $this->person(2, 'Bernd', 'OhneIban', null);
        $this->mandate(2, '2021-01-05');
        $this->fee(11, 2, 7.0);
        $this->person(3, 'Carla', 'OhneMandat', 'DE02120300000000202051');
        $this->mandate(3, null);
        $this->fee(12, 3, 9.0);

        $preview = $this->service->previewSepaExport(self::CLUB);

        $this->assertSame(1, $preview['transactionCount']);
        $this->assertSame(12.5, $preview['totalAmount']);
        $this->assertSame('Anna Ok', $preview['transactions'][0]['memberName']);
        $this->assertCount(2, $preview['skipped']);
        $reasons = array_column($preview['skipped'], 'reason', 'memberName');
        $this->assertStringContainsString('IBAN', $reasons['Bernd OhneIban']);
        $this->assertStringContainsString('Mandat', $reasons['Carla OhneMandat']);
    }

    public function testMemberWithoutAnyMembershipInTheClubIsSkipped(): void {
        $this->person(1, 'Anna', 'Fremd', 'DE02120300000000202051');
        $this->fee(10, 1, 5.0); // no membership registered for anna in this club

        $preview = $this->service->previewSepaExport(self::CLUB);

        $this->assertSame(0, $preview['transactionCount']);
        $this->assertCount(1, $preview['skipped']);
    }

    public function testGeneratedXmlCarriesMandateCreditorAndTotals(): void {
        $this->person(1, 'Anna', 'Ok & Co', 'DE02 1203 0000 0000 2020 51');
        $this->mandate(1, '2021-01-05', 'ANNA-1');
        $this->fee(10, 1, 12.5, 'Beitrag 2026');
        $this->person(2, 'Bernd', 'Keiner', null);
        $this->mandate(2, '2021-01-05');
        $this->fee(11, 2, 7.0);

        $result = $this->service->generateSepaXml(self::CLUB);

        $this->assertSame(1, $result['skippedCount']);
        $this->assertSame([10], $result['feeIds'], 'only the fees that are in the file');
        $xml = simplexml_load_string($result['xml']);
        $this->assertNotFalse($xml, 'XML must be well-formed (names with & are escaped)');
        $ns = $xml->getNamespaces(true);
        $doc = $xml->children($ns['']);
        $pmt = $doc->CstmrDrctDbtInitn->PmtInf;

        $this->assertSame('12.50', (string)$doc->CstmrDrctDbtInitn->GrpHdr->CtrlSum);
        $this->assertSame('Musterverein & Söhne', (string)$pmt->Cdtr->Nm);
        $this->assertSame('DE89370400440532013000', (string)$pmt->CdtrAcct->Id->IBAN);
        $this->assertSame('DE98ZZZ09999999999', (string)$pmt->CdtrSchmeId->Id->PrvtId->Othr->Id);

        $tx = $pmt->DrctDbtTxInf;
        $this->assertCount(1, $tx);
        $this->assertSame('ANNA-1', (string)$tx->DrctDbtTx->MndtRltdInf->MndtId);
        $this->assertSame('2021-01-05', (string)$tx->DrctDbtTx->MndtRltdInf->DtOfSgntr);
        $this->assertSame('Anna Ok & Co', (string)$tx->Dbtr->Nm);
        $this->assertSame('DE02120300000000202051', (string)$tx->DbtrAcct->Id->IBAN, 'spaces are stripped from the IBAN');
        $this->assertSame('Beitrag 2026', (string)$tx->RmtInf->Ustrd);
        // no BIC on the member -> IBAN-only marker instead of an empty BIC element
        $this->assertSame('NOTPROVIDED', (string)$tx->DbtrAgt->FinInstnId->Othr->Id);
    }

    public function testMandateReferenceFallsBackToAGeneratedOne(): void {
        $this->person(1, 'Anna', 'Ok', 'DE02120300000000202051');
        $this->mandate(1, '2021-01-05', null);
        $this->fee(10, 1, 5.0);

        $preview = $this->service->previewSepaExport(self::CLUB);

        $this->assertSame('M' . self::CLUB . '-1', $preview['transactions'][0]['mandateReference']);
    }

    public function testExportFailsWithNamesWhenNothingIsExportable(): void {
        $this->person(2, 'Bernd', 'Keiner', null);
        $this->mandate(2, '2021-01-05');
        $this->fee(11, 2, 7.0);

        $this->expectExceptionMessage('Bernd Keiner');
        $this->service->generateSepaXml(self::CLUB);
    }

    public function testExportFailsWhenThereAreNoOpenFeesAtAll(): void {
        $this->expectExceptionMessage('Keine offenen');
        $this->service->generateSepaXml(self::CLUB);
    }

    public function testExportRequiresACreditorId(): void {
        $this->account->setCreditorId('');
        $this->person(1, 'Anna', 'Ok', 'DE02120300000000202051');
        $this->mandate(1, '2021-01-05');
        $this->fee(10, 1, 5.0);

        $this->expectExceptionMessage('Gläubiger-ID');
        $this->service->generateSepaXml(self::CLUB);
    }
}
