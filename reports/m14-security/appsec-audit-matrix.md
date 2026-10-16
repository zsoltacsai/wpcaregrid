# M14 AppSec Audit Matrix — Planning Review

Evidence pointers are repository-relative. `PARTIAL` means a control or test exists but complete audit evidence does not. No runtime tests were run during this planning pass.

| ID | Area | Disposition | Evidence / current control | Gap and required proof | Status |
|---|---|---|---|---|---|
| AS-01 | Authentication/session | High-priority review; no exploit confirmed | Separate customer/Owner/Operator guards; login regenerates sessions; logout invalidates; `config/session.php` uses DB, 120-minute idle, HttpOnly, SameSite=Lax and JSON serialization | prove reset/activation single use, password/role change revocation, absolute-lifetime policy and stale session behavior | PARTIAL |
| AS-02 | Owner/Operator RBAC | High | `EnsureOwner`, `EnsureOperator`, explicit route `can:` abilities; operator permission enum; `AuthorizationMatrixTest`, Owner and Operator tests | direct service/job paths and least privilege for every permission combination; forbidden cross-console config changes | PARTIAL |
| AS-03 | Tenant IDOR | Critical-impact class; no defect confirmed | `WebsitePolicy`, customer middleware, scoped nested bindings, authorization/backup/privacy tests | full two-tenant matrix across read/update/delete/export/restore/billing/security/provider/replay, web/API/jobs; search results alone are not findings | PARTIAL |
| AS-04 | CSRF | Medium | `bootstrap/app.php` exempts only Stripe webhook; browser route files load `web`; `EnforcesCsrf` tests | assert all state-changing browser routes are protected and no other exceptions exist | PARTIAL |
| AS-05 | XSS/output encoding | Medium | Blade escaping, `HtmlSanitizer`, CSP config and `ProductionHardeningTest` | adversarial Owner/site/plugin/provider values; raw HTML/attribute/JSON script sink inventory; CSP staging mode verification | PARTIAL |
| AS-06 | SSRF/outbound HTTP | High | `PublicOrigin`, `PublicAddressLookup`, `SafeRedirect`, `WebsiteProbe` pin DNS and validate redirects; fixed provider clients | classify every outbound call; test IPv4/IPv6/link-local/metadata, rebinding and redirect races; preserve authorized private-host policy | PARTIAL |
| AS-07 | Stripe webhook | High-priority | Stripe SDK signature verification, unique inbox event IDs, idempotent handler/tests | timestamp tolerance, duplicate races/redrive, key rotation and redacted failure behavior | PARTIAL |
| AS-08 | Rate limiting/resource abuse | Medium | named limiters cover login, account, checkout, Connector, Owner/Operator actions | route-to-limiter completeness, per-site/token fairness, cross-worker store, upload/depth/queue cost caps | PARTIAL |
| AS-09 | Connector credentials/replay | High-priority | 48-character bearer format, stored credential lookup, active check, exact Website UUID binding, production HTTPS default, throttles | rotation/re-pair/revoke/expiry, in-flight revocation and replay recovery contract | PARTIAL |
| AS-10 | Connector payload trust | High-priority | validators, `InventoryIngest`, `RecordJobResult`, whitelisted job types, object-size limits | cross-site forged result, nested payload/depth/size bounds and job scope at worker effect | PARTIAL |
| AS-11 | Filesystem/archive safety | High-impact; no defect confirmed | Connector `PathGuard`, `FileRestorer`, `TempDir`, symlink checks, file-plan hashes and backup tests | adversarial archive limits, expansion ratio/member count, path aliases, case collisions and TOCTOU/rollback fixtures | PARTIAL |
| AS-12 | Restore/remediation safety | High | `RestoreService`, backup policy, remediation defaults OFF, recent verified full-backup requirement and reversible quarantine | prove every async path checks current authorization/gates, active conflicts, locks and bounded rollback | PARTIAL |
| AS-13 | Secret lifecycle | High | Owner Integration Credentials encrypted under APP_KEY; docs require masked write-only behavior; CI Gitleaks | rotation/last-used/revoke matrix, key recovery/versioning, exception/log/package redaction | PARTIAL |
| AS-14 | Destructive controls | Critical-impact class | M12 gates default false; restore and remediation default false; status surfaces exist | central inventory of all mutation switches and queue-time recheck; M8 synthetic fixture switch is separate and site-bound | PARTIAL |
| AS-15 | CSP/HSTS/secure cookies | Medium | `config/http.php`, `config/session.php`, deployment guidance | staging CSP runtime not read; source fallback `off`; prove staged report-only assessment and policy before enforce | PARTIAL |
| AS-16 | Security scanner/feed | Medium; M14 closure gate | current staging warning: no completed scan; vulnerability source not configured; default `none` reports unavailable | run a current detect-only staging scan after hardening, record coverage/feed freshness/findings and dispositions | OPEN |
| AS-17 | Dependency/CI supply chain | High-priority | five-job GitHub CI; Composer/npm audit; full-history redacted Gitleaks; connector release gate | action/download integrity, branch protection, secret environment scope, artifact provenance and update process | PARTIAL |

