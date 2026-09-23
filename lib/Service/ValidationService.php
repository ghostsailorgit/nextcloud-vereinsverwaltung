<?php
declare(strict_types=1);

namespace OCA\Verein\Service;

class ValidationService {
    /**
     * Anrede-Optionen, die im Formular angeboten werden.
     */
    public const SALUTATIONS = ['Herr', 'Frau', 'Divers', 'Firma'];

    /**
     * Validiert ein Mitglied auf Pflicht- und Formatfelder.
     *
     * @param array $data Erwartete Schlüssel: name, email, iban, firstName,
     *   salutation, postalCode, birthDate, joinDate, leaveDate
     *   (alle außer name/email optional)
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
            $errors[] = 'Name ist erforderlich';
        } elseif (strlen($name) < 2) {
            $errors[] = 'Name muss mindestens 2 Zeichen lang sein';
        } elseif (strlen($name) > 255) {
            $errors[] = 'Name darf maximal 255 Zeichen lang sein';
        }

        // Email validieren
        if (empty(trim($email))) {
            $errors[] = 'E-Mail ist erforderlich';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'E-Mail ist ungültig';
        }

        // IBAN validieren (wenn angegeben)
        if (!empty($iban) && !$this->validateIBAN($iban)) {
            $errors[] = 'IBAN ist ungültig (z.B. DE89370400440532013000)';
        }

        if (!empty($salutation) && !in_array($salutation, self::SALUTATIONS, true)) {
            $errors[] = 'Anrede ist ungültig';
        }

        if (!empty($postalCode) && strlen((string)$postalCode) > 10) {
            $errors[] = 'Postleitzahl darf maximal 10 Zeichen lang sein';
        }

        $birthDateObj = $this->validateOptionalDate($birthDate, 'Geburtsdatum', $errors);
        if ($birthDateObj !== null && $birthDateObj > new \DateTime()) {
            $errors[] = 'Geburtsdatum darf nicht in der Zukunft liegen';
        }

        $joinDateObj = $this->validateOptionalDate($joinDate, 'Eintrittsdatum', $errors);
        $leaveDateObj = $this->validateOptionalDate($leaveDate, 'Austrittsdatum', $errors);
        if ($joinDateObj !== null && $leaveDateObj !== null && $leaveDateObj < $joinDateObj) {
            $errors[] = 'Austrittsdatum darf nicht vor dem Eintrittsdatum liegen';
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
            $errors[] = $label . ' ist ungültig';
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
            $errors[] = 'Gültige Mitglieds-ID erforderlich';
        }

        if ($amount <= 0) {
            $errors[] = 'Betrag muss größer als 0 sein';
        } elseif ($amount > 100000) {
            $errors[] = 'Betrag ist zu hoch (max. 100.000)';
        }

        if (empty(trim($dueDate))) {
            $errors[] = 'Fälligkeitsdatum ist erforderlich';
        } else {
            try {
                new \DateTime($dueDate);
            } catch (\Exception $e) {
                $errors[] = 'Fälligkeitsdatum ist ungültig';
            }
        }

        if ($description !== null && trim($description) !== '') {
            $length = strlen(trim($description));
            if ($length < 2) {
                $errors[] = 'Beschreibung muss mindestens 2 Zeichen lang sein';
            } elseif ($length > 500) {
                $errors[] = 'Beschreibung darf maximal 500 Zeichen lang sein';
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
    private function validateIBAN(string $iban): bool {
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

        // Mod-97 Check
        return bcmod($numeric, '97') === '1';
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
