<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\Verein\Service\Export;

use OCA\Verein\L10n\DocumentL10n;
use OCA\Verein\L10n\Formats;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

// Require the bundled TCPDF (lib/Service/Export -> 3 levels up to app root) - unless another app already loaded one:
// declaring the class a second time would be a fatal error on every PDF export.
$tcpdf_file = __DIR__ . '/../../../vendor/tecnickcom/tcpdf/tcpdf.php';
if (!class_exists('TCPDF', false) && file_exists($tcpdf_file)) {
    require_once $tcpdf_file;
}

/**
 * PDF Exporter using TCPDF. The documents are written in the instance's default language (DocumentL10n), messages
 * to the person clicking (TCPDF missing) in theirs.
 */
class PdfExporter {
    /**
     * DejaVu Sans (bundled with TCPDF, embedded as a subset): the built-in Helvetica only has the Latin-1 characters,
     * so names like "Łukasz" or Cyrillic ones came out as "?". Regular and bold only - scripts/build-archive.sh keeps
     * exactly these font files.
     */
    private const FONT = 'dejavusans';

    private IL10N $l;
    private IL10N $doc;

    public function __construct(
        private ?\OCA\Verein\Service\Clock $clock = null,
        ?IL10N $l10n = null,
        ?DocumentL10n $documentL10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
        $this->doc = $documentL10n?->get() ?? $this->l;
    }

