# Security, idempotency and adversarial review

## Trust boundaries

- Browser provides plan selection, coupon code and billing form data only. Server resolves price, currency, product, tier, interval, promotion eligibility and Stripe Price ID. Reject arbitrary amount/price/customer/subscription ID fields.
- Stripe webhook is trusted only after signature timestamp/tolerance validation and endpoint-specific secret, expected account and test/live mode checks. Never expose webhook secret or raw payload in logs.
- Local customer ownership is enforced by policy/scoped query; never locate account by email alone. `Customer.stripe_customer_id` and `Subscription.stripe_subscription_id` uniqueness are important existing controls.
- Számlázz calls are isolated behind existing provider gates/service credential; tax decision, identity snapshot, environment allowlist and issuance gates must pass.
- Mail is untrusted transport; email links remain signed/expiring and email failure cannot change entitlement.

## Required idempotency keys and serialization

| Operation | Stable key / constraint |
|---|---|
| Checkout | Existing `checkout-intent-{uuid}`; one active intent/subscription selection per customer operation as policy allows |
| Webhook | Unique Stripe event ID; durable receipt status; transition unique on source event/aggregate |
| Subscription command | Immutable operation UUID + verb/version; Stripe idempotency key derived once and persisted |
| Invoice intent | Existing unique Stripe invoice + purpose and intent key |
| Számlázz issue | Existing one attempt/intent and unique external reference; lookup after ambiguous result |
| Billing notification | Aggregate + source event/transition + recipient + template version unique |
| Promotion redemption | Existing reservation/consume under transaction; one-time charge semantics |

Use DB constraints as final arbiter. Redis/cache locks reduce duplicate work but must not be the correctness mechanism. For aggregate mutations, lock subscription/customer in a consistent order, commit local intent, perform provider call outside lock, then reconcile response under lock. Simultaneous cancel/upgrade requests serialize or one is rejected with a visible pending operation.

## Threat review and test evidence required

| Threat | Mitigation | Regression test |
|---|---|---|
| Forged/replayed webhook | Signature verification, tolerance, unique durable receipt, mode/account binding | Bad signature 400; event replay no-op; wrong mode no mutation |
| Price tampering / coupon abuse | Server catalog resolution, immutable price version, reservation rules | Altered POST ignored/rejected; concurrent last coupon yields one redemption |
| Customer subscription takeover / IDOR | Authenticated customer scope and policy; provider identity match | Cross-customer UUID/provider IDs return 404/403 without leak |
| Duplicate charge or plan update | Stable Stripe idempotency operation UUID; operation ledger; readback after timeout | Two workers / unknown response issue one provider request |
| Duplicate invoice | Unique intent/attempt/reference; ambiguous lookup-only | MySQL concurrent submit creates one invoice attempt/provider call |
| OAuth? | Not in M11 scope | N/A |
| Secret/payment data leakage | Redacted categories only; encrypted credentials; no card data stored | Secret-pattern scan; log assertions for webhook/provider exceptions |
| Privilege escalation / direct Owner edit | Ability-gated commands, no status mutation endpoint, audit | Operator/Customer denied; owner cannot force active/paid |
| Unrecoverable event data | APP_KEY/DR readiness dependency; minimized encrypted webhook payload; provider reconciliation fallback | Key unavailable blocks decrypt/recovery without false processing |
| Replay/race/poison retry | Durable state transitions, bounded attempts, leases, dead-letter/reconcile | MySQL independent process contention, stale lease reclaim, poison cutoff |
| Misleading healthy UI | Show stale age, pending/blocked/unknown separately | Provider outage test cannot show current/healthy |
| Email injection / phishing | Framework escaping, fixed template/host allowlists, no raw provider strings | HTML escaping, malicious name, URL host tests |

## Privacy/accounting boundary

Billing identity and event payloads may include personal data. Store only what is needed, encrypt sensitive fields, apply least-privilege access, define event/payload retention with M12 legal policy, and do not include raw address/tax identity in audit. Issued invoice snapshots are immutable. No tax classification/FX/correction rule is invented in M11.

## Fail-closed semantics

Configuration/account mismatch, unknown provider status, stale provider sync, identity conflict, invalid Price ID, unapproved tax rule or ambiguous external invoice result must block privilege increase or issuance. Safe existing paid-through service remains governed by explicit policy. A technical inability to read provider state must not be translated to “canceled” or “paid.”
