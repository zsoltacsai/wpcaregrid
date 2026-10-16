# M14.1 Authorization Matrix

Status codes: `OBSERVED` = code evidence; `PARTIAL` = tests exist but matrix incomplete; `REQUIRED` = implementation-phase proof. No row is marked fully passed by this source review.

| Surface / action | Principal and control | Object scope / side effect | Existing evidence | Required adversarial proof | Status |
|---|---|---|---|---|---|
| Customer account/profile/privacy | `web` guard, `customer`, policies | Authenticated user’s customer; export/rectification/closure | Account routes group; `CustomerPolicy`, `PrivacyRequestPolicy`; account/privacy tests | foreign valid case/export IDs; direct service calls; stale customer linkage | PARTIAL |
| Customer website/backup/restore | `web`, `customer`, `WebsitePolicy`, scoped nested bindings | website→customer→backup/object; restore is high impact | `AccountBackupsTest`, account authorization tests | foreign website+backup pair, ID swap, direct object download, queue boundary | PARTIAL |
| Billing/subscription/invoice | customer policy; Stripe/webhook system path | subscription/customer; immutable financial history | billing and M11 tests | foreign UUID, portal access, duplicate webhook vs privacy state; no cross-account invoice link | PARTIAL |
| Owner read/config/action | `owner` guard, `EnsureOwner`, route `can:owner.*` | global platform/admin scopes; sensitive changes separately throttled | Owner access/route-contract/CSRF tests | enumerate every ability, role mutation during session, non-Owner and Operator crossover, direct workflow services | PARTIAL |
| Operator site operations | `operator` guard, `EnsureOperator`, `can:operator.*` | assigned website/permission; restore, remediation and access are sensitive | Operator authorization/security tests | permission revoked between queue and effect; cross-site IDs; least-privilege combinations | PARTIAL |
| Connector API | bearer credential, active Connector, matching website UUID header | exact connector website; jobs/backups/admin-access API | `AuthenticateConnector`, Connector API tests | swapped IDs/header, revoked key race, second site object, malformed/oversized payload (deep payload hardening remains later scope) | PARTIAL |
| System jobs/commands | system identity; usually row/relationship re-resolution | queued DB IDs, external/provider effects | job-specific tests and idempotency patterns | deleted/reparented row, cross-tenant relation change, retry/concurrency, auth/gate recheck | PARTIAL |
| Privacy execution gates | Owner ability + recent password confirmation + exact preview + global/action gates | single subject/action; journal-before-mutation | Wave 7 approval and execution-plan tests; gates default false per prior evidence | replayed approval, source/policy change, cross-tenant target, queue-time vs execution-time gate | PARTIAL |
| Public and auth utility routes | guest/web middleware; Stripe webhook signature/CSRF exception | No private object response before auth | route source and auth tests | enumerate signed/temp URLs, expiry, ID guessing, no-store/noindex and rate-limit coverage | PARTIAL |

Route inventory baseline: source declarations `web=68`, `owner=122`, `operator=32`, `api=13`; expanded route list is a required first implementation artifact because PHP CLI was unavailable in planning. All 122 Owner and 32 Operator declarations must map to exact abilities; route count alone is not proof.

Acceptance: unauthorized valid foreign object attempts return repository-consistent 403/404, produce no state change or side effect, and disclose no foreign attributes. UI filtering is never accepted as authorization.

## Current runtime mapping — 2026-10-16

The staging route list at executable SHA `e4659f30140669c9e229c8e583d35e6bb377a979` still contains 236 routes. The expanded per-route inventory, including current method, URI, route name, declared guard/role/ability, route-object placeholders, transport boundary, limiter, re-auth note, side-effect classification, existing test suite references and per-row disposition, is [`m14-1-route-control-reconciliation.md`](m14-1-route-control-reconciliation.md).

This makes route declarations enumerable and source-mapped. It does **not** mean every row has route-specific behavior tests: the manifest explicitly marks behavior/object-scope gaps. `AuthorizationMatrixTest` supplies broad wrong-role/anonymous denial across account/Operator/Owner routes; it does not supply valid foreign object IDs for each route. Existing focused foreign-object tests cover selected Operator backup-restore and admin-access cases. AS-02/AS-03 therefore remain `PARTIAL` until exhaustive action and side-effect evidence is added.

## Final audit closure reconciliation — 2026-10-16

The statement above is historical and superseded for M14.1 disposition. All 236 route rows are now marked `RECONCILED` and point to the relevant class-level route contract or behavioral evidence. Principal counts: Owner 122; Operator 32; Customer 39; public read-only 16; Connector API 13; public auth 8; system/utility 5; webhook 1. All 141 state-changing routes are classified: 130 browser mutators (all protected by framework CSRF middleware), 10 Connector API mutators (authenticated API boundary), and one signed/idempotent Stripe webhook.

The synthetic two-tenant matrices record 38 valid foreign-object attempts and 20 explicit grouped zero-side-effect invariants. Covered object/action families include website, backup, restore, pairing/disconnect, admin-access request/token, privacy export artifact, security finding/incident and existing foreign-site paths. Owner and Operator route/ability contracts and active-session revocation cases provide shared evidence for route classes; no assertion is made that every route has a unique test. The broader `m14-1-route-control-reconciliation.md` row table is authoritative for each route’s object family, boundary, limiter, test reference and disposition.

Final M14.1 dispositions: AS-02 `PASS_WITH_MEDIUM_RESIDUAL`; AS-03 `PASS_WITH_MEDIUM_RESIDUAL`. No unresolved Critical/High authorization or tenant-isolation defect was found. The remaining residual is bounded breadth beyond current class-level proof and is carried to the broader M14/M17 review.
