# M12 Wave 1 — Non-destructive privacy foundation

Status: M12 Wave 1 engineering foundation deployed and validated on staging. This is not overall M12 closure and not a legal-compliance determination.

## Verified base

- Primary branch: `claude/ecstatic-wozniak-oemyuf`.
- Remote primary at Wave 1 implementation start: `0ac10ad59d300e9e85528509f2d5bbeab5da97a6`.
- Wave 1 branch: `codex/m12-wave1-privacy-foundation`, based on that exact primary SHA.
- At implementation start, no staging or production deployment had occurred. The final staging release is recorded below.
- Existing M8/M9/M11 evidence and separate worktrees were left untouched.

## Implemented on the work branch

- A technical handling registry for account, profile, billing/accounting evidence, website, backup, security, monitoring, audit, webhook, email, session and platform-backup categories. Entries describe technical purpose codes, source systems, operational ownership, provisional retention/deletion treatment, candidate export/erasure handling and approval requirements. These are engineering labels only; they do not declare a lawful basis or retention duration.
- A provider fact registry scoped to current technical integrations. Region, contract/DPA, deletion capability and international-transfer review remain explicitly unverified/unreviewed.
- Additive database foundation for privacy requests, append-only request events, provider reconciliation task records and versioned retention-policy metadata. Foreign keys restrict removal of customer/request evidence. The migration refuses rollback while any of its tables contain records.
- Idempotent privacy request/task services, constrained state transitions, safe metadata/error codes, actor authorization and audit events. Provider tasks record work state; they contain no provider deletion client and make no external calls.
- Tenant-scoped request viewing policy, Owner ability and read-only Owner Console list/detail views. Only GET routes are exposed; the pages do not display request narratives or customer email addresses.
- Destructive-retention gate defaults false and accepts only the literal boolean `true`. The Owner system health check reports the safe disabled state; it does not enable or execute retention.
- Regression tests for state, idempotency, tenant boundary, read-only routes, append-only event model, provider-task state/audit, provider facts and fail-closed gate. A separate-process MySQL race test is included in the required CI concurrency gate.

## Safety and scope

No export, rectification, erasure, retention cleanup, provider deletion, legal-document publication, acceptance capture, lawful-basis selection, retention duration or customer-facing privacy claim is implemented. Existing invoice/accounting snapshots are not mutated. The code does not enable a destructive path; the gate remains off. No real-customer data or provider mutation was used.

## Validation evidence

The focused privacy/Owner/system checks passed locally: **35 tests, 1,025 assertions** before the system-check contract assertion update; then Privacy, Owner SystemStatus and ProductionHardening checks passed: **42 tests, 41 passed, 1 skipped, 525 assertions**. The skip is the separate-process duplicate-request test because local Windows configuration is SQLite; its explicit condition requires an explicitly named local MySQL test database and skips before migration otherwise. CI includes that test in the required MySQL process-concurrency gate.

The first full local suite run with a 512 MB PHP memory limit reported 1,189 passed, 2 failed, 1 error and 26 skipped. After fixing the new system-check expectation, the final full local Windows run reported 1,190 passed, 1 failed, 1 error and 26 skipped (1,218 total). The remaining failure is an existing POSIX `0600` file-mode assertion, which Windows represents differently; the remaining error is an existing Bash deployment smoke test whose Windows path was not translated for `/bin/bash`. They are platform-specific limitations and were covered by the successful Linux CI jobs. The focused privacy/Owner/system checks passed: 42 tests, 41 passed, 1 skipped, 525 assertions. The skip requires a separately configured local MySQL database and ran in CI's MySQL concurrency gate.

The first exact-SHA Linux CI dispatch (run `38588358397`) exposed a cleanup defect in the new MySQL process-concurrency test: Laravel's `DatabaseMigrations` teardown correctly refused to roll back the privacy migration after the test created its synthetic request. The privacy migration guard remains intact; the test now removes only the request created with its fixed test idempotency key, including its related event/task rows, in `finally` before teardown. The corrected exact-SHA run `38590540024` passed before merge.

