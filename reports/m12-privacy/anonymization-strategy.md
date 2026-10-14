# M12 Account Anonymization Strategy — Design Only

## Goal

Preserve referential integrity and only the minimum approved history while removing direct identity fields from active customer identity. Anonymization is a later irreversible operation; Wave 4 only reports eligibility and does not anonymize.

## Proposed lifecycle

`active → closed → privacy_review → anonymization_candidate → approved → anonymized`

Each transition is asynchronous, idempotent and audited. `approved` requires identity verification, dependency analysis, legal/accounting exception resolution, cooling-off completion where applicable, and a fresh policy-version-bound preview. A cancellation or reactivation before approval returns the case to a reviewed non-destructive state; no direct status edit skips review.

## Field-level treatment

- **Customer/account row:** retain opaque internal ID only where required by relationships; clear or replace approved direct identifiers. Do not blanket-delete the row where billing/audit relations depend on it.
- **Email:** replace with a unique, non-deliverable reserved-domain value, recommended form `deleted+<opaque-id>@privacy.invalid`. Never encode/hash the original in a reversible or enumerable way. The mapping must not permit later registration to reconnect to the old identity.
- **Name, phone, address, profile fields:** clear or replace only where no approved accounting/security exception applies. Keep any retained exception separate, access-restricted, and reference the approved rule.
- **Authentication:** disable account, rotate/invalidate password/reset material, revoke sessions, API credentials and recovery tokens; prevent password reset or delivery to the placeholder.
- **Connector/admin access:** revoke/reconcile pairings, support access, maintenance credentials and pending grants as a separately audited operation; never delete active restore evidence.
- **Communication state:** suppress marketing/transactional delivery to anonymized identity and cancel unsent messages; ensure retries re-check active identity before send.
- **Historical invoice/seller/accounting snapshots:** do not rewrite. Mark as compliance-retained only under approved accounting rule; do not claim entire history is erased if an exception remains.
- **Audit/security evidence:** minimize/pseudonymize only with reviewed rules; do not delete evidence needed for incident/accounting obligations by default.

## Re-registration invariant

After completion, an account registration using the original address must pass active identity uniqueness validation and create a new opaque customer/user identity. It must not reconnect to the anonymized row through normalized email, provider ID, tombstone, or guessed UUID. Tombstone replay may suppress restoration of the old identity but must not block a new, independently verified account from registering with that email.

Required future test: create synthetic `person@example.com`, complete anonymization, assert the original value is absent from active unique indexes and messages, register again using the same original email, assert a new ID and no relationship to the old account, then simulate historical database restore and confirm tombstone replay suppresses the old identity without suppressing the new one.

## Privacy tombstone relationship

Use the minimum pseudonymous/stable evidence needed to prevent a restored snapshot from reviving erased identity. Prefer an opaque account reference generated independently of email; protect key material separately, avoid plain email hashes, and keep the suppression journal independent of the restored database failure domain. Scope, key recovery and retention duration require legal review. Missing or unavailable journal blocks recovery promotion; it does not authorize skipping reconciliation.

## Preconditions and stop states

Stop for manual review if an immutable accounting dependency, active restore/update/compensation, unresolved provider deletion, unknown field mapping, legal hold conflict, inability to revoke credentials, email collision, or tombstone gap exists. No fallback to hard deletion. A completed anonymization is not a reversible transaction; preview and approval evidence must be preserved.
