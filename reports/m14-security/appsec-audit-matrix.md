# M14 AppSec Audit Matrix — Planning Review

Evidence pointers are repository-relative. `PARTIAL` means a control or test exists but complete audit evidence does not. No runtime tests were run during this planning pass.

| ID | Area | Disposition | Evidence / current control | Gap and required proof | Status |
|---|---|---|---|---|---|
| AS-01 | Authentication/session | High-priority review; no exploit confirmed | Separate customer/Owner/Operator guards; login regenerates sessions; logout invalidates; `config/session.php` uses DB, 120-minute idle, HttpOnly, SameSite=Lax and JSON serialization | prove reset/activation single use, password/role change revocation, absolute-lifetime policy and stale session behavior | PARTIAL |
| AS-02 | Owner/Operator RBAC | High | `EnsureOwner`, `EnsureOperator`, explicit route `can:` abilities; operator permission enum; 122 Owner/32 Operator route contract and synthetic Owner walkthrough | direct service/async breadth remains for future broader audit; no known bypass | PASS_WITH_MEDIUM_RESIDUAL |
| AS-03 | Tenant IDOR | Critical-impact class; no defect confirmed | `WebsitePolicy`, customer middleware, scoped nested bindings; 38 valid foreign-object attempts and 20 grouped zero-side-effect invariants | residual bespoke coverage for every billing/provider/async sub-action; no known cross-tenant disclosure or mutation | PASS_WITH_MEDIUM_RESIDUAL |
| AS-04 | CSRF | Medium | 130 browser mutators classified on CSRF middleware; Customer/Owner/Operator behavior mapped; 10 Connector API and one signed webhook have explicit non-browser boundaries | no unexplained CSRF exemption; Stripe webhook uses signature and idempotency | PASS |
| AS-05 | XSS/output encoding | Medium | raw Blade/JSON/script/attribute/Owner/site/security/provider sinks classified; sanitized output and structural serialization have hostile tests | no unknown sink in reviewed runtime search; broader M14 AppSec scan remains separate | PASS |
| AS-06 | SSRF/outbound HTTP | High | `PublicOrigin`, `PublicAddressLookup`, `SafeRedirect`, `WebsiteProbe` pin DNS and validate redirects; fixed provider clients | classify every outbound call; test IPv4/IPv6/link-local/metadata, rebinding and redirect races; preserve authorized private-host policy | PARTIAL |
| AS-07 | Stripe webhook | High-priority | Stripe SDK signature verification, unique inbox event IDs, idempotent handler/tests | timestamp tolerance, duplicate races/redrive, key rotation and redacted failure behavior | PARTIAL |
| AS-08 | Rate limiting/resource abuse | Medium | 27 limiter definitions / 31 middleware forms mapped; 12 classes behavior-probed, including reset fairness and shared store | 15 specialized aliases lack individual behavioral 429/fairness probes; bounded medium residual | PASS_WITH_MEDIUM_RESIDUAL |
| AS-09 | Connector credentials/replay | High-priority | 48-character bearer format, stored credential lookup, active check, exact Website UUID binding, production HTTPS default, throttles | rotation/re-pair/revoke/expiry, in-flight revocation and replay recovery contract | PARTIAL |
| AS-10 | Connector payload trust | High-priority | validators, `InventoryIngest`, `RecordJobResult`, whitelisted job types, object-size limits | cross-site forged result, nested payload/depth/size bounds and job scope at worker effect | PARTIAL |
| AS-11 | Filesystem/archive safety | High-impact; no defect confirmed | Connector `PathGuard`, `FileRestorer`, `TempDir`, symlink checks, file-plan hashes and backup tests | adversarial archive limits, expansion ratio/member count, path aliases, case collisions and TOCTOU/rollback fixtures | PARTIAL |
| AS-12 | Restore/remediation safety | High | `RestoreService`, backup policy, remediation defaults OFF, recent verified full-backup requirement and reversible quarantine | prove every async path checks current authorization/gates, active conflicts, locks and bounded rollback | PARTIAL |
| AS-13 | Secret lifecycle | High | Owner Integration Credentials encrypted under APP_KEY; docs require masked write-only behavior; CI Gitleaks | rotation/last-used/revoke matrix, key recovery/versioning, exception/log/package redaction | PARTIAL |
| AS-14 | Destructive controls | Critical-impact class | M12 gates default false; restore and remediation default false; status surfaces exist | central inventory of all mutation switches and queue-time recheck; M8 synthetic fixture switch is separate and site-bound | PARTIAL |
| AS-15 | CSP/HSTS/secure cookies | Medium | `config/http.php`, `config/session.php`, deployment guidance | staging CSP runtime not read; source fallback `off`; prove staged report-only assessment and policy before enforce | PARTIAL |
| AS-16 | Security scanner/feed | Medium; M14.7 carry-forward | staging warning remains: “No completed security scan yet” | detect-only scan and feed review remain M14.7_CARRY_FORWARD; not an M14.1 closure gate | M14.7_CARRY_FORWARD |
| AS-17 | Dependency/CI supply chain | High-priority | five-job GitHub CI; Composer/npm audit; full-history redacted Gitleaks; connector release gate | action/download integrity, branch protection, secret environment scope, artifact provenance and update process | PARTIAL |

