# Staging PHP Runtime Migration Procedure

**Status:** source-only crash-safe bootstrap design is incomplete; host execution is **HOLD**. PHP 8.5 installation and staging cutover remain **NO-GO** until separately reviewed and approved. This document does not authorize package, service, backup, runtime, or deployment changes.

## Scope and invariants

The migration uses only the fixed profiles `/usr/bin/php8.4` and `/usr/bin/php8.5`. It does not use `/usr/bin/php`, PATH lookup, or `update-alternatives` for application PHP commands. PHP 8.4 remains installed and its shared FPM service and socket remain available to sibling vhosts. The Nginx selector is scoped to the single `staging.wpcaregrid.com` vhost; the helper refuses a selector referenced by any other vhost. Queue and scheduler commands use the root-owned `/usr/local/bin/wpcaregrid-php` dispatcher, which accepts only the application Artisan entry point and resolves the same profile as the Nginx selector.

The existing exact-SHA CI verifier, branch-tip check, deployment lock, protected DB backup, maintenance gate, scheduler lock/pause, queue drain, application health checks, and fail-closed recovery remain required. The profile switch is attempted only after the gate and scheduler pause are active and both queue workers are stopped. The helper rejects arbitrary profile names, paths, commands, Nginx changes, and Supervisor commands.

## Current release-gate compatibility

