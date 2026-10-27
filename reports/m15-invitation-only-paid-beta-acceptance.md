# M15 — Invitation-Only Paid Beta Acceptance Criteria

**Status:** Proposed criteria; no beta activation is authorized.

**Decision:** **NO-GO until every applicable gate below has current evidence and a separate Owner GO.**

**Scope:** Initial cohort of 3–5 invited, paying customers on individually validated supported hosts.

**Baseline:** `c390ff3ef91b7b892f02e5d920e7da778812fb35` (READINESS-1A merge; recheck primary before any release decision).

This document turns the Owner's 2026-10-27 prioritization into measurable acceptance gates. It does not activate the service, authorize contacting customers, enable billing, approve a release, or authorize staging/production operations. Public marketing release and paid service beta are separate decisions. No row below is marked PASS without the listed evidence for the exact candidate release and target environment.

## Decision rules

- **PASS:** The stated threshold is met, evidence is current, attributable to the exact release/host/cohort, and reviewed by its named owner.
- **FAIL:** Evidence shows a threshold is missed or a prohibited condition exists.
- **UNKNOWN:** Evidence is missing, stale, ambiguous, or not attributable to the target. UNKNOWN is NO-GO.
- **BLOCKED:** A required capability, authorization, or external decision does not exist. BLOCKED is NO-GO.
- **NOT APPLICABLE:** Only with a written rationale and approval by the relevant control owner. It cannot be used to bypass a safety gate.

Every evidence record should identify the full release SHA, environment/site identity, collection time (UTC), method, result, reviewer, and redaction/storage location. Never attach secrets, raw credentials, or unnecessary customer content.

## Acceptance matrix

| Gate | Measurable acceptance threshold | Evidence required | Accountable decision owner | Current disposition |
|---|---|---|---|---|
| Cohort size and invitation | 3–5 named customer organizations; no public self-service enrollment; 100% of active beta sites linked to a recorded invitation and accepted terms. Hard cap enforced at 5. | Redacted invite/acceptance ledger and server-side cap verification for the exact candidate release. | Owner + Product | UNKNOWN — no cohort activated |
| Customer authority and consent | 100% of sites have an identified customer administrator with authority over the site, explicit per-operation consent, and an approved service/data-processing agreement before pairing or data processing. | Signed/accepted agreement IDs and consent audit events; no passwords or Connector tokens in the record. | Owner + Legal/Privacy | UNKNOWN |
| Supported-host identity | 100% of proposed sites match an explicit supported-host/provider/version matrix. No ambiguous host, unknown provider, shared production target, or unsupported restore provider. | Per-site provider identity, relevant version tuple, provider compatibility result, and exact site/tenant binding. | Engineering + Owner | BLOCKED for restore until an approved provider is implemented and independently validated; other operation support remains per-site UNKNOWN until measured |
| WordPress/PHP/Connector compatibility | 100% of sites satisfy the approved compatibility matrix and run an approved Connector artifact/API version with verified fingerprint and correct pairing identity. | Exact WordPress/PHP versions, Connector version/API, artifact SHA-256, tenant/site pairing record, and fresh persisted readiness projection. | Engineering | UNKNOWN for beta sites; no staging/customer inventory collected in this task |
| Connector readiness | For each operation proposed for enablement, all required persisted evidence is VERIFIED under the effective freshness policy; no future timestamp, mismatch, stale snapshot, or unknown execution state is represented as ready. Delivered jobs are not counted as running. | Owner/Operator readiness view plus underlying redacted evidence. Status reads must not poll or dispatch. | Operations + Engineering | PR #201 has Integration Ready review; it remains unmerged pending Owner decision, and no site is certified by this document |
| Least privilege and authorization | 100% of beta workflows tested with allowed and denied roles, including cross-tenant/site identifiers. Unauthenticated, customer, and insufficient-permission requests are denied; no unauthorized state or data changes. | Exact-head automated authorization tests and reviewed access-control matrix. | Security + I&QA | BLOCKED pending the Owner decision on unmerged PR #201 and any required beta-specific product-flow tests |
| Backup integrity | Before any operation requiring a safety backup, a site-bound backup must pass the existing integrity/manifest verification; a missing, stale, failed, or cross-site backup blocks that operation. | Backup metadata and integrity evidence bound to the exact site and release. | Operations | UNKNOWN; no backup operation is authorized by this plan |
| Recoverability | A successful backup is insufficient. For each host/provider and restore scope offered to beta, a restore and recovery drill must succeed against disposable data, including failure/recovery paths; unknown providers remain fail-closed. If no provider is accepted, restore is excluded and all UI/terms/support materials must say so. | Independently reviewed, exact-provider drill record with before/after integrity checks, recovery receipt, timing, and cleanup evidence. | Engineering + Security + Owner | BLOCKED — provider implementation/validation is not authorized or complete |
| Security scanning claims | Every exposed scan type has documented, measured coverage and limitations. No claim of complete malware protection follows from an Integrity scan. Any live scanner use needs a separate exact-scope approval, isolated targets, bounded side effects, and current safety-gate evidence. | Synthetic test matrix, staged operational evidence if separately authorized, feed provenance/licensing, and reviewed customer-facing wording. | Security + Owner | BLOCKED for operational scanner assurance; no scan authorized |
| M12 safety gates | All six effective values are freshly read from the target environment using an approved side-effect-free method and satisfy the relevant approved safe-state policy. Any TRUE, UNKNOWN, stale, unreadable, or ambiguous value is NO-GO. No gate may be changed to make the beta pass. | Timestamped, redacted effective-value evidence for the exact environment/release and reviewer sign-off. | Owner + Security | UNKNOWN — this plan performs no gate readback |
| Billing and invoicing | 100% of paid accounts use an Owner-, Legal-, and Accounting-approved billing route with compliant terms, tax treatment, invoice issuance, refunds, and reconciliation. Stripe-live is OFF unless separately approved and validated. Manual bank transfer is not an automatic substitute; its controls also need approval. | Written Accounting/Legal decisions and a synthetic end-to-end billing/reconciliation test with no live charge. | Owner + Legal + Accounting | UNKNOWN; no billing activation authorized |
| Privacy and retention | 100% of applicable privacy notice, data-processing terms, retention/deletion process, support contact, and incident disclosure are approved for the exact beta scope. Relevant M12 controls remain intact. | Approved document versions, retention schedule, deletion evidence plan, and privacy review. | Legal/Privacy + Owner | UNKNOWN |
| Monitoring and alert delivery | Every critical service, Connector, queue, backup, security, and billing failure in the beta scope has an assigned signal, owner, escalation channel, and response target. Proposed acceptance: all synthetic alert tests reach the designated responder within 5 minutes; no test message goes to a real customer. | Alert matrix and timestamped isolated delivery tests; on-call rota and escalation acknowledgement. | Operations + Owner | UNKNOWN; no notifications were sent or routing checked |
| Support and incident response | Named primary and backup responders cover the agreed beta hours. Proposed targets: acknowledge a security/availability incident within 1 hour during covered hours; acknowledge ordinary support within 1 business day. Owner must approve these targets before invitation. | Rota, escalation runbook, and tabletop exercise with recorded timings and action closure. | Owner + Operations | UNKNOWN; response targets are proposals pending approval |
| Capacity and resource limits | Capacity is measured for the exact beta topology; all resource limits and queue concurrency are bounded. No unverified “free disk” assumption. Connector-side capacity monitoring and user warnings are separate backlog items, not evidence supplied here. | Redacted capacity baseline, threshold rationale, alert test, and load/resource test report. | Operations + Engineering | UNKNOWN |
| Release and rollback | Exact release SHA is immutable, seven required CI contexts pass, both PHP 8.4 and 8.5 MySQL concurrency gates pass, required reviews are complete, and a tested rollback/recovery procedure exists. PHP 8.5 staging cutover remains NO-GO until separately accepted. | Exact-head and exact-merge-SHA CI, review receipts, release manifest, rollback exercise evidence. | Release + I&QA + Owner | UNKNOWN for any future beta release; no merge/deploy authorized here |
| Security review | No unresolved Critical/High issue in the beta exposure path; all other findings have explicit Owner acceptance, mitigation, and expiry/review date. Independent AppSec review is required; self-review does not satisfy it. | Independent review report, finding dispositions, and exact release scope. | Independent AppSec + Owner | UNKNOWN / pending required review |
| Beta operating boundary | At most 5 sites; invitation-only; manually supervised onboarding; per-operation consent and authorization retained; no unsupported host restore; no public payment/signup path; pause triggers are defined and exercisable. | Signed operational contract, feature/access boundary review, and pause/disable drill in a non-production environment. | Owner + I&QA | NO-GO — no activation or pilot authorization |

