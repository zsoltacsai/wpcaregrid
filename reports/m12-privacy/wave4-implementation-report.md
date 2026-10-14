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
- Corrected migration CI then exposed MySQL JSON object-key normalization in policy watermarks/preview hashes and in one test assertion. Hash inputs now use a recursive canonical JSON encoder (sorting object keys while preserving list order); the RET default test compares typed object fields independently and a regression checks key-order independence. Re-run exact-head MySQL and SQLite suites before merge.
- The next run (`38619134380`, head `f5a0778ce1f5881a176f3569ee87b6f4059c5cdf`) confirmed those fixes at migration level but found the new key-order regression import needed the repository's Pint ordering. The import was reordered; this same run also completes the full MySQL suite, whose final results must be recorded after completion.
- Run `38619562190` passed all five jobs except one remaining `assertSame` that compared a persisted activated JSON object by key order. The 1,232-test MySQL suite had 17,600 assertions and only that one failure; the assertion now checks the `value` and `unit` fields independently. This test-only correction requires another exact-head full CI run.
- Review found that a preview did not bind to other active policy versions and could use a fallback duration if its active policy row was missing. The source watermark now includes all active policy versions/values, preview activation rejects changed policy snapshots, and missing active values return `BLOCKED_POLICY_MISSING` without calculating candidates. Regression coverage verifies both cases and confirms a draft duration changes candidate counts.
- Activated policy content and approval metadata are now immutable at the model layer; only superseding lifecycle fields can change. Draft Owner approval and effective UTC time are recorded at report-only activation after preview, not when a draft is created.
- Activation now acquires policy rows in stable ID order to serialize competing reporting activations and prevent a different category's active version from changing between preview validation and commit. Regression verifies one current version remains per policy key.

## Validation and release evidence

Local validation against an archive of the exact verified `3e48d488ed1fa01f39ef412ebc65e25fb7f1f14d` baseline plus this worktree's changes:

- Wave 4 suite: 13 passed, 391 assertions, 0 failures, 0 skips. Assertions check every RET-01…RET-33 value, category, trigger, type, lock and independent review state; synthetic backup fixtures exercise active-restore, active-update and last-verified-recovery-point blockers.
- Relevant privacy, backup, platform backup, billing outbox, Stripe webhook and Owner SystemStatus regressions: 89 passed, 980 assertions, 0 failures, 1 skip. Skip: `PrivacyRequestConcurrencyTest` requires an explicitly named local MySQL DB; MySQL concurrency remains mandatory in CI.
- Full local SQLite suite: 1,231 tests, 17,350 assertions, 1 failure, 25 skips. The one failure (`ConnectorPackageArtifactTest::test_untracked_connector_source_is_rejected_before_packaging`) requires `.git` metadata, which is intentionally absent from the disposable source archive. Skips are the documented MySQL/PCNTL cases and unavailable local `moto_server` / `sshd` integration services. These checks remain required in exact-head CI. The final +3 assertions are covered by the passing focused Wave 4 run.
- SQLite migration fresh → full rollback → migrate passed on a disposable `/tmp` database. The rollback guard was verified with empty preview/history state; no customer/source data was used.
- Pint: the import-order issue found in run `38619134380` was corrected and the quality job passed in run `38619562190`. Composer audit: no known advisories. Vite build and Gitleaks passed in that run. SQLite server tests and both Connector jobs passed; MySQL migration round-trip passed; MySQL suite reported 1,232 tests / 17,600 assertions / 1 failure from JSON assertion ordering. The assertion is corrected; next exact-head CI is mandatory before merge.
- No staging deploy or staging mutation has been attempted yet. Strict SSH fingerprint verification, exact merged SHA, synthetic-only staging validation, final health checks, and final evidence merge remain pending.

Record final implementation head, PR, exact-head CI run IDs, merge SHA, final primary SHA, staging SHA, synthetic staging checks and final health here before declaring Wave 4 closed.

## Final closure evidence — 2026-10-14

This append-only section records the completed release and staging verification. The earlier pending statements above describe the implementation-time snapshot and remain as historical evidence; this section supersedes them for final status.

### Repository and CI

