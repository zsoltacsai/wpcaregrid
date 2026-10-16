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

## Open policy boundaries

1. Whether Owner may raise retention beyond purchased entitlement, and whether it can ever be lowered (recommended: no decrease below plan/legal/accounting floor).
2. How policy changes apply to active subscriptions, upgrades/downgrades, cancellation and outstanding safety points.
3. Post-service retention periods and legal holds: WAIT_LEGAL / WAIT_ACCOUNTING; M14 must not guess durations.
4. S3/SFTP versioning, delete marker, Object Lock, lifecycle and physical absence: WAIT_FACT until provider evidence is recorded.
