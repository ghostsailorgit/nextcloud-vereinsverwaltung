<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\AuditLogService;
use OCA\Verein\Service\ClubMailer;
use OCA\Verein\Service\SepaPrenotificationService;
use OCA\Verein\Service\SepaService;
use PHPUnit\Framework\TestCase;

/**
 * Advance notice of a direct debit: one email per member, built from exactly the transactions the export would
 * contain (same account and collection date), with what SEPA asks for - amount, date, creditor ID, mandate
 * reference - and only the last digits of the IBAN.
 */
class SepaPrenotificationServiceTest extends TestCase {
    private array $sentMails = [];

    private function member(int $id, string $first, string $email): Member {
        $m = new Member();
        $m->setId($id);
        $m->setFirstName($first);
        $m->setName('Muster');
        $m->setSalutation('Frau');
        $m->setEmail($email);
        return $m;
    }

    private function txn(int $memberId, float $amount, string $reference, string $mandate = 'M3-7'): array {
        return ['memberId' => $memberId, 'memberName' => 'x', 'iban' => 'DE89370400440532013000', 'amount' => $amount,
            'collectionDate' => '2026-04-27', 'mandateReference' => $mandate, 'reference' => $reference, 'feeId' => 1];
    }

    private function service(array $transactions, string $creditorId = 'DE98ZZZ09999999999', ?AuditLogService $audit = null): SepaPrenotificationService {
        $sepa = $this->createMock(SepaService::class);
        $sepa->method('previewSepaExport')->with(3, 1, '2026-04-25')->willReturn([
            'creditorId' => $creditorId, 'collectionDate' => '2026-04-27', 'transactions' => $transactions, 'skipped' => [],
        ]);
        $members = $this->createMock(MemberMapper::class);
        $members->method('findByClub')->willReturn([$this->member(7, 'Erika', 'erika@example.org'), $this->member(8, 'Lena', ''), $this->member(9, 'Mia', 'mia@example.org')]);
        $club = new Club();
        $club->setId(3);
        $club->setName('TSV Musterstadt');
        $clubs = $this->createMock(ClubMapper::class);
        $clubs->method('find')->willReturn($club);
        $mail = $this->createMock(ClubMailer::class);
        $mail->method('isUsable')->willReturnCallback(fn (string $e) => $e !== '');
        $mail->method('send')->willReturnCallback(function (Club $c, string $to, string $name, string $template, string $subject, array $paragraphs) {
            $this->sentMails[$to] = [$subject, $paragraphs];
            return $to !== 'mia@example.org';
        });
        return new SepaPrenotificationService($sepa, $members, $clubs, $mail, $audit, SourceL10n::fromAppLanguage('de'));
    }

    public function testOneNoticePerMemberWithWhatSepaAsksFor(): void {
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('record')->with(3, 'fee', 0, 'sepa_notice', ['sent' => 1, 'failed' => 1, 'withoutEmail' => 1, 'collectionDate' => '2026-04-27']);

        $result = $this->service([
            $this->txn(7, 60.0, 'Beitrag 2026'), $this->txn(7, 12.5, 'Arbeitsdienst 2026'),
            $this->txn(8, 30.0, 'Beitrag 2026', 'M3-8'), $this->txn(9, 30.0, 'Beitrag 2026', 'M3-9'),
        ], audit: $audit)->send(3, 1, '2026-04-25');

        $this->assertSame([7], array_column($result['sent'], 'memberId'));
        $this->assertSame([8], array_column($result['withoutEmail'], 'memberId'), 'listed, so the club can tell them another way');
        $this->assertSame([9], array_column($result['failed'], 'memberId'));
        $this->assertSame('2026-04-27', $result['collectionDate']);

        [$subject, $paragraphs] = $this->sentMails['erika@example.org'];
        $this->assertStringContainsString('TSV Musterstadt', $subject);
        $text = str_replace("\u{a0}", ' ', implode("\n", $paragraphs));
        $this->assertStringContainsString('Liebe Erika,', $text);
        $this->assertStringContainsString('60,00 €', $text);
        $this->assertStringContainsString('12,50 €', $text);
        $this->assertStringContainsString('72,50 €', $text, 'two collections: the total');
        $this->assertStringContainsString('27.04.2026', $text);
        $this->assertStringContainsString('DE98ZZZ09999999999', $text);
        $this->assertStringContainsString('M3-7', $text);
        $this->assertStringContainsString('3000', $text);
        $this->assertStringNotContainsString('DE89370400440532013000', $text, 'never the whole IBAN in an email');
    }

    public function testNeedsACreditorId(): void {
        $this->expectException(ValidationException::class);
        $this->service([$this->txn(7, 60.0, 'Beitrag 2026')], '')->send(3, 1, '2026-04-25');
    }

    public function testNothingToCollectNothingToAnnounce(): void {
        $this->expectException(ValidationException::class);
        $this->service([])->send(3, 1, '2026-04-25');
    }
}
