<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../scripts/l10n.php';

/**
 * Every translatable source string (t('verein', ...), n(...), IL10N ->t()) needs a German translation in
 * l10n/de.json, and the generated l10n/de.js, de_DE.json and de_DE.js must be up to date (php scripts/l10n.php build).
 * German users must never see an English text because a translation was forgotten.
 */
class L10nTest extends TestCase {
    public function testEverySourceStringHasAGermanTranslation(): void {
        $missing = \OCA\Verein\Scripts\check()['missing'];
        $this->assertSame([], $missing, 'add these to l10n/de.json: ' . implode(' | ', array_keys($missing)));
    }

    public function testGeneratedTranslationFilesAreUpToDate(): void {
        $this->assertSame([], \OCA\Verein\Scripts\check()['outdated'], 'run: php scripts/l10n.php build');
    }

    public function testPluralTranslationsHaveBothForms(): void {
        $de = json_decode((string)file_get_contents(__DIR__ . '/../../l10n/de.json'), true);
        foreach ($de['translations'] as $key => $value) {
            if (str_starts_with($key, '_') && str_contains($key, '_::_')) {
                $this->assertIsArray($value, $key);
                $this->assertCount(2, $value, $key);
            } else {
                $this->assertIsString($value, $key);
            }
        }
    }

    public function testTheExtractorFindsTheKnownSourceStrings(): void {
        $keys = array_keys(\OCA\Verein\Scripts\extract());
        $this->assertContains('Dunning', $keys);
        $this->assertContains('_%n letter created_::_%n letters created_', $keys);
        $this->assertContains('Cancel', $keys);
    }
}
