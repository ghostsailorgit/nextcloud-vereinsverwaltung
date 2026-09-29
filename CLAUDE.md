# Vereinsverwaltung (Nextcloud app `verein`)

Nextcloud app for managing one or several clubs (Vereine): members, memberships, fees, SEPA direct
debit export, roles/permissions, calendar reminders and member self-service. Fork of
`Wacken2012/nextcloud-verein` (remote `upstream`; our history is grafted onto upstream `64a9b8b`).
**The repository is public**: everything committed is world-readable, forever.
UI language is German, code and comments are English. Target: Nextcloud 33-35, PHP 8.2-8.5 (test instance: NC 34, PHP 8.5.10, MySQL 8.4), Vue 3.
**CI** (`.github/workflows/tests.yml`): unit tests on PHP 8.2 and 8.5 against the **Nextcloud 33** OCP stubs (`composer.json` pins
`nextcloud/ocp: dev-stable33` - an API newer than the oldest supported version fails there), an `integration` job that installs real
Nextcloud 33/34/35 on SQLite/MySQL/MariaDB/PostgreSQL and runs `tests/Integration/smoke.php` + HTTP checks + an `occ` restore, and
`reuse lint`. Raise `info.xml` min/max only together with that matrix. Every new code file needs an SPDX header (see CONTRIBUTING.md).

Read `docs/ARCHITEKTUR.md` for the data model and the decisions behind it. This file is what you
must know to change code safely.

## Public repo: must stay free of identifying data
- No real domain, host names, IPs, container names, user names, employer names or member data in
  code, tests, docs or commit messages. Use `cloud.example.org`, `Mustermann`, `DE89 3704 0044 0532 0130 00`.
- Commits use the noreply identity configured for this repo; do not override it with a work e-mail.
- Real member data lives only in the running Nextcloud database, never in the repo.
- History was rewritten to remove such data. Do not reintroduce it; check `git diff` before pushing and
  scan for real names, hosts, e-mail addresses and IBANs (see the scan in "Working conventions").

## Commands
```
composer update                       # dev deps: phpunit + Nextcloud OCP stubs (composer.lock is not committed)
vendor/bin/phpunit                    # must stay green; CI runs the same (.github/workflows/tests.yml)
npm ci && npm run build               # bundles js/main.js -> js/dist/ (dist is not committed)
php scripts/ApiDocs.php               # regenerate docs/API.md after route changes (a test checks it)
```
Runtime dependency: TCPDF (`composer install --no-dev`) is only needed for the PDF export; without it the export answers 503.

## Architecture in one screen
- **Club (`verein_clubs`)** is the top level: name (unique), team-folder path, calendar groups, bank
  accounts (`verein_club_accounts`: IBAN, BIC, creditor ID), and `role_mapping` (see automatic rights).
- **Person (`verein_members`)**: name, address, birth date, own IBAN/BIC, `user_id` (linked Nextcloud
  account, unique). One row per human, shared by all clubs.
- **Membership (`verein_memberships`)**: person x club - role (`member|treasurer|admin`=Vorstand),
  join/leave date, founding flag, `deactivated` (see rule 4), SEPA mandate (reference, signature date, signed file path).
  `Member` (entity) exposes the membership fields of the club it was loaded for via `setMembership()`.
- **Fee categories (`verein_fee_rates`)** per club (name, yearly amount, one default; 0 = fee-free);
  a membership points to one via `fee_rate_id`, none = the club's default.
- **Fees (`verein_fees`)** carry `club_id`; `period` (e.g. `2026`) marks annual membership fees. **Roles** (`verein_roles`) are global definitions, **assignments**
  (`verein_user_roles`) are per club.
- Everything user-facing is scoped to a club via a `clubId` request parameter. The frontend sends it
  automatically (`js/api.js` interceptor + `js/store/club.js`); direct `axios` calls must add it.

## Rules that are easy to get wrong
1. **Every route needs `#[RequirePermission('verein.x')]`** (club-scoped by default; `clubScoped: false`
   for global things). `tests/Unit/RoutePermissionsTest` fails for any unprotected route unless it is on its
   short, justified exception list. New endpoints that return only "own" data (`/me`) are the exception.
