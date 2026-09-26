<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Verein\Calendar;

use OCA\Verein\Db\Club;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCP\Calendar\ICalendar;
use OCP\Constants;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * One club's calendar, computed from the member data on every request (read-only): a yearly birthday event and
 * a yearly club anniversary (join date) for every active member - not for former, deceased or deactivated ones,
 * so deactivating or leaving takes effect immediately and anonymized people can never appear.
 *
 * search() returns Sabre VObject VEVENT components: that is what Nextcloud's CalDAV wrapper for app calendars
 * (OCA\DAV\CalDAV\AppCalendar) builds its calendar objects from. Sabre VObject ships with Nextcloud.
 */
class ClubCalendar implements ICalendar {
    /** @var ClubEvent[]|null built once per request (the CalDAV layer calls search() once per object) */
    private ?array $events = null;

    public function __construct(
        private Club $club,
        private MemberMapper $members
    ) {
    }

    public function getKey(): string {
        return ClubCalendarProvider::URI_PREFIX . $this->club->getId();
    }

    public function getUri(): string {
        return ClubCalendarProvider::URI_PREFIX . $this->club->getId();
    }

    public function getDisplayName(): ?string {
        return 'Vereinstermine ' . $this->club->getName() . ' (App-Kalender)';
    }

    public function getDisplayColor(): ?string {
        return '#2e7d32';
    }

    public function getPermissions(): int {
        return Constants::PERMISSION_READ;
    }

    public function isDeleted(): bool {
        return false;
    }

    /**
     * @return VEvent[]
     */
    public function search(string $pattern, array $searchProperties = [], array $options = [], ?int $limit = null, ?int $offset = null): array {
        if (isset($options['types']) && is_array($options['types']) && !in_array('VEVENT', $options['types'], true)) {
            return [];
        }
        $pattern = mb_strtolower($pattern);
        $result = [];
        foreach ($this->events() as $event) {
            $uid = (string)$event->UID;
            if (isset($options['uid']) && $options['uid'] !== $uid) {
                continue;
            }
            if (isset($options['uri']) && $options['uri'] !== $uid . '.ics') {
                continue;
            }
            if ($pattern !== '' && !$this->matches($event, $pattern, $searchProperties ?: ['SUMMARY'])) {
                continue;
            }
            $result[] = $event;
        }
        return array_slice($result, $offset ?? 0, $limit);
    }

    private function matches(VEvent $event, string $pattern, array $properties): bool {
        foreach ($properties as $property) {
            $uid = (string)$event->UID;
            // the CalDAV layer looks objects up by file name ("<uid>.ics") or by UID: those must match exactly
            if ($property === 'X-FILENAME' && $pattern === mb_strtolower($uid . '.ics')) {
                return true;
            }
            if ($property === 'UID' && $pattern === mb_strtolower($uid)) {
                return true;
            }
            if (!in_array($property, ['X-FILENAME', 'UID'], true) && isset($event->{$property})
                && str_contains(mb_strtolower((string)$event->{$property}), $pattern)) {
                return true;
            }
        }
        return false;
    }

    /** @return VEvent[] */
    private function events(): array {
        if ($this->events !== null) {
            return $this->events;
        }
        $this->events = [];
        foreach ($this->members->findByClub($this->club->getId()) as $member) {
            if ($member->isFormer() || $member->getDeactivated() || $member->getAnonymizedAt() !== null) {
                continue;
            }
            $this->addEvent($member, 'birthday', $member->getBirthDate(), '🎂 Geburtstag: ' . $member->getFullName());
            $this->addEvent($member, 'anniversary', $member->getJoinDate(), '🎉 Vereinsjubiläum: ' . $member->getFullName());
        }
        return $this->events;
    }

    private function addEvent(Member $member, string $kind, ?string $date, string $summary): void {
        if ($date === null || $date === '') {
            return;
        }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
        if ($start === false) {
            return;
        }
        $vcalendar = new VCalendar();
        $event = new ClubEvent($vcalendar, 'VEVENT', [
            'UID' => 'verein-club-' . $this->club->getId() . '-member-' . $member->getId() . '-' . $kind,
            'SUMMARY' => $summary,
            'RRULE' => 'FREQ=YEARLY',
            'TRANSP' => 'TRANSPARENT',
        ], false);
        $event->DTSTAMP = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $event->add('DTSTART', $start, ['VALUE' => 'DATE']);
        $event->add('DTEND', $start->modify('+1 day'), ['VALUE' => 'DATE']);
        $this->events[] = $event;
    }
}
