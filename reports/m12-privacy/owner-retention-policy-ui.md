# Owner Console — Privacy & Retention Policy Center

**Navigation:** Owner Console → Settings → Privacy & Retention. Follow existing Owner Settings authorization, audit, form validation, flash-state, and layout conventions. The UI is a design only in this planning task.

## Information architecture

1. **Account Closure** — RET-01 cooling-off; account/service state distinction.
2. **Customer Data** — profile handling and anonymization strategy, RET-02/03.
3. **WordPress Backups** — active-plan retention separate from post-service RET-04 and RET-33; active restore dependencies.
4. **Platform Backups** — separate policy and recovery-point protection; never inherit customer-account retention.
5. **Exports** — link expiry RET-05 and artifact expiry RET-06.
6. **Webhooks** — raw payload RET-07 versus event/idempotency evidence RET-08.
7. **Email** — rendered body RET-09 versus minimized delivery metadata RET-10.
8. **Logs & Audit** — application identifiers RET-11 and security/audit RET-12 distinctly labeled.
9. **Staging/Test Data** — synthetic-only lifecycle RET-13.
10. **Accounting Evidence** — compliance-locked RET-26/27, display-only.
11. **Provider Deletion** — retry/manual review RET-14 and provider state; no delete action in Wave 4.
12. **Safety** — gate status, restore/update/compensation/last recovery point guards RET-18–25. Read-only in Wave 4.
13. **Policy Versions** — draft, preview, approval, effective date, superseded history.
14. **Impact Preview** — read-only counts and blocker breakdown bound to a version/snapshot.

## Every setting’s visible metadata

Show current value, recommended default, category, trigger, version, effective date/time, status/approval, required legal/accounting/security review, non-secret decision reference, last changed by/at, and reason for any current blocker. Present UTC as authoritative and local time as a display conversion. Never render raw candidate PII in aggregate views.

## Three distinct control classes

- **Operational Owner settings:** validated value and effective time can be drafted by Owner. Save creates a new draft version; it does not activate or delete. Include safe minimum/maximum, unit, allowed choices, and a clear “eligibility only” explanation.
- **Compliance-locked policy:** show `LOCKED BY APPROVED ACCOUNTING/LEGAL POLICY`, approved value, approval reference and effective date. No editable input and no way to reduce below the approved minimum. If no approved value exists, show `PENDING REVIEW` and block affected candidate decisions.
- **Engineering safety guard:** show immutable status such as `ALWAYS ON` / `DESTRUCTIVE RETENTION OFF`. No Owner toggle or environment override via this screen. Report-only output remains available.

## Save/activation flow

`Edit draft → validate → save version → generate impact preview → review → activate effective version`. A change to a duration or trigger must report newly eligible, no-longer-eligible, newly blocked and unresolved counts. It must not perform cleanup. Existing evaluations retain their prior version. Any legal/accounting-gated version remains provisional until its approved reference is attached through the reviewed approval workflow.

Manual backup/download/restore controls are not part of this page. A policy preview cannot invoke queue/provider mutation. Wave 4 UI does not contain a destructive action.

## Required interface states

- `No approved policy`: operational recommendation visible; evaluation blocked as policy missing/review pending.
- `Draft`: not used by evaluator.
- `Preview ready`: counts pinned to policy version, source snapshot and `evaluated_at`.
- `Review required`: legal/accounting/security dependency shown without implying approval.
- `Effective`: read-only current version; future evaluations use it.
- `Superseded`: historical and immutable.
- `Source unavailable`: show unknown/partial and fail closed; never display healthy/zero candidates as if source was checked.
- `Destructive execution`: always OFF/absent in Wave 4; no button.

## Audit and access

Audit draft creation, changes, previews, activation, review reference changes, export/download of reports, and failed authorization. Store actor, timestamp, policy version, changed field codes and non-secret reference. Do not store secrets, raw customer data or full request bodies. Reuse Owner authorization and existing audit conventions; test direct route access, CSRF, role denial, and concurrent version edits.