## Surface inventory

| Surface | Entry point | Auth / tenant / role | Mutation / side effect | Current control / M14 focus |
|---|---|---|---|---|
| Public marketing/legal | localized home/pricing, sitemap/robots, published legal routes (`routes/web.php`) | public | public rendering only | keep private preview/system surfaces unexposed |
| Customer auth/account | login/reset/activation, `/account/*` | customer guard + customer middleware/policies | profile, sites, billing, privacy, closure | throttle and CSRF present; prove all object scope/session lifecycle |
| Checkout/billing | `/checkout/*`, account subscription routes, `/stripe/webhook` | checkout public selection; account auth; signed Stripe event | Stripe, subscription, mail, invoices | replay/idempotency and client-authority boundaries |
| Owner Console | `/owner/*` (`routes/owner.php`) | Owner guard/role + named `owner.*` ability | customer, staff, content, pricing, legal, backups, privacy, integrations, DNS | sensitive limiters/audit; re-auth/secret exposure and direct authorization |
| Operator Console | `/operator/*` | enabled/verified Operator + named permission | site operations, scan, remediation, admin access | scoped abilities/throttle; verify combinations and Owner separation |
| Connector API | `/api/v1/connector/*` (`routes/api.php`) | bearer, active Connector, exact website UUID, production HTTPS | heartbeat, inventory, jobs, backup transfer, admin-access exchange | scope, token lifecycle/replay and payload bounds |
| Backup/restore | customer/staff routes, Connector API, transfer/retention jobs | website→customer→backup/object relation; explicit staff ability | S3/SFTP backup objects and customer-site file/database writes | integrity checks, locks and protection; test archives, preflight, recovery |
| Security scan/remediation | staff routes, scheduler, result ingestion | staff ability + Connector site scope | scan reads; remediation mutates files/plugin | warning remains open; remediation default off and verified-backup requirement |
| Privacy/retention | customer routes, Owner plans, replay commands | customer owns case; Owner approves; replay recovery-gated | export/rectification; anonymization/deletion switches off | external WAIT states preserved in sync/async paths |
| Platform backup/DR | Owner platform-backup routes, scheduler/recovery commands | Owner ability/system job | private platform DB objects/quarantine | APP_KEY recovery, failure-domain and queue access review |
| Queue/scheduler | `app/Jobs`, console routes and cron wrapper | system identity | background versions of above | fresh tenant/auth/gate checks, duplicate/timeout bounds |
| Deployment/CI | GitHub workflow, staging script, SSH/Supervisor/cron | repository roles and deploy identity | code/schema/runtime switch | exact SHA, secrets, host identity, branch protection and rollback evidence |
| External providers | Stripe, Cloudflare, S3/SFTP, mail, tax, vulnerability feed | server-side credentials | payment, DNS, backup, mail, tax | IAM scope/rotation, timeout/redaction and actual provider facts |

`routes/owner.php`, `routes/operator.php`, `routes/api.php`, `routes/web.php`, `routes/console.php` and `app/Jobs` define the entry points to enumerate exhaustively during M14. A static route-group review is not the completed route/action audit.

## Planning disposition

Confirmed Critical: **0**. Confirmed High exploitable defects: **0**. AS-16 is an open evidence gap, not evidence of clean security. High-impact attack paths remain `PARTIAL` until the prescribed tests and scan are executed.

## Finding-register fields for M14 execution

