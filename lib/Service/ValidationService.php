<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

class ValidationService {
    private IL10N $l;

    public function __construct(?IL10N $l10n = null) {
        $this->l = $l10n ?? new SourceL10n();
    }

    /**
     * Anrede-Optionen, die im Formular angeboten werden.
     */
    public const SALUTATIONS = ['Herr', 'Frau', 'Divers', 'Firma'];

    /**
     * Validiert ein Mitglied auf Pflicht- und Formatfelder.
     *
     * @param array $data Erwartete Schlüssel: name, email, iban, firstName,
     *   salutation, postalCode, birthDate, joinDate, leaveDate
     *   (alle außer name optional)
     * @return array Mit 'valid' (bool) und 'errors' (array)
     */
    public function validateMember(array $data): array {
        $errors = [];

        $name = (string)($data['name'] ?? '');
        $email = (string)($data['email'] ?? '');
        $iban = $data['iban'] ?? null;
        $salutation = $data['salutation'] ?? null;
        $postalCode = $data['postalCode'] ?? null;
        $birthDate = $data['birthDate'] ?? null;
        $joinDate = $data['joinDate'] ?? null;
        $leaveDate = $data['leaveDate'] ?? null;

        // Name validieren
        if (empty(trim($name))) {
            $errors[] = $this->l->t('Name is required');
        } elseif (strlen($name) < 2) {
            $errors[] = $this->l->t('Name must be at least 2 characters long');
        } elseif (strlen($name) > 255) {
            $errors[] = $this->l->t('Name must not be longer than 255 characters');
        }

        // E-Mail ist optional (nicht jedes Mitglied hat eine), aber wenn angegeben, muss sie gültig sein
        if (trim($email) !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $this->l->t('E-mail is invalid');
        }

        // IBAN validieren (wenn angegeben)
        if (!empty($iban) && !$this->validateIBAN($iban)) {
            $errors[] = $this->l->t('IBAN is invalid (e.g. DE89370400440532013000)');
        }

        if (!empty($salutation) && !in_array($salutation, self::SALUTATIONS, true)) {
            $errors[] = $this->l->t('Salutation is invalid');
        }

        if (!empty($postalCode) && strlen((string)$postalCode) > 10) {
            $errors[] = $this->l->t('Postal code must not be longer than 10 characters');
        }

        $birthDateObj = $this->validateOptionalDate($birthDate, $this->l->t('Birth date'), $errors);
        if ($birthDateObj !== null && $birthDateObj > new \DateTime()) {
            $errors[] = $this->l->t('Birth date must not be in the future');
        }

        $joinDateObj = $this->validateOptionalDate($joinDate, $this->l->t('Join date'), $errors);
        $leaveDateObj = $this->validateOptionalDate($leaveDate, $this->l->t('Leave date'), $errors);
        $this->validateOptionalDate($data['mandateDate'] ?? null, $this->l->t('Signature date of the mandate'), $errors);
        $mandateRef = (string)($data['mandateReference'] ?? '');
        if ($mandateRef !== '' && !preg_match('/^[A-Za-z0-9+?\/\-:().,\' ]{1,35}$/', $mandateRef)) {
            $errors[] = $this->l->t('Mandate reference may only contain letters, digits and simple special characters (max. 35 characters)');
        }
        if ($joinDateObj !== null && $leaveDateObj !== null && $leaveDateObj < $joinDateObj) {
            $errors[] = $this->l->t('Leave date must not be before the join date');
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
        ];
    }

    /**
     * Parses an optional Y-m-d date, appending an error for the given label
     * if it's present but malformed. Returns null if absent or invalid.
     */
    private function validateOptionalDate(mixed $value, string $label, array &$errors): ?\DateTime {
        if (empty($value)) {
            return null;
        }
        try {
            return new \DateTime((string)$value);
        } catch (\Exception $e) {
            $errors[] = $this->l->t('%s is invalid', [$label]);
            return null;
        }
    }

