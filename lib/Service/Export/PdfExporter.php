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
        if (!class_exists('TCPDF')) {
            throw new \RuntimeException('TCPDF library not available on server. Ensure vendor dependencies are deployed.');
        }
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
}
