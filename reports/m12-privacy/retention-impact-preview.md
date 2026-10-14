# Retention Policy Impact Preview — Design

## Purpose

Before a version is activated, show the exact scope it would produce. This is read-only: **Would actually be deleted: 0**. It is not an authorization to act, a legal determination, or a provider response.

## Preview binding

Every preview binds:

- policy key/version and proposed effective UTC time;
- source data snapshot boundary/scan watermark;
- fixed `evaluated_at`;
- categories/triggers and discovery coverage;
- preview creator/reviewer and preview ID;
- counts by result/blocker/exception and incomplete-source warnings.

If policy version, effective date, source inventory or material data changes, mark the preview stale and regenerate. Avoid implying snapshot isolation if the underlying providers cannot provide it.

## Owner view

Example only; values are synthetic:

| Category | Would become eligible | Not yet eligible | Blocked / review |
|---|---:|---:|---:|
| Customer profile | 12 | 6 | 3 manual review |
| Customer WordPress backup | 48 | 14 | 2 active restore; 4 provider unresolved |
| Raw webhook body | 3,481 | 120 | 11 missing approval |
| Rendered email body | 524 | 70 | 5 source unknown |
| Export artifacts | 2 | 1 | 0 |
| Accounting evidence | 0 | 0 | 27 accounting-locked |

**Would actually be deleted: 0.** The figures above are illustrative and are not current system counts.

Display additional panels for newly eligible versus previous effective version, no-longer-eligible, missing policy/source coverage, active restore/update/compensation, last verified recovery-point protection, legal/accounting holds, provider reconciliation, and unknown states. A zero count with incomplete source coverage must be labeled **partial / not a clean bill of health**.

## Privacy and integrity controls

- Aggregated counts only by default; no email, site URL, raw payload, invoice body or credential in preview.
- Drill-down, if later approved, is Owner-only, paginated, purpose-logged, tenant constrained, and shows opaque references with blocker codes.
- Avoid labels or metrics containing customer identifiers.
- Counts use bounded batches and record scanned/skipped/unknown totals; partial processing is explicit.
- Repeated evaluation at the same clock/snapshot/policy version produces deterministic counts and reason codes.
- Do not count unresolved object metadata as deleted/missing without reconciliation evidence.
- Preview export is a separate privacy-sensitive artifact and should inherit secure export access/expiry controls.

## Activation rules

Draft → preview → applicable review → explicit version activation. A material policy change gets a new preview; no path from “save” or “activate” to deletion. During Wave 4 the UI and report show zero actual deletions and no provider delete calls exist.
