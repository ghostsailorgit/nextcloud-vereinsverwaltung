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
| `verein_members` | Personen: Anschrift, Geburtsdatum, eigene IBAN/BIC, verknüpftes Nextcloud-Konto, `anonymized_at` (siehe „Anonymisieren“) |
| `verein_memberships` | Person × Verein: Funktion, Eintritt, Austritt, Mandat (Referenz, Datum, Datei) |
| `verein_fee_rates` | Beitragskategorien eines Vereins (Name, Jahresbetrag, eine Standardkategorie; 0 € = beitragsfrei) |
| `verein_fees` | Beiträge je Person und Verein; `period` (z. B. 2026) kennzeichnet Jahresbeiträge |
| `verein_roles` | Rollendefinitionen (gelten für alle Vereine, nur Nextcloud-Admins ändern sie) |
| `verein_user_roles` | wer hat welche Rolle in welchem Verein |
| `verein_audit_log` | Änderungsprotokoll: wer hat wann was geändert (siehe „Änderungsprotokoll“) |

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
`verein.sepa.export`, `verein.role.manage`, `verein.club.manage`, `verein.audit.view`.

## Mitglieder deaktivieren
Ein Mitglied lässt sich in einem Verein deaktivieren, statt es zu löschen (Reiter „Mitglieder“, Recht „Rollen verwalten“).
Das gilt für die Mitgliedschaft in diesem Verein, nicht für die Person in allen Vereinen. Ein deaktiviertes Mitglied
- wird im Beitragslauf übersprungen (mit Grund „deaktiviert“), bekommt keine neuen Beiträge (auch nicht von Hand),
- wird im SEPA-Export nicht eingezogen (Grund „Mitglied deaktiviert“; bereits offene Beiträge bleiben bestehen),
- hat keine Geburtstags- und Jubiläumstermine mehr im Vereinskalender,
- bekommt keine automatisch aus der Vereinsfunktion abgeleiteten Rechte mehr; explizit zugewiesene Rollen bleiben.
Es wird nichts gelöscht, „Aktivieren“ stellt alles wieder her. Das Recht „Rollen verwalten“ ist nötig, weil sich damit
Rechte entziehen und zurückgeben lassen (eine deaktivierte Person könnte sich sonst selbst wieder aktivieren).
Vorgesehen für ruhende Mitgliedschaften oder laufende Klärungen, nicht als Ersatz für den Austritt.

## Änderungsprotokoll
Jede Änderung an Mitgliedern/Mitgliedschaften, Beiträgen, Beitragskategorien, Vereinen (inkl. Bankkonten,
Rollen-Zuordnung) und Rollen/Zuweisungen wird protokolliert: wer (Nextcloud-Konto, Anzeigename zum Zeitpunkt
der Änderung), wann, welche Aktion (anlegen/ändern/löschen/deaktivieren/aktivieren/…) und bei Änderungen welche
Felder von welchem auf welchen Wert. Rein anfügend, nichts wird nachträglich bearbeitet.
**Aufbewahrung:** Einträge zu Mitgliedern, Mitgliedschaften, Beiträgen und Rollenzuweisungen (also zu Personen, ihren
Zahlungen und Zugriffsrechten) werden 10 Jahre aufbewahrt, alle übrigen (Vereine, Bankkonten, Beitragskategorien,
Beitragsläufe, Rollendefinitionen) 30 Tage. Ein täglicher Hintergrundjob (`AuditLogCleanupJob`) löscht Abgelaufenes;
die Zuordnung steht in `AuditLogService::LONG_RETENTION_TYPES`. Das Protokoll ist Teil der Sicherung.
**Personenbezogene Felder** (Name, Anschrift, E-Mail, IBAN/BIC, Geburtsdatum, Konto-Verknüpfung - `AuditLogService::SENSITIVE_FIELDS`)
werden nie im Klartext gespeichert, nur die Tatsache, dass sich das Feld geändert hat (`{"redacted": true}` bzw. `true`
bei „angelegt“). Einsehbar je Verein über die API (`GET /audit-log`, Recht „Änderungsprotokoll einsehen“); eine eigene
Ansicht in der Oberfläche gibt es noch nicht.