## Final status

The required exact-SHA CI, normal merge, guarded staging deployment, additive migration, live system checks, worker/scheduler checks, and public HTTP checks are complete. The authenticated Owner UI was not exercised interactively because the available browser session belonged to an unrelated WordPress site; Owner access control and read-only behavior passed automated tests, and the staging guest route redirects to authentication. No legal determination or destructive privacy action is claimed.

## Final CI, merge, and staging validation

### Exact-SHA CI

- Corrected exact-head run: GitHub Actions run `38590540024`.
- Tested head SHA: `fbec1b74635df99d42c94a107e90b063eafab779`.
- Result: all five required jobs passed: Quality, Connector MySQL, Connector SQLite, Server MySQL, and Server SQLite.
- Server MySQL suite: 1,218 tests and 17,156 assertions passed. Its required separate-process MySQL concurrency gate and final production-cache HTTP smoke checks also passed.
- Server SQLite suite passed; the MySQL-only process-concurrency gate is intentionally not run in SQLite.
- Quality passed Pint, PHP syntax, dependency audits, frontend build, and Gitleaks.
- Earlier runs are retained as history: `38588358397` had the wrong workflow input for the repository's primary branch, and `38588848105` exposed the test fixture teardown issue. The final run tested the corrected pushed head and passed.

### Merge and deployed release

- PR #39: <https://github.com/zsoltacsai/wpcaregrid/pull/39>.
- PR head: `fbec1b74635df99d42c94a107e90b063eafab779`.
- Normal squash merge commit and resulting primary SHA: `dcd072a89910078bb7ebed308c88bfa6c7957ebf`.
- Staging deployed that exact primary SHA through `scripts/deploy-staging.sh`; deployment performed no production or customer-site changes.
- Strict SSH verification used the pinned staging Ed25519 fingerprint `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`. The remote checkout was clean at baseline `bb3b88d7846992ac35f52fa1842b868d23d3af4b` before deployment.
- The guarded deployment retained its pre-deploy database backup at `/home/deploy/private-backups/wpcaregrid-pre-deploy-20261014T154829Z.sql.gz` and applied the additive M12 migration successfully.

### Post-deployment evidence

- Deployed Git HEAD equals `dcd072a89910078bb7ebed308c88bfa6c7957ebf`; remote tracked/untracked worktree status was clean.
- Migration `2026_10_14_000002_create_m12_privacy_foundation_tables` is recorded as `Ran`.
- `system:check`: application, database, queue, scheduler, Connector API, Stripe, Cloudflare, backup storage, mail, safety switches, and privacy-retention safety are OK. Privacy status is `SAFE / DISABLED BY POLICY`; security scanner remains WARNING because no completed security scan exists.
- Both required Supervisor workers restarted with new PIDs and are RUNNING; scheduler heartbeat recovered.
- Staging homepage and `/up` return HTTP 200. The public/guest request to `/owner/privacy-requests` redirects with 302, as expected for authentication-protected Owner routes. The live browser session available during validation was on an unrelated WordPress site, so no authenticated Owner UI walkthrough is claimed. Owner authorization, tenant isolation, and read-only behavior are covered by the green automated suites.
- No privacy request or provider-deletion task was created, and no export, erasure, retention deletion, or provider API mutation was performed.
- Global/customer restore gates and M8 runtime configuration were not changed by this release.

### Final disposition

Wave 1 implementation, automated regression, exact-SHA CI, normal merge, and guarded staging deployment are complete. This closes only the non-destructive M12 Wave 1 engineering foundation; it does not close M12 and makes no legal-compliance determination. Export, erasure, retention execution, provider deletion, legal-document publication, lawful-basis selection, and retention duration remain out of scope and disabled pending the separate legal/Owner approvals defined in the M12 plan.
