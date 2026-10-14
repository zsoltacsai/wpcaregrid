# M12 implementation validation and closure plan

## Test levels

1. Unit/model: field classification, state transitions, immutable legal versions and policy version snapshots.
2. Feature/database: account-scoped request/export, request auth, immutable invoice boundaries, provider-task idempotency, audit, report-only retention, and migration/backfill unknown states.
3. Queue/concurrency: duplicate request/jobs, expired leases, simultaneous export/erase, subscription/webhook races, provider outage, policy change mid-run, tombstone unavailable and kill switch.
4. Storage adapter: S3/SFTP configured behavior, per-object partial delete, versioning/delete marker, checksum/readback and manifest integrity; provider mocks are not remote proof.
5. Security: IDOR, privilege escalation, CSRF, signed link disclosure, secret leakage, file/temp cleanup, request forgery, audit tampering and backup substitution.
6. MySQL-backed: transaction/lock/unique constraint/concurrent worker tests on supported DB; SQLite alone does not prove production locking semantics.
7. Synthetic staging E2E: fake/synthetic tenant export, correction, erasure preview, approved test deletion, invoice exception, provider mock/sandbox, backup deletion, stale webhook, cancellation, document acceptance, incident fixture and restore with post-snapshot tombstone.
8. Operational evidence: worker/scheduler and provider health; no customer/production record used; restore/export packages removed; all gates reset and no stale jobs/locks remain.

## Required future test scenarios

- Access/export exact field allowlist and completeness across user/customer/website/subscription/billing/monitoring records; no secrets, other tenant, or restricted security notes.
- Immutable customer/seller/invoice snapshot remains unchanged while future profile version updates; accounting exceptions are retained/restricted.
- Erasure request requires identity verification; duplicate/replayed case is idempotent; reactivation, billing webhook and invoice issuance races do not silently recreate or erase.
- Provider delete timeout/partial object delete remains pending; reconciliation confirms each object/version; no false “complete.”
- Active restore/compensation and last verified backup refuse deletion; policy reduction is dry-run only.
- Historical backup restore replays tombstones before queue, scheduler, webhooks or customer access; unavailable tombstone journal fails closed.
- Contract acceptance captures exact version/checksum and locale; legacy acceptance remains unknown; Terms/Privacy update follows approved reacceptance/notice rule.
- Audit contains sufficient action/evidence but no raw key/token/payload; IP/UA omitted unless approved.
- Cookie/analytics tests make no request before approved consent state; withdrawal stops later requests where implemented in M13.
- Localization, accessibility, notification failure and safe status text in HU/EN.

## Closure gates

M12 cannot close until all are evidenced: full source + runtime data inventory; approved purpose/role/lawful-basis and processor/transfer decisions; published counsel-approved legal documents and correct acceptance behavior; export/rectification/erasure case lifecycle with tenant isolation and audited exceptions; approved retention rules and report-only reconciliation; customer and platform backup deletion/verification with holds/active-restore guards; restore-after-erasure tombstone replay and queue quarantine; external provider reconciliation; billing/accounting exceptions; privacy incident workflow; security review; synthetic staging E2E; CI including MySQL concurrency; deployment/rollback evidence; final Owner/legal/accounting sign-off and no unresolved high/critical defect.

No production destructive validation is required or authorized by this plan. Any production deletion/retention enablement requires a separate prompt and explicit Owner approval after dry-run review.
