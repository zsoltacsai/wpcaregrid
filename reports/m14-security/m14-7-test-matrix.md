# M14.7 Security Test and Evidence Matrix

## M147-E.2a live preflight — 2026-10-18

| Scenario | Status | Evidence / disposition |
|---|---|---|
| Mandatory authorization record complete before staging connection | BLOCKED | Owner supplied a DRAFT / NOT APPROVED record. It still lacks the host identity pin, active window, authorization reference/final approval, and exact reviewed commands; several RO operations are explicitly blocked pending safety review. Stop rule applied; no connection. |
| Staging identity, revision, runtime, WordPress and Connector | NOT TESTED | No live access; values UNKNOWN. Repository's 0.4.7/API v1 is expectation only. |
| Six effective M12 booleans | NOT TESTED — UNKNOWN | No gate was read or modified. All six remain UNKNOWN. |
| Effective scanner/feed, queue/scheduler, routing, storage, disk and recovery state | NOT TESTED — UNKNOWN | No live interfaces used. No scan/feed/queue/backup/recovery operation occurred. |

See [E.2a preflight evidence](m14-7-e2a-staging-preflight-evidence.md). This is an authorization-blocked attempt, not a completed read-only preflight and not scanner validation. M147-E.2b remains NO-GO / NOT AUTHORIZED.

## M147-E.1 staging readiness plan — 2026-10-18

Offline source review only on primary `57b8cf02d655cb7316648785bd9a1b6b07b148e1`. No staging, production, customer site, live gate, feed, scanner, backup, queue, external service, or browser was accessed or executed. The six live M12 values remain UNKNOWN. All M147-E.2 runbook scenarios below are **NOT EXECUTED — OWNER APPROVAL REQUIRED**.

| Readiness area | Repository evidence | Status / future requirement |
|---|---|---|
| Scan authorization and orchestration | Owner/Operator scan routes and `SecurityScanService`; explicit abilities and route throttles. | Source mapped; live identities, permissions and exact target require read-only verification. |
| Connector flow | Authenticated outbound poll/result API; website-bound credential; allowlisted `SECURITY_SCAN` jobs. | Source mapped; live version, pairing, capability and artifact fingerprint UNKNOWN. |
| Coverage/posture | `SecurityPosture` requires latest recent completed scan and every scanner coverage state completed for Healthy. | Source rule confirmed; live posture UNKNOWN. |
| Feed | Default source `none`; Wordfence v2 configured feed, 72-hour default freshness; scoped M147-F07 synthetic protections. | Live source/license/host/IP/DNS/freshness UNKNOWN; no live feed. |
| Incident/backup | Scan finish calls incident detection; auto-open and auto-backup defaults true; backup request uses `BackupService`. | Side effects material; M147-F04 OPEN. No scan or real side effect. |
| M12 gates | Six exact env-to-config mappings in `config/privacy.php`. | All six live values UNKNOWN and NOT READ; proposed future secret-safe checklist only. |
| Recovery | Deploy procedure has guarded code rollback and pre-deploy DB backup; does not automatically restore DB or reverse migrations. | Live backup/recovery state UNKNOWN; M147-E.2 NO-GO until independently evidenced and approved. |

See the [M147-E.1 readiness plan](m14-7-e1-readiness-plan.md) for the gate worksheet, sequence diagram, exact inventory, proposed test scope and go/no-go rules, [runbook](m14-7-e1-staging-runbook.md) for future procedure, and [risk matrix](m14-7-e1-risk-matrix.md) for per-operation side effects.

Planning baseline was `b0285d36a7aa0dbfe7281282ce8c9c6116c3eb3f`; planning PR #119 merged at `0a0d25c2da256015d5cffad5f419603593ed72b5`. M147-A is closed by PR #120 merged at `a84d03146ac3ba0ba8c47301f61be41052903905`; its merged-primary CI run `39105703168` passed 7/7. The Owner separately approved Level 2 M147-B source/workflow analysis and disposable local synthetic adversarial filename tests only. No scanner, feed, browser, staging, production, or customer-site execution is included. Level 3 operational activity still requires separate authorization. Preserve seven mandatory CI jobs, including the MySQL process/concurrency gate within Server MySQL.

## M147-C local synthetic execution (2026-10-18)

