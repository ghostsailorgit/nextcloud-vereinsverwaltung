## Was ändert sich und warum?

## Checkliste

- [ ] `vendor/bin/phpunit` ist grün, neues Verhalten hat Tests
- [ ] Neue Endpunkte haben `#[RequirePermission]`; schreibende Endpunkte haben **kein** `@NoCSRFRequired`
- [ ] Fehler laufen über `$this->errorResponse($e)`, keine Ausnahmetexte in Antworten
- [ ] Neue Tabellen stehen in einer Migration und in `BackupService::TABLES`
- [ ] `CHANGELOG.md` und `ROADMAP.md` angepasst (Version in `appinfo/info.xml` passend), `docs/API.md` bei Routenänderungen neu erzeugt
- [ ] Keine echten Namen, Adressen, IBANs, Domains oder Zugangsdaten in Code, Tests, Doku oder Commit-Texten
