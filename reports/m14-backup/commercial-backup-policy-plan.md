# M14 Commercial Backup Policy — Domain/Entitlement Plan

**Updated after source audit at primary `db272400513882bae8fa8d2ab15d0996cd34dcc7`:** schedule/frequency is canonical in `config/pricing.php`; customer retention values are environment-driven in `config/backup.php` and default `null`. `NULL` means no configured expiry, while a numeric zero is clamped to one by `BackupPolicy`. Critically, scheduled `backup:retention` calls `Retention::apply()`, which can call `BackupDeletion::remove()` when non-null count/day values are configured. The source defaults do not establish deployed staging/production values, and staging env could not be verified in this audit. M14.4 implementation must contain this legacy path before shadow GFS rollout; it is not safe to assume it is report-only. Platform DB backup retention remains separate.

## Proposed domain boundary

Model commercial promises as versioned, server-owned entitlement snapshots per existing canonical product/tier (`wordpress` / `woocommerce` × `basic` / `business` / `secure`) and effective version. Snapshot cadence and GFS counts together with safety/manual/restore feature policy and source transition. Keep price amounts and Stripe identifiers in `PricingCatalog`; extend/adapt its canonical plan identity instead of maintaining a competing retention catalogue in `config/backup.php`. Stripe-confirmed activation and effective plan transitions append immutable snapshots using current event idempotency/effective-time rules. Backups retain request-time policy context. A policy update never silently rewrites history.

### Target policy supplied by roadmap (not yet live entitlements)

| Product | Tier | Cadence | Retained points | Safety backup |
|---|---|---|---|---|
| WordPress | Basic | monthly | 3 monthly | separate pre-update backup |
| WordPress | Business | weekly | 8 weekly + 3 monthly | separate pre-update backup |
| WordPress | Secure | daily | 14 daily + 8 weekly + 3 monthly | separate pre-update backup |
| WooCommerce | Basic | weekly | 8 weekly + 3 monthly | separate pre-update backup |
| WooCommerce | Business | daily | 14 daily + 8 weekly + 3 monthly | separate pre-update backup |
| WooCommerce | Secure | daily | 30 daily + 12 weekly + 6 monthly | separate pre-update backup |

These are approved engineering target values only; they are not implemented/active entitlements or public promises. OQ-04 is already approved. Do not expose as current commercial promise before implementation and explicit commercial rollout/M16 presentation. Legal/accounting and provider gates remain unresolved for post-service/deletion behavior.

## Policy lifecycle distinctions

| Concept | Meaning | Safety requirement |
|---|---|---|
| Entitlement | Package/customer right to cadence and features | server-authoritative; immutable per subscription period snapshot |
| Scheduling | When an eligible backup is requested | unique slot; missed windows do not generate destructive bursts |
| Retention | GFS slot/protection eligibility calculation | deterministic, reportable and versioned; is not approval or provider deletion |
| Protection | Active restore/update dependency, last verified recovery point, safety point, hold | protected object cannot become executable candidate |
| Deletion eligibility | Candidate after all blockers/recovery checks | preview and explicit action approval; global/customer/provider gates remain OFF by default |
| Post-service retention | backups after cancellation/offboarding | WAIT_LEGAL / WAIT_ACCOUNTING; no invented duration; OQ-04 does not set a period |
| Safety backup | pre-update or pre-remediation recovery point | separate from package quota, survives routine retention until dependency clears |
| Provider deletion | request/reconciliation with actual provider semantics | WAIT_FACT until version/delete-marker/lifecycle and absence evidence are verified |

## Owner configuration principles

OQ-04 was approved on 2026-10-16. Owner configuration may extend recovery protection above purchased entitlement (for example, additional retained recovery points, longer approved retention or additional safety protection), but may not reduce below purchased entitlement, the protected last-known-good point, active restore requirements, safety-backup requirements, the system safety floor or any future legal/accounting minimum. A policy reduction requires an impact preview, can create eligibility only, and never synchronously deletes backups. Restore, safety, legal, accounting, privacy and provider gates remain in force. Purchased entitlement is the minimum contractual/product floor; Owner preference cannot override system safety. Any commercial/pricing implications of extensions remain separate product/accounting decisions.

This decision does not set post-service retention duration or resolve legal/accounting schedules. Those remain `WAIT_LEGAL` / `WAIT_ACCOUNTING`; provider physical-deletion semantics remain `WAIT_FACT`.

## M14.4 planning audit refinements

The legacy `backup:retention` scheduled command is an effectful path when count/day env values are non-null. Its current logic protects newest verified point, active restores and pending update dependencies, but has no identified separate customer-backup execution gate. M14.4 first implementation batch must contain/disable this legacy effect before shadow selection. Do not reuse M12 privacy deletion gate as an undocumented commercial-retention switch. Source null defaults are not proof about deployed env. Current backup records lack entitlement/catalogue version, GFS slot membership and reasoned protection relationships. Manual backups are source-enabled for all public plan configs with a 60-minute cooldown, but quota/slot semantics remain undefined. `backup:schedule` runs hourly with a unique site/window key and bounded current-window behavior; it does not implement GFS classification.

`BackupDeletion` records logical object deletion intent, calls the provider and verifies read-after-delete; its audit records provider versions as unknown. This is not proof of physical absence with S3 versioning/lifecycle/Object Lock or SFTP semantics (`WAIT_FACT`). Do not conflate customer-site backups with platform DB backups or M12 privacy retention.

## Migration/rollout work for M14 implementation

Versioned catalogue snapshots from Stripe-confirmed canonical product/tier; conservative existing-account mapping and no default-to-Basic. Never translate null retention env into zero. Dual-read comparison only, zero dual-mutating authority, no mass evaluation/deletion on deployment. CI fixture asserts price/checkout/subscription/snapshot/scheduler contract and six GFS combinations. Apply approved extension-only policy with recent re-auth, impact preview and source fingerprint. Candidate generation starts shadow/report-only. Future provider deletion is a separate authorization/executor and remains disabled; `WAIT_LEGAL`, `WAIT_ACCOUNTING`, `WAIT_FACT` stay explicit fail-closed states.
