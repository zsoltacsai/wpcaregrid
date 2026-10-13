# Owner decision dossier

Planning recommends defaults so implementation can proceed after explicit approval. These decisions materially affect customer contracts, access or accounting; they are not safely inferable from code. No provider or account settings were changed.

| Decision | Recommended default | Alternatives / tradeoff | Must resolve before |
|---|---|---|---|
| Grace duration | 7 calendar days from first failed renewal; immutable deadline per failure episode | 3 days faster restriction; 14 days more recovery time and unpaid exposure | dunning/entitlement implementation |
| During grace | Keep read access, monitoring/heartbeat/security observation and already scheduled safety backups; block new site/paid expansion; no destructive site action | Keep all plan features (more unpaid exposure); suspend almost all operations (customer safety risk) | entitlement policy |
| Cancellation | At period end by default; service through provider-confirmed paid-through date; no data/site deletion | Immediate cancellation/refund is riskier and requires accounting procedure | commands/UX |
| Reactivation | Allow clearing scheduled cancellation until period end after Stripe confirmation; if already ended, new Checkout | No reactivation after period end simplifies state but adds friction | command flow |
| Downgrade | Effective at next renewal; never delete resources/data; warn and block new resources if over new limit | Immediate prorated downgrade requires product and credit/refund decisions | plan changes |
| Upgrade | Recommended effective after provider-confirmed successful payment; no higher entitlement during pending payment | Immediate access with invoice later increases credit risk; no proration avoids mid-cycle complexity but may surprise | upgrade flow |
| Monthly ↔ annual switch | Apply at renewal, no mid-period dual schedule; annual payment collected at cycle start | Immediate prorated switch is more complex; could use provider schedule | period-switch implementation |
| Proration | Disable customer-visible proration for first M11 release; use next-cycle changes except an approved immediate upgrade path | Stripe proration needs precise invoice/refund treatment | Checkout/change design |
| Refunds | Owner/accountant manual review; M11 records evidence and flags, does not issue automatic refunds/storno | Automatic full/partial refund increases duplicate/accounting risk | refunds remain out of scope until policy |
| Invoice provider | Retain Számlázz.hu groundwork, but live automatic issuance only after accountant-approved profile, tax/FX rules, issuance gates and test evidence | Stripe invoices as legal invoices requires jurisdiction/accounting analysis | live invoice activation |
| Billing Portal | Custom UI owns plan/cancel; Portal only for payment method update initially | Full Portal adds policy divergence and may expose unsupported actions | customer UX |
| Coupon behavior | Keep current allowed once/repeating/forever restrictions and reservation controls; no stacking; changes affect new checkouts only | Stacking makes total price/invoice semantics harder | promotion extensions |
| Annual discount | Preserve current catalog value; label savings only when same-market annual vs monthly total validates; version copy and price together | Market-specific discount can vary | pricing versioning |
| Ended account access | Preserve login, invoice access and recovery/support contact; no website data deletion in M11; M12 determines retention/deletion | Full lockout hurts support and invoice access | offboarding UX |
| Operator visibility | Operational billing restriction only, no financial detail | Wider financial view needs data-access case | role design |
| Past-due safety operations | Never disconnect or delete WordPress site; define any cap on newly scheduled backups only after backup-safety review | Continue all operations vs limit future cost | grace policy |

## Decisions that do not need to block architecture

Stripe remains the single payment provider; the current immutable Stripe invoice is the payment evidence. No generic multi-provider abstraction, usage-based billing, tax-law engine, automatic refund or production release is in M11. Existing Owner/Operator abilities and service-credential patterns should be reused. `Customer.stripe_customer_id` remains the identity link; email is mutable contact data only.

## Approval evidence

Before implementation, capture the selected values and approving Owner/finance role in a versioned policy document/audit record. Never record secret values, card information or live provider credentials in this dossier.
