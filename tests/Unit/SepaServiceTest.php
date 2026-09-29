<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubAccount;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Fee;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\Membership;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Service\Clock;
use OCA\Verein\Service\ClubService;
use OCA\Verein\Service\SepaService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SepaServiceTest extends TestCase {
    private const CLUB = 4;
    /** 2026-04-01 12:00 in Berlin, a Wednesday right before Easter (Good Friday 3 April, Easter Monday 6 April) */
    private const NOW = 1775037600;
    /**
     * The official ISO 20022 schema. Not committed (ISO licence); CI downloads it, locally:
     *   curl -fsSL -o tests/fixtures/pain.008.001.02.xsd https://raw.githubusercontent.com/php-sepa-xml/php-sepa-xml/e5cfc5748cec9b02f7d3ca06012bf150ad3c8c2e/doc/ISO20022/pain/008/001/pain.008.001.02.xsd
     * (sha256 1ce9e574ad74f48e33d956d876bfe32c7aa9cd57aef95941cdbf251b0fc6d9ad, checked in CI)
     */
    private const SCHEMA = __DIR__ . '/../fixtures/pain.008.001.02.xsd';

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

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        $tz = $this->createMock(IDateTimeZone::class);
        $tz->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));

        $this->service = new SepaService($this->fees, $this->members, $this->memberships, $clubs, $this->clubService, new Clock($time, $tz), l10n: SourceL10n::fromAppLanguage('de'));
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

    public function testDeactivatedMembersAreNotCollectedEvenWithIbanAndMandate(): void {
        $this->person(1, 'Anna', 'Ok', 'DE02120300000000202051');
        $this->mandate(1, '2021-01-05');
        $this->fee(10, 1, 12.5);
        $this->person(2, 'Paula', 'Pausiert', 'DE02120300000000202051');
        $this->mandate(2, '2021-01-05');
        $this->membershipsByMember[2]->setDeactivated(true);
        $this->fee(11, 2, 7.0);

        $preview = $this->service->previewSepaExport(self::CLUB);

        $this->assertSame(1, $preview['transactionCount']);
        $this->assertSame(['Paula Pausiert' => 'Mitglied deaktiviert'], array_column($preview['skipped'], 'reason', 'memberName'));
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
        $this->assertNotFalse($xml, 'XML must be well-formed');
        $ns = $xml->getNamespaces(true);
        $doc = $xml->children($ns['']);
        $pmt = $doc->CstmrDrctDbtInitn->PmtInf;

        $this->assertSame('12.50', (string)$doc->CstmrDrctDbtInitn->GrpHdr->CtrlSum);
        $this->assertSame('Musterverein + Soehne', (string)$pmt->Cdtr->Nm, 'SEPA character set: umlauts written out, & -> +');
        $this->assertSame('DE89370400440532013000', (string)$pmt->CdtrAcct->Id->IBAN);
        $this->assertSame('DE98ZZZ09999999999', (string)$pmt->CdtrSchmeId->Id->PrvtId->Othr->Id);

        $tx = $pmt->DrctDbtTxInf;
        $this->assertCount(1, $tx);
        $this->assertSame('ANNA-1', (string)$tx->DrctDbtTx->MndtRltdInf->MndtId);
        $this->assertSame('2021-01-05', (string)$tx->DrctDbtTx->MndtRltdInf->DtOfSgntr);
        $this->assertSame('Anna Ok + Co', (string)$tx->Dbtr->Nm);
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

    private function exportXml(): \DOMDocument {
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($this->service->generateSepaXml(self::CLUB)['xml']));
        return $dom;
    }

    private function value(\DOMDocument $dom, string $path): string {
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02');
        return (string)$xp->evaluate('string(' . $path . ')');
    }

    public function testFileIsValidAgainstTheOfficialSchema(): void {
        if (!is_file(self::SCHEMA)) {
            if (getenv('CI')) {
                $this->fail('SEPA schema missing: ' . self::SCHEMA . ' (see the download step in .github/workflows/tests.yml)');
            }
            $this->markTestSkipped('SEPA schema not downloaded, see the comment on SepaServiceTest::SCHEMA');
        }
        // everything that tends to break a file: umlauts, &, <, emoji, overlong names, spaces and
        // lower case in IBAN/BIC, with and without BIC, several cents that do not add up as floats
        $this->person(1, 'Jürgen', 'Müller-Lüdenscheidt & Söhne <GbR> 🎉 ' . str_repeat('x', 80), 'de02 1203 0000 0000 2020 51', 'cobadeffxxx');
        $this->mandate(1, '2021-01-05', 'ANNA-1');
        $this->fee(10, 1, 0.1, 'Beitrag 2026 – Ermäßigt “Jugend” ' . str_repeat('y', 150));
        $this->person(2, 'Zoë', 'Łukasz', 'DE02120300000000202051');
        $this->mandate(2, '2021-01-05');
        $this->fee(11, 2, 0.2);
        $this->account->setIban('de89 3704 0044 0532 0130 00');
        $this->account->setBic('');

        $dom = $this->exportXml();

        libxml_use_internal_errors(true);
        $valid = $dom->schemaValidate(self::SCHEMA);
        $errors = array_map(fn ($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        $this->assertTrue($valid, implode("\n", $errors));

        $this->assertSame('0.30', $this->value($dom, '//p:GrpHdr/p:CtrlSum'), 'control sum in cents, not a float sum');
        $this->assertSame('Zoe Lukasz', $this->value($dom, '(//p:Dbtr/p:Nm)[2]'));
        $this->assertSame('COBADEFFXXX', $this->value($dom, '(//p:DbtrAgt//p:BIC)[1]'));
        $this->assertSame('DE89370400440532013000', $this->value($dom, '//p:CdtrAcct//p:IBAN'));
        $this->assertSame('SLEV', $this->value($dom, '//p:ChrgBr'));
    }

    public function testNamesAndReferenceUseOnlyTheSepaCharacterSetAndLengths(): void {
        $this->person(1, 'Jürgen', 'Müller & Co <GbR> ' . str_repeat('x', 80), 'DE02120300000000202051');
        $this->mandate(1, '2021-01-05');
        $this->fee(10, 1, 5.0, 'Beitrag “Jugend” 2026 € ' . str_repeat('y', 150));

        $dom = $this->exportXml();

        $name = $this->value($dom, '//p:Dbtr/p:Nm');
        $this->assertStringStartsWith('Juergen Mueller + Co GbR x', $name);
        $this->assertSame(70, strlen($name));
        $ref = $this->value($dom, '//p:Ustrd');
        $this->assertLessThanOrEqual(140, strlen($ref));
        foreach ([$name, $ref, $this->value($dom, '//p:Cdtr/p:Nm')] as $text) {
            $this->assertMatchesRegularExpression("#^[A-Za-z0-9/\-?:().,'+ ]+$#", $text);
        }
    }

    public function testFeesWithDataTheBankWouldRejectAreSkippedWithAReason(): void {
        $this->person(1, 'Anna', 'Ok', 'DE02120300000000202051');
        $this->mandate(1, '2021-01-05');
        $this->fee(10, 1, 5.0);
        $this->person(2, 'Null', 'Betrag', 'DE02120300000000202051');
        $this->mandate(2, '2021-01-05');
        $this->fee(11, 2, 0.0);
        $this->person(3, 'Falsche', 'Iban', 'DE02120300000000202052');
        $this->mandate(3, '2021-01-05');
        $this->fee(12, 3, 5.0);
        $this->person(4, 'Falsche', 'Bic', 'DE02120300000000202051', 'COBA');
        $this->mandate(4, '2021-01-05');
        $this->fee(13, 4, 5.0);
        $this->person(5, 'Spaeter', 'Unterschrieben', 'DE02120300000000202051');
        $this->mandate(5, '2026-04-02');
        $this->fee(14, 5, 5.0);
        $this->person(6, 'Kaputtes', 'Datum', 'DE02120300000000202051');
        $this->mandate(6, '05.01.2021');
        $this->fee(15, 6, 5.0);
        $this->person(7, 'Schlechte', 'Referenz', 'DE02120300000000202051');
        $this->mandate(7, '2021-01-05', 'Mandat//Ä');
        $this->fee(16, 7, 5.0);

        $preview = $this->service->previewSepaExport(self::CLUB);

        $this->assertSame(1, $preview['transactionCount']);
        $this->assertSame([
            'Null Betrag' => 'Betrag ist null oder negativ',
            'Falsche Iban' => 'IBAN ist ungültig',
            'Falsche Bic' => 'BIC ist ungültig',
            'Spaeter Unterschrieben' => 'Unterschriftsdatum des Mandats liegt in der Zukunft',
            'Kaputtes Datum' => 'Unterschriftsdatum des Mandats ist ungültig',
            'Schlechte Referenz' => 'Mandatsreferenz enthält in SEPA unzulässige Zeichen (max. 35 Buchstaben, Ziffern und einfache Sonderzeichen)',
        ], array_column($preview['skipped'], 'reason', 'memberName'));
    }

    public function testMandateSignedTodayIsCollected(): void {
        $this->person(1, 'Anna', 'Ok', 'DE02120300000000202051');
        $this->mandate(1, '2026-04-01 00:00:00');
        $this->fee(10, 1, 5.0);

        $dom = $this->exportXml();

        $this->assertSame('2026-04-01', $this->value($dom, '//p:DtOfSgntr'), 'a stored time is cut off');
    }

    public function testCollectionDateSkipsWeekendsAndTargetHolidays(): void {
        $this->person(1, 'Anna', 'Ok', 'DE02120300000000202051');
        $this->mandate(1, '2021-01-05');
        $this->fee(10, 1, 5.0);

        // Wed 1 April + 5 TARGET days: Thu 2, (Good Friday, weekend, Easter Monday), Tue 7, Wed 8, Thu 9, Fri 10
        $this->assertSame('2026-04-10', $this->service->previewSepaExport(self::CLUB)['collectionDate']);
        $this->assertSame('2026-04-10', $this->value($this->exportXml(), '//p:ReqdColltnDt'));
    }

    public function testMessageIdIsUniquePerFileAndFitsTheSchema(): void {
        $this->person(1, 'Anna', 'Ok', 'DE02120300000000202051');
        $this->mandate(1, '2021-01-05');
        $this->fee(10, 1, 5.0);

        $first = $this->value($this->exportXml(), '//p:MsgId');
        $second = $this->value($this->exportXml(), '//p:MsgId');

        $this->assertNotSame($first, $second, 'two exports in the same second must not share a message id');
        $this->assertStringStartsWith('VEREIN-' . self::CLUB . '-20260401', $first);
        $this->assertLessThanOrEqual(32, strlen($first));
    }

    public function testExportRefusesAnInvalidCreditorAccount(): void {
        $this->person(1, 'Anna', 'Ok', 'DE02120300000000202051');
        $this->mandate(1, '2021-01-05');
        $this->fee(10, 1, 5.0);
        $this->account->setIban('DE89370400440532013001');

        $this->expectExceptionMessage('IBAN des Bankkontos ist ungültig');
        $this->service->generateSepaXml(self::CLUB);
    }
}
