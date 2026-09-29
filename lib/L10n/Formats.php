<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\L10n;

use OCP\IL10N;

/**
 * Dates and amounts in the locale of an IL10N - the document's (DocumentL10n) for letters and PDFs, the user's for
 * messages. Never a fixed German format in a translated text: "1.234,56 €" and "31.12.2026" read wrong in English.
 *
 * With PHP's intl extension (recommended by Nextcloud, not required) the ICU formats of the locale; without it a
 * German or an English fallback by language.
 */
final class Formats {
    /** "2026-12-01" (or a longer timestamp starting with it) -> "01.12.2026" (de) / "Dec 1, 2026" (en); unreadable input as is */
    public static function date(IL10N $l, string $ymd): string {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($ymd), 0, 10));
        if ($d === false) {
            return $ymd;
        }
        if (class_exists(\IntlDateFormatter::class)) {
            $f = new \IntlDateFormatter(self::locale($l), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC');
            $s = $f->format($d->setTimezone(new \DateTimeZone('UTC')));
            if (is_string($s)) {
                return $s;
            }
        }
        return self::isGerman($l) ? $d->format('d.m.Y') : $d->format('M j, Y');
    }

    /** a moment (already in the reader's time zone) -> "01.12.2026, 14:05" (de) / "Dec 1, 2026, 2:05 PM" (en) */
    public static function dateTime(IL10N $l, \DateTimeInterface $moment): string {
        if (class_exists(\IntlDateFormatter::class)) {
            $f = new \IntlDateFormatter(self::locale($l), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, $moment->getTimezone());
            $s = $f->format($moment);
            if (is_string($s)) {
                return $s;
            }
        }
        return self::isGerman($l) ? $moment->format('d.m.Y, H:i') : $moment->format('M j, Y, g:i A');
    }

    /** 1234.5 -> "1.234,50 €" (de) / "€1,234.50" (en) */
    public static function money(IL10N $l, float $amount): string {
        if (class_exists(\NumberFormatter::class)) {
            $s = (new \NumberFormatter(self::locale($l), \NumberFormatter::CURRENCY))->formatCurrency($amount, 'EUR');
            if (is_string($s)) {
                return $s;
            }
        }
        return self::isGerman($l)
            ? number_format($amount, 2, ',', '.') . ' €'
            : ($amount < 0 ? '-' : '') . '€' . number_format(abs($amount), 2, '.', ',');
    }

    private static function locale(IL10N $l): string {
        $locale = $l->getLocaleCode();
        return $locale !== '' ? $locale : $l->getLanguageCode();
    }

    private static function isGerman(IL10N $l): bool {
        return str_starts_with(strtolower(self::locale($l)), 'de');
    }
}
