# M14.4 Owner protection controls plan

## Scope

Owner Console operational surface only; no public pricing page, customer self-service reduction, billing calculator, or large aggregate dashboard. OQ-04 is approved: Owner may extend protection above the purchased plan floor, never reduce that floor or any system safety floor. M14.4 changes only policy/preview metadata; no delete action exists.

## Proposed control surface

- View six target policy definitions with `TARGET / IMPLEMENTED / ACTIVE / PUBLICLY_OFFERED` state kept distinct; policy version/effective date; current site entitlement and extension; GFS slots/gaps/protection reasons; last evaluation timestamp.
- Add protection: additional retained points/time or manual hold only after domain bounds are specified. Addition is monotonic and cannot reduce safety. Show estimated logical point/storage impact as an estimate, not a quote.
- Reduce/remove Owner-added protection: only the Owner role; CSRF; M14.1 recent re-auth; audit event; server-side comparison with purchased floor and system floor. Show impact preview before confirmation.
- Post-service desired duration selector may store only preference (`0`, `7`, `14`, `30`, `60`, `90`, bounded custom); default target 30 is not legal approval. Label as desired policy; display `WAIT_LEGAL` / `WAIT_ACCOUNTING` and no eligibility/execution assertion while unresolved.
- Customer view/edit is out of M14.4. A future read-only entitlement contract may be consumed by M16.

## Impact preview and stale state

Preview is deterministic and immutable: site/subscription, current entitlement version, current Owner extension version, proposed values, effective-policy digest, backup-set/verification/deletion fingerprint, restore/update/compensation state, hold set/version, last-known-good ID/version, recovery gaps and newly eligible candidates. It identifies (a) points losing only the removed `OWNER_EXTENSION` reason, (b) points retained by other reasons, and (c) points that become candidates after full recompute. It must say “may become eligible for future cleanup,” never “will be deleted.”

Confirmation compares exact source fingerprint and entitlement/override version under transaction/lock. Any new backup, verification change, restore, hold, subscription transition, policy change, safety state change, or preview expiry rejects as stale and requires a fresh preview. Confirmation removes only Owner-extension protection. It creates no provider deletion record, queue job, `BackupDeletion` call, or executable candidate approval.

## Authorization, audit, and redaction

Use existing Owner ability/route group, M14.1 re-auth and CSRF mechanisms. Operator and Customer cannot mutate global Owner policy. Audit append-only actor, site/subscription scope, before/after policy version and preview fingerprint/status; omit credentials, provider secrets, content, raw paths and unnecessary customer data. No button or API for force, purge, delete now, or safety bypass. Test direct service/controller invocation, not only UI visibility.
