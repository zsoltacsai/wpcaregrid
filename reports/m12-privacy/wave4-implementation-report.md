# M12 Wave 4 — Report-Only Retention Implementation

## Scope and status

Wave 4 implements policy-version metadata, Owner policy drafts, aggregate impact previews, and a deterministic eligibility evaluator. It remains report-only. This report is updated as exact-SHA CI, merge and guarded staging evidence become available.

## Starting baseline

- Primary branch: `claude/ecstatic-wozniak-oemyuf`
- Verified remote primary before implementation: `3e48d488ed1fa01f39ef412ebc65e25fb7f1f14d`
- Work branch: `codex/m12-wave4-retention-engine`
- Staging/deployment: not yet attempted in this evidence snapshot.

## Implemented design

- `PrivacyRetentionPolicy` is extended with typed technical values, independent Owner/legal/accounting/security review states, immutable version links, effective UTC time, approval references and reporting activation evidence.
- Migration `2026_10_14_000003_add_m12_wave4_report_only_retention.php` seeds RET-01…RET-33 from the Owner-approved decision package. All v1 rows use action `report_only`, Owner approval is recorded, legacy compliance status remains `pending_review`, and review-gated states remain `wait_legal`, `wait_accounting`, or `wait_security`. No legal/accounting approval reference is fabricated.
- Owner-only routes use `owner.settings.manage`. Policy editing is draft-only, validates non-negative duration values within technical bounds, and creates a new immutable version. Reporting activation requires an unconsumed, version-bound, source-watermark-bound preview. A stale/tampered preview is rejected.
- `RetentionEligibilityEvaluator` is a pure UTC calculation with constrained results/blockers. It has no model, storage, queue, provider, delete or anonymization dependency. Partial/unknown coverage adds a blocker. Zero days reports immediate eligibility only.
- `RetentionSourceScanner` uses aggregate database reads only. Supported sources include customer backup metadata/service-end/restore/job state, Stripe webhook inbox, billing email outbox payload proxy, platform database backup metadata, local Owner/site audit data, accounting tables, and privacy case counts. It does not read or return PII payload values.
- Customer-profile closure timestamps, external application logs, completed privacy-case lifecycle, generated export artifacts, and trusted staging-synthetic markers are unsupported/unknown. Platform backup `expires_at` is reported only as expired metadata and never as a candidate proof. Customer backup candidates remain blocked by provider-state, legal and security review; active restore/update and last verified recovery-point blockers are surfaced.
- Accounting evidence tables are counted and remain `BLOCKED_ACCOUNTING_APPROVAL`; no period is invented and snapshots are not exposed or changed.
- Owner UI at `/owner/settings/privacy-retention` displays all RET defaults, review states, compliance locks, version history, previews, partial coverage, and zero actual deletions. Customer-facing retention controls are not added.
- System health shows `REPORT ONLY`, actual source-coverage state, review count, and the destructive gate. A disabled destructive state is healthy/safe.
- Reusable decision and browser-safety rules are recorded in `docs/ENGINEERING_DECISION_PROTOCOL.md`.

## Side-effect guarantees

No retention cleanup scheduler, job, source mutation, anonymization, provider deletion client, backup deletion call, or execution callback is implemented. The Wave 4 evaluator has no action path even if the global environment gate is set true. `M12_DESTRUCTIVE_RETENTION_ENABLED` is not changed and must remain false. The impact preview stores aggregate counts and opaque references only.

## Coverage and limitations

Coverage is intentionally `PARTIAL` while any source lacks a trustworthy trigger or inventory. Current schemas have no customer-closure timestamp or synthetic-staging marker; the platform backup `expires_at` alone cannot authorize eligibility; local application logs are not inventoried. Outbox payload age is a proxy and remains `MANUAL_REVIEW`, because the persisted payload is not proven to be a rendered email body. Provider state is never asserted without readback/reconciliation. These limitations are surfaced rather than treated as clean coverage.

