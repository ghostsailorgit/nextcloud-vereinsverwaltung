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
| `verein_fees` | Beiträge je Person und Verein; `period` (z. B. 2026) kennzeichnet Jahresbeiträge, `dunning_level` (0-3) und `last_dunned_at` das Mahnwesen |
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
**Personenbezogene Felder werden im Protokoll nie im Klartext gespeichert**, nur die Tatsache, dass sich das Feld
geändert hat (`{"redacted": true}` bzw. `true` bei „angelegt“). Umgesetzt als Erlaubnisliste, nicht als Sperrliste
(`AuditLogService::SAFE_FIELDS`): für Mitglieder werden nur `id`, `role`, `joinDate`, `leaveDate`, `foundingMember`,
`deactivated`, `deceased`, `clubId` im Klartext protokolliert, alles andere wird redigiert - auch abgeleitete Felder
wie `fullName`, `mandateReference`, `mandateFile` (der Pfad enthält oft den Namen) oder `age`, die eine Sperrliste
leicht übersieht. Einsehbar je Verein im Reiter „Protokoll“ bzw. über `GET /audit-log` (Recht „Änderungsprotokoll einsehen“), neueste
zuerst, 100 Einträge je Seite (`beforeId` = älteste ID der vorigen Seite, `hasMore` sagt, ob es ältere gibt), filterbar nach
Bereich. Die Antwort enthält die Erlaubnisliste (`safeFields`), damit die Oberfläche ein geschwärztes Feld von einem echten
Ja/Nein-Feld unterscheiden kann, ohne eine eigene Kopie der Liste zu pflegen. Namen von Personen zeigt die Ansicht nur,
wenn man auch Mitglieder ansehen darf (sonst „Person #id“). Einträge ohne Verein (Rollendefinitionen, Anonymisieren)
erscheinen dort nicht.

## Anonymisieren
Statt eine Person zu löschen (`POST /members/{id}/anonymize`, Recht „Rollen verwalten“, wie bei Deaktivieren; die
Person muss laut `MemberController::anonymize()` Mitglied im aufrufenden Verein sein, sonst 404 - sonst könnte jeder
Rolleninhaber irgendeines Vereins jede Person anonymisieren): Name, Anschrift, E-Mail, IBAN/BIC, Geburtsdatum und die
Konto-Verknüpfung werden durch Platzhalter ersetzt, `anonymized_at` wird gesetzt. Der Datensatz bleibt bestehen, damit
Beiträge und SEPA-Historie weiter der (jetzt anonymen) Person zuzuordnen sind - eine harte Löschung würde die
Aufbewahrungspflicht der Buchhaltung verletzen. Geht erst, wenn die Person in jedem Verein ausgetreten (oder
verstorben) ist - eine noch aktive Mitgliedschaft braucht die Daten. Ältere Protokolleinträge zu der Person und all
ihren Mitgliedschaften werden beim Anonymisieren nachträglich redigiert (`AuditLogService::scrubEntity()`), nicht nur
künftige. Der Vorgang selbst wird für jeden Verein der Person protokolliert, damit er in jedem Reiter „Protokoll“
erscheint. Es gibt kein Zurück: `anonymized_at` bleibt gesetzt.
**Nicht betroffen:** `mandateReference`/`mandateFile` an der Mitgliedschaft (kann den Namen enthalten) und die
unterschriebene Mandatsdatei in Nextcloud Files selbst - Mandate haben eine eigene Aufbewahrungsfrist. Ebenso nicht
betroffen: Freitext in Beitragsbeschreibungen. In der Oberfläche (Knopf „Anonymisieren“ in der Mitgliederliste, nur bei Ehemaligen und nur mit
„Rollen verwalten“) muss vor dem Auslösen der vollständige Name eingetippt werden - ein normaler Bestätigungsdialog wird
zu leicht weggeklickt. Über die API gibt es die Rückfrage bewusst nicht, das wäre nur Reibung ohne Nutzen. Der Knopf
„Datenauskunft“ daneben lädt `GET /members/{id}/export` als JSON-Datei (für alle, die Mitglieder ansehen dürfen).

## Selbstauskunft („Meine Daten“) und Admin-Export
Wer mit einer Person verknüpft ist, sieht seine eigenen Daten, Mitgliedschaften und Beiträge und kann sie als
JSON herunterladen (`GET /me/export`). Das geht ohne Rolle. Es ist bewusst nur lesend (siehe SEPA-Betrugsrisiko bei
selbst geänderten IBANs). Für eine Auskunft nach Art. 15 DSGVO an eine Person, die sich nicht (mehr) selbst anmelden
kann, exportiert `GET /members/{id}/export` dieselben Daten - aber nur Mitgliedschaft und Beiträge **des aufrufenden
Vereins**, nicht die anderer Vereine der Person. Das Recht ist dasselbe wie für die normale Mitgliederansicht
(`verein.member.view`), da es nichts zeigt, was dort nicht ohnehin sichtbar ist; `MemberController::export()` prüft
zuerst mit `find()`, dass die Person überhaupt Mitglied des aufrufenden Vereins ist (404 sonst).

## Beiträge
- **Kategorien** (Reiter „Verein“): z. B. Erwachsene 24 €, Jugend 12 €, Ehrenmitglied 0 €. Eine Kategorie ist der Standard;
  jede Mitgliedschaft kann im Mitgliederformular eine eigene Kategorie bekommen.
- **Beitragslauf** (Reiter „Finanzen“): Jahr und Fälligkeit wählen, Vorschau ansehen, erzeugen. Aktive Mitglieder bekommen ihren
  Jahresbeitrag; übersprungen werden Ausgetretene und Verstorbene, spätere Eintritte, Beitragsfreie, Mitglieder ohne Kategorie
  (und ohne Standard) sowie alle, die für das Jahr schon einen Beitrag haben. Ein Lauf lässt sich deshalb wiederholen.
  Wahlweise **anteilig**: Wer im Beitragsjahr eingetreten ist, zahlt die Monate ab dem Eintrittsmonat (einschließlich) bis
  Dezember (Jahresbeitrag × Monate / 12, auf Cent gerundet). Eintritt im Januar, früher oder ohne Datum = ganzes Jahr, damit
  fehlende Daten nicht zu einem zu niedrigen Beitrag führen. Die Option gilt je Lauf, nicht je Verein (keine gespeicherte
  Einstellung); ein Austritt im Beitragsjahr wird nicht anteilig gerechnet (Ausgetretene bekommen keinen Beitrag).
- **Nach dem SEPA-Export** bietet die Seite an, genau die exportierten Beiträge als bezahlt zu markieren (erst nach dem Einreichen bei der Bank).
- **Überfällige markieren** setzt offene Beiträge mit abgelaufener Fälligkeit auf „überfällig“.
- **Mahnwesen** (`DunningService`, Reiter „Finanzen“, Recht „Finanzen bearbeiten“): Beiträge, die offen oder überfällig und seit
  mindestens N Tagen fällig sind, ergeben ein Schreiben je Person mit allen solchen Beiträgen. Stufe = höchste bisherige Stufe
  der Beiträge + 1: Zahlungserinnerung, 1. Mahnung, 2. und letzte Mahnung; danach wird die Person mit Grund übersprungen.
  Übersprungen werden außerdem deaktivierte (keine Zahlungsvorgänge) und anonymisierte Personen sowie alle, die innerhalb
  des eingestellten Abstands schon ein Schreiben bekommen haben - ein Lauf lässt sich deshalb gefahrlos wiederholen.
  Vorschau zuerst, der Lauf setzt Stufe und Datum in einer Transaktion (je Stufe eine SQL-Anweisung) und macht offene
  Beiträge überfällig. Die Schreiben (`GET /dunning/letters`, PDF für DIN-Fensterumschläge, sortiert nach Nachname) nennen
  die Bankverbindung des Standardkontos und lassen sich später erneut erzeugen, jeweils mit der aktuellen Stufe. Die App
  verschickt nichts; bezahlte Beiträge behalten ihre Stufe als Verlauf.

## Mitgliederimport
CSV-Import (`MemberImportService`, Knopf „CSV importieren“ im Reiter „Mitglieder“, Recht „Mitglieder verwalten“). Die Oberfläche
liest die Datei und schickt sie als UTF-8 (Windows-1252 von Excel wird erkannt); der Server erkennt Trennzeichen und Spalten
über deutsche und englische Überschriften, darunter die des eigenen Exports, und prüft jede Zeile mit denselben Regeln wie
das Mitgliederformular. Zeilennummern entsprechen den Zeilen der Tabellenkalkulation. Doppelte werden gemeldet, nicht
angelegt: gleicher Vor- und Nachname gilt als dieselbe Person, außer beide Geburtsdaten sind bekannt und verschieden.
Gesucht wird nur im importierenden Verein und in der Datei - ob es die Person in einem anderen Verein gibt, verrät der Import
nicht (dafür gibt es die Suche mit der Zwei-Vereins-Prüfung). Eine Funktion außer „Mitglied“ wird nur mit „Rollen verwalten“
übernommen, eine Konto-Verknüpfung nie (sie steuert Rechte). Angelegt wird in Paketen zu höchstens 100 Zeilen (die
Oberfläche schickt 20), jede Zeile über `MemberService::create()` samt Protokoll.

## SEPA-Lastschrift
- Pro Verein und Bankkonto wird eine pain.008.001.02-Datei erzeugt.
- Eingezogen wird nur, wenn die Person eine IBAN **und** ein Mandat mit Unterschriftsdatum hat. Alle anderen werden in
  Vorschau und Export mit Grund gemeldet.
- Das unterschriebene Mandat liegt als normale Datei im Team-Ordner des Vereins in Nextcloud Files; in der
  Datenbank steht nur der Pfad.
- Sequenztyp immer `RCUR`, fehlende BIC wird als `NOTPROVIDED` (IBAN-only) gesendet, Entgeltregelung `SLEV`.
- Texte (Namen, Verwendungszweck) werden in den SEPA-Zeichensatz umgesetzt (`a-z A-Z 0-9 / - ? : ( ) . , ' + Leerzeichen`;
  Umlaute ausgeschrieben, `&` → `+`) und auf 70 bzw. 140 Zeichen gekürzt. IBAN, BIC und Gläubiger-ID ohne Leerzeichen, groß.
- Was die Bank ablehnen würde, kommt nicht in die Datei, sondern in die Liste „nicht enthalten“ mit Grund: Betrag ≤ 0,
  ungültige IBAN/BIC, Unterschriftsdatum ungültig oder in der Zukunft, Mandatsreferenz außerhalb des Zeichensatzes.
  Ein ungültiges Vereinskonto (IBAN, BIC, Gläubiger-ID) bricht den Export ab.
- Beträge und Kontrollsumme in Cent (keine Float-Summe), Nachrichten-ID je Datei eindeutig (Verein, Zeitstempel, Zufall),
  frühestes Einzugsdatum fünf TARGET2-Bankarbeitstage nach heute (lokales Datum).
- Nie vor Fälligkeit: Beiträge mit Fälligkeit bis zum frühesten Datum (oder ohne lesbare Fälligkeit) werden zu diesem
  eingezogen, Beiträge mit Fälligkeit bis 14 Kalendertage danach an ihrem Fälligkeitstag (nächster TARGET2-Tag), je Datum
  ein eigener `PmtInf`-Block; später fällige werden als „noch nicht fällig“ gemeldet und bleiben offen.
- `SepaServiceTest` prüft die Datei gegen das offizielle Schema pain.008.001.02; die XSD wird wegen der ISO-Lizenz nicht
  eingecheckt, sondern in der CI geladen (Commit und Prüfsumme fest, siehe `.github/workflows/tests.yml`).

## Sicherung
Täglich läuft ein Nextcloud-Hintergrundjob (`DailyBackupJob`), der alle Vereinstabellen als komprimierte JSON-Datei im
App-Datenordner ablegt (`BackupService`). Alte Sicherungen werden nach 30 Tagen gelöscht, die neuesten 7 bleiben immer erhalten,
höchstens 100 werden aufbewahrt. Nextcloud-Administratoren sehen die Liste im Reiter „Verein“, können sofort sichern und
herunterladen. Die Sicherung braucht keine Datenbank-Werkzeuge und läuft auf jeder von Nextcloud unterstützten Datenbank.
Zurückspielen: `occ verein:backup:list` und `occ verein:backup:restore <Name oder Pfad>` (nur per Kommandozeile, bewusst nicht in der
Weboberfläche). Vorher wird automatisch eine Sicherung des aktuellen Stands angelegt, das Ersetzen läuft in einer Transaktion.
Der Kalender braucht keine Sicherung: Er wird aus den Mitgliederdaten erzeugt.
Das Zurückspielen wird in der CI mit SQLite, MySQL, MariaDB und PostgreSQL geprüft (für PostgreSQL werden die ID-Zähler nachgezogen).

## Kalender
Pro Verein ein Kalender „Vereinstermine <Verein>“ (der Name folgt der Sprache des Nutzers, englisch „Club events …“) mit jährlich wiederkehrenden Geburtstagen und Vereinsjubiläen (Eintrittstag)
aktiver Mitglieder - nicht für Ausgetretene, Verstorbene, Deaktivierte oder Anonymisierte.

Seit 0.18 ist er ein **App-Kalender** über Nextclouds öffentliche Schnittstelle `OCP\Calendar\ICalendarProvider`
(`lib/Calendar/ClubCalendarProvider.php`, angemeldet in `Application::register()`): Nextcloud fragt die App bei jedem Abruf,
welche Kalender ein Nutzer hat, und die App liefert die Termine live aus den Mitgliederdaten. Es werden keine Termine
gespeichert, nichts muss synchron gehalten oder gelöscht werden, und die App braucht keine internen Klassen der
Kalender-App mehr. Sichtbar ist er für Mitglieder der im Verein eingetragenen Nextcloud-Gruppen (Reiter „Verein“,
„Kalendergruppen“) - die App prüft die Gruppenzugehörigkeit selbst; Nextcloud zeigt ihn nur lesend, in der Kalender-App und
per CalDAV (Handys). `ClubEvent` passt die Termine daran an, wie Nextclouds Einbindung (`AppCalendar`) sie liest.

Bis 0.17 lagen die Termine als Kopie in einem echten Kalender eines Admin-Kontos, der mit den Gruppen geteilt wurde; dafür
brauchte die App interne Klassen (`CalDavBackend`, Sharing), und beim Löschen von Personen blieben gelegentlich Termine
zurück. Der Reparaturschritt `RemoveLegacyCalendars` (in `info.xml` unter `repair-steps`) löscht diese alten Kalender beim
Update einmalig samt Terminen und Freigaben; schlägt das fehl, bleibt der alte Kalender stehen und kann in der Kalender-App
gelöscht werden. Die Spalte `verein_clubs.calendar_uri` bleibt nur, damit ältere Sicherungen einspielbar bleiben.

## Technik
- Backend: PHP, Nextcloud AppFramework (Controller → Service → Mapper/Entity), Migrationen in `lib/Migration`.
- Frontend: Vue 3 + `@nextcloud/vue`, gebaut mit Vite nach `js/dist/`: ein kleiner Einstieg `nextcloud-verein.mjs`, der Rest in
  `js/dist/chunks/` - selten Gebrauchtes (Statistik-Diagramme, Nextclouds Dateiauswahl, Datums-Sprachdateien) lädt der Browser
  erst bei Bedarf. Beim Kopieren auf einen Server immer den ganzen Ordner `js/dist/` mitnehmen. Der ausgewählte Verein liegt in
  `js/store/club.js`; `js/api.js` hängt die `clubId` an jede Anfrage. Die Adresse der Nextcloud (Unterordner, `index.php`)
  gibt die Seite dem Frontend über `data-url-root` mit (`js/absoluteUrl.js`).
- Tests: PHPUnit (`tests/Unit`), Nextcloud-Klassen kommen aus den `nextcloud/ocp`-Stubs.
- PDF-Export: TCPDF aus `vendor/` (im Release-Archiv enthalten, aus dem Quellcode `composer install --no-dev`); fehlt es,
  antwortet der Export mit 503 und einem Hinweis. Hat eine andere App schon eine `TCPDF`-Klasse geladen, wird diese
  benutzt statt die eigene ein zweites Mal zu deklarieren (sonst PHP-Fatal-Error).
- Release-Archiv: `scripts/build-archive.sh` (von `release.yml` und dem CI-Job `archive` benutzt) - gebautes Frontend,
  Produktions-Abhängigkeiten, TCPDF ohne Beispiele und ohne die ungenutzten Schriften (nur DejaVu Sans normal/fett und Helvetica), keine Dev-Dateien.
  Der CI-Job entpackt genau dieses Archiv in ein separates `custom_apps/` einer frischen Nextcloud, aktiviert es und lässt
  Smoke-Test und Hintergrundjobs über Webcron laufen.
- Übersetzungen: englische Quelltexte, Deutsch in `l10n/de.json` (einzige von Hand gepflegte Datei, `php scripts/l10n.php build`
  erzeugt den Rest; `L10nTest` meldet Fehlendes). Oberfläche und Meldungen kommen in der Sprache des Nutzers; PHP-Klassen
  bekommen `IL10N` als optionales letztes Argument (sonst englisch über `L10n\SourceL10n`). `occ` bleibt englisch.
  Dokumente (PDF, Mahnschreiben, SEPA-Verwendungszweck, der gespeicherte Standardtext eines Beitrags) kommen in der
  Sprache der Instanz, wenn in der config.php `default_language` oder `force_language` gesetzt ist, sonst in der Sprache
  dessen, der sie erstellt (`L10n\DocumentL10n`) - so sehen die Briefe gleich aus, egal wer sie druckt.
- Englisch ist die Quelle aller Übersetzungen: ein Begriff je Fachbegriff nach `docs/GLOSSARY.md` (Verein = club, Funktion =
  position, Beitragskategorie = fee category, Mahnwesen = payment reminders, …). `L10nTest` liest die verbotenen Wörter aus
  dem Glossar und prüft Stilregeln (… statt ..., typografische Anführungszeichen, keine fest formatierten Beträge/Daten).
- Datum und Beträge stehen nie formatiert im Text, sondern werden nach der Spracheinstellung formatiert: im Browser
  `js/format.js` (Nextclouds Gebietsschema des Kontos), in PHP `L10n\Formats` (mit der Erweiterung intl nach ICU, sonst
  deutsch bzw. englisch). Gespeicherte Werte bleiben intern (Anrede `Herr`/`Frau`/`Divers`/`Firma`, Funktion
  `member`/`treasurer`/`admin`); Oberfläche und CSV-Export zeigen die Bezeichnung, der Import nimmt beides an.
- PDFs nutzen DejaVu Sans (als Teilmenge eingebettet), damit auch Namen wie „Łukasz“ oder kyrillische korrekt erscheinen;
  Tabellenzellen stauchen zu lange Texte, statt überzulaufen.
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
