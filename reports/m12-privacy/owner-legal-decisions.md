# Owner, legal and accounting decision register

Planning assumptions are not approvals. No retention duration or lawful basis is selected here. Technical defaults below are recommendations for a later decision. The coding plan can proceed on non-destructive catalogue, request, audit and dry-run foundations; destructive deletion, final notice publication, or claims of compliance remain blocked pending the relevant approvals.

## Owner decisions — 13

| ID | Decision | Recommended starting default | Alternatives / risk | Approver |
|---|---|---|---|---|
| O1 | Post-cancellation customer/account lifecycle and paid-through/closure states | disable service/access separately; no auto-delete | retain indefinitely vs defined review window; unclear state risks unnecessary data | Owner + Product |
| O2 | Customer WP backup retention by product and cancellation | keep current product cadence; explicit per-plan period and cancellation transition before use | count-only, days-only, both; too short risks recovery, too long increases privacy/storage exposure | Owner + Product + Legal |
| O3 | Platform DB backup retention per destination | daily/weekly/monthly classes with last-good and incident protection | shorter/longer and independent destination schedules; legal review and RPO/RTO impact | Owner + SRE + Legal |
| O4 | Operational log retention and access | minimize structured logs, keep raw exceptions shorter than metadata | long incident logs improve forensics but increase exposure | Owner + Security + Legal |
| O5 | Audit, webhook inbox, email outbox retention controls | preserve minimal idempotency/audit facts, purge raw body/payload separately | keep raw provider payload vs derived facts; duplicate processing risk if keys purged too early | Owner + Billing + Legal |
| O6 | Synthetic staging/test-data TTL and cleanup owner | synthetic-only, labeled, short reviewed TTL with evidence carve-out | retain longer for regression; no prod copy without approved process | Owner + QA |
| O7 | Export format/package and self-service vs Owner-assisted launch | JSON + CSV + index; allowlisted and private short-lived download | self-service earlier vs manual workflow; incomplete scope/oversharing risk | Owner + Product + Legal |
| O8 | Authoritative provider/subprocessor inventory and contract evidence | named service, region and DPA owner per environment | keep generic/incomplete list; makes notices and incident response inaccurate | Owner + Operations |
| O9 | Legal-hold capability/scope, roles and review cadence | do not create broad indefinite hold; manual reviewed scoped hold until policy | no hold vs purpose-limited hold; unauthorized hold may block rights | Owner + Legal |
| O10 | M13 analytics vendors and consent platform choices | no analytics until approved design, consent-gated by default | no analytics / first-party / third party | Owner + Marketing + Legal |
| O11 | Privacy incident contact and on-call roles | named Owner incident lead + backup counsel/provider contacts | outsourced DPO/adviser; unknown contacts delay response | Owner |
| O12 | DPA commercial model and customer access point | downloadable versioned DPA after counsel-approved role schedule | negotiated per customer vs standard terms; role mismatch risk | Owner + Legal |
| O13 | Destructive retention enablement authority | separate Owner approval after report-only period and drill | manual-only vs automatic; mass deletion risk | Owner + Legal + Security |

## Legal review decisions — 18

1. Controller/processor/joint-role map per purpose, including WP-content, account, billing and security.
2. Lawful basis per purpose and any Article 9/special-category condition relevant to customer backup contents.
3. Transparency notice content, controller identity/contact, language and rights process.
4. Post-cancellation and account-erasure eligibility/exceptions and any response/service timelines.
5. Customer backup retention and historical backup erasure disclosure/semantics.
6. Platform backup, restore copy and tombstone retention/erasure rationale.
7. Audit, security incident, IP/UA and forensic evidence retention/pseudonymization.
8. Raw Stripe webhook payload vs event-id/derived billing facts retention.
9. Email/outbox/rendered content and delivery proof retention.
10. Cookie classification, consent requirement, exemptions and withdrawal evidence.
11. Analytics/Consent Mode design and M13 boundary.
12. Terms, Privacy, Cookie Notice and DPA required contents, locales and publication/effective process.
13. Existing M11 users: notice-only vs reacceptance by change type and legacy unknown state.
14. DPA Article 28 terms and customer/controller instruction model.
15. Processor/subprocessor notices, objection/change process and contract status.
16. International transfer destinations/mechanisms and supplementary safeguards.
17. Breach assessment/reportability/notification roles, deadlines, DPO requirement and authority contact.
18. Data access/export scope, identity checks, refusal/restriction exceptions, deletion tombstone purpose, and cooling-off/security proof.

