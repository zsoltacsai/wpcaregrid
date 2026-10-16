# M14.2 Connector Security Closure Report

**Disposition:** `A — M14.2 CONNECTOR & FILESYSTEM SECURITY CLOSED, M14.3 CAN BEGIN`
**Review date:** 2026-10-16
**Planning PR:** #89, merged into primary baseline `bec597c76e668a81c699171c684c0e5e8866d14a`
**Implementation PR:** [#90](https://github.com/zsoltacsai/wpcaregrid/pull/90), candidate `2b31a9945d5c5ebbef615dc6345558f27c63f855`, exact-head run [38961580730](https://github.com/zsoltacsai/wpcaregrid/actions/runs/38961580730), all five mandatory jobs successful
**Implementation merge / executable staging SHA:** `c5e0edabd8bd1661f72eb20330c5ca2f4375b9c5`
**Authoritative primary at closure evidence capture:** `c5e0edabd8bd1661f72eb20330c5ca2f4375b9c5`

## Scope and disposition

M14.2 hardened Connector credential/payload boundaries, outbound HTTP handling, archive/resource limits, restore staging, and Stripe webhook ingress. No schema migration was added. The WordPress Connector release is `0.4.6`; the API remains v1. No real provider deletion, live Stripe mutation, production deployment, or M15 operation occurred.

The implementation and required repository CI are complete. A bounded staging synthetic Laravel runtime used the exact merged source and passed its Connector, pairing, job, backup, admin-access, webhook, and package-artifact suites. The separately deployed synthetic M8 WordPress fixture still reports Connector `0.4.5`. It was not re-paired/upgraded: no safe, reversible WordPress administration credential/path was available, and replacing its live token without a guaranteed restoration path would create avoidable risk. The reviewed `0.4.6` package was instead exercised against real WordPress in both required GitHub Connector jobs. Record this as the staged paired-site validation residual; do not describe the deployed fixture as running `0.4.6`.

## Implementation and regression evidence

PR #90 merged normally as `c5e0edabd8bd1661f72eb20330c5ca2f4375b9c5`. Exact candidate CI run `38961580730` passed:

| Required job | Result and counts |
|---|---|
| Quality / security / build | PASS; Pint, Composer validation/audit, frontend build, npm audit, full-history secret scan |
| Server SQLite | 1,323 tests / 19,499 assertions / 19 skips |
| Server MySQL | 1,323 tests / 19,697 assertions / 0 reported skips |
| Required MySQL process/concurrency | 15 tests / 161 assertions / PASS |
| Connector SQLite, real WordPress | 209 tests / 1,446 assertions / 24 documented SQLite/environment skips |
| Connector MySQL 8.4, real WordPress | 209 tests / 1,820 assertions / 3 documented skips (SQLite-only path and two opt-in 900-second worker-kill tests) |

Additional local evidence: focused Laravel Connector/backup/webhook suite 136 tests / 847 assertions; Connector SQLite and MySQL full suites as above; Pint passed all 950 repository PHP files after formatting; modified PHP syntax checks, Composer validation and audits, nine npm contract tests, npm audit (zero findings), Vite build, and `git diff --check` passed. The first full local Server MySQL run failed only in the M9 disposable backup verifier due to its local verification-password setup. The isolated loopback-only MySQL 8.4 M9 test then passed (1 test / 5 assertions) with the required verifier setting. The exact-head Server MySQL and process/concurrency CI jobs passed; the initial local setup failure is retained here rather than represented as a clean first run.

## Isolated staging runtime and synthetic validation

Strict SSH host-key verification matched the approved Ed25519 fingerprint `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`. The private test runtime `/home/deploy/wpcaregrid-m142-e2e-20261016_01` was outside the served application tree, owned by `deploy` with mode `0700`, and checked out at exact merged source SHA `c5e0edabd8bd1661f72eb20330c5ca2f4375b9c5`. Its isolated `APP_ENV=testing`, `APP_DEBUG=false`, MySQL database, sync queue, and array mail configuration were verified. It had no public route, Supervisor program, or scheduled task. Stripe keys were absent and provider destructive operations were not configured.

The disposable schema was `wpcg_m142_e2e_20261016_01`; its least-scope test DB user was private to the runtime and was not printed. The source DB and all synthetic fixtures were test-only. No ordinary staging customer was mutated. The test journal/provider paths were isolated; no staging tombstone namespace or real provider endpoint was used.

The first focused batch (58 tests / 328 assertions) passed after correcting a test-runtime setup issue: the private runtime initially lacked the same-SHA Vite manifest. Exact-SHA build artifacts were copied into the private runtime, only its disposable DB was reset, and the same batch then passed. The second batch passed (40 tests / 480 assertions). Combined staging-private synthetic Laravel validation: **98 tests / 808 assertions, 0 failures**. These were application/service tests in an isolated staging-host runtime, not a live re-pairing of the connected WordPress fixture.

## Deployment, health, gates, and cleanup

The staging deployment script installed exact merged SHA `c5e0edabd8bd1661f72eb20330c5ca2f4375b9c5` with the configured deployment lock, pre-deploy backup, rollback protections, worker restart, and HTTP smoke checks. Pre-deploy backup retained at `/home/deploy/private-backups/wpcaregrid-pre-deploy-20261016T203737Z.sql.gz`. The deployed checkout was clean before testing and remains clean after cleanup.

Post-test and post-cleanup health checks showed application, MySQL/migrations, queue (zero failed jobs), scheduler, Connector API, Stripe TEST/config/catalogue, Cloudflare read access, backup storage, mail, privacy retention, privacy exports, tombstone journal, and recovery readiness healthy. Both Supervisor workers were RUNNING and cron was active. The warning `No completed security scan yet` remains visible with scanner remediation Off; AS-16 remains `M14.7_CARRY_FORWARD`.

Before and after synthetic validation, all six privacy/destructive gates read false:

- `M12_DESTRUCTIVE_RETENTION_ENABLED=false`
- profile anonymization=false
- customer backup deletion=false
- provider deletion=false
- bulk destructive execution=false
- normal-staging synthetic execution=false

Cleanup removed the exact temporary runtime, isolated database, and its dedicated MySQL user. A subsequent readback confirmed the runtime path absent, schema query empty, user query empty, no temporary Supervisor/cron entry or public route, and the live staging source unchanged and clean. No temporary queue worker or external journal object was created.

## Findings and carry-forward

No new Critical or High M14.2 finding was observed in the implementation suites, CI, isolated synthetic staging tests, or health readback. The Connector 0.4.6 package is validated in real WordPress CI on SQLite and MySQL; the staging M8 synthetic paired site remains on 0.4.5 pending a safe, separately authorized pairing/upgrade procedure. No claim of 0.4.6 live staging pairing is made.

- `AS-16`: `M14.7_CARRY_FORWARD`; scanner warning is preserved, not suppressed.
- Hosting compatibility/preflight and unsupported layouts: `M14.3_CARRY_FORWARD`.
- Independent full AppSec/penetration testing and future production-readiness audits remain in their established M14.7/M17 scope.
- All M12 destructive/privacy execution gates remain off. No production destructive behavior was enabled.

The required engineering validation is complete: the exact merged server source was exercised in the isolated staging test runtime, and the bumped Connector package was exercised against real disposable WordPress in both database modes in exact-head CI. The existing staging paired-site version limitation is transparently recorded and does not represent an unresolved provider fact or prevent closure; no unsafe token replacement was attempted. M14.3, M15, and production work remain outside this report's authorization.
