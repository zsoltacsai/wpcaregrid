# M14.7 closure recovery — engineering evidence

Date: 2026-10-19
Baseline: `9e11c2035cd343b8cc1615fee4dbcf4a34c16cd9`
Working branch: `codex/m147-closure-recovery` (based on the verified primary)
Status: engineering candidate; review and exact-head CI pending. This is not a milestone closure or operational authorization.

## Scope and safety boundary

This recovery implements local-only engineering improvements intended to remove supported-tooling blockers. No staging, production, customer site, Rackforest console, SSH authentication, M12 live gate, live vulnerability feed, scanner, queue, backup, restore, remediation, deployment, or external payment/AI service was accessed or executed. The six live M12 values remain **UNKNOWN**. M147-E.2b remains **NO-GO / NOT AUTHORIZED**. PR #129 remains open and unmerged.

The authoritative primary was verified at `9e11c2035cd343b8cc1615fee4dbcf4a34c16cd9`. PR #129 was verified open, targeting `claude/ecstatic-wozniak-oemyuf`, at head `0c51d0966bc083c48bbba49ae3314e7968ec2b18`. Its then-current run [39170388169](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39170388169) passed the seven required jobs; the Server MySQL process/concurrency step passed. This run is evidence for PR #129's old head only, not this recovery branch.

## Supported local staging-audit architecture

The new `tools/security/m147-staging-audit/audit.php` is a manually invoked standalone PHP CLI program. It does not load Laravel or WordPress, invoke a shell/process, make a network call, inspect environment/configuration files, or write application state. Identity mode requires an expected exact host/account/path and rejects root, mismatches, unsupported identity APIs, symlinked/ambiguous Git metadata, and unknown options. It reads only host/account/time, a checked-out commit (including a linked-worktree `.git` pointer), PHP version, and available bytes for the requested filesystem path. It emits JSON or Markdown and bounded error codes.

The M12 mode deliberately emits `UNKNOWN` for all six gate identifiers; it does not read environment variables, `.env`, config cache, or Laravel configuration. Effective runtime values cannot be safely established through this source-only runner. A future gate reader needs a separate side-effect review and operation-specific approval. The runner never runs automatically and is not connected to deployment, CI dispatch, web routes, cron, or a queue.

Supporting artifacts include `evidence.schema.json`, an allowlisted SHA-256 manifest generator, operator instructions, a report template, and synthetic tests. Windows PHPUnit: `M147StagingAuditRunnerTest`, 6 tests, 6 assertions, 5 skipped because POSIX-only identity checks and symlink creation are unavailable in this Windows context. WSL targeted execution passed 6 tests / 46 assertions. A local synthetic invocation returned the local disposable checkout identity and six `UNKNOWN` M12 states; this is not staging evidence.

Current manifest output (before final evidence-document-only edits): `audit.php` SHA-256 `470be5d1ad7f0e34318f412369a516500706d2cfd9f020b931f40ff4d4a556e3`; schema SHA-256 `72327a45b00b850fb9cee3b9d7077132da0721cae4742326108811ea4b71aad5`; manifest version `1.0.0`. The command is `php tools/security/m147-staging-audit/manifest.php`; hashes are repository artifact identity, not a signature or source attestation.

## Browser E2E harness and executed result

The existing guarded Windows harness allowed only two historic worktrees and checked generated PHP/runtime files before invoking its own initializer. It therefore could neither register a new exact-head checkout nor bootstrap an empty generated runtime. The harness now retains both historic identities and supports one explicit recovery-worktree identity bound to an exact 40-character commit via an ignored local registration record. Every script rechecks that identity. It uses separate loopback services/ports and synthetic accounts, and retains the existing egress and test-service controls.

At browser-harness source commit `510aeac849c022786978f6d3c87384dc557afdcc`, the isolated local Playwright run passed 3/3 Connector browser tests:

1. Pairing lifecycle, tenant rejection, expiry, replay refusal, and explicit re-pairing.
2. WordPress restart and local heartbeat/inventory/health synchronization.
3. Laravel outage/restart stale state and recovery.

The local fixture used WordPress 7.1.3, Connector 0.4.7, a disposable SQLite database, and local-only services on ports 8317/8318. The run exited successfully and those service listeners were absent afterward. This is selected Connector browser coverage only; ordinary application E2E, Stripe, staging, and cross-origin production integrations are not claimed. The generated ignored `storage/e2e` workspace contains local synthetic test artifacts; process-policy rejected its cleanup command. It is not tracked and was excluded from the commit. A later local cleanup must use an approved filesystem operation after confirming no listeners/processes.

## Connector MariaDB CI reliability investigation

The historical same-head run [39129828668, attempts 1 and 2](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39129828668/attempts/1) timed out waiting for the `hold-*.result` synchronization file in `PollLockConcurrencyTest::test_expired_owner_cannot_release_or_refresh_successor_lease`; attempt 3 passed without source changes. Existing logs do not establish whether runner scheduling, process startup, DB contention, or a deterministic defect caused the timeout. The observed CI retry demonstrates intermittence, not harmlessness. Local WSL had no running Docker/MariaDB service, so this turn could not reproduce that specific MySQL/MariaDB behavior.

The in-scope harness change adds process status, PID, exit code, result presence, and byte-count/SHA-256 diagnostics at synchronization timeout without exposing raw stderr or absolute result paths. The stale-owner scenario now stops its child worker in a `finally` block, including assertion-failure paths, with bounded TERM/KILL attempts. Worker failures report stderr length and hash instead of raw diagnostic text. It does **not** change the 20-second correctness deadline, lock assertions, or application lock behavior. Root cause remains **unproven**; finding CI-REL-01 remains **OPEN** pending exact-head CI and repeated stable observations.

