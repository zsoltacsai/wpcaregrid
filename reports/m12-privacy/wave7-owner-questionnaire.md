# M12 Wave 7 — Consolidated Owner / Review Questionnaire

**Status:** planning gate; no destructive action is authorized.
**Authority:** current primary `697048bb490e2b5e43c4427d520aa28e09d4dcde`; Wave 7 planning branch.
**Global safety state:** `M12_DESTRUCTIVE_RETENTION_ENABLED=false`.

## Owner decision disposition — 2026-10-15

The Owner accepted the recommended defaults for **W7-OP-01, W7-OP-02, W7-OP-03, W7-OP-04, W7-OP-05, and W7-SEC-01** as product/engineering/security defaults. This is not legal, accounting, or provider approval.

| ID | Disposition | Recorded approved default |
|---|---|---|
| W7-OP-01 | `OWNER_APPROVED` | One explicit Owner approval per subject/action plan, recent re-authentication, exact preview hash, typed confirmation, no bulk approval; invalidate approval if source or policy changes. |
| W7-OP-02 | `OWNER_APPROVED` | Eligibility discovery may be automatic; irreversible execution may not be unattended and requires a separately approved per-subject plan/action. |
| W7-OP-03 | `OWNER_APPROVED` | Customer WordPress site content, remote site mutation, and site backups are outside ordinary account erasure; each needs separate scoped authority/approval. |
| W7-OP-04 | `OWNER_APPROVED` | No generic completion while required actions/providers are pending, unknown, failed, retained, or unverified; expose partial/reconciliation outcomes. |
| W7-OP-05 | `OWNER_APPROVED` | Cancel only before the first irreversible action; after start, no rollback promise. Reopening creates a linked case; retries use reconciled idempotency and Owner review. |
| W7-SEC-01 | `OWNER_APPROVED` | Production destructive execution remains separately gated and disabled after Wave7 implementation; later exact-SHA enablement requires its own approvals, evidence, and stop authority. |

Still open and unchanged: **W7-L-01…03 = `WAIT_LEGAL`; W7-A-01…02 = `WAIT_ACCOUNTING`; W7-P-01 = `WAIT_FACT`.** Owner approval does not weaken qualified legal/accounting requirements or resolve unknown provider behavior. No deletion, anonymization, provider deletion, or staging/production mutation is authorized. `M12_DESTRUCTIVE_RETENTION_ENABLED=false` remains mandatory.

This questionnaire contains only decisions not already settled by the RET-01…RET-33, ER-01/ER-08, Wave 2, Wave 3, or Wave 6 decisions. Replies may use `ACCEPT RECOMMENDED DEFAULTS` and compact IDs. Acceptance of a product default is not legal, accounting, security, or provider approval. Review-gated items remain blocked until a qualified, attributable approval or verified provider fact is recorded. The implementation may build safe, non-destructive preparation and reconciliation while those reviews remain open.

## Resolved and not asked again

- Account closure and erasure are separate; erasure requires its own request (ER-01 hybrid, ER-08 separate request).
- The 14-day erasure cancellation window (RET-16), 30-day post-service backup eligibility (RET-04/33), anonymization strategy (RET-02/03), dry-run/impact preview, provider confirmation, final recovery-point protection, and destructive-retention OFF (RET-18…25) are approved product/engineering defaults.
- Commercial active-plan backup cadence and package entitlement belong to M14/M16. They are outside Wave 7.
- Accounting evidence is separated from erasable profile data; immutable snapshots are not rewritten (RET-26/27). Periods and field-level exceptions remain unapproved.
- Wave 6 provides a separate object-storage tombstone journal and replay gate; provider physical-deletion facts, legal purpose/retention, and accounting periods remain unresolved.

## Decisions

### W7-OP-01 — Per-subject destructive approval

