# M14 Commercial Backup Policy — Domain/Entitlement Plan

**Current state:** schedule/frequency is canonical in `config/pricing.php`; customer retention values are environment-driven in `config/backup.php` and default `null`, meaning no configured expiry/deletion policy. `BackupPolicy`, `Retention`, `backup:schedule`, `backup:retention`, backup manifests and Owner platform-backup settings are distinct. Platform DB backup retention is a separate system and must not be conflated with customer backup package entitlements.

## Proposed domain boundary

Model commercial promises as versioned, server-owned `BackupEntitlement` values per product/tier/effective version: backup cadence, retained daily/weekly/monthly point counts, pre-update safety-backup policy, manual-backup eligibility, restore eligibility and policy version. The Connector receives an executable schedule/entitlement snapshot but cannot create policy. Checkout/product assignment and pricing read the same canonical catalogue. A policy update creates a new version; existing snapshots and protected recovery points remain explainable.

### Target policy supplied by roadmap (not yet live entitlements)

| Product | Tier | Cadence | Retained points | Safety backup |
|---|---|---|---|---|
| WordPress | Basic | monthly | 3 monthly | separate pre-update backup |
| WordPress | Business | weekly | 8 weekly + 3 monthly | separate pre-update backup |
| WordPress | Secure | daily | 14 daily + 8 weekly + 3 monthly | separate pre-update backup |
| WooCommerce | Basic | weekly | 8 weekly + 3 monthly | separate pre-update backup |
| WooCommerce | Business | daily | 14 daily + 8 weekly + 3 monthly | separate pre-update backup |
| WooCommerce | Secure | daily | 30 daily + 12 weekly + 6 monthly | separate pre-update backup |

These are a planned target model only; do not expose as current commercial promise before implementation, Owner approval, legal/accounting review where applicable and M16 presentation.

## Policy lifecycle distinctions

| Concept | Meaning | Safety requirement |
|---|---|---|
| Entitlement | Package/customer right to cadence and features | server-authoritative; immutable per subscription period snapshot |
| Scheduling | When an eligible backup is requested | unique slot; missed windows do not generate destructive bursts |
| Retention | Class/count/date eligibility calculation | deterministic, reportable and versioned; is not a provider deletion confirmation |
| Protection | Active restore/update dependency, last verified recovery point, safety point, hold | protected object cannot become executable candidate |
| Deletion eligibility | Candidate after all blockers/recovery checks | preview and explicit action approval; global/customer/provider gates remain OFF by default |
| Post-service retention | backups after cancellation/offboarding | WAIT_LEGAL / WAIT_ACCOUNTING; no invented duration; OQ-04 does not set a period |
| Safety backup | pre-update or pre-remediation recovery point | separate from package quota, survives routine retention until dependency clears |
| Provider deletion | request/reconciliation with actual provider semantics | WAIT_FACT until version/delete-marker/lifecycle and absence evidence are verified |

## Owner configuration principles

OQ-04 was approved on 2026-10-16. Owner configuration may extend recovery protection above purchased entitlement (for example, additional retained recovery points, longer approved retention or additional safety protection), but may not reduce below purchased entitlement, the protected last-known-good point, active restore requirements, safety-backup requirements, the system safety floor or any future legal/accounting minimum. A policy reduction requires an impact preview, can create eligibility only, and never synchronously deletes backups. Restore, safety, legal, accounting, privacy and provider gates remain in force. Purchased entitlement is the minimum contractual/product floor; Owner preference cannot override system safety. Any commercial/pricing implications of extensions remain separate product/accounting decisions.

This decision does not set post-service retention duration or resolve legal/accounting schedules. Those remain `WAIT_LEGAL` / `WAIT_ACCOUNTING`; provider physical-deletion semantics remain `WAIT_FACT`.

## Migration/rollout work for M14 implementation

Versioned catalogue migration/backfill from existing pricing frequency; conservative defaults for existing accounts; never translate null retention env into zero. Dual-read reconciliation, migration rollback, stale job policy version checks and no mass delete on deployment. CI fixture asserts plan vs checkout vs connector entitlement and all six GFS combinations. Apply the approved extension-only override policy with impact preview and safety floors. Start in report/preview mode; execution is separate, remains disabled under M12 and unresolved provider facts.
