# Számlázz.hu billing and invoice integration

## Phase 2 — billing settings and guarded provider adapter

This section supersedes the Phase 1 provider-interface description below. Phase 2 adds typed Owner billing settings, immutable effective-dated configuration versions, per-intent tax proposals, a separately gated Számla Agent adapter, one-attempt issuance orchestration, and read-only reconciliation. It does not activate tax decisions or invoice issuance.

### Status boundary

| State | Meaning in this milestone |
| --- | --- |
| **TECHNICALLY IMPLEMENTED** | Owner settings, provider code registry, version snapshots, pending accountant-review proposals, local XML/XSD validation, provider adapter, issuance attempt lock, safe ambiguous outcome, and read-only lookup are implemented. |
| **LEGALLY APPROVED** | **No tax rule is approved by this code.** Seller registration, service classification, place of supply, VAT code, wording, tax points, currency conversion, and zero-value policy still require accountant/legal approval and an explicitly versioned decision. |
| **ACTIVATED** | **No.** `provider_enabled=false`, `dry_run=true`, and `issuance_enabled=false` remain fail-closed defaults. There is no Owner invoice-issue action or queue path that bypasses those gates. |

### Owner billing settings and history

Owner → Settings → Billing & Tax contains typed settings for seller profile, invoice language, payment method, payment deadline, fulfillment-date policy, optional invoice prefix, customer notification, foreign-currency handling, tax configuration mode, default provider tax code, and effective date. Only the settings allowlist is accepted. Secret values and invoice payloads are not settings fields; the Agent key remains in the existing encrypted Service Credentials store under `szamlazz_agent`.

Every accepted change creates an immutable `billing_tax_configuration_versions` row with monotonically assigned version, effective timestamp, creator, and typed JSON. A database counter row serializes version assignment. Paid invoice intents link to the effective configuration version and snapshot its safe settings and seller-profile reference. Existing records remain nullable; no historical tax or seller data is invented. Audit records contain identifiers, version, effective date, tax code, and gate state, not credentials or customer tax identifiers.

The Owner tax-code selector lists the provider's XML vocabulary, not legal outcomes. The source of the registry is the [official Számlázz.hu VAT-code list](https://docs.szamlazz.hu/agent/generating_invoice/vat-rates). For example, the provider distinguishes `TAHK` (outside VAT subject matter), `TAM` (VAT-exempt supply), and `AAM` (exempt person); they are not interchangeable. `eusAfa` is deliberately not emitted: provider documentation restricts its use to OSS registration or a non-Hungarian seller tax number. A configured default code never becomes a transaction decision.

`MANUAL_REVIEW` permits an Owner to record a proposed tax code/treatment and an encrypted justification/basis reference for an intent. The entry records actor, timestamp, and configuration version with status `proposed_pending_accountant`; it does not alter the intent's blocked status or create an approved `TaxDecision`. The proposal form validates against the provider registry but cannot assert accountant approval. `APPROVED_RULES` is only a reserved mode: no speculative rules exist, and a missing, unapproved, or mismatched decision blocks issuance.

### Provider adapter and runtime credential

`SzamlazzAgentProvider` is bound only to the new `InvoiceSubmissionProvider`; the pre-existing `InvoiceProvider` binding remains the local dry-run adapter. The Agent key is read at runtime from the existing fixed `szamlazz.agent_api_key` config path after the established encrypted credential loader applies database-backed Service Credentials. It is never put into configuration cache, a queue payload, audit metadata, log context, model metadata, or serialized provider results. No credential was read from staging or used during this task.

The submission adapter validates generated XML with the vendored official invoice XSD, then inserts the key into an in-memory XML DOM and submits HTTPS multipart to `https://www.szamlazz.hu/szamla/` using `action-xmlagentxmlfile`, a bounded connect/request timeout, and redirects disabled. Provider response parsing keeps only a validated invoice number and small allowlisted metadata; raw XML, response bodies, PDFs, and exception messages are discarded. Payment labels are selected from a bounded language-aware registry for supported invoice languages.

The read-only reconciliation method uses the provider's documented XML query operation with `szamlaKulsoAzon`; the query schema is pinned at `resources/schemas/szamlazz-agent-xmlszamlaxml.xsd`. Querying is only useful when the same external reference was included when issuing the document. “Not found”, provider error, timeout, and malformed response remain unresolved; none proves that a prior create request did not succeed.

### Issuance safety and idempotency

The workflow requires all of these before inserting an attempt or calling the adapter: provider enabled, dry-run disabled, issuance enabled, an explicit allowed application environment, runtime credential, locally validated intent, immutable billing/seller snapshots, a decision with accountant/tax-counsel authority and approval reference, matching intent/version/tax code/amount/currency, payment date when required, and approved FX evidence for non-HUF currency. The provider repeats the three runtime gate checks. No default tax code can satisfy the per-intent decision gate.

`invoice_issuance_attempts` has unique constraints on invoice intent, deterministic external reference, and provider invoice number. The intent row lock and unique attempt mean concurrent workers and retries can create at most one provider submission attempt. `submission_started` is committed before the external call. A timeout, process crash, or ambiguous provider response transitions to reconciliation-required and is never automatically resubmitted. Reconciliation may only resolve an attempt after a provider lookup identifies a matching invoice. A definitive provider rejection is retained as a failed attempt; changing or retrying it requires an explicit future operator policy, not an automatic retry. This is application-side idempotency; the provider's invoice endpoint is not assumed to offer an idempotency key.

