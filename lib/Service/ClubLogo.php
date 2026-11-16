<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\Club;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * The club's logo for a PDF. The club only stores an https address (no upload, no public route); a PDF needs the image
 * itself, so it is fetched through Nextcloud's own HTTP client - which refuses local and private addresses unless the
 * administrator allowed them (allow_local_remote_servers) - with a short timeout, at most 1 MB and only PNG or JPEG.
 * Anything else means "no logo": the document is created without it and the reason goes to the log.
 */
class ClubLogo {
    private const MAX_BYTES = 1048576;

    public function __construct(
        private IClientService $clients,
        private LoggerInterface $logger
    ) {
    }

    /** @return string|null the image bytes */
    public function imageFor(Club $club): ?string {
        $url = trim((string)$club->getMailLogoUrl());
        if ($url === '' || !str_starts_with(strtolower($url), 'https://')) {
            return null;
        }
        try {
            $response = $this->clients->newClient()->get($url, ['timeout' => 5, 'nextcloud' => ['allow_local_address' => false]]);
            $body = $response->getBody();
            $data = is_string($body) ? $body : stream_get_contents($body, self::MAX_BYTES + 1);
            if (!is_string($data) || $data === '' || strlen($data) > self::MAX_BYTES) {
                throw new \RuntimeException('empty or larger than 1 MB');
            }
            // by content, not by the header the server sends
            $info = @getimagesizefromstring($data);
            if ($info === false || !in_array($info[2] ?? null, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                throw new \RuntimeException('not a PNG or JPEG image');
            }
            return $data;
        } catch (\Throwable $e) {
            $this->logger->warning('Club logo could not be loaded for a PDF', ['app' => 'verein', 'exception' => $e]);
            return null;
        }
    }
}
