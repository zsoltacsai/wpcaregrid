# M12 Wave 5 — Pre-implementation Owner Questionnaire

**Baseline reviewed:** primary `9daad07fc5cff22799f589e38a7842e0ba91dceb` (2026-10-14). This questionnaire contains only Wave 5 product choices not already settled by the M12 decision package. It is not legal, accounting, tax, or security approval.

## Owner decisions

| ID | Category | Question | Recommended default | Alternatives | Why it matters | Reversible? | Owner answer | Legal/accounting review | Implementation impact |
|---|---|---|---|---|---|---|---|---|
| ER-01 | Owner product policy | Which account-closure model should WP CareGrid use? | **Hybrid:** preserve internal identity and relational history where needed; only anonymize/delete separately classified data after approved exceptions and gates | Profile anonymization with selective physical deletion; hard delete wherever technically possible | Hard deletion can break billing, audit, website, incident, backup, and request references; a closure workflow must distinguish erasable data from retained evidence | Policy can change for future cases; completed anonymization is irreversible | **OWNER_APPROVED — Hybrid model (Recommended)** | Legal/accounting exceptions remain subject to existing L3/L4 and C1–C4 reviews | Implemented as a non-destructive plan; no anonymization or deletion in Wave 5 |
| ER-08 | Owner product policy | Does account closure itself start an erasure/cooling-off request, or only restrict access until a separate erasure request is filed? | **Separate erasure request required.** Closure and service restriction do not imply a privacy erasure request | Closure starts the accepted 14-day erasure cooling-off; configurable choice with separate-request default | Prevents service cancellation/closure from being conflated with a data-rights request and preserves a clear request source | Reversible before any later separately approved irreversible action | **OWNER_APPROVED — Separate erasure request required (Recommended)** | Statutory request handling/timing remains subject to L3/L4; this product rule does not limit rights | Implement separate case types and explicit transitions; closure preview remains non-mutating |

## Existing decisions reused; not asked again

- RET-01: 14-day account-closure cooling-off technical default.
- RET-02: anonymize the linked profile where approved and preserve stable internal references as needed.
- RET-03: use a unique non-deliverable replacement identity; release the original email; do not reversibly encode it.
- RET-04 / RET-33: 30-day post-service customer-backup eligibility, separate from active-plan retention; zero means eligible, never synchronous deletion.
- RET-12: provisional 365-day security/audit engineering horizon; qualified review remains outstanding.
- RET-16: separate 14-day erasure-request cancellation window; cancellation/reactivation remains available only before any future irreversible execution.
- RET-17: tombstone/replay architecture accepted; retention duration remains legally/accounting provisional.
- RET-18 through RET-25: destructive retention stays off; dry-run, preview, restore/update/compensation/last-recovery-point protections, and provider confirmation are locked safety rules.
- RET-26 / RET-27: accounting evidence remains separate and immutable; exact retention/field exceptions require accounting/legal review.
- Customer-controlled WordPress content is not modified by SaaS account closure absent a separately authorized operation.

## Review gates that remain open

No new legal or accounting conclusion is recorded by the two Owner answers above. Existing consolidated legal questions L3/L4 and accounting questions C1–C4 continue to cover response scope/timing, erasure exceptions, retained accounting fields, and retention periods. Provider deletion facts remain `WAIT_FACT` until independently evidenced. Wave 5 may create request metadata, policy versions, plans, and previews only; it may not anonymize profiles, delete records/backups, contact providers, or mark a request complete while any required review/reconciliation is unresolved.

**Destructive gate:** `M12_DESTRUCTIVE_RETENTION_ENABLED=false`.
