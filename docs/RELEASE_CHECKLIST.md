# Release checklist

Gates before the controlled pilot and before production use. Each item is **READY** (done and verified in the repository or by tests), **NOT READY** (work or a rehearsal is still needed) or **NOT CONFIGURED** (needs production configuration or an external account). The detailed legacy rows below include historical configuration states and must be refreshed against the current exact release before use. See [PROJECT_STATUS.md](PROJECT_STATUS.md) for the current status register and evidence boundaries.

How to check most items: `php artisan system:check` (OK / WARNING / ERROR / NOT CONFIGURED, never prints secrets) and the owner System page. Deployment steps: [DEPLOYMENT.md](DEPLOYMENT.md); pilot: [PILOT.md](PILOT.md).

> **Historical snapshot notice — 2026-11-14:** The release-evidence rows below
> were prepared for proposed candidate `5e0e784c69bcd5cfdcaa3f03a4734cb62ed40bd8`.
> At the first GO-8 note, primary was `198e32d127c782bf80ff7ca837f06df82d50655a`
> after PRs #395, #396 and #385; later that day #384 was squash-merged as
> `28f9061f59c65be8958b67ea54642245a7456a57`. These are dated history, not a live
> primary pointer. The candidate identity is not silently changed here. Treat the
> rows as the `5e0e784` snapshot, not current primary, staging or production evidence.
> Check issue #174 for newer state. Production remains NO-GO.

## Historical candidate `5e0e784` evidence snapshot — 2026-11-13

This snapshot was refreshed from GitHub repository and workflow records. It contains
no fresh production or staging host observation. Treat all runtime values below as
`UNKNOWN` unless accompanied by new, exact-release evidence. The detailed sections
marked November 4 are preserved as historical decisions and observations; do not
reuse their host identities, deployed SHAs, backup claims, runtime state, or test
results as current acceptance evidence. Reviewer provider/model labels are copied
from the reports and are not independently authenticated; report hashes verify
bytes only, not reviewer identity or provenance.

