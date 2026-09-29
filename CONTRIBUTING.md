# Mitmachen

Schön, dass du mithelfen möchtest. Fehlermeldungen, Ideen und Pull Requests sind willkommen.

## Fehler melden und Ideen vorschlagen

Bitte ein [Issue](https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung/issues) anlegen. Für Fehler helfen: Nextcloud- und PHP-Version, was du getan hast, was du erwartet hast, was passiert ist und der passende Eintrag aus `nextcloud.log` (Zeilen mit „Verein:“).

**Keine echten Mitgliederdaten in Issues, Screenshots oder Logs posten.** Bitte Namen, Adressen, IBANs und Domains unkenntlich machen. **Sicherheitslücken bitte nicht öffentlich melden**, sondern wie in [SECURITY.md](SECURITY.md) beschrieben.

## Entwicklung

Voraussetzungen: PHP 8.2+, Composer, Node.js 24 (LTS; 26 wird in der CI mitgebaut).

```bash
composer install                       # PHPUnit und die Nextcloud-OCP-Stubs (Dev-Abhängigkeiten)
vendor/bin/phpunit                     # Unit-Tests, müssen grün bleiben
npm ci && npm run build                # baut js/dist/
php scripts/ApiDocs.php                # nach Routenänderungen: docs/API.md neu erzeugen
```

Zum Ausprobieren die App in eine Nextcloud-Entwicklungsinstanz nach `custom_apps/verein` legen (siehe [docs/INSTALLATION.md](docs/INSTALLATION.md)). Die Architektur und die Gründe hinter den Entscheidungen stehen in [docs/ARCHITEKTUR.md](docs/ARCHITEKTUR.md), die Regeln, an denen man sich leicht die Finger verbrennt, in [CLAUDE.md](CLAUDE.md) (englisch, gilt für Menschen genauso wie für KI-Assistenten).

## Was ein Pull Request mitbringen sollte

- **Tests** für neues Verhalten und für behobene Fehler (`tests/Unit`).
- **Rechte:** Jeder neue Endpunkt braucht `#[RequirePermission(...)]`, sonst schlägt `RoutePermissionsTest` an. Schreibende Endpunkte dürfen kein `@NoCSRFRequired` tragen.
- **Fehler:** Controller geben Ausnahmen an `$this->errorResponse($e)` weiter, sie bauen keine Antwort aus `$e->getMessage()`. Meldungen für Nutzer kommen aus `ValidationException` und `NotFoundException`.
- **Datenbank:** Schemaänderungen als Migration in `lib/Migration/`, neue Tabellen zusätzlich in `BackupService::TABLES`.
- **Doku:** `CHANGELOG.md` (neuer Eintrag oben, passend zur Version in `appinfo/info.xml`), `ROADMAP.md` und bei geänderten Regeln `docs/ARCHITEKTUR.md` im selben PR.
- **Keine echten Daten** im Code, in Tests oder in Commit-Texten. Beispiele: `Mustermann`, `cloud.example.org`, `DE89 3704 0044 0532 0130 00`.
- Kleine, gut lesbare Commits mit einer kurzen Beschreibung, **warum** etwas geändert wird.

Die Testsuite läuft bei jedem Push und Pull Request in GitHub Actions.

## Übersetzungen

Texte der Oberfläche werden auf Englisch geschrieben und übersetzt (Nextcloud-Konvention); Deutsch liegt in
`l10n/de.json`.

- Vue/JS: `import { t, n } from '/l10n'`, dann `t('verein', 'Delete {name}?', { name })` bzw.
  `n('verein', '%n letter', '%n letters', count)`. PHP: `$this->l->t('Delete %s?', [$name])` (`OCP\IL10N`).
- Nur einfache Zeichenketten in `t()` - keine Template-Literale oder Verkettung, sonst findet das Werkzeug sie nicht.
- Deutsche Übersetzung in `l10n/de.json` eintragen, dann `php scripts/l10n.php build` (erzeugt `de.js`, `de_DE.*`).
  `php scripts/l10n.php check` zeigt fehlende Übersetzungen; die Tests schlagen fehl, solange eine fehlt.

## Lizenz

Mit deinem Beitrag stimmst du zu, dass er unter der Lizenz der jeweiligen Datei veröffentlicht wird: AGPL-3.0-only in Dateien aus dem
Ursprungsprojekt, sonst AGPL-3.0-or-later (steht im SPDX-Kopf jeder Datei). Neue Code-Dateien bekommen einen solchen Kopf:

```
SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
SPDX-License-Identifier: AGPL-3.0-or-later
```

Die CI prüft das mit `reuse lint`; Dateien ohne Kopf (Doku, Konfiguration) werden in `REUSE.toml` erfasst.
