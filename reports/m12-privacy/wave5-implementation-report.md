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
- The first hosted MySQL run exposed an order-sensitive test comparison for a JSON billing-address object: MySQL normalized object-key order while preserving the same data. The assertion now recursively canonicalizes associative keys before comparing snapshots. No production code defect or data mutation was involved; the updated test and full exact-head CI are being rerun.
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

## Guarded release evidence — 2026-10-14

- Implementation PR #50 exact head `398fc6d3b41bceab6460ac9e99ec613ce894bd63` passed all five required CI jobs in run `38646048446`; the only earlier PR-head failure was the MySQL-order-sensitive test assertion described above and was corrected before the green rerun.
- PR #50 merged normally. Merge commit and verified remote primary (`claude/ecstatic-wozniak-oemyuf`) SHA: `436585fdd18403ce3c9f40e5e83185cf87ec98c9`.
- The independent post-merge run `38647578980` exposed a transient-looking Connector MySQL process-harness timeout in `PollLockConcurrencyTest::test_expired_owner_cannot_release_or_refresh_successor_lease` while waiting 20 seconds for a child-process result file. This Connector test was unchanged and passed on exact PR-head CI. The remaining Server MySQL job was still running at report edit time; final run/retry evidence must be appended before closure.
- Staging SSH used `StrictHostKeyChecking=yes`, `HostKeyAlgorithms=ssh-ed25519`, and the pinned host key matching the approved fingerprint `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`.
- The guarded `scripts/deploy-staging.sh` deployed the exact merge SHA `436585fdd18403ce3c9f40e5e83185cf87ec98c9`. The pre-deploy database backup was retained at `/home/deploy/private-backups/wpcaregrid-pre-deploy-20261014T222652Z.sql.gz`. No migration was pending or applied.
- Post-deploy staging checkout is clean at the target SHA. `/up` returned HTTP 200. `system:check` reported application, database, queue, scheduler, Connector API, Stripe TEST, Cloudflare, backup storage, mail, safety, and privacy retention OK. The security scanner remains WARNING because no completed security scan exists. Both `wpcaregrid-default` and `wpcaregrid-backups` workers are RUNNING; scheduler heartbeat recovered. The privacy health reports safe/disabled by policy. `M12_DESTRUCTIVE_RETENTION_ENABLED` is unset and defaults to `false`.
- The authenticated Owner UI validation is still pending. The newly opened Brave window produced an unexpected 404 state before its host could be reliably verified, so browser inspection stopped under the browser-safety rule. No credentials were entered and no Owner action was taken. Complete the synthetic-only Owner UI preview/case validation from a newly opened private window after manually verifying `staging.wpcaregrid.com`, or record that browser E2E remains unverified if unavailable.
- No deletion, anonymization, provider deletion API, production action, customer website action, or customer account mutation occurred during deployment or read-only health verification.

### Post-merge CI retry — 2026-10-14

- The first post-merge attempt of run `38647578980` completed with four successful jobs and one failure: the unchanged Connector MySQL `PollLockConcurrencyTest::test_expired_owner_cannot_release_or_refresh_successor_lease` timed out after 20 seconds waiting for its child-process synchronization result. The same exact SHA had already passed all five PR-head jobs in run `38646048446`.
- A rerun of the failed workflow attempt was started on the unchanged primary SHA `436585fdd18403ce3c9f40e5e83185cf87ec98c9`. The rerun completed successfully across all five mandatory jobs: Connector MySQL `116143445802`, Server SQLite `116143447176`, Quality/security/build `116143447946`, Server MySQL `116143448154`, Connector SQLite `116143504827`.
- The transient process-harness timeout did not reproduce on retry; no code change was needed. The merged Wave 5 application and regression changes remain identical to the exact PR-head CI result.
- Remaining closure evidence: authenticated synthetic Owner UI validation. Guest HTTP probes for the Owner closure and retention routes returned the expected 302 authentication redirects. The browser inspection was stopped when a fresh Brave window showed an unexpected state and the hostname could not be verified before page inspection. A request is pending for a manual Owner sign-in in a new private window; no credentials or account changes have been made.

### Report-only merge and final primary CI — 2026-10-15

- Report-only PR #51 merged with merge SHA and verified remote primary SHA `c80d5665b03d1e8ec4c456f39dc3e8bdc121d01c`. Its exact-head CI run `38649842300` passed all five required jobs.
- Post-merge CI run `38650978542` passed all five mandatory jobs on exact primary SHA `c80d5665b03d1e8ec4c456f39dc3e8bdc121d01c`: Connector MySQL `116149394931`, Server MySQL `116149395104`, Server SQLite `116149395115`, Quality/security/build `116149395157`, and Connector SQLite `116149395226`.
- No source/runtime changes or redeployment were made for this report-only update. The staging deployment remains the implementation merge SHA `436585fdd18403ce3c9f40e5e83185cf87ec98c9`.
- The authenticated synthetic Owner UI exercise remains unverified and is the only outstanding Wave 5 validation item. Therefore this report does not declare Wave 5 closed. Owner decisions ER-01 (hybrid model) and ER-08 (separate erasure request required) are recorded; closure does not start erasure or its cooling-off period.
- Destructive retention remains disabled. No deletion, anonymization, customer/provider mutation, or production change occurred.
