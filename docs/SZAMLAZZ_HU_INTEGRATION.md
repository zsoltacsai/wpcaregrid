# Számlázz.hu invoice preparation

## Phase 1 boundary

**PHASE 1 DOES NOT ISSUE INVOICES.** The application has no Számlázz.hu submission method, HTTP client, or Owner “Issue invoice” action. The provider binding is a local dry-run adapter. Its only output is local validation against a pinned local XSD; it never means that Számlázz.hu accepted anything. Stripe invoices remain the payment-system record. The tax engine has no executable approved decision yet, so paid invoice intents normally stop at `blocked_tax_review`.

This feature does not determine VAT, AAM, OSS, reverse charge, place of supply, or an invoice tax code. VIES data and customer country are evidence only. A future implementation requires accountant-approved, versioned tax decisions and effective seller profiles.

## Existing event boundary

`StripeWebhookHandler` performs Stripe signature verification, event deduplication, and transactional dispatch. `InvoiceEvents::paid()` is the canonical integration point: after the Stripe-authoritative `BillingInvoice` has been applied, it records at most one intent for the Stripe invoice and purpose. Failed invoices do not create paid invoice intents. `charge.refunded` creates a review marker only; it never creates a correction invoice. Refunds that arrive before the paid invoice are correlated by PaymentIntent when the intent is created later.

The invoice-intent idempotency key is a deterministic SHA-256-derived value from the Stripe invoice ID and purpose. A unique database constraint on `(stripe_invoice_id, purpose)` and a unique idempotency key are the final guards. Intent creation runs in a transaction serialized on the persisted `BillingInvoice` row; competing insert collisions use a locking/current read to retrieve the committed winner. Queue dispatch is after commit. Stripe event idempotency remains the webhook handler’s responsibility. Billing identity snapshots are included only when the referenced identity belongs to the same application customer; mismatches are blocked without copying the other customer’s identity.

## Intent lifecycle

The initial state is `pending`. Preparation freezes an encrypted commercial snapshot, including the billing identity version, effective seller-profile version, Stripe invoice totals, and subscription facts. Missing identity becomes `blocked_identity`; a missing or unverified seller profile becomes `blocked_seller_profile`; otherwise the absence of an approved tax decision becomes `blocked_tax_review`. These blocked states do not change checkout, tax, or payment amounts.

Future states are reserved for a separately approved phase: `ready_for_dry_run`, `dry_run_validating`, `dry_run_validated`, `dry_run_failed`, `issuance_disabled`, and `reconciliation_required`. `issued` and `failed` are reserved labels; Phase 1 cannot issue. A successful dry run, if an approved decision boundary is added later, is explicitly `LOCAL_VALIDATION_PASSED`, never provider acceptance.

| Stripe event | Phase 1 behavior |
| --- | --- |
| `invoice.paid` | After the billing snapshot is applied, create one intent. Zero-total paid invoices are represented too; invoice obligation is unresolved. |
| `invoice.payment_failed` | Persist the payment failure through existing billing logic; create no paid invoice intent. |
| Recovered `invoice.paid` | Resolve to the same Stripe invoice idempotency key; do not create another intent. |
| `charge.refunded` | Add a human reconciliation marker; never issue a correction automatically. |

## Provider boundary and official contract

The `InvoiceProvider` interface in this phase deliberately has only local `validate()` and fixture `parseResponse()` methods. It has no `submit`, issue, retrieval, PDF, cancel, correction, or reconcile operation. Later provider operations must be introduced behind an explicit, separately reviewed interface and runtime gate.

