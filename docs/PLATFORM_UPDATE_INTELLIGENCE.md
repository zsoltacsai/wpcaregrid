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
* `platform_update_checks` — history of runs: trigger, status (`succeeded|partial|failed|refused`), fixed reason code, counts. No payloads.

## Sources of truth

Installed versions are read **only** from fixed, read-only sources of this deployment, never from assumptions:

| Component | Source | Advisory source (MVP) |
|---|---|---|
| Composer packages (Laravel, Stripe SDK, Guzzle, ...) | `composer.lock` of the running release | OSV (Packagist) — includes GitHub Security Advisories |
| NPM packages | `package-lock.json` | OSV (npm) |
| PHP, OpenSSL (PHP build), database, OS | the running process, the DB connection, `/etc/os-release` | **none yet → `UNVERIFIED`** |
| WP CareGrid Connector | plugin header of the packaged plugin | **none → `UNVERIFIED`** (first-party) |

A component whose version cannot be read is `UNKNOWN`; a component without an advisory source is `UNVERIFIED`. Neither is ever shown as "up to date".
Not in the MVP, therefore `UNVERIFIED`/absent and listed as follow-ups: Ubuntu Security Notices, PHP/MySQL official announcements, Nginx/Apache,
Redis/Supervisor, Docker images, GitHub Actions advisories, Composer/NPM *available-version* lookup (latest release; needs Packagist/npm registry calls).

## Security boundaries

* The check runs in the application's own unprivileged context (web/queue user), never as root; no shell, no new privilege, no new file write.
* Outbound traffic: two fixed HTTPS URLs (`https://api.osv.dev/v1/querybatch`, `https://api.osv.dev/v1/vulns/<id>`) hard-coded in `OsvClient`.
  No configuration or user input reaches a URL (SSRF), redirects are refused, 10 s timeout, 5 MB response cap, ids validated against a strict pattern.
* Every field of every answer is validated; any doubt raises a fixed code (`osv_*`) and the check is `failed`: **nothing is resolved and no component is marked
  `CHECKED` on the strength of a failed source** — a broken source can never look like "no advisories".
* A second check cannot start while one runs (cache lock) nor within `min_interval_seconds` (rate limit); the same advisory is one row (idempotent).
* Off by default (`PLATFORM_UPDATES_ENABLED=false`): no scheduled outbound request until the Owner enables it. On staging-next it additionally needs the existing
  external-services opt-in (`StagingActiveProfile::externalContactClosed()`), otherwise the check is `refused` without any request.
* Output of the command is one fixed line (`PLATFORM_UPDATE_CHECK=<STATUS>;CODE=...;COMPONENTS=..;UNKNOWN=..;OPEN_ADVISORIES=..;CRITICAL=..`).

## Operation

`php artisan platform:updates:check [--manual]` (scheduled daily 05:20 UTC when enabled). No VPS configuration change is required to ship the code;
enabling it is an environment variable decision of the Owner.

## Phases

| Phase | Content | State |
|---|---|---|
| A | Discovery, architecture, boundaries | this document |
| B | Inventory, advisory processing, scheduled check, history, critical detection | PR 1 (this change) |
| C | Owner UI "System → Platform Updates" | PR 2 (separate) |
| D | Notifications with de-duplication and daily digest | PR 3 (separate) |
| E | Further sources: Ubuntu USN, PHP/MySQL announcements, available-version lookup, GitHub Actions/Docker | planned |

`PLATFORM_UPDATE_INTELLIGENCE` is **not** complete until C and D work.