Refund/cancellation detection continues to set the existing review-required state only. No cancel invoice, credit note, correction, or refund API operation is implemented. Paid Stripe totals are preserved from the Stripe invoice snapshot; no value is recomputed from the current catalog. Foreign-currency issuance remains blocked unless a future approved, effective-dated FX decision supplies rate and bank.

### Provider contract references reviewed 2026-10-08

- [Authentication](https://docs.szamlazz.hu/agent/basics/authentication)
- [Sending requests](https://docs.szamlazz.hu/agent/basics/sending-requests)
- [Invoice request](https://docs.szamlazz.hu/agent/generating_invoice/request)
- [Invoice XML + XSD](https://docs.szamlazz.hu/agent/generating_invoice/xml)
- [Response](https://docs.szamlazz.hu/agent/generating_invoice/response)
- [VAT codes and `eusAfa` restrictions](https://docs.szamlazz.hu/agent/generating_invoice/vat-rates)
- [Currencies](https://docs.szamlazz.hu/agent/generating_invoice/currencies)
- [Errors and retry guidance](https://docs.szamlazz.hu/agent/basics/error-handling)
- [Invoice query request](https://docs.szamlazz.hu/agent/querying_xml/request)
- [Invoice query XML](https://docs.szamlazz.hu/agent/querying_xml/xml)
- [Duplicate invoice considerations](https://docs.szamlazz.hu/agent/generating_invoice/important-information)

The official documentation is mutable; it was checked on the date above. The exact XSD snapshots are versioned files in this repository and must be rechecked against official sources before any future activation. The docs describe an Agent payment-method free-text field and normalized value; this implementation sends a localized label from a fixed translation registry. The response parser accepts the documented invoice number header/plain response and XML response fields; a malformed/unknown response is ambiguous.

### Remaining blockers before invoice issuance

1. Accountant/legal decision pack must resolve seller registrations and effective dates, service classification (including whether human assistance is integral/incidental), place of supply, treatment and wording per scenario, zero-total invoice duty, recurring tax point, and foreign-currency rate source.
2. An approved, immutable TaxDecision model/rules and traceable approval workflow must replace the reserved `approved_rules` placeholder; the current Owner action records a proposal only.
3. Validate seller profile and Agent account requirements with the accountant and provider using a dedicated TEST account. There is intentionally no “test connection” call because the documented invoice operation mutates provider state and no documented safe credential-only validation operation has been established.
4. Verify all required buyer identity/tax fields and approved invoice XML mappings. The current invoice payload does not automatically map a customer tax identifier into Agent XML.
5. Perform provider sandbox/dedicated-account create/query/error/reconciliation tests after explicit authorization. This task made no provider HTTP request and issued no invoice.
6. Complete a focused security review and staging acceptance with issuance gates closed. No deployment, merge, live action, or tax activation is part of this implementation.

### Rollback note

The additive migration creates only configuration-version, proposal, and issuance-attempt tables plus a nullable configuration-version FK on invoice intents. Rollback drops those new tables and FK; it does not rewrite Stripe/billing rows or delete historical billing snapshots. The immutable configuration and proposal model guards updates/deletes at the application layer; database rows remain available for auditable history unless the migration itself is rolled back.

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

The provider ID is `szamlazz_hu`; its Owner credential reference is `szamlazz_agent`. Owner → Service Credentials allows an authorized Owner to save, replace, or delete the Számlázz.hu Számla Agent API key. It reuses the existing `integration_credentials` store and Laravel encryption. The UI reports only configured/not configured and, when configured, a fixed masked marker; it never reads the key back into the form or returns it from a status endpoint. Credential changes are audited with the provider ID and created/replaced/deleted action only. The key is not written to audit data or logs.

Saving this key does not activate the provider. `provider_enabled` remains `false`, `dry_run` remains `true`, and `issuance_enabled` remains `false`. The credential is not consumed by the Phase 1 local dry-run adapter, and saving it does not issue invoices, queue issuance, call the provider, or change tax decisions. The Owner should enter the TEST key manually only after this credential-management change has been deployed to staging; no key belongs in source, examples, fixtures, or chat.

No documented non-mutating Agent API authentication/account-validation operation was identified for a “Test connection” button. The official [authentication documentation](https://docs.szamlazz.hu/agent/basics/authentication) describes the key as request authentication. The [request documentation](https://docs.szamlazz.hu/agent/basics/sending-requests) lists operations, and the read-only [taxpayer query](https://docs.szamlazz.hu/agent/querying_taxpayer/request) checks a supplied VAT number rather than validating the account or API key; it is not a suitable connection test. The documented invoice request sends invoice XML and may create a document, so this phase intentionally has no connection test and makes no call to Számlázz.hu. A future validation step requires separate approval of a safe, documented provider operation and a dedicated test account. Tax/accountant approval remains a prerequisite to issuance. The HU Tax Advisor Agent remains separate and is not integrated here.

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