The official [invoice request documentation](https://docs.szamlazz.hu/agent/generating_invoice/request) describes an HTTPS POST to `https://www.szamlazz.hu/szamla/` using `multipart/form-data`, with the XML file in `action-xmlagentxmlfile`. The official [XML documentation](https://docs.szamlazz.hu/agent/generating_invoice/xml) publishes the request structure and XSD. The official [request basics](https://docs.szamlazz.hu/agent/basics/sending-requests) describe the operation fields and response handling. Official [rounding/response documentation](https://docs.szamlazz.hu/agent/generating_invoice/rounding) documents response headers such as `szlahu_szamlaszam`, net/gross totals, `szlahu_error`, and `szlahu_error_code`. Official [error handling guidance](https://docs.szamlazz.hu/agent/basics/error-handling) warns against blind retries and documents bounded attempts. Official [currency documentation](https://docs.szamlazz.hu/agent/generating_invoice/currencies) requires exchange-rate and bank data for foreign-currency invoices.

The locally vendored XSD was retrieved from the official XSD URL in the XML documentation (download SHA-256: `90AF7504BAB00E92BCB84971ED3088D9B7C67DD70219148DABE454E32A3B5498`). Trailing whitespace was normalized for repository hygiene; local SHA-256: `825A2051583987FBE55BCAC73C3BEC28B07F09F2A8E1300C317654A61424F43C`. Review/update this snapshot against the official source before any future provider integration. The XML serializer uses the official element order, XML escaping, integer minor-unit amounts, and local schema validation with network access disabled.

The published invoice request/XML docs reviewed for this phase do not establish an invoice-create idempotency key or a guaranteed duplicate-submit behavior. Do not infer one from the separate receipt API. A future submission integration must treat a timeout after sending as ambiguous, stop automatic resubmission, and reconcile against provider records using a documented external reference or manual review. No provider response body or raw XML is persisted in Phase 1.

## Configuration and credentials

Configuration is in `config/szamlazz.php`. Defaults are fail-closed:

```dotenv
INVOICE_PROVIDER=szamlazz_hu
SZAMLAZZ_PROVIDER_ENABLED=false
SZAMLAZZ_DRY_RUN=true
SZAMLAZZ_ISSUANCE_ENABLED=false
```

The credential reference points to the existing encrypted Owner Integration credential allowlist (`szamlazz_agent`). Runtime use is explicitly unsupported in Phase 1. The blank `.env.example` identifiers describe configuration only; no credential value is included. The dry-run adapter does not read or transmit the credential.

## Owner monitoring and audit

Owner → Billing → Invoice intents is read-only and gated by `owner.billing.invoices.view`. It shows payment reference, amount/currency, status, safe reason, and transition history. It omits encrypted commercial snapshots, tax identifiers, provider payloads, and credential data. There is no retry, tax-approval bypass, or issue action. Intent transitions are recorded without sensitive snapshots or provider payloads. Job logs contain only intent IDs and fixed error categories.

## Storage and retention

The additive migration creates `invoice_intents`, `invoice_intent_events`, and `invoice_intent_follow_up_markers`. Stripe invoice/purpose and deterministic idempotency key are unique. Legal identity and seller/tax facts in `commercial_snapshot` use Laravel encrypted-array storage. Historical invoices with no linked identity remain null; there is no inferred backfill. Invoice intents are append-only operational/audit records. Rollback drops only these new tables and their constraints.

Provider metadata is an allowlisted parsed summary only; raw XML, SOAP/API body, credential, VAT ID, and full provider payload are not saved. The official source may change, so the pinned schema must be reviewed before a future release.

## Zero totals, refunds, and recovery

An applied Stripe `invoice.paid` with zero amount is recorded with its actual Stripe amount. Whether and how a zero-value transaction must be invoiced remains an accountant decision. A failed payment creates no paid intent. A later successful event for the same Stripe invoice is idempotent. Refunds are marked for reconciliation; Phase 1 does not create cancellation/correction documents.

## Requirements before real issuance

1. Accountant-approved seller registrations/effective dates, service classification, place-of-supply and tax treatment, tax codes, invoice wording, tax points, and zero-value policy.
2. A persisted, versioned and approved tax decision tied to the exact billing identity, seller profile, product, invoice, and rule version.
3. Provider contract review for authentication, required account settings, invoice request fields, response formats, rate limits, timeouts, reconciliation, PDF/XML retrieval, and correction behavior.
4. Explicit invoice idempotency/ambiguous-submit operations policy; no blind retry after an unknown outcome.
5. Security and privacy review for credential use, data minimization, retention, access logs, and Owner operations.
6. Dedicated test account end-to-end verification, then separately approved release and runtime controls.

The future HU Tax Advisor Agent remains a separate advisory boundary. Its output cannot approve tax treatment or trigger issuance without a human-approved, versioned decision.