## Anonymisieren
Statt eine Person zu löschen (`POST /members/{id}/anonymize`, Recht „Rollen verwalten“, wie bei Deaktivieren): Name,
Anschrift, E-Mail, IBAN/BIC, Geburtsdatum und die Konto-Verknüpfung werden durch Platzhalter ersetzt, `anonymized_at`
wird gesetzt. Der Datensatz bleibt bestehen, damit Beiträge und SEPA-Historie weiter der (jetzt anonymen) Person
zuzuordnen sind - eine harte Löschung würde entweder die Aufbewahrungspflicht der Buchhaltung verletzen oder verwaiste
Beitragszeilen hinterlassen. Geht erst, wenn die Person in jedem Verein ausgetreten (oder verstorben) ist - eine
noch aktive Mitgliedschaft braucht die Daten. Ältere Protokolleinträge zu der Person und all ihren Mitgliedschaften
werden beim Anonymisieren nachträglich redigiert (`AuditLogService::scrubEntity()`), nicht nur künftige. Es gibt kein
Zurück: `anonymized_at` bleibt gesetzt.

## Selbstauskunft („Meine Daten“) und Admin-Export
Wer mit einer Person verknüpft ist, sieht seine eigenen Daten, Mitgliedschaften und Beiträge und kann sie als
JSON herunterladen (`GET /me/export`). Das geht ohne Rolle. Es ist bewusst nur lesend (siehe SEPA-Betrugsrisiko bei
selbst geänderten IBANs). Für eine Auskunft nach Art. 15 DSGVO an eine Person, die sich nicht (mehr) selbst anmelden
kann, exportiert `GET /members/{id}/export` dieselben Daten für ein einzelnes Mitglied; das Recht ist dasselbe wie für
die normale Mitgliederansicht (`verein.member.view`), da es nichts zeigt, was dort nicht ohnehin sichtbar ist.

## Beiträge
- **Kategorien** (Reiter „Verein“): z. B. Erwachsene 24 €, Jugend 12 €, Ehrenmitglied 0 €. Eine Kategorie ist der Standard;
  jede Mitgliedschaft kann im Mitgliederformular eine eigene Kategorie bekommen.
- **Beitragslauf** (Reiter „Finanzen“): Jahr und Fälligkeit wählen, Vorschau ansehen, erzeugen. Aktive Mitglieder bekommen ihren
  Jahresbeitrag; übersprungen werden Ausgetretene und Verstorbene, spätere Eintritte, Beitragsfreie, Mitglieder ohne Kategorie
  (und ohne Standard) sowie alle, die für das Jahr schon einen Beitrag haben. Ein Lauf lässt sich deshalb wiederholen.
  Anteilige Beiträge bei unterjährigem Eintritt gibt es noch nicht.
- **Nach dem SEPA-Export** bietet die Seite an, genau die exportierten Beiträge als bezahlt zu markieren (erst nach dem Einreichen bei der Bank).
- **Überfällige markieren** setzt offene Beiträge mit abgelaufener Fälligkeit auf „überfällig“. Mahnschreiben gibt es noch nicht.

## SEPA-Lastschrift
- Pro Verein und Bankkonto wird eine pain.008.001.02-Datei erzeugt.
- Eingezogen wird nur, wenn die Person eine IBAN **und** ein Mandat mit Unterschriftsdatum hat. Alle anderen werden in
  Vorschau und Export mit Grund gemeldet.
- Das unterschriebene Mandat liegt als normale Datei im Team-Ordner des Vereins in Nextcloud Files; in der
  Datenbank steht nur der Pfad.
- Sequenztyp immer `RCUR`, fehlende BIC wird als `NOTPROVIDED` (IBAN-only) gesendet.

