<?php
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\Sharing\Service as CalendarSharingService;
use OCA\DAV\DAV\Sharing\Backend as SharingBackend;
use OCA\Verein\Db\Club;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Member;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates/removes yearly-recurring "birthday" and "membership anniversary"
 * calendar reminders for members, via one dedicated "Vereinstermine" calendar
 * per club - no admin configuration needed. The calendar technically has to
 * belong to some real Nextcloud user principal, so the first account in the
 * "admin" group is picked automatically the first time it's needed and
 * cached in app config; nobody has to enter a username anywhere.
 *
 * Each club's calendar is shared read-only with the Nextcloud groups
 * configured for that club (Club::getCalendarGroupsArray()) via Nextcloud's
 * normal internal CalDAV group sharing - exactly what the Calendar app's own
 * "share with group" feature does, the same way any logged-in club member
 * would see any other shared calendar: it shows up under "Weitere Kalender"
 * for them to enable. Deliberately NOT a public/unauthenticated link - only
 * real Nextcloud accounts in those groups can ever see it.
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
    private const CALENDAR_DISPLAY_NAME = 'Vereinstermine';

    public function __construct(
        private IConfig $config,
        private string $appName,
        private LoggerInterface $logger,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private ClubMapper $clubMapper
    ) {
    }

    /**
     * Creates/updates the birthday + join-anniversary reminders for a member
     * in the given club (the member must carry that club's membership - see
     * MemberMapper::findInClub()), or removes both once the member is
     * former (left or deceased).
     */
    public function syncMember(Club $club, Member $member): void {
        try {
            $calendarId = $this->getOrCreateCalendarId($club);
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
                'clubId' => $club->getId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Removes both reminders from the club calendar, e.g. when a member is
     * removed from the club.
     */
    public function removeMember(Club $club, Member $member): void {
        try {
            $calendarId = $this->getOrCreateCalendarId($club, false);
            if ($calendarId === null) {
                return;
            }
            $backend = $this->getBackend();
            $this->removeEvent($backend, $calendarId, $this->objectUri($member->getId(), 'birthday'));
            $this->removeEvent($backend, $calendarId, $this->objectUri($member->getId(), 'anniversary'));
        } catch (Throwable $e) {
            $this->logger->warning('Verein: Kalender-Erinnerungen für Mitglied konnten nicht entfernt werden', [
                'memberId' => $member->getId(),
                'clubId' => $club->getId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Brings the club calendar's name and group shares in line with the
     * club's current settings (called after a club is created or edited).
     */
    public function syncClubCalendar(Club $club): void {
        try {
            $this->getOrCreateCalendarId($club);
        } catch (Throwable $e) {
            $this->logger->warning('Verein: Vereinskalender konnte nicht aktualisiert werden', [
                'clubId' => $club->getId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Deletes the club calendar (when the club itself is deleted).
     */
    public function deleteClubCalendar(Club $club): void {
        try {
            $calendarId = $this->getOrCreateCalendarId($club, false);
            if ($calendarId !== null) {
                $this->getBackend()->deleteCalendar($calendarId, true);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Verein: Vereinskalender konnte nicht gelöscht werden', [
                'clubId' => $club->getId(),
                'exception' => $e,
            ]);
        }
    }

    private function getBackend(): CalDavBackend {
        return Server::get(CalDavBackend::class);
    }

    /**
     * The Nextcloud account that technically owns the club calendars.
     * Auto-picked once (first account in the "admin" group) and cached in
     * app config - never asked of anyone. Re-validated on every call
     * (userExists() is a cheap lookup) rather than trusted blindly: if that
     * account is later deleted, a stale cached UID would silently orphan the
     * calendars under a principal nobody can reach anymore.
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
     * The club's calendar URI: the one stored on the club, or - assigned and
     * stored on first use - a URI unique to the club.
     */
    private function calendarUri(Club $club): string {
        $uri = trim((string)$club->getCalendarUri());
        if ($uri === '') {
            $uri = 'vereinstermine-' . $club->getId();
            $club->setCalendarUri($uri);
            $this->clubMapper->update($club);
        }
        return $uri;
    }

    private function displayName(Club $club): string {
        return self::CALENDAR_DISPLAY_NAME . ' ' . $club->getName();
    }

    /**
     * @param bool $create Whether to create the calendar if it is missing.
     *   False when just removing an object - no point creating a calendar
     *   only to leave it empty.
     */
    private function getOrCreateCalendarId(Club $club, bool $create = true): ?int {
        $owner = $this->resolveOwnerUid();
        if ($owner === null) {
            return null;
        }

        $backend = $this->getBackend();
        $principal = 'principals/users/' . $owner;
        $uri = $this->calendarUri($club);

        $existing = $backend->getCalendarByUri($principal, $uri);
        if ($existing !== null) {
            $calendarId = (int)$existing['id'];
            if ($create) {
                $this->ensureDisplayName($backend, $calendarId, $existing, $club);
                $this->ensureGroupShares($calendarId, $club);
            }
            return $calendarId;
        }

        if (!$create) {
            return null;
        }

        $calendarId = $backend->createCalendar($principal, $uri, [
            '{DAV:}displayname' => $this->displayName($club),
        ]);
        $this->ensureGroupShares($calendarId, $club);
        return $calendarId;
    }

    private function ensureDisplayName(CalDavBackend $backend, int $calendarId, array $calendar, Club $club): void {
        $wanted = $this->displayName($club);
        if (($calendar['{DAV:}displayname'] ?? null) === $wanted) {
            return;
        }
        $patch = new \Sabre\DAV\PropPatch(['{DAV:}displayname' => $wanted]);
        $backend->updateCalendar($calendarId, $patch);
        $patch->commit();
    }

    /**
     * Makes the calendar's group shares match the club's configured groups:
     * shares read-only with every configured group that exists and removes
     * shares with groups that are no longer configured (so taking a group
     * off the list really withdraws its access). Cheap no-op once in sync.
     */
    private function ensureGroupShares(int $calendarId, Club $club): void {
        $sharingService = Server::get(CalendarSharingService::class);
        $existing = array_map(
            static fn (array $share) => $share['{http://owncloud.org/ns}principal'] ?? null,
            $sharingService->getShares($calendarId)
        );

        $wanted = [];
        foreach ($club->getCalendarGroupsArray() as $gid) {
            if ($this->groupManager->groupExists($gid)) {
                $wanted[] = 'principals/groups/' . $gid;
            }
        }

        foreach ($wanted as $principal) {
            if (!in_array($principal, $existing, true)) {
                $sharingService->shareWith($calendarId, $principal, SharingBackend::ACCESS_READ);
            }
        }
        foreach ($existing as $principal) {
            if (is_string($principal) && str_starts_with($principal, 'principals/groups/') && !in_array($principal, $wanted, true)) {
                $sharingService->deleteShare($calendarId, $principal);
            }
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
            'PRODID:-//Vereinsverwaltung//DE',
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