## Stop and pause triggers

Before launch, the Owner must approve named stop authorities and the control that can halt new invitations and affected operations. Pause the affected operation or entire cohort if any of these occurs:

- A site, tenant, Connector, host, provider, or release identity is ambiguous or mismatched.
- Any required safety gate becomes TRUE, UNKNOWN, unreadable, stale, or changes unexpectedly.
- A cross-tenant authorization defect, credential exposure, unauthorized write, or unexplained Connector/queue action is observed.
- A backup verification or required recovery check fails, or a restore host is unsupported.
- Critical monitoring or notification routing is unavailable; capacity thresholds are exceeded; or a critical incident is not acknowledged within its approved target.
- The active cohort would exceed five customers, a customer withdraws required consent, or billing/privacy approval is withdrawn.

Stop new work first. Do not automatically retry destructive operations, unlock a fenced restore, delete evidence, change safety gates, or expand the cohort during incident response.

## Decisions required before a GO review

1. Owner approval of the billing route and cohort terms, with Legal/Privacy and Accounting decisions recorded.
2. Owner approval of the support/incident response targets and named coverage.
3. Engineering and independent AppSec evidence for each supported host/provider and each operation to be offered. Restore stays unavailable until its provider gate is met.
4. Fresh, approved, redacted M12 gate evidence for the exact candidate environment; this document does not provide it.
5. Exact release SHA, exact operation list, site cohort, monitoring and stop controls, and a separate written Owner GO/NO-GO decision.

Passing this matrix can support a later decision only. It does not itself authorize customer contact, account activation, payment collection, staging or production access, Connector pairing, scanning, backups, restore, updates, remediation, or deployment.
