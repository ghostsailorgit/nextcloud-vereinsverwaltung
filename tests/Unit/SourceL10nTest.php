<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\ValidationService;
use PHPUnit\Framework\TestCase;

/**
 * The fallback IL10N must follow Nextcloud's placeholder rules, otherwise a message that looks right in the tests
 * would come out differently in the app.
 */
class SourceL10nTest extends TestCase {
    public function testWithoutTranslationsTheEnglishSourceIsReturned(): void {
        $l = new SourceL10n();
        $this->assertSame('Backup not found: x.json.gz', $l->t('Backup not found: %s', ['x.json.gz']));
        $this->assertSame('en', $l->getLanguageCode());
    }

    public function testGermanTranslationsWithPositionalPlaceholders(): void {
        $l = SourceL10n::fromAppLanguage('de');
        $this->assertSame('de', $l->getLanguageCode());
        $this->assertSame('Geburtsdatum „31.13.2026“ ist kein Datum (TT.MM.JJJJ)',
            $l->t('%1$s "%2$s" is not a date (DD.MM.YYYY)', [$l->t('Birth date'), '31.13.2026']));
        // a scalar parameter is allowed like in Nextcloud
        $this->assertSame('Eintritt erst nach 2027', $l->t('joins only after %s', 2027));
    }

    public function testPluralFormsAndCount(): void {
        $de = SourceL10n::fromAppLanguage('de');
        $this->assertSame('1 Beitrag', $de->n('%n fee', '%n fees', 1));
        $this->assertSame('3 Beiträge', $de->n('%n fee', '%n fees', 3));
        $this->assertSame('0 fees', (new SourceL10n())->n('%n fee', '%n fees', 0));
    }

    public function testAServiceWithoutInjectedL10nSpeaksEnglish(): void {
        $errors = (new ValidationService())->validateMember(['name' => 'Muster', 'email' => 'no-mail'])['errors'];
        $this->assertSame(['E-mail is invalid'], $errors);
        $errors = (new ValidationService(SourceL10n::fromAppLanguage('de')))->validateMember(['name' => 'Muster', 'email' => 'no-mail'])['errors'];
        $this->assertSame(['E-Mail ist ungültig'], $errors);
    }

    public function testAnUnknownLanguageFallsBackToEnglish(): void {
        $this->assertSame('Backup not found', SourceL10n::fromAppLanguage('xx')->t('Backup not found'));
    }
}
