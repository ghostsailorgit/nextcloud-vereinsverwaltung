# Vereinsverwaltung für Nextcloud

Eine Nextcloud-App, mit der ein oder mehrere Vereine ihre **Mitglieder, Beiträge und SEPA-Lastschriften** verwalten. Sie läuft in der eigenen Nextcloud, die Daten bleiben auf dem eigenen Server.

> **Status: Beta.** Die App wird in einem echten Verein erprobt, ist aber noch nicht für den produktiven Einsatz ohne eigene Prüfung freigegeben. Bitte vor dem ersten echten SEPA-Einzug die erzeugte Datei mit dem Prüfwerkzeug der eigenen Bank kontrollieren und zuerst mit kleinen Beträgen testen. Ein Fehler in der App entbindet nicht von der eigenen Sorgfaltspflicht.

## Funktionen

- **Mehrere Vereine in einer Installation.** Eine Person kann in mehreren Vereinen Mitglied sein, mit eigenem Eintrittsdatum, eigener Funktion und eigenem SEPA-Mandat je Verein. Jeder Verein hat eigene Bankkonten (IBAN, BIC, Gläubiger-ID).
- **Mitglieder:** Stammdaten, Funktion (Mitglied, Kassierer, Vorstand), Ein- und Austritt, Gründungsmitglied, verstorben, Suche und Filter, **Import aus CSV** (auch aus Excel) mit Vorschau und Dubletten-Erkennung. Mitglieder lassen sich **deaktivieren**: Sie werden dann nicht mehr für Beiträge und Lastschriften berücksichtigt und erscheinen nicht mehr im Kalender.
- **Beiträge:** Beitragskategorien je Verein (auch beitragsfrei), **Beitragslauf** mit Vorschau (wiederholbar, ohne doppelte Beiträge, wahlweise anteilig bei Eintritt im laufenden Jahr), einzelne Beiträge, Status offen/bezahlt/überfällig, **Mahnwesen** mit drei Stufen und Mahnschreiben als PDF.
- **SEPA-Lastschrift** (pain.008.001.02) je Verein und Bankkonto, mit Mandatsverwaltung (Referenz, Unterschriftsdatum, unterschriebenes Mandat als Datei in Nextcloud). Beiträge, die sich nicht einziehen lassen (keine IBAN, kein Mandat), werden mit Grund gemeldet statt still übersprungen. Nach dem Einreichen bei der Bank lassen sich genau die exportierten Beiträge als bezahlt markieren.
- **Rechte je Verein.** Wer im Vorstand des einen Vereins ist, sieht den anderen Verein nicht. Rechte lassen sich per Rolle vergeben oder **automatisch aus der Vereinsfunktion** ableiten (das Mitglied ist mit seinem Nextcloud-Konto verknüpft).
- **Meine Daten:** Jedes verknüpfte Mitglied sieht seine eigenen Daten und Beiträge und kann sie als JSON herunterladen (nur lesend).
- **Kalender:** Pro Verein ein Kalender mit Geburtstagen und Vereinsjubiläen, nur intern mit ausgewählten Nextcloud-Gruppen geteilt, nie öffentlich.
- **Export** als CSV und PDF, **Statistik** auf der Startseite.
- **Sicherung und Änderungsprotokoll:** tägliche automatische Sicherung aller Vereinsdaten (30 Tage), Wiederherstellung per `occ`; ein Änderungsprotokoll (Reiter „Protokoll“) erfasst, wer was geändert hat, ohne personenbezogene Inhalte im Klartext.

Nicht enthalten: Buchhaltung, Online-Beitritt, Serienbriefe, Versand von E-Mails. Die Oberfläche ist auf Deutsch.

## Voraussetzungen

- Nextcloud 27 bis 34 (entwickelt und getestet mit **Nextcloud 34**, PHP 8.5 und MySQL 8.4; die Unit-Tests laufen in der CI mit PHP 8.3 und 8.5; ältere Versionen und andere Datenbanken sind ungetestet)
- PHP 8.1 oder neuer
- Für den PDF-Export die Bibliothek TCPDF (kommt mit `composer install --no-dev`)
- Ein funktionierender Nextcloud-Cron, damit Sicherung und Aufräumen laufen

## Installation

Kurzfassung (Einzelheiten in [docs/INSTALLATION.md](docs/INSTALLATION.md)):

```bash
cd /pfad/zu/nextcloud/custom_apps
git clone https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung.git verein
cd verein
composer install --no-dev
npm ci && npm run build
sudo -u www-data php /pfad/zu/nextcloud/occ app:enable verein
```