The matrix above is the planning disposition. At execution, every finding must be reopened with explicit attack path, impact, fix, regression test and release blocker status; this table prepopulates those required dimensions without treating a threat scenario as a discovered vulnerability.

| ID | Attack path | Impact if exploitable | Proposed fix / test | Blocks |
|---|---|---|---|---|
| AS-01 | credential reset, stale session or role change leaves prior session active | account/Owner control persists | one-use/concurrent reset, old-session replay, guard-specific regeneration tests | M14 closure if high; M15 if privileged session survives; M17 recheck |
| AS-02 | direct service or route path bypasses role ability | unauthorized operation/customer data | exhaustive role × route × service negative matrix | M14/M15; any privilege escalation blocks M17 |
| AS-03 | foreign UUID/nested object/job identifier is accepted | cross-tenant disclosure, mutation or external effect | two-tenant web/API/worker matrix; test read/write/export/restore | M14 and M15 immediately; M17 |
| AS-04 | forged cross-origin request reaches authenticated mutator | unauthorized state change | verify `web` CSRF and all exceptions; browser feature tests | M14 if bypass; M15 for sensitive operation; M17 |
| AS-05 | stored input reaches raw HTML/script context | account takeover or admin action | source sink audit, adversarial inputs, sanitizer tests and CSP review | M14 for high-impact XSS; M15/M17 |
| AS-06 | attacker controls/changes hostname or redirect to internal address | internal service/cloud metadata access | address pinning, redirect re-resolution and hostile DNS fixtures | M14/M15/M17 if reachable |
| AS-07 | attacker forges or replays webhook event | duplicate/incorrect billing or account state | signature/timestamp/idempotency/race and key rotation tests | M14 closure; M15 billing audit; M17 |
| AS-08 | repeated expensive requests use new IP/tenant/job dimensions | queue, disk or provider exhaustion | route limiter matrix, aggregate caps and load fixtures | M14 for exploitable exhaustion; M15 operational gate |
| AS-09 | stolen or replayed Connector bearer acts for site | site operations/data access | rotate/revoke/expiry tests, exact-site binding and replay analysis | M14/M15/M17 |
| AS-10 | malicious Connector response or result crosses site/job scope | wrong-site state or unsafe action | strict bounded schemas and worker ownership recheck | M14/M15 immediately; M17 |
| AS-11 | archive member escapes root or expands without bound | arbitrary overwrite, host compromise or outage | path/symlink/collision/ZIP-bomb limits with disposable fixture | M14/M15 immediately; M17 |
| AS-12 | stale restore/remediation worker ignores lock/gate/source | customer data loss or site damage | fresh state recheck, locking, verified safety point and fault injection | M14/M15 immediately; M17 |
| AS-13 | key exposed or cannot be rotated/recovered safely | account/provider compromise or permanent data loss | secret redaction, least privilege, versioned rotation/recovery drill | M14/M15 for exposed key; M17 |
| AS-14 | alternate endpoint/job bypasses global/action switch | uncontrolled destructive operation | centralized switch inventory + gate check at execution-time tests | M14/M15 immediately; M17 |
| AS-15 | weak headers/cookie configuration enables script/session abuse | session theft or click/script attack | actual staging header capture; CSP staged rollout and cookie tests | M14 if high risk unresolved; M15/M17 |
| AS-16 | system reports healthy despite no scanner/feed coverage | false security assurance and undetected known exposure | completed scan with real coverage and documented feed freshness | M14 closure and M15 gate; M17 scan recheck |
| AS-17 | mutable action/download/deploy path is compromised | arbitrary code or secret exfiltration | pin/verify tooling, ruleset proof, exact SHA and rollback audit | M14/M15 if trust chain uncertain; M17 |

## M14.1 source-review addendum — 2026-10-16

This addendum supersedes no historical evidence and does not upgrade static observations to tested PASS. Verified source baseline: primary `039a9ce555909243cd36ff5bd77352d43821915d`; staging executable `a335162936afa2933e348fe7b04956e98e45c5a3` over the approved strict-SSH host key. No runtime changes or staging actions were made.

