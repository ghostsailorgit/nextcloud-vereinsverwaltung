# Planung

Stand: Version 0.21.0-beta. Die Reihenfolge ist keine Zusage, sondern die aktuelle Absicht. Wünsche und Ideen gern als [Issue](https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung/issues).

## Fertig

| Thema | Inhalt |
|---|---|
| Mehrere Vereine | Vereine, Mitgliedschaften (eine Person in mehreren Vereinen), Bankkonten, Rechte je Verein |
| Mitglieder | Stammdaten, Deaktivieren, Import aus CSV mit Vorschau, IBAN-Prüfung und Dubletten-Erkennung |
| Rechte | Rollen je Verein, automatische Rechte aus der Vereinsfunktion, Selbstauskunft „Meine Daten“ |
| Beiträge | Kategorien, Beitragslauf mit Vorschau (wahlweise anteilig bei Eintritt im laufenden Jahr), Überfällige markieren, Mahnwesen (Zahlungserinnerung, 1. und letzte Mahnung, Schreiben per E-Mail über den Nextcloud-Mailserver oder als PDF), Mitglieder deaktivieren |
| SEPA | Lastschriftdatei (pain.008.001.02) je Verein und Konto, in der CI gegen das offizielle Schema geprüft, SEPA-Zeichensatz, Einzug nie vor Fälligkeit, Einzugsdatum nach Bankarbeitstagen oder frei wählbar, freiwillige Vorabankündigung per E-Mail, Mandatsverwaltung, Meldung nicht einziehbarer Beiträge mit Grund, „bezahlt“-Markierung nach dem Export |
| Kalender | Geburtstage und Jubiläen je Verein als App-Kalender über Nextclouds öffentliche Schnittstelle, sichtbar nur für ausgewählte Gruppen |
| Sicherheit und Betrieb | Rechte- und CSRF-Prüfung pro Endpunkt (per Test erzwungen), tägliche Sicherung mit `occ`-Wiederherstellung, Änderungsprotokoll mit Aufbewahrungsfristen, einheitliche Fehlerbehandlung |
| Datenschutz | Person anonymisieren statt löschen (mit Bestätigung durch Eintippen des Namens), Datenauskunft einer Person, Änderungsprotokoll ohne Klartext bei personenbezogenen Feldern, Reiter „Protokoll“ |
| Qualität | Automatische Tests und CI, Integrationstests gegen Nextcloud 33-35 mit SQLite, MySQL, MariaDB und PostgreSQL, API-Übersicht aus dem Code erzeugt |
| Übersetzungen | Englisch nach festem Glossar (per Test geprüft), Deutsch mitgeliefert (durchgehend „du“, auch in den Mahnschreiben); Oberfläche, Meldungen und Dokumente übersetzbar; Datum und Beträge nach Spracheinstellung; PDFs mit Unicode-Schrift; Briefe in der Sprache der Instanz, sonst in der des Erstellers |
| Installation | Release-Archiv ohne Kommandozeile installierbar (entpacken, hochladen, aktivieren), keine zusätzlichen PHP-Erweiterungen, Programme oder System-Cron nötig; in der CI aus dem Archiv in eine frische Nextcloud installiert und getestet |
| Oberfläche | Jede Seite in Englisch und Deutsch von Handy- bis Monitorbreite durchgeklickt; Listen einzeilig mit Aktionsmenü, Formulare auf Knopfdruck, Bestätigungen für Löschen in Rot |
| Lizenz | SPDX-Kopf in jeder Datei, REUSE-konform (per CI geprüft), Urheber des Ursprungsprojekts in `AUTHORS.md` |

## Als Nächstes

- **Veröffentlichung im App Store:** App signieren und Archiv aus `release.yml` hochladen (englische `info.xml` mit
  Beschreibung, Doku-Links und Bildschirmfotos ist fertig).

## Ideen ohne Termin

- Feinere Vereinsfunktionen (z. B. 1. Vorsitzender, Schriftführer) statt nur Mitglied, Kassierer, Vorstand.
- Noch kleineres JavaScript beim Start (derzeit 1,26 MB, 306 KB komprimiert; größter Teil sind Nextcloud-Vue-Komponenten).

## Bekannte Einschränkungen

- Die SEPA-Datei wird gegen das offizielle ISO-20022-Schema geprüft, aber noch nicht mit der Software einer Bank; vor dem ersten Einzug bitte mit dem Prüfwerkzeug der eigenen Bank kontrollieren.
- Die Vorabankündigung (Pre-Notification) ist freiwillig: per E-Mail aus dem Reiter „SEPA-Export“ oder auf eigenem Weg (Beitragsrechnung, Satzung, Papier). Wer keine E-Mail-Adresse hat, muss anders informiert werden; die App nennt diese Mitglieder.
- Alle Einzüge werden als Folgelastschrift (RCUR) gekennzeichnet; Erst- und Einmallastschriften gibt es nicht.
- Unterstützt werden Nextcloud 33 bis 35 und PHP 8.2 bis 8.5, in der CI mit echten Nextcloud-Installationen 33, 34 und 35 auf SQLite, MySQL 8.4, MariaDB 11.4 und PostgreSQL 17 mit PHP 8.2 bis 8.5 getestet (Installation, Migrationen, Kernfunktionen, HTTP-Rechteprüfung, Wiederherstellung einer Sicherung). Im echten Betrieb erprobt ist bisher nur Nextcloud 34 mit MySQL.
- Mitgeliefert sind Englisch und Deutsch; weitere Sprachen kommen, sobald die App im Nextcloud-Übersetzungsdienst (Transifex) ist.
- Der CSV-Import erkennt Datumsangaben nur als TT.MM.JJJJ oder JJJJ-MM-TT (nicht im US-Format MM/DD/YYYY).
