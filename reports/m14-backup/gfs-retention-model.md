# M14 GFS Retention Model

**Implementation status (2026-10-17):** M14.4 shadow evaluator is implemented in PR #98 and deployed at `29bdd9489eafd6581d9d5b9bc5d7a12fe26b4209`. It calculates GFS slots, recovery gaps, additive protections, and blocked potential candidates without deletion. Synthetic-only staging walkthrough remains pending. See [M14.4 closure report](m14-4-closure-report.md).

GFS = daily/weekly/monthly recovery-point classes. The v1 catalogue and evaluator are implemented engineering policy; they are not public commercial promises and do not authorize deletion.

## Model semantics (refined after runtime source audit)

- Every backup belongs to one site. Backup requests now retain the applicable entitlement snapshot; shadow evaluations persist the entitlement policy version, selected slots, protection reasons, recovery gaps, and source fingerprint. Historical backups are not rewritten.
- Selection retains the newest complete, verified, usable recovery point inside each required UTC class/window; unverified/incomplete/corrupt artifacts are not usable points. Missing coverage is a recovery gap.
- Pre-update and pre-remediation safety backups remain separate protected points until linked operation completion and review; they are not consumed by ordinary package quota.
- Active restore sources, compensation points, last verified recovery point, incident holds and unresolved privacy/legal/accounting/provider blockers are excluded from any deletion candidate.
- Retention calculation produces eligibility and reason codes. It does not call provider deletion. Provider deletion remains a distinct approval, task, request, verification and reconciliation sequence.
- Use UTC daily `YYYY-MM-DD`, ISO week `YYYY-Www` (Monday start), and month `YYYY-MM`. The rolling set includes current slot and preceding N−1 slots. One backup can satisfy multiple logical class reasons only within its natural day/week/month; never duplicate the physical object. The hourly schedule comparison is non-authoritative and does not queue a second job for a GFS gap.
- Selection/evaluation is distinct from deletion. Proposed output includes `KEEP`, `ELIGIBLE`, `BLOCKED`, `UNKNOWN` plus recovery gaps. Unknown means not eligible. M14.4 rollout must ensure the existing `backup:retention` path cannot mutate while new logic is shadow/report-only.

## Roadmap target matrix (not active)

| Product / tier | Cadence | GFS slots retained |
|---|---|---|
| WordPress Basic | monthly | 3 monthly |
| WordPress Business | weekly | 8 weekly + 3 monthly |
| WordPress Secure | daily | 14 daily + 8 weekly + 3 monthly |
| WooCommerce Basic | weekly | 8 weekly + 3 monthly |
| WooCommerce Business | daily | 14 daily + 8 weekly + 3 monthly |
| WooCommerce Secure | daily | 30 daily + 12 weekly + 6 monthly |

Each row also has separate pre-update safety backups. These are target policy only, not active customer promise. Current `config/backup.php` count/day values default null; null means no configured expiry, numeric zero is clamped to one, and deployed env values were not established. Do not map target onto current values without migration. Current `Retention::apply()` may call provider deletion if values are configured, so first batch must contain the legacy side effect.

## Approved Owner override boundary and remaining gates

OQ-04 was approved on 2026-10-16: Owner policy may extend protection beyond purchased entitlement, but may not reduce below the purchased plan, protected last-known-good point, active restore requirement, safety-backup requirement, system safety floor or a future legal/accounting minimum. Reductions require an impact preview, create eligibility only and never synchronously delete backups. All restore/safety/legal/accounting/privacy/provider gates remain applicable. Commercial/pricing implications of extensions remain separate product/accounting decisions.

Remaining boundaries:

1. How policy changes apply to active subscriptions, upgrades/downgrades, cancellation and outstanding safety points must be specified in implementation without weakening the approved floor.
2. Post-service retention periods and legal holds remain `WAIT_LEGAL` / `WAIT_ACCOUNTING`; M14 must not guess durations.
3. S3/SFTP versioning, delete-marker, Object Lock, lifecycle and physical absence remain `WAIT_FACT` until provider evidence is recorded.

OQ-04 is a product-policy decision; it is not legal/accounting approval and does not authorize backup deletion or production destructive execution.

## Source separation and gates

The customer site backup engine (`app/Backups`, `backups`/`backup_objects`, S3/SFTP customer artifacts) is separate from `config/platform_backup.php` and platform DB disaster-recovery retention. The M12 privacy gate is also a separate domain; do not reuse it implicitly as the customer commercial retention gate.

Legacy `backup:retention` is report/comparison-only after PR #96 and cannot call `BackupDeletion::remove()`. Exact staging readback at SHA `29bdd9489eafd6581d9d5b9bc5d7a12fe26b4209` showed all six plans' legacy count/day values are null. S3 versioning/delete-marker/Object Lock/lifecycle and physical absence, and SFTP semantics remain `WAIT_FACT`; post-service duration and accounting minima remain `WAIT_LEGAL` / `WAIT_ACCOUNTING`.
