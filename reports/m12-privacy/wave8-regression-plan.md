# M12 Wave 8 — Final Regression Plan

This is an execution plan; no tests were run in this planning task. Test names are the current repository suites found in the verified primary tree.

## Privacy-focused suites

- `tests/Feature/Privacy/PrivacyFoundationTest.php`
- `PrivacyExportTest.php`, `LegalDocumentWorkflowTest.php`, `RetentionEligibilityTest.php`
- `AccountClosureOrchestrationTest.php`, `ErasureExecutionPlanTest.php`
- `PrivacyTombstoneJournalTest.php`, `PrivacyTombstoneReplayTest.php`, `HistoricalPrivacyRestoreTest.php`, `PrivacyRestoreSafetyTest.php`, `Wave7SyntheticAnonymizationRestoreTest.php`
- `PrivacyRequestConcurrencyTest.php`

Run focused files first and record exact tests/assertions/skips/failures and reasons. Use an explicit disposable MySQL 8.4 DB for the process/concurrency, dump-import and restore gates; SQLite cannot substitute for those.

## Laravel/server functional regression

- Full server SQLite suite and full server MySQL suite.
- Account lifecycle/auth/authorization/onboarding/customer area: `tests/Feature/Account/*` and privacy routes.
- Billing and accounting: billing snapshot, identity concurrency, InvoiceIntent/tax review/issuance, Stripe webhook inbox, billing email outbox and idempotency suites.
- Customer backup and restore: all `tests/Feature/Backups/*`, especially account backups, restore request concurrency, retention, pre-update safety, corruption and storage reconciliation.
- Platform DR: `PlatformDatabaseBackupTest.php`, `PlatformDatabaseBackupMysqlTest.php`, scheduling/recovery mode.
- Monitoring/security: `SystemStatusTest.php`, security scan/incident/remediation/permissions tests and relevant audit assertions.

## Connector and process/concurrency regression

- Connector SQLite suite.
- Connector MySQL suite using real WordPress harness.
- Required server MySQL process-concurrency gate must actually run and pass; the SQLite job's MySQL-only skip is expected and does not replace it.
- Target races: erasure vs restore; erasure vs billing/webhook; erasure vs backup; duplicate privacy worker; duplicate provider task; policy edit vs approval; source update vs approval; closure vs renewal; restore vs retention. Prefer existing deterministic process tests. Add a focused regression only when a reproducible race is found; do not normalize repeated flakes as harmless.

## Quality, supply chain and packaging

- Quality/security/build job: PHP syntax, Pint, Composer advisories, frontend build, npm high/critical advisory check, full-history secret scan and Connector package consistency.
- `git diff --check` and targeted secret-pattern scan for any report changes.
- Verify supported PHP/Laravel behavior through repository CI; do not install dev dependencies in deployed staging tree.

## Staging validation layers

1. Read-only normal staging health/runtime/gates and host-key validation.
2. Authenticated UI read-only validation in a host/environment/identity verified context; browser failure is not a product failure, and authenticated HTTP/service-path checks are an accepted fallback.
3. Isolated synthetic MySQL E2E for mutation/replay scenarios; no live test queue, mail, S3 delete or provider calls.
4. Cleanup verification and post-test normal staging health/gates.

## CI closure gates

GitHub Actions is primary. Exact candidate SHA/tree must pass all five required jobs: Quality/security/build; Server SQLite; Server MySQL; Connector SQLite; Connector MySQL. MySQL process/concurrency must be verified in Server MySQL. GitLab fallback is permitted only for an evidenced GitHub quota/account/runner/service failure and must validate the identical candidate tree. Never merge red CI or use fallback to bypass a defect.

## Failure treatment

Detect → diagnose → root-cause fix → regression test → exact-SHA CI → guarded deploy (runtime fix only) → rerun affected E2E. Stop immediately for customer/production mutation risk, tenant leak, compromised journal/restore safety, gate bypass, financial snapshot change, or uncontrolled deletion. If a flaky concurrency failure repeats, find and repair the synchronization/root cause instead of suppressing assertions or increasing timeouts without evidence.
