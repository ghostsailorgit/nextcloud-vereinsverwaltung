<?php

namespace OCA\Verein\Service\Export;

// Require TCPDF if available (lib/Service/Export -> 3 levels up to app root)
$tcpdf_file = __DIR__ . '/../../../vendor/tecnickcom/tcpdf/tcpdf.php';
if (file_exists($tcpdf_file)) {
    require_once $tcpdf_file;
}

/**
 * PDF Exporter using TCPDF
 */
class PdfExporter {
    /**
     * Create TCPDF instance with common settings
     *
     * @return TCPDF
     */
    private function createPdf() {
        $this->requireTcpdf();
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Set document properties
        $pdf->SetCreator('Vereins-App');
        $pdf->SetAuthor('Vereins-App');

        // Set margins
        $pdf->SetMargins(15, 25, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Set font
        $pdf->SetFont('helvetica', '', 10);

        // Add first page
        $pdf->AddPage();

        return $pdf;
    }

    /**
     * Export members as PDF
     *
     * @param array $members Array of member objects/arrays
     * @param string $organizationName Organization name for header
     * @return array with keys: content, filename, mimeType
     */
    public function exportMembers(array $members, string $organizationName = 'Vereins-App'): array {
        $pdf = $this->createPdf();

        // Header
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Mitgliederliste', 0, 1, 'C');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, 'Exportiert am: ' . date('d.m.Y H:i:s'), 0, 1, 'R');
        $pdf->Ln(5);

        // Table header
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(200, 220, 255);

        $w = [10, 40, 25, 25, 20, 15, 20, 20];
        $headers = ['ID', 'Name', 'Vorname', 'Ort', 'Rolle', 'Alter', 'Mitgl.-Jahre', 'Status'];

        foreach ($headers as $i => $header) {
            $pdf->Cell($w[$i], 7, $header, 1, 0, 'C', true);
        }
        $pdf->Ln();

        // Table data
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetFillColor(240, 245, 255);

        $fill = false;
        foreach ($members as $member) {
            $m = is_array($member) ? $member : $member->jsonSerialize();

            $status = !empty($m['deceased']) ? 'Verstorben' : (!empty($m['isFormer']) ? 'Ehemalig' : 'Aktiv');

            $pdf->Cell($w[0], 6, (string)($m['id'] ?? ''), 1, 0, 'C', $fill);
            $pdf->Cell($w[1], 6, substr((string)($m['name'] ?? ''), 0, 24), 1, 0, 'L', $fill);
            $pdf->Cell($w[2], 6, substr((string)($m['firstName'] ?? ''), 0, 16), 1, 0, 'L', $fill);
            $pdf->Cell($w[3], 6, substr((string)($m['city'] ?? ''), 0, 16), 1, 0, 'L', $fill);
            $pdf->Cell($w[4], 6, substr((string)($m['role'] ?? ''), 0, 12), 1, 0, 'C', $fill);
            $pdf->Cell($w[5], 6, (string)($m['age'] ?? '-'), 1, 0, 'C', $fill);
            $pdf->Cell($w[6], 6, (string)($m['membershipYears'] ?? '-'), 1, 0, 'C', $fill);
            $pdf->Cell($w[7], 6, $status, 1, 0, 'C', $fill);
            $pdf->Ln();

            $fill = !$fill;
        }

        // Footer with page numbers
        $pdf->SetFont('helvetica', '', 8);
        $pageCount = $pdf->getAliasNbPages();
        $currentPage = $pdf->getAliasNumPage();
        $pdf->Cell(0, 10, 'Seite ' . $currentPage . ' von ' . $pageCount, 0, 0, 'R');

        $content = $pdf->Output('', 'S');

        return [
            'content' => $content,
            'filename' => 'members_' . date('Y-m-d_His') . '.pdf',
            'mimeType' => 'application/pdf',
        ];
    }

    /**
     * Export fees as PDF
     *
     * @param array $fees Array of fee objects/arrays
     * @param string $organizationName Organization name for header
     * @return array with keys: content, filename, mimeType
     */
    public function exportFees(array $fees, string $organizationName = 'Vereins-App'): array {
        $pdf = $this->createPdf();

        // Header
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Gebührenliste', 0, 1, 'C');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, 'Exportiert am: ' . date('d.m.Y H:i:s'), 0, 1, 'R');
        $pdf->Ln(5);

        // Table header
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(200, 220, 255);

        $w = [15, 25, 30, 50, 25, 30];
        $headers = ['ID', 'Mit-ID', 'Betrag', 'Beschreibung', 'Status', 'Fällig am'];

        foreach ($headers as $i => $header) {
            $pdf->Cell($w[$i], 7, $header, 1, 0, 'C', true);
        }
        $pdf->Ln();

        // Table data
        $pdf->SetFont('helvetica', '', 9);
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
                $amount = number_format((float)$amount, 2, ',', '.');
            } else {
                $amount = (string)$amount;
            }

            // Format due date
            if ($dueDate instanceof \DateTime) {
                $dueDate = $dueDate->format('d.m.Y');
            } elseif (is_string($dueDate) && !empty($dueDate)) {
                try {
                    $dueDate = (new \DateTime($dueDate))->format('d.m.Y');
                } catch (\Exception $e) {
                    $dueDate = (string)$dueDate;
                }
            } else {
                $dueDate = '-';
            }

            // Translate status
            $statusLabels = ['open' => 'Offen', 'paid' => 'Bezahlt', 'overdue' => 'Überfällig'];
            $statusLabel = $statusLabels[$status] ?? $status;

            $pdf->Cell($w[0], 6, (string)$id, 1, 0, 'C', $fill);
            $pdf->Cell($w[1], 6, (string)$memberId, 1, 0, 'C', $fill);
            $pdf->Cell($w[2], 6, (string)$amount . ' €', 1, 0, 'R', $fill);
            $pdf->Cell($w[3], 6, substr((string)$description, 0, 28), 1, 0, 'L', $fill);
            $pdf->Cell($w[4], 6, (string)$statusLabel, 1, 0, 'C', $fill);
            $pdf->Cell($w[5], 6, (string)$dueDate, 1, 0, 'C', $fill);
            $pdf->Ln();

            $fill = !$fill;
        }