2. **Nextcloud ignores what `Middleware::beforeController()` returns.** Blocking must `throw`; the
   middleware converts it in `afterException()`. (An earlier version returned a response and enforced nothing.)
3. **Privilege escalation:** a member's `role`, `user_id` and `deactivated` flag drive automatic rights, so only
   holders of `verein.role.manage` may change them (`MemberController::canManageRoles()`, and the
   `#[RequirePermission('verein.role.manage')]` on `MemberController::deactivate()`/`activate()`); role *definitions*
   are Nextcloud-admin only; creating/deleting clubs is Nextcloud-admin only. Keep it that way.
4. **Automatic rights** (`RoleService::derivedRoles()`): explicit assignments + roles mapped from the linked
   person's *active* memberships (no leave date, not deceased, not deactivated). Deactivating a membership
   (`MemberService::deactivate()`, per club) deletes nothing; it stops payments (fee run, SEPA export and manual
   fees skip/refuse the member), hides the member from the club calendar and suspends this derivation
   until `activate()`. No mapping configured = nothing derived.
5. **SEPA export only includes fees whose member has an IBAN AND a mandate signature date**; the rest are
   reported with the reason, never dropped silently. Mandate reference falls back to `M<clubId>-<memberId>`.
   Sequence type is always RCUR (allowed since 2016). Data the bank would reject (amount <= 0, invalid IBAN/BIC, bad
   mandate date/reference) is skipped with a reason too; text goes through `SepaService::text()` (SEPA character set).
   Fees are never collected before their due date (one `PmtInf` per collection date, fees due > 14 days after the
   earliest date are skipped as "not due yet"). `SepaServiceTest` validates the file against the official XSD (downloaded in CI, not committed) - keep it passing.
6. **Self-service is read-only** on purpose (a member changing their own IBAN would be a SEPA fraud risk).
7. **Entity dirty tracking:** `Entity::setX()` does nothing if the value equals the current one, so a NOT NULL
   column that keeps its PHP default is omitted from INSERT - give such columns a DB default (see the
   `email` migration) or make the property default `null` (see `FeeRate::$amount`: a 0.00 fee-free category
   failed to insert until then; same for `AuditLogEntry::$entityId`, bulk actions log id 0; `tests/Unit/EntityInsertTest` pins both). Boolean fields need `addType('x', 'bool')` or an UPDATE that flips them fails with
   "Incorrect integer value".