## Sicherung
Täglich läuft ein Nextcloud-Hintergrundjob (`DailyBackupJob`), der alle Vereinstabellen als komprimierte JSON-Datei im
App-Datenordner ablegt (`BackupService`). Alte Sicherungen werden nach 30 Tagen gelöscht, die neuesten 7 bleiben immer erhalten,
höchstens 100 werden aufbewahrt. Nextcloud-Administratoren sehen die Liste im Reiter „Verein“, können sofort sichern und
herunterladen. Die Sicherung braucht keine Datenbank-Werkzeuge und läuft auf jeder von Nextcloud unterstützten Datenbank.
Zurückspielen: `occ verein:backup:list` und `occ verein:backup:restore <Name oder Pfad>` (nur per Kommandozeile, bewusst nicht in der
Weboberfläche). Vorher wird automatisch eine Sicherung des aktuellen Stands angelegt, das Ersetzen läuft in einer Transaktion.
Kalender und ihre Freigaben sind nicht Teil der Sicherung; sie werden beim nächsten Speichern eines Mitglieds angeglichen.
Getestet ist das Zurückspielen mit MariaDB/MySQL; für PostgreSQL werden die ID-Zähler nachgezogen, aber nicht praktisch geprüft.

## Kalender
Pro Verein ein Kalender „Vereinstermine <Verein>“ mit jährlich wiederkehrenden Geburtstagen und Jubiläen aktiver
Mitglieder. Er wird nur intern mit den je Verein festgelegten Nextcloud-Gruppen geteilt, nie öffentlich.

## Technik
- Backend: PHP, Nextcloud AppFramework (Controller → Service → Mapper/Entity), Migrationen in `lib/Migration`.
- Frontend: Vue 3 + `@nextcloud/vue`, gebaut mit Vite nach `js/dist/`. Der ausgewählte Verein liegt in
  `js/store/club.js`; `js/api.js` hängt die `clubId` an jede Anfrage. Die Adresse der Nextcloud (Unterordner, `index.php`)
  gibt die Seite dem Frontend über `data-url-root` mit (`js/absoluteUrl.js`).
- Tests: PHPUnit (`tests/Unit`), Nextcloud-Klassen kommen aus den `nextcloud/ocp`-Stubs.
- PDF-Export: TCPDF aus `vendor/` (`composer install --no-dev`); fehlt es, antwortet der Export mit 503 und einem Hinweis.
- API-Übersicht: `docs/API.md` wird aus den Routen erzeugt (`php scripts/ApiDocs.php`), ein Test hält sie aktuell.

## Fehlerbehandlung
Dienste werfen `ValidationException` (400), `NotFoundException` (404), `PermissionDeniedException` (403) oder
`DependencyMissingException` (503); deren Texte sind für Nutzer gedacht. Controller geben jede Ausnahme an
`RespondsWithErrors::errorResponse()` weiter. Alles Unerwartete wird als 500 mit allgemeinem Text beantwortet, die
Einzelheiten stehen nur im Nextcloud-Log (Datenbankfehler können SQL enthalten). Ein Test stellt sicher, dass kein
Controller einen Ausnahmetext selbst in eine Antwort schreibt.

## Entwicklung
```
composer update
vendor/bin/phpunit
npm ci && npm run build
```
Bei jeder Änderung, die Rechte betrifft, bitte mit einem echten Nicht-Admin-Konto testen. Beim Ausspielen
auf eine Nextcloud immer aus einem frischen Verzeichnis kopieren und danach die Dateien per Prüfsumme mit dem Repo
vergleichen. Für jede Veröffentlichung `<version>` in `appinfo/info.xml` erhöhen.

## Datenschutz im Repo
Das Repository ist öffentlich. Keine echten Mitgliederdaten, Domains, Servernamen, E-Mail-Adressen oder Firmenbezüge in Code, Tests, Doku oder Commit-Texten (Beispiele: `Mustermann`, `cloud.example.org`). Commits laufen unter der GitHub-noreply-Adresse.

## Sicherheit
- Rechte je Endpunkt (`#[RequirePermission]`), Mandantentrennung über `clubId` (jede ID-Abfrage prüft die Vereinszugehörigkeit).
- Schreibende Endpunkte behalten den CSRF-Schutz von Nextcloud; nur lesende Endpunkte und Downloads dürfen `@NoCSRFRequired` tragen (Test).
- SQL nur über Query-Builder mit Parametern, SEPA-XML mit Escaping, CSV-Export gegen Formelinjektion geschützt.
- Entscheidung (bewusst so belassen): Personen sind vereinsübergreifend geteilt. Wer in Verein A Mitglieder verwalten darf, kann die Stammdaten einer Person ändern, die auch in Verein B ist (Name, IBAN, „verstorben“).
