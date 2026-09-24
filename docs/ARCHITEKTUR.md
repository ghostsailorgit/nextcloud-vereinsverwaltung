# Architektur der Vereinsverwaltung

Diese Übersicht ist für Menschen gedacht, die am Projekt mitarbeiten. Für Claude Code gibt es zusätzlich
die `CLAUDE.md` im Hauptverzeichnis.

## Grundgedanke
Ein **Verein** steht ganz oben. Mitglieder hängen nicht direkt am Verein, sondern über eine
**Mitgliedschaft**. So kann dieselbe **Person** in mehreren Vereinen sein, mit je eigenem Eintrittsdatum,
eigener Funktion und eigenem SEPA-Mandat.

```
Verein ──< Mitgliedschaft >── Person ── (optional) Nextcloud-Konto
  │              │
  │              └─ Funktion, Eintritt/Austritt, Gründungsmitglied, SEPA-Mandat
  ├──< Bankkonto  (IBAN, BIC, Gläubiger-ID)
  ├──< Beitrag    (Betrag, Status, Fälligkeit, gehört zu Person + Verein)
  └──< Rollenzuweisung (Nextcloud-Benutzer + App-Rolle, je Verein)
```

| Tabelle | Inhalt |
|---|---|
| `verein_clubs` | Name (eindeutig), Team-Ordner, Kalendergruppen, Zuordnung „Vereinsfunktion → App-Rolle“ |
| `verein_club_accounts` | Bankkonten eines Vereins, ein Standardkonto |
| `verein_members` | Personen: Anschrift, Geburtsdatum, eigene IBAN/BIC, verknüpftes Nextcloud-Konto |
| `verein_memberships` | Person × Verein: Funktion, Eintritt, Austritt, Mandat (Referenz, Datum, Datei) |
| `verein_fees` | Beiträge je Person und Verein |
| `verein_roles` | Rollendefinitionen (gelten für alle Vereine, nur Nextcloud-Admins ändern sie) |
| `verein_user_roles` | wer hat welche Rolle in welchem Verein |

## Rechte
Drei Quellen, die sich addieren:
1. **Nextcloud-Administratoren** dürfen alles in allen Vereinen.
2. **Manuell zugewiesene Rollen** (Reiter „Rollen“), immer für einen bestimmten Verein.
3. **Automatische Rechte:** Im Reiter „Verein“ wird je Vereinsfunktion (Mitglied / Kassierer / Vorstand) eine
   App-Rolle festgelegt. Wer im Mitgliederformular mit einem Nextcloud-Konto verknüpft ist und eine *aktive*
   Mitgliedschaft hat, bekommt diese Rolle automatisch. Bei Austritt oder Tod enden die Rechte sofort.
   Ohne eingerichtete Zuordnung passiert nichts.

Damit sich niemand selbst befördert, dürfen **Funktion und Konto-Verknüpfung nur ändern, wer „Rollen verwalten“
darf**. Vereine anlegen/löschen und Rollendefinitionen ändern dürfen nur Nextcloud-Administratoren.

Jeder Endpunkt ist mit `#[RequirePermission('verein.…')]` geschützt; ein Test stellt sicher, dass keiner
vergessen wird. Rechte: `verein.member.view/manage`, `verein.finance.read/write/delete/export`,
`verein.sepa.export`, `verein.role.manage`, `verein.club.manage`.

## Selbstauskunft („Meine Daten“)
Wer mit einer Person verknüpft ist, sieht seine eigenen Daten, Mitgliedschaften und Beiträge und kann sie als
JSON herunterladen. Das geht ohne Rolle. Es ist bewusst nur lesend (siehe SEPA-Betrugsrisiko bei
selbst geänderten IBANs).

## SEPA-Lastschrift
- Pro Verein und Bankkonto wird eine pain.008.001.02-Datei erzeugt.
- Eingezogen wird nur, wenn die Person eine IBAN **und** ein Mandat mit Unterschriftsdatum hat. Alle anderen werden in
  Vorschau und Export mit Grund gemeldet.
- Das unterschriebene Mandat liegt als normale Datei im Team-Ordner des Vereins in Nextcloud Files; in der
  Datenbank steht nur der Pfad.
- Sequenztyp immer `RCUR`, fehlende BIC wird als `NOTPROVIDED` (IBAN-only) gesendet.

## Kalender
Pro Verein ein Kalender „Vereinstermine <Verein>“ mit jährlich wiederkehrenden Geburtstagen und Jubiläen aktiver
Mitglieder. Er wird nur intern mit den je Verein festgelegten Nextcloud-Gruppen geteilt, nie öffentlich.

## Technik
- Backend: PHP, Nextcloud AppFramework (Controller → Service → Mapper/Entity), Migrationen in `lib/Migration`.
- Frontend: Vue 3 + `@nextcloud/vue`, gebaut mit Vite nach `js/dist/`. Der ausgewählte Verein liegt in
  `js/store/club.js`; `js/api.js` hängt die `clubId` an jede Anfrage.
- Tests: PHPUnit (`tests/Unit`), Nextcloud-Klassen kommen aus den `nextcloud/ocp`-Stubs.

## Entwicklung
```
composer update
vendor/bin/phpunit --testsuite "Unit Tests"
npm install && npm run build
```
Bei jeder Änderung, die Rechte betrifft, bitte mit einem echten Nicht-Admin-Konto testen. Beim Ausspielen
auf eine Nextcloud immer aus einem frischen Verzeichnis kopieren und danach die Dateien per Prüfsumme mit dem Repo
vergleichen. Für jede Veröffentlichung `<version>` in `appinfo/info.xml` erhöhen.

## Datenschutz im Repo
Keine echten Mitgliederdaten, Domains, Servernamen oder Firmenbezüge in Code, Tests, Doku oder Commit-Texten.