8. **Annual fee run** (`FeeRunService`): one fee per active member by category, idempotent per member+year
   (cancelled fees don't count), preview first, all-or-nothing in a transaction. Optional `prorata` per run: a member who
   joined during the fee year pays months from the join month to December / 12 (missing/unreadable join date = full year). Mass status changes
   (`markPaidInClub`, `flagOverdueInClub`) are single SQL statements - row-by-row updates took minutes on the slow host.
   After a SEPA export the UI offers to mark exactly the exported fees (ids in `X-Sepa-Fee-Ids`) as paid.
9. `IQueryBuilder::execute()` no longer exists: use `executeStatement()` / `executeQuery()`.
10. Schema changes are `lib/Migration/Version*` classes (the legacy `appinfo/database.xml` is NOT processed).
   Bump `<version>` in `appinfo/info.xml` for every release so Nextcloud runs `occ upgrade` and the
   browser gets the new (immutable-cached) JS.
11. **Calendar = app calendar, nothing stored:** `lib/Calendar/ClubCalendarProvider` (public `OCP\Calendar\ICalendarProvider`,
    registered in `Application::register()`) gives users in a club's calendar groups a read-only calendar computed live from
    the member data (`ClubCalendar`; `ClubEvent` answers `$event['UID']`, which Nextcloud's AppCalendar wrapper relies on).
    Never go back to storing events or sharing a calendar, and never expose it publicly (birth dates are personal data).
    The only remaining use of DAV internals is the one-time repair step `Migration/RemoveLegacyCalendars` (deletes the
    pre-0.18 stored calendars; looked up by class name, failures only logged).
12. **Audit log** (`AuditLogService`, injected as an optional collaborator - a
    service without one just logs nothing, so existing tests that construct a service directly still compile):
    call `record()` after a create/delete, or `diff(before, after)` then `record()` with the result after an
    update, so a manual DI factory in `Application.php` must be updated too when you add the param (see
    `MemberService`, `FeeService`, `RoleService` there) - the ones without a manual factory (`ClubService`,
    `FeeRateService`, `FeeRunService`) are auto-wired and need no such change. Read back via
    `GET /audit-log` (`verein.audit.view`, club-scoped, 100 per page via `beforeId`/`hasMore`, UI tab `AuditLog.vue`); entries with `clubId = null` (role definitions) are
    not exposed through it. Retention (`AuditLogCleanupJob`, daily): entity types in
    `AuditLogService::LONG_RETENTION_TYPES` (member, membership, fee, user_role) 10 years, all others 30 days.
    A new entity type is short-lived unless you add it there - decide deliberately.

13. **CSRF:** never put `@NoCSRFRequired` on a POST/PUT/DELETE action (it once was everywhere; a sibling site on the same
    registrable domain could then forge writes). Only GET reads and file downloads may have it - `RoutePermissionsTest` enforces it.
    The frontend goes through `js/api.js` (`@nextcloud/axios` adds the token); do not use raw `fetch` for writes.

14. **Portable by design:** the app must drop into any Nextcloud instance without host-specific setup. Use only public `OCP`
    APIs, no shell tools (`mysqldump`, cron on the host), no hard-coded hosts/paths/groups, work on every database Nextcloud
    supports, ship background jobs via `appinfo/info.xml`. New tables go into `BackupService::TABLES` (a test checks it).
    Backups: `BackupService` (gzip JSON in the app data folder), `DailyBackupJob`, retention 30 days but the newest 7 always kept.

15. **Anonymize, don't delete:** `MemberService::anonymize()` replaces a person's PII with placeholders but keeps the row -
    fees and SEPA history stay attributable, and a hard delete would break bookkeeping retention. Only allowed once the
    person has left (or is deceased in) every club - an active member's data is still needed. It also calls
    `AuditLogService::scrubEntity()` for the person and every membership, redacting *already-stored* log entries, not just
    future ones. `MemberController::anonymize()`/`export()` must call `MemberService::find($clubId, $id)` first (404 if the
    person is not a member of the calling club) before doing anything else - `MemberService::anonymize()` and
    `SelfServiceService::forMemberId()` look the person up globally and do not check that themselves, so skipping it lets
    anyone with the right permission in *any* club reach a person who only belongs to a different one.
16. **`AuditLogService::SAFE_FIELDS` is an allow-list, not a deny-list:** everything not on it gets redacted for a covered
    entity type, so a new field on `Member` needs nothing done to stay safe by default - only add to the list if it should
    show up in plain text (a deny-list missed derived/joined fields like `fullName`, `mandateReference`/`mandateFile`, `age`
    once before; don't repeat that with a new sensitive-fields list elsewhere).

17. **Dunning** (`DunningService`): one letter per person over all their due fees, level = highest level so far + 1, capped at 3;
    deactivated/anonymized people and anyone dunned within `intervalDays` are skipped with a reason. Preview first, the run is one
    statement per level in a transaction. Letters are generated on demand from the fees' current level; the app sends nothing.
18. **Member import** (`MemberImportService`): never maps a column to `userId` (it drives rights), a role other than member only
    with `verein.role.manage` (controller passes `mayAssignRoles`), duplicates only checked inside the importing club (don't leak
    other clubs), unexpected errors per row are reported as "internal error" (translated), never with the exception text.

19. **Confirmations** go through `js/confirm.js` (`confirmAction(title, text, { labelConfirm, severity })`, Nextcloud's own
    dialog, built with the dialog builder: red button for `severity: 'error'`, closing counts as "no") - never the browser's
    `confirm()`; name the action on the button, `severity: 'error'` for destructive ones. UI conventions (row actions menu,
    `.table-scroll`, null form fields, class names Nextcloud already uses) are in `docs/ARCHITEKTUR.md` under "Technik".
20. **"Today" is local:** Nextcloud runs PHP in UTC, so `date('Y-m-d')` is yesterday shortly after midnight in Europe. Anything a
    person reads or that depends on the calendar day (due/overdue, letters, SEPA dates, export names) uses `Service\Clock`
    (ITimeFactory + the user's/instance's time zone, optional collaborator, `Clock::todayOf()`); DB timestamps stay UTC.

21. **Translations:** all text is English source - `t('verein', '...')` / `n('verein', ...)` (`@nextcloud/l10n`) in the frontend,
    `$this->l->t()` in PHP - plain string literals with placeholders (`{name}` in JS; `%s`/`%1$s` in PHP), no template
    literals or concatenation inside `t()`; inside an HTML attribute no `"` (use “…”). **English follows `docs/GLOSSARY.md`**
    (club, position, fee category, payment reminders, audit log, account, Email, US spelling, … not ...) - `L10nTest` reads
    its "Not" column and fails on those words. Dates and amounts are never formatted into a text: `js/format.js`
    (formatMoney/formatDate, the account's locale) in the browser, `L10n\Formats` in PHP (documents in the DocumentL10n
    language); stored values stay internal ids (salutation `Herr`…, role `admin`…), the UI/CSV show labels, the import
    accepts both. PDFs use `PdfExporter::FONT` (DejaVu Sans; `scripts/build-archive.sh` keeps exactly its files). Add the German text to `l10n/de.json`
    (the only hand-edited file) and run `php scripts/l10n.php build`; `L10nTest` fails on a missing translation or outdated
    generated files. PHP classes take `?IL10N $l10n = null` as their last constructor argument and fall back to
    `L10n\SourceL10n` (English); a manual DI factory in `Application.php` must pass `IL10N::class`, and unit tests pass
    `l10n: SourceL10n::fromAppLanguage('de')` so they keep asserting the German texts. Messages go out in the requesting
    user's language; `occ` output stays English. Documents (PDF exports, dunning letters, the SEPA remittance text, a stored
    default fee description) use `L10n\DocumentL10n`: the instance language if `default_language`/`force_language` is set,
    otherwise the creating user's - never Nextcloud's bare English fallback. `l10n/` must be deployed and is part of the
    release archive.

## Working conventions
- **With every feature/fix/release update `CHANGELOG.md` (new entry at the top, matching the `info.xml` version) and
  `ROADMAP.md`** (move items between done/next), and `docs/ARCHITEKTUR.md` when the data model or rules change, in the same
  commit as the change. Routes changed? Run `php scripts/ApiDocs.php`.
- **Before every push** search the diff and the commit messages for real names, e-mail addresses, IPs, host names and
  IBANs; the repository is public.
- Ask before anything hard to reverse or outward-facing (data changes on a live instance, pushing, posting).
- **Errors:** controllers catch `\Throwable` and return `$this->errorResponse($e)` (trait `RespondsWithErrors`); services throw
  `ValidationException` (400, user-safe text), `NotFoundException` (404) or `DependencyMissingException` (503). Never put
  `$e->getMessage()` of an unexpected exception into a response - it can contain SQL. `RespondsWithErrorsTest` enforces it.
- Personal data: when a request could mean "public", "any user" or "specific group", ask which.
- Test security-relevant changes with a **real non-admin Nextcloud account** (temporary, deleted afterwards);
  an admin session hides permission bugs.
- Deploying to a Nextcloud container: stage into a **fresh empty directory** every time (a reused staging
  directory once overwrote hotfixes with stale files), copy `lib/`, `appinfo/`, `js/dist/` (with `chunks/`), `chown` to
  `www-data`, run `occ upgrade`, then compare `sha1sum` of the files with the repo. PHP changes take ~60 s
  to show up (opcache revalidation).
- The Bash tool can choke on heredocs with quotes or backticks; write helper scripts to files instead.
- Vue files may have CRLF line endings on Windows checkouts (`autocrlf`); normalise before multi-line patches.

## Planning
The plan lives in `ROADMAP.md` (done / next / ideas / known limitations). Data protection (anonymize/delete members,
admin data export, masking sensitive values in the audit log) is done (see rule 15) - built by the colleague it was
assigned to (`feature/anonymisieren`); its UI (log tab, anonymize with typed-name confirmation, data export button) was
added in 0.16.1 on the user's decision without him.
Real-world data entry (bank account, creditor ID, real IBANs and mandates) happens in the running instance, not in the repo.