Protected primary `010cfe4568d1943fc2c0924c5c22598d75d684ea` (PR #217 merge) has successful exact-SHA CI [40828956859](https://github.com/zsoltacsai/wpcaregrid/actions/runs/40828956859): 37/37 jobs, all seven required contexts, both MySQL process-concurrency gates, the PHP 8.4 SQLite regression and all 33 WordPress 6.8/7.1 Connector matrix jobs passed. This is repository validation only; it does not establish staging-host readiness, effective server configuration, successful deployment, or production readiness.

PR #209 registers `MarketingOnlyReleaseGate` globally and removes the staging exemption. With the documented staging identity (`APP_ENV=production`, `CAREGRID_DEPLOYMENT_ENV=staging`), the exact three-value testing exemption cannot apply. Under the current gate, requests outside its explicit public marketing/legal/health allowlist return an empty 404 before route dispatch; authentication, Owner, Operator, account, and `/api/v1/connector/*` endpoints are among the denied routes. `invitation_beta` also does not grant backend access. The live staging configuration cache was not inspected; current live behavior remains UNKNOWN.

PR #217 resolved the repository-level deployment-smoke conflict and its exact merge-SHA CI passed. This does not authorize a staging deployment or establish that backend endpoints are available on the live host. Do not restore `staging_exempt`, use PHPUnit testing identity values to enable staging, or weaken the production marketing gate. The source-defined route behavior and the live host configuration must remain distinct in any later readiness decision.

## PHP 8.4 bootstrap — crash-safety blocker (source-only draft)

The current controller/helper pair cannot safely bootstrap the missing selector/dispatcher from the previously described host state. The controller preflight requires the selector before the installer runs; it does not itself require the dispatcher, but the reviewed controller/helper flow can install the scheduler wrapper before the dispatcher exists. The fixed wrapper fails closed at its fixed dispatcher path and has no arbitrary or unversioned PHP fallback; without the dispatcher, however, that scheduler invocation is unavailable, and any pre-existing dispatcher requires fresh provenance verification. The helper does not implement the required legacy-cron-to-sentinel handoff, the legacy `schedule:run` process does not honor the new scheduler lock, and current worker startup can consume jobs before post-start executable verification. The HTTP maintenance gate does not fence queue consumers or all job producers. No current live-host facts are asserted here.

The Integration & QA and M17 AppSec requirements recorded in issue #174 comments [6489961478](https://github.com/zsoltacsai/wpcaregrid/issues/174#issuecomment-6489961478), [6489967627](https://github.com/zsoltacsai/wpcaregrid/issues/174#issuecomment-6489967627), [6490025734](https://github.com/zsoltacsai/wpcaregrid/issues/174#issuecomment-6490025734), and [6490066340](https://github.com/zsoltacsai/wpcaregrid/issues/174#issuecomment-6490066340) are binding. A seeder-only change followed by the unchanged controller is not an accepted bootstrap design.

### Required durable state contract

Any future implementation must use a root-owned, restrictive, durable journal with versioned state, transaction identifier, source hashes, last completed operation, and recovery disposition. Journal replacement and marker transitions must use fixed paths, no-follow validation, same-filesystem temporary files, file `fsync`, atomic rename, and parent-directory `fsync`; readers must treat absent, malformed, stale, or inconsistent state as blocked. State transitions must be idempotent. Traps and lock lifetime are supplementary only. On restart/reboot, no incomplete state may automatically start workers, run Laravel scheduling, reopen HTTP traffic, or choose an unversioned PHP executable.

| State | Required meaning | Restart/recovery behavior |
| --- | --- | --- |
| `IDLE` | No transaction is active; live source/host preconditions were freshly verified. | A missing/corrupt journal combined with any transition marker is not IDLE; refuse and keep all available holds. |
| `LOCKED` | Controller deployment lock acquired first, scheduler lock second; both held and verified before mutation. | Lock failure means no writes. Lock loss after mutation enters `RECOVERY_REQUIRED`. |
| `FENCED` | Traffic gate, durable scheduler pause, and durable producer/consumer hold committed under both locks. | Keep gates and holds. Never infer producer suspension from HTTP gate or empty queue counts. |
| `CRON_SENTINEL` | Only the fixed legacy cron line was replaced with the fixed inert sentinel; sentinel execution token was observed; all old `schedule:run` processes exited naturally. | Keep scheduler paused. Do not kill active schedule processes or install the invoking wrapper before dispatcher verification. |
| `RUNTIME_READY` | Exact reviewed dispatcher and PHP 8.4-only profile verified; fixed staging selector is validated against effective Nginx scope; dispatcher precedes any wrapper capable of invoking it. | Remain non-consuming and gated. No reload is implicit. |
| `CONSUMERS_HELD` | Supervisor and cron restart paths remain durably unable to launch real work; worker and scheduler commands are pinned to the protected dispatcher. | Autostart/reboot must remain held until an explicit validated release transition. |
| `IDENTITY_VERIFIED` | Non-consuming PHP 8.4 probe passed. If worker argv/executable verification needs processes, a fail-closed startup shim must prove they cannot enter `queue:work` before durable release authorization. | No real work may be consumed merely to check `/proc`; verified identity alone does not reopen producers. |
| `RECOVERY_REQUIRED` | Any interrupted, failed, unknown, or inconsistent transition. | Default state: traffic gated, scheduler paused, queue consumers stopped/held, no automatic marker clearing or unversioned PHP fallback. Recovery is explicit, reviewed, and idempotent. |
| `RELEASE_APPROVED` | The durable journal references a separately authenticated Owner GO bound to the exact host identity, source SHA, and named operation, and records validated queues, workers, scheduler effects and recovery evidence. The journal state itself is not authorization. | Only a valid, matching external approval plus the durable state may permit a controlled handoff; traffic-gate release remains a distinct Owner-approved action. |

The intended lock hierarchy is the existing controller deployment lock, then the exclusive scheduler lock, both acquired and verified before the first journal, marker, cron, selector, or Supervisor mutation. No root-admin seeder may retain either lock across a separate controller invocation. A selector-only root-admin tool may be considered separately, but it must not pretend to complete the controller bootstrap or release any gate.

### Queue and producer-fencing blocker

The current application does not expose one proven, durable fence shared by every queue producer and worker startup path. Repository source shows queue writes from HTTP/account/operator/Connector and billing/backup/security workflows, scheduled commands that dispatch jobs, Artisan commands, and other application code. The queue includes separate `default` and `backups` worker programs, delayed/retry/leased states, and active backup work. This source inventory is not proof of complete runtime producer coverage.

Before any real worker can start, a later implementation must provide an authoritative inventory for every configured queue and connection covering ready, delayed, reserved/leased, executing/in-flight, retry and recovery states; prove active backup jobs and leases safe; and durably block every producer (HTTP/Connector ingress, Laravel scheduler/cron, CLI/deploy, and any additional source) across concurrent requests, process failure and reboot. A one-time queue-empty count is insufficient. Delayed work must not become consumable before identity verification. Both fixed worker commands must resolve through the protected dispatcher to `/usr/bin/php8.4`, with executable and argv evidence, while a fail-closed startup mechanism prevents autostart or reboot from bypassing the journal.

If those producer and startup fences cannot be proven, workers remain stopped. Use a separate non-consuming PHP 8.4 runtime probe for interpreter validation; do not launch `queue:work` merely to inspect its process identity. Do not run `schedule:run` as a bootstrap heartbeat: that is an actual scheduling event with potentially external side effects and requires separate Owner approval. Scheduler resumption also requires bounded heartbeat verification, accounting for all scheduled side effects, and proof that no old schedule process or unaccounted dispatched work remains.

### Draft boundary and required implementation evidence

This PR is documentation-only because the current source does not yet demonstrate the complete crash-safe producer/consumer invariant. Runtime code that starts workers, resumes cron/scheduler, or releases markers would be unsafe until a common durable producer fence and reboot-safe consumer startup hold are implemented and tested. This is an explicit blocker, not a claim that the bootstrap design is accepted or ready for host execution.

A subsequent source implementation must cover: fixed helper contract and inert sentinel; exact sentinel execution proof; graceful bounded drain of every legacy `schedule:run`; dispatcher-before-wrapper ordering; root-only selector seeder with no sudo allowlist expansion; `openat`/no-follow path validation and fixed destinations; atomic durable journal/marker writes; PHP 8.4-only dispatcher selection; staging-only effective Nginx validation; unchanged shared PHP 8.4 FPM socket and sibling vhosts; Supervisor/cron restart-path fencing; non-consuming identity probe or equivalent closed startup shim; deterministic idempotent recovery; and explicit privilege checks. It must include fixture/mock fault injection for competing locks, lock failure before mutation, sentinel delay, legacy scheduler overlap, active backup lease, delayed jobs becoming due, concurrent producers, wrong worker executable/argv, crash/reboot after every journal/marker write and clear, worker startup and scheduler release, corrupt journal, Nginx validation failure, sibling-vhost isolation, repeated execution, unknown prerequisites, no unversioned fallback, and forbidden sudo expansion. No test may target staging.

Host execution remains **HOLD**. The installed helper/controller hashes and sudo scope, staging checkout/config-cache identity, PHP 8.4 CLI/FPM/socket, Nginx include graph, selector, dispatcher/wrapper, Supervisor ACL/config and live processes, cron/scheduler, queue/backup/lease state and all marker states remain **UNKNOWN** until fresh authorized sanitized read-only evidence is supplied. Selector seeding, helper replacement, Nginx reload, controller execution, worker/scheduler resumption and traffic-gate release each require separate Owner GO. PHP 8.5 installation/cutover and global alternatives changes remain **NO-GO**.

## Required host preparation (ordered gates; each write needs separate Owner approval)

This section is a preparation plan, not authorization to act. Gate 1 is blocked pending a reviewed implementation that satisfies the durable state and producer/consumer fence above, plus fresh host evidence and separate Owner GO. The controller preflight requires the selector; independently, wrapper-before-dispatcher ordering leaves the scheduler invocation unavailable if no verified dispatcher is installed. The fixed wrapper fails closed and does not use a PHP fallback. Do not invoke the existing controller as the bootstrap path in either case. PHP 8.5 installation, staging cutover, production release and infrastructure changes remain NO-GO absent their own approvals.

### Gate 1 — pin application consumers to PHP 8.4 first

Before any PHP 8.5 package is installed:

1. Read-only record the current PHP 8.4 CLI/FPM versions, shared 8.4 FPM service/socket state, the global `php` alternatives mode and selection, and the exact Supervisor and scheduler consumer commands. The current alternatives mode is auto; do not change it as part of this runbook step.
2. Do not run the existing controller to seed missing prerequisites. It checks the selector/runtime before its installer path; the root helper does not create the selector, and current transition ordering does not establish reboot-safe producer/consumer fencing. A future reviewed implementation must satisfy the durable state contract above before a separate Owner GO can be requested.
3. Treat Supervisor/cron changes and any real consumer startup as separate future operations. Require fresh sanitized evidence, the full authoritative queue/backup/lease inventory, the durable restart fence, and a separately approved rollback procedure before changing either. Keep PHP 8.4 FPM and its shared socket available to sibling vhosts.
4. Before Gate 1 is accepted, read-only verify both live workers resolve to `/usr/bin/php8.4`, their command lines use the dispatcher, and the scheduler invokes the fixed wrapper/dispatcher with the 8.4 profile. Confirm no application worker or scheduler command relies on unversioned `php` or PATH lookup. Record sanitized evidence only.
5. Keep the existing deploy-only sudo policy. Install the root helper only from its reviewed merge commit and source hash; do not grant general sudo, root SSH, package-manager access, or direct service commands to deploy.
6. If any consumer cannot be pinned and verified, stop. Do not proceed to PHP 8.5 package installation. Do not alter global alternatives, Nginx, FPM configuration, or services during this gate unless a separate Owner GO explicitly covers that exact change.

### Gate 2 — separately approved PHP 8.5 side-by-side package installation

Only after Gate 1 is complete and independently verified, prepare a distinct package-install request:

1. Revalidate the Ondřej PHP PPA signer, signed repository metadata, candidate package hashes, host architecture, required extensions and exact CLI/FPM package versions. The supplied 2026-10-28 evidence records the signer and inspected candidate integrity as verified; refresh that evidence immediately before package use.
2. Inspect the exact package maintainer scripts and a fresh APT simulation. The PHP 8.5 CLI package can register priority-85 `php` alternatives while global alternatives are in auto mode. Do not run `update-alternatives` or change its mode under this plan. The dispatcher/consumer pinning in Gate 1 is a prerequisite, not proof that unrelated host consumers are unaffected; account for and verify the package-script effect within the separately approved installation plan.
3. Review and explicitly approve the PHP 8.5 FPM package's service-start behavior before installation. It may attempt to start its versioned FPM service. If the effect cannot be safely controlled and verified without changing Nginx or disturbing PHP 8.4/shared services, stop and revise the installation plan before requesting GO.
4. After a distinct Owner GO, install only the reviewed PHP 8.5 CLI/FPM packages and required extensions side-by-side. Do not remove or upgrade the PHP 8.4 rollback packages. Keep PHP 8.4 FPM and its shared socket available; do not change Nginx selectors or any vhost in this installation-only step.
5. After installation, perform read-only checks of `/usr/bin/php8.5`, package versions, both FPM services and sockets, `[www]` worker UID/GID (`www-data`), and socket owner/mode (`www-data:www-data`, `0660`). Confirm the effective PHP 8.5 FPM settings, including Zend OPcache availability and `opcache.enable`, `opcache.validate_timestamps`, and `opcache.revalidate_freq`; PHP 8.5 OPcache is statically built in, so a separate OPcache package or `.so` is not expected. Keep the shared PHP 8.4 FPM pool/socket available to sibling vhosts. Unknown or out-of-contract values block any later cutover.

### Nginx selector preparation (separate Owner GO)

Nginx configuration is outside the authorized scope of this PR and remains unchanged during both preparation gates. If the reviewed PHP 8.4 bootstrap route needs the runtime selector, obtain a separate Owner GO for this exact prerequisite before using that route. Otherwise, prepare the selector only after Gate 2 and later cutover prerequisites pass. Any Nginx change is limited to an independently approved operation. The required root-owned mode-0644 snippets each contain exactly one directive:

- `wpcaregrid-staging-php84.conf`: `fastcgi_pass unix:/run/php/php8.4-fpm.sock;`
- `wpcaregrid-staging-php85.conf`: `fastcgi_pass unix:/run/php/php8.5-fpm.sock;`

Set `wpcaregrid-staging-runtime.conf` to a root-owned symlink to the 8.4 profile initially. Change only the `staging.wpcaregrid.com` vhost to include this selector at its PHP FastCGI location. Do not change other vhosts. The helper verifies expanded Nginx configuration and fails unless that exact include is used once and only in the staging vhost. PHP 8.4 FPM/socket remains shared with other vhosts and must not be repointed.

Do not use the current controller as a Gate 1 bootstrap or consumer-transition mechanism, even if selector prerequisites are present: its worker/scheduler crash and producer-fencing contract is not accepted. A future reviewed implementation and fresh host evidence are required before requesting any Gate 1 operation GO. No staging package or configuration operation was performed for this PR.

## Protected cutover — blocked pending reviewed transition implementation

The command below is a record of the intended later interface only. It is **not executable authorization**. Do not invoke the current controller for bootstrap or PHP switching: the crash-safe marker/journal and full producer/consumer fences described above are not implemented or independently accepted. A later PR must fix and test the transition, receive Integration & QA and M17 AppSec review, and be followed by fresh host evidence and a separate Owner GO for the exact operation.

```bash
scripts/deploy-staging.sh <exact-approved-primary-merge-sha> --php-runtime 8.5
```

The current implementation includes branch/CI checks and deployment receipts, but its existing independent marker and worker/scheduler transitions do not yet satisfy the crash/reboot safety contract in this document. Do not treat the command as safe because its checks pass. Any future approved operation must keep traffic and consumers gated until an explicit durable release state, and must use a separately approved database backup and side-effect plan.

Do not manually switch the selector, edit Supervisor, run Artisan, install packages, or invoke the root helper outside a future reviewed workflow. Current crash recovery is not proven across power loss/reboot; retain all existing gates and do not clear markers to retry. No host action is authorized by this runbook.

## Rollback

Rollback is a full code-and-runtime rollback, not only a Git checkout. Its mechanism remains blocked on the same crash-safe transition implementation and fresh host evidence. The following command records the intended later interface only, for a separately Owner-approved baseline and operation:

```bash
scripts/deploy-staging.sh --rollback <administrator-approved-baseline-sha> --php-runtime 8.4
```

Do not assume the existing helper/controller safely restores the selector, worker and scheduler state after a crash. A future reviewed rollback must retain the shared PHP 8.4 FPM service/socket, use only pinned `/usr/bin/php8.4`, leave traffic/consumer gates closed until durable recovery checks pass, and never restore an unversioned PHP command. Do not remove or downgrade the PHP 8.4 rollback packages.

If target migrations may have changed data, automatic code/runtime rollback is refused and the gate stays active. Database restore or migration rollback requires a separate reviewed decision and verified backup; it is not part of runtime switching.

## Stop conditions and evidence

Stop and retain the gate if any binary, version, service, socket owner/mode, Nginx include scope, Supervisor command, worker executable, Composer platform requirement, cache build, scheduler heartbeat, health check, or HTTP smoke test differs from the fixed contract. Record only sanitized command classifications and the release receipt; never report `.env`, configuration cache contents, customer data, or secrets.

Required evidence before the Owner can approve cutover:

- Verified PPA signing fingerprint and signed repository metadata.
- PHP 8.5 CLI/FPM package versions and required extension compatibility.
- Read-only verification of the exact staging vhost, FPM pools/sockets, Supervisor commands, scheduler path, and absence of stale PHP 7.4 dependencies.
- Fresh staging backup created through the documented mechanism, integrity-checked, with recoverability evidence; backup-worker idle/drain procedure reviewed so active backup work is not interrupted.
- Exact target merge SHA and all required CI jobs green.
- Exclusive staging window, rollback SHA, operator, stop conditions, and post-cutover health checklist.

Until these are independently verified and approved, staging cutover is **NO-GO**. Production remains out of scope.

## Compatibility and release-gate matrix (reviewed 2026-10-28)

This is a CI/source snapshot, not host evidence. Recheck the primary branch, ruleset and exact-SHA push run immediately before any later cutover.

| Layer | Current automated coverage | Limit |
| --- | --- | --- |
| Laravel server | PHP 8.4 and 8.5, each against MySQL 8.4; full server test suite and that PHP lane's MySQL process-concurrency gate | No Laravel PHP 8.5 SQLite or MariaDB lane |
| Laravel SQLite regression | PHP 8.4 targeted SQLite regression gate | Does not establish PHP 8.5 SQLite compatibility |
| WordPress Connector | PHP 8.2–8.5 across WordPress 6.8/7.1 with MySQL 5.7, MySQL 8.0, MySQL 8.4 and MariaDB 10.11; the protected PHP 8.4 / WordPress 7.1 SQLite, MySQL 8.4 and MariaDB 10.11 contexts | Connector compatibility is separate from Laravel server runtime coverage |

The seven protected contexts at review time are Quality; Server PHP 8.4/MySQL; Server PHP 8.5/MySQL; Server SQLite/PHP 8.4; and Connector PHP 8.4/WordPress 7.1 SQLite, MySQL and MariaDB. Both server MySQL jobs include their own process-concurrency gate. Do not remove the PHP 8.4 rollback lane, change the workflow, or infer host readiness from these tests.

The merged changes represented by PRs #209, #210, #214, #215 and #216 must be interpreted within their scope:

- PR #209's marketing gate is global; its synthetic test identity is not a staging configuration. PR #217 resolved the repository-level smoke conflict, but live staging endpoint/configuration behavior and backend readiness remain UNKNOWN.
- PR #210's Chromium marketing tests use an isolated loopback server, synthetic SQLite and testing-only configuration. They validate rendered marketing UX and contact-only pricing presentation, not staging authentication, Connector APIs, deployment smoke or backend readiness.
- PR #214 makes the legacy receipt writer default an omitted PHP binary to validated `/usr/bin/php8.4`, while current callers can pass their selected binary explicitly. This fixes receipt compatibility only; it does not install the root helper, create the runtime dispatcher, seed the Nginx selector, or make a controller with unmet preflight prerequisites a bootstrap path.
- PR #215 updates the locked `symfony/mime` dependency from 8.1.7 to 8.1.10. Deploy and rollback checks must use the exact approved release's `composer.lock` and run platform checks under the selected pinned PHP binary; the dependency fix does not prove host compatibility.
- PR #216 makes the Connector inventory test assert the WordPress core version supplied by the test runtime instead of a hard-coded patch release. The resulting WordPress 6.8/7.1 matrix is repository test coverage, not live Connector or staging evidence.

The exact primary CI run includes both PHP 8.4/8.5 MySQL process-concurrency gates, the PHP 8.4 SQLite regression, and the complete Connector matrix. These results support source review only. They do not clear the marketing-gate blocker or authorize deployment, package installation, cutover, or production release.

At this snapshot, the application declares PHP `^8.3`, Laravel `^13.17`, and Composer's lock platform is PHP `^8.3`; the resolved Laravel lock version is 13.33.0. These constraints admit PHP 8.5 but are not a substitute for runtime validation. Laravel 13's documented minimum is PHP 8.3 and its documented extension set includes Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer and XML. The deployment contract additionally requires `intl`, `zip`, `pdo_mysql`, and `pcntl` for CLI queue workers. Validate the real PHP 8.5 CLI and FPM runtimes with the protected `composer check-platform-reqs --no-dev --lock` gate plus the application-specific extension checks; do not use `--ignore-platform-reqs`. The Composer command checks locked package requirements, so it does not replace checks for application-specific extensions.

## PHP security advisory preflight

The PHP project lists PHP 8.4.27 and 8.5.12 as the October 2026 releases fixing CVE-2026-109618 and CVE-2026-109619. The version alone does not prove that a distro package came from the expected signer; the Ondřej PHP PPA signer and cached InRelease signature must be independently verified before package use.

A separate PHP notice dated 2026-10-23 recommends setting `session.upload_progress.enabled=0` until a later regular bugfix release, for an unusual session-serialization configuration that can expose an RCE path. Before cutover, recheck the current PHP advisory and the effective setting for both CLI and FPM profiles, and verify the application does not switch `session.serialize_handler` at runtime before session start. If the notice still applies and the effective setting or application behavior is unknown, STOP; any configuration change needs its own Owner approval and verification. Do not expose a public phpinfo page.

## Time-bounded rehearsal and acceptance

Proposed window: one exclusive 60-minute staging window, with its calendar start approved by the Owner and Integration & QA. If the window starts without all gates green, or a required step cannot complete before expiry, retain the maintenance gate and stop for review. This proposal does not authorize a backup, package change, service restart or cutover.

1. **Before the window:** capture a same-day sanitized read-only host inventory; verify PPA key fingerprint and signed InRelease; record exact primary release SHA and all seven protected checks; verify PHP 8.5 CLI/FPM package versions, required extensions, FPM pool UID/GID, socket owner/mode, selector scope, Supervisor commands and live worker binaries; resolve the PHP 7.4 inventory; verify current PHP advisories and effective session settings.
2. **Backup and drain gate:** obtain a separate Owner GO for a fresh staging backup using the documented protected backup mechanism. Confirm the backup worker is idle and no backup transfer/job is active; never interrupt a running backup. Drain queue work gracefully under the controller's pause/lock procedure. Verify the backup manifest and integrity, confirm the recorded database recovery point, and obtain recoverability evidence from an approved isolated verification. If drain or integrity verification fails, do not switch PHP.
3. **Cutover:** only after the exact branch-tip / CI gate, host preparation and approvals pass, run the protected controller with the exact approved SHA and `--php-runtime 8.5`. Do not manually edit the Nginx selector, Supervisor, scheduler, alternatives or service state.
4. **Post-switch checks:** while the maintenance gate remains active, verify the selected FPM socket/pool and worker identity, run `composer check-platform-reqs --no-dev --lock` under PHP 8.5, verify both Supervisor workers' live executable and version, scheduler heartbeat/interpreter, Laravel system health, HTTPS smoke checks, and unchanged behavior for sibling vhosts using PHP 8.4. Compare against the recorded PHP 8.4 baseline (including its existing Connector and backup-storage warnings); any new error, 5xx, missing heartbeat, wrong executable/socket, extension mismatch or unexplained warning is STOP.
5. **Rollback:** use the controller's separately approved PHP 8.4 baseline SHA with `--rollback ... --php-runtime 8.4`. Keep PHP 8.4.27 CLI/FPM packages, shared FPM service/socket and worker/scheduler profile intact. Confirm PHP 8.4.27 is selected, both workers and scheduler resolve to the 8.4 profile, platform requirements and health pass, sibling vhosts are healthy, and only then clear the gate. Do not automatically roll back or restore database state; if the release applied migrations or changed data, keep the gate and obtain a separate database recovery decision.
6. **Integration & QA acceptance:** accept only after reviewing sanitized pre/post receipts, the exact SHA and CI, package-signature evidence, backup integrity/recoverability evidence, selected PHP versions for FPM/CLI/workers/scheduler, smoke/health results, and rollback readiness. Owner cutover approval remains a separate decision.

