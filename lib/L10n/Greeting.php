<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\L10n;

use OCA\Verein\Db\Member;
use OCP\IL10N;

/**
 * The salutation of a letter or email to a member, in the given (document) language. Last name and first name are both
 * passed: English addresses by last name ("Dear Ms Mustermann,"), the German translation (informal "du") by first name
 * ("Liebe Erika,") - positional placeholders pick the one they need.
 */
final class Greeting {
    public static function of(Member $member, IL10N $l): string {
        $first = trim((string)$member->getFirstName());
        $params = [$member->getName(), $first];
        return match (true) {
            $first !== '' && $member->getSalutation() === 'Herr' => $l->t('Dear Mr %1$s,', $params),
            $first !== '' && $member->getSalutation() === 'Frau' => $l->t('Dear Ms %1$s,', $params),
            default => $l->t('Dear %s,', [trim($first . ' ' . $member->getName())]),
        };
    }
}
