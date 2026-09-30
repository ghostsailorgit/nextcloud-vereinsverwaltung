<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\Club;
use OCP\Mail\IMailer;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Emails from a club to a member (reminder letters, advance notice of a direct debit), all the same way:
 *
 * - only through Nextcloud's IMailer, i.e. the mail server the Nextcloud administrator set up (Administration settings →
 *   Basic settings → Email server); the app stores no credentials and has no transport of its own,
 * - from Nextcloud's sender address with the club's sender name, replies to the club's reply-to address ("Club" tab),
 * - in Nextcloud's email template, the text escaped, line breaks kept,
 * - a message that cannot be sent is logged for the administrator (SMTP answer, host names) and reported as false -
 *   never as an exception text in a response.
 */
class ClubMailer {
    public function __construct(
        private IMailer $mailer,
        private LoggerInterface $logger
    ) {
    }

    /** an address the mailer would accept */
    public function isUsable(string $email): bool {
        return trim($email) !== '' && $this->mailer->validateMailAddress(trim($email));
    }

    /**
     * @param string[] $paragraphs plain text, one entry per paragraph (empty ones are left out)
     * @param array{content: string, name: string, type: string}|null $attachment
     * @return bool false if the message could not be sent (the reason is in the Nextcloud log)
     */
    public function send(Club $club, string $to, string $toName, string $templateId, string $subject, array $paragraphs, ?array $attachment = null): bool {
        try {
            $template = $this->mailer->createEMailTemplate($templateId, ['club' => $club->getName()]);
            $template->setSubject($subject);
            $template->addHeader();
            $template->addHeading($subject);
            foreach ($paragraphs as $paragraph) {
                if ($paragraph === '') {
                    continue;
                }
                // escaped here; the line breaks (bank details, closing) are kept in the HTML part too
                $template->addBodyText(nl2br(htmlspecialchars($paragraph, ENT_QUOTES)), $paragraph);
            }
            $template->addFooter($club->getName());

            $message = $this->mailer->createMessage();
            $message->setFrom([$this->senderAddress() => $club->getMailSenderName() ?: $club->getName()]);
            $replyTo = (string)$club->getMailReplyTo();
            if ($replyTo !== '') {
                $message->setReplyTo([$replyTo]);
            }
            $message->setTo([trim($to) => $toName]);
            $message->useTemplate($template);
            if ($attachment !== null) {
                $message->attach($this->mailer->createAttachment($attachment['content'], $attachment['name'], $attachment['type']));
            }
            if ($this->mailer->send($message) !== []) {
                throw new \RuntimeException('recipient refused');
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Club email could not be sent', ['app' => 'verein', 'template' => $templateId, 'exception' => $e]);
            return false;
        }
    }

    /** Nextcloud's own sender address (mail_from_address@mail_domain), the one its mail server is set up to send as */
    protected function senderAddress(): string {
        return Util::getDefaultEmailAddress('noreply');
    }
}
