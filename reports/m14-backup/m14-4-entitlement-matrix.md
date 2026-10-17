# M14.4 entitlement matrix

**Implementation evidence:** PR #97 merged at `470294f881939480f77fd15d387a4898c2f3a160`; catalogue version `backup-entitlements-v1` is implemented and all six policies are covered by exact-head CI. The target remains engineering policy, not public offer. See [closure report](m14-4-closure-report.md) for release lineage and remaining staging validation.

**All six rows are implemented engineering policy, not automatically active customer promises or public pricing copy.** Canonical identity reuses `product_type` (`wordpress`/`woocommerce`) and tier (`basic`/`business`/`secure`) from `PricingCatalog`.

| Canonical ID | Product | Tier | Cadence | Daily | Weekly | Monthly | Safety backup | Manual | Restore feature | Catalogue |
|---|---|---|---|---:|---:|---:|---|---|---|---|
| `wordpress.basic` | WordPress | Basic | monthly | 0 | 0 | 3 | Separate pre-update safety artifact; outside GFS | Currently enabled by source config; quota semantics TBD | Current plan config says true; runtime restore switch also applies | `backup-entitlements-v1` target |
| `wordpress.business` | WordPress | Business | weekly | 0 | 8 | 3 | Same | Same | Same | Same |
| `wordpress.secure` | WordPress | Secure | daily | 14 | 8 | 3 | Same | Same | Same | Same |
| `woocommerce.basic` | WooCommerce | Basic | weekly | 0 | 8 | 3 | Same | Same | Same | Same |
| `woocommerce.business` | WooCommerce | Business | daily | 14 | 8 | 3 | Same | Same | Same | Same |
| `woocommerce.secure` | WooCommerce | Secure | daily | 30 | 12 | 6 | Same | Same | Same | Same |

`0` is no quota for that slot class, not an expiry instruction. Expected maximum routine logical points are respectively 3, 11, 25, 11, 25, 48. Physical objects can satisfy more than one logical class when they naturally fall within each retained slot; do not multiply object storage by logical reasons.

Entitlement snapshots are immutable and effective-dated. They persist canonical product/tier, catalogue version, cadence, counts, safety/manual/restore feature flags, transition identity, and effective time. Prices and Stripe identifiers remain in `PricingCatalog`. Manual backup v1 semantics are implemented: a verified point can satisfy a natural GFS slot, does not suppress cadence, and receives no permanent protection solely because it is manual. Runtime restore remains additionally constrained by its technical readiness, authorization, entitlement, workflow and safety.

Before activation, test the six entries against `PricingCatalog`, checkout’s Stripe price mapping, Subscription fields, resulting immutable entitlement snapshot, and `backup:schedule` decision. Unknown/internal/legacy product mappings must become explicit review/UNKNOWN and remain protected, never silently map to Basic.
