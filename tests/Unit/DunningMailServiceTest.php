<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\AuditLogService;
use OCA\Verein\Service\DunningMailService;
use OCA\Verein\Service\DunningService;
use OCA\Verein\Service\Export\PdfExporter;
use OCP\Mail\IAttachment;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Reminder letters by email: one message per person through Nextcloud's mailer, the club's sender name and reply-to,
 * the letter as PDF - and nobody is lost: without an address or when sending fails, the fees come back for printing.
 */
class DunningMailServiceTest extends TestCase {
    private array $messages = [];
    private array $bodyTexts = [];

    private function letter(int $memberId, string $name, string $email, array $feeIds): array {
        return [
            'memberId' => $memberId, 'name' => $name, 'email' => $email, 'feeIds' => $feeIds, 'level' => 1,
            'title' => 'Zahlungserinnerung', 'address' => [$name], 'greeting' => 'Hallo ' . $name . ',',
            'fees' => [['text' => 'Beitrag 2026', 'dueDate' => '31.03.2026', 'amount' => 60.0]], 'total' => 60.0, 'reference' => 'Beitrag 2026, Mitglied ' . $memberId, 'sortKey' => $name,
        ];
    }

    private function service(?string $replyTo, ?AuditLogService $audit = null): DunningMailService {
        $club = new Club();
        $club->setId(3);
        $club->setName('TSV Musterstadt');
        $club->setMailSenderName('TSV Musterstadt Vorstand');
        $club->setMailReplyTo($replyTo);
        $clubs = $this->createMock(ClubMapper::class);
        $clubs->method('find')->willReturn($club);

        $dunning = $this->createMock(DunningService::class);
        $dunning->method('letters')->willReturn([
            'club' => ['name' => 'TSV Musterstadt', 'address' => []], 'account' => null, 'date' => '29.09.2026', 'deadline' => '13.10.2026',
            'letters' => [
                $this->letter(7, 'Erika <b>Mustermann</b>', 'erika@example.org', [1, 2]),
                $this->letter(8, 'Max Mustermann', '', [3]),
                $this->letter(9, 'Paul Probe', 'kaputt', [4]),
                $this->letter(10, 'Mia Probe', 'mia@example.org', [5]),
            ],
        ]);

        $pdf = $this->createMock(PdfExporter::class);
        $pdf->method('dunningLetterTexts')->willReturn(['intro' => 'Bitte zahlen.', 'pay' => "Konto:\nIBAN DE89", 'closing' => 'Danke.', 'regards' => "Viele Grüße\nDer Vorstand"]);
        $pdf->method('exportDunningLetters')->willReturnCallback(fn (array $data) => [
            'content' => '%PDF ' . count($data['letters']) . ' letter(s) for ' . $data['letters'][0]['memberId'], 'filename' => 'x.pdf', 'mimeType' => 'application/pdf',
        ]);

        $mailer = $this->createMock(IMailer::class);
        $mailer->method('validateMailAddress')->willReturnCallback(fn (string $a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false);
        $mailer->method('createEMailTemplate')->willReturnCallback(function () {
            $template = $this->createMock(IEMailTemplate::class);
            $template->method('addBodyText')->willReturnCallback(function (string $html, $plain = '') {
                $this->bodyTexts[] = [$html, $plain];
            });
            return $template;
        });
        $mailer->method('createAttachment')->willReturnCallback(function ($data, $name, $type) {
            $a = $this->createMock(IAttachment::class);
            $this->messages[count($this->messages) - 1]['attachment'] = [$data, $name, $type];
            return $a;
        });
        $mailer->method('createMessage')->willReturnCallback(function () {
            $record = [];
            $this->messages[] = &$record;
            $m = $this->createMock(IMessage::class);
            foreach (['setFrom' => 'from', 'setReplyTo' => 'replyTo', 'setTo' => 'to'] as $method => $key) {
                $m->method($method)->willReturnCallback(function (array $v) use (&$record, $key, $m) {
                    $record[$key] = $v;
                    return $m;
                });
            }
            $m->method('useTemplate')->willReturn($m);
            $m->method('attach')->willReturn($m);
            return $m;
        });
        $mailer->method('send')->willReturnCallback(function (IMessage $m) {
            $last = $this->messages[count($this->messages) - 1];
            if (isset($last['to']['mia@example.org'])) {
                throw new \RuntimeException('550 relay denied by smtp.example.org');
            }
            return [];
        });

        return new class($dunning, $pdf, $mailer, $clubs, $this->createMock(LoggerInterface::class), $audit, SourceL10n::fromAppLanguage('de')) extends DunningMailService {
            protected function senderAddress(): string {
                return 'noreply@cloud.example.org';
            }
        };
    }

    public function testSendsOneMessagePerPersonAndReturnsTheRestForPrinting(): void {
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('record')->with(3, 'fee', 0, 'dunning_email', ['sent' => 1, 'failed' => 1, 'withoutEmail' => 2]);

        $result = $this->service('vorstand@example.org', $audit)->send(3, [1, 2, 3, 4, 5]);

        $this->assertSame([7], array_column($result['sent'], 'memberId'));
        $this->assertSame([8, 9], array_column($result['withoutEmail'], 'memberId'), 'no address and an unusable one');
        $this->assertSame([10], array_column($result['failed'], 'memberId'));
        $this->assertSame([3, 4, 5], $result['printFeeIds'], 'everyone not reached by email gets a letter to print');
        $this->assertStringNotContainsString('relay', json_encode($result), 'the SMTP answer goes to the log, not to the UI');

        $first = $this->messages[0];
        $this->assertSame(['noreply@cloud.example.org' => 'TSV Musterstadt Vorstand'], $first['from']);
        $this->assertSame(['vorstand@example.org'], $first['replyTo']);
        $this->assertSame(['erika@example.org' => 'Erika <b>Mustermann</b>'], $first['to']);
        $this->assertSame(['%PDF 1 letter(s) for 7', 'Zahlungserinnerung.pdf', 'application/pdf'], $first['attachment'], 'only this person\'s letter');
    }

    public function testTextIsEscapedAndKeepsItsLineBreaks(): void {
        $this->service(null)->send(3, [1]);

        $this->assertArrayNotHasKey('replyTo', $this->messages[0], 'no reply-to set for the club: none on the message');
        [$html, $plain] = $this->bodyTexts[0];
        $this->assertSame('Hallo Erika &lt;b&gt;Mustermann&lt;/b&gt;,', $html);
        $this->assertSame('Hallo Erika <b>Mustermann</b>,', $plain);
        $this->assertContains(["Konto:<br />\nIBAN DE89", "Konto:\nIBAN DE89"], $this->bodyTexts);
        $this->assertContains(['Beitrag 2026 – fällig seit 31.03.2026: 60,00 €', 'Beitrag 2026 – fällig seit 31.03.2026: 60,00 €'],
            array_map(fn ($t) => str_replace("\u{a0}", ' ', $t), $this->bodyTexts), 'the fees the text speaks of');
    }
}
