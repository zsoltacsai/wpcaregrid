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
