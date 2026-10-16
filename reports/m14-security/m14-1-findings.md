# M14.1 Implementation Findings and Status

Baseline: planning merge `37f31d0ddd6cc8c62221a5cac20e39b69e6fe45a` on `codex/m14-1-appsec-implementation`. Review date: 2026-10-16. This is a focused application-security implementation record, not a penetration-test or security certification.

| ID | Severity / state | Evidence | Root cause / impact | Resolution / remaining work |
|---|---|---|---|---|
| F-14.1-01 | **High — confirmed; fix implemented; awaiting exact-SHA CI and staging retest** | `PasswordResetController` changed the password and rotated `remember_token`, but did not invalidate persisted Laravel sessions. Regression coverage now creates a persisted session for the user, resets the password, and asserts the session is removed. Login persistence is separately asserted against the database session handler. | Session-guard authentication is based on a guard-specific session key. Rotating the remember token does not revoke an already-authenticated session. An old session could remain authenticated after password reset. | Added `App\\Auth\\SessionRevoker`; password reset atomically updates the credential and removes all database sessions for the user, then logs out all current guards and invalidates the current session. Staff password activation and Owner/Operator CLI role/permission changes also revoke stored sessions transactionally. Focused auth suite: 13 tests / 67 assertions passed. Broad Account/Owner/Operator/Security suite: 457 / 5,666 passed. Final full suite is being rerun after the last guard adjustment. |
| F-14.1-02 | **PARTIAL — stale `TransferBackup` scope still needs adversarial MySQL/process proof** | Runtime inventory enumerates 9 jobs. `TransferBackup` reloads the backup and object relations from storage at execution time; missing or non-`Uploaded` rows no-op. Existing backup transfer/retention tests are in the full server suite. | The job carries only a backup ID and uses the current relationship graph. Local SQLite coverage does not establish behavior under concurrent delete/reparent/duplicate-worker changes on MySQL. | Exact test plan remains: delete after dispatch; website/tenant reparent after dispatch; child object reassignment; duplicate workers; assert no cross-tenant storage key or audit event. Fix only if an adversarial test demonstrates unsafe effects. |
| F-14.1-03 | **PARTIAL — deployment configuration observed; distributed rate-limit behavior not yet demonstrated** | Staging `php artisan about` reports database cache, database queue and database sessions; database and scheduler services are active. Effective staging rate-limit keys and a multi-process counter test remain to be captured. | A process-local cache would permit limiter divergence across worker processes/hosts. Current staging cache driver is shared database cache, but topology-wide behavior is not proven by configuration alone. | Add/run the bounded two-process shared-counter validation on the exact deployed runtime; record no key material or customer identifiers. |
| F-14.1-04 | **PASS — runtime inventory generated** | `m14-1-runtime-entrypoint-inventory.md`: `route:list --json` reports 236 runtime routes; 36 project command classes; 19 scheduled entries; 9 job classes; event/listener scan recorded. | Static source declarations alone undercount runtime route expansion and framework/legacy routes. | Reconcile the generated entries with authorization/tenant matrices and close route inventory only after reviewers confirm mutator mapping. |

## Staging baseline (pre-deploy)

- Strict SSH authentication succeeded for `stg1.wpcaregrid.com`; the stored Ed25519 fingerprint is `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`.
- Current runtime SHA: `a335162936afa2933e348fe7b04956e98e45c5a3`; detached checkout clean.
- Laravel 13.33.0, PHP 8.4.26, `APP_ENV=production`, debug off; MySQL, database cache/queue/session; migrations current.
- MySQL, Supervisor and Nginx active; both configured workers RUNNING; no failed queue jobs; homepage returns 200 after one canonical redirect. The Owner URL was reached through the authenticated browser context.
- `config:show privacy` read back global, profile, backup, provider, bulk and synthetic profile execution gates as `false`.
- `SYNTHETIC_FIXTURE_CONTROLLED_EXECUTION_ENABLED=true` is present on staging; this is a fixture-provisioning control, not the profile anonymization execution gate. M14.1 must prove it cannot invoke destructive privacy execution outside `APP_ENV=testing`; do not treat it as permission to mutate staging customers.
- The previously visible “no completed security scan” warning remains an M14 AppSec residual and must not be suppressed.

## Scope limits and disposition

No M12 gate was changed, no customer/staging record was mutated, and no real provider deletion was called. The current change intentionally requires the database session driver for persisted user-wide revocation; `array` is permitted only under the automated `testing` environment, while unsupported persistent drivers fail closed. Any hosting configuration using another persistent session backend must be reconciled before that environment relies on password-reset/role-change revocation.

No Critical/High status may be declared resolved until the regression suite, exact-head required CI, guarded staging deploy and post-deploy checks pass. Full M14.1 closure also requires the outstanding two-process limiter test and stale transfer-job adversarial coverage.

## Validation update (2026-10-16)

- Added and passed regressions for deleted and no-longer-uploaded queued `TransferBackup` jobs. They are no-ops and do not produce a verification event. Reparenting/foreign-child and simultaneous-worker MySQL behavior remain unproven and are still tracked in F-14.1-02.
- Session-revocation coverage uses the configured JSON session serialization, revokes matching `web`, `owner`, and `operator` guard keys for null-`user_id` legacy sessions, preserves another user's session, and fails closed for unsupported session drivers and a separate session database connection.
- Focused Account/Owner/Operator/Security run: 42 passed, 1,053 assertions. Latest focused session-revoker run: 3 passed, 6 assertions. Backup transfer/retention suite: 26 passed, 176 assertions.
- Full Server SQLite: 1,278 passed, 25 skipped, 18,651 assertions, 0 failures (104.67 seconds). The skips are the existing environment-specific opt-ins; MySQL/process coverage remains required in CI.
- Pint: 9 changed PHP files passed. PHP lint passed across application and feature-test PHP files. Composer validation passed; Composer audit reported no advisories. `git diff --check` and the scoped secret-pattern scan passed.
- F-14.1-03 remains PARTIAL until a bounded independent-process shared-counter check is recorded for the deployed staging cache topology.
