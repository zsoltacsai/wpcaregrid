# M12 final decision register

> **Wave 4 planning addendum — 2026-10-14:** The new Owner defaults questionnaire is authoritative for report-only retention defaults RET-01 through RET-33. Earlier A/L/C register items remain historical and in force unless explicitly superseded by a reviewed decision. Wave 4 planning does not approve a legal/accounting period or authorize destructive retention. See `retention-defaults-questionnaire.md` and `wave4-retention-engine-plan.md`.

Status date: 2026-10-14. This is a planning register, not legal or accounting approval. The audited source baseline remains `856f7a03d7624158eb5cf9719fdec4776fbf6893`; the remote primary was re-fetched and is unchanged. See `planning-baseline.md` for scope and limitations.

## A — Technical / product Owner decisions

These recommendations do not choose a legal basis, legal duration, controller/processor status, or accounting exception. `PROPOSED_OWNER_DEFAULT` means safe engineering can proceed using the default, subject to Owner review before customer-facing or destructive activation.

| ID | Topic / current question | Recommended default | Status | Reviews | Blocks / can engineering start? | Evidence |
|---|---|---|---|---|---|---|
| A1 | Retention engine behavior | Discover → classify → dry-run → preview → audit; no delete call in initial release | PROPOSED_OWNER_DEFAULT | No legal approval to build report-only; approved rules needed before execution | Retention dry-run can start; destructive execution blocked | `retention-policy-plan.md`; current customer backup retention exists, platform expiry is metadata only |
| A2 | Erasure request workflow | Asynchronous, audited state machine; never synchronous cascade | PROPOSED_OWNER_DEFAULT | Legal review of response scope/timing | Models and workflow can start | `dsar-export-rectification-erasure.md` |
| A3 | Cooling-off UX | Reversible cancel-before-execution; exact delay configurable only after policy review | PROPOSED_OWNER_DEFAULT | Legal review before selecting a response or retention deadline | State machine can start; timed destructive execution cannot | `owner-legal-decisions.md`; no duration chosen |
| A4 | Export format | ZIP with canonical JSON, useful CSVs, human-readable index | PROPOSED_OWNER_DEFAULT | Counsel must approve released scope and exclusions | Export allowlist/design can start; external response workflow needs scope approval | `dsar-export-rectification-erasure.md` |
| A5 | Export delivery | Private temporary artifact; authenticated/short-lived download; automatic expiry; no public permanent URL | PROPOSED_OWNER_DEFAULT | Counsel review of delivery/identity policy | Engineering can start | `security-and-abuse-model.md` |
| A6 | Account closure | Restrict access/revoke sessions first; retain a controlled identity record pending approved exception analysis; no immediate physical cascade | PROPOSED_OWNER_DEFAULT | Legal/accounting determine exceptions | Lifecycle and audit can start | `retention-policy-plan.md` |
| A7 | Customer backup cancellation lifecycle | Paid-through end → restricted/post-service state → policy-controlled deletion; cancellation alone is not deletion | PROPOSED_OWNER_DEFAULT | Legal + Owner approve final duration/notice | State/report-only changes can start; deletion blocked | `backup-retention-and-erasure.md` |
| A8 | Platform backup policy | Separate from site backups; preserve destination-specific minimum recoverable set and last verified recovery point | PROPOSED_OWNER_DEFAULT | Legal review of duration; Owner controls infrastructure | Catalog/dry-run can start; deletion blocked | M9 platform backup records have expiry metadata, not automatic deletion (`retention-policy-plan.md`) |
| A9 | Backup erasure semantics | Remove permitted live data first; historical copies expire under approved policy; replay minimal erasure tombstones before restored data is exposed | PROPOSED_OWNER_DEFAULT | Legal must approve notice and tombstone purpose/period | Tombstone design can start; live erasure and restore promotion gated | `backup-retention-and-erasure.md` |
| A10 | Tombstone registry | Minimal independently protected append-only suppression journal; stable internal/keyed reference, never plain email hash | PROPOSED_OWNER_DEFAULT | Legal review of purpose/retention | Schema/design can start; operational use before destructive erase requires approval | `backup-retention-and-erasure.md` |
| A11 | Legal hold implementation | Do not build broad hold system now; model a narrowly scoped reviewed override only if counsel requires it | PROPOSED_OWNER_DEFAULT | Legal decision required before hold capability is used | Base lifecycle can start | `retention-policy-plan.md` |
| A12 | Staging/test data | Synthetic-only default; production-to-staging copy disabled absent a separately approved controlled process | PROPOSED_OWNER_DEFAULT | No legal decision to enforce engineering guard | Engineering can start | `personal-data-inventory.md`; M8/M11 evidence uses synthetic test resources |
| A13 | Raw webhooks and email bodies | Separate short-lived raw payload/body from longer-lived minimal event/idempotency/delivery facts | PROPOSED_OWNER_DEFAULT | Counsel approves durations and minimum evidence | Data minimization design can start; purge schedule waits | `failure-and-concurrency-matrix.md`; M11 durable inbox/outbox architecture |
| A14 | Privacy request auth | Recent re-authentication; stronger confirmation for erasure; tenant-scoped authorization | PROPOSED_OWNER_DEFAULT | Legal review of identity proof and exceptions | Engineering can start | `security-and-abuse-model.md` |
| A15 | Destructive job kill switch | One global `M12_DESTRUCTIVE_RETENTION_ENABLED=false` default; no per-task bypass | PROPOSED_OWNER_DEFAULT | Owner/legal/security approval required to enable later | Gate and tests can start | This register; `validation-and-closure-plan.md` |
| A16 | M13 consent interface | Store consent category, document/version, timestamp and source only; no analytics integration in M12 | RESOLVED_TECHNICAL | Legal review of categories/consent obligations | Interface can start; GA4/GSC remain M13 | `cookies-consent-and-m13-boundary.md` |
| A17 | Tenant boundary | Reuse existing tenant/customer authorization; Owner convenience cannot bypass scoping | RESOLVED_TECHNICAL | None for technical control; role model still counsel-reviewed | Mandatory from first endpoint | `security-and-abuse-model.md` |
| A18 | Document platform | Immutable versions/locales/effective date/publication/acceptance ledger separate from approved legal prose | RESOLVED_TECHNICAL | Legal approval required for content and reacceptance trigger only | Version machinery can start | `contracts-policies-and-acceptance.md` |

