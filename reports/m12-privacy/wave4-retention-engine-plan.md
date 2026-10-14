# M12 Wave 4 — Report-Only Retention Eligibility Engine

**Status:** planning only; no Wave 4 implementation in this change.  
**Planning baseline:** remote primary `133c0ff7d6118c043f9d59f1d5aabd8dd1eb79df`, verified 2026-10-14.  
**Wave 1:** formally CLOSED for engineering/foundation scope by the appended Owner disposition in `wave1-implementation-report.md`.  
**Wave 4 target:** deterministic, auditable eligibility reports; no deletion, anonymization, provider mutation or scheduled cleanup.

## Verified starting point

The repository has a `PrivacyDataCategoryRegistry` with 13 technical categories and explicit provisional retention/deletion labels. `PrivacyRetentionPolicy` stores UUID, policy key/version, category, trigger, nullable duration/unit, action, approval state, legal/accounting review references, effective/review times and enabled state. `RetentionAction` includes `retain`, `report_only`, `anonymize`, and `delete`; `PrivacyRetentionPolicy::isExecutable()` requires the global `DestructiveRetentionGate`, enabled/approved policy, positive duration, past effective time and legal reference, plus accounting reference for immutable evidence. The gate defaults false. The migration is additive and refuses rollback while privacy evidence exists.

The code search found `isExecutable()` exercised by a feature test and no application consumer or retention executor. Current customer backup retention is separately implemented under `app/Backups/Retention.php` and `app/Console/Commands/Backups/ApplyRetention.php`; Wave 4 must inspect/report its eligibility without changing that behavior. Do not treat this as evidence of a unified retention engine. Platform-backup expiry metadata is not proof of an automatic deletion path. No staging runtime was inspected or mutated for this planning task.

## Objective and boundary

Answer: “Which records/objects would be eligible under this exact policy version at this evaluation time, and what prevents or qualifies them?” The report is evidence for later human review. Wave 4 must not dispatch a deletion job, mutate source/provider state, call provider delete APIs, anonymize identity, rewrite accounting snapshots, or enable `M12_DESTRUCTIVE_RETENTION_ENABLED`.

There must be no `DELETE` result state. “Eligible” means a candidate for a later separately approved lifecycle, not permission or instruction to remove data. A zero-day duration means eligibility begins at the trigger instant; it never means execute immediately.

## Evaluator contract

For each candidate, resolve only from authoritative source state and produce:

- opaque candidate/object reference and category (no unnecessary names, email, domain, raw payload, or secret);
- exact policy key/version, action class, approval state and effective timestamp;
- trigger code, source trigger timestamp, calculated `eligible_at`, and fixed `evaluated_at` in UTC;
- result state, bounded blocker/exception codes, provider/reconciliation state, and required review/approval types;
- stable explanation code for repeatable tests and an audit reference.

Suggested states: `NOT_ELIGIBLE`, `ELIGIBLE_DRY_RUN`, `BLOCKED_ACTIVE_RESTORE`, `BLOCKED_RECOVERY_SAFETY`, `BLOCKED_ACCOUNTING`, `BLOCKED_LEGAL`, `BLOCKED_PROVIDER`, `BLOCKED_POLICY_MISSING`, `MANUAL_REVIEW`, `UNKNOWN`. Blockers take precedence over eligibility; uncertain source/provider state fails closed to `UNKNOWN` or `MANUAL_REVIEW`. Never infer missing trigger dates or approval from display text.

## Evaluation order

1. Enumerate only allowlisted categories/data sources; enforce tenant scope where the subject belongs to a customer.
2. Classify candidate using the technical registry; unknown category → `UNKNOWN`.
3. Select one effective policy version by category + trigger + evaluation time. Missing, conflicting, disabled, draft, or unapproved rules → `BLOCKED_POLICY_MISSING` / review state.
4. Check legal/accounting approvals and exception references. Accounting evidence without approved accounting reference → `BLOCKED_ACCOUNTING`; missing legal review where the rule requires it → `BLOCKED_LEGAL`.
5. Calculate eligibility with an injected evaluation clock: `eligible_at = trigger_at + duration`; duration zero may be eligible now. Null duration, invalid unit, missing trigger, or incomparable timestamps → blocked/unknown.
6. If `eligible_at > evaluated_at`, return `NOT_ELIGIBLE`.
7. Check active restore, update, compensation, export, unresolved provider operation, legal/accounting exception, last verified recovery point and other dependencies. Any missing safety state blocks; never treat absent lock data as “no lock.”
8. Emit a read-only evaluation and aggregate preview, pinning policy version and source snapshot/time. No work is enqueued.

## Policy lifecycle and Owner defaults

Use immutable versioned records with an explicit UTC effective time. Proposed lifecycle: `draft → previewed → review_pending/approved → scheduled/effective → superseded`. Draft edits do not affect active reports. A material policy change requires a fresh impact preview bound to that version and a deliberate activation; no activation auto-deletes. Existing evaluations keep the policy version used at their evaluation time. Do not retroactively reinterpret a case unless a new explicit evaluation is recorded.

The full 33-item Owner questionnaire is in `retention-defaults-questionnaire.md`. Owner-configurable values are separate from compliance-locked values and locked engineering safeguards. Legal/accounting decisions stay provisional after technical default acceptance. No per-tenant override in initial scope.

## Data-source plan

Implement a registry/adapter boundary over the existing categories, then add read-only discovery in controlled increments:

1. privacy policy rows and account/service state;
2. customer backup records + current retention semantics, restore references and object metadata;
3. platform backup metadata as its own category and recovery scope;
4. webhook raw body versus idempotency evidence;
5. rendered email content versus minimized delivery metadata;
6. export artifacts, logs, security/audit and synthetic staging records only after the source inventory is verified.

Do not build broad table scans that fetch payload bodies or secrets just to count candidates. Prefer indexed IDs, timestamps and state fields. Use bounded keyset pagination and a stable snapshot boundary. The exact source models must be revalidated during implementation; Wave 1 category labels are not proof that every source table is connected.

## Owner interaction

Owner Console → Settings → Privacy & Retention, designed in `owner-retention-policy-ui.md`. Owner may draft operational settings and inspect policy/health evidence. Compliance-locked rules are visibly read-only. Wave 4 may provide read-only preview/audit only; it must not expose a “delete now” control. The final impact view must display **Would actually be deleted: 0**.

## Wave 4 non-goals and safety

- No migration or executable code in this planning task.
- No retention scheduler, provider deletion client, anonymization service, retention command, or destructive UI action in Wave 4.
- No live provider read/write is required to plan; provider states may be unknown or mocked in tests.
- No customer-facing legal claim or exact statutory deadline.
- No account-level or global platform backup cleanup.
- Keep destructive gate false in code/config/runtime; do not use Wave 4 to change it.

## Dependencies, acceptance and evidence

Wave 4 report-only implementation can begin against unset/unapproved rules if it represents them as blocked. Before using live preview, source models/states and tenant boundaries must be verified. Owner acceptance is needed for operational defaults; qualified legal/accounting reviews are required for provisional categories and any later destructive policy. Acceptance criteria and tests are in `wave4-test-plan.md`; risks are in `wave4-risk-register.md`.

**Ready for implementation:** yes, as a read-only/report-only evaluator. **Destructive execution:** explicitly out of scope and remains separately blocked.
