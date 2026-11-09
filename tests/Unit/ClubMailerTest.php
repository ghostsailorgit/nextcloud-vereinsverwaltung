<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Db\Club;
use OCA\Verein\Service\ClubMailer;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClubMailerTest extends TestCase {
    /** @var array<int, array{0: string, 1: mixed}> */
    private array $bodies = [];

    private function send(?string $logoUrl): bool {
        $template = $this->createMock(IEMailTemplate::class);
        $template->method('addBodyText')->willReturnCallback(function (string $html, $plain = '') {
            $this->bodies[] = [$html, $plain];
        });
        $mailer = $this->createMock(IMailer::class);
        $mailer->method('createEMailTemplate')->willReturn($template);
        $mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
        $mailer->method('send')->willReturn([]);
        $sender = new class($mailer, $this->createMock(LoggerInterface::class)) extends ClubMailer {
            protected function senderAddress(): string {
                return 'noreply@example.org';
            }
        };
        $club = new Club();
        $club->setName('Musterverein');
        $club->setMailLogoUrl($logoUrl);
        return $sender->send($club, 'erika@example.org', 'Erika', 'verein.test', 'Betreff', ['Hallo']);
    }

    public function testWithoutALogoOnlyTheTextIsSent(): void {
        $this->assertTrue($this->send(null));
        $this->assertCount(1, $this->bodies);
    }

    public function testTheLogoComesAfterTheTextInTheHtmlPartOnly(): void {
        $this->assertTrue($this->send('https://example.org/logo.png'));

        $this->assertCount(2, $this->bodies);
        $this->assertSame('Hallo', $this->bodies[0][1]);
        $this->assertStringContainsString('<img src="https://example.org/logo.png"', $this->bodies[1][0]);
        $this->assertStringContainsString('alt="Musterverein"', $this->bodies[1][0]);
        $this->assertFalse($this->bodies[1][1], 'no line in the plain text part');
    }

    public function testTheAddressIsEscaped(): void {
        $this->send('https://example.org/a.png?x="><script>');

        $this->assertStringNotContainsString('<script>', $this->bodies[1][0]);
    }
}
