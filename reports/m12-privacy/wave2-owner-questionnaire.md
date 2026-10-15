# M12 Wave 2 — Owner decision questionnaire

Status: **Owner questions answered for the implementation defaults below.** This record is an engineering/product decision only. It does not approve legal export scope, accounting disclosure, or statutory timelines.

## Q-W2-01 — Export-link reuse

- **Category:** OWNER PRODUCT POLICY / SECURITY
- **Question:** May the authenticated export link be reused during its already-approved 24-hour validity window, or must it be one-time use?
- **Recommended default:** Reusable for 24 hours; require an authenticated customer session, bind the artifact to that customer, and audit every successful download.
- **Alternatives:** One-time use; shorter reusable window.
- **Why it matters:** A reusable link helps a customer retry a failed download, while customer authentication and expiry prevent the URL from becoming a standalone bearer credential.
- **Customer effect:** The customer can download again during the valid window after signing in.
- **Operational effect:** Each successful transfer creates an audit event; a new link may be requested while the encrypted package remains available.
- **Reversibility:** Future link issuance can change; already-issued links expire at their recorded UTC deadline.
- **Owner answer:** **APPROVED — REUSABLE FOR 24 HOURS, EACH DOWNLOAD AUDITED.** Answer received 2026-10-15.
- **Legal/accounting review:** Still required for the final disclosure scope and any accounting-document representation. This answer does not resolve either review.
- **Implementation effect:** Use the approved RET-05 24-hour technical value; keep the package separately bounded by RET-06 (7 days); require authentication and tenant ownership in addition to the signed URL.

## Other Wave 2 decisions

No additional unresolved Owner product-policy choice blocks the non-destructive implementation. Apply the existing decision register and approved RET-01…RET-33 defaults. The implementation uses a conservative allowlist and reports partial coverage where legal/accounting scope is unresolved.

The following remain explicitly outside Owner product-default approval:

- **LEGAL REVIEW REQUIRED:** final access/export scope, identity-proof sufficiency, excluded third-party/security content, and any response-time or compliance claim (existing L4).
- **ACCOUNTING REVIEW REQUIRED:** treatment and field-level disclosure of immutable invoice, billing identity, tax, payment/refund, and seller evidence (existing C1–C4).
- **PROVIDER FACT REQUIRED:** current external provider disclosure/deletion facts where export references depend on them.

These unresolved reviews do not prevent building the allowlisted, non-destructive export mechanism. They do prevent the product from claiming complete legal response coverage or exposing withheld categories.

## Technical choices (not escalated)

- Use the existing privacy-request case and append-only event history; do not create a parallel request system.
- Require current-password confirmation for each export/rectification request and customer authentication for downloads.
- Use an authenticated, customer-bound signed URL; reuse is allowed only until its stored 24-hour expiry.
- Store an application-encrypted ZIP in the existing private local disk; never publish a public object URL.
- Export JSON as the canonical representation; use a human-readable index. Do not add CSV where it duplicates nested JSON without benefit.
- Exclude tax identifiers, immutable invoice snapshots, raw webhook bodies, backup archives, secrets, operator-only security evidence, and other tenants' records; show generic exclusion reasons and partial coverage.
- Use the existing canonical profile editor for rectification. Create a new billing-identity version where its current service already does so; never rewrite prior invoice or tax snapshots.
- Fail closed when an approved RET-05/RET-06 policy value or export integrity check is unavailable.
- Temporary export-package expiry cleanup may remove only generated encrypted export artifacts, never the source profile, billing, site, backup, webhook, or audit data.
