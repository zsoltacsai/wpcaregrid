# M12 architecture plan — privacy, rights and data lifecycle

## Purpose and boundary

M12 adds an accountable privacy/data lifecycle around the existing SaaS. It must preserve billing evidence, prevent cross-tenant disclosure, reconcile external deletion, and make backup restoration unable to silently resurrect an erased account. Start with inventory and report-only operations. Destructive retention/erasure is a later, separately gated capability. M12 does not decide legal bases, legal deadlines, processor roles, or final contract wording.

## Verified baseline

The primary is `claude/ecstatic-wozniak-oemyuf` at `856f7a03d7624158eb5cf9719fdec4776fbf6893`; staging source is `bb3b88d7846992ac35f52fa1842b868d23d3af4b` (read-only strict-host-key SSH). A clean planning worktree was based on primary. M11 has customer profile/billing identity versioning, immutable invoice/tax evidence, a durable encrypted Stripe webhook inbox, billing email outbox, and subscription operations; M9 has customer backup deletion/retention and encrypted platform DB backup artifact paths. See the inventory and baseline documents for scope/evidence limits.

## Recommended target architecture

1. **Data catalogue:** version-controlled category/purpose/owner/provider metadata and field-to-category mapping, with migration inventory maintained during schema changes.
2. **Policy registry:** versioned retention rules in UTC with category, trigger, hold conditions, legal/accounting approval reference, action, destination semantics, review date and dry-run result. No duration becomes active without Owner and relevant legal/accounting approval.
3. **Privacy request case:** authenticated request, verified identity, scope, request type, deadlines tracked as configurable legal-policy data (not hard-coded), status, reviewer, linked tenant records, provider tasks, exclusions, completion evidence. Idempotent state machine; no synchronous cascade delete.
4. **Export pipeline:** tenant-scoped read models and allowlist, generated in private storage, encrypted/download URL short-lived, access audited, automatic package expiry. Export excludes secrets, other tenants, provider tokens, and restricted investigation notes pending legal policy.
5. **Retention engine:** `discover → dry-run → classify → approve → bounded execute → provider reconcile → verify → audit`. Initially report-only; fail closed on ambiguous ownership, holds, active restores/jobs, accounting links, provider timeout, or divergent state.
6. **Deletion ledger/tombstone:** durable non-content suppression marker with pseudonymous subject/tenant references, scope, request state and minimum evidence needed to reapply erasure after restores. Retain only under a reviewed purpose/period. A restore must quarantine stale destructive queues and apply tombstones before customer operations resume.
7. **Provider task adapter:** per-provider asynchronous states `pending/requested/confirmed/retained_legal/failed/manual_review`, retries, idempotency keys, no false completion on timeout. Keep provider-confirmation evidence separate from local deletion.
8. **Immutable evidence boundary:** invoices, issued invoice references, identity/seller snapshots, tax reviews and audit/incident evidence require typed retention exceptions and restricted access; ordinary profile correction must not mutate historical records.

## Roles and data flow boundaries

Determine Controller/Processor role per purpose, not per company. Customer WordPress content/backup processing may be processor work under customer instructions; WP CareGrid account, platform security, billing and its own compliance records may have different roles. Stripe, Számlázz.hu, email, hosting, object storage, Cloudflare and security-feed status require service-, region-, contract- and flow-specific review. Do not infer jurisdiction/transfer basis from SDK presence.

## Recovery/offboarding order

Freeze provisioning and unsafe jobs; verify identity and subscriptions; classify legal/accounting exceptions; disconnect/revoke Connector credentials and admin-access sessions; stop future collection; export if requested; erase/anonymize permitted active records; issue provider deletion requests; retain only approved evidence; mark backups eligible but do not delete while restore/job/hold references exist; allow historical encrypted backups to age out under the approved policy; on any restore replay tombstones before workers and customer access resume.

## Guardrails and closure gates

- Maintain tenant scoping by authenticated customer relationship and policy checks; opaque UUIDs are not authorization.
- No mass deletion on policy edits, cancellation, storage pressure, or account close. Preview exact rows/objects and protect last verified backup, active restore dependencies, financial exceptions, holds, and pending provider work.
- Existing customer backups contain arbitrary WordPress database/files content. A site restore is a separate product workflow and must not invoke platform-wide privacy cleanup.
- Reacceptance vs notice-only, role allocation, lawful bases, retention periods, cross-border safeguards and incident notification decisions require qualified review.
- M12 closes only after request lifecycle, export, safe deletion, dry-run/approved retention, provider reconciliation, backup/restore tombstone replay, contract version evidence, isolation, audit and synthetic staging/CI evidence pass.

## Non-goals

M13 marketing/analytics implementation (M12 defines consent boundary only); M14 general security hardening; M15 production pilot; AI processing/agents; agency/multi-client features; general performance optimization. No final legal prose, provider activation, production action, or deletion is part of planning.
