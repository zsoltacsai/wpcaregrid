# M14.1 Implementation Backlog

| Order | Priority | Objective | Existing evidence reused | Expected code changes | Required tests | Staging / acceptance |
|---|---|---|---|---|---|---|
| 1 | P0 | Expand routes/commands and produce endpoint authorization inventory | route files, Owner/Operator route-contract tests | no, unless gap demonstrated | expanded route contract | read-only staging; every write route classified |
| 2 | P0 | Two-tenant IDOR matrix for customer, billing, privacy, backup, restore, security, Connector and Owner workflows | current policy and foreign-ID tests | test-first | SQLite feature + MySQL for races | isolated synthetic tenants; no cross-tenant state/effects |
| 3 | P0 | Job/command re-resolution and stale-object proof | job idempotency and backup/restore suites | only on reproduced defect | MySQL process gate, fake provider/storage | exact object/tenant association rechecked at effect time |
| 4 | P0 | Reset/activation session invalidation and concurrent one-time token behavior | auth reset/activation tests | conditional narrow fix | all guards, MySQL session/process tests | synthetic accounts; old sessions denied |
| 5 | P0 | RBAC, Owner/Operator boundary, recent re-auth, CSRF | route ability and CSRF suites | only proven missing guard | ability permutations and stale re-auth | authenticated synthetic Owner/Operator; no production credentials |
| 6 | P0 | Rate-limit coverage, key scope and shared-store proof | current limiter registrations and route attachments | targeted attachment/config changes only | 429, key collisions, concurrent requests | verify effective staging cache topology, preserve service health |
| 7 | P1 | Output sinks, audit/log redaction and bounded resource checks in M14.1 paths | Blade escaping, sanitizer, SecurityHeaders tests | only actual defect | hostile values/log capture/oversized request | no secrets in evidence |
| 8 | P0 | Full regression, exact SHA CI and staging verification | required five GitHub jobs | conditional | SQLite/MySQL/Connector/concurrency/quality | strict host key, gates off, synthetic-only |
| 9 | P0 | Update audit matrix and M14.1 closure evidence | all preceding results | docs only | diff/secret scan + exact CI | merge only after review and green gates |

Failure sequence: detect → diagnose → root-cause fix → regression → retest → exact-SHA CI → merge → guarded deploy → repeat affected staging scenario. Never weaken tenant or auth assertions to get a pass.
