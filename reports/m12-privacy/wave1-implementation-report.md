# M12 Wave 1 — Non-destructive privacy foundation

Status: implementation and local validation in progress. This is not M12 closure and not a legal-compliance determination.

## Verified base

- Primary branch: `claude/ecstatic-wozniak-oemyuf`.
- Remote primary at inspection: `0ac10ad59d300e9e85528509f2d5bbeab5da97a6`.
- Wave 1 branch: `codex/m12-wave1-privacy-foundation`, based on that exact primary SHA.
- No staging or production deployment has occurred in this work.
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

The first full local suite run with a 512 MB PHP memory limit reported 1,189 passed, 2 failed, 1 error and 26 skipped. One failure was the expected system-check count after adding the privacy health item; that test contract was corrected and the focused ProductionHardening test now passes. The remaining two failures and one error are Windows environment limitations: POSIX `0600` file mode assertion (Windows reports a different mode) and a Bash deployment smoke test whose Windows path was not translated for `/bin/bash`; the smoke helper also prints `command -v` errors on Windows. These unrelated portability cases require Linux CI confirmation. This report will be updated after full rerun and exact-SHA CI.

The first exact-SHA Linux CI dispatch (run `38588358397`) exposed a cleanup defect in the new MySQL process-concurrency test: Laravel's `DatabaseMigrations` teardown correctly refused to roll back the privacy migration after the test created its synthetic request. The privacy migration guard remains intact; the test now removes only the request created with its fixed test idempotency key, including its related event/task rows, in `finally` before teardown. A rerun is required before merge.

## Not yet validated

- Full CI on the implementation commit, including the MySQL separate-process race gate.
- Merge and clean post-merge source state.
- Staging deployment, strict SSH host identity verification, migration and Owner read-only UI/system-health check.
- Final Wave 1 completion decision.
