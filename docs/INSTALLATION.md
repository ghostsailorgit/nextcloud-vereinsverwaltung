# Installation und Betrieb

Diese Anleitung geht davon aus, dass eine Nextcloud bereits läuft. Unterstützt werden Nextcloud 33 bis 35 mit PHP 8.2 bis 8.5 und allen von Nextcloud unterstützten Datenbanken (siehe [README](../README.md#voraussetzungen)).

## 1. App installieren

Es gibt drei Wege. Für die ersten beiden braucht es **keine Kommandozeile** und keine Werkzeuge auf dem Server; die App
benötigt nichts, was Nextcloud nicht ohnehin voraussetzt (keine zusätzlichen PHP-Erweiterungen, keine Programme, kein
System-Cron).

### a) Aus dem Nextcloud App Store (sobald veröffentlicht)

Als Administrator in Nextcloud **Apps** öffnen, nach „Verein“ suchen, **Herunterladen und aktivieren**. Updates erscheinen
dann wie bei jeder anderen App unter „Aktualisierungen“.

### b) Fertiges Archiv, ohne Kommandozeile

1. Von der [Release-Seite](https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung/releases) die Datei
   `verein-v….tar.gz` herunterladen (nicht „Source code“ - dem fehlen das gebaute Frontend und die PDF-Bibliothek) und auf
   dem eigenen Rechner entpacken. Es entsteht ein Ordner `verein`.
2. Diesen Ordner per SFTP/FTP oder mit dem Dateimanager des Hosters in den App-Ordner der Nextcloud hochladen, so dass es
   `…/nextcloud/custom_apps/verein/appinfo/info.xml` gibt. Der Ordner muss `verein` heißen (das ist die App-ID). Wo der
   App-Ordner liegt:

   | Installation | Ordner |
   |---|---|
   | Normale Installation / Webhosting | `custom_apps/` im Nextcloud-Ordner; gibt es ihn nicht, `apps/` |
   | Offizielles Docker-Image (`nextcloud`) | `/var/www/html/custom_apps/` im Container (meist ein Volume) |
   | Snap | `/var/snap/nextcloud/current/nextcloud/extra-apps/` |
   | Nextcloud AIO | nur über den App Store (Weg a) |

3. In Nextcloud **Apps → Deaktivierte Apps** öffnen und bei „Verein“ **Aktivieren** klicken. Dabei legt Nextcloud die
   Datenbanktabellen an (`oc_verein_*`).

Die Dateien müssen dem Benutzer gehören, unter dem PHP läuft. Beim Webhosting ist das in der Regel der FTP-Benutzer, dann
ist nichts zu tun; auf einem eigenen Server ggf. `chown -R www-data:www-data custom_apps/verein`.

Genau dieses Archiv wird in der CI in eine frische Nextcloud 33 und 35 entpackt, aktiviert und getestet - ohne Composer,
npm oder System-Cron.

### c) Aus dem Quellcode (für Entwickler)

```bash
cd /pfad/zu/nextcloud/custom_apps
git clone https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung.git verein
cd verein
composer install --no-dev      # PDF-Bibliothek (TCPDF)
npm ci && npm run build        # erzeugt js/dist/
sudo -u www-data php /pfad/zu/nextcloud/occ app:enable verein
```

Das Archiv aus Weg b baut `scripts/build-archive.sh` (braucht git, Composer, npm).

## 2. Nextcloud-Cron einrichten

Die tägliche Sicherung und das Aufräumen des Änderungsprotokolls laufen als Nextcloud-Hintergrundjobs. Am zuverlässigsten ist **Cron** (Verwaltungseinstellungen → Grundeinstellungen → Hintergrundaufgaben). Wo das nicht geht
(Webhosting), funktionieren auch **Webcron** (ein externer Dienst ruft `cron.php` auf; in der CI getestet) und **AJAX** -
dann laufen die Jobs nur, wenn jemand die Oberfläche öffnet.

## 3. Rechte einrichten

Nextcloud-Administratoren dürfen alles. Alle anderen brauchen Rechte, die pro Verein vergeben werden (Reiter **Rollen**), oder sie bekommen sie automatisch aus ihrer Vereinsfunktion (Reiter **Verein** → „Automatische Rechte“). Ein Mitglied muss dafür mit seinem Nextcloud-Konto verknüpft sein (Mitglied bearbeiten → „Verknüpftes Nextcloud-Konto“). Ohne eingerichtete Zuordnung wird nichts automatisch vergeben.

Die App muss nicht auf Gruppen beschränkt werden; wer keine Rolle und keine Mitgliedschaft hat, sieht in der App nichts.

## 4. Aktualisieren

**Vor jedem Update eine Sicherung anlegen** (siehe unten).

- **App Store:** wie jede andere App unter „Apps → Aktualisierungen“.
- **Archiv:** das neue Archiv entpacken, den alten Ordner `verein` im App-Ordner **löschen** und den neuen hochladen (nicht
  darüberkopieren - sonst bleiben alte Dateien liegen). Beim nächsten Aufruf zeigt Nextcloud eine Seite „Aktualisierung
  erforderlich“; nach dem Klick auf „Aktualisieren“ laufen die neuen Datenbank-Migrationen.
- **Quellcode:** `git pull`, `composer install --no-dev`, `npm ci && npm run build`, dann `occ upgrade`.

Das Frontend wird vom Browser stark zwischengespeichert; die Version in `appinfo/info.xml` ändert die Adresse der Dateien,
ein neu geladenes Fenster genügt. PHP-Änderungen werden je nach OPcache-Einstellung erst nach einer Minute oder einem
PHP-Neustart wirksam.

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
| PDF-Export zeigt „TCPDF fehlt“ | Das Archiv `verein-v….tar.gz` verwenden (nicht „Source code“), es enthält die Bibliothek; bei Installation aus dem Quellcode im App-Ordner `composer install --no-dev` ausführen. Alle anderen Funktionen laufen auch ohne. |
| Nach einem Update sieht man noch die alte Oberfläche | Seite hart neu laden (Strg+F5). Beim Archiv: Wurde der alte Ordner vorher gelöscht? Aus dem Quellcode: Ist `npm run build` gelaufen und `js/dist/` aktuell? |
| „Interner Fehler. Einzelheiten stehen im Nextcloud-Log.“ | Unerwartete Fehler zeigt die App bewusst nicht im Detail. Die Ursache steht in `nextcloud.log` (Verwaltungseinstellungen → Protokoll), Einträge beginnen mit „Verein:“. |
| Die App lässt sich nicht aktivieren / eine leere Seite erscheint | Wurde „Source code“ statt `verein-v….tar.gz` hochgeladen? Heißt der Ordner genau `verein` (nicht `verein-v0.18…` oder `verein/verein`)? |
| Die App erscheint nicht im Menü | App aktiviert? `occ app:list \| grep verein`. Hat der Benutzer eine Rolle oder eine verknüpfte Mitgliedschaft? Ohne beides sieht er nichts. |
| Tägliche Sicherung fehlt | Läuft der Nextcloud-Cron? `occ background-job:list --class='OCA\Verein\BackgroundJob\DailyBackupJob'` zeigt den letzten Lauf. |
| Kalendertermine fehlen | Der Kalender „Vereinstermine …“ erscheint automatisch bei Nutzern, die in einer der im Verein hinterlegten Kalendergruppen sind (Reiter „Verein“). Nach dem Update auf 0.18 verschwindet der alte, geteilte Kalender gleichen Namens; bleibt er stehen, stand der Grund im Update-Protokoll, und der Besitzer kann ihn in der Kalender-App löschen. |