        // Footer with page numbers
        $pdf->SetFont('helvetica', '', 8);
        $pageCount = $pdf->getAliasNbPages();
        $currentPage = $pdf->getAliasNumPage();
        $pdf->Cell(0, 10, 'Seite ' . $currentPage . ' von ' . $pageCount, 0, 0, 'R');

        $content = $pdf->Output('', 'S');

        return [
            'content' => $content,
            'filename' => 'fees_' . date('Y-m-d_His') . '.pdf',
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
        $pdf->SetCreator('Vereins-App');
        $pdf->SetAuthor($data['club']['name']);
        $pdf->SetTitle('Mahnschreiben');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(25, 20, 20);
        $pdf->SetAutoPageBreak(true, 20);

        $sender = implode(' · ', array_merge([$data['club']['name']], $data['club']['address']));
        $money = fn (float $v): string => number_format($v, 2, ',', '.') . ' €';

        foreach ($data['letters'] as $letter) {
            $pdf->AddPage();

            // sender line and address field of the window envelope
            $pdf->SetXY(20, 45);
            $pdf->SetFont('helvetica', '', 7);
            $pdf->Cell(85, 4, $sender, 'B', 1);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetX(20);
            $pdf->MultiCell(85, 5, implode("\n", $letter['address']), 0, 'L', false, 1);

            $pdf->SetXY(125, 50);
            $pdf->Cell(60, 5, $data['date'], 0, 1, 'R');

            $pdf->SetXY(25, 100);
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 7, $letter['title'] . ' – ' . $data['club']['name'], 0, 1);
            $pdf->Ln(4);

            $pdf->SetFont('helvetica', '', 10);
            $pdf->MultiCell(0, 5, $letter['greeting'], 0, 'L', false, 1);
            $pdf->Ln(2);
            $pdf->MultiCell(0, 5, $this->dunningText((int)$letter['level'], $data['deadline']), 0, 'L', false, 1);
            $pdf->Ln(3);

            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(95, 6, 'Beitrag', 'B', 0);
            $pdf->Cell(35, 6, 'fällig seit', 'B', 0);
            $pdf->Cell(0, 6, 'Betrag', 'B', 1, 'R');
            $pdf->SetFont('helvetica', '', 10);
            foreach ($letter['fees'] as $fee) {
                $pdf->Cell(95, 6, (string)$fee['text'], 0, 0);
                $pdf->Cell(35, 6, (string)$fee['dueDate'], 0, 0);
                $pdf->Cell(0, 6, $money((float)$fee['amount']), 0, 1, 'R');
            }
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(130, 7, 'Offener Betrag', 'T', 0);
            $pdf->Cell(0, 7, $money((float)$letter['total']), 'T', 1, 'R');
            $pdf->Ln(4);

            $pdf->SetFont('helvetica', '', 10);
            if ($data['account'] !== null) {
                $pay = "Bitte überweisen Sie den Betrag bis zum {$data['deadline']} auf das Konto des Vereins:\n"
                    . 'Kontoinhaber: ' . $data['club']['name'] . "\n"
                    . 'IBAN: ' . trim(chunk_split((string)$data['account']['iban'], 4, ' ')) . "\n"
                    . ($data['account']['bic'] !== '' ? 'BIC: ' . $data['account']['bic'] . "\n" : '')
                    . 'Verwendungszweck: ' . $letter['reference'];
            } else {
                $pay = "Bitte begleichen Sie den Betrag bis zum {$data['deadline']}. Die Bankverbindung erfahren Sie beim Vorstand.";
            }
            $pdf->MultiCell(0, 5, $pay, 0, 'L', false, 1);
            $pdf->Ln(3);
            $pdf->MultiCell(0, 5, 'Sollten Sie den Betrag inzwischen überwiesen haben, betrachten Sie dieses Schreiben bitte als gegenstandslos. Bei Fragen wenden Sie sich gern an den Vorstand.', 0, 'L', false, 1);
            $pdf->Ln(6);
            $pdf->MultiCell(0, 5, "Mit freundlichen Grüßen\n\nDer Vorstand\n" . $data['club']['name'], 0, 'L', false, 1);
        }

