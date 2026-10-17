# M14.4 migration and legacy cutover plan

## Source-to-domain mapping

| Existing source | Current meaning | Planned mapping | Safety rule |
|---|---|---|---|
| `config/pricing.php` `plans.*.*.backup_frequency` | Canonical cadence by product/tier | Preserve as input to unified versioned entitlement catalogue | Do not duplicate plan identities |
| Stripe price / `Subscription.product_type`, `plan`, interval | Active purchased product/tier and billing mapping | Resolve canonical entitlement snapshot from confirmed effective transition | Unknown mapping => review/UNKNOWN, not Basic |
| `config/backup.php` `plans.*.*.retention_count` | Per-environment legacy count; null if unset | Read-only comparison during shadow period; then deprecate as customer-commercial authority | `NULL` never becomes zero |
| `config/backup.php` `retention_days` | Per-environment legacy age cutoff; null if unset | Read-only comparison; migrate only explicit approved non-null values to a review report, not deletion rules | No implicit expiry/candidate |
| `manual`, `pre_update_backup`, `restore` | Current booleans; manual true, pre-update defaults true, restore true plus global restore switch | Preserve facts; make manual entitlement semantics explicit; safety is separate; restore remains multi-gate | No weakening safety or restore switch |
| Backup row/manifest/timestamps | Artifact, status, provider and verification evidence | Add request-time policy/catalogue context and GFS evaluation/protection evidence in later schema if justified | Existing rows start UNKNOWN/review; no PASS by migration |
| `expires_at` | Current per-backup expiry field | Do not treat as authorization; reconcile legacy rows in report before any new authority | Historical NULL remains unknown/no expiry, not expired |
| Platform backup retention | Laravel database/DR artifacts | No mapping | Separate platform subsystem |

## Required safe sequence

1. **Before any new evaluator:** add/verify a distinct, default-off customer backup deletion execution gate or retire `backup:retention` side effects to report-only. Prove the command and its scheduler cannot reach `BackupDeletion::remove()` in M14.4. Do not reuse M12 privacy gates as an undocumented commercial-backup switch.
2. Inventory exact production/staging environment values through approved operations. Planning source proves null defaults but cannot prove deployed env overrides. Record values without exposing secrets. If current retention values are non-null, disable/contain legacy deletion before rollout and preserve a reversible snapshot of configuration.
3. Add schema with conservative unknown state. Migration must not calculate mass candidates, contact provider, enqueue backup/deletion, or alter subscriptions. Existing subscription mapping is backfilled in bounded batches only where canonical mapping is unambiguous; others stay review/protected.
4. Capture current behavior in read-only dual-read comparison: legacy count/day decisions versus target GFS selection. No dual mutating authority; never allow both systems to execute.
5. Create effective-dated entitlement snapshots from Stripe-confirmed existing subscription identity; preserve original billing fields and event idempotency. For missing/internal/legacy mappings, no commercial entitlement is inferred.
6. Store per-backup origin policy context for new requests. Historical backups lacking that context remain explainable as `LEGACY_POLICY_UNKNOWN`; their protection defaults conservative.
7. Shadow/report-only GFS runs on synthetic and bounded staging metadata. Resolve candidate differences and recovery gaps before any future product activation.
8. Rollback: disable new evaluator/scheduling read path; preserve snapshots and history; return scheduler to an explicitly approved prior non-destructive behavior. Do not restore a legacy config that silently re-enables provider deletion.

## Schema concepts (not final migration commitment)

Prefer a normalized immutable `subscription_backup_entitlements` history with subscription/site FK, canonical product/tier, catalogue/policy version, cadence/counts, feature flags, effective interval, transition source/event unique key, and creation time. A bounded per-site evaluation record may contain evaluation version/time, entitlement snapshot ID, source fingerprint, required/satisfied/missing slots and candidate/blocked summaries. Protection relationships should be normalized if independently released and audited; no table per class. Backups can carry nullable `entitlement_snapshot_id` and request policy version for origin explainability. Index by site/effective time, subscription/event key, site/status/verified time and evaluation freshness. Final schema follows existing model and DB portability conventions.

All new eligibility state defaults UNKNOWN. Rollback/reapply tests cover empty DB, current backup rows, unmapped subscription, 0.4.5/legacy data, old policy version and concurrent transition. Migration rollback must not erase immutable audit/source evidence; use additive nullable fields and staged deployment. No migration may execute a provider operation.
