# M14.7 integration candidate review — 2026-10-19

## Decision and scope

This report records a local integration candidate combining the reviewed changes from PRs [#129](https://github.com/zsoltacsai/wpcaregrid/pull/129) and [#130](https://github.com/zsoltacsai/wpcaregrid/pull/130), plus narrowly scoped fixes discovered while validating that combined tree. It is a review candidate only. Neither source PR nor the candidate was merged to the protected primary branch. No staging, production, customer system, live feed, credential, M12 gate, backup, restore, scanner, deployment, or external operational service was accessed.

Authoritative primary at verification: `9e11c2035cd343b8cc1615fee4dbcf4a34c16cd9`. The primary branch remained at that exact SHA. Source PR heads at verification were #129 `0c51d0966bc083c48bbba49ae3314e7968ec2b18` and #130 `0afddacb234124b723978d542736d8477db28974`; both were open, targeted `claude/ecstatic-wozniak-oemyuf`, and were reported mergeable. Their exact-head GitHub CI runs were respectively [39170388169](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39170388169) and [39237437478](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39237437478), each 7/7 successful. The explicit Server MySQL process/concurrency gate passed in both runs. These are source-PR results, not CI evidence for this integration candidate.

The candidate branch `codex/m147-pr-integration-review` is based on primary `9e11c2035cd343b8cc1615fee4dbcf4a34c16cd9`. Its final local validation SHA before evidence-documentation changes was `57ccb42a55c5cbe9220a40530685fa91a2a40173`. Integration PR [#131](https://github.com/zsoltacsai/wpcaregrid/pull/131) was opened at `66060cbdd1acf0379fb63af416001a898832db72`; exact-head run [39243862900](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39243862900) completed 7/7 successfully, including the Server MySQL process/concurrency gate. This evidence is for that exact SHA; a documentation-only follow-up changes the head and requires a fresh exact-head CI run.

## Integration and review decisions

The source PRs overlapped in `docs/PROJECT_STATUS.md`, `reports/m14-security/m14-7-findings.md`, and `wordpress/tests/Integration/PollLockConcurrencyTest.php`. The integration retained both histories and reconciled the implementation: atomic result-file publication and bounded sanitized worker diagnostics from #130, plus early worker-exit detection and its regression coverage from #129. It retained the distinct E2a evidence and did not discard #130 recovery evidence. The database lock assertions and 20-second deadline were not weakened.

Review of the combined staging audit runner found that linked worktree resolution did not consult the common Git directory, so packed branch refs could be missed. It also canonicalized a path before checking symlink traversal, potentially losing evidence that a lexical ancestor was a symlink. The candidate resolves `commondir`, searches worktree/common directories for loose and packed refs, and validates relevant lexical paths before canonicalization. New tests cover common packed refs and a symlinked Git-directory pointer. These are local runner correctness and path-safety fixes; the runner was not executed against staging and reports all six effective M12 gates as `UNKNOWN` without reading runtime configuration.

## Validation actually executed

### Local application and helper tests

On Ubuntu WSL, the integrated Laravel tree at ancestor `1da2ee0` ran PHPUnit: **1,413 tests, 20,548 assertions, 0 failures, 25 skipped**. The 25 skips are service/platform prerequisites, not passes: isolated MySQL/PCNTL concurrency cases, an isolated MySQL 8.4 backup service, Moto for S3 fakes, and `sshd` for SFTP fakes were unavailable in that local run. The required database matrix remains the GitHub CI responsibility.

The M147 staging-audit runner suite ran on the same implementation lineage: **8 tests, 55 assertions, 0 failures**. PHP syntax checks passed. PowerShell AST parsing passed for `Assert-ApprovedConnectorBrowserWorktree.ps1` and `Register-ConnectorBrowserWorktree.ps1` before the final E2E setup change; the scripts were not modified after that parse check except that the initializer itself received the Vite prerequisite fix and should be re-parsed in final CI.

At candidate SHA `57ccb42a55c5cbe9220a40530685fa91a2a40173`, `npm test` ran **20 tests, 20 passed, 0 failed, 0 skipped**. `git diff --check` passed. The suite includes the M147-A fixture safety/evidence tests and marketing analytics contract tests.

For the documentation update, `git diff --check`, local Markdown-link existence checks, and a limited secret-pattern screen of the changed diff passed. `gitleaks` is not installed in this local environment, so no local Gitleaks result is claimed; the required repository Quality CI secret scan must run on the final candidate SHA. PowerShell AST parsing passed for the two worktree registration/guard scripts and the updated local initializer.

### Browser and cross-origin E2E

The first full-browser attempt at `18bcfdd` failed before meaningful application assertions: Laravel `/login` and application routes returned HTTP 500 because the isolated checkout lacked `public/build/manifest.json`; `/up` alone was not a sufficient readiness check. The candidate now runs the locked Vite build in local E2E initialization and checks the manifest before starting services. Vite 8.3.4 build succeeded; loopback smoke checks returned `/login` 200 and `/en/pricing` 200.

The full local browser run on candidate SHA `57ccb42a55c5cbe9220a40530685fa91a2a40173` passed **14/14** in Windows Chrome headless against isolated Laravel/SQLite and synthetic WordPress 7.1.3 + Connector 0.4.7 + SQLite services on loopback ports 8317/8318. Coverage in the executed suite: Connector pairing/recovery 3; localization/public/customer UI 8; selected customer/operator/owner boundaries, CSRF, CORS and authentication 3. It used synthetic identities and no external services. Stripe-dependent browser flows were excluded because they require a separately configured public HTTPS Stripe TEST/Mailpit environment; no Stripe request occurred. This is not complete browser or cross-origin assurance: cross-origin coverage is limited to the tested routes and cases, and staging behavior is untested.

The first run was interrupted and its bounded local cleanup was then invoked. A subsequent full run completed and the harness service-state markers were absent; ports 8317/8318 had no listener and no task-owned Apache/PHP service remained. The ignored `.env.e2e`, `storage/e2e/credentials.json`, and local SQLite fixture still exist (metadata confirms they are generated within this fresh dedicated worktree). An attempted broader recursive cleanup was rejected by the execution policy, so these task-owned synthetic files remain local and were not added to Git. No contents were copied into evidence. This local cleanup limitation is disclosed for handoff; it does not indicate that a service remains active.

### CI-REL-01

Historical failures remain visible. Run [39153976426](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39153976426) failed on older PR #129 head `14dec63646d293ad2fec87228b22fa2150dbb676` in the Connector MariaDB `PollLockConcurrencyTest::test_expired_owner_cannot_release_or_refresh_successor_lease`, waiting for `hold-*.result` until the 20-second deadline. Run [39230906190](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39230906190) exposed the same result-publication race on PR #130 head `512412e...`: the worker exited with `JsonException` while the parent could observe a partially written result file. The fix writes a complete JSON payload to a temporary file and atomically renames it; the deadline and lock assertions remain unchanged. Post-fix run [39233009685](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39233009685) passed 7/7 including the MySQL gate, and the current source PR heads also pass their own exact-head CI. The observed file-publication race is fixed in the harness with regression coverage; broader intermittent runner reliability remains subject to exact integration CI and continued monitoring, so CI-REL-01 is not closed by this report.

## Operational boundary and remaining findings

No live staging operation occurred. RO-01–RO-04 remain unexecuted; RO-05–RO-09 remain unauthorized. All six live M12 effective values remain `UNKNOWN`. The local audit runner is not evidence of its execution on staging. M147-E.2b remains `NO-GO / NOT AUTHORIZED`.

AS-16 / M147-F01 operational scanner validation remains open. M147-F02 live M12 verification remains open. M147-F03 live feed provenance/licensing/runtime configuration remains open. M147-F04 scanner side-effect combinations remain open. M147-F05 broader browser/cross-origin and staging integration remains open despite the bounded 14-test local run. M147-F06 independent security review remains open. M147-F08 external M17 penetration-test checkpoint remains open. CI-REL-01 remains open pending review and continued reliability evidence. Existing narrow closures M147-C-02, M147-F07, and M146-F08 are not broadened.

## Candidate disposition

The local candidate is ready for protected PR review with the documented cleanup limitation; its exact-head CI on `66060cbdd1acf0379fb63af416001a898832db72` passed 7/7. A green source-PR run cannot substitute for CI on the integrated head. M14.7 is **not fully validated or closed**: operational scanner evidence, fresh M12 readback, independent review, the external M17 checkpoint, and separately authorized staging work remain outstanding. No M14.8 work is started.