## M14.1 final audit closure addendum — 2026-10-16

This addendum supersedes the M14.1 statuses above for the bounded M14.1 application-security scope only. It does not assert completion of the broader M14 AppSec campaign. Exact-head CI evidence is recorded in `m14-1-closure-report.md`; the 236 route rows and transport boundaries are in `m14-1-route-control-reconciliation.md`.

| Control | Final M14.1 disposition | Evidence / residual |
|---|---|---|
| AS-02 Owner/Operator RBAC | PASS_WITH_MEDIUM_RESIDUAL | All 122 Owner and 32 Operator route rows reconciled; ability contract and negative combinations, cross-console denial, stale Operator permission and disable-after-login cases, plus synthetic Owner security walkthrough are covered. Direct behavior is shared across route contracts and representative actions; not a full independent penetration test. |
| AS-03 Tenant IDOR | PASS_WITH_MEDIUM_RESIDUAL | Two-tenant valid-object matrices cover website/backup/restore/pairing/admin-access, signed privacy export, and Operator security findings/incidents. Thirty-eight foreign-object attempts and 20 explicit grouped zero-side-effect invariants are recorded; residual breadth remains for every billing/provider/async sub-action, which is not upgraded to route-specific behavioral coverage. |
| AS-04 CSRF | PASS | All 130 browser mutators classified through the `web`/CSRF middleware boundary; Customer, Owner, Operator and sensitive-action behavioral rejection evidence is mapped. Ten Connector API writes use their explicit authenticated API boundary; Stripe webhook uses signature verification and idempotency. Three logout actions are session teardown, not CSRF-exempt business mutations. |
| AS-05 Output/XSS | PASS | Relevant raw Blade, JSON, script, attribute, Owner content, site/security/provider output sinks are inventoried as escaped, sanitized with tests, structurally serialized or safe constants. Hostile-rendering coverage is mapped; no unknown sink remains in the reviewed runtime search. |
| AS-08 Rate limiting | PASS_WITH_MEDIUM_RESIDUAL | All runtime mutators reconciled to the 27 named limiter definitions/31 attached middleware forms, with bounded 429, reset/fairness and shared-store behavior tests. Fifteen specialized limiter aliases have source/route mapping but lack individual behavioral 429/fairness probes; this is the only M14.1 medium residual carried here. |
| AS-16 Security scan/feed | M14.7_CARRY_FORWARD | Staging warning “No completed security scan yet” remains visible and is not a M14.1 closure criterion. |

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

The following remain `PARTIAL`: AS-02 broad least-privilege/object/action proof; AS-03 full valid two-tenant matrix and zero-side-effect assertions; AS-04 behavioral CSRF rejection across representative customer/Owner/Operator paths beyond existing suites (the customer profile CSRF regression now passes in exact-head CI but does not cover the full route set); AS-05 complete output-sink inventory; AS-08 behavioral limiter scope/fairness/abuse tests; and the authenticated Owner Console security walkthrough. No finding is upgraded to PASS based solely on route-source metadata. The prior Owner Console overview was visible in an authenticated tab; the requested Privacy & Retention detail view redirected to Owner sign-in, so the detailed walkthrough is incomplete.

AS-16 remains visibly open as **M14.7_CARRY_FORWARD** (“No completed security scan yet”); the scanner warning is not an M14.1 closure criterion. No security warning is suppressed.

## M14.2 source reconciliation — 2026-10-16

