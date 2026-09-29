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
        $this->assertContains('Payment reminders', $keys);
        $this->assertContains('_%n letter created_::_%n letters created_', $keys);
        $this->assertContains('Cancel', $keys);
    }

    /**
     * docs/GLOSSARY.md fixes one English word per concept; the words in backticks in its "Not" column and spelling
     * section must not appear in any source string (placeholders like {user} are not text and are left out).
     */
    public function testSourceStringsFollowTheGlossary(): void {
        $glossary = (string)file_get_contents(__DIR__ . '/../../docs/GLOSSARY.md');
        $forbidden = [];
        foreach (preg_split('/\R/', $glossary) as $line) {
            $cells = str_starts_with($line, '|') ? explode('|', trim($line, " |\t")) : null;
            $checked = $cells !== null && count($cells) === 3 ? $cells[2] : (str_starts_with($line, 'British spellings') || str_starts_with($line, '(checked') ? $line : '');
            if (preg_match_all('/`([^`]+)`/', $checked, $m)) {
                array_push($forbidden, ...$m[1]);
            }
        }
        $this->assertContains('fee rate', $forbidden, 'the glossary could not be read');

        $found = [];
        foreach (array_keys(\OCA\Verein\Scripts\extract()) as $key) {
            $text = preg_replace('/\{[a-zA-Z]+\}/', '', $key);
            foreach ($forbidden as $word) {
                if (preg_match('/(?<![\w-])' . preg_quote($word, '/') . '(?![\w-])/i', $text)) {
                    $found[] = "\"$word\" in: $key";
                }
            }
        }
        $this->assertSame([], $found, 'see docs/GLOSSARY.md');
    }

    public function testSourceStringsFollowTheStyleRules(): void {
        $found = [];
        foreach (array_keys(\OCA\Verein\Scripts\extract()) as $key) {
            if (str_contains($key, '...')) {
                $found[] = 'use … instead of ...: ' . $key;
            }
            if (str_contains($key, '"')) {
                $found[] = 'use “…” instead of straight quotes: ' . $key;
            }
            // amounts and dates in a fixed (German) format belong into placeholders, formatted by locale
            if (preg_match('/\d+,\d{2}\s*€|\b\d{1,2}\.\d{1,2}\.\d{4}\b/', $key)) {
                $found[] = 'formatted amount or date in the text: ' . $key;
            }
        }
        $this->assertSame([], $found);
    }
}

