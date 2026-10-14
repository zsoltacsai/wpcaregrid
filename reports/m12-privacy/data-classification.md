# Internal data classification

Classification is a handling control, not a legal conclusion. An item may have multiple labels; use the highest applicable restrictions. Every newly added field should declare category, purpose, owner, source, retention class, export behavior and deletion behavior in review/migration documentation.

| Class | Examples in current system | Required handling |
|---|---|---|
| PUBLIC | Published marketing content, public pricing, public docs | Verify approval before publishing; no secrets or customer-specific values. |
| INTERNAL | Scheduler status, release SHA, aggregate system health, non-customer configuration | Staff access only as required; keep low-detail monitoring labels. |
| CUSTOMER DATA | Website URL, site inventory, jobs/results, backups, domain-specific health | Tenant-scoped; encrypted transport/storage; export only under allowlist; no cross-customer joins; vendor access under documented instruction. |
| PERSONAL DATA | Account/contact names/emails, staff actor IDs, IP/UA, domains identifying a sole trader, recipients, free-form notes | Purpose minimization; approved retention; access/audit; response rights workflow; logs sanitized. |
| FINANCIAL / BILLING DATA | Billing identity/address/tax ID, Stripe refs, invoice data, immutable snapshots, tax evidence | Restricted role; encrypt direct identifiers; never log raw payload; separate legal/accounting exception; no blanket cascade delete. |
| SECURITY-SENSITIVE | Connector credential hashes/hints, access token hashes, findings/incident evidence, webhook payloads, job errors | Restricted operator access; pseudonymized monitoring; preserve only approved investigation evidence; never expose in normal export. |
| SECRETS / CREDENTIALS | Passwords, reset/pairing tokens, Stripe/S3/SMTP/Számlázz keys, APP_KEY, backup encryption keys | Never export/report/log; secret-manager/approved vault only; rotation and access evidence; do not place in audit data. |

## Model mapping

- `users`, `customers`, `checkout_intents`, `subscriptions`: Personal Data + Financial/Billing Data; auth fields are Secrets/Security-Sensitive.
- `customer_billing_identities`, `billing_invoices`, `invoice_intents`, invoice events/reviews/attempts, VAT/tax verification, seller tax profiles: Financial/Billing Data + Personal Data; tax ID and encrypted commercial snapshots restricted.
- `websites`, `connectors`, `connector_pairing_codes`, `website_inventories`, `website_jobs`, `website_events`, `admin_access_requests`: Customer Data + Security-Sensitive; actor/contact/domain fields are Personal Data; hashes are still restricted identifiers.
- `backups`, `backup_objects`, `backup_restores`, customer object storage: Customer Data containing unknown site-subject data; high sensitivity by default.
- `security_*`, `monitoring_*`: Security-Sensitive/Internal + Customer Data; actor, domain, note, recipient or evidence can be Personal Data.
- `admin_audit_events`, `security_incident_events`, `website_events`: append-only evidence, often Personal Data and Security-Sensitive.
- `stripe_webhook_inbox`, `stripe_events`, `billing_email_outbox`, `jobs`, `failed_jobs`, `job_batches`, `sessions`, `password_reset_tokens`, `staff_invitation_tokens`: Personal/Financial/Security-Sensitive depending payload; tightly bound raw data and retention.
- `integration_credentials`: Secrets, encrypted under APP_KEY; backups containing them remain secret-equivalent.
- `platform_database_backups`: inherits the maximum classification of every table it contains; client-side encryption and independent key recovery are mandatory.
- `content_entries` and `price_definitions`: ordinarily Public/Internal; Owner drafts/audit actor details can be Personal Data.

## Product implementation rule

No category grants automatic deletion or disclosure. Use an explicit allowlist for export, role/tenant scopes for access, reviewed per-field retention, and a documented exception when records are retained. Encrypting a value does not remove its personal-data classification.
