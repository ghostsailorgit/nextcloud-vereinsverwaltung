<?php

namespace OCA\Verein\Exception;

use Exception;

/**
 * The requested record does not exist (or is not visible in the given club).
 * The message is written for the user and is shown to them (HTTP 404).
 */
class NotFoundException extends Exception {
    public function __construct(string $message = 'Nicht gefunden', ?\Throwable $previous = null) {
        parent::__construct($message, 404, $previous);
    }

    public function getStatusCode(): int {
        return 404;
    }
}
