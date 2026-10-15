# M12 Hungarian/EU privacy adviser handoff

**Purpose.** Request a focused legal review of WP CareGrid's processing model before customer-facing legal terms or destructive retention are activated. This is not a legal opinion and no GDPR-compliance conclusion is asserted. Planning baseline: primary `claude/ecstatic-wozniak-oemyuf`, SHA `856f7a03d7624158eb5cf9719fdec4776fbf6893`, re-fetched 2026-10-14.

## Product and technical facts

WP CareGrid is a Laravel SaaS that manages WordPress/WooCommerce sites through a Connector. It stores account and billing records; receives site inventory, health/security observations and WebsiteJobs; can access/maintain authorized WordPress sites; and creates customer-site database/files backups. Site archives can contain arbitrary personal data placed in WordPress by the customer. Platform database backups are a separate M9 recovery plane. M11 has Stripe checkout/webhook/billing lifecycle and gated invoice-provider integration. No privacy-request/export/erasure case flow, legal acceptance ledger, consent manager, processor registry or erasure tombstone replay was found in audited primary source.

### Technical role map — provisional, purpose-specific only

| Purpose | WP CareGrid technical activity | Customer technical activity | Provider / unknown | Legal role confirmation |
|---|---|---|---|---|
| SaaS account and authentication | Operates account, sessions, access/security | Supplies account identity/contact | Hosting/mail vendor unknown | Required; do not infer one global role |
| Subscription billing | Creates checkout/subscription state and reconciles signed events | Selects plan and pays | Stripe integration known; account/region/contract unknown | Required |
| Invoice preparation/issuance | Stores billing identity/snapshots; provider path exists, issuance gated in M11 evidence | Supplies billing identity | Számlázz.hu integration known; live use and contract unknown | Required |
| Managed WordPress data/backups | Connector operations, backup storage and restore | Chooses site content/purpose and grants access | Customer storage provider/region unknown; S3/SFTP adapters exist | Required, including backup contents |
| Security and maintenance | Runs health/security checks and authorized maintenance actions | Authorizes site and configures WordPress | Optional vulnerability feed; hosting/edge paths unknown | Required by purpose |
| Email and alerts | Sends activation, billing and monitoring mail | Receives notices | SMTP path used historically; current vendor/region unknown | Required |
| Platform operations/backups | Operates SaaS and encrypted platform DB backup design | Not normally directing platform account processing | Hosting and platform S3 runtime facts need verification | Required |
| DNS/edge/security | DNS adapter exists; edge use not established | May delegate DNS/edge configuration | Cloudflare adapter known; actual proxy/log flow unknown | Required if customer data is processed |

## Provider facts to verify separately

Repository evidence: VPS deployment model is documented, but hosting provider/region is unknown; generic S3-compatible/SFTP/local customer storage adapters exist, but active customer endpoint is unknown; platform-S3 code/configuration exists, while an earlier M9 handoff reported no verified bucket at that report date; Laravel mail exists, but vendor is unknown; Stripe and Számlázz.hu integrations exist; Cloudflare DNS adapter exists; Wordfence is optional and active source unknown. No GA4/GSC, Google Drive or AI customer-data integration was found in audited app source. Current runtime provider settings, contracts, region and actual edge tags were not inspected. These are factual verification tasks, not role or transfer conclusions.

## Consolidated legal questions

