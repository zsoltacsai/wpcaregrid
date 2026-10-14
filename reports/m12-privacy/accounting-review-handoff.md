# M12 accounting and tax review handoff

This short handoff asks only accounting/tax questions. It does not request a general GDPR opinion and contains no customer tax identifiers or secret values. Technical evidence shows versioned billing identity and immutable invoice-intent/snapshot behavior in the M11 design; M12 must preserve those historical facts unless a qualified correction process says otherwise. No retention duration is assumed here.

## Questions for the accountant / tax adviser

1. **Retention schedule:** For each record type below, what retention period applies and what event starts the period: issued invoice and its immutable snapshot; payment/refund/chargeback evidence; credit note/storno/correction; tax verification/decision reference; seller profile version; failed or dry-run invoice attempt? Please distinguish each category rather than giving one global duration.
2. **Identity fields:** Which customer and seller identity/address/tax fields must remain attached to the retained accounting record? Which profile fields may be removed or pseudonymized independently without invalidating the record?
3. **Correction process:** When a customer or seller identity is corrected after issuance, what evidence and linkage are required? Confirm that the issued snapshot must remain immutable and corrections should be represented by a linked correction/credit-note record rather than rewriting history, or specify the approved alternative.
4. **Tax/seller decision evidence:** What retention and linkage are required for seller tax-status evidence, tax-code decision references, invoice configuration versions, and supporting evidence? Identify any categories that must be retained even when the user profile is closed.

## Evidence requested

Return a record-type schedule with: record category, applicable trigger, duration, required fields, permissible redaction/anonymization, correction linkage, source of approval, effective date and a non-secret decision reference. Do not send tax IDs, invoice payloads, actual customer records, API credentials or payment details in the response.

## Technical constraints already planned

- Do not physically delete or rewrite an issued invoice snapshot through an account-erasure cascade.
- Keep erasable profile data separable from immutable accounting evidence where the existing schema permits; if not, use a reviewed migration plan.
- An unresolved accounting exception blocks completion of the affected erasure and any destructive retention eligibility, but not read-only inventory, request tracking or dry-run reporting.
- No accounting retention periods are selected by this engineering plan.