    /**
     * Create TCPDF instance with common settings
     *
     * @return TCPDF
     */
    private function createPdf() {
        $this->requireTcpdf();
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Set document properties
        $pdf->SetCreator($this->doc->t('Club Management'));
        $pdf->SetAuthor($this->doc->t('Club Management'));

        // TCPDF's own header and footer default to Helvetica
        $pdf->setHeaderFont([self::FONT, '', 10]);
        $pdf->setFooterFont([self::FONT, '', 8]);

        // Set margins
        $pdf->SetMargins(15, 25, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Set font
        $pdf->SetFont(self::FONT, '', 10);

        // Add first page
        $pdf->AddPage();

        return $pdf;
    }

    /**
     * Export members as PDF
     *
     * @param array $members Array of member objects/arrays
     * @return array with keys: content, filename, mimeType
     */
    public function exportMembers(array $members): array {
        $pdf = $this->createPdf();

        // Header
        $pdf->SetFont(self::FONT, 'B', 16);
        $pdf->Cell(0, 10, $this->doc->t('Member list'), 0, 1, 'C');

        $pdf->SetFont(self::FONT, '', 9);
        $pdf->Cell(0, 5, $this->doc->t('Exported on: %s', [Formats::dateTime($this->doc, \OCA\Verein\Service\Clock::nowOf($this->clock))]), 0, 1, 'R');
        $pdf->Ln(5);

        // Table header
        $pdf->SetFont(self::FONT, 'B', 8);
        $pdf->SetFillColor(200, 220, 255);

        $w = [10, 34, 24, 24, 20, 12, 31, 20];
        $headers = ['ID', $this->doc->t('Name'), $this->doc->t('First name'), $this->doc->t('City'), $this->doc->t('Position'), $this->doc->t('Age'), $this->doc->t('Years of membership'), $this->doc->t('Status')];

        foreach ($headers as $i => $header) {
            $pdf->Cell($w[$i], 7, $header, 1, 0, 'C', true, '', 1);
        }
        $pdf->Ln();

        // Table data
        $pdf->SetFont(self::FONT, '', 9);
        $pdf->SetFillColor(240, 245, 255);

        $roles = ['member' => $this->doc->t('Member'), 'treasurer' => $this->doc->t('Treasurer'), 'admin' => $this->doc->t('Board')];
        $fill = false;
        foreach ($members as $member) {
            $m = is_array($member) ? $member : $member->jsonSerialize();

            $status = !empty($m['deceased']) ? $this->doc->t('Deceased') : (!empty($m['isFormer']) ? $this->doc->t('Former') : $this->doc->t('Active'));

            $pdf->Cell($w[0], 6, (string)($m['id'] ?? ''), 1, 0, 'C', $fill, '', 1);
            $pdf->Cell($w[1], 6, mb_substr((string)($m['name'] ?? ''), 0, 24), 1, 0, 'L', $fill, '', 1);
            $pdf->Cell($w[2], 6, mb_substr((string)($m['firstName'] ?? ''), 0, 16), 1, 0, 'L', $fill, '', 1);
            $pdf->Cell($w[3], 6, mb_substr((string)($m['city'] ?? ''), 0, 16), 1, 0, 'L', $fill, '', 1);
            $pdf->Cell($w[4], 6, mb_substr($roles[$m['role'] ?? ''] ?? (string)($m['role'] ?? ''), 0, 12), 1, 0, 'C', $fill, '', 1);
            $pdf->Cell($w[5], 6, (string)($m['age'] ?? '-'), 1, 0, 'C', $fill, '', 1);
            $pdf->Cell($w[6], 6, (string)($m['membershipYears'] ?? '-'), 1, 0, 'C', $fill, '', 1);
            $pdf->Cell($w[7], 6, $status, 1, 0, 'C', $fill, '', 1);
            $pdf->Ln();

            $fill = !$fill;
        }

        // Footer with page numbers
        $pdf->SetFont(self::FONT, '', 8);
        $pageCount = $pdf->getAliasNbPages();
        $currentPage = $pdf->getAliasNumPage();
        $pdf->Cell(0, 10, $this->doc->t('Page %1$s of %2$s', [$currentPage, $pageCount]), 0, 0, 'R');

        $content = $pdf->Output('', 'S');

        return [
            'content' => $content,
            'filename' => 'members_' . \OCA\Verein\Service\Clock::nowOf($this->clock)->format('Y-m-d_His') . '.pdf',
            'mimeType' => 'application/pdf',
        ];
    }

    /**
     * Export fees as PDF
     *
     * @param array $fees Array of fee objects/arrays
     * @return array with keys: content, filename, mimeType
     */
    public function exportFees(array $fees): array {
        $pdf = $this->createPdf();

        // Header
        $pdf->SetFont(self::FONT, 'B', 16);
        $pdf->Cell(0, 10, $this->doc->t('Fee list'), 0, 1, 'C');

        $pdf->SetFont(self::FONT, '', 9);
        $pdf->Cell(0, 5, $this->doc->t('Exported on: %s', [Formats::dateTime($this->doc, \OCA\Verein\Service\Clock::nowOf($this->clock))]), 0, 1, 'R');
        $pdf->Ln(5);

        // Table header
        $pdf->SetFont(self::FONT, 'B', 8);
        $pdf->SetFillColor(200, 220, 255);

        $w = [15, 25, 30, 50, 25, 30];
        $headers = ['ID', $this->doc->t('Member ID'), $this->doc->t('Amount'), $this->doc->t('Description'), $this->doc->t('Status'), $this->doc->t('Due on')];

        foreach ($headers as $i => $header) {
            $pdf->Cell($w[$i], 7, $header, 1, 0, 'C', true, '', 1);
        }
        $pdf->Ln();

        // Table data
        $pdf->SetFont(self::FONT, '', 9);
        $pdf->SetFillColor(240, 245, 255);

        $fill = false;
        foreach ($fees as $fee) {
            // Handle both array and object formats
            $id = is_array($fee) ? ($fee['id'] ?? '') : $fee->getId();
            $memberId = is_array($fee) ? ($fee['member_id'] ?? $fee['memberId'] ?? '') : $fee->getMemberId();
            $amount = is_array($fee) ? ($fee['amount'] ?? 0) : $fee->getAmount();
            $description = is_array($fee) ? ($fee['description'] ?? '') : ($fee->getDescription() ?? '');
            $status = is_array($fee) ? ($fee['status'] ?? '') : $fee->getStatus();
            $dueDate = is_array($fee) ? ($fee['due_date'] ?? $fee['dueDate'] ?? '') : $fee->getDueDate();

            // Format amount
            if (is_numeric($amount)) {
                $amount = Formats::money($this->doc, (float)$amount);
            } else {
                $amount = (string)$amount;
            }

            // Format due date
            if ($dueDate instanceof \DateTimeInterface) {
                $dueDate = Formats::date($this->doc, $dueDate->format('Y-m-d'));
            } elseif (is_string($dueDate) && !empty($dueDate)) {
                $dueDate = Formats::date($this->doc, $dueDate);
            } else {
                $dueDate = '-';
            }

            // Translate status
            $statusLabels = ['open' => $this->doc->t('Unpaid'), 'paid' => $this->doc->t('Paid'), 'overdue' => $this->doc->t('Overdue'), 'cancelled' => $this->doc->t('Canceled')];
            $statusLabel = $statusLabels[$status] ?? $status;

            $pdf->Cell($w[0], 6, (string)$id, 1, 0, 'C', $fill, '', 1);
            $pdf->Cell($w[1], 6, (string)$memberId, 1, 0, 'C', $fill, '', 1);
            $pdf->Cell($w[2], 6, (string)$amount, 1, 0, 'R', $fill, '', 1);
            $pdf->Cell($w[3], 6, mb_substr((string)$description, 0, 40), 1, 0, 'L', $fill, '', 1);
            $pdf->Cell($w[4], 6, (string)$statusLabel, 1, 0, 'C', $fill, '', 1);
            $pdf->Cell($w[5], 6, (string)$dueDate, 1, 0, 'C', $fill, '', 1);
            $pdf->Ln();

            $fill = !$fill;
        }

        // Footer with page numbers
        $pdf->SetFont(self::FONT, '', 8);
        $pageCount = $pdf->getAliasNbPages();
        $currentPage = $pdf->getAliasNumPage();
        $pdf->Cell(0, 10, $this->doc->t('Page %1$s of %2$s', [$currentPage, $pageCount]), 0, 0, 'R');

        $content = $pdf->Output('', 'S');

        return [
            'content' => $content,
            'filename' => 'fees_' . \OCA\Verein\Service\Clock::nowOf($this->clock)->format('Y-m-d_His') . '.pdf',
            'mimeType' => 'application/pdf',
        ];
    }

    /**
     * One dunning letter per page (DunningService::letters()), laid out for a DIN A4 window envelope
     * (address field 20 mm from the left, 45 mm from the top).
     *
     * @param array $data see DunningService::letters()
     * @return array with keys: content, filename, mimeType
     */
    public function exportDunningLetters(array $data): array {
        $this->requireTcpdf();
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator($this->doc->t('Club Management'));
        $pdf->SetAuthor($data['club']['name']);
        $pdf->SetTitle($this->doc->t('Reminder letters'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(25, 20, 20);
        $pdf->SetAutoPageBreak(true, 20);

        $sender = implode(' · ', array_merge([$data['club']['name']], $data['club']['address']));
        $money = fn (float $v): string => Formats::money($this->doc, $v);

        foreach ($data['letters'] as $letter) {
            $pdf->AddPage();

            // sender line and address field of the window envelope
            $pdf->SetXY(20, 45);
            $pdf->SetFont(self::FONT, '', 7);
            $pdf->Cell(85, 4, $sender, 'B', 1);
            $pdf->SetFont(self::FONT, '', 10);
            $pdf->SetX(20);
            $pdf->MultiCell(85, 5, implode("\n", $letter['address']), 0, 'L', false, 1);

            $pdf->SetXY(125, 50);
            $pdf->Cell(60, 5, $data['date'], 0, 1, 'R');

            $pdf->SetXY(25, 100);
            $pdf->SetFont(self::FONT, 'B', 12);
            $pdf->Cell(0, 7, $letter['title'] . ' – ' . $data['club']['name'], 0, 1);
            $pdf->Ln(4);

            $pdf->SetFont(self::FONT, '', 10);
            $pdf->MultiCell(0, 5, $letter['greeting'], 0, 'L', false, 1);
            $pdf->Ln(2);
            $pdf->MultiCell(0, 5, $this->dunningText((int)$letter['level'], $data['deadline']), 0, 'L', false, 1);
            $pdf->Ln(3);

            $pdf->SetFont(self::FONT, 'B', 10);
            $pdf->Cell(95, 6, $this->doc->t('Fee'), 'B', 0);
            $pdf->Cell(35, 6, $this->doc->t('due since'), 'B', 0);
            $pdf->Cell(0, 6, $this->doc->t('Amount'), 'B', 1, 'R');
            $pdf->SetFont(self::FONT, '', 10);
            foreach ($letter['fees'] as $fee) {
                $pdf->Cell(95, 6, (string)$fee['text'], 0, 0);
                $pdf->Cell(35, 6, (string)$fee['dueDate'], 0, 0);
                $pdf->Cell(0, 6, $money((float)$fee['amount']), 0, 1, 'R');
            }
            $pdf->SetFont(self::FONT, 'B', 10);
            $pdf->Cell(130, 7, $this->doc->t('Amount outstanding'), 'T', 0);
            $pdf->Cell(0, 7, $money((float)$letter['total']), 'T', 1, 'R');
            $pdf->Ln(4);

            $pdf->SetFont(self::FONT, '', 10);
            if ($data['account'] !== null) {
                $pay = $this->doc->t('Please transfer the amount by %s to the club\'s bank account:', [$data['deadline']]) . "\n"
                    . $this->doc->t('Account holder: %s', [$data['club']['name']]) . "\n"
                    . 'IBAN: ' . trim(chunk_split((string)$data['account']['iban'], 4, ' ')) . "\n"
                    . ($data['account']['bic'] !== '' ? 'BIC: ' . $data['account']['bic'] . "\n" : '')
                    . $this->doc->t('Payment reference: %s', [$letter['reference']]);
            } else {
                $pay = $this->doc->t('Please pay the amount by %s. The board can give you the bank details.', [$data['deadline']]);
            }
            $pdf->MultiCell(0, 5, $pay, 0, 'L', false, 1);
            $pdf->Ln(3);
            $pdf->MultiCell(0, 5, $this->doc->t('If you have transferred the amount in the meantime, please disregard this letter. If you have any questions, please contact the board.'), 0, 'L', false, 1);
            $pdf->Ln(6);
            $pdf->MultiCell(0, 5, $this->doc->t('Kind regards

The board
%s', [$data['club']['name']]), 0, 'L', false, 1);
        }

        return [
            'content' => $pdf->Output('', 'S'),
            'filename' => $this->doc->t('reminder-letters_%s.pdf', [\OCA\Verein\Service\Clock::todayOf($this->clock)]),
            'mimeType' => 'application/pdf',
        ];
    }

    private function dunningText(int $level, string $deadline): string {
        return match ($level) {
            1 => $this->doc->t('Perhaps this has escaped your attention: we have not yet received payment for the following membership fees. Please pay the outstanding amount by %s.', [$deadline]),
            2 => $this->doc->t('Unfortunately, despite our payment reminder, we have not yet received payment for the following membership fees. Please pay the outstanding amount by %s at the latest.', [$deadline]),
            default => $this->doc->t('Despite a payment reminder and a second reminder, the following membership fees are still outstanding. This is our final request to pay the amount by %s at the latest. Otherwise the board reserves the right to take further steps in accordance with the club\'s statutes.', [$deadline]),
        };
    }

    private function requireTcpdf(): void {
        if (!class_exists('TCPDF')) {
            throw new \OCA\Verein\Exception\DependencyMissingException($this->l->t('PDF export is not available: the TCPDF library is missing on the server. Please ask the administrator to install the app from the release archive or to run %s in the app folder.', ['"composer install --no-dev"']));
        }
    }
}
