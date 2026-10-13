# M11 billing failure matrix

| Failure | Durable expected state | Retry/recovery | Customer effect | Owner/M10 visibility |
|---|---|---|---|---|
| Stripe available, app unavailable | Stripe may have completed Checkout/update; local projection remains stale | Stripe webhook retries; scheduled reconciliation after restore. Never replay stale DB destructive work | Pending state, no false activation; existing paid-through service governed by last confirmed contract | `billing.webhook_stale`, recovery reconciliation |
| Stripe unavailable, app available | Preserve last provider-confirmed state; command/inbox becomes retryable or blocked | Backoff with bounded retries; same idempotency key; no new charge attempt after ambiguous result | Explain provider delay; no duplicate checkout | `billing.provider_unavailable`/sync age |
| Webhook delayed | Receipt/event not yet applied; checkout remains pending | Provider retry and reconciliation fetch | Pending, never “paid” based on redirect | Stale inbox metric |
| Webhook duplicated | Unique event/operation causes one projection and one outbox | Acknowledge duplicate/no-op | One transition/email/invoice | Duplicate counter without alert storm |
| Webhook out of order | Old event cannot regress snapshot | Retrieve canonical current object if conflict | Current confirmed state; temporary sync indication | `billing.subscription_inconsistent` if unresolved |
| Invalid signature / wrong mode | Reject; no trusted receipt or provisioning | No retry for forged event; correct endpoint secret/environment through ops | No entitlement change | Security event, no secret logged |
| Payment succeeds, local commit fails | Stripe truth may be newer; event retries/inbox remains pending | Idempotent reprocess/reconcile | Do not duplicate charge; show processing | Inbox age and retry state |
| DB transaction rolls back | No partial event marker/domain/outbox | Event retry; transactional unit | No half-applied plan | Queue/DB health plus inbox backlog |
| Queue worker crashes | Receipt/outbox lease expires; attempt count retained | Bounded retry; poison state at threshold | Delayed notice/state projection only | Worker/dead-letter signal |
| Invoice provider unavailable before request | Intent remains ready/retryable if no submission reached provider | Backoff; auth error stops; no financial state rollback | Paid subscription remains valid; invoice is pending | `billing.invoice_provider_unavailable` |
| Invoice request outcome ambiguous | Attempt `reconciliation_required` | Lookup by stable external reference; never blind resubmit | Invoice delayed, payment/subscription still correct | Owner review/actionable alert |
| Invoice issued, app response lost | Provider lookup recovers same invoice | Reconcile; do not create second invoice | One invoice | Inconsistent issue alert until repaired |
| Mail unavailable | Notification outbox pending/failed, business state committed | Bounded queue retry; stop on auth/permanent error | Billing remains correct; email delayed | M10 `provider.mail` and `billing.mail_failed` |
| Customer cancels during grace | Provider command serialized; period-end policy wins only after confirmation | Same operation key, reconcile current state | Explicit cancellation date; no data deletion | Audit and command state |
| Recovery payment races cancellation | Subscription lock + provider refresh; no local status overwrite | Apply canonical provider snapshot, preserve separate cancellation flag | Clear resulting schedule | `subscription_inconsistent` if conflict persists |
| Reconciliation mismatches local/provider | Mark stale/reconciliation-required; no privilege increase from local guess | Fetch canonical object and apply shared projector | May see pending/support state | M10 actionable alert, Owner diff |
| Price/catalog changes during checkout | CheckoutIntent pinned to immutable catalog version/price ID | Complete old valid Checkout or expire safely | No silent amount switch | Catalog version in audit |
| Duplicate invoice workers | Unique invoice intent + locked issuance attempt + external ref | One submit; other reads existing attempt | One invoice | Concurrency alert if invariant violation |
| Stripe refund after invoice | Payment/refund facts recorded; invoice correction held | Owner/accountant manual review | Clear “under review”; no auto entitlement rewrite | Refund review event |

No row triggers website deletion, Connector unpairing or broad service shutdown. Every retry class has bounded attempts, jitter, and poison/reconciliation visibility.
