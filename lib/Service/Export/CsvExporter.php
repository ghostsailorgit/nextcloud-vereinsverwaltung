<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\Verein\Service\Export;

use Exception;

/**
 * CSV Exporter - UTF-8 with BOM for Excel compatibility
 */
class CsvExporter {
    /**
     * Export data as CSV
     *
     * @param array $data Array of arrays to export
     * @param array $headers Column headers
     * @param string $filename Filename for download (without extension)
     * @return array with keys: content, filename, mimeType
     */
    public function export(array $data, array $headers, string $filename): array {
        // UTF-8 BOM for Excel compatibility
        $csv = "\xEF\xBB\xBF"; // UTF-8 BOM

        // Add header row
        $csv .= $this->escapeLine($headers);

        // Add data rows
        foreach ($data as $row) {
            $csv .= $this->escapeLine($row);
        }

        return [
            'content' => $csv,
            'filename' => $filename . '.csv',
            'mimeType' => 'text/csv; charset=utf-8',
        ];
    }

    /**
     * Escape CSV line and return with newline
     *
     * @param array $fields
     * @return string
     */
    private function escapeLine(array $fields): string {
        $line = [];

        foreach ($fields as $field) {
            // Convert to string
            $field = (string)$field;

            // Spreadsheet formula injection: text that Excel/LibreOffice would run as a
            // formula (names, addresses and notes are typed in by members' managers)
            if (preg_match('/^[=+\-@\t\r]/', $field) === 1 && !is_numeric($field)) {
                $field = "'" . $field;
            }

            // Escape quotes by doubling them
            $field = str_replace('"', '""', $field);

            // Wrap in quotes if contains semicolon, newline, or quote
            if (strpos($field, ';') !== false || strpos($field, "\n") !== false || strpos($field, '"') !== false) {
                $field = '"' . $field . '"';
            }

            $line[] = $field;
        }

        // Join with semicolon (common in Europe)
        return implode(';', $line) . "\n";
    }

    /**
     * Format members data for export
     *
     * @param array $members Array of member objects/arrays
     * @return array with headers and formatted data
     */
    public function formatMembers(array $members): array {
        $headers = [
            'ID',
            'Anrede',
            'Vorname',
            'Name',
            'Straße',
            'PLZ',
            'Ort',
            'Email',
            'Rolle',
            'IBAN',
            'BIC',
            'Geburtsdatum',
            'Alter',
            'Eintrittsdatum',
            'Mitglied seit (Jahre)',
            'Austrittsdatum',
            'Gründungsmitglied',
            'Verstorben',
            'Erstellt am',
        ];

        $data = [];
        foreach ($members as $member) {
            $m = is_array($member) ? $member : $member->jsonSerialize();

            $data[] = [
                $m['id'] ?? '',
                $m['salutation'] ?? '',
                $m['firstName'] ?? '',
                $m['name'] ?? '',
                $m['street'] ?? '',
                $m['postalCode'] ?? '',
                $m['city'] ?? '',
                $m['email'] ?? '',
                $m['role'] ?? '',
                $m['iban'] ?? '',
                $m['bic'] ?? '',
                $m['birthDate'] ?? '',
                $m['age'] ?? '',
                $m['joinDate'] ?? '',
                $m['membershipYears'] ?? '',
                $m['leaveDate'] ?? '',
                !empty($m['foundingMember']) ? 'Ja' : 'Nein',
                !empty($m['deceased']) ? 'Ja' : 'Nein',
                $m['createdAt'] ?? ($m['created_at'] ?? ''),
            ];
        }

        return [
            'headers' => $headers,
            'data' => $data,
        ];
    }

    /**
     * Format fees data for export
     *
     * @param array $fees Array of fee objects/arrays
     * @return array with headers and formatted data
     */
    public function formatFees(array $fees): array {
        $headers = [
            'ID',
            'Member ID',
            'Member Name',
            'Amount',
            'Period',
            'Status',
            'Created At',
        ];

        $data = [];
        foreach ($fees as $fee) {
            // Handle both array and object formats
            $id = is_array($fee) ? ($fee['id'] ?? '') : $fee->getId();
            $memberId = is_array($fee) ? ($fee['member_id'] ?? '') : $fee->getMemberId();
            $memberName = is_array($fee) ? ($fee['member_name'] ?? '') : '';
            $amount = is_array($fee) ? ($fee['amount'] ?? '') : $fee->getAmount();
            $period = is_array($fee) ? ($fee['period'] ?? '') : '';
            $status = is_array($fee) ? ($fee['status'] ?? '') : $fee->getStatus();
            $createdAt = is_array($fee) ? ($fee['created_at'] ?? '') : $fee->getCreatedAt();

            $data[] = [
                $id ?? '',
                $memberId ?? '',
                $memberName ?? '',
                $amount ?? '',
                $period ?? '',
                $status ?? '',
                $createdAt ?? '',
            ];
        }

        return [
            'headers' => $headers,
            'data' => $data,
        ];
    }
}