- AS-01 remains `PARTIAL`. The reset callback rotates `remember_token` but source review did not find application `AuthenticateSession`/`logoutOtherDevices` use or explicit session-row revocation. Multi-guard stale-session proof is required before classifying this as a vulnerability.
- AS-02/AS-03 remain `PARTIAL`. Explicit Owner/Operator abilities and representative scoped-binding/foreign-object tests are present; complete direct-service, job, Connector and two-tenant matrices remain required.
- AS-04 remains `PARTIAL`. Bootstrap exposes only the Stripe webhook CSRF exception; enumerate expanded runtime routes and verify every state-changing browser endpoint.
- AS-05 remains `PARTIAL`. Blade escaping, a legal HTML sanitizer and safe JSON-LD serialization are observed; hostile Owner/provider/site content and all raw sinks need adversarial tests.
- AS-08 remains `PARTIAL`. Named limits cover major auth, checkout, Connector, Owner, Operator and customer write classes. Effective multi-node cache behavior and complete route-to-limiter coverage are not established by source defaults.

The detailed implementation and test plan is in [`m14-1-plan.md`](m14-1-plan.md), with evidence boundaries in [`m14-1-findings.md`](m14-1-findings.md). Route declaration counts (235 source declarations) are not expanded runtime counts because PHP CLI was unavailable.

## M14.1 final validation addendum — 2026-10-16

The preceding sections preserve their planning-time status and are superseded only by this dated evidence. The M14.1 implementation/fix lineage is PR #85 (`943af21330451377ef79ddf46a92dd0319372adf`) and PR #86 (candidate `7532df4e692b13047ac30df0ac25c1cd3a5958d9`, merged as `e4659f30140669c9e229c8e583d35e6bb377a979`). Exact candidate CI run [38903198033](https://github.com/zsoltacsai/wpcaregrid/actions/runs/38903198033) passed all five required jobs, including Server MySQL and the required MySQL process/concurrency gate (15 tests / 161 assertions). Staging is on the merged SHA and clean; focused isolated MySQL staging coverage and post-deploy health are recorded in [`m14-1-findings.md`](m14-1-findings.md).

AS-01 password-reset session revocation is fixed and validated. The stale queued backup scope defect is fixed and validated. The shared-cache limiter counter test passed for the exercised case. Runtime inventory generation is complete at 236 routes, 36 command classes, 19 scheduled entries and 9 jobs. **The broader AS-02/AS-03/AS-04/AS-08 controls remain `PARTIAL`** until the route-by-route authorization, two-tenant object/action, CSRF and complete limiter matrices are reconciled with current tests. Output-sink and remaining authorization checks remain scoped to the original partial statuses. AS-16 remains `OPEN`: staging still reports “No completed security scan yet.” No confirmed Critical/High M14.1 issue remains open from the focused implementation, but this is not a completed M14 AppSec audit or a clean-scan assertion.

## M14.1 continuation audit — 2026-10-16

Fresh runtime reconciliation at executable staging SHA `e4659f30140669c9e229c8e583d35e6bb377a979` is recorded in [`m14-1-route-control-reconciliation.md`](m14-1-route-control-reconciliation.md): 236 routes; 141 state-changing entries (130 browser, 10 API, one signed webhook). The only CSRF exemption is the Stripe webhook signature path. Owner and Operator route contracts, area crossover, existing representative IDOR/CSRF/rate-limit tests, and service-level password confirmation are mapped to source suites. A deterministic route-boundary test and an active-session Operator permission-revocation regression have been added in this continuation, but have not yet been executed by exact-head CI.

The following remain `PARTIAL`: AS-02 broad least-privilege/object/action proof; AS-03 full valid two-tenant matrix and zero-side-effect assertions; AS-04 behavioral CSRF rejection across representative customer/Owner/Operator paths beyond the existing suites (a customer profile CSRF regression is now added but pending CI); AS-05 complete output-sink inventory; AS-08 behavioral limiter scope/fairness/abuse tests; and the authenticated Owner Console security walkthrough. No finding is upgraded to PASS based solely on route-source metadata. The prior Owner Console overview was visible in an authenticated tab; the requested Privacy & Retention detail view redirected to Owner sign-in, so the detailed walkthrough is incomplete.

AS-16 remains visibly open as **M14.7_CARRY_FORWARD** (“No completed security scan yet”); the scanner warning is not an M14.1 closure criterion. No security warning is suppressed.