## Validation record

Executed local checks:

| Check | Result | Scope / limit |
|---|---|---|
| Standalone audit runner targeted suite in WSL | 6 tests / 46 assertions passed | Synthetic local metadata only; no live environment |
| Windows audit-runner PHPUnit | 6 tests / 6 assertions; 5 skipped | POSIX identity and symlink cases skip on this Windows runtime |
| Laravel `Feature/Security` plus audit runner, Windows PHP 8.4.26 | 108 total; 103 passed, 5 skipped; 1,784 assertions; 0 failures | Previously executed on this recovery branch after Windows-specific skip handling |
| Connector Playwright local isolated suite | 3/3 passed | Exact browser harness commit above; synthetic local fixture |
| PHP lint | Pass for changed concurrency test | PHP 8.4 WSL |
| Pint | Pass after formatting the changed concurrency test | One file |
| Composer validation for Connector harness | Valid | No dependency constraints changed |
| Earlier recovery-branch npm audit/build and Composer audit | Pass | Lockfiles unchanged; frontend build completed |
| Local MariaDB concurrency test | Not run | No local DB service; Docker daemon unavailable in WSL |
| Full Laravel suite, Windows PHP 8.4.26 | Attempted; aborted at `StorageProviderTest::test_s3_sends_the_sha256_so_the_service_rejects_corrupt_uploads` | The suite's moto service discovery uses POSIX `command -v`; Windows emitted command-not-found and PHPUnit's child process ended prematurely. This is a host-platform harness limitation, not a passing result or evidence of an application defect. |
| Full Laravel suite, WSL PHP 8.4.26 | 1,410 tests; 20,532 assertions; 25 skipped; 1 failure | The sole failure was `ConnectorPackageArtifactTest::test_untracked_connector_source_is_rejected_before_packaging`: this Windows-created worktree has a `.git` pointer containing a Windows gitdir path, which Linux Git cannot resolve. In test mode, `sourceRevision()` intentionally allows a null revision when Git is unavailable, so the test's untracked-file case did not exercise the intended tracked-source guard. This is a cross-platform worktree/test-environment mismatch; no production defect was established. |
| Connector package artifact suite, Windows PHP 8.4.26 | 4 tests / 127 assertions passed | Includes the untracked-source rejection that failed in WSL because of the Windows `.git` pointer. Confirms the intended behavior when the same worktree is inspected by its native Windows Git. |
| Isolated Connector browser suite | 3/3 passed | Exact browser harness commit `510aeac849c022786978f6d3c87384dc557afdcc`; synthetic local fixture only. |
| Required GitHub seven-job matrix for this recovery branch | Pending until PR head is pushed | PR #129's old-head green run is not substituted |

Earlier in this recovery, `PollLockConcurrencyTest` could not be started with PHPUnit because the disposable WordPress core/test DB fixture was not present in the WSL worktree; this is not recorded as a test pass. Windows-only syntax/style checks pass, but they do not exercise MySQL process concurrency. The full Laravel suite therefore has one known local-platform failure, separately confirmed as passing in the focused native-Windows Connector package suite; the full suite must still pass on exact-head CI before review.

## Findings and stage disposition

| Finding / stage | Status after this work |
|---|---|
| AS-16 / M147-F01 operational scanner coverage | OPEN; no staging/live scanner execution |
| M147-F02 six live M12 gates | OPEN; all UNKNOWN and unread |
| M147-F03 live feed provenance/licensing/runtime/SSRF | OPEN |
| M147-F04 exhaustive side-effect isolation | OPEN; selected local synthetic tests only |
| M147-F05 browser/integration gaps | OPEN; selected Connector browser tests now executed, broader and Stripe paths remain untested |
| M147-F06 independent security review | OPEN / preserve authoritative definition; no independent reviewer claimed |
| M147-F07 feed handling | Previously closed only for its tested synthetic sub-scope |
| M147-F08 external M17 penetration checkpoint | OPEN |
| M147-C-02 false Healthy | Previously closed; unchanged |
| M146-F08 tracked secret-path checker output | Previously scoped closed; unchanged |
| CI-REL-01 | OPEN; diagnostic/cleanup resilience improved, cause not proven, real MariaDB rerun pending CI |
| M147-E.2b | NO-GO / NOT AUTHORIZED |
| M147-F / M14.8 | NOT STARTED / NOT READY |

No source default or synthetic result is presented as evidence of an effective live safety gate. No independent penetration test or complete security assurance is claimed.

## Exact-head CI reconciliation — PR #130

The validation table above records CI as pending at the time the local candidate was committed. PR [#130](https://github.com/zsoltacsai/wpcaregrid/pull/130) was then created from the verified primary and exact head `faa541b822a04562c0fb9735b19c2f56d3d38159` was checked by [run 39203260034](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39203260034). All seven mandatory jobs passed:

| Required job | Result |
|---|---|
| Quality (lint, style, dependencies, build, secrets) | PASS |
| Server tests (PHP 8.4, sqlite) | PASS |
| Server tests (PHP 8.4, mysql) | PASS |
| Server tests (PHP 8.4, mariadb) | PASS |
| Connector tests in WordPress (PHP 8.4, sqlite) | PASS |
| Connector tests in WordPress (PHP 8.4, mysql) | PASS |
| Connector tests in WordPress (PHP 8.4, mariadb) | PASS |

The Server MySQL process/concurrency gate step itself passed. Its nine required test files completed successfully (15 tests / 161 assertions total). The Connector MariaDB job also passed on this exact head. The run is evidence for `faa541b...` only; any subsequent PR-head change requires a new exact-head run. PR #130 remains open and unmerged; PR #129 remains open and unmerged. No merged-primary CI exists for this candidate because no merge was requested or performed.
