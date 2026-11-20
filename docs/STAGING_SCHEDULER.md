# Staging-next scheduler: audit, allowlist and activation

The Laravel scheduler of staging-next may only do what the isolated staging stack allows. `App\Release\StagingSchedulePolicy` is one central, **fail-closed** allowlist attached to every scheduled event in `routes/console.php`. Outside staging-next (production, legacy staging, tests) it does nothing: the schedule is exactly what it was.

This document does not authorise any host operation. Starting the timer needs the Owner's root step at the end.

## Rules on staging-next

A task runs only if all of these hold:

1. It is classified in `StagingSchedulePolicy::TASKS` (an unclassified or unreadable task never runs; a test fails until a new task is classified).
2. The environment is proven: `StagingActiveProfile::identityProblems()` is empty (profile, env, URL, debug, enforced mode, mysql `wpcg_staging_next` on a loopback host, live Stripe mode off) **and** the live `select database()` is `wpcg_staging_next`. If the profile is missing or any identity check fails, everything is held except the heartbeat (a timestamp that proves the scheduler runs).
3. Its isolation group is open:

| Group | Opens when |
| --- | --- |
| `local` | always (after rule 2) |
| `stripe` | `sk_test_`/`rk_test_` secret, test publishable key, live mode off |
| `mail` | the mailer is a sink (`log`/`array`) or `STAGING_MAIL_ALLOWLIST` is not empty (otherwise the mail guard would block everything and an outbox would record blocked mail as delivered) |
| `connector` | `STAGING_ACTIVE_CONNECTOR_API=true` |
| `external` | `STAGING_ACTIVE_EXTERNAL_SERVICES=true` |
| `platform_updates` | `STAGING_ACTIVE_PLATFORM_UPDATES_OUTBOUND=true` (only the Platform Updates advisory check; it opens nothing else and is **not** opened by `external`) |

Extra: `backup:monitor` deletes below `backup.upload.staging_path`; it is held unless that path is inside this application's `storage/`. On staging-next every `withoutOverlapping()` mutex expires after 15 minutes instead of 24 hours, so a run killed by the one-minute `TimeoutStartSec=55` cannot starve its task for a day.

`system:check` (`staging_isolation` → "Scheduler tasks") lists the held tasks with a reason code: `environment_unproven`, `task_not_allowlisted`, `stripe_closed`, `mail_closed`, `connector_closed`, `external_closed`, `platform_updates_outbound_closed`, `backup_staging_path_outside_storage`. Names and codes only.

## Audit of the 21 scheduled tasks

