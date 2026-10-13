# Entitlement and plan-limit model

## Current evidence

Today `SubscriptionStatus::grantsAccess()` allows active/trialing only; `Website::allowsServiceOperations()` and Connector `JobQueue` use that subscription gate, with an explicit PROBONO backup exception. A general plan-feature registry, grace allowance and per-feature limits evaluator were not found in the reviewed source. Plan/price data currently comes from config plus current published `PriceDefinition` overlays.

## Recommended central contract

Introduce a single domain service, e.g. `EntitlementResolver`, consumed by website creation, backup scheduling, Connector job enqueue/delivery, security scanning, monitoring/report access and UI. No scattered `if ($plan === 'business')` branches. A result is typed and explainable:

```text
feature_key, value_type, value, decision, reason,
effective_from_utc, effective_until_utc, catalog_version,
subscription_id, evaluated_at
```

Feature keys are stable identifiers, not translated labels. Examples: `websites.max`, `backup.frequency`, `backup.retention`, `security.scan.frequency`, `updates.automation`, `monitoring.level`, `woocommerce.enabled`, `reports.history_days`, `maintenance.access`. M19 AI features are future catalog entries only, not part of M11 implementation.

## State-to-entitlement policy

- `active`/`trialing`: versioned plan feature map applies.
- `cancel_scheduled`: current paid features through provider-confirmed period end.
- `grace`: conservative, explicit subset of already contracted service; proposed continue read-only dashboard, monitoring/heartbeat, alerts, security observation, and existing scheduled safety backups; block additional sites and premium actions after first failed payment if Owner approves. Never terminate website or delete backup.
- `suspended/restricted`: deny new premium mutations; maintain account/billing/invoice access and safety-critical observation for an approved bounded period. Restore requires explicit Owner policy and normal restore gates.
- `ended`: account/invoice history access according to retention policy; no new paid feature jobs; no automatic deletion/offboarding in M11.
- unknown/inconsistent/stale: do not grant a higher feature tier. Do not unexpectedly revoke a paid-through entitlement solely because webhook processing is delayed; show stale state and reconcile.
- PROBONO/internal: explicit internal plan mapping, not coerced to public tier; preserve existing internal limits.

## Limits and over-limit semantics

| Limit | Enforcement | Downgrade/legacy behavior |
|---|---|---|
| Website count | Atomic admission transaction locks entitlement/customer; refuse only new site creation above limit | Existing websites remain connected; Owner/customer sees count and upgrade/remediation actions |
| Backup frequency/retention | Scheduler reads effective plan entitlement each dispatch; never schedule more than policy allows | Existing verified recovery points remain; retention changes cannot mass-delete without separate retention safety |
| Scan frequency | Enforce at scheduling and job creation | Existing findings and evidence stay visible |
| Update automation | Gate at enqueue and delivery, as Connector job queue already rechecks service eligibility | Pending jobs are cancelled/replanned safely; no arbitrary replay |
| Storage | Soft warning threshold then hard block for new growth only after explicit policy | Never delete backups to enforce limit without approved retention rules |
| WooCommerce support | Capability gate in Connector/backend and plan | Do not alter WordPress data when access changes |

Distinguish hard limits (cannot create additional resource), soft warnings (approaching threshold), grandfathered state (existing contracts), and scheduled limit changes. Downgrade does not delete existing websites or reduce backup safety. Grandfathering must have a finite, audited policy and no silent plan mutation.

## Storage and snapshot

Prefer immutable plan/catalog version plus subscription's effective version references; resolve all feature values from that version, not today's mutable plan row. Store an entitlement decision snapshot only where it is needed for audit or stable asynchronous job authorization; otherwise calculate from canonical rows with a versioned cache. Jobs store the feature requirement and the policy/catalog version at creation, but delivery rechecks current entitlement and records a reason if no longer allowed.

## Tests

Unit tests for each state/feature mapping and grace boundary (UTC frozen time), value types and unknown state. Feature tests for atomic website cap under two concurrent requests, job enqueue/delivery recheck, downgrades preserving existing sites/backups, PROBONO unchanged, cache invalidation after commit, and every protected route/action. MySQL process tests for resource-limit races. No UI-only enforcement.