This addendum uses primary 18ed2da5b0862b701ba19da6ef40b183ed6c44fb, M14.1 closure report and current source. It does not upgrade untested controls to PASS. Connector route source has 13 declarations (10 mutators, 3 reads), plugin version 0.4.5, API v1. Current source shows one-time pairing, server-side SHA-256 token lookup, encrypted plugin token option, exact website UUID binding, Connector/job/backup scoping, per-Connector limits, Stripe signed/encrypted/idempotent inbox, path validation and staged restore.

M14-04/AS-09 credential lifecycle and replay, M14-05/AS-10 payload bounds, M14-06/AS-11 filesystem/archive ceilings and race resistance, M14-07/AS-07 webhook timestamp/rotation/concurrency, AS-06 outbound destination/redirect proof, relevant AS-12 restore safety and AS-13 secret exposure remain VALIDATION_REQUIRED for M14.2. No confirmed Critical or High M14.2 finding is established in this bounded planning review. See m14-2-findings.md and supporting M14.2 matrices. AS-16 remains M14.7_CARRY_FORWARD. M12 gates remain false.

## M14.2 implementation validation update — 2026-10-16

The M14.2 implementation candidate is under validation on `codex/m14-2-connector-security-implementation`, based on planning-merged primary `bec597c76e668a81c699171c684c0e5e8866d14a`. Executable changes currently cover bounded Connector/backup request bodies, Stripe webhook byte/timestamp checks, Connector response bounds and redirect policy, bounded filesystem archive read/write, staging path/race checks, and release package version `0.4.6`. Local Laravel SQLite, Connector WordPress SQLite/MySQL, Pint, PHP syntax, Composer audits, frontend build and npm contract tests have passed. Exact-head GitHub CI, server MySQL/process-concurrency evidence, guarded staging deploy/validation, post-deploy M12-gate readback, and cleanup remain pending; this is not an M14.2 closure assertion.

The Connector site-health probes are intentionally same-site checks using configured `home_url()` / `rest_url()`, carry no Connector bearer, now disable redirects and cap response bodies. Server-side website probing continues to use `PublicOrigin` / `SafeRedirect`. Fixed WordPress.org checksum/remediation hosts use verified TLS, disabled redirects and bounded bodies. `WP_CAREGRID_API_URL` remains deployment configuration (never a request/job field); the API client requires HTTPS except explicit loopback development configuration, sends bearer only to that configured URL, disables redirects and bounds responses. Destination/rebinding evidence is limited to these source controls and fake-HTTP tests; no live hostile-DNS or external network probing was performed.

AS-16 remains `M14.7_CARRY_FORWARD` and the staging warning remains visible. M12 destructive and synthetic gates were not changed by this implementation work.

## M14.2 final implementation and staging closure — 2026-10-16

M14.2 implementation PR #90 merged as `c5e0edabd8bd1661f72eb20330c5ca2f4375b9c5`; exact candidate CI `38961580730` passed all five required jobs. Guarded staging deployment installed that exact SHA. Isolated staging synthetic validation passed 98 tests / 808 assertions; health and all six M12 gates were read back after cleanup, and the temporary runtime, DB and test user were verified absent. Details and the explicit residual are in [M14.2 closure report](m14-2-closure-report.md).

The Connector/webhook portions of AS-06, AS-09, AS-10, AS-11 and AS-13 are implemented and validated for the M14.2 scope. No Critical/High defect was found. The connected synthetic staging WordPress site remains on Connector 0.4.5; the 0.4.6 package passed both real-WordPress CI jobs, but a live staging re-pair/upgrade was not attempted without a safe reversible WP administration path. Broader hosting compatibility remains M14.3, and full scan/independent AppSec work remains M14.7. Preserve the visible scanner warning.

## M14.2 Connector-scope reconciliation — 2026-10-16

The Connector credential, payload, archive, egress, and webhook portions of the existing AS-06/09/10/11/13 review are closed for this milestone on PR #90 and deployed SHA `c5e0edabd8bd1661f72eb20330c5ca2f4375b9c5`. Evidence: five green required CI jobs, real WordPress Connector 0.4.6 suites on SQLite/MySQL, 98/808 isolated staging synthetic Laravel assertions, healthy post-cleanup staging, and all M12 gates false. The no-hostile-live-DNS probe and staging paired fixture at 0.4.5 remain explicitly limited; the latter was not re-paired without a safe recovery path. This scoped M14.2 closure does not claim broader M14 audit completion.

AS-16 remains `M14.7_CARRY_FORWARD`; the visible warning “No completed security scan yet” is intentionally preserved and is not a blocker to this M14.2 scope. No penetration-test certification or production authorization is claimed.
