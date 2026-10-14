# M12 Wave 5 — Account Closure & Erasure Orchestration

**Status:** implementation/validation in progress; no closure verdict recorded yet.
**Verified implementation base:** `9daad07fc5cff22799f589e38a7842e0ba91dceb`.
**Read-only staging baseline before deployment:** `6aa3b9bb2308f29148dbffae546f4eaa8a496a76` (clean server worktree, Laravel 13.33.0, `/up` returned HTTP 200).
**Owner decisions:** ER-01 hybrid; ER-08 separate erasure request required. See `wave5-owner-questionnaire.md` and `final-decision-register.md`.

## Scope and safety boundary

Wave 5 creates request metadata, a typed anonymization plan, and a read-only aggregate impact preview. Owner may restrict customer-area access for a distinct account-closure case after all active subscriptions have ended through the separate billing workflow. The reversible restriction sets the existing customer access flag inactive, records privacy and admin audit events, and can be restored by Owner unless a separate erasure request is active. It does not cancel billing, revoke Connector pairings, alter a site, anonymize a User/Customer, delete backups, invoke a provider, or mark a case completed. The global destructive retention gate remains `M12_DESTRUCTIVE_RETENTION_ENABLED=false`. No migration was required: the existing request type and status columns are strings with room for the new labels.

## Implemented design

- `account_closure` and `erasure` are separate privacy request types. An account closure case cannot enter the erasure cooling-off or retention-analysis states. Closure does not imply cancellation or account deactivation.
- Erasure workflow now has explicit `cooling_off` and `retention_analysis` labels plus distinct legal/accounting blocked states. The state machine has no transition to `completed`, `failed`, `ready_for_anonymization`, provider deletion, or destructive execution. Owner UI does not let an Owner mark identity verified without a separately implemented verification evidence flow.
- Owner-only routes can record closure or erasure case metadata and produce a preview from a customer UUID. The preview shows counts and opaque references, never names, emails, addresses, tax identifiers, site URLs, invoice payloads, or customer content. State changes use POST, CSRF, Owner ability checks, validation and the existing append-only privacy/audit event paths. Closure access restriction is independently audited, reversible, and blocked while any service-granting subscription remains active; it cannot be conflated with subscription cancellation or erasure.
- The typed anonymization plan separates profile fields, stable relational references, immutable invoice/accounting evidence, customer-controlled WordPress content, and provider reconciliation. It contains intended action codes and review states, not source values or a mutator.
- Email-release helper creates a random UUID-backed `@privacy.invalid` replacement. No anonymization code consumes it in Wave 5.
- Backup preview uses service `ended_at` plus RET-04 (30 days), separate from active-plan retention. It reports the next technical eligibility timestamp and candidate count, plus restore, active-operation, last-recovery-point, provider, legal, and security blockers. Zero days would only make a backup technically eligible; no synchronous deletion path exists.
- Adversarial review found that a stale but unfinished backup could otherwise be counted as a technical candidate and that the initial age calculation did not account for artifacts completed after service end. The planner now calculates only verified/expired objects, starts the retention age from the later of service end and artifact completion, and surfaces unfinished/failed/corrupt states for manual review. A regression test covers both cases.
- Accounting evidence remains a retention exception pending qualified accounting approval. Legal/security review and provider facts remain explicitly pending.

## Owner UI

`Owner Console → Privacy & Retention → Account closure & erasure cases` provides a UUID-based aggregate preview and a case-recording form. The case detail screen offers non-destructive state transitions and a separately audited, reversible customer-area access restriction for an account-closure case after service-granting subscriptions have ended. Restoration is blocked while a separate erasure request is active. This control does not cancel billing, revoke Connector pairing, alter a WordPress site, or erase data.

## Validation

Validation is being run in the isolated worktree from the exact verified primary. The host PowerShell environment does not expose PHP; WSL PHP 8.4 is used. Composer dependencies are installed from the locked manifest in an isolated Linux test copy because extracting into the Windows-mounted worktree was unusually slow. This setup is local only and is not part of the repository.

Local validation completed in an isolated test copy from this worktree:

- Focused `AccountClosureOrchestrationTest`: **11 passed, 74 assertions**.
- Full applicable SQLite suite: **1,218 passed, 25 skipped, 17,502 assertions**. Skips are environment-gated MySQL/process-concurrency or isolated MySQL 8.4 suites, plus provider tests requiring `moto_server` or `sshd`; no claim is made that those skipped integrations passed locally. Required hosted CI remains pending.
- Laravel Pint: **875 files passed**; PHP syntax lint: **604 application and test PHP files passed**.
- `composer audit --locked`: no known security advisories.
- Frontend production build: passed with Vite 8.3.1 in the isolated Linux copy.
- `git diff --check`: passed; repeat before commit.
- A local credential-pattern scan of changed source files found no credential-like values. The Gitleaks executable was unavailable locally; hosted secret-scan CI remains required.
- Local Gitleaks executable was unavailable; hosted secret-scan CI remains required.

Exact-head CI, merge, guarded staging deployment, and synthetic staging Owner UI validation remain pending. Staging and production have not been mutated by this implementation so far.

## Remaining review and facts

- Legal: existing L3/L4 handoffs; response scope/timing, profile erasure scope, tombstone duration and audit/security retention remain unapproved.
- Accounting: existing C1–C4 handoffs; invoice/seller/tax/payment evidence fields and retention period remain unapproved.
- Provider: deletion capability/regions/contract facts remain WAIT_FACT. No provider API was called.
- Operational: no authenticated staging E2E has yet been performed for the Wave 5 page. A fresh isolated browser tab was directed only to the explicitly entered staging URL and redirected to Owner login; authentication was not available in that context. No pre-existing tab was inspected. After guarded deployment, use the Owner-authenticated path only after hostname, environment, application, and identity are verified; otherwise use authenticated HTTP/CLI checks.

## Evidence

To be completed after local tests, CI, merge, and synthetic staging checks. Expected invariant: zero deletions, zero anonymizations, zero provider deletion calls, zero customer mutations, zero production changes, and destructive retention OFF.
