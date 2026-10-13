# Invoice and Számlázz.hu plan

## Existing foundation and boundary

Verified components include `BillingInvoice` (Stripe invoice projection), `InvoiceIntent`, immutable billing identity references, immutable/effective-dated seller/tax configuration, invoice intent event/review rows, `InvoiceIssuanceAttempt` unique per intent/external reference, validation and normalized request classes, `InvoiceIntentWorkflow`, `InvoiceIssuanceWorkflow`, submission and lookup interfaces, synthetic provider/tests, Owner invoice-intent UI, and explicit configuration/environment/credential/approved-tax gates. `docs/SZAMLAZZ_HU_INTEGRATION.md` and `docs/TAX_ENGINE_FOUNDATION.md` describe the staged implementation. The current workflow does not mean every real invoice policy is approved or live issuance is safe.

## Authority and processing order

Stripe is authoritative for payment and Stripe invoice facts. A paid Stripe invoice creates or updates a unique CareGrid invoice intent. CareGrid snapshots the exact billing identity version, seller profile/config version, plan/price/currency/discount/payment references and a normalized line description. Tax treatment is supplied only from already approved configuration/accountant decision; M11 does not infer tax law. The workflow validates/dry-runs, enters human review where required, and submits at most once using stable external reference. Provider returns invoice number/PDF reference; persist metadata and expose the document under authorization. Notification is an independent outbox delivery.

## Duplicate prevention and ambiguous outcomes

Unique `stripe_invoice_id + purpose`, unique intent idempotency key, one issuance attempt per intent, unique external reference and unique provider invoice number (when provider supports it) are the local safety net. If submission times out after provider may have issued, mark `reconciliation_required`; perform lookup by the exact external reference. Never resubmit until the provider proves no invoice exists and an authorized operator explicitly releases a new attempt under a recorded reason. If lookup is unavailable, preserve ambiguous state and alert; do not label failed or issue duplicate.

## Retries and outages

Validation/business rejection is terminal until input/review changes. Transient connection failure before submission can be retried only when it is proven no request reached provider. Ambiguous response is lookup-only. Authentication failure stops retry, marks provider unavailable and alerts Owner. Dry-run provider is test-only and may never be presented as a legally issued invoice. M10 `provider.mail` is separate from invoice-provider health.

## Refunds and corrections

M11 default: no automatic refunds. Stripe `charge.refunded` marks related invoice intent and event for manual review. Refund entitlement effect, credit note/storno, correction invoice and tax treatment require explicit approved accounting procedure. If later included, use a unique refund operation and immutable correction-document linkage; never delete/rewrite the original issued invoice or snapshot.

## Billing identity and snapshots

Use immutable `CustomerBillingIdentity` version. At checkout/invoice intent creation pin the exact identity. Later profile edits create a new version and affect only future transactions. Snapshot seller legal identity/profile and applicable effective-dated configuration version. Never silently update already-issued invoice details. Identity/tax fields remain encrypted/restricted and absent from ordinary logs. M12 determines long-term legal retention/deletion.

## Owner experience

Invoice list/intent view exposes state, Stripe invoice reference, intent and attempt IDs, safe error category, tax-review status, external invoice number/reference and last reconciliation. Actions are narrowly permissioned: review, reconcile lookup, approve a configured tax decision, and (if explicitly enabled) start a single submission. No arbitrary amount/customer/Stripe ID editing. Every action has confirmation, rate limit, audit and actor. No arbitrary local “issued” toggle.

## Owner/accountant decision gate

Before automatic live issuance: approve seller profile versions, jurisdiction/tax code/payment method/fulfillment date/FX rules, discount display, invoice language and notification policy; verify Számlázz account environment and test fixture; document cancellation/refund/storno procedure. Until then issuance stays blocked/dry-run and M11 does not claim tax/accounting compliance.
