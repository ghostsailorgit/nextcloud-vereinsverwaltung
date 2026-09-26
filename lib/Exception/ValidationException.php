<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
/**
 * ValidationException.php - Validierungsfehler
 * 
 * Wirft bei ungültigen Eingaben wie IBAN, Email, etc.
 * 
 * @category Exception
 * @package Verein\Exception
 * @license AGPL-3.0
 */

namespace OCA\Verein\Exception;

use Exception;

class ValidationException extends Exception {
    
    protected $statusCode = 400;
    
    public function __construct(
        string $message = "Validierungsfehler",
        int $code = 400,
        ?Exception $previous = null
    ) {
        $this->statusCode = $code;
        parent::__construct($message, $code, $previous);
    }
    
    public function getStatusCode(): int {
        return $this->statusCode;
    }
}
