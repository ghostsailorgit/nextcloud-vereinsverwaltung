<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Translations of the app (Nextcloud convention: English source strings, translations in l10n/<lang>.json).
 *
 *   php scripts/l10n.php check   list source strings without a German translation (exit 1) and unused translations
 *   php scripts/l10n.php build   write l10n/de.js, l10n/de_DE.json and l10n/de_DE.js from l10n/de.json
 *
 * l10n/de.json is the only file edited by hand. Source strings are found in
 *   - JS/Vue: t('verein', '...') and n('verein', '...', '...', count)
 *   - PHP:    ->t('...') and ->n('...', '...', count) on an IL10N instance
 * Only plain string literals are recognised - no template literals or concatenation inside t(); use placeholders:
 * t('verein', 'Delete {name}?', { name }) / $l->t('Delete %s?', [$name]).
 * "de" is Nextcloud's informal German, "de_DE" the formal one; both get the same texts for now.
 */

declare(strict_types=1);

namespace OCA\Verein\Scripts;

const ROOT = __DIR__ . '/..';

/** @return array<string, string[]> source key => places ("file:line"); plural keys as "_sing_::_plural_" */
function extract(): array {
    $found = [];
    $add = function (string $key, string $file, int $line) use (&$found): void {
        $found[$key][] = substr($file, strlen(ROOT) + 1) . ':' . $line;
    };
    $str = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")';
    $files = [];
    foreach (['js', 'lib', 'templates'] as $dir) {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = str_replace('\\', '/', $f->getPathname());
            if (!str_contains($p, '/js/dist/') && preg_match('/\.(js|vue|php)$/', $p)) {
                $files[] = $p;
            }
        }
    }
    sort($files);
    foreach ($files as $p) {
        $src = file_get_contents($p);
        $isPhp = str_ends_with($p, '.php');
        $single = $isPhp ? '/->t\(\s*' . $str . '/' : '/\bt\(\s*[\'"]verein[\'"]\s*,\s*' . $str . '/';
        $plural = $isPhp ? '/->n\(\s*' . $str . '\s*,\s*' . $str . '/' : '/\bn\(\s*[\'"]verein[\'"]\s*,\s*' . $str . '\s*,\s*' . $str . '/';
        foreach ([[$single, false], [$plural, true]] as [$re, $isPlural]) {
            preg_match_all($re, $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($m as $hit) {
                $line = substr_count(substr($src, 0, $hit[0][1]), "\n") + 1;
                $key = $isPlural ? '_' . unquote($hit[1][0]) . '_::_' . unquote($hit[2][0]) . '_' : unquote($hit[1][0]);
                $add($key, $p, $line);
            }
        }
    }
    // Nextcloud translates the navigation entries of appinfo/info.xml with the app's translations
    $info = (string)file_get_contents(ROOT . '/appinfo/info.xml');
    if (preg_match_all('#<navigation>.*?<name>([^<]+)</name>#s', $info, $nav, PREG_OFFSET_CAPTURE)) {
        foreach ($nav[1] as [$name, $offset]) {
            $add(html_entity_decode($name, ENT_XML1), ROOT . '/appinfo/info.xml', substr_count(substr($info, 0, $offset), "\n") + 1);
        }
    }
    ksort($found);
    return $found;
}

function unquote(string $literal): string {
    return stripcslashes(substr($literal, 1, -1));
}

/** @return array{translations: array<string, string|string[]>, pluralForm: string} */
function readDe(): array {
    $data = json_decode((string)file_get_contents(ROOT . '/l10n/de.json'), true, 512, JSON_THROW_ON_ERROR);
    return ['translations' => $data['translations'] ?? [], 'pluralForm' => $data['pluralForm'] ?? 'nplurals=2; plural=(n != 1);'];
}

/** @return array<string, string> file => expected content of the generated files */
function generated(): array {
    $de = readDe();
    $json = json_encode(['translations' => $de['translations'], 'pluralForm' => $de['pluralForm']],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $js = 'OC.L10N.register(' . "\n" . '    "verein",' . "\n    "
        . str_replace("\n", "\n    ", json_encode($de['translations'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        . ",\n    " . json_encode($de['pluralForm']) . "\n);\n";
    return ['l10n/de.js' => $js, 'l10n/de_DE.json' => $json, 'l10n/de_DE.js' => $js];
}

/** @return array{missing: array<string, string[]>, unused: string[], outdated: string[]} */
function check(): array {
    $source = extract();
    $de = readDe()['translations'];
    $missing = array_diff_key($source, $de);
    $unused = array_keys(array_diff_key($de, $source));
    $outdated = [];
    foreach (generated() as $file => $content) {
        if (!is_file(ROOT . '/' . $file) || file_get_contents(ROOT . '/' . $file) !== $content) {
            $outdated[] = $file;
        }
    }
    return ['missing' => $missing, 'unused' => $unused, 'outdated' => $outdated];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $mode = $argv[1] ?? 'check';
    if ($mode === 'build') {
        foreach (generated() as $file => $content) {
            file_put_contents(ROOT . '/' . $file, $content);
            echo "wrote $file\n";
        }
        exit(0);
    }
    $r = check();
    foreach ($r['missing'] as $key => $places) {
        echo "MISSING  \"$key\"  (" . implode(', ', $places) . ")\n";
    }
    foreach ($r['unused'] as $key) {
        echo "unused   \"$key\"\n";
    }
    foreach ($r['outdated'] as $file) {
        echo "OUTDATED $file - run: php scripts/l10n.php build\n";
    }
    echo count(extract()) . ' source strings, ' . count($r['missing']) . ' without German translation' . "\n";
    exit($r['missing'] === [] && $r['outdated'] === [] ? 0 : 1);
}
