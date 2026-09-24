# Vereinsverwaltung (Nextcloud app `verein`)

Nextcloud app for managing one or several clubs (Vereine): members, memberships, fees, SEPA direct
debit export, roles/permissions, calendar reminders and member self-service. Private fork of
`Wacken2012/nextcloud-verein` (remote `upstream`; our history is grafted onto upstream `64a9b8b`).
UI language is German, code and comments are English. Target: Nextcloud 34, PHP 8.3+, Vue 3.

Read `docs/ARCHITEKTUR.md` for the data model and the decisions behind it. This file is what you
must know to change code safely.

## This repo is private but must stay free of identifying data
- No real domain, host names, IPs, container names, user names, employer names or member data in
  code, tests, docs or commit messages. Use `cloud.example.org`, `Mustermann`, `DE89 3704 0044 0532 0130 00`.
- Commits use the noreply identity configured for this repo; do not override it with a work e-mail.
- Real member data lives only in the running Nextcloud database, never in the repo.
- History was rewritten once to remove such data. Do not reintroduce it; check `git diff` before pushing.

## Commands
```
composer update                       # dev deps: phpunit + Nextcloud OCP stubs (composer.lock is not committed)
vendor/bin/phpunit --testsuite "Unit Tests"    # must stay green; CI runs the same (.github/workflows/tests.yml)
npm install && npm run build          # bundles js/main.js -> js/dist/ (dist is not committed)
```
`tests/Integration` is not part of the suite (stale sanitizer tests, known).

## Architecture in one screen
- **Club (`verein_clubs`)** is the top level: name (unique), team-folder path, calendar groups, bank
  accounts (`verein_club_accounts`: IBAN, BIC, creditor ID), and `role_mapping` (see automatic rights).
- **Person (`verein_members`)**: name, address, birth date, own IBAN/BIC, `user_id` (linked Nextcloud
  account, unique). One row per human, shared by all clubs.
- **Membership (`verein_memberships`)**: person x club - role (`member|treasurer|admin`=Vorstand),
  join/leave date, founding flag, SEPA mandate (reference, signature date, signed file path).
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
3. **Privilege escalation:** a member's `role` and `user_id` drive automatic rights, so only holders of
   `verein.role.manage` may change them (`MemberController::canManageRoles()`); role *definitions* are
   Nextcloud-admin only; creating/deleting clubs is Nextcloud-admin only. Keep it that way.
4. **Automatic rights** (`RoleService::effectiveRoles()`): explicit assignments + roles mapped from the linked
   person's *active* memberships (no leave date, not deceased). No mapping configured = nothing derived.
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

12. **CSRF:** never put `@NoCSRFRequired` on a POST/PUT/DELETE action (it once was everywhere; a sibling site on the same
    registrable domain could then forge writes). Only GET reads and file downloads may have it - `RoutePermissionsTest` enforces it.
    The frontend goes through `js/api.js` (`@nextcloud/axios` adds the token); do not use raw `fetch` for writes.

## Working conventions
- **With every feature/fix/release update all three: `CHANGELOG.md` (new entry at the top, matching the
  `info.xml` version), `ROADMAP.md` (table "Stand dieses Forks" at the top) and the roadmap below.** Also
  `docs/ARCHITEKTUR.md` when the data model or rules change. Do this in the same commit as the change.
- Ask before anything hard to reverse or outward-facing (data changes on a live instance, pushing, posting).
- Personal data: when a request could mean "public", "any user" or "specific group", ask which.
- Test security-relevant changes with a **real non-admin Nextcloud account** (temporary, deleted afterwards);
  an admin session hides permission bugs.
- Deploying to a Nextcloud container: stage into a **fresh empty directory** every time (a reused staging
  directory once overwrote hotfixes with stale files), copy `lib/`, `appinfo/`, `js/dist/`, `chown` to
  `www-data`, run `occ upgrade`, then compare `sha1sum` of the files with the repo. PHP changes take ~60 s
  to show up (opcache revalidation).
- The Bash tool can choke on heredocs with quotes or backticks; write helper scripts to files instead.
- Vue files may have CRLF line endings on Windows checkouts (`autocrlf`); normalise before multi-line patches.

## Roadmap (agreed order)
1. Security & reliability - done (RBAC verified, runnable tests + CI, e-mail optional).
2. Real-world data (IBANs, mandates, bank account/creditor ID, open import questions) - mostly manual work.
3. Fees in daily use - done: categories per club, annual fee run with preview, mark exported fees paid, flag overdue.
   Still open: reminder letters / dunning levels (Mahnungen), pro-rata fees for members who join mid-year.
4. Data protection: delete/lock members, per-person data export, change log - taken over by a colleague (coordinate before starting).
5. Cleanup/publishing: slim README/docs (many stale upstream docs still in the repo), proper 404 vs 500 codes,
   automatic backup of the club tables.