## Legal/accounting and safety state

RET-01…RET-33 are Owner-approved technical/product defaults only. Legal, accounting and security review states remain separately represented. Accounting evidence is protected. No `READY_TO_DELETE`, `DELETE`, `ANONYMIZE`, provider completion or equivalent terminal action result exists in Wave 4.

## Defects found and corrected

- The first disposable SQLite rollback exposed an indexed-column rollback-order failure. The migration now drops the new index before dropping the indexed column. Its rollback removes only its own v1 seed rows when no preview evidence or non-seed/later policy history exists; it refuses rollback if preview evidence or later policy records exist. A disposable SQLite fresh → full rollback → migrate cycle then passed.
- Exact-head CI run `38616403865` exposed a MySQL 8.4 identifier-length failure: Laravel's generated composite index name exceeded MySQL's 64-character limit. The migration now assigns the concise explicit name `prp_policy_version_eval_idx`; the required MySQL empty-database/full-rollback/re-migrate CI gate is the regression check and must pass on the corrected head before merge.
- Review found that a preview did not bind to other active policy versions and could use a fallback duration if its active policy row was missing. The source watermark now includes all active policy versions/values, preview activation rejects changed policy snapshots, and missing active values return `BLOCKED_POLICY_MISSING` without calculating candidates. Regression coverage verifies both cases and confirms a draft duration changes candidate counts.
- Activated policy content and approval metadata are now immutable at the model layer; only superseding lifecycle fields can change. Draft Owner approval and effective UTC time are recorded at report-only activation after preview, not when a draft is created.
- Activation now acquires policy rows in stable ID order to serialize competing reporting activations and prevent a different category's active version from changing between preview validation and commit. Regression verifies one current version remains per policy key.

## Validation and release evidence

Local validation against an archive of the exact verified `3e48d488ed1fa01f39ef412ebc65e25fb7f1f14d` baseline plus this worktree's changes:

- Wave 4 suite: 13 passed, 391 assertions, 0 failures, 0 skips. Assertions check every RET-01…RET-33 value, category, trigger, type, lock and independent review state; synthetic backup fixtures exercise active-restore, active-update and last-verified-recovery-point blockers.
- Relevant privacy, backup, platform backup, billing outbox, Stripe webhook and Owner SystemStatus regressions: 89 passed, 980 assertions, 0 failures, 1 skip. Skip: `PrivacyRequestConcurrencyTest` requires an explicitly named local MySQL DB; MySQL concurrency remains mandatory in CI.
- Full local SQLite suite: 1,231 tests, 17,350 assertions, 1 failure, 25 skips. The one failure (`ConnectorPackageArtifactTest::test_untracked_connector_source_is_rejected_before_packaging`) requires `.git` metadata, which is intentionally absent from the disposable source archive. Skips are the documented MySQL/PCNTL cases and unavailable local `moto_server` / `sshd` integration services. These checks remain required in exact-head CI. The final +3 assertions are covered by the passing focused Wave 4 run.
- SQLite migration fresh → full rollback → migrate passed on a disposable `/tmp` database. The rollback guard was verified with empty preview/history state; no customer/source data was used.
- Pint: 12 changed PHP files passed. Composer audit: no known advisories. Vite build: passed. `git diff --check`: passed. Initial CI run `38616403865`: quality/security/build, server SQLite, Connector SQLite and Connector MySQL passed; Server MySQL failed only at the Wave 4 migration's generated index name before tests began. A corrected exact-head rerun is required; initial run must not be treated as green.
- No staging deploy or staging mutation has been attempted yet. Strict SSH fingerprint verification, exact merged SHA, synthetic-only staging validation, final health checks, and final evidence merge remain pending.

Record final implementation head, PR, exact-head CI run IDs, merge SHA, final primary SHA, staging SHA, synthetic staging checks and final health here before declaring Wave 4 closed.
