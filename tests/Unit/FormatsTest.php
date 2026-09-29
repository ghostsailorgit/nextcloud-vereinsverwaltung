<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\L10n\Formats;
use OCA\Verein\L10n\SourceL10n;
use PHPUnit\Framework\TestCase;

/**
 * Letters and PDFs are written in the document language: an English letter must not say "1.234,56 €" or "01.12.2026".
 */
class FormatsTest extends TestCase {
    public function testGermanDocuments(): void {
        $de = SourceL10n::fromAppLanguage('de');
        $this->assertSame('01.12.2026', Formats::date($de, '2026-12-01'));
        $this->assertSame('01.12.2026', Formats::date($de, '2026-12-01 00:00:00'), 'a stored timestamp: its calendar day');
        $this->assertSame('1.234,50 €', str_replace("\u{a0}", ' ', Formats::money($de, 1234.5)));
    }

    public function testEnglishDocuments(): void {
        $en = new SourceL10n();
        $this->assertSame('Dec 1, 2026', Formats::date($en, '2026-12-01'));
        $this->assertSame('€1,234.50', Formats::money($en, 1234.5));
        $this->assertStringStartsWith('Dec 1, 2026', Formats::dateTime($en, new \DateTimeImmutable('2026-12-01 14:05', new \DateTimeZone('Europe/Berlin'))));
    }

    public function testTheDayDoesNotShiftWithTheTimeZone(): void {
        $tz = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            $this->assertSame('Jan 1, 2027', Formats::date(new SourceL10n(), '2027-01-01'));
        } finally {
            date_default_timezone_set($tz);
        }
    }

    public function testUnreadableInputIsShownAsIs(): void {
        $this->assertSame('bald', Formats::date(new SourceL10n(), 'bald'));
    }
}
