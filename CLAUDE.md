# Vereinsverwaltung (Nextcloud app `verein`)

Nextcloud app for managing one or several clubs (Vereine): members, memberships, fees, SEPA direct
debit export, roles/permissions, calendar reminders and member self-service. Fork of
`Wacken2012/nextcloud-verein` (remote `upstream`; our history is grafted onto upstream `64a9b8b`).
**The repository is public**: everything committed is world-readable, forever.
UI language is German, code and comments are English. Target: Nextcloud 34, PHP 8.1+ (test instance: PHP 8.5.10 and MySQL 8.4; CI: PHP 8.3 and 8.5), Vue 3.

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
   fees skip/refuse the member), removes the birthday/anniversary calendar events and suspends this derivation
   until `activate()`. No mapping configured = nothing derived.
5. **SEPA export only includes fees whose member has an IBAN AND a mandate signature date**; the rest are
   reported with the reason, never dropped silently. Mandate reference falls back to `M<clubId>-<memberId>`.
   Sequence type is always RCUR (allowed since 2016).
6. **Self-service is read-only** on purpose (a member changing their own IBAN would be a SEPA fraud risk).
7. **Entity dirty tracking:** `Entity::setX()` does nothing if the value equals the current one, so a NOT NULL
   column that keeps its PHP default is omitted from INSERT - give such columns a DB default (see the
   `email` migration) or make the property default `null` (see `FeeRate::$amount`: a 0.00 fee-free category
   failed to insert until then; `tests/Unit/EntityInsertTest` pins it). Boolean fields need `addType('x', 'bool')` or an UPDATE that flips them fails with
   "Incorrect integer value".
8. **Annual fee run** (`FeeRunService`): one fee per active member by category, idempotent per member+year
   (cancelled fees don't count), preview first, all-or-nothing in a transaction. Mass status changes
   (`markPaidInClub`, `flagOverdueInClub`) are single SQL statements - row-by-row updates took minutes on the slow host.
   After a SEPA export the UI offers to mark exactly the exported fees (ids in `X-Sepa-Fee-Ids`) as paid.
9. `IQueryBuilder::execute()` no longer exists: use `executeStatement()` / `executeQuery()`.
10. Schema changes are `lib/Migration/Version*` classes (the legacy `appinfo/database.xml` is NOT processed).
   Bump `<version>` in `appinfo/info.xml` for every release so Nextcloud runs `occ upgrade` and the
   browser gets the new (immutable-cached) JS.
11. Calendar events are managed through `OCA\DAV\CalDAV\CalDavBackend` (the public `OCP\Calendar` API cannot
    update/delete). The calendar is shared read-only with Nextcloud groups configured per club - never with a
    public link (member birth dates are personal data).
12. **Audit log** (`AuditLogService`, injected as an optional collaborator like `MemberCalendarService` - a
    service without one just logs nothing, so existing tests that construct a service directly still compile):
    call `record()` after a create/delete, or `diff(before, after)` then `record()` with the result after an
    update, so a manual DI factory in `Application.php` must be updated too when you add the param (see
    `MemberService`, `FeeService`, `RoleService` there) - the ones without a manual factory (`ClubService`,
    `FeeRateService`, `FeeRunService`) are auto-wired and need no such change. Read back via
    `GET /audit-log` (`verein.audit.view`, club-scoped); entries with `clubId = null` (role definitions) are
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
  directory once overwrote hotfixes with stale files), copy `lib/`, `appinfo/`, `js/dist/`, `chown` to
  `www-data`, run `occ upgrade`, then compare `sha1sum` of the files with the repo. PHP changes take ~60 s
  to show up (opcache revalidation).
- The Bash tool can choke on heredocs with quotes or backticks; write helper scripts to files instead.
- Vue files may have CRLF line endings on Windows checkouts (`autocrlf`); normalise before multi-line patches.

## Planning
The plan lives in `ROADMAP.md` (done / next / ideas / known limitations). Data protection (anonymize/delete members,
admin data export, masking sensitive values in the audit log) is done (see rule 15) - built by the colleague it was
assigned to (`feature/anonymisieren`), coordinate before changing that area further.
Real-world data entry (bank account, creditor ID, real IBANs and mandates) happens in the running instance, not in the repo.