The Owner separately authorized M147-C Level 2 local synthetic security-engine validation. Implementation branch `codex/m147-c-security-engine-validation` was created from and remains based on authoritative primary `d863da22d3439f2fa52a9988297e7b19a4c31f36`. Candidate `1dc8c93a96640a251d0744927ff963e01a6544f9` passed exact-head run [39116313678](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39116313678), 7/7; the Server MySQL process/concurrency gate passed. This evidence-status documentation revision is validated on its own exact head. No live feed, external scan service, staging, production, customer site, browser, real credential, or remote backup was used.

| Scope | Executed evidence | Result / limit |
|---|---|---|
| Laravel security feature regression | PHP 8.4.26, app PHPUnit; `VulnerabilityAndPostureTest`, `SecurityScanTest`, `IncidentLifecycleTest`, `FindingLifecycleTest`, `RemediationTest`, `SecurityVisibilityAndPermissionsTest` | **98 tests / 1,124 assertions passed.** Synthetic Wordfence fixtures cover vulnerable/patched core, plugin and theme versions, duplicate and multiple advisories, unsafe markup escaping, missing/unknown versions as partial, unavailable/stale source not healthy, invalid local imports (including missing title, unsafe ID and unsupported type) preserving the existing index, and fake HTTP redirect/oversize/truncated/empty/wrong-content-type/503/connection-failure preserving feed and index. Test DB/storage are disposable test resources. |
| Connector scanner integration | Disposable WordPress 7.1.3 + SQLite drop-in 1.3.3; Connector 0.4.7 active in the isolated test harness; `wordpress/vendor/bin/phpunit tests/Integration/SecurityScanTest.php` | **22 tests / 159 assertions passed.** Existing integration tests exercise scanner protocol and inert synthetic fixture cases with mocked WordPress.org transport. No live WordPress.org requests were permitted or made for test execution. |
| Disposable local WordPress bootstrap from M147-A | Existing locked fixture bootstrap/health evidence, exact package versions in [M147-A evidence](m14-7-a-execution-evidence.md) | Prior run had actual install, health check and cleanup. Rechecked this run: prior temporary fixture directory is absent. The Connector test harness is separate and disposable. |
| Test isolation | `RefreshDatabase`, per-test temporary local backup storage, fake WordPress HTTP transport, no production `.env` in native test clone, no real provider credentials | No live external service, customer data, remote storage, staging or production access. This is repository/test-harness isolation evidence, not host-level egress firewall certification. |

Coverage remains **synthetic and bounded**. This run did not use a real Wordfence feed or checksum service, did not perform browser E2E, did not run the production/staging scanner, and did not freshly read any of the six M12 live gates. Malware/path/checksum matrix rows below are covered only where existing unit/integration tests say so; unexecuted cases remain NOT TESTED, not implicitly passed. Detailed changes and limits: [M147-C execution evidence](m14-7-c-execution-evidence.md).

M147-A local validation on source SHA `5dc8a4618faaff958621ccc6fde5a2795543054c`: 11 focused harness tests plus 9 existing JavaScript tests, 20 passed, 0 failed, 0 skipped. The actual package-backed WordPress fixture was also installed and health-validated using lockfile-pinned WordPress `7.1.3` and SQLite drop-in `1.3.3`; its Connector `0.4.7` was discovered but remained inactive. The fixed fixture-local SQLite DB was exercised. Egress preflight refusals and actual filesystem cleanup were observed. See [M147-A execution evidence](m14-7-a-execution-evidence.md) for exact test commands/results and limits.

M147-A's actual merged-primary run `39105703168` passed 7/7 on `a84d03146ac3ba0ba8c47301f61be41052903905`; earlier planning/source-head runs are historical and are not substituted for that merge evidence. M147-B final PR-head run `39109648655` passed 7/7 on `e10695f1ff5cd505ae6883e1a5e5678bdd7f31bd`; merged-primary run `39110282933` passed 7/7 on exact merge SHA `0699374432afeb26e036a87477c9a0cad5c5518b`. The Server MySQL process/concurrency gate passed in both runs. No scanner, feed, browser E2E, application side effect, M12 live-gate read, or staging/production operation occurred. Scanner coverage remains `NOT_RUN`.

