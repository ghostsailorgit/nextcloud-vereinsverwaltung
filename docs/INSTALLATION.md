# Installation und Betrieb

Diese Anleitung geht davon aus, dass eine Nextcloud bereits läuft. Unterstützt werden Nextcloud 33 bis 35 mit PHP 8.2 bis 8.5 und allen von Nextcloud unterstützten Datenbanken (siehe [README](../README.md#voraussetzungen)).

## 1. App installieren

Die App liegt in `custom_apps/verein` (der Ordnername muss `verein` lauten, das ist die App-ID).

```bash
cd /pfad/zu/nextcloud/custom_apps
git clone https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung.git verein
cd verein
```

Dann die Abhängigkeiten holen und das Frontend bauen:

```bash
composer install --no-dev      # nur nötig für den PDF-Export (TCPDF)
npm ci
npm run build                  # erzeugt js/dist/
```

`composer` und `npm` sind nur zum Bauen nötig, nicht im laufenden Betrieb. Wer die App auf einen Server ohne Build-Werkzeuge bringen will, baut sie auf einem anderen Rechner und kopiert den Ordner (ohne `node_modules/` und `.git/`, aber mit `js/dist/` und `vendor/`).

Zum Schluss Dateibesitzer setzen und die App aktivieren:

```bash
sudo chown -R www-data:www-data /pfad/zu/nextcloud/custom_apps/verein
sudo -u www-data php /pfad/zu/nextcloud/occ app:enable verein
```

Beim Aktivieren legt Nextcloud die Datenbanktabellen an (`oc_verein_*`).

## 2. Nextcloud-Cron einrichten

Die tägliche Sicherung und das Aufräumen des Änderungsprotokolls laufen als Nextcloud-Hintergrundjobs. Nextcloud sollte dafür auf **Cron** stehen (Verwaltungseinstellungen → Grundeinstellungen → Hintergrundaufgaben). Ohne Cron laufen die Jobs nur, wenn Nutzer die Oberfläche öffnen.

## 3. Rechte einrichten

Nextcloud-Administratoren dürfen alles. Alle anderen brauchen Rechte, die pro Verein vergeben werden (Reiter **Rollen**), oder sie bekommen sie automatisch aus ihrer Vereinsfunktion (Reiter **Verein** → „Automatische Rechte“). Ein Mitglied muss dafür mit seinem Nextcloud-Konto verknüpft sein (Mitglied bearbeiten → „Verknüpftes Nextcloud-Konto“). Ohne eingerichtete Zuordnung wird nichts automatisch vergeben.

Die App muss nicht auf Gruppen beschränkt werden; wer keine Rolle und keine Mitgliedschaft hat, sieht in der App nichts.

## 4. Aktualisieren

```bash
cd /pfad/zu/nextcloud/custom_apps/verein
git pull
composer install --no-dev
npm ci && npm run build
sudo -u www-data php /pfad/zu/nextcloud/occ upgrade
```

`occ upgrade` führt neue Datenbank-Migrationen aus. Das Frontend wird vom Browser stark zwischengespeichert; die Version in `appinfo/info.xml` ändert die Adresse der Dateien, ein neu geladenes Fenster genügt. PHP-Änderungen werden je nach OPcache-Einstellung erst nach einer Minute oder einem PHP-Neustart wirksam.

**Vor jedem Update eine Sicherung anlegen** (siehe unten).

## 5. Sicherung und Wiederherstellung

Die App sichert täglich alle Vereinsdaten (Vereine, Bankkonten, Mitglieder, Mitgliedschaften, Beiträge, Rollen, Änderungsprotokoll) als komprimierte JSON-Datei im Datenordner der App (`appdata_<instanz>/verein/backups`). Sicherungen älter als 30 Tage werden gelöscht, die neuesten 7 bleiben immer erhalten. Nextcloud-Administratoren finden die Liste im Reiter **Verein** und können dort sofort sichern und Dateien herunterladen.

Wiederherstellen per Kommandozeile (ersetzt alle aktuellen Vereinsdaten; vorher wird automatisch eine Sicherung des aktuellen Stands angelegt):

```bash
sudo -u www-data php occ verein:backup:list
sudo -u www-data php occ verein:backup:restore verein-backup-20260101-030000.json.gz
# oder mit dem Pfad einer heruntergeladenen Datei
sudo -u www-data php occ verein:backup:restore /pfad/zur/verein-backup-….json.gz
```

Hinweise: Die Sicherung enthält personenbezogene Daten, sie ist wie die Datenbank zu schützen. Der Vereinskalender braucht keine Sicherung, er wird aus den Mitgliederdaten erzeugt. Die Sicherung ersetzt **nicht** die Sicherung der gesamten Nextcloud (Dateien, Datenbank, Konfiguration). Das Zurückspielen ist mit MySQL 8.4 getestet, für MariaDB, PostgreSQL und SQLite nicht.

## 6. SEPA-Lastschriften

- Im Reiter **Verein** je Bankkonto IBAN, BIC und **Gläubiger-Identifikationsnummer** eintragen (bei der Bundesbank beantragt).
- Pro Mitglied IBAN und Mandat erfassen: **Unterschriftsdatum** ist Pflicht für den Einzug, die Mandatsreferenz wird sonst automatisch gebildet. Das unterschriebene Mandat lässt sich als Datei aus Nextcloud verknüpfen.
- Die Datei entspricht pain.008.001.02, alle Einzüge werden als Folgelastschrift (RCUR) gekennzeichnet. **Prüfen Sie die erste Datei vor dem Einreichen mit dem Prüfwerkzeug Ihrer Bank** und ziehen Sie zunächst kleine Beträge ein.

## Fehlerbehebung

| Beobachtung | Ursache und Abhilfe |
|---|---|
| PDF-Export zeigt „TCPDF fehlt“ | Im App-Ordner `composer install --no-dev` ausführen. Alle anderen Funktionen laufen auch ohne. |
| Nach einem Update sieht man noch die alte Oberfläche | Seite hart neu laden (Strg+F5). Prüfen, ob `npm run build` gelaufen ist und `js/dist/` aktuell ist. |
| „Interner Fehler. Einzelheiten stehen im Nextcloud-Log.“ | Unerwartete Fehler zeigt die App bewusst nicht im Detail. Die Ursache steht in `nextcloud.log` (Verwaltungseinstellungen → Protokoll), Einträge beginnen mit „Verein:“. |
| Die App erscheint nicht im Menü | App aktiviert? `occ app:list \| grep verein`. Hat der Benutzer eine Rolle oder eine verknüpfte Mitgliedschaft? Ohne beides sieht er nichts. |
| Tägliche Sicherung fehlt | Läuft der Nextcloud-Cron? `occ background-job:list --class='OCA\Verein\BackgroundJob\DailyBackupJob'` zeigt den letzten Lauf. |
| Kalendertermine fehlen | Der Kalender „Vereinstermine …“ erscheint automatisch bei Nutzern, die in einer der im Verein hinterlegten Kalendergruppen sind (Reiter „Verein“). Nach dem Update auf 0.18 verschwindet der alte, geteilte Kalender gleichen Namens; bleibt er stehen, stand der Grund im Update-Protokoll, und der Besitzer kann ihn in der Kalender-App löschen. |
