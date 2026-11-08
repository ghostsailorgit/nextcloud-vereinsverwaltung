<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

/**
 * Which age tier of a fee category applies to a person in a fee year.
 *
 * The age counts on the category's cut-off day of the fee year (by default December 31, so everybody born in the same
 * year pays the same, whenever the fees are collected).
 */
final class AgeGrading {
    public const DEFAULT_CUTOFF = '12-31';

    /**
     * @return int|null the age on the cut-off day; null if the birth date is missing or unreadable.
     *                  Somebody born after the cut-off day counts as 0.
     */
    public static function ageAt(?string $birthDate, int $year, ?string $cutoff): ?int {
        if ($birthDate === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $birthDate, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        $notYet = ($m[2] . '-' . $m[3]) > ($cutoff ?: self::DEFAULT_CUTOFF);
        return max(0, $year - (int)$m[1] - ($notYet ? 1 : 0));
    }

    /**
     * @param array<int, array{fromAge: int, amount: float}> $tiers ascending by fromAge, the first starting at 0
     * @return array{fromAge: int, amount: float}|null null if the age cannot be determined
     */
    public static function tierFor(array $tiers, ?string $birthDate, int $year, ?string $cutoff): ?array {
        $age = self::ageAt($birthDate, $year, $cutoff);
        if ($age === null) {
            return null;
        }
        $found = null;
        foreach ($tiers as $tier) {
            if ((int)$tier['fromAge'] <= $age) {
                $found = ['fromAge' => (int)$tier['fromAge'], 'amount' => (float)$tier['amount']];
            }
        }
        return $found;
    }
}