| Area | Adversarial/synthetic cases | Required evidence | Safe layer / boundary | Stop condition |
|---|---|---|---|---|
| M147-A fixture and evidence harness | staging/production/unknown environment; absent prerequisites; repeated setup/cleanup; unmarked or symlinked fixture path; sibling preservation; fake queue/storage; external destination; evidence secret/control/schema rejection | Locked WordPress/SQLite fixture installed and validated; explicit refusal cases pass; only the fixed marked fixture was removed; evidence stays bounded/allowlisted and scanner coverage remains NOT_RUN | 11 focused Node tests and 9 existing JavaScript tests; real local WordPress CLI bootstrap and fixture-local SQLite health validation. No service, pairing, scanner, or browser started. | Any unmarked/redirected path deletion, real job/storage dispatch, external target, or evidence secret/control leakage |
| Tenant isolation | Two synthetic tenants/sites; read/write foreign website, finding, scan, incident, quarantine, remediation and nested job; stale website/Connector association at execution | Expected 404/403/refusal; zero foreign row/job/audit mutation; service checks tenant relationship, not only route binding | Laravel Feature SQLite, MySQL, MariaDB; deterministic process race on MySQL | Any foreign disclosure or mutation is Critical/High triage; no staging scan until fixed |
| Roles/session/re-auth | Guest/customer/Operator/Owner; revoked permission or session; cross-console route; absent/stale recent auth | Denial before side effect; no provider/site job; audit does not claim success | Feature tests; synthetic users only | Any privilege bypass or required session revoke failure |
| CSRF/XSS/output | Cross-origin mutation; stored/reflected/script/attribute/JSON values in labels/findings/provider errors; Unicode/control encodings | Mutator protection; escaped output; CSP behavior; no executable rendering or secret/log disclosure | Feature tests and isolated local browser checks | Any executable injection or CSRF mutation |
| Connector credential/replay | Missing, wrong-site, revoked, expired, replayed, duplicate and concurrently delivered requests; malformed headers/body; wrong tenant UUID | Correct auth/scope denial; idempotency; bounded response; no credential in logs | Connector API fake request tests; real WordPress harness | Cross-site job delivery or credential exposure |
| Payload/resource bounds | Oversized/deep JSON, too many findings/parts, invalid enum/version/path/hash, repeated partial scan, timeout/cancel | Reject/partial/failed truthfully; bounded memory/storage/queue; prior findings preserved safely | Unit/Feature, MySQL process tests | Unbounded resource behavior or state corruption |
| Vulnerability source | No source, stale index, malformed feed, unavailable URL, valid fixture, patched/unpatched core/plugin/theme, unknown versions, empty inventory | Source identity/version/freshness; expected affected-range matches; unavailable distinct from zero; no invented CVE/severity/patch | Local fake feed/source; no external feed download for unit proof | UI/system says clean when source unavailable/stale or unsupported claim |
| Feed download/parser | Non-HTTPS, redirect, timeout, 4xx/5xx, invalid JSON, extreme file/entry count, path/symlink target, write interruption | Fixed configured origin policy or narrow remediation if gap found; bounded failure; prior good index retained or state becomes unavailable truthfully | Isolated filesystem and HTTP fake; assess current SyncVulnerabilities timeout/stream/error handling before acceptance | Feed source/credential leakage, path escape, corrupt index treated fresh |
| Core/plugin/theme integrity | Correct, modified, missing, unrecognized WordPress core/plugin/theme file; absent checksum API; wrong version; upstream unavailable | Distinguish verified mismatch from inability to verify; checksum/version/release used recorded; no false malware confirmation | Disposable WordPress copy; no customer install | Integrity unknown reported clean, or path outside WordPress root |
| Malware/suspicious rules | Inert marker fixtures under allowed/disallowed paths; benign lookalikes; database/user/cron/config cases; malformed/dangerous names; file changed between scan and action | Rule ID/version, confidence and severity separate; no executing specimen; suspicious ≠ confirmed; false positives documented | Fake filesystem/DB/Connector result; dedicated synthetic WordPress only for integration | Rule causes unintended modification or scanner sets confirmed malware |
| Scan lifecycle/status | Queued/running/completed/partial/failed/cancelled/timeout; scanner unavailable; one complete + one failed; zero scanners complete; stale result UUID; duplicate/out-of-order part | Terminal status matches per-scanner coverage; completed requires actual completed coverage; stale/foreign result rejected; health warning truthful | Laravel Feature tests with synthetic report fixtures | completed/healthy when no scanner completed or coverage missing |
| Findings/incident | Dedup, repeat/resolve only on complete scanner, severity-confidence matrix, high-confidence incident auto-open, auto-backup linkage | Findings metadata only; no source/content/secrets; no auto-confirmed malware; one incident and scoped verified backup only where explicit synthetic scenario exercises default behavior | Feature tests; no real site; if defaults auto-backup, instrument job count and cleanup | Unexpected customer mutation, unverified backup used, or source/content in evidence |
| Remediation safeguards | Gates false, unauthorized operator, wrong website, unconfirmed finding, stale/missing backup, duplicate remediation, changed file hash, plugin disable flag false | Requests refused before job/mutation; recent verified full backup required; disable plugin stays off; audit truthful | Feature tests with fake jobs and local temp files only | Any action proceeds with false gate, without backup, or on customer target |
| Restore/backup | Wrong tenant, stale restore source, traversal/symlink/archive limits, restore racing scan/incident, safety backup | Existing restore protections remain; scan never triggers restore; no destructive backup delete; verified recovery point preserved | Existing unit/Feature and MySQL concurrency suites | Loss, cross-tenant restore or deletion pathway |
| SSRF/redirect | IPv4/IPv6 private/link-local/metadata, alternate encodings, DNS rebinding, redirect chains, timeout/resolution races | Only intended destination or explicitly authorized local test target; auth not forwarded across hosts; bounded request; refusals audited safely | Fake resolver/HTTP transport; no real probing of customer domains | Any private/metadata fetch or credential forwarding |
| Stripe/webhook | Missing/bad signature, stale timestamp, duplicated event, race, malformed body, test-mode boundaries | Reject before mutation; idempotent inbox; no real charge/customer PII | Signed synthetic payload with local test secret | Any unsigned/replayed event changes state |
| M14.5 provider boundary | Three fixed provider IDs, unknown ID, auth failure/timeout/redirect/oversize; customer/site/security/backup sentinels | No default, feature enablement, authorization or arbitrary data argument; request body empty; no cross-provider credential leakage | Existing fake transport tests; no external provider call | Any customer-data egress, custom destination or feature execution |
| M146-F08 tracked secret-path checker output | Owner separately approved M147-B Level 2 local tests. A disposable Git repository test creates annotation-, output-, env-, and masking-like synthetic paths, including newline/control cases. | Exact checker output is fixed redacted text; sensitive tracked files fail the policy check; benign tracked names do not create a false failure; fake runner command files remain untouched. The test does not simulate GitHub's live command parser. | Five filesystem-safe Windows cases passed locally. All 10 hostile cases passed on Linux exact test/source head `a3080a7af802f0dd96f1f6b548233b555f989399`, run `39107517613`. Final documentation head `7d77311f123dbe7913d46abf77b4f0fe300c975e` passed all seven jobs in run `39108485648`; MySQL process/concurrency gate passed. No secrets, staging, or protected workflow edits. Owner-approved disposition: close for this checker path only. | Any future change that prints untrusted path bytes or writes attacker-controlled command-file content; broader workflow log integrity remains outside this scoped test. |
| M14.6 deploy chain | Malformed/not-tip SHA, missing exact CI, origin mismatch, receipt mismatch, helper unknown args, concurrent deploy, clean baseline, root-command denial | Fixed origin, exact merged SHA/checks, root-helper allowlist, receipt/runtime provenance; failures preserve live release | Static/unit/fault-injection fixtures; no production | Target code root elevation, source substitution or unsafe half-live deploy |
| M12 gates | Six exact values before/after; missing/unreadable, one true, wrong environment | Secret-safe six booleans; false required; unknown stops; no mutation to change them | Before any separately authorized staging scanner validation, use a fresh secret-safe read-only operator command. No live values were read for this planning update. | Any gate true, unknown, or unreadable before staging validation; do not change gates to proceed |
| Browser flows | Security-relevant Owner/localization flows and 3 Connector cases with synthetic users/data; Stripe-dependent cases separated | Exact tests/environment and pass/fail/skip recorded; artifacts sanitized; cleanup proven | Local planning/harness preparation is approved now. Browser-test execution requires separate Level 2 approval. Stripe-dependent browser runs also require separate approval of isolated Stripe TEST/webhook/Mailpit infrastructure. Never staging/customer. | Production credentials/data, unisolated app, real payment, live webhook, customer email, external side effect or unverified cleanup |

