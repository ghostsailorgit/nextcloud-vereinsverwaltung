<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\L10n\DocumentL10n;
use OCA\Verein\L10n\Formats;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\Export\PdfExporter;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Sends reminder letters by email: one message per person with a short text and the letter as PDF - the same letter
 * that would be printed (PdfExporter), for the fees DunningService::run() has just dunned.
 *
 * The mail server is Nextcloud's own (Administration settings → Basic settings → Email server), used through the
 * public IMailer; the app stores no mail credentials. The message comes from Nextcloud's sender address with the
 * club's sender name, replies go to the club's reply-to address (both in the "Club" tab).
 *
 * Nothing is lost when a message cannot be sent: people without a usable address and failed messages are reported,
 * and their fee ids come back as "print" so the UI offers exactly those letters as PDF.
 */
class DunningMailService {
    private IL10N $l;
    private IL10N $doc;

    public function __construct(
        private DunningService $dunning,
        private PdfExporter $pdf,
        private IMailer $mailer,
        private ClubMapper $clubs,
        private LoggerInterface $logger,
        private ?AuditLogService $auditLog = null,
        ?IL10N $l10n = null,
        ?DocumentL10n $documentL10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
        $this->doc = $documentL10n?->get() ?? $this->l;
    }

    /**
     * @param int[] $feeIds the dunned fees (DunningService::run() 'feeIds')
     * @return array{sent: array, withoutEmail: array, failed: array, printFeeIds: int[]}
     * @throws \OCA\Verein\Exception\ValidationException
     */
    public function send(int $clubId, array $feeIds, int $deadlineDays = 14, ?string $today = null): array {
        $club = $this->clubs->find($clubId);
        $data = $this->dunning->letters($clubId, $feeIds, $deadlineDays, $today);

        $sent = [];
        $withoutEmail = [];
        $failed = [];
        $printFeeIds = [];
        foreach ($data['letters'] as $letter) {
            $who = ['memberId' => $letter['memberId'], 'name' => $letter['name']];
            if ($letter['email'] === '' || !$this->mailer->validateMailAddress($letter['email'])) {
                $withoutEmail[] = $who;
                array_push($printFeeIds, ...$letter['feeIds']);
                continue;
            }
            try {
                $failedRecipients = $this->mailer->send($this->message($club->getName(), $club->getMailSenderName(), $club->getMailReplyTo(), $data, $letter));
                if ($failedRecipients !== []) {
                    throw new \RuntimeException('recipient refused');
                }
                $sent[] = $who;
            } catch (\Throwable $e) {
                // the reason (SMTP answer, host names) goes to the Nextcloud log for the administrator, not to the UI
                $this->logger->warning('Reminder letter email could not be sent', ['app' => 'verein', 'exception' => $e]);
                $failed[] = $who;
                array_push($printFeeIds, ...$letter['feeIds']);
            }
        }

        if ($sent !== [] || $failed !== []) {
            $this->auditLog?->record($clubId, 'fee', 0, 'dunning_email', [
                'sent' => count($sent),
                'failed' => count($failed),
                'withoutEmail' => count($withoutEmail),
            ]);
        }
        return ['sent' => $sent, 'withoutEmail' => $withoutEmail, 'failed' => $failed, 'printFeeIds' => $printFeeIds];
    }

    private function message(string $clubName, ?string $senderName, ?string $replyTo, array $data, array $letter): IMessage {
        $texts = $this->pdf->dunningLetterTexts($data, $letter);
        $subject = $letter['title'] . ' – ' . $clubName;

        $template = $this->mailer->createEMailTemplate('verein.dunning', ['club' => $clubName, 'level' => $letter['level']]);
        $template->setSubject($subject);
        $template->addHeader();
        $template->addHeading($subject);
        // the intro speaks of "the following fees": one line per fee, as in the letter's table
        $feeLines = array_map(fn (array $fee) => $fee['text'] . ' – ' . $this->doc->t('due since') . ' ' . $fee['dueDate']
            . ': ' . Formats::money($this->doc, (float)$fee['amount']), $letter['fees']);
        foreach ([
            $letter['greeting'],
            $texts['intro'],
            implode("\n", $feeLines),
            $this->doc->t('Amount outstanding') . ': ' . Formats::money($this->doc, (float)$letter['total']),
            $texts['pay'],
            $this->doc->t('The letter with all details is attached as a PDF.'),
            $texts['closing'],
            $texts['regards'],
        ] as $paragraph) {
            if ($paragraph === '') {
                continue;
            }
            // the text is escaped here; the line breaks (bank details, closing) are kept in the HTML part too
            $template->addBodyText(nl2br(htmlspecialchars($paragraph, ENT_QUOTES)), $paragraph);
        }
        $template->addFooter($clubName);

        $pdf = $this->pdf->exportDunningLetters(array_merge($data, ['letters' => [$letter]]));
        $message = $this->mailer->createMessage();
        $message->setFrom([$this->senderAddress() => $senderName ?: $clubName]);
        if ($replyTo !== null && $replyTo !== '') {
            $message->setReplyTo([$replyTo]);
        }
        $message->setTo([$letter['email'] => $letter['name']]);
        $message->useTemplate($template);
        $message->attach($this->mailer->createAttachment($pdf['content'], $this->fileName($letter['title']), 'application/pdf'));
        return $message;
    }

    /** Nextcloud's own sender address (mail_from_address@mail_domain), the one its mail server is set up to send as */
    protected function senderAddress(): string {
        return Util::getDefaultEmailAddress('noreply');
    }

    /** "Payment reminder.pdf" / "Zahlungserinnerung.pdf": the title, reduced to characters every mail program keeps */
    private function fileName(string $title): string {
        $name = trim((string)preg_replace('/[^\p{L}\p{N} ._-]+/u', '', $title));
        return ($name !== '' ? $name : 'letter') . '.pdf';
    }
}
