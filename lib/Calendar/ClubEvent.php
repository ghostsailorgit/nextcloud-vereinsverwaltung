<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Verein\Calendar;

use Sabre\VObject\Component\VEvent;

/**
 * A VEVENT that also answers array access by property name ($event['UID']).
 *
 * Nextcloud's CalDAV wrapper for app calendars (OCA\DAV\CalDAV\AppCalendar\AppCalendar::getChildren()) reads the UID
 * of each search result as (string)$object['UID'] and wraps the results in a VCalendar - so a result has to be a
 * Sabre component and support that access. A plain Sabre component answers array access with its own element
 * list, which logged an "Undefined array key" warning per event on every request.
 */
class ClubEvent extends VEvent {
    #[\ReturnTypeWillChange]
    public function offsetExists($offset) {
        if (is_string($offset) && !ctype_digit($offset)) {
            return isset($this->{$offset});
        }
        return parent::offsetExists($offset);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset) {
        if (is_string($offset) && !ctype_digit($offset)) {
            return $this->{$offset};
        }
        return parent::offsetGet($offset);
    }
}