## B — Consolidated legal review questions

| ID | Consolidated question | Technical fact / why legal review is needed | If answer differs | Affected work; before answer? | Status |
|---|---|---|---|---|---|
| L1 | What is WP CareGrid's role for each processing purpose, including customer WordPress content/backups, account/billing, security, platform operations and audit? | The system processes customer-directed site content and its own account, billing and security records; role cannot be inferred globally from product design | Role schedule determines notices, DPA, instructions and rights routing | W1 catalogue may proceed; public role claims/DPA content wait | LEGAL_REVIEW_REQUIRED |
| L2 | What lawful basis and any special-category treatment applies by purpose/data class, including arbitrary information inside WordPress archives? | Backup contents may include site-user/visitor data not known to SaaS; purpose and lawful basis require counsel | Narrow collection, controls, notice and/or product scope may change | Technical inventory proceeds; purpose claims and destructive policy wait | LEGAL_REVIEW_REQUIRED |
| L3 | What retention trigger, duration, exception and response timeline applies to accounts, sites, monitoring, backups, logs, audit, webhook/outbox, tombstones and erasure requests? | Current code has customer-backup retention controls; M9 platform `expires_at` is metadata only; no unified retention registry | Approved schedule drives eligibility, notice and provider tasks | Registry/report-only can start; any delete or deadline promise waits | LEGAL_REVIEW_REQUIRED |
| L4 | What rights response scope, identity verification, restriction/refusal exceptions, correction process and notice/reacceptance rules apply to existing M11 users? | No full privacy request or legal acceptance workflow was found in audited primary source | Export fields, response UX, notices and reacceptance behavior change | Models/UI shell can start; customer response rules and activated acceptance wait | LEGAL_REVIEW_REQUIRED |
| L5 | Which binding documents and DPA terms/roles/instructions are required; how are subprocessors notified and objections handled? | Provider and data flows are partly known, but contract status, roles and customer contracts need confirmation | Standard DPA vs negotiated terms and notice process | Versioned document/registry machinery can start; approved copy/public claims wait | LEGAL_REVIEW_REQUIRED |
| L6 | Which actual international transfer paths exist and what mechanism/safeguards are needed? | Regions, support access and onward processing are not established by source code | Provider choice/region, transfer controls or additional safeguards may be required | Fact collection can start; compliance claim/transfer-dependent launch waits | LEGAL_REVIEW_REQUIRED |
| L7 | Which cookies require consent, how must consent/withdrawal be evidenced, and what is the boundary for future analytics? | Runtime cookies and edge injection were not inspected; source does not show GA4/GSC integration | Consent categories and M13 gates change | Technical consent interface can start; analytics and legal classification wait | LEGAL_REVIEW_REQUIRED |
| L8 | What breach assessment, notification decision, responsible roles, deadlines, authority contact and DPO assessment apply? | Technical incident lifecycle can preserve facts, but legal notification duties are not encoded | Escalation matrix and notification automation/manual workflow change | Incident case/audit foundation can start; deadlines/auto-notice wait | LEGAL_REVIEW_REQUIRED |
| L9 | What customer-facing representation is accurate for residual data in immutable/off-site backups and how/when is tombstone replay disclosed? | Immediate erasure from historical copies is not guaranteed; restore can reintroduce old records without a separate suppression journal | Notice, backup expiry, tombstone scope and recovery order change | Tombstone/replay architecture can start; claims and operational erase wait | LEGAL_REVIEW_REQUIRED |
| L10 | Is a legal hold mechanism needed; what authority, scope, evidence and review/expiry are required? | No hold model currently exists; indefinite generic holds could block rights improperly | Either omit or implement a narrowly scoped reviewable hold | Ordinary report-only foundations proceed; hold use and delete exception wait | LEGAL_REVIEW_REQUIRED |

