<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Verein\Calendar;

use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\MemberMapper;
use OCP\Calendar\ICalendarProvider;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Provides each club's birthday/anniversary calendar through Nextcloud's public calendar-provider API
 * (OCP\Calendar\ICalendarProvider, registered in Application::register()). Nextcloud shows such app calendars
 * read-only in the Calendar app and over CalDAV (since 27) - no events are stored, nothing has to be kept in
 * sync, and no internal classes of the DAV app are needed.
 *
 * A user sees a club's calendar when they belong to one of the Nextcloud groups configured for it
 * (Club::getCalendarGroupsArray(), tab "Verein") - the same rule as the former shared calendar.
 */
class ClubCalendarProvider implements ICalendarProvider {
    public const URI_PREFIX = 'verein-club-';

    public function __construct(
        private ClubMapper $clubs,
        private MemberMapper $members,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private LoggerInterface $logger
    ) {
    }

    public function getCalendars(string $principalUri, array $calendarUris = []): array {
        try {
            if (!preg_match('#^principals/users/([^/]+)$#', $principalUri, $m)) {
                return [];
            }
            $user = $this->userManager->get(urldecode($m[1]));
            if ($user === null) {
                return [];
            }
            $userGroups = $this->groupManager->getUserGroupIds($user);
            if ($userGroups === []) {
                return [];
            }

            $calendars = [];
            foreach ($this->clubs->findAll() as $club) {
                if (array_intersect($club->getCalendarGroupsArray(), $userGroups) === []) {
                    continue;
                }
                $uri = self::URI_PREFIX . $club->getId();
                if ($calendarUris !== [] && !in_array($uri, $calendarUris, true)) {
                    continue;
                }
                $calendars[] = new ClubCalendar($club, $this->members);
            }
            return $calendars;
        } catch (Throwable $e) {
            // never break a user's whole calendar list because of this app
            $this->logger->warning('Verein: Vereinskalender konnten nicht bereitgestellt werden', ['exception' => $e]);
            return [];
        }
    }
}
