# Änderungen

Alle wichtigen Änderungen dieser App. Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/), die Versionen folgen [Semantic Versioning](https://semver.org/lang/de/). Solange die App im Beta-Stadium ist, können sich Dinge zwischen Versionen noch ändern.

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