- **Question:** What approval should authorize an irreversible execution plan after its exact preview is current?
- **Recommended default:** one explicitly authorized Owner approval per subject and action plan, with recent re-authentication, immutable preview hash, typed confirmation, no bulk approval, and a separate global kill switch. A later policy edit or source change invalidates approval.
- **Why / alternatives:** prevents accidental execution while keeping the workflow operable. Alternatives: dual Owner approval; approval by ticket only; automatic execution (not recommended).
- **Customer / operations:** adds a deliberate step and audit evidence; does not change customer rights.
- **Reversibility:** approval can be revoked before execution begins; provider deletion may be irreversible after dispatch.
- **Classification:** OWNER PRODUCT POLICY; **Owner review:** yes; **Legal:** no new conclusion; **Accounting:** no; **Security:** review implementation; **Owner configurable:** workflow choice only; **blocks Wave 7 planning:** no; **blocks destructive activation:** yes, until accepted and implemented.

### W7-OP-02 — Human approval versus unattended execution

- **Question:** May any account/profile or backup erasure run unattended once a schedule says eligible?
- **Recommended default:** no. Keep eligibility discovery automated, but require an approved per-subject plan and an explicit execution action; no timer or queue may convert eligibility into deletion.
- **Alternatives:** automated execution after one-time policy approval; manual-only preparation and execution.
- **Customer / operations:** slower but predictable, reviewable workload.
- **Reversibility:** policy can change before approval; completed external deletion may not be reversible.
- **Classification:** OWNER PRODUCT POLICY / SECURITY POLICY; **Owner review:** yes; **Legal/Accounting:** separate review gates still apply; **Security:** yes; **Owner configurable:** not as a casual runtime toggle; **blocks planning:** no; **blocks unattended execution:** yes.

### W7-OP-03 — Customer website content scope

- **Question:** Does an account erasure plan include WordPress site/database/files, Connector records, security findings, and backup artifacts automatically?
- **Recommended default:** exclude customer-site content and remote WordPress mutations from account erasure. Treat each website/backup as a separately enumerated scope, with explicit site-owner authorization, restore/update checks, and provider reconciliation. An account profile case does not imply authority to erase an installed site.
- **Alternatives:** include selected backup objects after separate approval; include all site content (not recommended).
- **Customer / operations:** preserves site ownership and avoids surprise loss; requires offboarding coordination.
- **Reversibility:** local metadata can sometimes be corrected; remote site/content deletion is usually hard to reverse.
- **Classification:** OWNER PRODUCT POLICY + LEGAL REVIEW; **Owner review:** yes; **Legal:** yes for authority and customer terms; **Accounting:** no; **Security:** yes; **Owner configurable:** per reviewed scope, not a global inclusion toggle; **blocks planning:** no; **blocks site-content execution:** yes.

### W7-L-01 — Statutory request clocks versus product cooling-off

- **Question:** What approved operational rule reconciles the RET-16 14-day cancellation/safety window with applicable data-subject response and erasure requirements, including urgent or legally restricted cases?
- **Recommended technical default:** the 14-day value is only an engineering safety window; legal clocks, exemptions, exceptions, and any urgent handling must be separately modeled and may override it. Do not delay a legal obligation merely because the product timer is active.
- **Alternatives:** no cooling window; counsel-defined exception workflow.
- **Customer / operations:** may change case timing; no response deadline is asserted here.
- **Reversibility:** workflow configuration can be versioned; missed legal duties cannot be cured by a later setting change.
- **Classification:** LEGAL REVIEW REQUIRED; **Owner review:** acknowledge workflow; **Legal:** yes; **Accounting:** no; **Security:** no; **Owner configurable:** only after approval; **blocks planning:** no; **blocks legally governed execution/timing:** yes.

### W7-L-02 — Legal hold eligibility, authority, review, and release

- **Question:** Which authorities and case types may place or release a hold, what minimum evidence is recorded, who reviews expiry, and can a hold apply to selected data categories rather than an entire account?
- **Recommended technical default:** no generic hold by default; a hold is scoped, reason-coded, actor-attributed, time-bounded or review-dated, least-privilege, audited, and blocks only the approved data scope. Unknown/expired authority becomes manual review, not auto-release or auto-delete.
- **Alternatives:** no hold feature until required; account-wide hold (not recommended).
- **Customer / operations:** may delay erasure for a documented exception; increases review duties.
- **Reversibility:** hold metadata is reversible with review; disclosure/deletion during an invalid hold may be irreversible.
- **Classification:** LEGAL REVIEW REQUIRED; **Owner review:** yes; **Legal:** yes; **Accounting:** as applicable; **Security:** yes; **Owner configurable:** authorities and operational roles only after approval; **blocks planning:** no; **blocks affected execution:** yes.

