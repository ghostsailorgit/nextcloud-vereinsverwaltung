<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Verein\Migration;

use OCA\Verein\Db\ClubMapper;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One-time cleanup after switching to the app calendar (OCA\Verein\Calendar\ClubCalendarProvider): up to 0.17 the
 * app stored the birthdays/anniversaries as events in a real calendar per club (owned by an admin account, shared
 * read-only with the club's calendar groups). This step deletes those calendars permanently, with their events and
 * shares, and forgets them (Club::calendarUri = null).
 *
 * Runs after every upgrade (post-migration) but only acts on clubs that still have a calendarUri, so it does its
 * work once. It is the only place left that uses the DAV app's internal classes; they are looked up by name at
 * runtime, and if anything fails the old calendar simply stays (the owner can delete it in the Calendar app) and
 * the reason goes to the output and the log - an upgrade never fails because of it.
 */
class RemoveLegacyCalendars implements IRepairStep {
    private const BACKEND = 'OCA\\DAV\\CalDAV\\CalDavBackend';

    public function __construct(
        private ClubMapper $clubs,
        private IGroupManager $groupManager,
        private IAppConfig $appConfig,
        private LoggerInterface $logger
    ) {
    }

    public function getName(): string {
        return 'Vereinsverwaltung: remove the old stored club calendars (replaced by the app calendar)';
    }

    public function run(IOutput $output): void {
        $legacy = array_values(array_filter($this->clubs->findAll(), fn ($c) => trim((string)$c->getCalendarUri()) !== ''));
        if ($legacy === []) {
            return;
        }
        if (!class_exists(self::BACKEND)) {
            $output->warning('Calendar backend not available - old club calendars left in place');
            return;
        }

        try {
            $backend = Server::get(self::BACKEND);
            $owners = $this->ownerCandidates();
            foreach ($legacy as $club) {
                $uri = trim((string)$club->getCalendarUri());
                $deleted = 0;
                foreach ($owners as $uid) {
                    $calendar = $backend->getCalendarByUri('principals/users/' . $uid, $uri);
                    if ($calendar !== null) {
                        // permanently, including all events and the group shares
                        $backend->deleteCalendar((int)$calendar['id'], true);
                        $deleted++;
                    }
                }
                $club->setCalendarUri(null);
                $this->clubs->update($club);
                $output->info(sprintf('Club %d: %d old calendar(s) "%s" removed', $club->getId(), $deleted, $uri));
            }
            $this->appConfig->deleteKey('verein', 'calendar_owner_user');
        } catch (Throwable $e) {
            $this->logger->warning('Verein: could not remove the old club calendars', ['exception' => $e]);
            $output->warning('Old club calendars could not be removed (see log): ' . get_class($e));
        }
    }

    /**
     * The account that owned the old calendars: the one remembered in app config, else any admin
     * (the old code picked the first account of the admin group).
     *
     * @return string[]
     */
    private function ownerCandidates(): array {
        $uids = [];
        $cached = trim($this->appConfig->getValueString('verein', 'calendar_owner_user', ''));
        if ($cached !== '') {
            $uids[] = $cached;
        }
        foreach ($this->groupManager->get('admin')?->getUsers() ?? [] as $user) {
            $uids[] = $user->getUID();
        }
        return array_values(array_unique($uids));
    }
}
