# Failure and concurrency matrix

| Scenario | Required safe result | Recovery / evidence |
|---|---|---|
| Provider delete timeout / ambiguous response | Keep `requested/pending`; do not claim deletion | retry same idempotency key; provider read-after-write; manual review if provider cannot confirm |
| Multi-object backup partial delete | Mark `partially_deleted`; preserve manifest/metadata and block restore | enumerate each object state, retry missing deletes, verify provider versions; audit exact outcome |
| Export generation failure | Request remains failed/retryable; no partial public artifact | clean private temp files; idempotent job; re-authorize before issuing a new short URL |
| Retention worker crash | Resume from durable task checkpoint without duplicate delete | lease expiry + idempotency; bounded batches; report-only diff before first destructive rollout |
| Legal hold conflicts with expiry | Hold blocks destructive step; report conflict, never bypass | authorized hold release with reason and audit; review scope/expiration of hold |
| Financial retention conflicts with account erasure | Delete permitted profile/service data; preserve only approved financial evidence | ACCOUNTING + LEGAL classify fields and periods; do not keep full customer profile by default |
| Stripe deletion unsupported / payment record retained | External task `retained_legal`/`manual_review`, not false complete | provider's official API/contract response and approved exception; local identifiers minimized |
| S3 object delete failure or versioning | metadata stays pending; provider versions may remain | List/read versions under scoped credential, verified completion per approved semantics |
| Email failure while notifying export/deletion | Request state persists; notification retry independent and does not repeat destructive operation | outbox retry/idempotency; alternative contact procedure under approved identity policy |
| Duplicate erasure request | Return same case or link cases without duplicate deletion | unique tenant/request scope or idempotency token; preserve audit |
| Export concurrent with erasure | Serialize or snapshot explicit consistent state; never disclose after access revoked | request lock; invalidate package on deletion start; authenticated re-check at download |
| Subscription reactivation during erasure | No silent cancellation of erasure; either block/reactivation confirmation or explicitly cancel request | lock account lifecycle; audit user action; replay provider state before resuming service |
| Backup creation during erasure | Stop/hold new backups after freeze; no snapshot may silently reintroduce erased data | coordinate per-customer operation lock and tombstone generation; mark in-progress backup and safely cancel/review |
| Webhook after account deletion | Idempotent event processing cannot recreate a purged account without explicit recovery path | provider event ledger kept enough to suppress replay; resolve to tombstone/manual reconciliation |
| Invoice creation during erasure | Complete/resolve in-flight legally required transaction before deletion or hold request | unique issuance idempotency and billing lock; accountant-approved exception |
| Restore from pre-erasure backup | Queue remains quarantined; apply independent tombstones before workers/webhooks/customer access | compare snapshot time vs tombstone journal; replay and independently verify every affected entity |
| Concurrent retention runs | one policy generation and one per-object delete task wins; no double mutation | database/distributed lock, unique tasks, atomic claim, safe stale lease reclaim |
| Provider outage + stale metadata | distinguish unavailable from empty; no orphan auto-delete | read-only reconciliation report; backoff and operator review |
| Retention policy reduced while job active | current operation uses immutable policy version snapshot; new policy waits for review | generation check; preview affected object count and exceptions; explicit approval |
| Hold created during executing batch | next batch halts immediately; in-flight deletion reconciled and reported | row-level claim or batch-level hold check; preserve transition evidence |
| Tombstone journal unavailable during restore | restore stays isolated and customer jobs stay disabled | recover journal from independent backup/replica, verify completeness and sequence before promotion |

Tests must use synthetic tenants and fake provider adapters unless a separate provider integration approval exists. Provider integration mocks do not prove actual remote deletion semantics.