    /**
     * Validiert eine Gebühr auf Pflichtfelder
     */
    public function validateFee(
        int $memberId,
        float $amount,
        string $dueDate,
        ?string $description = null
    ): array {
        $errors = [];

        if ($memberId <= 0) {
            $errors[] = $this->l->t('A valid member ID is required');
        }

        if ($amount <= 0) {
            $errors[] = $this->l->t('Amount must be greater than 0');
        } elseif ($amount > 100000) {
            $errors[] = $this->l->t('Amount is too high (max. 100,000)');
        }

        if (empty(trim($dueDate))) {
            $errors[] = $this->l->t('Due date is required');
        } else {
            try {
                new \DateTime($dueDate);
            } catch (\Exception $e) {
                $errors[] = $this->l->t('Due date is invalid');
            }
        }

        if ($description !== null && trim($description) !== '') {
            $length = strlen(trim($description));
            if ($length < 2) {
                $errors[] = $this->l->t('Description must be at least 2 characters long');
            } elseif ($length > 500) {
                $errors[] = $this->l->t('Description must not be longer than 500 characters');
            }
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
        ];
    }

    /**
     * IBAN length per country (ISO 13616 / SEPA + common non-SEPA countries).
     * Only used to reject obviously wrong lengths early; the real
     * correctness check is the Mod-97 checksum below.
     */
    private const IBAN_LENGTHS = [
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21,
        'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18, 'EE' => 20,
        'ES' => 24, 'FI' => 18, 'FR' => 27, 'GB' => 22, 'GR' => 27,
        'HR' => 21, 'HU' => 28, 'IE' => 22, 'IS' => 26, 'IT' => 27,
        'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'MC' => 27,
        'MT' => 31, 'NL' => 18, 'NO' => 15, 'PL' => 28, 'PT' => 25,
        'RO' => 24, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27,
    ];

    /**
     * Validiert eine IBAN (beliebiges SEPA-/ISO-13616-Land, nicht nur DE)
     *
     * @param string $iban
     * @return bool
     */
    public function validateIBAN(string $iban): bool {
        // Leerzeichen entfernen und zu Großbuchstaben
        $iban = str_replace(' ', '', strtoupper($iban));

        if (!preg_match('/^([A-Z]{2})(\d{2})([A-Z0-9]+)$/', $iban, $matches)) {
            return false;
        }
        $country = $matches[1];

        $expectedLength = self::IBAN_LENGTHS[$country] ?? null;
        if ($expectedLength !== null) {
            if (strlen($iban) !== $expectedLength) {
                return false;
            }
        } elseif (strlen($iban) < 15 || strlen($iban) > 34) {
            // Unknown country: fall back to the general IBAN length range
            return false;
        }

        // Optional: IBAN Checksum validieren (Mod-97 Algorithmus)
        return $this->validateIBANChecksum($iban);
    }

    /**
     * Validiert die IBAN Checksum mittels Mod-97 Algorithmus
     *
     * @param string $iban
     * @return bool
     */
    private function validateIBANChecksum(string $iban): bool {
        // IBAN umstrukturieren: BAN + Ländercode + Checksum
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);

        // Buchstaben in Zahlen konvertieren (A=10, B=11, ..., Z=35)
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            if (is_numeric($char)) {
                $numeric .= $char;
            } else {
                $numeric .= (ord($char) - ord('A') + 10);
            }
        }

        // Mod-97 in chunks (no bcmath: Nextcloud does not require that extension)
        $rest = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $rest = (int)($rest . $chunk) % 97;
        }
        return $rest === 1;
    }

    /**
     * Validates a SEPA creditor identifier (Gläubiger-ID), e.g. DE98ZZZ09999999999.
     */
    public function validateCreditorId(string $creditorId): bool {
        $creditorId = str_replace(' ', '', $creditorId);
        return (bool)preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{3}[A-Za-z0-9+?\/\-:().,\']{1,28}$/', $creditorId);
    }

    /**
     * Validiert den Status einer Gebühr
     *
     * @param string $status
     * @return bool
     */
    public function validateFeeStatus(string $status): bool {
        $validStatuses = ['open', 'paid', 'overdue', 'cancelled'];
        return in_array($status, $validStatuses, true);
    }

    /**
     * Validiert die Rolle eines Mitglieds
     *
     * @param string $role
     * @return bool
     */
    public function validateRole(string $role): bool {
        $validRoles = ['member', 'treasurer', 'admin'];
        return in_array($role, $validRoles, true);
    }
}