| # | Command | Group | Runs by default on staging-next | Possible external side effect | Existing safeguard | Fix in this change | Test evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | `connector:update-presence` | local | yes | none (marks websites disconnected in the DB) | only websites with an active connector | classification | policy matrix, route inventory |
| 2 | `backup:schedule` | connector | **no** | queues connector backup jobs for paired sites; storage target if configured | needs an active connector, entitlement, capability; connector API closed by default | held until connector opt-in | group test |
| 3 | `backup:monitor` | local | yes | deletes the backup staging directory of failed backups; re-dispatches `TransferBackup` (upload to the configured backup storage) for backups stuck uploaded/verifying, which only exist once a connector has produced backups; staging has no `BACKUP_STORAGE` configured | path from `BACKUP_STAGING_PATH` | held if the path is outside `storage/` | path test |
| 4 | `backup:retention` | local | yes | none (report only, deletes nothing) | explicit "no backups were deleted" | classification | matrix |
| 5 | `privacy:exports-expire` | local | yes | none (deletes expired temporary exports on the `local` disk only) | refuses any other disk | classification | matrix |
| 6 | `platform:database-backup-schedule` | external | **no** | platform database backup to object storage | disabled unless Owner-enabled; own encryption/verification | held until external opt-in | group test |
| 7 | `admin-access:expire` | local | yes | none | DB only | classification | matrix |
| 8 | `security:monitor` | local | yes | none | DB only | classification | matrix |
| 9 | `security:schedule` | connector | **no** | queues scans on paired sites | `SECURITY_SCHEDULE_ENABLED` (default off), connected sites only | held until connector opt-in | group test |
| 10 | `security:vulnerabilities:sync` | external | **no** | downloads a third-party vulnerability feed | needs source, URL and `scheduled_sync_enabled` | held until external opt-in | group test |
| 11 | `monitoring:run` | local | yes | website probes contact customer sites | probes fenced on staging-next (PR #340: only the synthetic fixture, never a customer site); **the fence lifts together with `STAGING_ACTIVE_EXTERNAL_SERVICES=true`**, after which probes go to every website in the staging database | classification | PR #340 monitoring tests, matrix |
| 12 | `monitoring:deliver-alerts` | mail | only with a sink/allowlist | alert e-mail | mail guard (allowlist) | held while mail is not confined | group test |
| 13 | `system:scheduler-heartbeat` | local | yes (even when the rest is held) | none (cache timestamp) | — | exempt from rule 2 by design | unproven-environment test |
| 14 | `internal-plans:sync` | local | yes | none | DB only | classification | matrix |
| 15 | `offers:expire` | stripe | **no** until test keys | stops unpaid Stripe Checkout Sessions | Stripe gateways refuse live keys | held until Stripe is proven | group test |
| 16 | `stripe:webhooks:drain` | stripe | **no** until test keys | processes received Stripe events | signed webhooks only; live events refused | held until Stripe is proven | group test |
| 17 | `billing:emails:drain` | mail | only with a sink/allowlist | customer billing e-mail | mail guard (allowlist) | held while mail is not confined | group test |
| 18 | `stripe:subscriptions:reconcile` | stripe | **no** until test keys | Stripe API reads | gateways refuse live keys | held until Stripe is proven | group test |
| 19 | `billing:subscriptions:expire-grace` | local | yes | none (DB; queues a billing e-mail through the outbox) | outbox + mail guard | classification | matrix |
| 20 | `platform:updates:check` | platform_updates | **no** until its own opt-in | read-only advisory lookup at the fixed public OSV endpoint (ecosystem, package name and version of Composer/npm components only) | `PLATFORM_UPDATES_ENABLED` (default off); fixed host, no redirects, 5 MB / 10 s limits, one lock, report-only (installs and updates nothing) | held until `STAGING_ACTIVE_PLATFORM_UPDATES_OUTBOUND`; classification | `PlatformUpdatesBackendTest`, `PlatformUpdatesStagingGateTest`, policy matrix |
| 21 | `platform:updates:digest` | mail | only with a sink/allowlist | one daily summary e-mail to verified owners | `PLATFORM_UPDATES_ENABLED`; mail guard (sink or allowlist); one claim per recipient and day; failures logged and audited | held while mail is not confined; classification | `PlatformUpdateNotificationsTest`, policy matrix |

Tests: `tests/Feature/StagingSchedulerPolicyTest.php` (classification inventory of all 21, production/legacy/testing unchanged incl. mutex expiry, local-only default, each group opens only on its own condition, every identity deviation holds everything but the heartbeat, unknown/unreadable tasks, origin-based detection, backup path, mutex bound, `system:check` output without secrets, command-name parsing for `'` and `"` quoting).

Known limits: a blocked mail is dropped (logged), not retried; `billing:emails:drain` with an allowlist records customer mail that the guard dropped as handled. A task that is a closure other than the heartbeat is not allowlisted unless it has a classified name.

## systemd contract (already installed on the VPS, not reinstalled)

* `wpcg-staging-next-scheduler.service`: `Type=oneshot`, `User=deploy`, `WorkingDirectory=/var/www/wpcaregrid-staging-new`, `ExecStart=/usr/bin/php8.5 artisan schedule:run --no-interaction`, `TimeoutStartSec=55`, `NoNewPrivileges`, `PrivateTmp`, `ProtectHome`, `ProtectSystem=full`.
* `wpcg-staging-next-scheduler.timer`: `OnCalendar=*-*-* *:*:00`, `Persistent=false`.
* The worker `wpcg-staging-next-worker.service` is not touched.
* The legacy `/usr/local/bin/wpcaregrid-scheduler` wrapper belongs to the legacy staging and is not used.

## Deployment (after merge; needs Owner GO for the host steps, none are in the repository)

Code only: no migration, composer, npm or `.env` change. Backups first (`.env`, application files, database dump), maintenance mode, `git checkout --detach <merge-sha>`, tracked files readable by the web user, `php artisan up`, then verify `system:check --json` (`staging_isolation` not `ERROR`, "Scheduler tasks" shows the held list) and `php artisan schedule:list`. Rollback: restore the previous SHA, `php artisan up`.

## Activation (Owner, root) — only after the checks above are green

```sh
# 1. read-only: the units are as contracted. NOTE: schedule:list shows all 21 tasks (it does not evaluate the policy); the held tasks are shown by system:check ("Scheduler tasks")
systemctl cat wpcg-staging-next-scheduler.service wpcg-staging-next-scheduler.timer
systemd-analyze verify /etc/systemd/system/wpcg-staging-next-scheduler.service /etc/systemd/system/wpcg-staging-next-scheduler.timer
sudo -u deploy /usr/bin/php8.5 /var/www/wpcaregrid-staging-new/artisan schedule:list
sudo -u deploy /usr/bin/php8.5 /var/www/wpcaregrid-staging-new/artisan system:check --json | /usr/bin/python3 -c 'import json,sys; [print(c["check"],c["level"],c["details"].get("Scheduler tasks","")) for c in json.load(sys.stdin) if c["check"]=="staging_isolation"]'
# 2. start
systemctl enable --now wpcg-staging-next-scheduler.timer
# 3. verify
systemctl list-timers wpcg-staging-next-scheduler.timer --no-pager
sleep 70; journalctl -u wpcg-staging-next-scheduler.service --since "-3min" --no-pager | tail -20
sudo -u deploy /usr/bin/php8.5 /var/www/wpcaregrid-staging-new/artisan system:check --json | /usr/bin/python3 -c 'import json,sys; print([ (c["check"],c["level"]) for c in json.load(sys.stdin) if c["check"] in ("scheduler","queue")])'
# rollback (stops new runs; running tasks finish)
systemctl disable --now wpcg-staging-next-scheduler.timer
```

## Operating notes

* Do **not** use `php artisan schedule:test` on staging-next: it runs a task without evaluating the policy. `schedule:run` (also with `--name`) does evaluate it.
* `schedule:run` runs tasks one after another and the service is killed after 55 s: a hanging task also stops the tasks after it in that minute (the heartbeat is 13th). The next run retries after the 15-minute mutex expiry; no task can run twice in parallel (systemd does not start the oneshot while it is active).
* The policy recognises staging-next by the deployment id, by a requested `staging_active` profile (even when refused) or by the exact origin. Any one is enough, so a mis-identified stack is held, not released.
