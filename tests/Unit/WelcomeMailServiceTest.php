<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubAccount;
use OCA\Verein\Db\ClubAccountMapper;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeRate;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\Membership;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\AuditLogService;
use OCA\Verein\Service\ClubMailer;
use OCA\Verein\Service\MemberService;
use OCA\Verein\Service\WelcomeMailService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WelcomeMailServiceTest extends TestCase {
    private ClubMailer&MockObject $mail;
    private AuditLogService&MockObject $audit;
    private WelcomeMailService $service;
    private Club $club;
    private Member $member;
    private array $sent = [];
    private bool $sendWorks = true;

    protected function setUp(): void {
        $this->club = new Club();
        $this->club->setId(1);
        $this->club->setName('Musterverein');
        $this->club->setCity('Musterstadt');
        $this->club->setWelcomeSubject('Willkommen {vorname}');
        $this->club->setWelcomeBody("Hallo {firstName},\n\nIBAN {iban} bei {verein}");

        $this->member = new Member();
        $this->member->setId(42);
        $this->member->setFirstName('Erika');
        $this->member->setName('Mustermann');
        $this->member->setSalutation('Frau');
        $this->member->setEmail('erika@example.org');
        $this->member->setIban('DE89 3704 0044 0532 0130 00');
        $this->member->setBirthDate('1980-02-01');
        $ms = new Membership();
        $ms->setMemberId(42);
        $ms->setClubId(1);
        $ms->setJoinDate('2026-03-01 00:00:00');
        $ms->setFeeRateId(7);
        $this->member->setMembership($ms);

        $members = $this->createMock(MemberService::class);
        $members->method('find')->willReturnCallback(fn () => $this->member);
        $clubs = $this->createMock(ClubMapper::class);
        $clubs->method('find')->willReturnCallback(fn () => $this->club);

        $other = new ClubAccount();
        $other->setIban('DE00OTHER');
        $main = new ClubAccount();
        $main->setIban('DE89370400440532013000');
        $main->setBic('COBADEFFXXX');
        $main->setCreditorId('DE98ZZZ09999999999');
        $main->setIsDefault(true);
        $accounts = $this->createMock(ClubAccountMapper::class);
        $accounts->method('findByClub')->willReturn([$other, $main]);

        $rate = new FeeRate();
        $rate->setId(7);
        $rate->setName('Erwachsene');
        $rates = $this->createMock(FeeRateMapper::class);
        $rates->method('findByClub')->willReturn([$rate]);

        $this->mail = $this->createMock(ClubMailer::class);
        $this->mail->method('isUsable')->willReturnCallback(fn (string $e) => str_contains($e, '@'));
        $this->mail->method('send')->willReturnCallback(function ($club, $to, $name, $id, $subject, $paragraphs) {
            $this->sent[] = compact('to', 'name', 'subject', 'paragraphs');
            return $this->sendWorks;
        });
        $this->audit = $this->createMock(AuditLogService::class);
        $this->service = new WelcomeMailService($members, $clubs, $accounts, $rates, $this->mail, $this->audit, SourceL10n::fromAppLanguage('de'));
    }

    public function testTheTextIsFilledInAndSplitIntoParagraphs(): void {
        $this->service->send(1, 42);

        $this->assertCount(1, $this->sent);
        $this->assertSame('erika@example.org', $this->sent[0]['to']);
        $this->assertSame('Willkommen Erika', $this->sent[0]['subject']);
        $this->assertSame(['Hallo Erika,', 'IBAN DE89 … 3000 bei Musterverein'], $this->sent[0]['paragraphs']);
    }

    /** @dataProvider placeholders */
    public function testEveryPlaceholder(string $template, string $expected): void {
        $this->club->setWelcomeBody($template);

        $this->service->send(1, 42);

        $this->assertSame([$expected], $this->sent[0]['paragraphs']);
    }

    public static function placeholders(): array {
        return [
            'title' => ['{anrede}', 'Frau'],
            'name' => ['{name}', 'Erika Mustermann'],
            'upper or lower case' => ['{VORNAME} {Nachname}', 'Erika Mustermann'],
            'join date without the time' => ['{eintritt}', '01.03.2026'],
            'birth date' => ['{birthDate}', '01.02.1980'],
            'number' => ['{mitgliedsnummer}', '42'],
            'fee category' => ['{beitragskategorie}', 'Erwachsene'],
            'club iban in full, from the default account' => ['{vereinsiban} {vereinsbic}', 'DE89370400440532013000 COBADEFFXXX'],
            'creditor id' => ['{glaeubigerid}', 'DE98ZZZ09999999999'],
            'club' => ['{clubCity}', 'Musterstadt'],
            'unknown stays' => ['{unbekannt}', '{unbekannt}'],
        ];
    }

    public function testTheMembersFullIbanIsNeverInTheMail(): void {
        $this->club->setWelcomeBody('{iban} {memberIban} {IBAN}');

        $this->service->send(1, 42);

        $this->assertStringNotContainsString('3704', implode(' ', $this->sent[0]['paragraphs']));
        $this->assertStringNotContainsString('DE89370400', implode(' ', $this->sent[0]['paragraphs']));
    }

    public function testATooShortIbanIsLeftOut(): void {
        $this->assertSame('', WelcomeMailService::shortenIban('DE89'));
        $this->assertSame('', WelcomeMailService::shortenIban(''));
    }

    public function testAValueIsNeverReadAsAPlaceholder(): void {
        $this->member->setFirstName('{iban}');
        $this->club->setWelcomeBody('{vorname}');

        $this->service->send(1, 42);

        $this->assertSame(['{iban}'], $this->sent[0]['paragraphs']);
    }

    public function testTheSubjectStaysOnOneLine(): void {
        $this->member->setFirstName("A\r\nBcc: x@example.org");

        $this->service->send(1, 42);

        $this->assertStringNotContainsString("\n", $this->sent[0]['subject']);
        $this->assertStringNotContainsString("\r", $this->sent[0]['subject']);
    }

    public function testWindowsLineBreaksSeparateParagraphsToo(): void {
        $this->club->setWelcomeBody("Eins\r\n\r\nZwei\r\nnoch zwei");

        $this->service->send(1, 42);

        $this->assertSame(['Eins', "Zwei\nnoch zwei"], $this->sent[0]['paragraphs']);
    }

    public function testNoTextNoMail(): void {
        foreach ([[null, 'x'], ['x', null], ['  ', 'x']] as [$subject, $body]) {
            $this->club->setWelcomeSubject($subject);
            $this->club->setWelcomeBody($body);
            try {
                $this->service->send(1, 42);
                $this->fail('sent');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Text', $e->getMessage());
            }
        }
        $this->assertSame([], $this->sent);
    }

    public function testNoAddressNoMail(): void {
        $this->member->setEmail('');

        try {
            $this->service->send(1, 42);
            $this->fail('sent');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('E-Mail', $e->getMessage());
        }
        $this->assertSame([], $this->sent);
    }

    public function testAFailedDeliveryIsReportedAndNotLogged(): void {
        $this->sendWorks = false;
        $this->audit->expects($this->never())->method('record');

        $this->expectException(ValidationException::class);
        $this->service->send(1, 42);
    }

    public function testASentMailIsLoggedWithoutItsText(): void {
        $this->audit->expects($this->once())->method('record')->with(1, 'member', 42, 'welcome_mail');

        $this->service->send(1, 42);
    }
}
