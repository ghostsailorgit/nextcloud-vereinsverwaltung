<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\L10n;

use OCP\IL10N;

/**
 * IL10N for code that runs without Nextcloud's DI container - a service constructed by hand, or a unit test.
 * In the app the container injects Nextcloud's own IL10N (the user's language); services take it as an optional
 * last constructor argument and fall back to this.
 *
 * Without translations it returns the English source text; with a translation table (l10n/<lang>.json format)
 * it translates. Placeholders follow Nextcloud: vsprintf() parameters (%s, %1$s) and %n for the count in n().
 */
class SourceL10n implements IL10N {
    /**
     * @param array<string, string|string[]> $translations source => translation; plural keys "_sing_::_plural_" => [one, other]
     */
    public function __construct(
        private array $translations = [],
        private string $language = 'en',
    ) {
    }

    /** Translations from one of the app's l10n/<lang>.json files, e.g. "de". */
    public static function fromAppLanguage(string $language): self {
        $file = __DIR__ . '/../../l10n/' . $language . '.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return new self(is_array($data) ? ($data['translations'] ?? []) : [], $language);
    }

    public function t(string $text, $parameters = []): string {
        $translated = $this->translations[$text] ?? $text;
        return vsprintf(is_string($translated) ? $translated : $text, is_array($parameters) ? $parameters : [$parameters]);
    }

    public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
        $forms = $this->translations['_' . $text_singular . '_::_' . $text_plural . '_'] ?? [$text_singular, $text_plural];
        $text = $count === 1 ? $forms[0] : $forms[1];
        return vsprintf(str_replace('%n', (string)$count, $text), $parameters);
    }

    public function l(string $type, $data, array $options = []) {
        return false;
    }

    public function getLanguageCode(): string {
        return $this->language;
    }

    public function getLocaleCode(): string {
        return $this->language;
    }
}
