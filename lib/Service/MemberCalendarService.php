<?php
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\Sharing\Service as CalendarSharingService;
use OCA\DAV\DAV\Sharing\Backend as SharingBackend;
use OCA\Verein\Db\Member;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates/removes yearly-recurring "birthday" and "membership anniversary"
 * calendar reminders for members, via a dedicated, always-on "Vereinstermine"
 * calendar - no admin configuration needed. The calendar technically has to
 * belong to some real Nextcloud user principal, so the first account in the
 * "admin" group is picked automatically the first time it's needed and
 * cached in app config; nobody has to enter a username anywhere.
 *
 * The calendar is shared read-only with this deployment's club groups (see
 * SHARE_GROUPS) via Nextcloud's normal internal CalDAV group sharing -
 * exactly what the Calendar app's own "share with group" feature does, the
 * same way any logged-in club member would see any other shared calendar:
 * it shows up under "Weitere Kalender" for them to enable. Deliberately NOT
 * a public/unauthenticated link - only real Nextcloud accounts in those
 * groups can ever see it.
 *
 * Uses OCA\DAV\CalDAV\CalDavBackend directly (the same internal API apps
 * like Tasks/Deck use) because the public OCP\Calendar API only supports
 * creating events, not updating/removing them - both required here so
 * reminders stay in sync and disappear once a member leaves or passes away.
 *
 * Every operation is wrapped and logged rather than thrown, so a CalDAV
 * hiccup never blocks saving a member.
 */
class MemberCalendarService {
    private const CALENDAR_URI = 'vereinstermine';
    private const CALENDAR_DISPLAY_NAME = 'Vereinstermine';

    /**
     * The Nextcloud groups that get read access to the calendar - every
     * tier of this specific club's existing group structure (see
     * the groupfolders setup), i.e.
     * every member with a Nextcloud account. Groups that don't exist (yet)
     * are silently skipped.
     */
    private const SHARE_GROUPS = [
        'exec-board-read', 'exec-board-write',
        'board-read', 'board-write',
        'members-read', 'members-write',
    ];

    public function __construct(
        private IConfig $config,
        private string $appName,
        private LoggerInterface $logger,
        private IGroupManager $groupManager,
        private IUserManager $userManager
    ) {
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
     * The Nextcloud account that technically owns the "Vereinstermine"
     * calendar. Auto-picked once (first account in the "admin" group) and
     * cached in app config - never asked of anyone. Re-validated on every
     * call (userExists() is a cheap lookup) rather than trusted blindly:
     * if that account is later deleted, a stale cached UID would silently
     * orphan the calendar under a principal nobody can reach anymore.
     */
    private function resolveOwnerUid(): ?string {
        $cached = trim($this->config->getAppValue($this->appName, 'calendar_owner_user', ''));
        if ($cached !== '' && $this->userManager->userExists($cached)) {
            return $cached;
        }

        $admins = $this->groupManager->get('admin')?->getUsers() ?? [];
        $first = array_values($admins)[0] ?? null;
        if ($first === null) {
            return null;
        }

        $uid = $first->getUID();
        $this->config->setAppValue($this->appName, 'calendar_owner_user', $uid);
        return $uid;
    }

    /**
     * @param bool $create Whether to create the calendar if it's missing.
     *   False when just removing an object - no point creating a calendar
     *   only to leave it empty.
     */
    private function getOrCreateCalendarId(bool $create = true): ?int {
        $owner = $this->resolveOwnerUid();
        if ($owner === null) {
            return null;
        }

        $backend = $this->getBackend();
        $principal = 'principals/users/' . $owner;

        $existing = $backend->getCalendarByUri($principal, self::CALENDAR_URI);
        if ($existing !== null) {
            $calendarId = (int)$existing['id'];
            $this->ensureGroupShares($calendarId);
            return $calendarId;
        }

        if (!$create) {
            return null;
        }

        $calendarId = $backend->createCalendar($principal, self::CALENDAR_URI, [
            '{DAV:}displayname' => self::CALENDAR_DISPLAY_NAME,
        ]);
        $this->ensureGroupShares($calendarId);
        return $calendarId;
    }

    /**
     * Shares the calendar read-only with every SHARE_GROUPS group that
     * exists, skipping ones already shared. Cheap no-op once all groups
     * are shared; self-heals if a group is created later.
     */
    private function ensureGroupShares(int $calendarId): void {
        $sharingService = Server::get(CalendarSharingService::class);
        $existing = array_map(
            static fn (array $share) => $share['{http://owncloud.org/ns}principal'] ?? null,
            $sharingService->getShares($calendarId)
        );

        foreach (self::SHARE_GROUPS as $gid) {
            if (!$this->groupManager->groupExists($gid)) {
                continue;
            }
            $principal = 'principals/groups/' . $gid;
            if (in_array($principal, $existing, true)) {
                continue;
            }
            $sharingService->shareWith($calendarId, $principal, SharingBackend::ACCESS_READ);
        }
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