        return [
            'content' => $pdf->Output('', 'S'),
            'filename' => 'mahnschreiben_' . date('Y-m-d') . '.pdf',
            'mimeType' => 'application/pdf',
        ];
    }

    private function dunningText(int $level, string $deadline): string {
        return match ($level) {
            1 => 'sicher ist es Ihrer Aufmerksamkeit entgangen: Für die folgenden Mitgliedsbeiträge konnten wir noch keinen '
                . 'Zahlungseingang feststellen. Wir bitten Sie, den offenen Betrag bis zum ' . $deadline . ' zu begleichen.',
            2 => 'leider konnten wir trotz unserer Zahlungserinnerung bisher keinen Zahlungseingang für die folgenden '
                . 'Mitgliedsbeiträge feststellen. Bitte begleichen Sie den offenen Betrag bis spätestens ' . $deadline . '.',
            default => 'trotz Zahlungserinnerung und Mahnung sind die folgenden Mitgliedsbeiträge weiterhin offen. Wir bitten '
                . 'Sie letztmalig, den Betrag bis spätestens ' . $deadline . ' zu begleichen. Andernfalls behält sich der '
                . 'Vorstand weitere Schritte nach der Satzung vor.',
        };
    }

    private function requireTcpdf(): void {
        if (!class_exists('TCPDF')) {
            throw new \OCA\Verein\Exception\DependencyMissingException('PDF-Export nicht verfügbar: Die Bibliothek TCPDF fehlt auf dem Server. Der Administrator muss im App-Verzeichnis "composer install --no-dev" ausführen.');
        }
    }
}
