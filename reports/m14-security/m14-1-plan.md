# M14.1 Application Security Plan

**Disposition:** planning and source review complete; implementation and adversarial validation remain open. No runtime code, staging configuration, or production setting was changed in this pass.

## Verified baseline

- Primary: `claude/ecstatic-wozniak-oemyuf` at `039a9ce555909243cd36ff5bd77352d43821915d` (PR #83 owner-decision merge).
- Planning branch: `codex/m14-1-appsec-plan`, created clean from that primary; planning commit is recorded by Git after this document set is staged.
- Staging executable SHA last verified over strict SSH: `a335162936afa2933e348fe7b04956e98e45c5a3`. Approved ED25519 fingerprint matched `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`. Staging was not changed.
- Local PHP CLI is unavailable; `artisan route:list` and runtime tests were therefore not run. Route inventory below is source declarations, not the expanded Laravel route count.
- Owner decisions OQ-01–05 remain approved. No new Owner decision is required for this plan.

## Scope and review method

Reviewed route files and bootstrap middleware; auth guards, session defaults, login/reset/activation controllers; policies and ability checks; rate-limiter registrations and route attachments; Connector authentication; backup/export/billing jobs and representative console commands; existing feature-test coverage; and prior M14 threat, RBAC and tenant-audit records. Source search hits are leads, not findings without a demonstrated trust-boundary failure.

Static declarations: `routes/web.php` 68, `owner.php` 122, `operator.php` 32, `api.php` 13, total 235. These are route declaration counts and may include grouped declarations; verify expanded routes with `php artisan route:list --json` during implementation. Console commands are separately registered. `bootstrap/app.php` declares only the Stripe webhook CSRF exception, appends `SecurityHeaders` and `SetLocale`, and orders Connector auth before throttling.

## Evidence-backed observations

1. Customer, Owner and Operator have distinct session guards and login surfaces, and successful login regenerates the session. Customer logout invalidates it; invalid Owner/Operator roles clear session state. Explicit `can:` abilities cover Owner and Operator routes. Existing route-contract, role, CSRF and foreign nested-object tests provide useful baseline coverage.
2. Customer routes are wrapped by authentication, customer-account, legal-acceptance and recovery-quarantine middleware. Nested website/backup and admin-access bindings use scoped binding in representative high-impact routes. Connector API requires a credential and matching website UUID header; API operations resolve the authenticated connector.
3. Rate limiters cover login, password-email, Connector API/transfer, customer account actions, checkout, Owner external/sensitive actions and Operator restore/remediation/update/backup. Several customer routes still use numeric inline throttles; implementation should normalize only where doing so improves coverage/auditability. Default cache store is database, but deployed cache/session topology must be verified before claiming cross-node enforcement.
4. `PasswordResetController` rotates `remember_token` on reset but no app-level `AuthenticateSession`/`logoutOtherDevices` usage was found. No explicit all-session deletion is present in the reset callback. Whether the rotated token invalidates all active sessions across the configured guards depends on provider/session behavior; prove it with a regression test before classifying exploitability. Track as a P0 validation gap, not a confirmed vulnerability.
5. `TransferBackup` queue payload is an integer backup ID and reloads with global `Backup::find()`. The job accesses the backup’s website relation and its own objects, and no caller tenant is serialized. This is an intentional system workflow candidate; still prove stale/deleted/reparented backup and website ownership invariants at execution time in MySQL/process tests. Static global lookup alone is not evidence of IDOR.
6. Existing tests include foreign backup/admin-access rejection, UUID-vs-numeric route rejection, Owner/Operator permission refusals, route authorization contracts, and CSRF rejection. Full service/job/API object matrix and post-queue permission/relation-change tests remain incomplete.

## Implementation sequence

1. Generate expanded route/command inventory and assign owner, permission, subject scope, CSRF, limiter, re-auth, side effect, and test reference to every state-changing endpoint.
2. Add adversarial two-tenant tests for web/API/Owner/Operator/Connector, nested ID swaps, valid foreign UUIDs, downloads/exports, sensitive reads, and mutation side effects.
3. Add job/command boundary tests for queued IDs, stale relation changes, revoked permissions, deleted parents and retries; only change code where a test proves a defect.
4. Resolve password-reset/activation session revocation semantics across web/owner/operator guards; add concurrent token-use and session invalidation tests. Make a minimal fix only if tests show existing sessions remain authorized after reset.
5. Test role crossover, recent-auth expiry/replay, approval binding, password confirmation and CSRF across all sensitive Owner/Operator actions.
6. Map every public and authenticated mutating route to a limiter, test key dimensions and 429 behavior, then verify shared storage on staging. Bound transfer/body/expensive external operations where evidence shows a gap.
7. Run focused suites, full required CI and strict guarded staging validation on synthetic accounts only. Keep all M12 destructive gates off.
8. Update the matrix and closure report from observed results. Do not treat plan status or static code review as a passing adversarial test.

## Scope boundary

M14.1 covers tenant/object authorization, Owner/Operator RBAC, authentication/session/re-auth, CSRF coverage, output-sink review sufficient to route any finding, rate limiting/resource abuse, and application-layer destructive-gate authorization. Deep Connector SSRF/payload/credential lifecycle, Stripe replay hardening, filesystem/archive safety, full scanner campaign, CI supply-chain audit, hosting compatibility and production DR are later M14/M15/M17 scopes unless review finds a confirmed Critical issue requiring immediate containment.

See the authorization, tenant, authentication, rate-limit and adversarial matrices in this directory. Existing M14 decision boundaries remain in `reports/m14/owner-questionnaire.md`.
