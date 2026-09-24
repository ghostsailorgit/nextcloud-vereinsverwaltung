# API-Übersicht

*Diese Datei wird aus `appinfo/routes.php` und den `#[RequirePermission]`-Attributen erzeugt (`php scripts/ApiDocs.php`); ein Test stellt sicher, dass sie aktuell ist. Nicht von Hand ändern.*

Alle Adressen liegen unter `/index.php/apps/verein`. Anfragen brauchen eine angemeldete Nextcloud-Sitzung (oder Basic-Auth mit einem App-Passwort) und für schreibende Anfragen das CSRF-Token (`requesttoken`-Header). Vereinsbezogene Endpunkte erwarten den Parameter `clubId`; das Recht wird für genau diesen Verein geprüft. Fehler kommen als JSON `{"status":"error","message":"…"}` mit dem passenden HTTP-Status (400 ungültige Eingabe, 403 fehlendes Recht, 404 nicht gefunden, 503 fehlende Server-Voraussetzung, 500 unerwarteter Fehler).

## Oberfläche

| Methode | Pfad | Erforderliches Recht |
|---|---|---|
| GET | `/` | angemeldet (nur eigene Daten) |

## Vereine, Bankkonten, Beitragskategorien

| Methode | Pfad | Erforderliches Recht |
|---|---|---|
| GET | `/clubs` | angemeldet (nur eigene Daten) |
| POST | `/clubs` | Nextcloud-Administrator |
| PUT | `/clubs/{clubId}` | `verein.club.manage` |
| DELETE | `/clubs/{clubId}` | Nextcloud-Administrator |
| PUT | `/clubs/{clubId}/role-mapping` | `verein.role.manage` |
| POST | `/clubs/{clubId}/fee-rates` | `verein.club.manage` |
| PUT | `/clubs/{clubId}/fee-rates/{rateId}` | `verein.club.manage` |
| DELETE | `/clubs/{clubId}/fee-rates/{rateId}` | `verein.club.manage` |
| POST | `/clubs/{clubId}/accounts` | `verein.club.manage` |
| PUT | `/clubs/{clubId}/accounts/{accountId}` | `verein.club.manage` |
| DELETE | `/clubs/{clubId}/accounts/{accountId}` | `verein.club.manage` |

## Sicherungen

| Methode | Pfad | Erforderliches Recht |
|---|---|---|
| GET | `/backups` | Nextcloud-Administrator |
| POST | `/backups` | Nextcloud-Administrator |
| GET | `/backups/{name}` | Nextcloud-Administrator |

## Meine Daten (Selbstauskunft)

| Methode | Pfad | Erforderliches Recht |
|---|---|---|
| GET | `/me` | angemeldet (nur eigene Daten) |
| GET | `/me/export` | angemeldet (nur eigene Daten) |

## Mitglieder

| Methode | Pfad | Erforderliches Recht |
|---|---|---|
| GET | `/members` | `verein.member.view` |
| GET | `/members/lookup` | `verein.member.manage` |
| GET | `/members/users` | `verein.member.manage` |
| POST | `/memberships` | `verein.member.manage` |
| POST | `/members` | `verein.member.manage` |
| GET | `/members/{id}` | `verein.member.view` |
| PUT | `/members/{id}` | `verein.member.manage` |
| DELETE | `/members/{id}` | `verein.member.manage` |
| POST | `/members/{id}/deactivate` | `verein.role.manage` |
| POST | `/members/{id}/activate` | `verein.role.manage` |

## Beiträge (Finanzen)

| Methode | Pfad | Erforderliches Recht |
|---|---|---|
| GET | `/finance` | `verein.finance.read` |
| POST | `/finance/mark-paid` | `verein.finance.write` |
| POST | `/finance/flag-overdue` | `verein.finance.write` |
| POST | `/finance` | `verein.finance.write` |
| PUT | `/finance/{id}` | `verein.finance.write` |
| DELETE | `/finance/{id}` | `verein.finance.delete` |

## Beitragslauf

| Methode | Pfad | Erforderliches Recht |
|---|---|---|
| POST | `/fee-run/preview` | `verein.finance.write` |
| POST | `/fee-run` | `verein.finance.write` |

## SEPA-Lastschrift

| Methode | Pfad | Erforderliches Recht |
|---|---|---|
| POST | `/sepa/export` | `verein.sepa.export` |
| POST | `/sepa/preview` | `verein.sepa.export` |

## Export (CSV/PDF)

| Methode | Pfad | Erforderliches Recht |
|---|---|---|
| GET | `/export/members/csv` | `verein.member.view` |
| GET | `/export/members/pdf` | `verein.member.view` |
| GET | `/export/fees/csv` | `verein.finance.read` |
| GET | `/export/fees/pdf` | `verein.finance.read` |

## Statistik

| Methode | Pfad | Erforderliches Recht |
|---|---|---|
| GET | `/statistics/members` | `verein.member.view` |
| GET | `/statistics/fees` | `verein.finance.read` |

## Änderungsprotokoll

| Methode | Pfad | Erforderliches Recht |
|---|---|---|
| GET | `/audit-log` | `verein.audit.view` |

## Rollen und Rechte

| Methode | Pfad | Erforderliches Recht |
|---|---|---|
| GET | `/roles` | `verein.role.manage` (übergreifend) |
| POST | `/roles` | Nextcloud-Administrator |
| GET | `/roles/search-users` | `verein.role.manage` (übergreifend) |
| GET | `/roles/assignments` | `verein.role.manage` |
| POST | `/roles/users` | `verein.role.manage` |
| DELETE | `/roles/users` | `verein.role.manage` |
| GET | `/roles/club/{clubType}` | `verein.role.manage` (übergreifend) |
| GET | `/roles/users/{userId}` | `verein.role.manage` |
| GET | `/roles/{id}` | `verein.role.manage` (übergreifend) |
| PUT | `/roles/{id}` | Nextcloud-Administrator |
| DELETE | `/roles/{id}` | Nextcloud-Administrator |

## Berechtigungsliste

| Methode | Pfad | Erforderliches Recht |
|---|---|---|
| GET | `/permissions` | `verein.role.manage` (übergreifend) |

Rechte: `verein.member.view/manage`, `verein.finance.read/write/delete/export`, `verein.sepa.export`, `verein.role.manage`, `verein.club.manage`, `verein.audit.view`. Nextcloud-Administratoren haben alle Rechte in allen Vereinen. Details zur Rechtevergabe: [ARCHITEKTUR.md](ARCHITEKTUR.md#rechte).
