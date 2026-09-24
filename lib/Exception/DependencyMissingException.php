<?php

namespace OCA\Verein\Exception;

use Exception;

/**
 * A server-side prerequisite is missing (e.g. the PDF library after an install without
 * "composer install"). The message tells the administrator what to do (HTTP 503).
 */
class DependencyMissingException extends Exception {
    public function __construct(string $message, ?\Throwable $previous = null) {
        parent::__construct($message, 503, $previous);
    }

    public function getStatusCode(): int {
        return 503;
    }
}