## Browser harness preparation and execution boundary

1. Planning may inventory the declared tests and prepare a path-guarded harness. The source inventory previously recorded 23 ordinary cases (8 localization, 8 owner/commercial, 7 Stripe purchase) and 3 Connector cases; discovery is not execution evidence. Recount against the implementation SHA before any later run.
2. Owner/localization browser flows and Connector browser cases may be prepared around an isolated app database, synthetic users/site, loopback-only ports, separate SQLite databases, no-op mail, isolated queue, outbound request guard, cleanup marker, and refusal to overwrite unrelated runtime. Do not change guards to accept staging/production.
3. Execution of any local browser test requires separate Level 2 implementation approval. Stripe-dependent flows additionally require a separately approved disposable Stripe TEST account/webhook and Mailpit arrangement. Such flows are deferred; no real payment, live webhook, or customer email is authorized.
4. Staging or production is not an authorized browser-test target. Do not reuse customer or staging accounts. No browser tests were executed in this documentation task.
5. Any later approved run records exact commit, test names/count, browser/runtime versions, pass/fail/skip, sanitized artifacts, and cleanup outcome. If isolation, credentials, or sink services are absent, stop and report the limitation; do not substitute external/customer infrastructure.

The Owner has approved isolated local browser-test planning and harness preparation only. The prior M14.6 statement that Playwright cases were discovered does not establish that browser-level tests ran or passed. Neither M147-A nor M147-B executes browser tests; the M147-B approval does not authorize M147-C or later stages.

