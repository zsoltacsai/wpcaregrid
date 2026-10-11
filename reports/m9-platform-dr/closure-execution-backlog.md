# M9 Closure Execution Backlog

Priorities: P0 = closure gate; P1 = required Owner feature or high-confidence launch readiness; P2 = optional/post-launch. Every task is planned, not started in this planning pass. Existing uncommitted work at f007e19... is preserved.

## Track A — Existing M9 audit and corrections

| ID / Priority | Dependencies | Components | Acceptance and tests | Evidence / Owner approval |
|---|---|---|---|---|
| A-01 P0 | none | Existing PlatformDatabaseBackupService, migrations, docs, tests | Review prior changes against v4 artifact/destination model; confirm legacy records remain readable; inspect migration rollback and DB driver behavior. Run focused Platform suite. | Reviewed diff, migration/test result; no external approval |
| A-02 P0 | A-01 | Laravel tests, CI | Run full current CI including MySQL 8.4 service and MySQL import verification; resolve failures. | CI URL/SHA, exact counts/skips. No code release until green |
| A-03 P0 | A-01 | Platform DB backup verification | Ensure “verified” is level-specific; block false positive when MySQL import endpoint unavailable. Add malformed/encrypted artifact/manifest tamper cases. | Test report; no Owner action |

## Track B — Backup artifact and destination architecture

| ID / Priority | Dependencies | Components | Acceptance and tests | Evidence / Owner approval |
|---|---|---|---|---|
| B-01 P0 | A-01 | Platform backup domain/model | Introduce immutable logical artifact identity and separate per-destination delivery records; additive migration; legacy rows map safely. Test SQLite/MySQL migration, uniqueness, transactions, rollback. | Schema review, migration tests. No data rewrite on live DB without deployment approval |
| B-02 P0 | B-01, G-01 | Artifact serializer/encryption, manifest | Versioned authenticated encryption and authenticated manifest bind source, schema, release, artifact ID, size and ciphertext hash. Test tamper, wrong key/version, truncation, duplicate, decryption compatibility. | Crypto review and test evidence; Owner selects/approves key-management mechanism |
| B-03 P0 | B-01/B-02 | Delivery coordinator | One snapshot fans out to independently due destinations; destination outcomes isolated; idempotent retries and timeout-after-success recovery. Test S3 success/Drive failure, inverse, both fail, partial, duplicate, stale schedule. | State-machine tests; no provider action |

## Track C — Dedicated S3 bucket integration

| ID / Priority | Dependencies | Components | Acceptance and tests | Evidence / Owner approval |
|---|---|---|---|---|
| C-01 P0 | Owner bucket/credential action; B-01/B-02 | config/filesystems, secret management, platform S3 adapter | Dedicated platform bucket and separate scoped credential; private-only; no local deployed fallback; no customer credential reuse; fixed prefix/key validation; SSE/KMS config validated. | Read-only policy review and non-secret identity evidence. Owner creates bucket/credentials and approves costs |
| C-02 P0 | C-01/B-03 | upload/verification/reconciliation | Streaming upload, provider checksum semantics verified, full readback SHA-256 fallback, multipart resume/reconcile, no automatic orphan deletion. | Provider sandbox or approved staging test; bucket exact identity approval |
| C-03 P0 | C-02 | recovery import | Independently fetch, authenticate manifest, hash ciphertext, decrypt in isolation, import clean MySQL 8.4 and boot/sample data. | Disposable recovery drill report; Owner approves disposable environment only |
| C-04 P1 | C-01 | provider controls | Evaluate versioning, deny-delete, Object Lock, lifecycle/replication, logs. Start with versioning recommendation; never change settings without approval. | Capability/cost matrix; Owner/provider decision required |

## Track D — Google Drive OAuth and destination adapter (OPTIONAL; not closure gate)

