# Sicherheit

## Sicherheitslücken melden

Bitte melde Sicherheitslücken **nicht** in einem öffentlichen Issue. Nutze stattdessen die vertrauliche Meldung von GitHub: im Repository unter **Security → Report a vulnerability** ([Anleitung](https://docs.github.com/de/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)).

Hilfreich sind: betroffene Version, eine kurze Beschreibung, Schritte zum Nachstellen und die mögliche Auswirkung (z. B. „ein Benutzer ohne Rolle kann Mitglieder eines fremden Vereins lesen“). Bitte keine echten personenbezogenen Daten mitschicken.

Das Projekt wird ehrenamtlich gepflegt; eine feste Antwortzeit kann nicht zugesagt werden, Meldungen werden aber ernst genommen und so schnell wie möglich geprüft. Bis ein Fix veröffentlicht ist, bitte nichts öffentlich machen. Auf Wunsch wird der Melder im Changelog genannt.

## Unterstützte Versionen

Das Projekt befindet sich in der Beta. Sicherheitskorrekturen gibt es für den jeweils **aktuellen Stand von `master`** bzw. die letzte veröffentlichte Version.

## Was die App zur Sicherheit tut

- Jeder Endpunkt prüft ein Recht (`#[RequirePermission]`), pro Verein; ein Test erzwingt das für alle Routen.
- Schreibende Anfragen verlangen das CSRF-Token von Nextcloud, ebenfalls per Test abgesichert.
- Rechte, die andere Rechte vergeben (Funktion, Kontoverknüpfung, Deaktivieren), sind selbst an das Recht „Rollen verwalten“ gebunden.
- Datenbankzugriffe laufen über den Query-Builder mit Parametern; SEPA-XML wird maskiert; CSV-Export ist gegen Formelinjektion geschützt.
- Unerwartete Fehler zeigen dem Nutzer nur eine allgemeine Meldung, Einzelheiten stehen im Nextcloud-Log.
- Abhängigkeiten werden mit `composer audit` und `npm audit` geprüft.

## Was der Betreiber selbst tun muss

- Nextcloud und PHP aktuell halten, HTTPS erzwingen, Zugriff auf Datenbank und Datenordner beschränken.
- Die Sicherungen der App enthalten alle Vereinsdaten (IBAN, Anschrift) und sind wie die Datenbank zu schützen.
- Vor dem produktiven Einsatz die eigenen Rollen mit einem echten Nicht-Administrator-Konto testen.
