<?php
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Verein\Db\Member;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates/removes yearly-recurring "birthday" and "membership anniversary"
 * calendar reminders for members, via a dedicated, always-on "Vereinstermine"
 * calendar - no admin configuration needed. The calendar technically has to
 * belong to some real Nextcloud user principal, so the first account in the
 * "admin" group is picked automatically the first time it's needed and
 * cached in app config; nobody has to enter a username anywhere. Members
 * subscribe to it themselves via a public read-only ICS link (see
 * getSubscribeUrl()) - shown on the Dashboard - rather than the app writing
 * into anyone's private calendar or requiring per-user setup.
 *
 * Uses OCA\DAV\CalDAV\CalDavBackend directly (the same internal API apps
 * like Tasks/Deck use) because the public OCP\Calendar API only supports
 * creating events, not updating/removing them - both required here so
 * reminders stay in sync and disappear once a member leaves or passes away.
 * The public-link share is created the same way Nextcloud's own "copy
 * public link" feature does (a row in dav_shares with access=ACCESS_PUBLIC),
 * replicated directly via IDBConnection since that plumbing is normally
 * only reachable through a full CalDAV POST request cycle.
 *
 * Every operation is wrapped and logged rather than thrown, so a CalDAV
 * hiccup never blocks saving a member or loading the Dashboard.
 */
class MemberCalendarService {
    private const CALENDAR_URI = 'vereinstermine';
    private const CALENDAR_DISPLAY_NAME = 'Vereinstermine';
    private const ACCESS_PUBLIC = 4; // OCA\DAV\CalDAV\CalDavBackend::ACCESS_PUBLIC

    public function __construct(
        private IConfig $config,
        private string $appName,
        private LoggerInterface $logger,
        private IGroupManager $groupManager,
        private IDBConnection $db,
        private ISecureRandom $random,
        private IURLGenerator $urlGenerator
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

    /**
     * A public, read-only ICS subscribe link for the "Vereinstermine"
     * calendar - members paste this into their own calendar app (Nextcloud,
     * phone, Outlook, ...) to add it themselves. Null if it couldn't be
     * created (e.g. no admin account exists yet to own the calendar).
     */
    public function getSubscribeUrl(): ?string {
        try {
            $calendarId = $this->getOrCreateCalendarId();
            if ($calendarId === null) {
                return null;
            }
            $token = $this->getOrCreatePublicShareToken($calendarId, $this->principalUri());
            return $this->urlGenerator->getAbsoluteURL('/remote.php/dav/public-calendars/') . $token . '?export';
        } catch (Throwable $e) {
            $this->logger->warning('Verein: Öffentlicher Kalender-Link konnte nicht erstellt werden', [
                'exception' => $e,
            ]);
            return null;
        }
    }

    private function getBackend(): CalDavBackend {
        return Server::get(CalDavBackend::class);
    }

    private function principalUri(): string {
        return 'principals/users/' . $this->resolveOwnerUid();
    }

    /**
     * The Nextcloud account that technically owns the "Vereinstermine"
     * calendar. Auto-picked once (first account in the "admin" group) and
     * cached in app config - never asked of anyone.
     */
    private function resolveOwnerUid(): ?string {
        $cached = trim($this->config->getAppValue($this->appName, 'calendar_owner_user', ''));
        if ($cached !== '') {
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
            return (int)$existing['id'];
        }

        if (!$create) {
            return null;
        }

        return $backend->createCalendar($principal, self::CALENDAR_URI, [
            '{DAV:}displayname' => self::CALENDAR_DISPLAY_NAME,
        ]);
    }

    /**
     * Mirrors what Nextcloud's own "copy public link" calendar sharing does
     * (OCA\DAV\CalDAV\CalDavBackend::setPublishStatus()) - a dav_shares row
     * with access=ACCESS_PUBLIC - done directly via IDBConnection since that
     * method expects a full Sabre Calendar DAV node we don't have here.
     */
    private function getOrCreatePublicShareToken(int $calendarId, string $principalUri): string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('publicuri')
            ->from('dav_shares')
            ->where($qb->expr()->eq('resourceid', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('calendar')))
            ->andWhere($qb->expr()->eq('access', $qb->createNamedParameter(self::ACCESS_PUBLIC, IQueryBuilder::PARAM_INT)));
        $existing = $qb->executeQuery()->fetchOne();
        if ($existing !== false) {
            return (string)$existing;
        }

        $token = $this->random->generate(16, ISecureRandom::CHAR_HUMAN_READABLE);
        $insert = $this->db->getQueryBuilder();
        $insert->insert('dav_shares')
            ->values([
                'principaluri' => $insert->createNamedParameter($principalUri),
                'type' => $insert->createNamedParameter('calendar'),
                'access' => $insert->createNamedParameter(self::ACCESS_PUBLIC, IQueryBuilder::PARAM_INT),
                'resourceid' => $insert->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT),
                'publicuri' => $insert->createNamedParameter($token),
            ]);
        $insert->executeStatement();
        return $token;
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
