# Retention Policy and Evaluation State Machines

## Policy-version lifecycle

`draft → previewed → review_pending → approved (where required) → scheduled/effective → superseded`

- **Draft:** editable, not consumed by evaluator.
- **Previewed:** immutable preview binds exact version, source snapshot boundary, clock and impact counts. Any policy edit invalidates preview.
- **Review pending:** legal/accounting/security dependency is unresolved; affected records remain blocked.
- **Approved:** references and approval actor/time exist for all applicable categories. A technical Owner acceptance is not legal/accounting approval.
- **Scheduled/effective:** selected version applies at explicit UTC time; no retroactive mutation of prior evaluations.
- **Superseded:** retained historical version; not edited or deleted.

Wave 4 is report-only in every state. Policy activation changes future evaluation behavior, never executes data lifecycle actions.

## Candidate evaluation lifecycle

`discovered → classified → policy_resolved → evaluated → reported`

At any stage, source/identity uncertainty produces `UNKNOWN` or `MANUAL_REVIEW`; it must not be converted to a no-blocker result. Evaluation is append-only or otherwise immutable with policy version, trigger snapshot and time. Re-evaluation creates a new result linked to the old one.

Result values: `NOT_ELIGIBLE`, `ELIGIBLE_DRY_RUN`, `BLOCKED_ACTIVE_RESTORE`, `BLOCKED_RECOVERY_SAFETY`, `BLOCKED_ACCOUNTING`, `BLOCKED_LEGAL`, `BLOCKED_PROVIDER`, `BLOCKED_POLICY_MISSING`, `MANUAL_REVIEW`, `UNKNOWN`.

There is deliberately no transition to `DELETE`, `ANONYMIZE`, `COMPLETE` or provider deletion from a Wave 4 evaluation.

## Timing and version rules

- Store timestamps in UTC; calculate `eligible_at` from an explicit source trigger and fixed, injectable `evaluated_at`.
- Null duration, null trigger, unsupported unit, conflicting policy versions, non-effective policy or missing approval is blocked.
- Zero duration means candidate becomes eligible at trigger time. It never dispatches an action.
- Preview and report identify exact policy version and data snapshot boundary. Concurrent policy changes do not alter an in-flight run; re-read/version-check before publishing.
- A policy version change does not re-interpret old reports or automatically make newly eligible objects actionable.

## Blocker precedence

1. Unknown or missing source/dependency state → `UNKNOWN`/`MANUAL_REVIEW`.
2. Legal/accounting exceptions or missing approvals → corresponding `BLOCKED_*`.
3. Active restore/update/compensation or last-recovery-point risk → `BLOCKED_*_SAFETY`.
4. Provider state unresolved or inconsistent → `BLOCKED_PROVIDER`.
5. Missing/invalid policy → `BLOCKED_POLICY_MISSING`.
6. Future eligibility time → `NOT_ELIGIBLE`.
7. Otherwise → `ELIGIBLE_DRY_RUN`.

Eligibility is never reported as deletion-complete. More than one blocker may be retained as an ordered code list; the primary status uses the most conservative precedence.

## Future destructive workflow (not Wave 4)

If a later wave proposes execution, it needs a separate state machine and approval. At minimum: `candidate → dry_run → blockers_resolved → approval_recorded → bounded_execution → provider_reconciliation → verified_completion`, with terminal `manual_review/failed` states and idempotent retries. A future executor cannot consume Wave 4 results as authority; it must revalidate source, policy, approvals and active-operation locks immediately before each action. Destructive gate remains false until separately approved.
