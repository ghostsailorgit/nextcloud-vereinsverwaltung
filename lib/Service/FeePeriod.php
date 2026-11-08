<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

/**
 * The billing periods of a payment interval and the text stored on a fee: 2026, 2026-H1, 2026-Q1 or 2026-03.
 */
final class FeePeriod {
    public const YEARLY = 'yearly';

    /** payment interval => months per payment */
    public const INTERVALS = ['yearly' => 12, 'half_yearly' => 6, 'quarterly' => 3, 'monthly' => 1];

    /**
     * The period of the interval that contains the month.
     *
     * @return array{key: string, firstMonth: int, lastMonth: int, length: int, number: int}
     */
    public static function of(string $interval, int $year, int $month): array {
        $length = self::INTERVALS[$interval];
        $index = intdiv($month - 1, $length);
        $number = $index + 1;
        $key = match ($interval) {
            'half_yearly' => $year . '-H' . $number,
            'quarterly' => $year . '-Q' . $number,
            'monthly' => sprintf('%d-%02d', $year, $number),
            default => (string)$year,
        };
        return ['key' => $key, 'firstMonth' => $index * $length + 1, 'lastMonth' => ($index + 1) * $length, 'length' => $length, 'number' => $number];
    }

    /**
     * The months a stored period covers, or null for anything that is not a period of the fee run (a manual fee).
     *
     * @return array{year: int, first: int, last: int}|null
     */
    public static function covered(?string $period): ?array {
        if ($period === null || !preg_match('/^(\d{4})(?:-(H[12]|Q[1-4]|0[1-9]|1[0-2]))?$/', $period, $m)) {
            return null;
        }
        $year = (int)$m[1];
        $part = $m[2] ?? '';
        if ($part === '') {
            return ['year' => $year, 'first' => 1, 'last' => 12];
        }
        $length = match ($part[0]) {
            'H' => 6,
            'Q' => 3,
            default => 1,
        };
        $number = ($part[0] === 'H' || $part[0] === 'Q') ? (int)$part[1] : (int)$part;
        return ['year' => $year, 'first' => ($number - 1) * $length + 1, 'last' => $number * $length];
    }
}
