<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
/**
 * PermissionDeniedException.php - Permission-Fehler
 * 
 * Wirft bei fehlenden Rechten für Operationen.
 * 
 * @category Exception
 * @package Verein\Exception
 * @license AGPL-3.0
 */

namespace OCA\Verein\Exception;

use Exception;

class PermissionDeniedException extends Exception {
    
    protected $statusCode = 403;
    
    public function __construct(
        string $message = "Berechtigung verweigert",
        int $code = 403,
        Exception $previous = null
    ) {
        $this->statusCode = $code;
        parent::__construct($message, $code, $previous);
    }
    
    public function getStatusCode(): int {
        return $this->statusCode;
    }
}
