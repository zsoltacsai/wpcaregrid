# M14 GFS Retention Model

GFS = daily/weekly/monthly recovery-point classes. This is a proposed domain model derived from roadmap target schedules, not a statement of live deployed retention.

## Model semantics (refined after runtime source audit)

- Every backup belongs to one site. Current backup rows do not contain entitlement version, GFS class/slot, or reasoned protection links; those are implementation gaps, not existing facts. Proposed origin metadata records request-time catalogue/policy version and slot.
- Selection retains the newest complete, verified, usable recovery point inside each required UTC class/window; unverified/incomplete/corrupt artifacts are not usable points. Missing coverage is a recovery gap.
- Pre-update and pre-remediation safety backups remain separate protected points until linked operation completion and review; they are not consumed by ordinary package quota.
- Active restore sources, compensation points, last verified recovery point, incident holds and unresolved privacy/legal/accounting/provider blockers are excluded from any deletion candidate.
- Retention calculation produces eligibility and reason codes. It does not call provider deletion. Provider deletion remains a distinct approval, task, request, verification and reconciliation sequence.
- Use UTC daily `YYYY-MM-DD`, ISO week `YYYY-Www` (Monday start), and month `YYYY-MM`. The rolling set includes current slot and preceding N−1 slots. One backup can satisfy multiple logical class reasons only within its natural day/week/month; never duplicate the physical object. Existing hourly schedule is per site/window and current-window only; GFS-aware idempotency is future work.
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

Legacy `backup:retention` currently has an execution path to `BackupDeletion::remove()` for configured retention. The live staging setting could not be verified in this planning pass; operational state is unknown. Future M14.4 work must make the legacy path report-only/default-off gated before shadow GFS and verify zero provider calls. S3 versioning/delete-marker/Object Lock/lifecycle and physical absence, and SFTP semantics remain `WAIT_FACT`; post-service duration and accounting minima remain `WAIT_LEGAL` / `WAIT_ACCOUNTING`.