### W7-L-03 — Tombstone/recovery suppression purpose and retention

- **Question:** What lawful purpose, minimum data, access, retention/expiry, and end-of-life rule are approved for the independent tombstone journal and replay evidence?
- **Recommended technical default:** retain the minimum opaque subject reference, action, sequence, schema, integrity evidence, and replay state required to prevent resurrection; keep external to the rollback domain; no names, prior emails, or content. Mark `WAIT_LEGAL` until duration and basis are approved; never silently expire an unreviewed tombstone.
- **Alternatives:** counsel-approved fixed horizon; legal hold/reference policy; per-action cryptographic key separation if separately designed.
- **Customer / operations:** prevents restored data from becoming active; journal state may outlive the primary database snapshot.
- **Reversibility:** expiry is potentially irreversible; access controls and policy versions are reversible.
- **Classification:** LEGAL REVIEW REQUIRED; **Owner review:** yes; **Legal:** yes; **Accounting:** no unless evidence intersects accounting; **Security:** yes; **blocks planning:** no; **blocks destructive action whose replay proof depends on it:** yes.

### W7-A-01 — Billing disputes and unsettled financial activity

- **Question:** Which open states (unpaid invoice, refund, dispute/chargeback, pending correction/storno, invoice submission/reconciliation) block anonymization or provider/account deletion, and what resolution evidence is sufficient to release the blocker?
- **Recommended technical default:** any unresolved financial/provider state blocks affected execution and routes to manual review; preserve immutable invoice, seller, payment, refund, and tax evidence. Do not infer that account closure settles billing.
- **Alternatives:** narrowly enumerated unblock rules after accountant review; no account erasure until all financial cases close.
- **Customer / operations:** may extend case completion; avoids orphaning payment/reconciliation work.
- **Reversibility:** blocker disposition can be updated with evidence; invoice/history mutation is not treated as reversible.
- **Classification:** ACCOUNTING REVIEW REQUIRED; **Owner review:** yes; **Legal:** as applicable; **Accounting:** yes; **Security:** no; **Owner configurable:** no below approved accounting rules; **blocks planning:** no; **blocks affected execution:** yes.

### W7-A-02 — Erasable fields around retained accounting evidence

- **Question:** Which current customer/billing identity fields may be removed or pseudonymized after an invoice/seller snapshot exists, and what minimum link/evidence must remain for corrections, audit, and customer/accounting support?
- **Recommended technical default:** preserve immutable historical snapshots and required foreign-key/reconciliation references; remove only current-profile direct identifiers explicitly approved for removal; no blanket cascade or rewriting. Await category/field-level accountant and legal determination.
- **Alternatives:** retain current identity fields for a defined approved interval; pseudonymize selected current fields.
- **Customer / operations:** affects re-registration, billing support, and correction workflows.
- **Reversibility:** current profile may be re-entered by a new user; removed historic evidence is not reconstructed from the tombstone.
- **Classification:** ACCOUNTING REVIEW REQUIRED + LEGAL REVIEW REQUIRED; **Owner review:** yes; **Legal/Accounting:** both; **Security:** review access; **Owner configurable:** no below approved minimum; **blocks planning:** no; **blocks profile execution with linked evidence:** yes.

### W7-P-01 — Provider deletion scope and proof per storage/provider