| ID / Priority | Dependencies | Components | Acceptance and tests | Evidence / Owner approval |
|---|---|---|---|---|
| D-01 P2 | dedicated Google project/account; B-01/B-02 | OAuth controller, state store, credentials | Exact callback/state/session binding, least scope validated, refresh encrypted, revocation and reauthorization state. Mock tests for CSRF/replay/scope; sandbox OAuth separately. | OAuth scope/verifications checklist; Owner creates OAuth client/account and approves privacy/consent |
| D-02 P2 | D-01 | Drive adapter | Encrypted artifact only; app-created private folder; stable IDs; resumable upload/readback; quota/rate limit and partial recovery. | Mocked and provider sandbox tests; no claim from mocks alone |
| D-03 P2 | D-02 | Owner Console | Independent enable/schedule/status/history and disconnect workflow; Drive never overrides S3 primary. | Screens/tests and sandbox report; Owner explicitly activates optional secondary |
| D-04 P2 | D-02 | Google consent/recovery | Complete applicable app verification; human disaster-recovery download/import drill independent of SaaS. | Google approval/account recovery evidence. Not needed for M9 S3 closure |

## Track E — Owner Console platform backup management

| ID / Priority | Dependencies | Components | Acceptance and tests | Evidence / Owner approval |
|---|---|---|---|---|
| E-01 P0 | B-01/B-03, C-01 | owner routes/controllers/views, OwnerAbility, AdminAudit | Owner-only overview/history, separate upload/verify/recovery-drill states; split view/manage gates; no secret/raw artifact exposure; route ability test. | Authorization/redaction/audit tests |
| E-02 P0 | E-01, F-01 | OwnerSettings or typed schedule table | Validate 6h/12h/daily/weekly; UTC schedule versioning; frequency RPO warning; independent optional destination cadence; transaction/CAS update and audit. | Concurrent update/timezone/DST tests; Owner frequency approval already given |
| E-03 P0 | E-01/B-03 | manual backup workflow | CSRF, reauth if available, rate limiting, idempotency, snapshot lock, audit, bounded retry; no restore action. | Duplicate/concurrency/permission tests |
| E-04 P0 | E-01/F-02 | enable/disable and retention view | Explicit primary-disable phrase + warning + two-step confirmation; no artifact deletion; retention dry-run only until M12 policy. Protect last verified point and holds. | UX/API tests, audit evidence. Owner approves any later deletion feature |

## Track F — Dynamic scheduling, retention and reconciliation

| ID / Priority | Dependencies | Components | Acceptance and tests | Evidence / Owner approval |
|---|---|---|---|---|
| F-01 P0 | E-02/B-03 | scheduler occurrence table, command | Unique UTC slot/idempotency key, onOneServer plus durable uniqueness, no overlap, bounded latest-slot catch-up, active run captures settings version. | Race/downtime/DST/config-change tests |
| F-02 P0 | B-01/C-02 | retention evaluator | Destination-specific daily/weekly/monthly candidate rules and dry-run report. No automatic deletion at closure; respect holds, last verified point, active restore/drill, in-flight/divergent state. | Property/invariant tests; M12 legal retention decision remains open |
| F-03 P0 | B-01/C-02 | platform/customer reconcilers | Report missing object, metadata-only/remote-only, size/hash mismatch, unavailable, partial upload; no auto-delete. | MySQL/S3 sandbox tests and anomaly reports |
| F-04 P1 | C-02/D-02 | pressure/health | Age/capacity/backlog thresholds exposed as machine-readable signals; tune from measured data. | Threshold tests; M10 alert routing deferred |

## Track G — Encryption and APP_KEY escrow

| ID / Priority | Dependencies | Components | Acceptance and tests | Evidence / Owner approval |
|---|---|---|---|---|
| G-01 P0 | Owner chooses existing vault/KMS | key provider/format | Dedicated versioned data-encryption key, not APP_KEY; authenticated encryption, key ID versioning, decrypt old versions, rotation plan, controlled retrieval. | Security review; Owner selects approved escrow/key service and any cost |
| G-02 P0 | G-01 | manifest/envelope | Authenticate manifest and ciphertext; fail closed on altered tag/hash/key version. | Tamper/property tests and offline decrypt drill |
| G-03 P0 | Owner selects password manager/secret vault | deployment secret procedures | Escrow exact deployed APP_KEY independently with restricted break-glass; retrieval drill performed without report disclosure; map encrypted DB fields and reissue plan. | Owner-approved vault and access-test evidence; no key in source/reports |
| G-04 P1 | G-01 | key rotation/recovery | New backups use active version; old artifacts retain decryptable key reference; key retirement blocked until no retained artifact depends on it. | Version migration tests; Owner approval before key retirement |

