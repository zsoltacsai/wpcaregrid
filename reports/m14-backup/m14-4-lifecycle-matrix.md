# M14.4 entitlement and backup lifecycle matrix

**Implementation evidence:** effective-dated entitlement snapshots are recorded through the existing subscription lifecycle (PR #97). GFS evaluation and cadence comparison use the snapshot without deleting on upgrade, downgrade, grace or cancellation (PR #98). Exact-head CI and tests cover canonical mapping/idempotency. Staging Owner walkthrough remains pending; external deletion waits are unchanged.

| Event/state | Existing source behavior | Entitlement treatment to implement | Backup/retention treatment |
|---|---|---|---|
| Checkout / subscription creation | Checkout price resolves via `PricingCatalog`; local row may remain pending until Stripe confirms active/trialing | Create immutable snapshot only at authoritative effective confirmation; source price ID maps to canonical product/tier | No deletion; scheduler only after service + `BACKUP_READY` + safety gates |
| Renewal | Stripe period fields/event IDs synced; catalogue mapping remains canonical | Snapshot/version effective at period boundary only if contract/policy version says so; idempotent by Stripe event/transition key | Re-evaluate slots; no historical point fabrication |
| Paid upgrade | Stripe-confirmed plan/price maps through `PricingCatalog`; current tier fields update after confirmed transition | New snapshot effective at actual transition time | Stronger floor immediately at transition; recalculate gaps; no retroactive backups or deletion |
| Scheduled downgrade | `pending_plan` and `pending_change_effective_at` record future change | Keep current snapshot until recorded renewal/effective time, then append new one | Preview/recompute only; no synchronous cleanup; retain last-good, safety and holds |
| Duplicate/out-of-order Stripe event | `SubscriptionSync` orders events and ignores older/replayed transitions | Unique source event/effective transition prevents duplicate snapshots | Idempotent re-evaluation; no duplicate schedule slot |
| Payment failure / grace | Seven-day grace fields; normal service access bounded by existing entitlement; restore has emergency exception | Do not infer commercial retention change from grace start | Keep backups; no deletion consequence. Scheduling follows existing service-operation access and product decision, not a new retention policy |
| Grace expired / unpaid / paused | Existing service access becomes unavailable; command records grace expiry | Preserve last effective snapshot as history; mark current service state separately | No deletion; protect under unresolved post-service policy where service ends |
| Cancel at period end | Stripe `cancel_at_period_end`, `current_period_end` retained | Current entitlement through existing effective end; append ended/post-service state only at confirmed end | No deletion; post-service desired-duration remains WAIT gates |
| Canceled/offboarded | Subscription status and ended timestamps from Stripe | No new paid backup entitlement after effective end | Keep all points protected pending legal/accounting-approved post-service rule, holds, recovery dependencies and provider facts |
| Internal/legacy/no mapping | Internal plan may explicitly exclude backups; unknown public mapping is not valid paid tier | Explicit `NOT_ENTITLED`, `INTERNAL_POLICY`, or `UNKNOWN_REVIEW`; never default Basic | No commercial schedule/eligibility from guess; preserve artifacts |
| Owner adds extension | OQ-04 | Append audited extension above floor, versioned/effective | Increase protection only; no side effects |
| Owner reduces extension | Existing M14.1 Owner/re-auth patterns available | Fresh re-auth, immutable preview fingerprint, compare-and-set version | Remove only extension reason; recompute; no deletion/queue/provider effect |
| Catalogue policy v2 | No current backup entitlement version domain | New subscriptions use v2 only after product/legal decision on existing customers; snapshots never silently rewritten | Existing snapshots explain old contract; explicit migration/renewal boundary required |

### Temporal source-of-truth rule

Use Stripe-authoritative `product_type`, `plan`, `stripe_price_id`, period times, `pending_plan`, effective time, status, and event ordering already held by `Subscription`. Entitlement transition uses the same recognized effective boundary. Current source does not provide a commercial grandfathering rule for future catalogue-v2 changes; defer that question before such a change is activated. Billing grace is seven days for service semantics, not a post-service retention duration.
