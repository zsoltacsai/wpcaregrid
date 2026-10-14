# Controller/processor roles, processors and international transfers

Roles must be assigned by processing purpose and actual decision-making, not blanket business labels. The customer may determine purposes/means for managed WordPress data while WP CareGrid acts on documented instructions; WP CareGrid may determine its own purposes for accounts, billing, platform security and legal evidence. Counsel must validate joint/separate-controller edge cases. EDPB [Guidelines 07/2020](https://www.edpb.europa.eu/documents/guideline/guidelines-072020-on-the-concepts-of-controller-and-processor-in-the-gdpr_en) is the official review anchor, not a role conclusion.

| Provider/system | Evidence in code | Potential flow and role | Unknowns before legal notice/DPA |
|---|---|---|---|
| Stripe | Checkout, customer/subscription/invoice API, signed webhooks, reconciliation | payment processing and billing facts; independent/contracted role requires legal analysis | configured account/region, exact data shared, DPA/terms, subprocessors, retention/deletion and transfer framework |
| Számlázz.hu | gated invoicing provider/lookup/submission interface and Owner credentials | invoice issuance and document delivery | live vs synthetic state, data schema, account region, vendor DPA/retention, export/correction/delete support |
| Hetzner / S3-compatible storage | generic S3 and SFTP adapters; M9 separate platform S3 code | customer WP backups and platform DB backups on distinct credentials/destinations | actual customer/platform provider, bucket/region, replicas/versions, encryption, account, DPA, deletion behavior and physical retention; M9 handoff required Owner infrastructure confirmation |
| SMTP/email | Laravel mail transport and billing/monitoring outboxes | activation/reset, billing and alerts | vendor, data fields, delivery/bounce retention, region, DPA, subprocessors and deletion/suppression behavior |
| Hosting provider | deployment docs identify VPS infrastructure; actual runtime provider must be confirmed | app DB, logs, queues, backup staging and secrets | contract/DPA, region, backups/snapshots, support access, retention and incident notification |
| Cloudflare | DNS Owner console adapter; staging edge/DNS may be in deployment path | DNS records and potentially network/security logs depending current routing | actual proxy mode, enabled products/logs, region, DPA and transfer basis; do not assume code adapter means customer traffic flows there |
| Wordfence vulnerability feed | optional vulnerability feed adapter | public vulnerability catalog request; likely no customer records intentionally | active source/config, telemetry and feed terms; verify request contains no site/customer identifiers |
| VIES/tax verification | validator/provider path exists in app | tax identifier submitted for a validation query | exact provider endpoint, fields, region, logging and retention |
| GitHub | private code repo, deploy key and CI | source, synthetic tests/logs; no intended customer record flow | artifact retention/access settings; inspect only test data and CI logs. Do not list as customer processor absent evidence |
| Google Drive | M9 optional architecture/report only; no active integration established | optional encrypted platform backup copy if later implemented | not part of current runtime facts; OAuth scopes, account, region, quota, delete/retention and recovery access require separate approval |
| AI providers | no current personal-data flow identified in audited source | future feature only | out of M12 scope; perform separate DPIA/contract/transfer review before any data integration |

## DPA and subprocessor registry

Plan a versioned, Owner-reviewed registry: provider/service, purpose, data categories, environment, processing location, transfer status/mechanism, DPA/contract status and reference, subprocessors URL/version, effective date, last verified date, data subject/contact and deletion method. Keep credentials out. Public-facing registry generated from the same approved source, never inferred from dependencies. Change notification, effective dates, audit and customer objection workflow require legal/product policy.

## International transfer review

For each provider/environment establish processing region, support access, onward subprocessors and transfer route. Label EEA / non-EEA / unknown from current evidence; current repo cannot establish active cloud regions. **LEGAL REVIEW REQUIRED** for non-EEA transfers, applicable adequacy/transfer mechanisms and supplementary measures. Do not claim SCC or adequacy based only on provider branding.