Der Ordner muss `verein` heißen. Nach dem Einschalten erscheint der Eintrag „Verein“ im Nextcloud-Menü.

## Erste Schritte

1. Als Nextcloud-Administrator im Reiter **Verein** den Verein anlegen, Vereinsdaten und Bankkonto (IBAN, BIC, Gläubiger-ID) eintragen.
2. **Beitragskategorien** anlegen und eine als Standard festlegen.
3. **Mitglieder** anlegen (einen Import aus Dateien gibt es noch nicht). Für den Lastschrifteinzug pro Mitglied IBAN und Mandat (Unterschriftsdatum) erfassen.
4. Im Reiter **Finanzen** einen **Beitragslauf** starten, dann im Reiter **SEPA-Export** die Lastschriftdatei erzeugen.
5. Vorstandsmitglieder mit ihrem Nextcloud-Konto verknüpfen und im Reiter **Verein** unter „Automatische Rechte“ festlegen, welche Rechte welche Vereinsfunktion bekommt.

## Dokumentation

- [Installation und Betrieb](docs/INSTALLATION.md)
- [Architektur und Entscheidungen](docs/ARCHITEKTUR.md)
- [API-Übersicht](docs/API.md)
- [Änderungen](CHANGELOG.md) und [Planung](ROADMAP.md)
- [Mitmachen](CONTRIBUTING.md) und [Sicherheit](SECURITY.md)

## Datenschutz

Die App verarbeitet personenbezogene Daten (Anschrift, Geburtsdatum, Bankverbindung). Verantwortlich ist der Verein bzw. der Betreiber der Nextcloud: Zugriffsrechte, Aufbewahrung, Auftragsverarbeitung und Verzeichnis der Verarbeitungstätigkeiten gehören zu Ihren Pflichten. Die App hilft dabei mit Rechten je Verein, einer Selbstauskunft, einem Datenexport durch den Administrator, einem Änderungsprotokoll ohne Klartext bei personenbezogenen Feldern und Sicherungen. „Aus Verein entfernen“ löscht eine Person samt ihren Beiträgen; eine Person lässt sich außerdem anonymisieren statt löschen (Name, Anschrift, IBAN usw. werden durch Platzhalter ersetzt, Beiträge und SEPA-Historie bleiben zuordenbar) - erst möglich, wenn sie überall ausgetreten oder verstorben ist. Das Änderungsprotokoll behält Einträge zu Personen bis zu 10 Jahre. Die Sicherungen enthalten alle Vereinsdaten und sind wie die Datenbank selbst zu schützen.

## Herkunft und Lizenz

Dieses Projekt ist ein Fork von [Wacken2012/nextcloud-verein](https://github.com/Wacken2012/nextcloud-verein) und wurde für mehrere Vereine, Rechte je Verein, SEPA-Lastschrift und Beitragsläufe umgebaut. Vielen Dank an das Ursprungsprojekt.

Lizenz: [GNU Affero General Public License v3.0](LICENSE). Dateien aus dem Ursprungsprojekt stehen wie dort unter AGPL-3.0-only, neu geschriebene unter AGPL-3.0-or-later; die App als Ganzes ist damit unter AGPL-3.0 nutzbar. Urheber und Lizenz stehen in jeder Datei (SPDX-Kopf, [REUSE](https://reuse.software)), siehe [AUTHORS.md](AUTHORS.md).

---

## English summary

A Nextcloud app for managing one or several clubs: members, membership fees and SEPA direct debit (pain.008.001.02). Multi-club by design (a person can belong to several clubs with separate join dates, roles and mandates), per-club permissions with optional rights derived from a member's club role, annual fee runs with preview, member self-service, a per-club birthday calendar, CSV/PDF export, daily backups with `occ` restore, and a change log. The UI is German. **Beta software** - verify the generated SEPA file with your bank's tooling before the first real collection. Tested with Nextcloud 34, PHP 8.5 and MySQL 8.4 (unit tests in CI on PHP 8.3 and 8.5). Installation: see [docs/INSTALLATION.md](docs/INSTALLATION.md) (`composer install --no-dev`, `npm ci && npm run build`, `occ app:enable verein`). Licensed under AGPL-3.0 (files from upstream AGPL-3.0-only, new files AGPL-3.0-or-later, REUSE-compliant); forked from [Wacken2012/nextcloud-verein](https://github.com/Wacken2012/nextcloud-verein).