| Gate | Snapshot evidence | Status / acceptance |
| --- | --- | --- |
| Protected primary at snapshot time | `claude/ecstatic-wozniak-oemyuf` at [`5e0e784c69bcd5cfdcaa3f03a4734cb62ed40bd8`](https://github.com/zsoltacsai/wpcaregrid/commit/5e0e784c69bcd5cfdcaa3f03a4734cb62ed40bd8) | Verified source baseline on 2026-11-13; proposed candidate only, not a release tag or approval. After #384, primary was `28f9061f59c65be8958b67ea54642245a7456a57` on 2026-11-14; this is a historical snapshot. See issue #174 for newer state. |
| Exact-SHA CI | [Workflow 43386058267](https://github.com/zsoltacsai/wpcaregrid/actions/runs/43386058267), event `push`, exact `head_sha=5e0e784c69bcd5cfdcaa3f03a4734cb62ed40bd8`, `completed/success`; 38/38 check runs `completed/success` | PASS for repository CI only. It does not prove staging behavior or production readiness. |
| Candidate source review | Exact-SHA reports are preserved at [`QA`](release-evidence/qa-5e0e784.md) (PASS for static route isolation; report SHA-256 `82d6b3ebef14fdde464fa173fbf5a91e11c496577647f4977ac8d3272a17dca4`) and [`AppSec`](release-evidence/appsec-5e0e784.md) (BLOCKED for production acceptance pending runtime worker/scheduler fence evidence; report SHA-256 `c58201104e2170cbc7f73db9da80f02ff596d515ce614eba8bb2e7a4357f6409`). Both reviewed `5e0e784c69bcd5cfdcaa3f03a4734cb62ed40bd8`. | HOLD. The AppSec result is not PASS. V3 ledger records are not published: policy V3 requires an exact PR number/head pair, while `5e0e784…` is the protected-primary merge baseline and is not the head of an open release PR. Do not associate the reports with a different PR/head to force a record. |
| Staging acceptance | No fresh runtime or browser evidence for this exact SHA is recorded in this snapshot | UNKNOWN / HOLD. Run the staging checklist only after its backup, restore, migration, and deployment gates are separately satisfied. |
| Database migrations | [PR #380](https://github.com/zsoltacsai/wpcaregrid/pull/380), head `eae459a79bc335a032e68d645f961963704d8eac`, has a successful synthetic MySQL 8.4.11 qualification run ([43321682559](https://github.com/zsoltacsai/wpcaregrid/actions/runs/43321682559)). Its measured result is `least_privilege_path=UNRESOLVED_SUPER_ONLY`; the app account fails trigger creation with MySQL error 1419 under binlog ON/trust OFF. | HOLD. This is not production privilege evidence or an accepted production migration procedure. No application/global privilege or MySQL setting change is authorized by this record. |
| Backup and restore | No current application/config/database backup bound to this candidate and no independently verified isolated restore receipt is recorded here. The earlier restore investigation is paused. | HOLD. A metadata inventory, CI, or synthetic migration test is not restore proof. |
| Production runtime and scope isolation | No fresh evidence that current runtime settings, payment/order paths, provisioning, workers, or customer features match the marketing-only policy | UNKNOWN / HOLD. Verify on the exact deployed SHA before any release decision. |
| Production deployment path | [`PRODUCTION_MARKETING_RELEASE_RUNBOOK.md`](PRODUCTION_MARKETING_RELEASE_RUNBOOK.md) still identifies source baseline `cf2a944c27a2a24dbdf1097bf0ba766e6edd48fe` and production helper 2.0.0; the V3 policy says that helper does not yet enforce V3. | HOLD. Reconcile the controller/helper manifest and review-evidence contract with the proposed candidate before any production authorization. Do not infer current compatibility from the historical pin. |
| GO-6.4 inventory helper | The diagnostic package is handled by the separate [GO-6.2 installation runbook](PRODUCTION_G1_INVENTORY_GO62_INSTALL.md). The helper reports restore proof as `NOT_PROVEN` and database privilege/trigger evidence as `UNKNOWN`. | Separate diagnostic workstream, not a prerequisite to application release. Its install remains HOLD until the root-console gate; helper execution needs its own exact Owner GO. It cannot replace any release gate above. |
| Production activation | No release authorization for this candidate is recorded | NO-GO until the exact candidate, independent reviews, staging acceptance, migration/restore evidence, rollback, scope isolation, monitoring, and applicable Owner authorization all pass. |

### Proposed release scope and staging acceptance

The proposed candidate is the protected-primary source SHA above. It is not approved
for production or tagged as a release. The only intended first-release scope is the
HU/EN public marketing surface. Keep customer registration and operational
authentication, checkout, live payments, provisioning, Connector onboarding, paid
SaaS operations, and unfinished customer-facing features unavailable.

Staging acceptance must be fresh and tied to the exact deployed candidate SHA. Record
the deployed SHA and non-secret environment identity, then verify application boot
and configuration without printing secret values; database compatibility and
migration status; HU/EN landing pages; navigation, assets and redirects; HTTPS and
security headers; disabled customer/payment/provisioning paths; error logging; and
browser smoke tests at desktop and mobile widths. Negative tests must not create a
customer, order, payment, service, or Connector onboarding record. A source-level
feature fence or CI result alone is not staging PASS.

### Migration, restore, and rollback qualification

The current protected controller runs migrations. Before it is used, qualify the
complete target migration set on an isolated copy restored from a verified backup.
MySQL 8.4 trigger creation remains an unresolved privilege issue: the synthetic
qualification observed only a SUPER-assisted path with binlog enabled and
`log_bin_trust_function_creators=OFF`. The app account must not receive global SUPER;
do not enable trust-function-creators to bypass the failure. Any DBA-assisted plan
must define a narrowly controlled operation, its exact grant/revoke lifecycle, the
trigger DEFINER identity and its durable least-privilege permissions, partial-DDL
recovery, and post-migration verification. This plan is not yet approved or validated
for production.

The backup gate requires a fresh, mutually associated application/configuration/
database backup bound to the exact release SHA and timestamp, integrity verification,
protected recovery of `.env`/`APP_KEY` without exposing either value, and an actual
isolated restore with application/config/key pairing and structural/data checks.
Run release migrations on a separate restored clone. Code rollback does not reverse
schema changes. Until the Owner sets acceptable maximum data loss (RPO) and recovery
duration (RTO), both remain `UNKNOWN`; no value is assumed here. Keep the HTTP gate
closed on any migration, startup, smoke-test, or recovery failure and preserve the
database state for deliberate recovery rather than running destructive down-migrations.

### GO-6.4 diagnostic workstream boundary

The GO-6.4 package is optional diagnostic tooling for gathering bounded host metadata.
The production inventory helper is not part of the Laravel application release
controller, does not inspect database privileges through authentication, and never
proves a restore. Installation is still blocked on the Owner-only Rackforest
root-console identity and read-only preflight; no AI/T3 deploy session is an
acceptable substitute. Its installation or first invocation must not delay source
review or isolated release planning, and its results cannot be substituted for the
backup, restore, migration, staging, or exact-release gates.

## Historical owner release-sequence decisions — 2026-11-04

The policy decisions below remain relevant where consistent with the current
snapshot. All host/runtime/backup/staging observations and exact-source references
in the November 4 tables are historical only and must be refreshed before use.

### Latest decision: marketing-only MVP and backup/restore reprioritization

The first release is the full Laravel application operating in marketing-only mode through the existing protected controller. No separate static marketing site, alternate system or application architecture is authorized. The controller runs migrations; do not waive the fresh backup, demonstrated recovery and migration rehearsal gates.

- First release scope is HU/EN public marketing only. Registration, checkout, live payments, provisioning, Connector onboarding, and paid SaaS operations remain disabled. Production activation requires separate exact-release authorization.
- Move these to post-production: daily database backups to the existing S3-compatible store; encryption, retention and failure monitoring; secure internal database administration; restore tooling and periodic isolated restore verification; and full customer-facing M15 Backup & Restore. Hosting-provider weekly backups are Owner-reported only, not verified application/database restore evidence.
- Server-side PHP 8.4+ only. Keep PHP 8.4/8.5 CI; PHP 8.5 cutover is not authorized. Keep the WordPress Connector PHP matrix unchanged.
- Do not install a database admin UI in this release. For later operator access, prefer DBeaver Community over the approved SSH tunnel with verified host identity and least-privilege DB credentials. A future phpMyAdmin deployment requires separate security review and must be inaccessible from the public internet.

### Database-change split for the release gate

| Release path | Repository support and database effect | Required gate |
| --- | --- | --- |
| Current protected Laravel staging controller | **Not database-free.** `scripts/deploy-staging.sh` unconditionally inspects migration status and runs `php artisan migrate --force --no-interaction`; the `marketing_only` profile fences workers/scheduler but does not skip migrations. A pending migration may change live schema/data. On migration failure the controller keeps the HTTP gate closed and avoids automatic code rollback when migrations may have changed the database. | Fresh verified app/config/DB backup, protected `.env`/`APP_KEY` recovery, demonstrated restore/recovery, target migrations rehearsed on a restored disposable clone, exact-SHA rollback plan, PHP 8.4/runtime/Nginx checks, persistent background fences, then exact-SHA staging acceptance. Without these, `STAGING_DEPLOY=NO-GO`. |
**Current release-path conclusion:** use the full Laravel application through the existing controller. It unconditionally runs migrations, so the release can change database state. The target range adds seven migration files relative to staging: `2026_10_25_200000_add_locale_snapshot_to_checkout_intents`, `2026_10_26_000001_create_backup_download_grants_table`, `2026_10_30_000001_create_deployment_transition_fences`, `2026_10_30_000002_create_transition_bridge_requests_table`, `2026_11_02_000001_add_phase2b_fence_tuple_columns`, `2026_11_02_000002_create_transition_v2_requests_table`, and `2026_11_02_000003_create_transition_history_events_table`. The read-only migration status on the deployed older source cannot enumerate migration files that are only present in the target checkout; the controller checks the target before migrating. The weekly provider backup remains Owner-reported and is not proof of a successful application/database restore.

The controller's pre-deploy backup operation creates a protected database dump and validates gzip integrity, then pauses the scheduler and fences workers before code checkout/build and migrations. It does not test restoring that dump or verify a restored application/configuration pair. The controller also has no isolated-restore proof input. Keep the HTTP gate and deployment invocation out of use until an independently verified recovery path exists. Migration failure after an attempt leaves the public gate closed; automatic code rollback is intentionally avoided where database state may have changed.

**External boundary:** isolated restore provisioning and staging-to-WSL transfer investigation are paused. Do not retry or substitute another transport. No further external correspondence, provider ticket or message is authorized by this decision.

The first production target is the **HU/EN marketing-only MVP**. Customer registration, Stripe Checkout/webhook provisioning, subscription activation, Connector onboarding, paid services and live payments are out of scope and must remain unavailable. Paid service activation follows separate service, financial and operational readiness gates. Full M15 customer-facing Backup & Restore is a post-production milestone. Infrastructure backup/recovery requirements depend on whether the selected supported release path can change database state, as specified in the current decision above.

### Initial release gates for the enabled scope — historical snapshot as of 2026-11-04

| Gate | State recorded 2026-11-04 | Release acceptance |
| --- | --- | --- |
| Exact source and CI | Primary observed at that time `06d35a28453445ed19bdc11e62592e187e17dbf8`; merge CI `41817511959` — 38/38 PASS. | Recheck target SHA and mandatory jobs immediately before release. CI does not establish staging behavior. |
| Marketing-only application QA | Marketing E2E `41748568634` — PASS on application SHA `7355788882e026db7c4a6d37db2b957740b5f385`; later primary changes were documentation-only. | Revalidate HU/EN routes, informational pricing, navigation, CTA/contact, responsive layouts, SEO and negative access checks for registration, checkout, payment, service provisioning and SaaS/background actions on the deployed exact SHA. |
| Server PHP policy | PHP 8.4 is the staging/rollback baseline. Server CI covers PHP 8.4 and 8.5. | Keep staging baseline on 8.4. Complete 8.5 compatibility validation in CI or an approved isolated environment; this is not a PHP 8.5 cutover authorization. Connector PHP matrix remains separate and unchanged. |
| Pre-deployment backup | Fresh, mutually associated application/configuration/database backup is not currently evidenced. The controller creates only the DB dump; no application/config snapshot or restore proof is produced by that step. | Capture immediately before a state-changing deployment; verify identity, completeness and hashes; protect `.env`/`APP_KEY` through approved secret recovery without exposing values. |
| Actual recovery proof | Required for the current controller path because it runs migrations; restore work is paused by Owner decision. Synthetic MySQL migration passes are not actual backup restore evidence. | Before using the current controller, restore the fresh backup into an isolated disposable MySQL 8.4.11 environment, verify application/config/key pairing, run release migrations on a restored clone, and verify cleanup. |
| Rollback/recovery | Exact-SHA controller and PHP 8.4 rollback baseline exist; an actual current backup restore remains unproven. | Demonstrate code/config rollback and database recovery path without automatic destructive migration rollback. Keep the public gate closed on migration failure. |
| Runtime and background fencing | PHP 8.4 preflight, Nginx check and scheduler-lock evidence PASS; worker phase classification is accepted. | Recheck the current host before release. For marketing-only scope, workers and scheduler must remain persistently fenced and pending jobs retained; strict worker RUNNING contract applies after recovery starts workers. |
| Production readiness | Must be evaluated against the explicitly enabled production scope. | Complete applicable security, legal/accounting, monitoring, access/secrets and release-authorization gates. Deferred M15 product gates are not silently enabled or claimed complete. |
| Scope isolation | Owner has approved marketing-only first release; no actual production/staging sync yet. | Prove public marketing routes work and registration, checkout, Stripe webhook provisioning, Connector onboarding, customer service and SaaS background activity remain blocked. Do not accept a source-only claim instead of exact staging/browser checks. |

**Decision recorded 2026-11-04:** restore provisioning and transfer investigation were paused; no alternative transfer is authorized. `STAGING_DEPLOY=NO-GO` for the current controller path until verified recovery proof and all other release gates pass. The Owner-reported weekly provider backup is not restore proof. Do not contact the provider or attempt another transport.

### MVP scope and staging sync — historical snapshot as of 2026-11-04

| Item | State recorded 2026-11-04 | Acceptance / limitation |
|---|---|---|
| Authoritative primary at that time | `06d35a28453445ed19bdc11e62592e187e17dbf8`; exact merge CI `41817511959` passed 38/38. | Recheck exact current target and all mandatory jobs immediately before staging or production release. |
| Staging application SHA at that time | `761e0e5d37558da808ac961ca2d2a68b4ffab0db`, rechecked through `caregrid-staging` on 2026-11-04 11:27 UTC; worktree clean; PHP CLI 8.4.27. | Primary was 546 commits ahead. The target includes application, billing, Connector, backup, migrations, deployment/runtime, localization and marketing changes. Use the full protected exact-SHA controller release; do not cherry-pick marketing files. |
| Exact-source acceptance | Merge CI `41817511959` = 38/38 PASS; Marketing E2E `41748568634` = PASS on application SHA `7355788882e026db7c4a6d37db2b957740b5f385`, with later primary changes documentation-only. | Repository evidence only. It does not establish deployed staging behavior; actual browser QA follows a successful exact-SHA deploy. |
| Staging deploy | **NO-GO** | The full Laravel controller runs migrations. Do not deploy until fresh backup, isolated restore/recovery proof, target migration rehearsal, current runtime/fencing/controller checks and post-deploy acceptance are evidenced. No alternate architecture is authorized. |
| PHP policy | PHP 8.4 is staging/rollback baseline; PHP 8.5 is planned compatibility validation only. | Keep server CI on 8.4/8.5. No PHP 8.5 cutover; Connector matrix remains unchanged. |
| Paid plan candidate | `wordpress.basic`, 8,990 HUF/month, is a later candidate, not part of this MVP and not approved for sale. | It includes plugin/theme/core updates, remote backup, uptime monitoring, monthly security checks and activity log; catalogue marks it restore-eligible. Verify capability, operator coverage, entitlement, financial readiness and recovery before later activation. HU/EN labels are Basic/Business/Secure; 39,900 HUF is `woocommerce.secure`. |
| Registration and payment | Disabled for marketing-only MVP; real payment is prohibited. | Verify account creation, Checkout, webhook provisioning and service activation are inaccessible. Sandbox customer-journey verification belongs to the later paid-service gate; live mode always needs separate explicit Owner GO. |
| Deferred product scope | Full customer-facing M15 Backup & Restore is post-production; daily S3-compatible backups, encryption/retention/monitoring, secure internal DB administration, restore tooling and periodic isolated restore verification are also post-production roadmap items. | Keep customer Backup & Restore capabilities unavailable until M15 acceptance. Reconcile catalogue entitlements before any package is sold. The current controller's database-changing release still requires the recovery gate. |

### MVP customer acceptance after staging synchronization

The deployed exact SHA must pass actual staging browser tests for the HU/EN homepage, informational pricing, navigation/language/currency, mobile/desktop layout, CTAs/contact, SEO and console/network errors. Negative checks must prove registration, account creation, checkout, payment, webhook provisioning, Connector onboarding, customer service and SaaS/background actions stay inaccessible. Do not create a customer/site or submit a payment in this phase. The paid-service journey (sandbox success/decline, signed webhook/replay, subscription consistency, activation, onboarding, service delivery and operator support) is a later acceptance gate after the package and operational conditions pass. Source tests and CI do not substitute for staging acceptance.

Production remains a separate decision after the enabled package and operational scope are supportable and the applicable legal/accounting, security, access/secrets, monitoring, incident-response, recovery and exact-release gates pass. No production or live-payment operation is authorized by this checklist.

### Minimum infrastructure backup and recovery requirements

These remain mandatory before any state-changing staging or production release, independently of M15 product completion:

1. Fresh verified application, configuration and database backup bound to the exact source SHA and timestamp.
2. `.env` and original `APP_KEY` preserved using the approved secret-management/recovery route; never print, commit or attach secret values.
3. Actual restore proof in a disposable isolated MySQL 8.4.11 environment, with networking disabled or otherwise proven inaccessible to shared/live services.
4. Structural/data-integrity checks and target release migrations tested on a separate restored clone; no `migrate:rollback` used as restore proof.
5. Exact application/config rollback plan and database recovery decision, with the HTTP gate kept closed if migration or health checks fail.
6. Verified cleanup of only the disposable restore artifacts and environment.

## Application

| Item | State | Check |
| --- | --- | --- |
| PHP 8.4 with required extensions | READY | CI runs PHP 8.4 on Ubuntu 24.04; Ubuntu ships 8.3, so the server needs `ppa:ondrej/php`; exact extension list in DEPLOYMENT.md; server: `composer check-platform-reqs --no-dev` |
| PHP 8.5 backend compatibility | READY IN SERVER CI / STAGING CUTOVER NO-GO | Preserve Laravel CI coverage on PHP 8.4 and 8.5. Complete any required isolated compatibility validation before release; PHP 8.5 staging/production cutover needs separate approval. Connector matrix is separate and unchanged. |
| English and Hungarian for the public site, customer login and portal; HU → HUF; no fake international prices | READY | LOCALIZATION.md; CustomerLocalizationTest, HungarianContentTest, Playwright localization spec |
| Customer emails in Hungarian | NOT READY | English only; needs the language stored at checkout (LOCALIZATION.md) |
| Fresh install without dev packages | READY | `composer install --no-dev`, build, migrate, `connector:package`, all caches, rollback and re-migrate verified on an exported copy of the repository (2026-10-04) |
| Staging server `stg1.wpcaregrid.com` | READY — M14 staging deployment reported | Protected deployment of M14 SHA `b152fcfc53543938c27d820d966ca99bd56e22d6` is Owner-reported under receipt `release-20261024T165817.155049Z-b152fcfc53543938c27d820d966ca99bd56e22d6.json`; later dependency release deployment through current HEAD `761e0e5d37558da808ac961ca2d2a68b4ffab0db` is also Owner-reported, separate receipt not archived. Not production readiness. |
| Rollback procedure | READY (documented) | DEPLOYMENT.md "Rollback"; to be rehearsed once on staging |
| `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` set | NOT CONFIGURED | `system:check` Application = OK (ERROR if debug in production) |
| `APP_URL` is the public `https://` domain | NOT CONFIGURED | `system:check` Application (ERROR if http or local in production) |
| HTTPS redirect, HSTS, secure cookies | READY | defaults when `APP_URL` is https (tests: ProductionHardeningTest) |
| Security headers on every response | READY | `curl -I https://<domain>/`; HTTP smoke test |
| Content-Security-Policy | NOT READY | set `HTTP_CSP=report-only` on staging, check the console, then `enforce` |
| Trusted proxies (if behind a load balancer) | NOT CONFIGURED | `TRUSTED_PROXIES` |
| Production caches build | READY | CI runs config/route/view/event cache; deploy runs them |
| Frontend build | READY | CI `npm run build`; deploy runs it |

## Database

| Item | State | Check |
| --- | --- | --- |
| Migrations run from an empty database, roll back fully, run again | READY | Full Laravel CI on MySQL 8.4 for PHP 8.4 and 8.5; targeted SQLite regressions separately |
| Indexes and foreign keys | READY | verified on MySQL 8.4 (every table has a primary key; foreign keys on all relations) |
| Server-side suite on MySQL | READY | CI server job (mysql); found and fixed: reason codes longer than their columns, JSON key order in tests |
| No seed or demo data in production | READY | `migrate --force` only; no seeders run |
| Production MySQL database and user | NOT CONFIGURED | MySQL 8.4 LTS from the MySQL APT repository (Ubuntu 24.04 ships 8.0); `system:check` Database = OK, no pending migrations |
| Backup of WP CareGrid's own database | NOT CONFIGURED | nightly `mysqldump` to separate storage (DEPLOYMENT.md) |

## Queue

| Item | State | Check |
| --- | --- | --- |
| Two workers (`default`, `backups`) under a supervisor | NOT CONFIGURED | supervisor config in DEPLOYMENT.md; `supervisorctl status` |
| Backup transfers cannot run twice | READY | own connection with retry_after > timeout, unique job; `system:check` Queue reports ERROR otherwise |
| Abandoned transfers recover | READY | `backup:monitor` hands them over (test) |
| Failed jobs visible | READY | System page / `system:check`; `php artisan queue:failed` |
| Workers restarted on deploy | NOT CONFIGURED | `php artisan queue:restart` in the deploy procedure |

## Scheduler

| Item | State | Check |
| --- | --- | --- |
| One cron entry `schedule:run` every minute | NOT CONFIGURED | `system:check` Scheduler = OK (heartbeat younger than 5 minutes) |
| `internal-plans:sync` and `offers:expire` registered with the scheduler (hourly) | NOT CONFIGURED | `php artisan schedule:list` lists both (checked locally; to be repeated on staging) |
| `internal-plans:sync` and `offers:expire` execute successfully on staging | NOT CONFIGURED | run both by hand (exit 0), then `php artisan schedule:run` in the first minute of an hour; DEPLOYMENT.md "Staging checklist" item 12 |
| No duplicate task runs | READY | every task `withoutOverlapping()` + `onOneServer()` |

## Mail

| Item | State | Check |
| --- | --- | --- |
| Real mailer and sender address | NOT CONFIGURED | `system:check` Mail = OK (log driver = NOT CONFIGURED) |
| Mail queued, links from `APP_URL` | READY | invitation link (pilot E2E) and activation link (Stripe E2E through an https tunnel) used `APP_URL` |
| Delivery tested (invitation, password reset, activation) | NOT READY | activation, payment and subscription emails verified through SMTP into Mailpit (test catcher, 2026-10-04); a real provider on staging is still open (PILOT.md) |

## Stripe

| Item | State | Check |
| --- | --- | --- |
| Test-mode keys (`STRIPE_KEY`, `STRIPE_SECRET`) | READY (local) | test sandbox keys in the local `.env`; staging needs its own `.env` (STRIPE_TEST_MODE.md) |
| 2 products and 48 recurring TEST prices configured (HUF/EUR/USD/GBP) | PENDING | `php artisan pricing:verify-stripe` must validate all 48 prices before checkout acceptance |
| Webhook endpoint + signing secret, 9 events | READY (local) | registered for the temporary tunnel and verified; staging needs its own endpoint for its `APP_URL` |
| Tax setting for Hungary (final price, no VAT added) | READY | Stripe Tax off, no tax rates, `amount_tax` 0 on every session (STRIPE_TEST_MODE.md) |
| Promotion codes (local rules + Stripe discount) | READY | real checkout with `PILOT10`: 8 990 Ft → 8 091 Ft charged; unknown and not-applicable codes refused locally |
| Webhook signature, idempotency, transaction, retries | READY | tests (SubscriptionLifecycleTest, CheckoutTest) |
| Browser success page never activates service | READY | tests |
| Test purchase end to end | READY (local) | Playwright against real Stripe Checkout through an https tunnel (STRIPE_TEST_MODE.md); repeat on staging (PILOT.md step 4) |
| Failed payment, cancellation, webhook replay, delayed webhook | READY | real test-mode runs 2026-10-04 (PROJECT_STATUS.md) |
| Live mode | NOT READY | separate decision after the test-mode pilot (`STRIPE_ALLOW_LIVE_MODE`) |

## Backup product and infrastructure recovery

| Item | State | Check |
| --- | --- | --- |
| Fresh pre-deployment application/configuration/database backup | BLOCKED — NOT CURRENTLY EVIDENCED | Required immediately before a state-changing release; bind the artifacts to exact source SHA/time and preserve secret/key material through approved recovery. |
| Actual isolated restore of the release backup | PAUSED BY OWNER; NOT EVIDENCED | Required to use the current migration-running controller path. Restore provisioning and transfer investigation are paused; no alternate transport is authorized. |
| Restore structure/data integrity and release migrations | NOT VERIFIED ON A RESTORED COPY | Before using the current controller, run the restore on an approved isolated environment and target migrations on a separate clone; synthetic empty-database migration PASS is not a substitute. No automatic migration rollback. |
| Exact application/config rollback and gate recovery | DOCUMENTED; ACTUAL DATABASE RESTORE UNPROVEN | Keep the public HTTP gate closed on migration/health failure; use reviewed recovery steps, not `down()` migrations as restore proof. |
| Customer-facing backup/restore product | DEFERRED — FIRST POST-PRODUCTION M15 | Keep customer backup/restore, retention and restore-provider workflows unavailable/disabled until M15 completion and their own security/release gates. |
| Daily S3-compatible DB backup, encryption, retention and failure monitoring | POST-PRODUCTION INFRASTRUCTURE BACKLOG | Implement under a separate operations/security acceptance gate; provider-reported weekly server backups are not evidence for these features. |
| Secure internal DB administration | POST-PRODUCTION INFRASTRUCTURE BACKLOG | Evaluate DBeaver Community via approved SSH tunnel first; any browser-based phpMyAdmin must be private-only and separately approved. Do not install an admin UI for this release. |
| Restore tooling and periodic isolated restore verification | POST-PRODUCTION INFRASTRUCTURE BACKLOG | Separate from the release-specific recovery evidence required when the selected deployment can mutate the database. |
| Storage provider configuration and real-provider E2E | POST-PRODUCTION M15, unless required by the explicitly enabled release scope | Do not make this a blanket prerequisite while customer Backup & Restore is deferred. If a release enables a provider path, verify behavior and credentials through approved controls. |
| S3 and SFTP integration contracts | READY IN TESTS | CI uses synthetic/controlled providers (moto, pinned-host-key OpenSSH); this is not evidence of a production provider configuration. |
| Storage secrets never leave the server or reach logs | READY IN TESTS | StorageProviderTest and CredentialExposureTest; retain this security invariant. |
| Retention per customer plan | POST-PRODUCTION M15 | Commercial/legal decision and deletion controls remain required before enabling customer retention or destructive expiry. |
| Backup and verification end to end | READY LOCALLY / PRODUCTION STATE UNKNOWN | Local evidence does not prove production storage configuration or current backup recoverability. |

## Connector

| Item | State | Check |
| --- | --- | --- |
| Connector 0.4.2 package built and verified | READY | `php artisan connector:package`; CI checks release metadata, runtime manifest, and controlled runner membership |
| Tested in real WordPress (SQLite and MySQL) | READY | CI wordpress job; locally 169 tests on both |
| Symlink containment, path traversal, archive extraction | READY | strict mode in CI: never skipped |
| HTTPS-only connector API in production | READY | `CONNECTOR_REQUIRE_HTTPS` defaults on in production (also when left empty) |
| Pairing over HTTPS on a real host | NOT READY | PILOT.md gates |

## Security

| Item | State | Check |
| --- | --- | --- |
| `SECURITY_REMEDIATION_ENABLED=false` | READY | default; `system:check` Safety switches |
| Vulnerability feed decision | NOT CONFIGURED | licensed feed configured, or customers told it is not included |
| Rule set reviewed on real sites | NOT READY | pilot scans, medium/high findings reviewed (SECURITY_INCIDENTS.md checklist) |
| Quarantine outside the web root on pilot sites | NOT READY | `WP_CAREGRID_QUARANTINE_DIR` in each pilot site's wp-config.php |
| Quarantine write, undo, permissions, deny rules | READY | WordPress tests (Linux CI checks permissions) |
| Who may hold `security.remediate` | NOT READY | operator procedure written |
| Secret scanning | READY | gitleaks in CI over the full history: clean (CI run #2); the allowlist holds only named dummy test values |
| Dependency advisories | READY | `composer audit` (app and connector tests) and `npm audit` clean |
| Role isolation (customer / operator / owner) | READY | AuthorizationMatrixTest covers every route of every area |

## Admin Access

| Item | State | Check |
| --- | --- | --- |
| Maintenance access works and is restricted | READY (local) | local E2E: users, plugin install, editor, settings refused |
| Revocation ends the WordPress session | READY (local) | local E2E |
| `ADMIN_ACCESS_OPERATOR_FULL_ADMIN=false` | READY | default; `system:check` Safety switches |
| Tested on a real host over HTTPS | NOT READY | PILOT.md |

## Commercial operations (coupons, PROBONO, recovery offers)

| Item | State | Check |
| --- | --- | --- |
| Owner coupons: percentage 0 < p <= 100, one engine, 100% supported | READY | COUPONS.md; CouponManagementTest, ZeroChargeCheckoutTest |
| 100% coupon against real Stripe test mode (browser) | READY | docs/STRIPE_TEST_MODE.md (2026-10-05) |
| PROBONO internal, not public, no backups | READY | PROBONO.md; ProbonoPlanTest |
| Recovery offers: one-time payment, webhook-authoritative | READY | RECOVERY_OFFERS.md; RecoveryOffersTest, real Stripe test mode |
| Hourly `internal-plans:sync` and `offers:expire` registered in the scheduler | READY | routes/console.php; `php artisan schedule:list`. Running on staging: see "Scheduler" above |
| Invoices for offers and coupons' sales | NOT READY | invoice provider milestone (CLAUDE.md section 23) |

## Owner Admin

| Item | State | Check |
| --- | --- | --- |
| First owner created by invitation | NOT CONFIGURED | `php artisan owner:user` |
| Drafts never public, preview signed and expiring | READY | tests |
| Published changes invalidate caches | READY | tests |
| Unsafe URLs refused, output escaped | READY | tests |
| Hungarian content written | NOT READY | an editorial task; English is the fallback per section |

## Monitoring

| Item | State | Check |
| --- | --- | --- |
| Uptime monitor on `/up` | NOT CONFIGURED | external monitor |
| `system:check --json` monitored | NOT CONFIGURED | cron or monitoring agent; alert on exit code 1 |
| Failed jobs, scheduler heartbeat, connectors offline, failed backups watched | NOT CONFIGURED | owner dashboard / System page |

## Logging

| Item | State | Check |
| --- | --- | --- |
| `LOG_LEVEL=info`, daily rotation | NOT CONFIGURED | `.env`, logrotate or log shipping |
| Important events logged, no secrets | READY | tests check secrets never reach logs |

## CI

| Item | State | Check |
| --- | --- | --- |
| Workflow: quality, Laravel PHP 8.4/8.5 on MySQL 8.4, targeted SQLite regressions, Connector matrix | READY | CI workflow; Connector MariaDB coverage unchanged |
| First green run on GitHub | READY | run #2 (commit `3f01f5d`, 2026-10-04): all 5 jobs green; server 593 tests × 2 with real S3 (moto) and SFTP (OpenSSH), 0 skips; connector 169 tests × 2; gitleaks clean over full history; audits clean. Run #1 failed on CI configuration only (moto path, two test fixtures not allowlisted) |
| Required status checks on protected primary | READY | Ruleset 25300175 requires Quality, Laravel MySQL 8.4/PHP 8.4+8.5, SQLite regression, and three Connector PHP 8.4 contexts |
