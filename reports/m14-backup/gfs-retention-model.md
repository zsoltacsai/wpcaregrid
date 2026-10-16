# M14 GFS Retention Model

GFS = daily/weekly/monthly recovery-point classes. This is a proposed domain model derived from roadmap target schedules, not a statement of live deployed retention.

## Model semantics

- Every backup belongs to a site and a class/slot, with creation/completion time, integrity status, entitlement version, source Connector version and dependency references.
- Selection retains the newest verified recovery point in each required class/window; unverified/incomplete artifacts are not advertised as usable points.
- Pre-update and pre-remediation safety backups remain separate protected points until linked operation completion and review; they are not consumed by ordinary package quota.
- Active restore sources, compensation points, last verified recovery point, incident holds and unresolved privacy/legal/accounting/provider blockers are excluded from any deletion candidate.
- Retention calculation produces eligibility and reason codes. It does not call provider deletion. Provider deletion remains a distinct approval, task, request, verification and reconciliation sequence.
- Use UTC slot identities, unique per site/class/window, idempotent scheduling, bounded catch-up, and policy-version snapshot. Restore locks/retention workers serialize selection and execution.
- Deletion can only remove after all policy, recovery, legal/accounting, tenant and provider requirements are resolved; unknown means blocked.

## Roadmap target matrix (not active)

| Product / tier | Cadence | GFS slots retained |
|---|---|---|
| WordPress Basic | monthly | 3 monthly |
| WordPress Business | weekly | 8 weekly + 3 monthly |
| WordPress Secure | daily | 14 daily + 8 weekly + 3 monthly |
| WooCommerce Basic | weekly | 8 weekly + 3 monthly |
| WooCommerce Business | daily | 14 daily + 8 weekly + 3 monthly |
| WooCommerce Secure | daily | 30 daily + 12 weekly + 6 monthly |

Each row also has separate pre-update safety backups. Do not map this model onto current `retention_count`/`retention_days` env values without a migration and an explicit policy decision.

## Approved Owner override boundary and remaining gates

OQ-04 was approved on 2026-10-16: Owner policy may extend protection beyond purchased entitlement, but may not reduce below the purchased plan, protected last-known-good point, active restore requirement, safety-backup requirement, system safety floor or a future legal/accounting minimum. Reductions require an impact preview, create eligibility only and never synchronously delete backups. All restore/safety/legal/accounting/privacy/provider gates remain applicable. Commercial/pricing implications of extensions remain separate product/accounting decisions.

Remaining boundaries:

1. How policy changes apply to active subscriptions, upgrades/downgrades, cancellation and outstanding safety points must be specified in implementation without weakening the approved floor.
2. Post-service retention periods and legal holds remain `WAIT_LEGAL` / `WAIT_ACCOUNTING`; M14 must not guess durations.
3. S3/SFTP versioning, delete-marker, Object Lock, lifecycle and physical absence remain `WAIT_FACT` until provider evidence is recorded.

OQ-04 is a product-policy decision; it is not legal/accounting approval and does not authorize backup deletion or production destructive execution.
