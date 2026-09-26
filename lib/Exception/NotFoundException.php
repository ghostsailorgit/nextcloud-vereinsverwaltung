<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Verein\Exception;

use Exception;

/**
 * The requested record does not exist (or is not visible in the given club).
 * The message is written for the user and is shown to them (HTTP 404).
 */
class NotFoundException extends Exception {
    public function __construct(string $message = 'Not found', ?\Throwable $previous = null) {
        parent::__construct($message, 404, $previous);
    }

    public function getStatusCode(): int {
        return 404;
    }
}