1. **Roles by purpose:** Determine controller/processor/joint roles, responsibility and instruction model for site content/backups, account/auth, billing/invoicing, security/maintenance, audit and platform operations. Technical facts are above; the product cannot settle role from architecture alone. This affects notices, DPA and rights routing. Engineering inventory and neutral schemas may proceed; published role claims wait.
2. **Lawful basis and data scope:** Identify basis/necessity for each purpose and any special-category treatment relevant to arbitrary WordPress backup contents. This determines notice, minimization and possibly product constraints. Inventory proceeds; legal labels and destructive schedule wait.
3. **Retention and erasure:** Specify category-specific trigger, duration, exceptions and response timeline for account/site data, customer/platform backups, logs, audit, security evidence, webhook/outbox and erasure tombstones. Existing customer backup cleanup exists; platform `expires_at` is metadata only. Dry-run can proceed; deletion and deadline promises wait.
4. **Rights handling:** Define access/export scope, identity checks, third-party redaction, restriction/refusal exceptions, rectification and cancellation/erasure behavior, including legacy accounts. This governs customer response UX. Models and private export assembly can proceed; active response commitments wait.
5. **Documents and acceptance:** Confirm required Terms, Privacy, Cookie Notice and DPA content/locales/effective process, existing-user notice versus reacceptance triggers. Version/acceptance machinery can proceed; publishing/forcing acceptance waits.
6. **DPA and processors:** Validate Article 28 processor terms/instructions and provider/subprocessor notice, change and objection process for providers actually used. Provider identities/contracts must be fact-checked first. Registry shell can proceed; approved DPA/public list waits.
7. **International transfers:** Review actual locations, support access and onward flows after provider facts are collected; specify mechanisms/safeguards if needed. No regions or transfer compliance are inferred from vendor names. Fact collection proceeds; transfer-dependent claims wait.
8. **Cookies and analytics:** Classify actual app/edge cookies, required consent/withdrawal evidence and future M13 analytics boundary. No analytics integration was found in app source, but runtime edge injection is unverified. Consent-state interface can proceed; analytics waits for separate M13 scope.
9. **Incident/breach:** Define assessment/reportability and notification responsibilities, roles, deadlines, authority contact and whether DPO requirements apply. Case/audit foundation can proceed; deadlines or automatic notices wait.
10. **Backup erasure and hold:** Approve customer-facing representation for residual backup copies, tombstone purpose/period/restore replay, and whether a scoped legal hold is required. Historical backups cannot be promised instantaneously erased by current design. Technical tombstone architecture can proceed; operational erase/hold and claims wait.

## Already treated as technical/product defaults

Report-only retention first; async audited erasure; reversible cancel-before-execution with no legal deadline selected; portable ZIP export; private short-lived download; restrict identity before purge; cancellation does not itself delete backups; destination-specific platform recovery minimum; minimal tombstone design; synthetic-only staging; recent re-authentication; tenant-isolated access; document-version machinery separate from legal prose; global destructive gate default OFF. These do not require counsel to decide the engineering shape. See `owner-decision-summary.md` and `final-decision-register.md`.

## Not being asked to decide

- Do not choose application architecture, export file format, queue retry mechanics, batch sizing, UI wording for neutral workflow states, or whether report-only dry-run comes first.
- Do not approve or operate a provider account, change credentials, provision infrastructure, or verify a secret.
- Do not decide accounting/tax retention periods or seller evidence; those are in `accounting-review-handoff.md` for the accountant.
- Do not authorize production deletion, declare M12 implemented, or approve analytics implementation.
- Do not receive secrets, APP_KEY, tax IDs, invoice payloads, production database dumps, or customer backup content.

## Requested response format

For each question provide: decision by purpose/category, effective date, any exception, required evidence, review cadence if applicable, and a non-secret decision reference. Explicitly mark unresolved items. Consider current Hungarian law and current EEA guidance at review time; the references in the previous handoff are starting sources, not conclusions.

## Wave 7 execution-specific legal questions — 2026-10-15

This addendum supplements existing topics without repeating or resolving them. No statutory periods or legal conclusions are supplied by engineering. See `wave7-owner-questionnaire.md`:

- **W7-L-01:** how the RET-16 product safety/cancellation window interacts with applicable request clocks, exceptions, urgent cases, and response obligations.
- **W7-L-02:** authority, scope, evidence, review date/expiry, release, and customer-facing treatment of legal holds in an erasure plan.
- **W7-L-03:** lawful purpose, minimum fields, access, retention/end-of-life for the independent tombstone journal and replay evidence.
- **W7-OP-03 / W7-OP-04 / W7-OP-05:** legal review of site-content scope, partial/provider-pending completion wording, and cancellation/reopening after irreversible action begins.

Technical recommendation: unknown legal scope remains `WAIT_LEGAL`; it blocks affected destructive actions while allowing safe preview/state representation. This addendum is not legal advice or approval.
