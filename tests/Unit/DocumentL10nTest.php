<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\L10n\DocumentL10n;
use OCA\Verein\L10n\SourceL10n;
use OCP\IConfig;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Letters follow the instance language when the administrator set one, otherwise the person printing them -
 * never Nextcloud's bare English fallback.
 */
class DocumentL10nTest extends TestCase {
    private function documentL10n(array $system, string $generic): DocumentL10n {
        $factory = $this->createMock(IFactory::class);
        $factory->method('findGenericLanguage')->willReturn($generic);
        $factory->method('get')->willReturnCallback(fn (string $app, string $lang) => SourceL10n::fromAppLanguage($lang));
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(fn (string $key, $default) => $system[$key] ?? $default);
        return new DocumentL10n($factory, $config, SourceL10n::fromAppLanguage('de'));
    }

    public function testAConfiguredDefaultLanguageWins(): void {
        $l = $this->documentL10n(['default_language' => 'en'], 'en')->get();
        $this->assertSame('en', $l->getLanguageCode());
        $this->assertSame('Payment reminder', $l->t('Payment reminder'));
    }

    public function testAForcedLanguageWinsToo(): void {
        $this->assertSame('en', $this->documentL10n(['force_language' => 'en'], 'en')->get()->getLanguageCode());
    }

    public function testWithoutAnInstanceLanguageTheUsersLanguageIsUsedNotEnglish(): void {
        // Nextcloud's findGenericLanguage() says "en" here, but that is only its last resort
        $l = $this->documentL10n([], 'en')->get();
        $this->assertSame('de', $l->getLanguageCode());
        $this->assertSame('Zahlungserinnerung', $l->t('Payment reminder'));
    }
}
