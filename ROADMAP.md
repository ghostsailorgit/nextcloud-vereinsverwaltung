# Planung

Stand: Version 0.18.10-beta. Die Reihenfolge ist keine Zusage, sondern die aktuelle Absicht. Wünsche und Ideen gern als [Issue](https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung/issues).

## Fertig

| Thema | Inhalt |
|---|---|
| Mehrere Vereine | Vereine, Mitgliedschaften (eine Person in mehreren Vereinen), Bankkonten, Rechte je Verein |
| Mitglieder | Stammdaten, Deaktivieren, Import aus CSV mit Vorschau, IBAN-Prüfung und Dubletten-Erkennung |
| Rechte | Rollen je Verein, automatische Rechte aus der Vereinsfunktion, Selbstauskunft „Meine Daten“ |
| Beiträge | Kategorien, Beitragslauf mit Vorschau (wahlweise anteilig bei Eintritt im laufenden Jahr), Überfällige markieren, Mahnwesen (Zahlungserinnerung, 1. und letzte Mahnung, Schreiben als PDF), Mitglieder deaktivieren |
| SEPA | Lastschriftdatei (pain.008.001.02) je Verein und Konto, Mandatsverwaltung, Meldung nicht einziehbarer Beiträge, „bezahlt“-Markierung nach dem Export |
| Kalender | Geburtstage und Jubiläen je Verein als App-Kalender über Nextclouds öffentliche Schnittstelle, sichtbar nur für ausgewählte Gruppen |
| Sicherheit und Betrieb | Rechte- und CSRF-Prüfung pro Endpunkt (per Test erzwungen), tägliche Sicherung mit `occ`-Wiederherstellung, Änderungsprotokoll mit Aufbewahrungsfristen, einheitliche Fehlerbehandlung |
| Datenschutz | Person anonymisieren statt löschen (mit Bestätigung durch Eintippen des Namens), Datenauskunft einer Person, Änderungsprotokoll ohne Klartext bei personenbezogenen Feldern, Reiter „Protokoll“ |
| Qualität | Automatische Tests und CI, Integrationstests gegen Nextcloud 33-35 mit SQLite, MySQL, MariaDB und PostgreSQL, API-Übersicht aus dem Code erzeugt |
| Übersetzungen | Oberfläche, Meldungen und Dokumente übersetzbar (Nextcloud-Standard, Deutsch mitgeliefert); Briefe in der Sprache der Instanz, sonst in der des Erstellers |
| Lizenz | SPDX-Kopf in jeder Datei, REUSE-konform (per CI geprüft), Urheber des Ursprungsprojekts in `AUTHORS.md` |

## Als Nächstes

- **Veröffentlichung im App Store:** App signieren, englische Beschreibung und Bildschirmfotos, Eintrag im App Store.
- Mahnschreiben auf Wunsch per E-Mail statt nur als PDF (bisher verschickt die App nichts).

## Ideen ohne Termin

- Feinere Vereinsfunktionen (z. B. 1. Vorsitzender, Schriftführer) statt nur Mitglied, Kassierer, Vorstand.
- Kleineres JavaScript-Bundle (aktuell etwa 3,3 MB, davon ein großer Teil Nextcloud-Vue-Komponenten).
- Veröffentlichung im Nextcloud App Store (benötigt signierte Releases und eine Prüfung der unterstützten Nextcloud-Versionen).

## Bekannte Einschränkungen

- Die SEPA-Datei ist als XML wohlgeformt, wurde aber weder gegen das offizielle Schema noch mit Bankensoftware geprüft; vor dem ersten Einzug bitte selbst prüfen.
- Alle Einzüge werden als Folgelastschrift (RCUR) gekennzeichnet; Erst- und Einmallastschriften gibt es nicht.
- Unterstützt werden Nextcloud 33 bis 35 und PHP 8.2 bis 8.5, in der CI mit echten Nextcloud-Installationen 33, 34 und 35 auf SQLite, MySQL 8.4, MariaDB 11.4 und PostgreSQL 17 mit PHP 8.2 bis 8.5 getestet (Installation, Migrationen, Kernfunktionen, HTTP-Rechteprüfung, Wiederherstellung einer Sicherung). Im echten Betrieb erprobt ist bisher nur Nextcloud 34 mit MySQL.
- Zahlen und Datumsangaben in Mahnschreiben und PDF-Exporten stehen immer im deutschen Format (1.234,56 €, 31.12.2026), auch wenn die Texte übersetzt sind. Beträge und Datumsangaben auf dem Bildschirm sind teils ebenfalls fest deutsch formatiert.
- Mitgeliefert ist nur die deutsche Übersetzung; weitere Sprachen kommen, sobald die App im Nextcloud-Übersetzungsdienst (Transifex) ist.
