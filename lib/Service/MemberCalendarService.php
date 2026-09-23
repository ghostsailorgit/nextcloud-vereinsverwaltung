<?php
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Verein\Db\Member;
use OCP\IConfig;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates/removes yearly-recurring "birthday" and "membership anniversary"
 * calendar reminders for members, via a dedicated calendar ("Vereinstermine")
 * owned by an admin-configured Nextcloud user. Uses OCA\DAV\CalDAV\CalDavBackend
 * directly (the same internal API apps like Tasks/Deck use) because the public
 * OCP\Calendar API only supports creating events, not updating/removing them -
 * both of which are required here so reminders can be kept in sync and
 * deactivated when a member leaves or passes away.
 *
 * Disabled (no-op) until an admin sets a calendar owner user in Settings.
 * Every operation is wrapped and logged rather than thrown, so a CalDAV
 * hiccup never blocks saving a member.
 */
class MemberCalendarService {
    private const CALENDAR_URI = 'vereinstermine';
    private const CALENDAR_DISPLAY_NAME = 'Vereinstermine';

    public function __construct(
        private IConfig $config,
        private string $appName,
        private LoggerInterface $logger
    ) {
    }

    public function getOwnerUser(): ?string {
        $user = trim($this->config->getAppValue($this->appName, 'calendar_owner_user', ''));
        return $user === '' ? null : $user;
    }

    public function setOwnerUser(?string $user): void {
        $this->config->setAppValue($this->appName, 'calendar_owner_user', trim((string)$user));
    }

    /**
     * Creates/updates the birthday + join-anniversary reminders for an
     * active member, or removes both once the member is former (left or
     * deceased).
     */
    public function syncMember(Member $member): void {
        try {
            $calendarId = $this->getOrCreateCalendarId();
            if ($calendarId === null) {
                return;
            }
            $backend = $this->getBackend();

            if ($member->isFormer()) {
                $this->removeEvent($backend, $calendarId, $this->objectUri($member->getId(), 'birthday'));
                $this->removeEvent($backend, $calendarId, $this->objectUri($member->getId(), 'anniversary'));
                return;
            }

            $this->syncEvent(
                $backend,
                $calendarId,
                $this->objectUri($member->getId(), 'birthday'),
                $member->getBirthDate(),
                '🎂 Geburtstag: ' . $member->getFullName()
            );
            $this->syncEvent(
                $backend,
                $calendarId,
                $this->objectUri($member->getId(), 'anniversary'),
                $member->getJoinDate(),
                '🎉 Vereinsjubiläum: ' . $member->getFullName()
            );
        } catch (Throwable $e) {
            $this->logger->warning('Verein: Kalender-Sync für Mitglied fehlgeschlagen', [
                'memberId' => $member->getId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Removes both reminders, e.g. when a member is deleted outright.
     */
    public function removeMember(Member $member): void {
        try {
            $calendarId = $this->getOrCreateCalendarId(false);
            if ($calendarId === null) {
                return;
            }
            $backend = $this->getBackend();
            $this->removeEvent($backend, $calendarId, $this->objectUri($member->getId(), 'birthday'));
            $this->removeEvent($backend, $calendarId, $this->objectUri($member->getId(), 'anniversary'));
        } catch (Throwable $e) {
            $this->logger->warning('Verein: Kalender-Erinnerungen für Mitglied konnten nicht entfernt werden', [
                'memberId' => $member->getId(),
                'exception' => $e,
            ]);
        }
    }

    private function getBackend(): CalDavBackend {
        return Server::get(CalDavBackend::class);
    }

    /**
     * @param bool $create Whether to create the calendar if it's missing.
     *   False when just removing an object - no point creating a calendar
     *   only to leave it empty.
     */
    private function getOrCreateCalendarId(bool $create = true): ?int {
        $owner = $this->getOwnerUser();
        if ($owner === null) {
            return null;
        }

        $backend = $this->getBackend();
        $principal = 'principals/users/' . $owner;

        $existing = $backend->getCalendarByUri($principal, self::CALENDAR_URI);
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        if (!$create) {
            return null;
        }

        return $backend->createCalendar($principal, self::CALENDAR_URI, [
            '{DAV:}displayname' => self::CALENDAR_DISPLAY_NAME,
        ]);
    }

    private function objectUri(int $memberId, string $kind): string {
        return 'verein-member-' . $memberId . '-' . $kind . '.ics';
    }

    private function syncEvent(CalDavBackend $backend, int $calendarId, string $objectUri, ?string $date, string $summary): void {
        if (empty($date)) {
            $this->removeEvent($backend, $calendarId, $objectUri);
            return;
        }

        $calendarData = $this->buildIcs($objectUri, $date, $summary);
        $existing = $backend->getCalendarObject($calendarId, $objectUri);
        if ($existing !== null) {
            $backend->updateCalendarObject($calendarId, $objectUri, $calendarData);
        } else {
            $backend->createCalendarObject($calendarId, $objectUri, $calendarData);
        }
    }

    private function removeEvent(CalDavBackend $backend, int $calendarId, string $objectUri): void {
        $backend->deleteCalendarObject($calendarId, $objectUri);
    }

    private function buildIcs(string $objectUri, string $date, string $summary): string {
        $start = new \DateTime($date);
        $end = (clone $start)->modify('+1 day');
        $uid = preg_replace('/\.ics$/', '', $objectUri) . '@' . $this->appName;

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Vereinsverwaltung//Vereinsverwaltung//DE',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART;VALUE=DATE:' . $start->format('Ymd'),
            'DTEND;VALUE=DATE:' . $end->format('Ymd'),
            'RRULE:FREQ=YEARLY',
            'SUMMARY:' . $this->escapeIcsText($summary),
            'END:VEVENT',
            'END:VCALENDAR',
        ]) . "\r\n";
    }

    private function escapeIcsText(string $text): string {
        return str_replace([',', ';'], ['\\,', '\\;'], $text);
    }
}