## Accounting decisions — 4

- A1: statutory and tax retention periods by record type and triggering event, including issued invoice/evidence and payment/refund facts.
- A2: which customer/seller identity fields are necessary within retained accounting records; what can be redacted/pseudonymized without invalidating evidence.
- A3: invoice correction, credit-note/storno and preservation process; never mutate issued invoice snapshots.
- A4: retention of VAT/tax verification evidence, tax-decision references, seller profile versions and failed invoice attempts.

No decision is answered by the code's immutable model rule. Obtain written qualified approval, effective date and non-secret reference. Do not put tax IDs, keys, actual customer records or provider credentials in these approvals/reports.

## Normalized continuation — 2026-10-14

The historical counts above are preserved: 13 Owner items, 18 legal items and 4 accounting items. They are consolidated in `final-decision-register.md`; this section does not erase or reinterpret the earlier planning record.

### Resolved technical/product choices

- Retention begins report-only; destructive actions are a separate stage.
- Erasure is asynchronous, audited and idempotent; account restriction/revocation precedes any physical deletion.
- Exports are portable JSON/CSV/index packages delivered through private temporary storage.
- Cancellation and data erasure are separate; cancellation does not immediately delete customer backups.
- Platform backup retention is independent and preserves a destination-specific minimum verified recovery set.
- Historical backup semantics include expiry and tombstone replay, not a promise of instantaneous removal from all copies.
- Staging defaults to synthetic-only; production-to-staging copy remains disabled.
- Raw webhook/email payloads are separable from minimal event/idempotency/delivery facts.
- Privacy operations require recent re-authentication and existing tenant authorization boundaries.
- Document/version machinery can be built before legal prose is approved; M13 owns analytics implementation.
- Global destructive switch default is `M12_DESTRUCTIVE_RETENTION_ENABLED=false` and cannot be overridden from a UI.

These are technical/product defaults; see Owner summary for impact and reversibility. They do not set legal periods or bases.

### Remaining proposed Owner defaults

The 13 historical Owner items remain preserved above. Their normalized breakdown is 18 technical/product entries A1–A18, of which 15 are proposed defaults and 3 are resolved technical decisions; a few historical items moved to legal or factual verification. Review/approve/revise the proposed defaults: report-only retention, async erasure, reversible cooling-off without a selected duration, export format/security, account restriction, post-service backup lifecycle, separate platform recovery retention, tombstone design, no broad hold absent need, synthetic staging, payload minimization, request authentication, destructive kill switch, minimal consent interface and tenant isolation. No manual Owner action blocks Wave 1 non-destructive implementation.

### Remaining qualified review

- **Legal:** 10 consolidated questions L1–L10 in the final register and `legal-review-handoff.md`; they replace the 18 overlapping historical questions for review routing without deleting history.
- **Accounting/tax:** 4 concise questions C1–C4 in the final register and `accounting-review-handoff.md`; no period or exception is invented.
- **Provider facts:** P1–P9 need environment/vendor/contract evidence; P10 AI and P11 Drive are not currently used in audited app source. Provider facts must be established before legal role/transfer conclusions.

### Implementation gate

Wave 1 and report-only foundations may start. Legal content, lawful bases, role claims, schedule values, accounting exceptions, provider deletion claims and destructive execution stay blocked until their corresponding decision/evidence is recorded. Keep `M12_DESTRUCTIVE_RETENTION_ENABLED=false`; do not treat a planning default as authority to delete data.