## M147-C final coverage and merge evidence — 2026-10-18

PR #123 head `141f782cd7062c76175499492bf8e199203db8c5` passed exact-head CI run [39117328446](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39117328446) 7/7. Protected merge SHA: `5ec7ac4d2449d4eb95d98b108210607c40c847ce`. Exact merged-primary run [39118627595](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39118627595) passed 7/7. The MySQL process/concurrency gate passed in each run.

The detailed M147-C matrix uses the required scenario / test / evidence / result / gap / finding columns in the [closure report](m14-7-c-closure-report.md). Results distinguish TESTED — PASS from PARTIALLY TESTED and NOT TESTED. In particular, synthetic feed and selected Connector integrity/malware rules ran; live feed, exhaustive filesystem/malware cases, all side-effect combinations, browser E2E and live M12 gate reads did not. No untested row is treated as a pass.

M147-C is closed for the bounded local synthetic validation. AS-16 and M147-F01/F02/F03/F04/F05/F06/F08 remain open as described in the findings register. M147-F07 is closed only for the bounded feed-handling sub-scope; M147-C-02 is closed after merged-primary validation. No staging, production, customer or Level 3 operation occurred.

## M147-D attack-path matrix — execution status

This matrix adds the separately authorized synthetic attack-path stage. Final local execution status is shown below. This matrix reuses existing regression suites; M147-C evidence is not substituted for M147-D execution.

| Attack path | Planned scenario | Existing test surface | Current M147-D status |
|---|---|---|---|
| Authentication / role boundary | Guest, customer, Operator and Owner access to role-specific surfaces; ability denial | `OwnerAccessTest`, `OperatorSecurityTest`, route-boundary tests | TESTED — PASS (selected cases) |
| Tenant IDOR | Foreign website, nested backup, finding/incident and child-resource identifiers; assert no mutation | `BackupSecurityTest`, `SecurityVisibilityAndPermissionsTest` | TESTED — PASS (selected cases) |
| Connector identity | Invalid/revoked credentials, wrong-site identity, cross-site job and backup operations | `PairingTest`, `ConnectorApiTest`, `JobsTest`, `BackupSecurityTest` | TESTED — PASS (selected cases) |
| Connector replay / state | Duplicate job results, unknown/undelivered jobs, disconnect and result bounds | `JobsTest`, `ConnectorApiTest` | TESTED — PASS (selected cases) |
| Stripe webhook | Synthetic signature rejection, replay/deduplication and mode/state transitions | Stripe webhook and lifecycle feature tests | TESTED — PASS (selected cases) |
| CSRF / route boundary | Mutating web routes require CSRF or declared non-browser boundary and throttling | `RuntimeRouteBoundaryTest`, Owner/Operator security tests | TESTED — PASS (selected cases) |
| XSS / hostile text | Security finding text rendered as escaped text; synthetic hostile values | `SecurityVisibilityAndPermissionsTest` | PARTIALLY TESTED |
| Backup / restore | Foreign-site actions, connector/site binding, restore preconditions, fake queue/storage and concurrency | `BackupSecurityTest`, backup flow/restore suites | PARTIALLY TESTED (selected flows pass; local concurrency/service skips) |
| Incident / remediation | Synthetic detection side effects, authorization, remediation gate and fake queue/storage | incident, scan, remediation suites | PARTIALLY TESTED |
| SSRF / egress | Site URL admission and configured feed transport rejects invalid/redirect/oversize responses under fake transport | `AccountConnectorTest`, `VulnerabilityAndPostureTest` | PARTIALLY TESTED |
| AI boundary | Owner-only provider configuration; synthetic health transport; no inference surface | AI credential and provider health suites | PARTIALLY TESTED; inference action NOT APPLICABLE to inspected M14.5 surface |
| Operational controls | Source-only audit for web-accessible deploy, shell, environment, gate and worker control | routes/controllers/commands inventory | PARTIALLY TESTED — source review only |

