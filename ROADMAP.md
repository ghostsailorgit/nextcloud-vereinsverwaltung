# Planung

Stand: Version 0.17.0-beta. Die Reihenfolge ist keine Zusage, sondern die aktuelle Absicht. Wünsche und Ideen gern als [Issue](https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung/issues).

## Fertig

| Thema | Inhalt |
|---|---|
| Mehrere Vereine | Vereine, Mitgliedschaften (eine Person in mehreren Vereinen), Bankkonten, Rechte je Verein |
| Mitglieder | Stammdaten, Deaktivieren, Import aus CSV mit Vorschau, IBAN-Prüfung und Dubletten-Erkennung |
| Rechte | Rollen je Verein, automatische Rechte aus der Vereinsfunktion, Selbstauskunft „Meine Daten“ |
| Beiträge | Kategorien, Beitragslauf mit Vorschau (wahlweise anteilig bei Eintritt im laufenden Jahr), Überfällige markieren, Mahnwesen (Zahlungserinnerung, 1. und letzte Mahnung, Schreiben als PDF), Mitglieder deaktivieren |
| SEPA | Lastschriftdatei (pain.008.001.02) je Verein und Konto, Mandatsverwaltung, Meldung nicht einziehbarer Beiträge, „bezahlt“-Markierung nach dem Export |
| Kalender | Geburtstage und Jubiläen je Verein, nur intern geteilt |
| Sicherheit und Betrieb | Rechte- und CSRF-Prüfung pro Endpunkt (per Test erzwungen), tägliche Sicherung mit `occ`-Wiederherstellung, Änderungsprotokoll mit Aufbewahrungsfristen, einheitliche Fehlerbehandlung |
| Datenschutz | Person anonymisieren statt löschen (mit Bestätigung durch Eintippen des Namens), Datenauskunft einer Person, Änderungsprotokoll ohne Klartext bei personenbezogenen Feldern, Reiter „Protokoll“ |
| Qualität | Automatische Tests und CI, API-Übersicht aus dem Code erzeugt |

## Als Nächstes

- Weitere Funktionen (SEPA-Export, Deaktivieren, Selbstauskunft, Sicherung) mit echten Läufen und einem Nicht-Admin-Konto
  nachtesten, wie bei den neuen Funktionen.
- Mahnschreiben auf Wunsch per E-Mail statt nur als PDF (bisher verschickt die App nichts).

## Ideen ohne Termin

- Feinere Vereinsfunktionen (z. B. 1. Vorsitzender, Schriftführer) statt nur Mitglied, Kassierer, Vorstand.
- Kleineres JavaScript-Bundle (aktuell etwa 3,3 MB, davon ein großer Teil Nextcloud-Vue-Komponenten).
- Veröffentlichung im Nextcloud App Store (benötigt signierte Releases und eine Prüfung der unterstützten Nextcloud-Versionen).

## Bekannte Einschränkungen

- Die SEPA-Datei ist als XML wohlgeformt, wurde aber weder gegen das offizielle Schema noch mit Bankensoftware geprüft; vor dem ersten Einzug bitte selbst prüfen.
- Alle Einzüge werden als Folgelastschrift (RCUR) gekennzeichnet; Erst- und Einmallastschriften gibt es nicht.
- Getestet ist die App mit Nextcloud 34, PHP 8.5 und MySQL 8.4 (Unit-Tests in der CI mit PHP 8.3 und 8.5); andere Kombinationen, insbesondere MariaDB, PostgreSQL und SQLite, sind ungetestet.
- Kalendertermine gehören einem technischen Nextcloud-Konto (dem ersten Administrator) und werden mit Gruppen geteilt.
