# Platform Update Intelligence

Status: **Phases A-D are implemented in the source** as stacked pull requests (backend #352 and Owner page #353 are merged; notifications #354 is the last one). Nothing is deployed or enabled in any environment (`PLATFORM_UPDATES_ENABLED=false` by default). Nothing here updates, installs or restarts anything: it checks and reports.

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
| `App\Release\StagingSchedulePolicy` | Fail-closed allowlist of scheduled tasks on staging-next | `platform:updates:check` is classified `EXTERNAL` (needs the Owner's external-services opt-in), `platform:updates:digest` `MAIL`; `PlatformUpdateGate` asks this policy for every entry point |
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
* Off by default (`PLATFORM_UPDATES_ENABLED=false`): no scheduled outbound request until the Owner enables it. The flag is read fail-closed: only `true`, `1`, `yes` or `on` enables it; `false`, `no`, `off`, `0`, empty, `disabled` and any unknown or misspelled value read as off.
* One gate for every entry point (`PlatformUpdateGate`): the scheduler, the command, the queued job, the Owner button, the scanner and the notifier all ask the same question. Where the staging scheduler policy applies (staging-next recognised by its id, a requested `staging_active` profile or its exact host) the check additionally needs the Owner's external-services opt-in **and** the proven environment, otherwise it is `refused` (`staging_external_contact_closed`, `staging_environment_unproven`) without any request; notices and the digest need the proven environment and a confined mail transport (`staging_mail_not_confined`). The proof is read again on every call.
* Output of the command is one fixed line (`PLATFORM_UPDATE_CHECK=<STATUS>;CODE=...;COMPONENTS=..;UNKNOWN=..;OPEN_ADVISORIES=..;CRITICAL=..`).

## Operation

`php artisan platform:updates:check [--manual]` (scheduled daily 05:20 UTC when enabled). No VPS configuration change is required to ship the code;
enabling it is an environment variable decision of the Owner.

`PLATFORM_UPDATES_ENABLED` is declared in `.env.example` as `false`. With it off the scheduled check does not run and `platform:updates:check`
refuses (`CODE=disabled`). Neither setting ever installs or updates software. (The Owner page, the manual queued check and the notifications are gated by the same flag and the same `PlatformUpdateGate`.)

### Not covered yet (no claim is made for these)

Ubuntu/OS and PHP/MySQL advisory coverage (runtime components stay `UNVERIFIED`), connector-specific advisory intelligence, detection of newer
available versions, Docker and GitHub Actions advisories (the Actions are only inventoried, see Phase E1), any other advisory source than OSV (Phase E).

## Phases

| Phase | Content | State |
|---|---|---|
| A | Discovery, architecture, boundaries | this document |
| B | Inventory, advisory processing, scheduled check, history, critical detection | PR #352 |
| C | Owner UI "System → Platform Updates" (`/owner/system/platform-updates`) | PR #353 |
| D | Notifications with de-duplication and daily summary | PR #354 |
| E | Further sources: Ubuntu USN, PHP/MySQL announcements, available-version lookup, GitHub Actions/Docker | planned |

## Owner page (Phase C)

System → Platform Updates shows: overall state (never healthier than proven: no successful check, a failed last check, unknown versions, unchecked components or a last success older than two days are not "healthy"; healthy only means *no known advisory in the checked components*), tiles (open, critical, advisories with a proven fix, unknown versions, components without an advisory source), last successful check, last check, next scheduled check, advisory list and detail page, component table (filter by kind), history of checks, and one action: **Check for updates** (queued, read-only, throttled; refused when the feature is disabled or staging-next is closed). Development-only advisories are listed per component but never counted. English only (Owner area).

"Fixed in" has three meanings and the page keeps them apart: a version (the source proved a fix for the installed version), **none** (the source names no fixed version) and **not confirmed** (the advisory text was read for another installed version than the current one and could not be read again yet, so no stored fix is shown: the text is read again at the next check). The "Installed" version is always the component's current one, never the version the advisory text was last read for. A fix proven for another version is also not counted in "Advisories with a proven fix" and is not mailed (notices show the installed version and a fix only when it is confirmed).

## Notifications (Phase D)

Built on the existing Owner mail path (verified owners without a customer account; on staging-next the existing `StagingMailGuard` confines every message and nothing is sent unless it is confined):

| Event | Notification |
|---|---|
| A critical advisory affects an installed production component | one prominent notice (`critical`) |
| A new non-critical advisory | one notice (`security`) |
| The check failed | one system warning per UTC day (`failure`) |
| Daily summary (06:30 UTC) | only when something is open, the last check failed, or results are stale or missing |

De-duplication: an advisory is announced once (`notified_at`; re-opened advisories are announced again, and so is one that was announced as less serious and is later read as `critical` — escalation; a lowered or unchanged severity is never a new notice); the same vulnerability under another id (CVE/GHSA alias) is marked announced without a mail; every notice is claimed by a unique key (`platform_update_notifications.dedupe_key`) before it is sent; a failed send releases the key (the failed row stays as history) so the next check retries. UNKNOWN/UNVERIFIED data and development-only components never notify. Notice content: component, installed version, advisory/CVE, severity, fixed version only if proven, official source, and the statement that nothing is updated automatically.

Failure handling: a notice that cannot be delivered is never reported as sent. An exception from the mail layer (`mail_transport_failed`) and a message that a `MessageSending` listener stopped without an exception, such as the staging mail guard (`mail_not_sent`), release the claim (status `failed`, key freed) and leave the advisories unannounced, so the next check retries only the owners who did not get it; a failure is retried at most once per check, never in a loop. Each failure is written as one structured `warning` log line and one Owner audit entry (`platform_update.notification_failed`, actor `system`) carrying the notification id, the notice kind, the fixed code, the exception class, the check uuid, a per-round correlation id and a short hash of the recipient. Never the exception message, the address, the mail body or any credential. If the message went out but its claim could not be marked sent, it counts as delivered (a retry would be a duplicate) and is recorded as `platform_update.notification_unrecorded` with an `error` log line. Writing the log or the audit entry can fail without stopping the check.

Not in this version: notifications for *available* (non-security) version updates, because no available-version source is connected yet (Phase E); SMS/chat channels.

## Phase E1 — GitHub Actions inventory (inventory only)

`PLATFORM_UPDATES_ACTIONS_INVENTORY` (default `false`, strict like `PLATFORM_UPDATES_ENABLED`, and only effective while that switch is on; decided by `PlatformUpdateGate::actionsInventoryEnabled()`) adds the GitHub Actions the workflows use to the inventory, read from `.github/workflows/actions.lock`:

* Read only, one local file, no outbound request, no new staging permission, no migration. The file is parsed by `ActionsLockParser` against the one supported structure (format `v0.0.3`) line by line; limits: 64 KiB, 2000 lines, 300 characters per line, 200 entries, a component key that fits its column; every action a workflow names must be described under `dependencies`, and `version:` comes first. Anything else (unknown version, unexpected line, duplicate, over a limit, symlink, unreadable, missing) rejects the whole file and yields one `github-action:lock` component with an unknown version, never a partial or empty list.
* One component per action and pinned commit (`github-action:<owner>/<repo>@<commit>`; the same action under its tag and its SHA is one component), `kind = github-action`, `ecosystem = null`. The version is the lock's `ref` when it looks like a version, otherwise unknown.
* **No advisory evaluation.** OSV does not evaluate versions for the `GitHub Actions` ecosystem (a version known to be affected returns nothing), so a version query would look like "no advisories". These components are never sent to OSV, are never `CHECKED`, never have advisories, and always stay `UNVERIFIED` (they count in "no advisory source"). Switching the inventory off stops reporting them.
* The inventory is the lock's dependency list, not a scan of the workflow files. A lock that turns invalid leaves earlier rows as they were (an unreadable file proves nothing) next to the UNKNOWN placeholder; a release artifact without `.github/` shows only the placeholder while the switch is on.
* Not done: any advisory source or local vulnerability-range evaluation for Actions (a separate Owner decision).

## Known limits

* Only Composer, npm and (read-only) runtime/OS/connector inventory; runtime, OS and connector have **no advisory source yet**.
* Ubuntu USN, PHP/MySQL announcements, Nginx/Apache, Redis/Supervisor and Docker images are not monitored (Phase E). GitHub Actions are only inventoried and stay `UNVERIFIED` (Phase E1).
* OSV is a third-party service; its availability limits the check (a failed check is reported, never treated as clean).
* The migrations add table sets; staging-next's deploy e2e ledger literals move accordingly (see the pull requests).

`PLATFORM_UPDATE_INTELLIGENCE=READY_FOR_REVIEW` once the four pull requests are reviewed; it is not deployed.