## C — Consolidated accounting/tax review questions

| ID | Question | Affected technical behavior | Status |
|---|---|---|---|
| C1 | For each accounting record type, what retention period and start event applies to issued invoices, payment/refund evidence, failed attempts and tax records? | Category-specific exceptions and policy triggers; do not choose periods in code | ACCOUNTING_REVIEW_REQUIRED |
| C2 | Which customer and seller identity fields must remain attached to immutable invoice/accounting snapshots, and which may be separated, redacted or pseudonymized? | Erasure/export boundaries; historical snapshots stay immutable unless adviser specifies lawful correction process | ACCOUNTING_REVIEW_REQUIRED |
| C3 | How should invoice correction, credit note/storno, tax verification and tax-decision evidence be preserved and linked? | Append-only correction/evidence records; never rewrite issued snapshot | ACCOUNTING_REVIEW_REQUIRED |
| C4 | What retention applies to seller profile versions, tax-status evidence/references and failed invoice issuance evidence? | Seller/tax evidence exception and deletion state | ACCOUNTING_REVIEW_REQUIRED |

## D — Factual provider / contract verification (no legal conclusion)

| ID | Item | Known | Unknown / evidence needed | Status |
|---|---|---|---|---|
| P1 | Application hosting | Deployment docs describe a VPS deployment model | Current hosting vendor, region, support access, contract/DPA, retention | FACT_VERIFICATION_REQUIRED |
| P2 | Customer backup object storage | Generic S3-compatible, SFTP and local adapters exist | Current environment's selected endpoint/provider, region, bucket, credential scope, versioning and deletion semantics | FACT_VERIFICATION_REQUIRED |
| P3 | Platform backup storage | Dedicated platform-S3 configuration/code path exists; prior M9 handoff reported it not configured at that report date | Current runtime configuration and successful destination verification; do not infer from old report or Owner recollection | FACT_VERIFICATION_REQUIRED |
| P4 | Mail | Laravel mail/outbox path exists; historical staging M10/M11 evidence reports authenticated delivery | Current vendor identity, region, DPA/terms, bounce/body retention | FACT_VERIFICATION_REQUIRED |
| P5 | Stripe | Checkout, subscription, signed webhook integration exists; M11 used TEST flows | Account/mode in each environment, processing regions, DPA/subprocessors, deletion/export evidence | FACT_VERIFICATION_REQUIRED |
| P6 | Számlázz.hu | Invoice integration and gated issuance path exist; M11 evidence reports issuance disabled | Current live vs test setting/account and actual transmitted fields/contract | FACT_VERIFICATION_REQUIRED |
| P7 | Cloudflare | DNS adapter/Owner system checks exist | Actual DNS/proxy/WAF use for each hostname, logs and region | FACT_VERIFICATION_REQUIRED |
| P8 | Vulnerability feed | Optional feed adapter exists (`none`/Wordfence integration) | Active source and whether requests contain site identifiers | FACT_VERIFICATION_REQUIRED |
| P9 | Analytics | No GA4/GSC integration found in audited app source | Edge tag manager/hosting injection or external analytics not observable from repo | FACT_VERIFICATION_REQUIRED |
| P10 | AI provider | No current customer-data flow found in audited app source | Verify separately before any future AI feature; no active provider listed | NOT CURRENTLY USED (source audit only) |
| P11 | Google Drive | Optional M9 design only; no active Drive OAuth/adapter found | None for current inventory unless implementation changes | NOT CURRENTLY USED |

## E — Already resolved / no decision required

- M12 is planning/implementation of privacy lifecycle; M13 owns GA4/GSC implementation. No analytics feature is in this scope.
- Do not expose unrestricted production database restore or one-click bulk deletion; recovery remains controlled and deletion separately gated.
- Document version storage and acceptance evidence are separate from legal text approval; engineering machinery need not wait for final prose.
- Do not expose secrets in export/reporting; do not mutate immutable historical invoice snapshots.
- Staging fixtures default synthetic; do not copy production data into staging.
- No exact legal/accounting duration or legal basis has been selected by this register.

## Implementation gate summary

**Safe to start:** W1 non-destructive foundation; W2 export allowlist design/rectification plumbing with response scope feature-gated; W3 document/version machinery without publishing unapproved text; W4 dry-run retention; W5 request/task orchestration in disabled mode; processor registry and incident/audit foundations.

**Wait:** provider-dependent claims for P1–P9; legally approved purpose/role/basis/content/schedules for L1–L10; accounting data boundary and schedule for C1–C4.

**Destructive gate:** `M12_DESTRUCTIVE_RETENTION_ENABLED=false`. It remains OFF until approved rules are recorded, dry-run output is reviewed, staging synthetic deletion/reconciliation succeeds, tombstone restore replay is proven, and a separate Owner authorization is recorded. No planning approval enables it.
