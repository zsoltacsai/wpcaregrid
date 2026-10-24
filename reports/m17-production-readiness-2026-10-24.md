# M17 Production Readiness Evidence and Control Plan

**Evidence cut-off:** 2026-10-25  
**Workstream:** Thread 3 / M17  
**Worktree:** codex/m17-production-readiness  
**Authoritative primary:** b4677dd47af87f1d9627fb9693c9e3b0ed598e4a  
**Scope:** production-readiness preparation. No production access/config/deployment, credential operation, customer-data mutation, payment or recovery operation was performed.

Unknown or stale is not PASS. M14 remains closed within its accepted scope; this report does not reopen it or claim production readiness.

## Release baseline and PHP evidence

- Primary claude/ecstatic-wozniak-oemyuf was fetched from GitHub and verified at b4677dd47af87f1d9627fb9693c9e3b0ed598e4a (PR #176 merge).
- PR #175 adds Laravel/server PHP 8.5 CI while preserving PHP 8.4. Exact merge run [40133739348](https://github.com/zsoltacsai/wpcaregrid/actions/runs/40133739348): 40/40 passed, including PHP 8.4 and 8.5 MySQL process-concurrency. This is CI evidence, not a runtime migration.
- PR #176 documents Owner-reported staging inventory. Exact-head run [40135536019](https://github.com/zsoltacsai/wpcaregrid/actions/runs/40135536019): 40/40 passed. No PHP 8.5 staging cutover was authorized or performed.
- PR #177 is open for M15 inventory timestamp freshness, based on b4677dd and head 3296fa463e6274260a2f79a15fd359c6ae55bf0b. It is owned by M15 and outside this report.
- Thread 4 owns shared release/checklist coordination. Recheck primary, active PRs and the live ruleset before an M17 PR.

Staging observations below are Owner/Integration-provided read-only reports and were not independently reproduced by M17. They are point-in-time evidence, not a fresh release preflight.

| Item | Status | Evidence and limitation |
|---|---|---|
| Laravel server PHP CI | VERIFIED, CI scope | PHP 8.4/8.5 SQLite, MySQL, MariaDB lanes and MySQL process concurrency passed in #175 merge run. |
| WordPress Connector matrix | VERIFIED, CI scope | Existing PHP 8.2–8.5 Connector lanes; does not establish Laravel host compatibility or every customer environment. |
| Staging CLI/FPM | PARTIAL | Owner reports Ubuntu 24.04, CLI/FPM package 8.4.27 build 8.4.27-1+ubuntu24.04.1+deb.sury.org+1, php8.4-fpm active, PHP 8.5 absent. Earlier read-only observation reported CLI 8.4.27 and /up 200. |
| FPM sockets | PARTIAL | WP CareGrid vhost uses /run/php/php8.4-fpm.sock; multiple test/M8/M14 vhosts share it. Preserve it; any 8.5 pool needs a separate socket and exact reviewed vhost routing. |
| Queue workers | PARTIAL | Owner reports wpcaregrid-backups and wpcaregrid-default use /usr/bin/php8.4. Drain in-flight backup work and reverify process/version before any cutover. |
| Scheduler runtime | UNKNOWN | www-data crontab invokes root-owned /usr/local/bin/wpcaregrid-scheduler; wrapper resolves php through PATH. Actual interpreter/version is not evidenced. |
| Other active PHP consumers | UNKNOWN | No complete process/vhost inventory; legacy PHP usage unresolved. |
| Package patch/provenance | UNKNOWN | PHP 8.5.12 is offered by Ondřej PPA but is not installed. Published signing fingerprint does not prove local keyring or signed Release verification. |
| Staging health | PARTIAL | Owner/Integration report: system:check under PHP 8.4 returned 12 OK, 2 WARNING, 0 ERROR; warnings: one offline Connector and no verified backup within seven days. Refresh at each release. |
| PHP 8.5 staging cutover | NOT PERFORMED | Hotfix owns migration; Thread 4 owns sequencing. No exact UTC window, fresh verified backup, protected receipt, post-cutover proof or rollback rehearsal is recorded here. |

Before any staging PHP cutover, Hotfix must provide reviewed candidate/merge SHA, advisory and package patch disposition, signed package provenance, full active-consumer inventory, extension/workload impact, fresh verified backup, tested rollback to patched PHP 8.4, exact UTC window and protected-controller plan. Thread 4 must confirm the exclusive slot. This is not production authorization.

## M17-01 — Control inventory

| Control | Staging evidence/requirement | Production requirement | Status / next action | Owner | Release blocker |
|---|---|---|---|---|---|
| Source/release | #175/#176 exact CI passed; app SHA last reported 761e0e5d37558da808ac961ca2d2a68b4ffab0db, not freshly read | Protected release, exact CI, receipt and rollback | PARTIAL: refresh deployed SHA and health each release | Thread 4 | YES |
| Runtime/patching | PHP 8.4.27 reported; scheduler and package provenance unknown | Supported vendor-patched runtime; all consumers inventoried | PARTIAL: finish Hotfix gates; no M17 mutation | Hotfix | YES |
| App config/database | Staging config and health checks | Dedicated production config/DB and least-privilege grants | NOT CONFIGURED: separate approved production release | Owner/operations | YES |
| Backups/object storage | Fresh verified backup before cutover; current check warns none in 7 days | Independent encrypted/versioned backup and restore proof | BLOCKED: verify destination and isolated restore | M15/operations | YES |
| Workers/scheduler | Workers reported PHP 8.4; heartbeat and interpreter must be checked | Supervised, observable and recoverable | PARTIAL: identify scheduler PHP and rehearse graceful restart | Hotfix/operations | YES |
| Health monitoring | /up 200 reported; system check 12/2/0 | Independent uptime, DB/queue/storage/TLS alerts and on-call | PARTIAL: test alert delivery and acknowledgement | Operations | YES |
| Connector/security | M14 closed within scope; M15 active; one staging Connector offline | Tenant-safe monitoring and incident escalation | PARTIAL: finish M15 and production escalation policy | M15 | YES |
| Incident response | Runbooks exist | Named commander/on-call, tested route and tabletop | PARTIAL: no alert acknowledgement/tabletop evidenced | Operations/security | YES |
| Admin access | Staging deploy controls documented | Individual audited least privilege and break-glass | UNKNOWN: production grants not reviewed | Infrastructure owner | YES |
| Secrets/key recovery | Classes documented; no values read | Vault scope, rotation/revocation and APP_KEY escrow retrieval | PARTIAL: assign custodians and test recovery without exposing values | Owner/security | YES |
| Payments/invoicing | Staging test-only | Live Stripe/webhooks/tax/invoice reconciliation | EXTERNAL APPROVAL REQUIRED: production not ready | Owner/finance/accountant | YES |
| Mail/DNS/TLS | Staging routes/cert | Production sender, zone-scoped access and renewal | NOT CONFIGURED / UNKNOWN: establish through approved release | Operations/Owner | YES |
| Privacy/legal | Preserve approved M12 gates | Signed retention, deletion, subprocessors, terms and duties | EXTERNAL APPROVAL REQUIRED; do not alter M12 | Owner/advisors | YES |
| Independent security | Scoped preproduction review | Independent report, retest and risk disposition | EXTERNAL APPROVAL REQUIRED; M14 left review open | Owner/reviewer | YES |
| Rollback/DR | Exact-SHA rollback documented; staging rehearsal open | Tested app/DB recovery with measured objectives | PARTIAL/BLOCKED: independent destination and isolated restore absent | Thread 4/M15 | YES |

The staging app SHA is a historical report, not current evidence. M17 did not attempt SSH or obtain credentials.

## M17-02 — Least privilege and credential lifecycle

No secret value was accessed, created, copied or rotated. This is a target model, not an audit of live grants.

| Class | Minimum scope | Lifecycle evidence | Current |
|---|---|---|---|
| APP_KEY | Runtime; encrypted recovery copy limited to named custodians | Dual-control escrow retrieval, loss/reissue impact; original key needed for existing encrypted data | Retrieval untested |
| App DB | Runtime principal limited to needed schema; migration identity separate where feasible | Grant review, isolated rotation, health and audit | Production DB absent |
| Stripe/webhook | Payment runtime only; admin access separately approved | Test/live separation, signatures, idempotency, refunds, revoke/rotate and reconciliation | Live readiness absent |
| Backup storage | Transfer process restricted to bucket/prefix; recovery principal separate | Encryption, versioning, deletion controls, restore/decrypt proof and rotation | Recovery unverified |
| Mail/invoice | Queue consumer and restricted sender | SPF/DKIM/DMARC, suppression, redaction and delivery/rotation test | Unverified |
| Deploy/SSH/sudo | Protected controller and individual audited access | Host-key pin, exact command, approval, audit, expiry/revocation, incident-linked break-glass | Production grants unknown |
| DNS | Minimum permissions on approved production zone | Grant export, preview, audit and revoke test | Unknown |
| Connector/monitoring | Exact service/tenant boundary | Expiry, rotation, replay defenses, redaction and fallback alert route | Production review pending |

Procedure: inventory class/environment/data scope/owner/consumer/expiry; inspect grants and audit metadata without displaying values; exercise rotation/revocation in isolation; confirm service recovery; record version identifiers only. Break-glass is time-bounded, approved, incident-linked and revoked. Do not put secrets in issues, logs or reports.

## M17-03 — Monitoring and incident response

Documented thresholds are starting points, not proof of monitoring or SLAs: scheduler heartbeat each minute and stale after 5 minutes; backup warning 26h/critical 48h and alert on any verification failure; pending transfer >30m; staging disk warning 70%/critical 85%. Calibrate to actual schedule/load and test routing. No alert is delivered until acknowledged by an accountable recipient.

| Signal | First safe response |
|---|---|
| Availability/TLS | Check release, TLS, Nginx/FPM and DB; retain sanitized SHA/status; rollback only when schema-safe |
| Database | Preserve evidence; establish scope; no destructive repair or restore |
| Queue | Classify idempotency; inspect one job; no blanket retry or interruption of active backup |
| Scheduler | Inspect heartbeat, lock/pause, crontab, wrapper and resolved PHP before restart |
| Backup/storage | Block risky cutover/restore; preserve objects; restore only verified artifact in isolation |
| Connector/scanner | Scope exact site/job; avoid duplicate jobs; preserve audit; no M17 auto-remediation |
| Auth/SSH | Preserve logs and contain under incident plan; do not export credentials/customer data |
| Deployment | Freeze rollout; inspect schema compatibility; use approved exact-SHA rollback |

Incident sequence: record UTC incident ID, actor, environment and release SHA; appoint commander; preserve sanitized immutable evidence; classify impact; stop only affected operation by its runbook; avoid blind retries/DB restore; use reviewed rollback if safe; verify service/queue/data invariants; notify only approved routes; assign residuals and retest. Alert acknowledgement, on-call coverage and tabletop remain open gates.

## M17-04 — Disaster recovery preparation

The DR document's initial 24h DB RPO/4h RTO are targets, not promises. It says actual DB RPO is unbounded until independent verified backups are configured and monitored. APP_KEY recovery, remote storage recovery, restored app boot and actual RTO remain unverified.

| Priority | Scope | Proposed target | Measured state |
|---|---|---|---|
| P0 | App/auth/core DB/API | DB RPO <=24h; RTO <=4h | Unverified; independent production restore absent |
| P1 | Queue/scheduler/audit | Resume after DB integrity and stale-work quarantine | Rehearsal open |
| P1 | APP_KEY/integrations | Last escrowed version; 4h recovery target | Retrieval untested |
| P2 | Customer backup objects | Provider-dependent verified durability | Recovery unverified |
| P3 | Noncritical reporting | Restore after core integrity | Not accepted |

Isolated rehearsal: authorized synthetic data and disposable target only; record release/schema, artifact ID/hash/manifest/encryption; verify key retrieval without displaying the key; restore isolated DB/app; quarantine queues/webhooks/notifications; validate DB integrity, migrations, representative records, object references, app boot/auth/health; measure capture/verify/restore/validation; clean up with proof. Never restore customer data to public staging.

Acceptance requires independently stored artifact verification, successful isolated restore/app boot, DB/object integrity and auth/health, quarantined outbound work, measured actual RPO/RTO, verified cleanup, assigned/retested failures, and Owner acceptance before customer promises. A local synthetic MySQL drill is not independent DR evidence.

Exercise report fields: ID/date UTC, authority, synthetic-data/isolation proof, release/schema/source, artifact ID/hash, key retrieval proof without value, target, queue quarantine, DB/app/object checks, measured times/RPO/RTO, cleanup, failures/retest, reviewer and acceptance.

## M17-05 — Independent security, legal and accounting readiness

Independent review should cover tenant isolation; auth and Owner/Operator boundaries; Connector pairing/tokens/jobs/results/replay; scanner readiness and side effects; SSRF/redirect/archive/path/symlink safety; backup encryption/storage IAM/restore/deletion; Stripe signatures/idempotency/refunds/webhooks; privacy/retention/export/deletion; CI/dependency/provenance/deploy/secrets/log redaction; known residuals and abuse cases.

Require a dated report with scope/exclusions, finding ID/severity/environment, prerequisites, owner, remediation target/date, retest and disposition. No certification or independent review is claimed.

| Decision | Responsible party | Status |
|---|---|---|
| Retention/deletion/legal hold, privacy notice, subprocessors | Owner + privacy/legal advisor | EXTERNAL APPROVAL REQUIRED |
| VAT/OSS, invoice issuer/content, credit notes/accounting export | Owner + accountant | EXTERNAL APPROVAL REQUIRED |
| Live Stripe markets, refunds/disputes/reconciliation | Owner/finance/security | EXTERNAL APPROVAL REQUIRED |
| SLA/support/security claims/breach notice | Owner/legal/operations | EXTERNAL APPROVAL REQUIRED |
| Independent reviewer/scope/risk acceptance | Owner | EXTERNAL APPROVAL REQUIRED |
| Customer-facing RPO/RTO | Owner/operations/legal | EXTERNAL APPROVAL REQUIRED; actuals unmeasured |

## M17-06 — Production GO/NO-GO template

Every item needs dated evidence and a named owner. Any mandatory UNKNOWN is NO-GO.

- [ ] Protected current SHA, resolved reviews, exact-head and merge-SHA CI; MySQL process-concurrency passed.
- [ ] PHP advisory/patch disposition, signed package provenance and FPM/CLI/worker/scheduler inventory verified.
- [ ] PHP 8.5 CI distinguished from staging runtime; staging cutover has exact UTC window, fresh verified backup, protected receipt and tested rollback to patched 8.4.
- [ ] Production host/TLS/config/DB/storage/workers/scheduler/mail/Stripe/invoicing configured and reviewed under a separate release.
- [ ] Secret scopes, rotation/revocation, break-glass and APP_KEY escrow retrieval verified without values.
- [ ] Independent monitoring/alert route reaches accountable recipient; incident exercise accepted.
- [ ] Independent backup destination and isolated restore/app boot pass; actual RPO/RTO measured.
- [ ] M15 pilot/operations and M16 product/customer/accessibility/claims accepted.
- [ ] Independent security review complete; findings remediated/retested or explicitly risk-accepted by authorized Owner.
- [ ] Privacy/legal/accounting/tax/invoice/terms/customer claims approved by responsible parties.
- [ ] Residual risks have owner, mitigation, expiry and exception record.
- [ ] Separate explicit Owner production GO decision recorded.

This preparation report is not that decision and authorizes no production launch.

## Acceptance and ownership

| M17 item | Status | Remaining evidence |
|---|---|---|
| M17-01 control inventory | PARTIAL | Current deployed SHA/date; Thread 4 integrates shared checklist |
| M17-02 secrets/access lifecycle | PARTIAL | Production grants review, rotation/recovery test, named custodians |
| M17-03 monitoring/incident | PARTIAL | Alert acknowledgement and tabletop |
| M17-04 DR | BLOCKED | Independent backup, isolated restore/app boot, measured objectives |
| M17-05 security/legal/accounting | EXTERNAL APPROVAL REQUIRED | Independent report/retest and signed decisions |
| M17-06 launch decision | NO-GO | All evidence plus separate Owner GO |

Hotfix owns PHP remediation/cutover. Thread 4 owns shared release files, integration sequencing and staging slot. M15 owns pilot/backup operations. M16 owns UI/customer acceptance. Owner/advisors own production infrastructure and commercial/legal decisions. M17 does not edit shared release checklists or perform staging/production mutations.

**Worktree:** D:\-=AI=-\-=Git=-\wpcaregrid\.codex-worktrees\m17-production-readiness. Local branch codex/m17-production-readiness began at 943deb62558940181b948771b7abdf70e76ae516, a verified ancestor of refreshed primary b4677dd47af87f1d9627fb9693c9e3b0ed598e4a. This M17-owned report does not alter PR #176's PHP policy, M15 files, shared release documents or another worktree. Primary fetch succeeded after the initial DNS failure. No app tests are claimed for a documentation-only change; PR exact-head CI will provide repository checks.

**Disposition: M17 ENGINEERING IN PROGRESS.** Control inventory, least-privilege credential lifecycle, monitoring/incident procedures, DR proposal/rehearsal acceptance, external review register and GO/NO-GO template are prepared. Production remains NO-GO: package/signature/consumer evidence is partial, alert and DR verification are open, independent review/legal/accounting approvals are external, M15/M16 acceptance is required, and Owner launch approval is separate.

**Next action:** validate this scoped report, open a focused PR and obtain exact-head CI and Thread 4 review.
