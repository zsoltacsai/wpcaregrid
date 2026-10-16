# M12 Final Engineering Closure — Wave 8

**Status:** final evidence assembled; report-only closure PR and exact-head CI are the remaining release steps. This report records engineering validation, not legal or compliance approval.

## Source and release lineage

- Authoritative branch: `claude/ecstatic-wozniak-oemyuf`.
- Wave 8 plan PR #73 was verified at exact head `9c1f00d8266bc500934e4b1bb214827ce6d2b9dd`; its changes were documentation only and required five-job GitHub Actions run `38847059091` passed. It merged to primary as `57cdf9aac2ef0339ded74036181a469d9d11cbeb`.
- The Wave 8 validation branch was created from that exact merged primary. No executable changes were needed.
- Executable staging SHA: `19e31d7204a59e08211c4dd9e313b9cccbb01611`. The difference from Wave 8 primary is documentation only; no redeploy was required.
- Final report PR/CI/merge and final primary SHA are to be appended after the report-only release completes.

## Regression evidence

Exact plan-head Actions run `38847059091` passed all five mandatory jobs: quality/security/build, Server SQLite, Server MySQL, Connector SQLite and Connector MySQL. MySQL process/concurrency ran in the Server MySQL job.

| Gate | Result |
|---|---|
| Server SQLite | PASS — 1,283 tests, 18,413 assertions, 19 documented environment/opt-in skips |
| Server MySQL | PASS — 1,283 tests, 18,611 assertions, 0 skips; required process/concurrency gate passed |
| Connector SQLite | PASS — 202 tests, 1,401 assertions, 24 documented skips |
| Connector MySQL | PASS — 202 tests, 1,775 assertions, 3 documented skips |
| Quality/security/build | PASS |

These are per-job counts; SQLite/MySQL jobs exercise the same suites, so they are not a unique-test total. The skips are documented/environment-specific and counterpart coverage ran in the other database job where applicable.

A separate private staging-host test runtime used the exact executable source SHA above, `APP_ENV=testing`, `APP_DEBUG=false`, a unique disposable MySQL 8.4.11 database/user, sync queue, array mail, no scheduler/worker registration, no provider credentials and a private test journal namespace. It ran the focused Wave 8 privacy suite: **70 tests, 899 assertions, 0 failures, 0 errors, 0 skips**. Two SQLite-only restore test files were excluded because staging PHP has no `pdo_sqlite`; they are covered by Server SQLite CI and the current Wave 7 MySQL historical-restore proof.

## Staging baseline, gates and cleanup

The staging host was accessed with strict SSH verification. The Ed25519 fingerprint matched `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`. The staging checkout was clean at `19e31d7204a59e08211c4dd9e313b9cccbb01611`. Read-only application/DB/migration/queue/scheduler/worker/Connector/Stripe TEST/backup/mail/privacy/journal/recovery health checks passed before and after isolated validation. Failed jobs and active mutating WebsiteJobs were zero. The visible “no completed security scan” warning remains.

All six normal-staging gates were read before and after validation and remained false:

- `M12_DESTRUCTIVE_RETENTION_ENABLED=false`
- `M12_PROFILE_ANONYMIZATION_ENABLED=false`
- `M12_BACKUP_DELETION_ENABLED=false`
- `M12_PROVIDER_DELETION_ENABLED=false`
- `M12_BULK_RETENTION_EXECUTION_ENABLED=false`
- `M12_SYNTHETIC_PROFILE_ANONYMIZATION_ENABLED=false`

The temporary runtime, disposable database, temporary database user and external PHPUnit configuration were removed and absence verified. No S3 test objects, normal staging queue tasks, workers, scheduler entries or public routes were created. No normal staging customer/site row was mutated; no provider delete API, external mail delivery, live Stripe operation or production resource was used.

## Scenario A–O evidence disposition

The evidence-backed outcomes are recorded row by row in `wave8-e2e-matrix.md`. A–N pass through current exact-source CI, the focused isolated MySQL staging run, and directly relevant Wave 2–7 staging evidence. O passed with Wave 8 cleanup and post-run health/gate read-back. The scenarios were not one monolithic transaction; the matrix identifies the evidence reused for each and does not overstate that distinction.

- Account closure remains separate from an erasure request; closure restriction is reversible.
- Export repeat-download/expiry/revocation, rectification and legal/acceptance infrastructure remain within allowlisted, fail-closed behavior. Legal publication and enforcement remain off.
- Retention eligibility is report-only; eligibility does not invoke deletion.
- Synthetic anonymization is limited to the explicit testing fixture path. Wave 7 evidence proves journal-before-mutation, credential/session revocation, email release/re-registration isolation, unchanged accounting fingerprints, genuine T0 MySQL restore, replay and idempotent repeat replay.
- Wave 6 staging evidence proves object-storage journal replay for supported restriction/revocation states. Wave 7's anonymization replay test used an isolated local fake journal backend; it does not prove staging S3 physical durability or provider failure-domain independence.
- Backup safety, protected recovery points, provider pending/unknown/manual-review states and immutable accounting boundaries remain fail-closed. No physical deletion is claimed.
- Current test and prior staging evidence cover tenant scoping and Owner authorization. No cross-tenant disclosure or mutation was observed.

## Audit and historical-report reconciliation

The older Wave 5 report disposition is superseded by its later authenticated Owner preview evidence and current Wave 8 account-closure tests; historical text remains unchanged. `wave8-closure-matrix.md` contains the current 36-requirement reconciliation: 30 PASS, 1 PARTIAL/M14 residual, 1 WAIT_LEGAL, 1 WAIT_ACCOUNTING and 3 WAIT_FACT. No engineering row is MISSING or STALE. The WAIT_FACT rows are M12-11, M12-13 and M12-36, all reflecting the same unresolved provider semantics for affected actions.

`privacy-controls-readiness.md` and `dr-privacy-readiness.md` receive Wave 8 evidence updates. They describe technical privacy controls and reusable recovery evidence only. They do not declare GDPR compliance or complete the production DR audit.

## External gates and future audit work

- W7-L-01…03 and applicable L1–L10 remain `WAIT_LEGAL`; they block legal publication/acceptance enforcement, legally framed response promises, legal-hold application and applicable irreversible actions.
- W7-A-01…02 and applicable C1–C4 remain `WAIT_ACCOUNTING`; they block destructive changes to accounting-linked identity/evidence and any invented accounting retention schedule.
- W7-P-01 and applicable P1–P9/P11 remain `WAIT_FACT`; they block provider deletion/physical-absence claims and unverified durability/failure-domain claims.
- Production destructive execution remains separately unauthorized and disabled.
- The incomplete general security scan remains an open M14 AppSec residual. M14 AppSec, tenant-isolation, CI/CD supply-chain and hosting compatibility audits remain to be completed. M15 DR/restore, billing, privacy-control and operational-safety audits remain open. M17 final production-readiness audit must evaluate its exact release SHA.

## Closure meaning

This engineering milestone does **not** certify GDPR compliance, approve legal text or accounting periods, prove provider physical erasure, authorize production destructive execution, or complete M14/M15/M17 audits. `M12_DESTRUCTIVE_RETENTION_ENABLED=false` remains mandatory. Production profile anonymization, customer backup deletion, provider deletion, bulk execution and unattended erasure remain disabled.

## Final release evidence

Append exact-head report PR CI, merge SHA, final primary SHA, post-merge gate/status reconciliation, and final verdict here after the report-only closure PR merges. No staging redeploy is required for this documentation-only closure.
