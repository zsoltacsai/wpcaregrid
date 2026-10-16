# M12 Wave 8 — External Legal, Accounting and Provider Gate Matrix

These statuses are not approvals and are not changed by this plan. They block the specific actions/claims shown below; they do not inherently prevent synthetic fail-closed engineering validation.

## Legal review

| ID | Unresolved decision/fact | Blocks | Does not block | Wave8 treatment |
|---|---|---|---|---|
| L1 | Controller/processor roles by purpose | Approved role claims, DPA/policy publication, final rights routing | Technical inventory and neutral status workflows | Keep provisional; no legal prose or role assertion |
| L2 | Lawful basis/special-category scope, especially arbitrary site backups | Compliance claims, destructive processing justification, final notice | Synthetic tests and field inventory | Do not assign a basis in code/report |
| L3 | Retention triggers/durations/exceptions/response times | Destructive schedules, deletion and customer deadline promises | Report-only candidates using provisional approved engineering values | Show provisional values and blockers |
| L4 | Rights scope, identity proof, exceptions, correction, existing-user notice | Final export scope/legal promise and activated erasure | Tests of technical allowlist, auth gates, and blocked state | Verify package/status, no legal completeness claim |
| L5 | Required terms/DPA, subprocessor notice/objection and acceptance | Publication, enforcement and contract claims | Version/ledger machinery tests | Keep unpublished/enforcement off |
| L6 | Actual international transfer paths and mechanism | Transfer claims and provider-dependent activation | Code inventory/fact collection | Preserve unknown regions; legal review later |
| L7 | Cookie/consent classification and withdrawal evidence | Nonessential analytics/marketing activation | Necessary session/CSRF technical tests | No M13 integration; analytics remains out of scope |
| L8 | Breach reportability, roles, timing, DPO assessment | Notification decision/automatic legal notice and formal DPO claim | Technical incident evidence capture and escalation state tests | No statutory deadlines or DPO assertion |
| L9 | Customer-facing backup residual/tombstone statements | Erasure promises and backup/tombstone deletion policy | Technical replay protection | State no instant historical/provider erasure claim |
| L10 | Whether/how legal holds apply | Hold-based deletion exceptions and active hold policy | Generic blocker representation/testing | No arbitrary production holds; retain WAIT_LEGAL |
| W7-L-01 | Cooling-off vs statutory request clock/urgent exceptions | Actual production erasure timing and due-date semantics | Product safety preview and synthetic tests | Keep any action whose clock is unresolved blocked |
| W7-L-02 | Legal hold authority, scope, expiry/review/release | Applying/releasing a real hold and deleting affected data | Fail-closed placeholder/blocker tests | Do not create active production hold as test data |
| W7-L-03 | Tombstone purpose, minimum data, access, retention/end-of-life | Production legal basis/retention or destructive expiry of journal | Synthetic replay engineering proof | Journal stays isolated and non-expiring under technical guard until approved |

## Accounting review

| ID | Unresolved decision/fact | Blocks | Does not block | Wave8 treatment |
|---|---|---|---|---|
| C1 | Retention period/trigger per invoice/payment/refund/tax record | Destructive removal/period configuration for accounting categories | Hash comparison and immutability tests | No duration invented |
| C2 | Customer/seller identity fields to retain or separate | Anonymizing fields attached to accounting evidence | Current profile correction if historical records remain unchanged | Preserve immutable snapshot exactly |
| C3 | Correction/storno/tax evidence process | Rewriting/correcting issued history and linking legal correction | Non-mutating snapshot regression | Add no correction semantics by inference |
| C4 | Seller/tax/failed invoice evidence retention | Destructive cleanup/retention activation | Read-only accounting boundary tests | Keep evidence protected and approval status visible |
| W7-A-01 | Unsettled invoices/submissions/refunds/disputes and release evidence | Anonymization/deletion for affected subject while billing state is unresolved | Synthetic no-live-billing execution and blocker behavior | Inject billing blocker; case remains partial/manual review |
| W7-A-02 | Which current identity/tax fields may be removed with retained invoices | Real profile field-level destruction | Synthetic test of approved engineering allowlist with test-only gate | Preserve historical fields/snapshots and `WAIT_ACCOUNTING` |

## Provider factual verification

| ID | Unknown | Blocks | Does not block | Wave8 treatment |
|---|---|---|---|---|
| P1 | Hosting vendor/region/support/contracts/retention | Hosting transfer and provider retention claims; production deletion assumptions | Code-level validation | Unknown remains unknown until dated source evidence |
| P2 | Customer object provider/region/version/replica/delete semantics/credential scope | Any physical customer backup delete or absence claim | Fake-provider state machine | No normal bucket deletion |
| P3 | Active platform S3 runtime and destination/credential facts | Platform backup physical retention and independent disaster domain claim | Local fake-storage journal code-path tests | Wave7 test's local fake does not prove staging S3 durability |
| P4 | Mail vendor/region/DPA/body/bounce retention | External email deletion/transfer claim | Array/log mail E2E | No external mail send |
| P5 | Stripe mode/region/DPA/subprocessors/deletion support | Provider account deletion or live Stripe privacy claim | TEST/fake billing reconciliation | Never call live Stripe |
| P6 | Számlázz mode/account/transmitted fields/contracts | Invoice-provider deletion/data transfer claim | Snapshot invariance tests | Do not contact/mutate provider |
| P7 | Cloudflare route/proxy/log/region | Edge-log transfer/retention claim | Local route/auth tests | Current runtime facts must be read-only if later needed |
| P8 | Active vulnerability feed and request data | Feed-specific personal-data/transfer claim | Scanner tests with fixture feed | Keep no-completed-scan warning; no inference of customer identifiers |
| P9 | Analytics tags injected outside repository | Cookie/analytics transfer/consent claim | No-analytics source check | M13 out of scope; runtime edge fact unresolved |
| P10 | AI provider personal data flow | Any AI processing claim | None; source audit found no current flow | No AI feature/provider in M12 |
| P11 | Google Drive optional M9 path active? | Secondary destination/transfer/deletion claim if active | Current app tests | No current adapter found; reverify only if architecture changes |
| W7-P-01 | Per-provider versioning, object lock, delete-marker, replicas, credentials, absence proof | Provider destructive task execution and physical-erasure completion | Synthetic fake-provider reconciliation and technical closure | `WAIT_FACT`; no actual destructive API call |

## M12 closure effect

- **Blocks M12 engineering closure?** No, as long as the affected action remains disabled/blocked and the closure report does not claim the external fact/approval.
- **Blocks a specific destructive action?** Yes: applicable legal/accounting states block subject/profile/accounting categories; `WAIT_FACT` blocks provider physical delete/absence claims.
- **Blocks production activation?** Yes for all affected categories; global and action gates remain false after M12.
- **Blocks public legal publication/enforcement?** Yes for L1/L2/L4/L5/L7 and applicable W3 decisions; legal copy stays unpublished and enforcement OFF until qualified approval.
- **Blocks future M15/M17 readiness?** It may: production pilot/readiness cannot claim validated provider deletion, legal handling, accounting exceptions or production DR until the applicable reviews/facts and audits are completed.

No WAIT state is converted to `PASS` by synthetic tests. Owner-approved technical defaults remain separate from qualified compliance approvals.