- Verified implementation branch: `codex/m12-wave4-retention-engine`.
- Implementation head: `e732755ce99667f83ad3d02507aaabb983065ae3`.
- PR #46 merged normally: <https://github.com/zsoltacsai/wpcaregrid/pull/46>.
- Merge commit and verified primary branch SHA (`claude/ecstatic-wozniak-oemyuf`): `6aa3b9bb2308f29148dbffae546f4eaa8a496a76`.
- Exact PR-head CI run `38621754408` passed all five required jobs: Quality/security/build (`116050763894`), Server SQLite (`116050763959`), Server MySQL (`116050763632`), Connector SQLite (`116050763847`), and Connector MySQL (`116050763969`).
- The MySQL suite passed with 1,232 tests and 17,601 assertions. Required MySQL process-concurrency checks, migration fresh/rollback/re-migrate, system check, and production-cache HTTP smoke all passed.
- Post-merge CI run `38623714952` passed all five jobs on the exact primary merge SHA. Job IDs: Quality/security/build `116057428913`, Server SQLite `116057429056`, Server MySQL `116057429215`, Connector SQLite `116057428895`, Connector MySQL `116057428603`. The post-merge MySQL suite also passed with 1,232 tests and 17,601 assertions, including the process-concurrency gate.

### Guarded staging release

- Staging host key was independently scanned immediately before deployment and matched the Owner-approved Ed25519 fingerprint `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`. SSH used `StrictHostKeyChecking=yes` and the pinned `known_hosts` file.
- Pre-deploy staging SHA: `dcd072a89910078bb7ebed308c88bfa6c7957ebf`; the checkout was clean. The documented guarded deployment script deployed the exact green merge SHA `6aa3b9bb2308f29148dbffae546f4eaa8a496a76`.
- The script created and retained protected pre-deploy DB backup `/home/deploy/private-backups/wpcaregrid-pre-deploy-20261014T193920Z.sql.gz` before changing runtime code.
- Migration `2026_10_14_000003_add_m12_wave4_report_only_retention` completed successfully. Application caches rebuilt, both queue workers restarted, the scheduler resumed and reported a healthy heartbeat.
- Post-deploy checkout HEAD is the target SHA. `/up` returned 200; deployment smoke probes passed for public routes, protected paths, and Owner-route guest authentication redirect.
- Post-deploy `system:check --json`: application, MySQL database (zero pending migrations), queue (zero failed jobs), scheduler, Connector API, Stripe TEST, Cloudflare, backup storage, mail, safety, and privacy-retention all `OK`. Both `wpcaregrid-default` and `wpcaregrid-backups` workers were `RUNNING`. Active restores were 0. Privacy health reported `REPORT ONLY`, `PARTIAL` coverage, 29 review-gated policies, and destructive retention `false` / `Off`.
- The existing security-scanner `WARNING` remains: no completed scan and vulnerability data is not configured. This unrelated pre-existing condition is surfaced and was not changed or represented as healthy.

### Authenticated Owner Console validation

- The Owner signed in manually in a newly opened isolated Codex in-app staging tab; the verified page hostname was `staging.wpcaregrid.com`, and the route displayed `Privacy & Retention · WP CareGrid Owner`.
- The page displayed all 33 distinct RET-01…RET-33 policy keys, report-only mode, destructive retention disabled, partial source coverage, actual deletions `0`, and separate Owner/legal/accounting/security review states. Provisional legal/accounting states remained visible; no approval references were fabricated.
- To exercise the draft → preview → reporting-only activation workflow without changing the approved policy value, a staging-only RET-04 v2 draft was created at the existing 30-day value. Its aggregate impact preview was generated at `2026-10-14 19:43:21 UTC`, marked `PARTIAL`, and reported actual deletions `0`. The same-value v2 policy was activated for reporting only. The UI confirmed: “No records were changed.”
- The Owner UI and preview disclose counts only, not customer names, emails, site URLs, raw provider payloads, or accounting content. A database count check after the drill reported 34 policy versions/rows (33 seeded defaults plus the same-value RET-04 v2), 1 preview, 0 active restores, and 0 failed jobs. No customer or synthetic account records were created or changed for this drill; the preview used the engine's aggregate, read-only staging scans. Deterministic synthetic edge cases are covered by automated tests.
- The report-only route exposed no customer retention controls. The reporting activation did not enqueue jobs or call provider deletion APIs.

### Safety and closure conclusion

- No production system was accessed or changed. No customer record, backup, invoice, Connector, Stripe resource, or provider object was mutated or deleted.
- No anonymization, retention cleanup, backup deletion, or provider deletion API exists in the Wave 4 execution path. Staging validation confirmed the destructive gate remains false, actual deletions remain zero, and no restore was active.
- No legal, accounting, or security review state was changed to approved. Missing qualified approvals remain explicit blockers on any hypothetical future destructive action; Wave 4 has no destructive action pathway.
- Wave 4 engineering, required exact-head CI, merge, guarded staging deployment, and authenticated report-only Owner UI validation are complete. The final closure-evidence report commit/PR and its exact-head CI are tracked after this implementation merge; this report-only change does not require another staging deployment.
