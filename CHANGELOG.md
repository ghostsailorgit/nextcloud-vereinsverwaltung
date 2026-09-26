# Änderungen

Alle wichtigen Änderungen dieser App. Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/), die Versionen folgen [Semantic Versioning](https://semver.org/lang/de/). Solange die App im Beta-Stadium ist, können sich Dinge zwischen Versionen noch ändern.

---

## [0.18.8-beta] - 2026-09-26

### 🌐 Übersetzungen
- Übersetzbar: Mahnschreiben, die PDF-Exporte der Mitglieder- und Gebührenliste, der Verwendungszweck im SEPA-Export und
  der Standardtext „Mitgliedsbeitrag <Jahr>“ im Beitragslauf. Diese Dokumente kommen in der Sprache der Nextcloud-Instanz,
  wenn der Administrator eine festgelegt hat (`default_language` oder `force_language` in der config.php); dann sehen die
  Briefe gleich aus, egal wer sie druckt. Ohne diese Einstellung gilt die Sprache dessen, der sie erstellt.
- Damit ist die App vollständig übersetzbar; Deutsch ist die mitgelieferte Übersetzung.

### 🐛 Korrigiert
- Der PDF-Export der Mitglieder- und Gebührenliste bekam keine Zeitzone und benannte die Datei nach der UTC-Zeit;
  jetzt wie überall die lokale Zeit.
- Im PDF der Gebührenliste erscheint der Status „storniert“ jetzt übersetzt statt als „cancelled“.
- Im PDF der Mitgliederliste steht in der Spalte „Rolle“ jetzt Mitglied, Kassierer oder Vorstand statt der internen Werte „member“ oder „admin“.

---

## [0.18.7-beta] - 2026-09-26

### 🌐 Übersetzungen
- Übersetzbar: alle Meldungen des Servers – Fehlermeldungen und Prüfhinweise, Gründe im Beitragslauf, Mahnwesen,
  SEPA-Export und Import, die Spaltenköpfe des CSV-Exports, die Monatsnamen im Dashboard sowie Name und Termine des
  Vereinskalenders. Sie kommen in der Sprache des Nutzers.
- Der CSV-Export schreibt bei englischer Sprache englische Spaltenköpfe; der Import erkennt beide.
- Die Spaltenköpfe des Beitrags-Exports waren englisch („Member ID“, „Amount“ …) und sind jetzt deutsch.
- Die `occ`-Befehle für Sicherungen antworten jetzt englisch, wie die Befehle von Nextcloud selbst.
- Noch deutsch: PDF-Exporte, Mahnschreiben und der Verwendungszweck im SEPA-Export – sie folgen als Nächstes, in der
  Standardsprache der Nextcloud.

---

## [0.18.6-beta] - 2026-09-26

### 🌐 Übersetzungen
- Übersetzbar: Navigation, Dashboard, Verein (Vereinsdaten, Bankkonten, Beitragskategorien, automatische Rechte),
  Rollen, Meine Daten und Änderungsprotokoll. Damit ist die gesamte Oberfläche übersetzbar; offen sind noch die Texte
  des Servers (Fehlermeldungen, PDF, Mahnschreiben).
- Mengenangaben („1 Eintrag“, „1 Jahr dabei“) nutzen korrekte Einzahl/Mehrzahl. Im Rollen-Hinweis ist die Hervorhebung
  des Vereinsnamens entfallen (lässt sich nicht sicher übersetzen).

### 🐛 Korrigiert
- Der Hinweis zum Vereinskalender im Dashboard beschrieb noch den alten, freigegebenen Kalender („unter Weitere
  Kalender aktivieren“). Er beschreibt jetzt den schreibgeschützten App-Kalender für die unter „Verein“ eingetragenen Gruppen.

---

## [0.18.5-beta] - 2026-09-26

### 🌐 Übersetzungen
- Übersetzbar: Finanzen (Gebührenliste, neue Gebühr, Status), Beitragslauf und SEPA-Export. Mengenangaben („1 Beitrag
  erzeugt“, „1 Beitrag als bezahlt markiert“) nutzen jetzt korrekte Einzahl/Mehrzahl; der Wortlaut ist sonst unverändert.

---

## [0.18.4-beta] - 2026-09-26

### 🌐 Übersetzungen
- Übersetzbar: Mitgliederverwaltung (Formular, Liste, Übernahme aus anderem Verein, Bestätigungen) und der CSV-Import.
  Anreden werden weiterhin als „Herr/Frau/Divers/Firma“ gespeichert (sie stehen so in Briefen); übersetzt wird nur die Anzeige
  in der Auswahl. Mengenangaben („1 Mitglied importiert“) nutzen jetzt korrekte Einzahl/Mehrzahl.

---

## [0.18.3-beta] - 2026-09-26

### 🌐 Übersetzungen
- Übersetzbar: Hinweisfenster, Export-Knöpfe (jetzt ganze Sätze statt zusammengesetzter Teile), Sicherungen und der
  Anonymisieren-Dialog. Im Anonymisieren-Dialog sind zwei Hervorhebungen innerhalb von Sätzen entfallen (sie lassen sich
  nicht sicher übersetzen); der Wortlaut ist unverändert.

---

## [0.18.2-beta] - 2026-09-26

Beginn der Übersetzbarkeit.

### 🌐 Übersetzungen
- Grundlage nach Nextcloud-Konvention: englische Quelltexte, die deutsche Oberfläche kommt als Übersetzung aus
  `l10n/de.json` (für „Du“ und „Sie“). Für deutsche Nutzer ändert sich nichts.
- Umgestellt: die Bestätigungsdialoge und das Mahnwesen. Die übrigen Bereiche folgen.
- `scripts/l10n.php` findet alle übersetzbaren Texte, prüft, dass jeder eine deutsche Übersetzung hat, und erzeugt die
  Dateien, die Nextcloud im Browser lädt; ein Test verhindert fehlende Übersetzungen. `l10n/` ist Teil des Release-Archivs.

---

## [0.18.1-beta] - 2026-09-26

Nextcloud-Dialoge und das richtige „heute“.

### ✨ Geändert
- **Rückfragen im Nextcloud-Stil:** Alle 12 Bestätigungen (Löschen, Entfernen, Deaktivieren, Beitragslauf, Mahnlauf, Import,
  „als bezahlt markieren“, Rollen entziehen …) nutzen jetzt Nextclouds eigenen Dialog statt des Browser-Fensters – mit
  Überschrift, einem Knopf, der die Aktion benennt („Löschen“, „Entfernen“ …), und roter Darstellung bei Löschaktionen.

### 🐛 Behoben
- **„Heute“ ist jetzt der lokale Tag.** Nextcloud rechnet intern in UTC; kurz nach Mitternacht (in Deutschland bis 1 bzw. 2 Uhr)
  galt deshalb noch der Vortag – für „Überfällige markieren“, Datum und Frist der Mahnschreiben, Einzugsdatum und Zeitangaben
  der SEPA-Datei, Exportdatum und Dateinamen. Die App nutzt dafür jetzt Nextclouds Uhr und die Zeitzone des Nutzers bzw. der
  Instanz. Zeitstempel in der Datenbank bleiben wie in Nextcloud UTC.

---

## [0.18.0-beta] - 2026-09-26

Der Vereinskalender kommt jetzt über Nextclouds offizielle Schnittstelle für App-Kalender.

### ✨ Geändert
- **Vereinskalender als App-Kalender** (`OCP\Calendar\ICalendarProvider`): Der Kalender „Vereinstermine <Verein>“ wird bei jedem
  Abruf aus den Mitgliederdaten erzeugt, statt Termine als Kopie in einem geteilten Kalender zu speichern. Wer in einer der
  Kalendergruppen des Vereins ist, sieht ihn automatisch in der Kalender-App und auf dem Handy (CalDAV), nur lesend. Deaktivieren,
  Austritt, Tod und Anonymisieren wirken sofort; es gibt keine zweite Kopie der Geburtsdaten mehr und keine vergessenen Termine
  (im alten Kalender der Test-Instanz stand noch der Termin einer längst gelöschten Person).
- Die App braucht dafür keine internen Klassen der Nextcloud-Kalender-App mehr – ein Nextcloud-Update kann den Kalender nicht
  mehr unbemerkt brechen.
- **Beim Update** löscht die App einmalig den bisherigen gespeicherten Kalender samt Terminen und Freigaben
  (Reparaturschritt `RemoveLegacyCalendars`). Scheitert das, bleibt er stehen und der Grund steht im Update-Protokoll.

### 🐛 Behoben
- `appinfo/info.xml` entsprach nicht Nextclouds Schema (Reihenfolge der Abschnitte) – der App Store hätte die App abgelehnt.
  Die CI prüft das jetzt.
- Unter PHP 8.4 und neuer schrieb Nextcloud bei jeder abgelehnten oder ungültigen Anfrage eine Deprecation-Meldung als
  Fehler ins Log (implizit nullbarer Parameter in `ValidationException`/`PermissionDeniedException`). Die CI scheitert jetzt an
  solchen Deprecations und an Warnungen der App im Nextcloud-Log.

### 🔧 Technik
- CI: der App-Kalender (sichtbar nur für Gruppenmitglieder, Termine per CalDAV, Schreiben abgewiesen, keine Log-Warnungen) und
  das Entfernen eines alten Kalenders laufen auf Nextcloud 33, 34 und 35 mit allen Datenbanken.
- `sabre/vobject` nur als Entwicklungsabhängigkeit (für Tests); zur Laufzeit nutzt die App die Kopie aus Nextcloud.

---

## [0.17.1-beta] - 2026-09-26

Lizenzangaben je Datei und ehrliche, geprüfte Kompatibilität.

### 📦 Kompatibilität
- **Nextcloud 33 bis 35, PHP 8.2 bis 8.5.** Bisher stand in `info.xml` Nextcloud 27–34 und PHP ab 8.1, getestet war aber
  nur Nextcloud 34 mit MySQL; Nextcloud 35 war sogar ausgeschlossen, die App ließ sich dort nicht installieren.
- Neue CI: installiert echte Nextcloud 33, 34 und 35 auf SQLite, MySQL, MariaDB und PostgreSQL, aktiviert die App,
  prüft Import, Beitragslauf, Mahnwesen mit PDF, Protokoll, Anonymisieren und Sicherung gegen die echte Datenbank,
  stellt HTTP-Anfragen als Admin und als Nicht-Admin und spielt eine Sicherung per `occ` zurück. Die Unit-Tests laufen
  gegen die Schnittstellen von Nextcloud 33 (älteste unterstützte Version).
- Der Frontend-Build funktioniert jetzt auch innerhalb eines Nextcloud-Quellbaums (eigene `tsconfig.json`).

### ⚖️ Lizenz
- Jede Datei nennt Urheber und Lizenz (SPDX, [REUSE](https://reuse.software), in der CI geprüft). Dateien aus dem
  Ursprungsprojekt tragen wieder dessen Urheberhinweis und stehen wie dort unter AGPL-3.0-only, neue Dateien unter
  AGPL-3.0-or-later. Neu: `AUTHORS.md`, `LICENSES/`, `REUSE.toml`; das Release-Archiv enthält sie.

---

## [0.17.0-beta] - 2026-09-26

Mitgliederimport aus CSV und Mahnwesen.

### ✨ Neu
- **Mitglieder aus CSV importieren** (Reiter „Mitglieder“ → „CSV importieren“, Recht „Mitglieder verwalten“): Die Datei wird
  zuerst nur geprüft; jede Zeile zeigt „bereit“, „schon vorhanden“ oder „fehlerhaft“ mit Grund. Erkannt werden Semikolon,
  Komma und Tab, UTF-8 und Windows-1252 (Excel), deutsche und englische Spaltennamen einschließlich des eigenen
  Mitglieder-Exports, Datumsangaben als TT.MM.JJJJ, TT.MM.JJ oder JJJJ-MM-TT, IBAN mit Leerzeichen, Beitragskategorien über
  ihren Namen. Doppelte (im Verein oder in der Datei) werden nicht angelegt; gleicher Name mit verschiedenem Geburtsdatum
  gilt als zwei Personen. Eine Funktion wie „Vorstand“ wird nur mit dem Recht „Rollen verwalten“ übernommen, eine
  Verknüpfung mit einem Nextcloud-Konto nie. Importiert wird in Paketen mit Fortschrittsanzeige.
- **Mahnwesen** (Reiter „Finanzen“, Recht „Finanzen bearbeiten“): Für Beiträge, deren Fälligkeit eine einstellbare Zeit
  zurückliegt, entsteht ein Schreiben je Person – Zahlungserinnerung, 1. Mahnung, 2. und letzte Mahnung. Wer vor kurzem
  schon ein Schreiben bekam, deaktiviert oder anonymisiert ist oder die letzte Stufe erreicht hat, wird mit Grund
  übersprungen. Die Schreiben kommen als PDF für Fensterumschläge (Absender, Anschrift, Beitragsliste, Frist,
  Bankverbindung des Vereins, Verwendungszweck) und lassen sich erneut herunterladen. Die Beitragsliste zeigt die Mahnstufe,
  das Protokoll jeden Mahnlauf.

### 🔧 Technik
- Migration `Version020009`: `verein_fees.dunning_level`, `verein_fees.last_dunned_at`.
- Neue Endpunkte: `POST /members/import/preview`, `POST /members/import`, `POST /dunning/preview`, `POST /dunning`,
  `GET /dunning/letters` (siehe `docs/API.md`).
- Doku: In `CLAUDE.md` fehlte seit 0.15.1 ein Stück von Regel 7 (`AuditLogEntry::$entityId`).

---

## [0.16.1-beta] - 2026-09-25

Oberfläche für die Datenschutz-Funktionen.

### ✨ Neu
- **Reiter „Protokoll“** (Recht „Änderungsprotokoll einsehen“): wer hat wann was geändert, neueste zuerst, 100 Einträge je
  Seite mit „Ältere Einträge laden“, Filter nach Bereich (Person, Mitgliedschaft, Beitrag, Beitragslauf …). Änderungen
  werden lesbar dargestellt („Straße: geändert (Inhalt nicht protokolliert)“, „Status: offen → bezahlt“); personenbezogene
  Inhalte stehen wie bisher nie im Klartext. Namen erscheinen nur, wenn man auch Mitglieder ansehen darf.
- **Anonymisieren in der Mitgliederliste** (Recht „Rollen verwalten“, nur bei Ehemaligen): Der Dialog erklärt, was ersetzt
  wird und was erhalten bleibt, und lässt sich erst bestätigen, wenn der vollständige Name eingetippt ist. Ist die Person
  in einem anderen Verein noch aktiv, lehnt der Server ab und die Meldung wird angezeigt. Anonymisierte Personen tragen
  das Kennzeichen „Anonymisiert“.
- **Datenauskunft** in der Mitgliederliste: lädt alle gespeicherten Daten einer Person in diesem Verein als JSON-Datei
  (Auskunft nach Art. 15 DSGVO) für Personen, die „Meine Daten“ nicht selbst nutzen können.

### 🔧 Technik
- `GET /audit-log`: Blättern über `beforeId`, Antwort mit `hasMore` und der Erlaubnisliste `safeFields`; einheitliche
  Fehlerbehandlung wie bei den anderen Endpunkten (sie fehlte hier).
- 0.16.0-beta war nur ein interner Testbuild (die Version musste für den Browser-Cache erneut erhöht werden).

---

## [0.15.1-beta] - 2026-09-25

### 🐛 Behoben
- **Sammelaktionen meldeten „Interner Fehler“, obwohl sie ausgeführt waren**, und landeten nicht im Änderungsprotokoll:
  Beitragslauf, „als bezahlt markieren“ (nach dem SEPA-Export), „Überfällige markieren“ und das Entziehen aller Rollen
  eines Kontos. Diese Aktionen protokollieren mit Datensatz-Nummer 0; weil 0 auch der Startwert des Feldes war, schrieb
  Nextcloud die Spalte nicht mit, und MySQL lehnte den Protokolleintrag ab (`entity_id` hat keinen Standardwert). Die
  Daten selbst waren zu dem Zeitpunkt schon gespeichert, nur die Antwort war ein Fehler. Seit 0.12.0 vorhanden, beim
  Live-Test der anteiligen Beiträge gefunden. Ein Test (`EntityInsertTest`) hält es fest.

---

## [0.15.0-beta] - 2026-09-25

Anteilige Beiträge im Beitragslauf.

### ✨ Neu
- **Anteilige Beiträge bei Eintritt im laufenden Jahr:** Der Beitragslauf hat die Option „Anteilig bei Eintritt im
  Beitragsjahr“ (Parameter `prorata` bei `POST /fee-run/preview` und `POST /fee-run`). Ist sie gesetzt, zahlt, wer im
  Beitragsjahr eingetreten ist, nur die Monate ab dem Eintrittsmonat (einschließlich) bis Dezember: Jahresbeitrag × Monate / 12,
  auf Cent gerundet (Eintritt im Oktober bei 24 € = 3/12 = 6,00 €). Eintritt im Januar, in einem früheren Jahr oder ohne
  Eintrittsdatum zählt als ganzes Jahr. Ergibt die Rechnung 0,00 €, wird die Person mit Grund übersprungen. Die Vorschau zeigt
  bei anteiligen Beiträgen „anteilig n/12 von <Jahresbetrag>“. Ohne die Option ändert sich nichts (voller Jahresbeitrag).
- Das Änderungsprotokoll des Beitragslaufs vermerkt, ob anteilig gerechnet wurde.

---

## [0.14.0-beta] - 2026-10-01

Datenschutz vervollständigt (Löschen/Anonymisieren, Admin-Export, Protokoll-Redaktion).

### ✨ Neu
- **Anonymisieren statt löschen:** `POST /members/{id}/anonymize` (Recht „Rollen verwalten“) ersetzt Name,
  Anschrift, E-Mail, IBAN/BIC, Geburtsdatum und Konto-Verknüpfung einer Person durch Platzhalter. Beiträge und
  SEPA-Historie bleiben erhalten. Geht erst, wenn die Person überall ausgetreten oder verstorben ist. Die Person
  muss außerdem Mitglied des aufrufenden Vereins sein (sonst 404) - sonst könnte jeder Inhaber von „Rollen
  verwalten" in irgendeinem Verein jede Person eines fremden Vereins anonymisieren.
- **Admin-Datenexport:** `GET /members/{id}/export` liefert die gleiche JSON-Auskunft wie die Selbstauskunft,
  für Personen, die sich nicht selbst anmelden können (Art. 15 DSGVO), mit demselben Recht wie die normale
  Mitgliederansicht. Wie beim Anonymisieren muss die Person Mitglied des aufrufenden Vereins sein (sonst 404),
  und der Export enthält nur die Mitgliedschaft und die Beiträge dieses Vereins, nicht die anderer Vereine der
  Person.
- **Änderungsprotokoll ohne Klartext:** Personenbezogene Felder werden im Protokoll nur noch als „geändert“
  vermerkt, nicht mit Inhalt - als Erlaubnisliste, nicht als Sperrliste (`AuditLogService::SAFE_FIELDS`): für
  Mitglieder nur `id`, `role`, `joinDate`, `leaveDate`, `foundingMember`, `deactivated`, `deceased`, `clubId` im
  Klartext, alles andere geschwärzt - auch abgeleitete Felder wie `fullName`, `mandateReference`, `mandateFile`
  (der Pfad enthält oft den Namen) oder `age`, die eine Sperrliste leicht übersieht. Beim Anonymisieren werden
  auch ältere, schon gespeicherte Einträge der Person nachträglich redigiert (`AuditLogService::scrubEntity()`).

### 🔧 Technik
- Migration `Version020008`: `verein_members.anonymized_at`.
- Doku: Die getesteten Versionen sind korrigiert (Test-Instanz: PHP 8.5, MySQL 8.4 statt MariaDB; Unit-Tests in der CI mit PHP 8.3).
- CI: Die Unit-Tests laufen jetzt auf PHP 8.3 und 8.5 (Matrix in `.github/workflows/tests.yml`).

---

## [0.13.0-beta] - 2026-09-30

Aufräumen und Vorbereitung auf die Veröffentlichung.

### 🐛 Fehlerbehandlung
- Einheitliche Fehlerantworten: 400 bei ungültiger Eingabe oder verletzter Regel, 404 bei nicht gefundenen Datensätzen (vorher oft 500), 403 bei fehlendem Recht, 503 bei fehlender Server-Voraussetzung (z. B. TCPDF), 500 nur bei unerwarteten Fehlern.
- Unerwartete Fehler zeigen dem Nutzer nur noch „Interner Fehler“; Datenbank- und Ausnahmetexte stehen im Nextcloud-Log und nicht mehr im Browser. Ein Test verhindert, dass Controller wieder Ausnahmetexte durchreichen.
- Ein fehlgeschlagener CSV- oder PDF-Export liefert eine JSON-Fehlermeldung statt einer Datei `error.txt`.

### 🔧 Betrieb
- **Installationen in einem Unterordner** (z. B. `/nextcloud`) und ohne „schöne URLs“ funktionieren: Die Oberfläche bekommt die Adresse der Nextcloud von der Seite mitgeteilt, statt eine Installation im Hauptverzeichnis der Domain anzunehmen.
- Die alte Rollenseite in den Nextcloud-Administrationseinstellungen ist entfernt (sie sendete kein CSRF-Token und ist durch den Reiter „Rollen“ in der App ersetzt).

### 🧹 Aufgeräumt
- Ein nie verwendeter Platzhalter `RoleService::hasPermission()` (lieferte immer „erlaubt“), Rechtevorlagen für Musik-/Sportvereine mit nicht geprüften Rechten und die zugehörige Route `/roles/club/{clubType}` entfernt.
- Toter Code entfernt: ein nie angebundener PDF- und SEPA-Exporter (pain.001), ungenutzte Validierungsklassen, ein nicht erreichbarer Admin-Controller, nicht eingebundene Vorlagen und Oberflächen-Dateien, die alte `database.xml` und die ungültige Upstream-Signatur `signature.json`.
- Veraltete Upstream-Dokumente (Statusberichte, Release-Notizen, Handbücher, Wiki, PDF-Roadmap) entfernt. Neu geschrieben: README, Installationsanleitung, Planung, Beitragsregeln, Sicherheitshinweise. Die API-Übersicht (`docs/API.md`) wird aus dem Code erzeugt und per Test aktuell gehalten.
- Die nicht lauffähigen Integrationstests aus dem Upstream-Projekt entfernt.
- Frontend-Werkzeuge aktualisiert (Vite 7); `npm audit` und `composer audit` melden keine Schwachstellen mehr. Ungenutzte Abhängigkeiten entfernt.
- `info.xml`: Projektadressen, PHP-Mindestversion (8.1).

---

## [0.12.0-beta] - 2026-09-29

Erster Teil von Datenschutz (Roadmap-Punkt 4): Mitglieder deaktivieren, Änderungsprotokoll.

### ✨ Neu
- **Mitglieder deaktivieren/aktivieren** (`verein.role.manage`, wie eine Rollenänderung). Ein deaktiviertes Mitglied wird aus dem Beitragslauf und dem SEPA-Export ausgenommen (mit Grund gemeldet), bekommt keine neuen Beiträge, hat keine Geburtstags-/Jubiläumstermine im Kalender mehr und erhält keine automatisch abgeleiteten Rechte; explizit zugewiesene Rollen bleiben. Nichts wird gelöscht.
- **Änderungsprotokoll** (`verein_audit_log`): erfasst wer wann was geändert hat, mit Feld-Diff, für Mitglieder/Mitgliedschaften, Beiträge, Beitragskategorien, Vereine (inkl. Bankkonten, Rollen-Mapping) und Rollen/Zuweisungen. Abrufbar per API (`verein.audit.view`).
- **Aufbewahrung:** Einträge zu personenbezogenen Daten (Mitglieder, Mitgliedschaften, Beiträge, Rollenzuweisungen) 10 Jahre, alle übrigen 30 Tage; ein täglicher Hintergrundjob räumt auf.
- Das Änderungsprotokoll ist Teil der Sicherung; ältere Sicherungen ohne diese Tabelle lassen sich weiter zurückspielen (das Protokoll bleibt dann unberührt).

### 🔒 Sicherheit
- Die neuen schreibenden Endpunkte verlangen das CSRF-Token.

### 📋 Noch offen (Rest von Punkt 4)
- Mitglieder löschen/anonymisieren (zurückgestellt: Aufbewahrungspflicht für Buchhaltungsbelege).
- Datenexport einer Person durch einen Admin (bisher nur die Selbstauskunft, `/me/export`).

---

## [0.11.2-beta] - 2026-09-25

### 🐛 Behoben
- Sicherungsliste: fünf Einträge sichtbar, dann Scrollen (die globale Tabellen-Regel der App überschrieb die Zeilenhöhe).

---

## [0.11.1-beta] - 2026-09-25

### 🐛 Behoben
- Die Sicherungsliste zeigte wegen fremder Tabellen-Styles nur etwa 4 statt 5 Einträge vor dem Scrollen.

---

## [0.11.0-beta] - 2026-09-25

### ✨ Neu
- **Wiederherstellung aus einer Sicherung** per `occ verein:backup:restore <Dateiname oder Pfad>` (zeigt vorher Zeilenzahlen jetzt/Sicherung, fragt nach, legt automatisch eine Sicherung des aktuellen Stands an und ersetzt alle Vereinsdaten in einer Transaktion; bei einem Fehler bleibt alles unverändert). `occ verein:backup:list` listet die Sicherungen.
- Die Liste der Sicherungen zeigt 5 Einträge, darüber wird gescrollt.

---

## [0.10.0-beta] - 2026-09-25

### ✨ Neu
- **Automatische Sicherung:** täglich (Nextcloud-Hintergrundjob) werden alle Vereinstabellen als komprimierte JSON-Datei im App-Datenordner gesichert. Sicherungen älter als 30 Tage werden gelöscht (mindestens die neuesten 7 bleiben immer, höchstens 100).
- Im Reiter „Verein“ (nur Nextcloud-Administratoren): Liste der Sicherungen, „Jetzt sichern“ und Download.
- Läuft ohne `mysqldump` oder Shell und damit auf jeder von Nextcloud unterstützten Datenbank.

---

## [0.9.2-beta] - 2026-09-25

### 🔒 Sicherheit
- Schreibende Endpunkte (Mitglieder, Beiträge, Beitragslauf, Rollenzuweisung) verlangen wieder das CSRF-Token. Vorher war es überall abgeschaltet; ein Test verhindert das künftig.
- CSV-Export schützt vor Formelinjektion: Text, der mit `=`, `+`, `-` oder `@` beginnt, wird mit einem Apostroph entschärft.
- Rollen lassen sich nur noch an existierende Nextcloud-Konten vergeben.

---

## [0.9.1-beta] - 2026-09-25

### 🐛 Behoben
- Die Suche im Dropdown „Verknüpftes Nextcloud-Konto“ und die vereinsübergreifende Personensuche fanden nichts: `api.get()` gab die Suchparameter nicht weiter.

---

## [0.9.0-beta] - 2026-09-25

Stand dieses Forks (mehrere Vereine, Rechte, Beiträge). Die älteren Einträge unten stammen vom Upstream-Projekt.

### ✨ Neu
- **Mehrere Vereine:** Verein → Mitgliedschaft ← Person. Eine Person kann in mehreren Vereinen sein, mit eigenem Eintritt, eigener Funktion und eigenem SEPA-Mandat. Je Verein mehrere Bankkonten (IBAN, BIC, Gläubiger-ID).
- **Rechte je Verein:** jeder Endpunkt mit `#[RequirePermission]`, Vereinsauswahl im Kopf der App, Übergreifende Suche nur für Personen, die beide Vereine verwalten.
- **Automatische Rechte:** Vereinsfunktion (Mitglied/Kassierer/Vorstand) wird je Verein einer App-Rolle zugeordnet; gilt nur für aktive Mitglieder mit verknüpftem Nextcloud-Konto.
- **Nextcloud-Konto mit Mitglied verknüpfen** per Dropdown mit Namensvorschlägen.
- **Selbstauskunft „Meine Daten“** (nur lesend) inkl. JSON-Download.
- **SEPA-Lastschrift je Verein und Konto:** Mandatsdaten (Referenz, Datum, Datei im Team-Ordner) je Mitgliedschaft; nicht einziehbare Beiträge werden mit Grund gemeldet; nach dem Export können genau die exportierten Beiträge als bezahlt markiert werden.
- **Beitragskategorien** je Verein (0 € = beitragsfrei) und **Beitragslauf** mit Vorschau, wiederholbar ohne Doppelbuchungen.
- **„Überfällige markieren“** für offene Beiträge mit abgelaufener Fälligkeit.
- **Kalender je Verein**, nur intern mit festgelegten Nextcloud-Gruppen geteilt.
- E-Mail-Adresse von Mitgliedern ist optional.

### 🐛 Behoben
- Rechteprüfung wurde von Nextcloud ignoriert (Rückgabewert der Middleware); sie wirft jetzt eine Ausnahme.
- SEPA-Export übersprang Beiträge ohne IBAN stillschweigend.
- Beitragsfreie Kategorien (0 €) ließen sich nicht speichern.
- Massen-Statusänderungen liefen zeilenweise und brauchten Minuten; jetzt eine SQL-Anweisung.

### 🔧 Technik
- 280 Unit-Tests (PHPUnit 10, `nextcloud/ocp`-Stubs), CI in `.github/workflows/tests.yml`.
- Migrationen `Version0200xx`; `CLAUDE.md` und `docs/ARCHITEKTUR.md` für Mitarbeitende.

---

## Vor dem Fork (Upstream)

Die Versionen 0.1.0-alpha bis 0.2.x stammen aus dem Ursprungsprojekt [Wacken2012/nextcloud-verein](https://github.com/Wacken2012/nextcloud-verein) (Mitgliederverwaltung, Rollen, CSV/PDF-Export, Datenvalidierung). Der Code wurde seitdem für mehrere Vereine, Rechte je Verein, SEPA-Lastschrift und Beitragsläufe grundlegend umgebaut; die Änderungen davor sind im Verlauf des Ursprungsprojekts nachzulesen.
