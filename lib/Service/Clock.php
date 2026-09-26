<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Verein\Service;

use DateTimeImmutable;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;

/**
 * "Now" and "today" for everything people read or that depends on the calendar day: due dates, the overdue flag,
 * dunning letters, SEPA collection dates, export file names. Nextcloud runs PHP in UTC, so date('Y-m-d') was the
 * UTC day - shortly after midnight (in Germany until 01:00/02:00) still yesterday. This uses Nextcloud's clock
 * (ITimeFactory, replaceable in tests) and the time zone of the user or, without one (background jobs, occ), of the
 * instance (IDateTimeZone).
 *
 * Database timestamps (created_at, updated_at, ...) deliberately stay UTC, as in Nextcloud itself.
 */
class Clock {
    public function __construct(
        private ITimeFactory $time,
        private IDateTimeZone $timeZone
    ) {
    }

    public function now(): DateTimeImmutable {
        return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone($this->timeZone->getTimeZone());
    }

    /** Today's date (Y-m-d) in the user's or instance's time zone. */
    public function today(): string {
        return $this->now()->format('Y-m-d');
    }

    /**
     * A local "today" for code that may run without this service (unit tests constructing services directly).
     */
    public static function todayOf(?self $clock): string {
        return $clock?->today() ?? date('Y-m-d');
    }

    public static function nowOf(?self $clock): DateTimeImmutable {
        return $clock?->now() ?? new DateTimeImmutable();
    }
}
