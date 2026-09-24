<?php

namespace OCA\Verein\Controller;

use OCA\Verein\Exception\DependencyMissingException;
use OCA\Verein\Exception\NotFoundException;
use OCA\Verein\Exception\PermissionDeniedException;
use OCA\Verein\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException as FilesNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * One place that decides which HTTP status and message an exception becomes:
 *
 *  - ValidationException            400  the message is meant for the user
 *  - PermissionDeniedException      403  ditto
 *  - NotFoundException              404  ditto
 *  - DoesNotExistException          404  generic text (the framework's own message contains SQL)
 *  - DependencyMissingException     503  the message tells the administrator what is missing
 *  - anything else                  500  generic text; the details go to the Nextcloud log only
 *
 * Nothing but the messages of the first four kinds ever reaches the browser.
 */
trait RespondsWithErrors {
    protected function errorResponse(\Throwable $e): JSONResponse {
        if ($e instanceof ValidationException || $e instanceof PermissionDeniedException
            || $e instanceof NotFoundException || $e instanceof DependencyMissingException) {
            return $this->errorJson($e->getMessage(), $e->getStatusCode());
        }
        if ($e instanceof DoesNotExistException || $e instanceof FilesNotFoundException) {
            return $this->errorJson('Nicht gefunden', Http::STATUS_NOT_FOUND);
        }

        try {
            \OCP\Server::get(LoggerInterface::class)->error('Verein: unerwarteter Fehler', ['exception' => $e]);
        } catch (\Throwable $ignored) {
            // no logger available (e.g. in a unit test) - still answer safely
        }
        return $this->errorJson('Interner Fehler. Einzelheiten stehen im Nextcloud-Log.', Http::STATUS_INTERNAL_SERVER_ERROR);
    }

    private function errorJson(string $message, int $status): JSONResponse {
        return new JSONResponse(['status' => 'error', 'message' => $message], $status);
    }
}
