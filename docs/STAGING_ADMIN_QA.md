# Staging-next Admin QA mode

**Source-only implementation. Enabling this mode on a host is not authorized by this change.**

The `staging_admin_qa` profile is available only when configuration was loaded
with all of these exact conditions:

- `APP_ENV=staging`
- `CAREGRID_DEPLOYMENT_ENV=staging_next`
- `APP_URL=https://staging-next.wpcaregrid.com`
- `APP_DEBUG=false`
- `STAGING_ADMIN_QA_ENABLED=true`
- `STAGING_ADMIN_QA_SYNTHETIC_DATA=true`
- `MARKETING_RELEASE_PROFILE=staging_admin_qa`

Any missing or inconsistent value resolves to `marketing_only`. At request
time, the global gate independently requires the exact staging-next Host,
HTTPS, an unambiguous request target, and the same server-side deployment
identity. Production does not satisfy the contract and remains marketing-only.
The external Nginx HTTPS BasicAuth layer must remain enabled; application
configuration cannot attest that infrastructure property.

In QA mode the application gate permits only the public marketing GET/HEAD
surface, internal Owner/Operator read pages, customer account read pages, and
the existing login/logout POSTs. All other writes stay denied, including
checkout, Stripe/webhooks, registration, password reset/email, Connector/API,
backup/restore, scanning/remediation, provisioning and account/site mutations.
Role/permission enforcement remains in the existing authentication guards,
policies and `can:*` middleware. The profile does not grant any role.

## Account preparation boundary

1. The Owner account must already exist through the approved Owner bootstrap
   procedure. Never place a password or session value in source, CI or a report.
2. Create an Operator test account only through the existing Owner → Operators
   invitation flow, using a staging-only mail sink whose non-delivery behavior
   has been independently verified. Do not send an invite to an external
   address from this procedure.
3. Customer self-registration is not available. This change does not add a
   customer factory or data seeder. A synthetic Customer account is optional
   and remains BLOCKED until an approved, isolated fixture/provisioning
   mechanism exists and the staging-next database is verified as synthetic.
4. Set `STAGING_ADMIN_QA_SYNTHETIC_DATA=true` only after the operator has
   verified that the target database contains no real customer data. The flag
   is an assertion, not proof; deployment acceptance must retain its separate
   data-isolation evidence.

After deployment, acceptance requires BasicAuth still enforced; correct Owner,
Operator and (when available) synthetic Customer roles reach only their
existing authorized read pages; wrong roles remain denied; production stays
marketing-only; and checkout, billing, Stripe, Connector/API, registrations,
mutations and background SaaS work remain unavailable. No live staging account
or configuration was changed by this source implementation.
