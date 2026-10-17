# M14.4 entitlement matrix

**All six rows are approved engineering targets, not implemented entitlements, active customer promises, or public pricing copy.** Canonical identity reuses `product_type` (`wordpress`/`woocommerce`) and tier (`basic`/`business`/`secure`) from `PricingCatalog`.

| Canonical ID | Product | Tier | Cadence | Daily | Weekly | Monthly | Safety backup | Manual | Restore feature | Catalogue |
|---|---|---|---|---:|---:|---:|---|---|---|---|
| `wordpress.basic` | WordPress | Basic | monthly | 0 | 0 | 3 | Separate pre-update safety artifact; outside GFS | Currently enabled by source config; quota semantics TBD | Current plan config says true; runtime restore switch also applies | `backup-entitlements-v1` target |
| `wordpress.business` | WordPress | Business | weekly | 0 | 8 | 3 | Same | Same | Same | Same |
| `wordpress.secure` | WordPress | Secure | daily | 14 | 8 | 3 | Same | Same | Same | Same |
| `woocommerce.basic` | WooCommerce | Basic | weekly | 0 | 8 | 3 | Same | Same | Same | Same |
| `woocommerce.business` | WooCommerce | Business | daily | 14 | 8 | 3 | Same | Same | Same | Same |
| `woocommerce.secure` | WooCommerce | Secure | daily | 30 | 12 | 6 | Same | Same | Same | Same |

`0` is no quota for that slot class, not an expiry instruction. Expected maximum routine logical points are respectively 3, 11, 25, 11, 25, 48. Physical objects can satisfy more than one logical class when they naturally fall within each retained slot; do not multiply object storage by logical reasons.

The entitlement value should be immutable and effective-dated. Persist canonical product/tier, catalogue/policy version, cadence, counts, safety/manual/restore feature flags, source transition/event identity, and effective timestamps. Keep prices and Stripe identifiers in `PricingCatalog`; no prices belong in the backup entitlement snapshot. Manual backup remains enabled by current `config/backup.php`, but whether it consumes GFS quota or satisfies a slot is a product-policy gap; do not activate a new commercial claim until resolved. Runtime restore remains additionally constrained by `backup.restore.enabled`, M14.3 readiness, authorization, entitlement, workflow and safety.

Before activation, test the six entries against `PricingCatalog`, checkout’s Stripe price mapping, Subscription fields, resulting immutable entitlement snapshot, and `backup:schedule` decision. Unknown/internal/legacy product mappings must become explicit review/UNKNOWN and remain protected, never silently map to Basic.
