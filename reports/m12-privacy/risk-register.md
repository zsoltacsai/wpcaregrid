# M12 privacy risk register

| ID / risk | Evidence / severity | Mitigation and test | Decision owner / status |
|---|---|---|---|
| R1 No DSAR/account erasure workflow | No privacy routes/models found; high launch readiness gap | request state machine, authorization, audit, complete data graph; synthetic lifecycle | Product + Legal; unresolved |
| R2 No legal acceptance/version source of truth | footer links only; no legal page implementation in app | immutable legal catalogue, versioned acceptance, legacy unknown | Legal + Owner; unresolved |
| R3 Customer backups contain arbitrary third-party data | Connector archive includes WordPress DB and files | DPA/instructions, content classification, retention, object task ledger, tombstones | Legal + Product + Owner; high |
| R4 Backup retention may delete based on env policy but actual config unknown | `backup:retention` daily; env defaults null, runtime values not inspected | confirm effective policy; central reviewed registry; dry-run and safeguards | Owner + SRE; unresolved |
| R5 Partial provider deletion not fully represented | object loop then one metadata transition; no per-object delete ledger | per-object states, retry/reconciliation, never false complete | Engineering; high |
| R6 Platform backup expiry can be mistaken for deletion | expires metadata only; M9 says no deletion implementation | expose expiry-candidate vs retained; approved separate policy | Owner + Legal; high |
| R7 Erasure undone by restore | platform/site archives predate deletion; no tombstone replay found | independent tombstone journal before workload recovery | SRE + Legal; critical blocker before deletion enablement |
| R8 Immutable accounting records conflict with account delete | identity snapshots/invoice intents immutable | typed exception table, restricted data minimization, accounting approval | Accounting + Legal; unresolved |
| R9 Stripe encrypted raw webhook payload indefinite | durable inbox payload encrypted, no cleanup found | shorter raw payload retention; keep idempotency record as approved | Billing + Legal; unresolved |
| R10 Email outbox can duplicate identifiers/body variables | outbox payload JSON and recipient linked to customer; no purge found | minimize/render on send, terminal payload cleanup and sent fact retention | Product + Legal; unresolved |
| R11 Audit and incident notes may contain personal data | Owner IP field and free-text security notes/events; append-only | minimize, access restrict, redaction, retention with case exception | Security + Legal; unresolved |
| R12 Staging/test data has no unified TTL | synthetic test tables/docs, current staging records not inspected | synthetic-only labels, retention owner, environment census; no production copy without process | Owner + QA; open |
| R13 Provider list/region/contract not established from source | active env values and vendor terms unavailable | Owner-maintained inventory with regions, DPAs, deletion tests and review date | Owner; decision required |
| R14 Cookie law classification unknown | session + locale found; no consent system found | actual cookie/network audit, counsel classification, M13 boundary | Legal + Owner; unresolved |
| R15 External deletion timeout can be misreported | provider semantics differ | asynchronous per-provider task; `manual_review` state; reconciliation | Engineering; design required |
| R16 APP_KEY or backup key loss/compromise blocks recovery | encrypted fields and platform encrypted backups depend on keys | independent escrow/retrieval drill, separation and rotation procedure | Owner + SRE; operational confirmation required |
| R17 Controller/processor role overgeneralization | one SaaS has customer-site, billing and platform processing | per-purpose role and DPA schedule based on facts | Legal; unresolved |
| R18 Tenant export/deletion IDOR | future destructive endpoints not present | policy scoped queries and adversarial two-tenant tests | Engineering; gate |
| R19 Legal hold becomes indefinite/unreviewable | no model; future concept | purpose/authority, scope, expiry/review and audit; no broad hold by default | Legal + Owner; unresolved |
| R20 Scheduler/job replay during restored DB | jobs/failed jobs and M9 recovery quarantine exist; privacy-specific replay policy absent | quarantine stale destructive/outbox work and reconcile provider truth before workers resume | SRE + Billing; gate |

Highest-risk implementation constraint: no destructive retention/erasure can be safely enabled until tombstone/recovery replay, provider partial-delete reconciliation, financial exceptions, operational retention configuration, and legal approvals are settled.