- **Question:** For each actual provider and storage namespace, what delete API scope, version/delete-marker behavior, object-lock/lifecycle behavior, retries, and authoritative absence evidence are available under the configured contract/credentials?
- **Recommended technical default:** capability allowlist per provider; keep `WAIT_FACT` until independently evidenced; request acceptance is not deletion; versioned/replicated copies remain unresolved until provider readback/reconciliation establishes the required scope. Do not issue destructive calls during Wave 7 planning.
- **Alternatives:** manual Owner provider console workflow with evidence; automation only after sandbox proof and least-privilege approval.
- **Customer / operations:** may require manual follow-up and longer completion time.
- **Reversibility:** capability inventory is reversible; provider delete can be irreversible.
- **Classification:** PROVIDER FACT; **Owner review:** provide evidence/contract facts; **Legal:** transfer/retention review as applicable; **Accounting:** no; **Security:** yes; **Owner configurable:** provider allowlist after verification; **blocks planning:** no; **blocks provider execution:** yes.

### W7-OP-04 — Partial completion and case closure

- **Question:** How should a privacy case be presented when primary profile suppression is verified but one or more provider/backups remain pending or legally retained?
- **Recommended technical default:** no single “complete” state until each required action has a terminal reconciled outcome; expose `PARTIAL / PROVIDER_PENDING / RETAINED_LEGAL / MANUAL_REVIEW` and a case summary; do not say all copies were erased. Allow a scoped completion record only where the approved legal workflow permits it.
- **Alternatives:** keep the entire request open until every provider resolves; separate customer-facing request completion from residual-copy tracking (only after legal review).
- **Customer / operations:** avoids false assurance but may leave cases visibly open.
- **Reversibility:** status presentation is reversible; a false completion claim is not.
- **Classification:** OWNER PRODUCT POLICY + LEGAL REVIEW; **Owner review:** yes; **Legal:** yes; **Accounting:** as applicable; **Security:** yes; **Owner configurable:** no status semantics without review; **blocks planning:** no; **blocks final completion semantics:** yes.

### W7-OP-05 — Reopening, cancellation, and retry after execution starts

- **Question:** After cooling-off, what can the customer cancel; after an irreversible action/provider request begins, may a case be reopened, and how are retries authorized?
- **Recommended technical default:** permit idempotent cancellation only before the first irreversible action is committed; once started, do not promise rollback. Reopening creates a linked new case, not reuse of a terminal case; failed steps retry through reconciled idempotency keys and Owner review.
- **Alternatives:** cancel until each provider call; no cancellation after execution begins.
- **Customer / operations:** provides clear boundaries and avoids duplicate provider actions.
- **Reversibility:** pre-execution cancellation reversible by new request; post-provider request may not be reversible.
- **Classification:** OWNER PRODUCT POLICY + LEGAL REVIEW; **Owner review:** yes; **Legal:** yes; **Accounting:** when financial records involved; **Security:** yes; **Owner configurable:** bounded workflow; **blocks planning:** no; **blocks execution workflow:** yes.

### W7-SEC-01 — Production enablement and stop authority

- **Question:** What independent evidence and named authority are required to move from synthetic staging execution to any production destructive authorization, and who may immediately stop it?
- **Recommended technical default:** Wave 7 ships with global and per-action execution disabled. A future production enablement requires separate milestone approval, exact-SHA review, legal/accounting/provider/security gates, successful synthetic recovery/reconciliation, documented Owner authorization, and an always-available Owner/SRE kill switch. No enablement occurs as part of Wave 7 implementation.
- **Alternatives:** no production enablement until a later milestone; staged limited allowlist after all gates.
- **Customer / operations:** no effect until separately authorized.
- **Reversibility:** disabling is immediate; already-submitted external deletes may not be reversible.
- **Classification:** SECURITY POLICY / OWNER PRODUCT POLICY; **Owner review:** yes; **Legal/Accounting:** their category gates; **Security:** yes; **Owner configurable:** enablement is not a routine settings toggle; **blocks planning:** no; **blocks production destructive action:** yes.

## Response format

Reply with `ACCEPT RECOMMENDED DEFAULTS` for product/engineering choices and list overrides by ID. Legal, accounting, and provider entries still require their own qualified approval/evidence; owner acceptance cannot clear those waits. Until resolved, all affected destructive work remains disabled or blocked.
