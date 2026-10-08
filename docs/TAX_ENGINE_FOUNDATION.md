# Tax identity and VIES foundation

This is an **infrastructure foundation only**. It does not classify any service, calculate VAT, alter checkout amounts, block checkout, apply AAM, enable Stripe Tax, or issue invoices.

## Data model

- `customer_billing_identities` stores immutable, monotonic customer identity versions. An actual billing-profile edit creates a new version; historical snapshots are not updated or deleted.
- `tax_identifier_encrypted` on `customers` and `checkout_intents` is the encrypted write path for new identifier values. Existing `tax_id` columns remain readable for compatibility, with no backfill in this migration. Existing plaintext values therefore require a separate, reviewed migration before claiming all historical tax identifiers are encrypted.
- `checkout_intents.billing_identity_id`, `subscriptions.billing_identity_id`, and `billing_invoices.billing_identity_id` are nullable foreign keys. Existing rows remain null. Current checkout/webhook processing does not populate them; the later tax integration must associate an exact snapshot without inferring missing history.
- `seller_tax_profiles` and `seller_tax_registrations` hold versioned, effective-dated seller facts. A seeded registry-lock row serializes version allocation and overlap checks, including the first profile insert. No seller name, registration, AAM or OSS value is seeded. Defaults are `unknown`/`unverified`; verified seller facts require an evidence reference.
- `vat_validations` stores minimal VIES evidence. Tax identifiers and optional returned name/address are encrypted. The raw VIES response is not retained.
- `tax_verifications` is a review-workflow status distinct from tax treatment. No `tax_decisions` table or tax-rate rule has been introduced.

## VIES service

`VatNumberValidator` depends on `VatNumberProvider`; production binds `ViesSoapProvider`, while tests can bind a fake. Country prefix formatting is normalized separately from VAT validity. Current normalization supports the EU VIES prefixes and XI, maps `GR` to VIES prefix `EL`, and applies generic syntactic checks. It intentionally does not encode authoritative country-specific VAT-number algorithms; expand those only with maintained, verified source data.

Provider results are `valid` or `invalid` only when VIES returns an explicit boolean. SOAP faults, HTTP errors, malformed provider responses, connection failures and rate limits remain `unavailable` or `pending_verification`. VIES validity is evidence only; no reverse-charge or exemption decision follows from it.

Timeout, retry, rate limit and result-cache settings are under `config/tax.php`. Valid/invalid results are cacheable; provider failures are not cached as invalid. The cache contains only result status and provider reference/date, not returned names or addresses. Returned references/name/address are optional. Name/address comparison is exact-string evidence only, not a legal match determination.

## Privacy and compatibility notes

- New billing identity, seller-registration and VIES tax identifiers use Laravel encrypted casts; keyed HMAC fingerprints support lookup without storing a plain hash.
- Tax IDs are hidden from model serialization and are not logged by the VIES service.
- New account-profile and Owner customer edits store tax IDs in an encrypted column and clear the legacy customer `tax_id` value after successfully creating an immutable snapshot. Old customer and checkout-intent `tax_id` values are not rewritten or removed by migration.
- Encryption depends on stable `APP_KEY`. Identifier fingerprinting prefers `TAX_IDENTIFIER_FINGERPRINT_KEY` and falls back to `APP_KEY`; production must provision a stable dedicated key before relying on fingerprints across key rotation.
- The VIES request is an external disclosure of a VAT identifier to the European Commission service. Before production use, approve the privacy notice, lawful basis, retention, user access, and data-processing documentation.

## Migration and rollback

Migrations are additive: they add encrypted-write columns, nullable identity links, and new identity/profile/evidence tables. There is no historical identity backfill and no existing billing amount or Stripe ID is changed. Rollback drops the new tables and nullable columns; it does not alter existing invoice/subscription values. **Rollback will remove newly recorded identity/VIES evidence**, so export/retention policy must be considered before using these tables operationally.

The changes have not been run against shared staging or production. Apply only to an isolated development/test database during this phase. The legacy plain `tax_id` fields remain a known privacy limitation for pre-existing values and must not be described as encrypted.

## Not activated

No application code consumes seller profiles to decide tax. No decision can assign 0% VAT. Pending/rejected/unavailable review states have no effect on authorization, pricing, Stripe Checkout, customer access, subscription renewal, promotion behavior, or invoices.

The separate HU Tax Advisor Agent is not integrated. Any future connection is optional and must remain advisory: no credentials, network client, automated tax decision, or write access is introduced by this foundation. Any recommendation must use an explicitly selected evidence snapshot and remain subject to human/accountant review and approval.

See [the accountant decision pack](tax/WPCareGrid_Accountant_Decision_Pack_HU.md) before implementing any tax rules.
