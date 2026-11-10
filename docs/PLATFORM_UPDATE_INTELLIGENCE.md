# Platform Update Intelligence

Status: **Phase A (design) and Phase B (backend MVP) — PR 1.** The Owner UI (Phase C) and the notifications (Phase D)
are separate, later pull requests. Nothing here updates, installs or restarts anything: it checks and reports.

## Principle

Automatic **checking and notification**, never automatic **updating**. The module has no code path that executes a
command, edits a package, touches a lock file or contacts anything except the fixed advisory endpoint. A test
(`test_the_module_can_execute_nothing_and_update_nothing`) fails if `exec`, `shell_exec`, `proc_open`, `Process::`,
`Artisan::call`, `eval`, `sudo` or an update command appears in `app/Platform/Updates`.

## Phase A — discovery

Existing platform-monitoring that this module sits next to (and does not replace):

| Existing | What it does | Relation |
|---|---|---|
| `App\Owner\SystemStatus` / Owner "System" page | Health of application, database, queue, scheduler, Stripe, mail ... | Platform Updates becomes a sibling page (Phase C), not a new health dimension |
| `security:vulnerabilities:sync` (Wordfence v3) | Vulnerabilities of **customer WordPress sites** (plugins/themes) | Different subject; untouched |
| `monitoring:run`, `monitoring:deliver-alerts`, `MonitoringEngine` | Customer-site probes and alerts | The alert outbox is the candidate transport for Phase D |
| `App\Release\StagingSchedulePolicy` | Fail-closed allowlist of scheduled tasks on staging-next | `platform:updates:check` is classified `EXTERNAL` (needs the Owner's external-services opt-in) |
| `.github/workflows/actions.lock`, CI | Action pinning | Read-only source for Phase E (GitHub Actions) |

## Data model (migration `2026_11_10_000001`)

* `platform_components` — one row per installed component: `component_key` (`composer:<name>`, `npm:<name>[@version for nested copies]`,
  `runtime:*`, `connector:*`), `installed_version`, `version_state` (`VERIFIED`/`UNKNOWN`), `advisory_state`
  (`CHECKED`/`UNVERIFIED`/`UNKNOWN`), `evidence_source`, `development_only`, `present`.
* `platform_advisories` — one row per component and advisory id: aliases (CVE), severity (`critical|high|medium|low|unknown`,
  never estimated), summary, installed version at detection, `fixed_version` **only when the source proves one**, publication date,
  official source URL, `open`/`resolved`, `notified_at` (de-duplication for Phase D).
* **Freshness of an advisory row.** A fix is proven for one installed version only. When the installed version of a component changed and
  the advisory text cannot be read again (source error, detail budget), the advisory stays open and keeps its severity (the source still says it
  affects the new version) but its `fixed_version` is cleared; `affected_version` and `details_checked_at` stay as they were, so the row is
  never shown as freshly verified and is read again on the next check. A text read that succeeds replaces severity (a lowered one is taken
  over, a missing one becomes `unknown`), fix and `details_checked_at`. A resolved advisory that is reported again is reopened and announced again;
  a withdrawn advisory is never open.
* `platform_update_checks` — history of runs: trigger, status (`succeeded|partial|failed|refused`), fixed reason code, counts. No payloads.

## Sources of truth

Installed versions are read **only** from fixed, read-only sources of this deployment, never from assumptions:

| Component | Source | Advisory source (MVP) |
|---|---|---|
| Composer packages (Laravel, Stripe SDK, Guzzle, ...) | `composer.lock` of the running release | OSV (Packagist) — includes GitHub Security Advisories |
| NPM packages | `package-lock.json` | OSV (npm) |
| PHP, OpenSSL (PHP build), database, OS | the running process, the DB connection, `/etc/os-release` | **none yet → `UNVERIFIED`** |
| WP CareGrid Connector | plugin header of the packaged plugin | **none → `UNVERIFIED`** (first-party) |

Composer and npm entries come from the lock files, including development-only packages (`require-dev`, `devDependencies`). They are stored and flagged `development_only` but **not counted** as open or critical advisories, because a production release does not install them. A `fixed_version` is stored only if the source names one in a version range (ECOSYSTEM/SEMVER, never GIT commits) that contains the installed version; a withdrawn advisory is never open.

A component whose version cannot be read is `UNKNOWN`; a component without an advisory source is `UNVERIFIED`. Neither is ever shown as "up to date".
Not in the MVP, therefore `UNVERIFIED`/absent and listed as follow-ups: Ubuntu Security Notices, PHP/MySQL official announcements, Nginx/Apache,
Redis/Supervisor, Docker images, GitHub Actions advisories, Composer/NPM *available-version* lookup (latest release; needs Packagist/npm registry calls).

## Security boundaries

* The check runs in the application's own context (the web/queue/scheduler user of the deployment). That it is unprivileged is a deployment property (production runs the application as a non-root user), not something this code can enforce; the code itself uses no shell, requests no privilege and writes only to the database.
* Outbound traffic: two fixed HTTPS URLs (`https://api.osv.dev/v1/querybatch`, `https://api.osv.dev/v1/vulns/<id>`) hard-coded in `OsvClient`.
  No configuration or user input reaches a URL (SSRF), redirects are refused, 10 s timeout, 5 MB response cap, ids validated against a strict pattern.
* Every field of every answer is validated; any doubt raises a fixed code (`osv_*`) and the check is `failed`: **nothing is resolved and no component is marked
  `CHECKED` on the strength of a failed source** — a broken source can never look like "no advisories".
* A second check cannot start while one runs (cache lock, 30 min, longer than the 15 min run budget) nor within `min_interval_seconds` (re-checked inside the lock); the same advisory is one row (idempotent). A failed check also blocks a retry for the interval. `--manual` is refused when the feature is disabled.
* Privacy: only the ecosystem, package name and installed version of this deployment's components are sent to `api.osv.dev` (no customer data, no installation identifier).
* Size: a response whose `Content-Length` or received size exceeds 5 MB is aborted while downloading; the run has a time budget (detail lookups stop with a `partial` result).
* Off by default (`PLATFORM_UPDATES_ENABLED=false`): no scheduled outbound request until the Owner enables it. On staging-next it additionally needs the existing
  external-services opt-in (`StagingActiveProfile::externalContactClosed()`), otherwise the check is `refused` without any request.
* Output of the command is one fixed line (`PLATFORM_UPDATE_CHECK=<STATUS>;CODE=...;COMPONENTS=..;UNKNOWN=..;OPEN_ADVISORIES=..;CRITICAL=..`).

## Operation

`php artisan platform:updates:check [--manual]` (scheduled daily 05:20 UTC when enabled). No VPS configuration change is required to ship the code;
enabling it is an environment variable decision of the Owner.

`PLATFORM_UPDATES_ENABLED` is declared in `.env.example` as `false`. With it off the scheduled check does not run and `platform:updates:check`
refuses (`CODE=disabled`). Neither setting ever installs or updates software. (The Owner page, the manual queued check and the notifications
belong to the later phases C and D and are gated by the same flag in their own pull requests.)

### Not covered yet (no claim is made for these)

Ubuntu/OS and PHP/MySQL advisory coverage (runtime components stay `UNVERIFIED`), connector-specific advisory intelligence, detection of newer
available versions, Docker and GitHub Actions advisories, any other advisory source than OSV (Phase E).

## Phases

| Phase | Content | State |
|---|---|---|
| A | Discovery, architecture, boundaries | this document |
| B | Inventory, advisory processing, scheduled check, history, critical detection | PR 1 (this change) |
| C | Owner UI "System → Platform Updates" | PR 2 (separate) |
| D | Notifications with de-duplication and daily digest | PR 3 (separate) |
| E | Further sources: Ubuntu USN, PHP/MySQL announcements, available-version lookup, GitHub Actions/Docker | planned |

`PLATFORM_UPDATE_INTELLIGENCE` is **not** complete until C and D work.