A passing row is limited to explicit assertions in the referenced test. The detailed scenario matrix below distinguishes partial and untested requirements; no suite-level pass is generalized beyond its assertions.

## M147-D closure verification — 2026-10-18

PR #125 merged at `bc0a4e536abfd573b5b34d5e2152dcf15ad80c80`. Exact-head run [39122868939](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39122868939) and exact merged-primary run [39124920641](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39124920641) each passed all seven required jobs; the Server MySQL process/concurrency step passed in both. The local selected suites completed 471/483 passed, 12 skipped, 0 failed, 5,635 assertions. Skipped cases remain skipped; coverage rows above remain selected-path claims only. The full scenario evidence and closure disposition are in [M147-D closure report](m14-7-d-closure-report.md).

M147-D is closed for bounded local synthetic attack-path validation. Browser E2E, exhaustive SSRF/DNS-rebinding, every Connector replay/order case, full incident/backup/remediation combinations, live M12 gates and operational host controls remain untested or partial. AS-16 remains open. No staging, production, customer-site, live-service, deployment or Level 3 operation occurred.

## M147-E.2a RO-00–RO-04 execution — 2026-10-18

| Scenario | Status | Evidence / disposition |
|---|---|---|
| RO-00 trusted SSH host-key verification | BLOCKED | Unauthenticated keyscan attempted during the approved window; local KEX negotiation failed and no key/fingerprint was retrieved or compared. No authentication occurred. |
| RO-01 hostname, account, UTC time | NOT TESTED | Not executed after RO-00 blocked. |
| RO-02 deployed Git HEAD | NOT TESTED | Not executed after RO-00 blocked. |
| RO-03 PHP CLI version | NOT TESTED | Not executed after RO-00 blocked. |
| RO-04 filesystem capacity | NOT TESTED | Not executed after RO-00 blocked. |
| RO-05–RO-09 | NOT AUTHORIZED | Not attempted. All six live M12 gate values remain UNKNOWN. |

M147-E.2b remains NO-GO / NOT AUTHORIZED.

## Current M147-E.2a evidence reconciliation — 2026-10-18

| Scenario | Status | Evidence / disposition |
|---|---|---|
| Local RSA public-key fingerprint | TESTED — PASS | Recomputed from local public id_rsa.pub; exact match to the complete Owner-provided Rackforest-console fingerprint. Server-side result is Owner-reported, not directly read by Codex. |
| Local SSH binary identity and KEX | TESTED — PASS (local only) | Fresh hashes match verified package comparison record; local OpenSSH supports sntrup, Curve25519 and ED25519. No server negotiation. |
| SSH credential usability / deploy authorization | UNKNOWN | Private-key file presence only by metadata; no key contents, agent identity or authentication inspected/tested. |
| RO-00 original comparison | TESTED — FAIL; later reconciled | Original comparison against the then-supplied expected fingerprint failed. Owner later supplied independent exact match to observed keyscan value. Historical result preserved; no new keyscan. |
| RO-01–RO-04 | NOT TESTED | No current exact account/credential/window authorization. |
| RO-05–RO-09 and six M12 values | NOT TESTED / NOT AUTHORIZED | No read or operation occurred; six live values remain UNKNOWN. |
| Live scanner and side-effect execution | NOT TESTED | M147-E.2b is not authorized. |