## Track H — MySQL recovery drill and CI

| ID / Priority | Dependencies | Components | Acceptance and tests | Evidence / Owner approval |
|---|---|---|---|---|
| H-01 P0 | A-02/B-02/G-01 | GitHub CI MySQL 8.4 service, Platform backup tests | CI creates synthetic DB, snapshots/encrypts/hash verifies, decrypts/imports into clean MySQL 8.4, checks schema/tables/critical records, boots app and recovery mode. | Passing MySQL job URL/SHA, exact counts |
| H-02 P0 | H-01 | queue quarantine/replay | Seed old destructive WebsiteJobs and runnable Laravel jobs in disposable DB; recovery mode prevents execution; quarantine evidence retained; only safe reconciled work restarts. | Deterministic MySQL integration test; test no old job delivered/executed |
| H-03 P0 | H-01 | Stripe webhook | Duplicate signed synthetic event has one effect; after snapshot rollback, read-only provider state reconciliation policy documented; no real Stripe calls. | Existing regression + synthetic integration result |
| H-04 P1 | H-01 | CI services | Ensure S3/Moto and SFTP/sshd integration are non-skipped in CI, MySQL schema import test is non-skipped; record exact skip reason in other environments. | CI all required jobs green |

## Track I — Staging validation and M9 closure

| ID / Priority | Dependencies | Components | Acceptance and tests | Evidence / Owner approval |
|---|---|---|---|---|
| I-01 P0 | all P0 tracks, CI green | release/CI | Review complete diff, migrations, rollback, secrets scan, all required test suites and CI. | Reviewed SHA and CI evidence |
| I-02 P0 | C-01/G-03 Owner actions; I-01 | guarded staging deploy | Strict SSH host verification, clean staging baseline, pre-deploy DB backup, guarded deployment, migration and health checks, only idle-worker graceful restart. | Deployment SHA, backup reference/hash, health/queue/scheduler/storage checks. Owner deployment approval per existing process |
| I-03 P0 | I-02 | staging non-destructive verification | Configure platform S3, run synthetic platform DB backup, remote readback, isolated MySQL 8.4 import and read-only restore-readiness checks; do not restore staging or production DB. | Artifact metadata/hash, destination health, DB drill report, no secret values |
| I-04 P0 | I-03 | closure review | Reconcile outcomes, RPO/RTO actual vs target, APP_KEY/key retrieval evidence, recovery queue test, runbooks, retention safety and risk register. | Final signed evidence and M9 checklist; no production mutation |
| I-05 P2 | D-01..D-04 | optional Drive activation | Complete Google consent/project/account/tests, then opt in Drive; prove independent delivery/recovery. | Separate optional completion record; does not block I-04 |

## Recommended implementation order

A-01 → A-02, then Owner actions C-01/G-01/G-03 in parallel with B-01/B-02; B-03 → C-02/C-03 → H-01/H-02/H-03; E/F controls built atop destination state; I-01/I-02/I-03/I-04. Drive D-track may proceed after stable artifact contract but in separate release and never blocks primary S3 closure.

## Release gates and hard stop conditions

Do not call a backup recovery-ready when only local file creation or object HEAD passes. Stop on manifest/authentication mismatch, unknown bucket identity, key retrieval failure, import failure, queue replay risk, ambiguous remote object, storage credential scope broader than approved, or tests unexpectedly skipped in required CI. Do not run a destructive production restore. No M9 implementation or deployment was started by creating this backlog.
