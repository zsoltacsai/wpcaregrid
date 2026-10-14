# Wave 4 Risk Register

| Risk | Failure mode / impact | Mitigation | Required test/evidence | Gate |
|---|---|---|---|---|
| Unknown duration treated as zero | Unapproved policy appears immediately eligible | Null/invalid policy is blocked; zero is an explicit value meaning eligibility only | Null vs zero boundary tests | Must pass before preview |
| “Eligible” mistaken for authorized deletion | Operator or later worker removes data prematurely | UI says report-only; no delete state/job/API; zero action count | Spy asserts no queue/provider/filesystem mutation even with gate true | Locked safety |
| Stale policy or preview race | Counts differ from activated version | Immutable version/hash, preview binding, optimistic concurrency, stale preview invalidation | Concurrent version-change test | Must pass |
| Missing lock treated as no lock | Backup deleted while restore/update active | Fail closed on unavailable lock/operation source | Missing-source and active-operation tests | Must pass |
| Last recovery point removed | Restore becomes impossible | Separate protected recovery scope and verified-point guard | Last-good point fixture | Locked safety |
| Accounting evidence misclassified as profile | Required evidence reported eligible | Separate category and `BLOCKED_ACCOUNTING` without approval ref | Invoice snapshot/approved rule tests | Accounting review |
| Legal duration inferred from recommendation | UI/report implies compliance | Mark recommendations provisional; legal reference and approval required | Snapshot/UI copy assertion; register review | Legal review |
| Provider object state stale | Missing or retained object reported incorrectly | Include provider reconciliation state; unknown → blocked/manual review | Missing/mismatch/provider-timeout fixtures | Provider facts |
| Partial discovery looks complete | False “0 eligible” assurance | Scanned coverage, unknown/error counts, partial banner | Truncated source/timeout test | Must pass |
| PII leakage in preview | Aggregate report exposes account/site data | Aggregate by category; opaque references; bounded Owner drill-down with audit | Secret/PII sentinel scan | Security review |
| Unbounded scan exhausts DB/storage | Queue/database contention | Indexed keyset pages, rate/batch caps, no payload hydration | Query count/plan/performance fixture | Load review |
| New policy retroactively reinterprets history | Audit cannot reproduce prior decisions | Pin policy version and evaluation time; append new evaluations | Version immutability test | Must pass |
| Tenant boundary bypass | Cross-tenant inventory disclosure | Reuse tenant authorization; separate Owner aggregates | Cross-tenant feature tests | Must pass |
| Retention accepted as legal approval | Unsafe deletion later | Separate Owner defaults from review status; accounting lock cannot be lowered | Authorization state tests | Legal/accounting review |
| Browser/session hygiene recurrence | Unrelated page data in planning evidence | Fresh isolated context, verify origin/identity, stop unknown tab; fallback to HTTP/CLI | Validation procedure checklist | Mandatory operational rule |
| Destructive gate accidentally enabled | Wave 4 performs irreversible work | Wave 4 path has no executor/consumer; keep global gate false; config check | Test with gate true asserts no action | Must pass |
| Existing backup retention regression | Active plan behavior changes while adding report | Read-only adapter; do not modify ApplyRetention in Wave 4 | Before/after source/provider state test | Must pass |
| Anonymized email reconnects new registrant | Identity confusion/data leak | Unique non-routable placeholder; re-registration isolation; tombstone by opaque ID not email | Re-registration + restore replay test in later erasure wave | Later destructive gate |
| Accounting/legal value unresolved | Candidate report appears authoritative | Explicit blocker codes and visible provisional labels | Preview fixture with unapproved policies | Owner/legal/accounting review |

## Residual design constraints

Wave 4 is ready only as a report-only evaluator. A later destructive wave must independently revalidate current state immediately before each action and obtain separate Owner/legal/accounting approvals. Planning completion cannot turn on `M12_DESTRUCTIVE_RETENTION_ENABLED`.
