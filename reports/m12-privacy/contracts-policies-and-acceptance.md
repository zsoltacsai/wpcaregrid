# Legal document architecture and acceptance evidence

## Existing repository state

`config/marketing.php` defines configurable destinations `/terms`, `/privacy`, and `/cookie-policy` and comments that the legal pages are not in this build. The public footer schema stores section labels; it does not store legal document content/version or acceptance. No legal document version, DPA, cookie consent, terms acceptance, or re-acceptance model/route was found. `content_entries` stores editable/published marketing sections and actor/timestamps, not an immutable legal-text registry. M11's billing snapshots and tax versions are separate commercial/accounting evidence, not legal acceptance.

## Source-of-truth design

Create a legal-document catalogue with type (`terms`, `privacy_notice`, `cookie_notice`, `dpa`, optionally justified acceptable-use/security appendix), immutable version ID, locale, content checksum, draft/published/retired state, prepared/published timestamps, effective date, audience/product/region applicability, notice mode, acceptance-required flag and legal approval reference. Store actual approved document body once; public page, checkout/customer area and Owner Console render the same version. Never edit a published body in place; correction creates a new version and retains historic version only for a reviewed purpose.

Acceptance evidence should record user/customer, document type/version/checksum, locale, accepted timestamp UTC, flow/source and minimal version marker. Do not add IP or full user-agent by default; justify necessity, access and expiry first. Acceptance record must point to immutable document and be append-only. Reacceptance logic should block only defined flows and only for an approved policy version; notice-only changes must not be represented as affirmative consent.

## Contract content map and required legal review

The Terms review should be mapped to actual product behavior and cover (where counsel considers appropriate): service description and plan boundary; customer authority to connect each WordPress site and provide instructions; Connector/admin-access permission and revocation; backup/restore scope, verification limits and recovery limitations; hosting compatibility and sufficient free disk/storage capacity (insufficient capacity can prevent backups, restores, updates or maintenance); third-party dependencies and provider outages; subscription/payment/renewal/cancellation and paid-through access; suspension and security response; maintenance/updates and service availability; customer data processing and DPA precedence; acceptable use; incident cooperation; termination/offboarding, backups and deletion. Do not imply guaranteed restore or uninterrupted hosting where the code does not provide it. M14 may implement disk-space monitoring; M12 defines the contractual/documentation dependency only.

The Privacy Notice review should cover the source-based inventory, purpose/role/basis, provider recipients and transfers, retention and backup reality, rights process, security summary and contact/responsibility. Cookie Notice and any consent UI must reflect the actual set-cookie and network-request inventory. DPA must align with customer instructions and actual site-data processing rather than include blanket language for all WP CareGrid purposes.

## Required legal review

Counsel to determine required documents/content, merchant and controller details, language, effective date, disclosure/notice and contract formation, which customer/staff groups accept, whether Terms or DPA updates require fresh affirmative acceptance vs notice, transition behavior for existing M11 customers, DPA execution and document retention, cookie notice/interface, consumer-rights and withdrawal/refund disclosures, and local Hungarian consumer/e-commerce/accounting overlays. Do not publish draft texts as binding advice or treat a checkbox as proof that processing is lawful.

## Existing customer transition

Keep acceptance `unknown/not_recorded` distinct from accepted/refused. Backfill no false acceptance timestamp. Legal review selects reacceptance/notice-only by document change and affected customer group. Preserve original version when known; if not known, record explicitly `legacy_unverified`, not an inferred version. Existing account access must have a counsel-approved transitional rule and notice path.

## Tests

Immutable version content/checksum; correct locale/version shown; acceptance atomically references shown version; stale/different version cannot be accepted accidentally; no IP/UA collection unless enabled and justified; audit and tenant scope; reacceptance vs notice behavior; existing legacy states; rendering safety and accessibility; no duplicate text source across views; export includes user acceptance evidence only within allowed scope.
