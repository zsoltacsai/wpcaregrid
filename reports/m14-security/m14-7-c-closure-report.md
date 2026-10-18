# M147-C Formal Closure Report

Date: 2026-10-18

## Decision

**M147-C CLOSED** after protected merge and successful exact merged-primary CI. This closes the bounded local synthetic validation stage. It does not certify production scanner coverage, live Wordfence feed correctness, customer-site safety, or the broader M14.7 attack-path campaign.

## Source and release evidence

| Evidence | Value |
|---|---|
| Authoritative branch | `claude/ecstatic-wozniak-oemyuf` |
| Implementation baseline | `d863da22d3439f2fa52a9988297e7b19a4c31f36` |
| PR #123 head | `141f782cd7062c76175499492bf8e199203db8c5` |
| PR #123 exact-head CI | [39117328446](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39117328446) — 7/7 passed |
| PR #123 merge commit | `5ec7ac4d2449d4eb95d98b108210607c40c847ce` |
| Merged-primary CI | [39118627595](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39118627595) — 7/7 passed |
| Implementation PR | [#123](https://github.com/zsoltacsai/wpcaregrid/pull/123), merged 2026-10-18 through the active primary ruleset |
| Current primary at closure | `5ec7ac4d2449d4eb95d98b108210607c40c847ce` |

The active ruleset targeted the authoritative branch, required the PR path, seven named checks and conversation resolution, blocked force-push and deletion, and had no bypass actors. No administrative bypass or direct primary push was used.

Both exact-head and merged-primary runs passed:

1. Quality (lint, style, dependencies, build, secrets)
2. Server tests (PHP 8.4, SQLite)
3. Server tests (PHP 8.4, MySQL)
4. Server tests (PHP 8.4, MariaDB)
5. Connector tests in WordPress (PHP 8.4, SQLite)
6. Connector tests in WordPress (PHP 8.4, MySQL)
7. Connector tests in WordPress (PHP 8.4, MariaDB)

The required MySQL process/concurrency gate passed in both runs as a step in Server MySQL. No unexpected source, dependency, or workflow changes were included in PR #123.

## Implementation and test evidence

The M147-C change set updates the vulnerability-feed command/parser, vulnerability coverage accounting, security posture predicate and focused feature tests. It adds timeouts, disabled redirects, streamed bounded reads, content-type checks, strict feed parsing, temporary files and atomic index replacement. Failed or incomplete vulnerability coverage remains unavailable or partial; it cannot be presented as an empty clean result or Healthy posture.

Executed local validation:

- Laravel/PHPUnit security suite on PHP 8.4.26: **98 tests, 1,124 assertions, passed**. It included `VulnerabilityAndPostureTest`, `SecurityScanTest`, `IncidentLifecycleTest`, `FindingLifecycleTest`, `RemediationTest`, and `SecurityVisibilityAndPermissionsTest`.
- Disposable WordPress 7.1.3 / SQLite drop-in 1.3.3 / Connector 0.4.7 integration suite, `wordpress/vendor/bin/phpunit tests/Integration/SecurityScanTest.php`: **22 tests, 159 assertions, passed**.
- Pint, Composer validation, npm tests (**20/20**), Vite build, internal Markdown-link validation and `git diff --check`: passed.
- The Connector suite's WordPress.org HTTP behavior was mocked. No live feed or external scanner was called.

The current evidence report was written while PR-head CI and Owner review were still pending. That statement was true at the time of writing and remains part of the audit history. The later PR-head result, Owner-authorized merge and merged-primary result are recorded here; they are not retroactively attributed to the earlier evidence snapshot.

## Coverage reconciliation

“Tested” below means the named synthetic test or repository suite actually ran. A pass is not evidence for live-feed availability or production configuration.

| Planned scenario | Implemented test / evidence | Result | Remaining gap | Finding / follow-up |
|---|---|---|---|---|
| Vulnerable and patched WordPress core, plugin and theme versions | Fake Wordfence fixture matching in `VulnerabilityAndPostureTest` | TESTED — PASS | Synthetic version ranges only; no live feed validation | M147-F03 / AS-16 |
| Multiple advisories for one component and duplicate records | Duplicate and distinct Akismet advisories; finding dedup assertion | TESTED — PASS | No claim about all upstream feed duplicate conventions | None |
| Feed markup/script-like titles | Rendered title remains escaped text | TESTED — PASS | Other output surfaces still belong to broader XSS campaign | M147-D |
| No configured source, stale index and unusable index | Unavailable-source, stale-index, corrupt/oversized-index cases | TESTED — PASS | Runtime feed configuration not inspected | M147-F03 |
| Redirect, oversized, truncated, empty, HTML/wrong content type, 503 and connection failure | Laravel fake HTTP responses; old index/feed and temporary-file assertions | TESTED — PASS | Fake transport does not prove packet-level behavior or actual upstream behavior | M147-F07 bounded code path |
| Bounded response transfer and timeouts | Source inspection shows streaming and bounded reads, 3s connect / 10s overall timeout; synthetic oversize rejection | PARTIALLY TESTED | No real socket timeout, packet-level buffering, encoding or slow-stream test | M147-F07 residual evidence limit |
| Malformed feed records and invalid local import | Empty/incomplete structure, missing title, unsafe ID and unsupported software type; old index preserved | TESTED — PASS | Invalid timestamps, all schema variants and partial-feed semantics lack dedicated exhaustive fixtures | M147-F03 / future feed review |
| Feed destination and redirect confinement | HTTP scheme rejected; configured HTTPS URL used; redirects disabled | PARTIALLY TESTED | URL is server configuration and not request input, but no host allowlist, private-address/DNS-rebinding or resolver test was run | M147-D SSRF review; M147-F03 |
| Missing/unknown component version | Empty and “unknown” versions are skipped and recorded as partial; posture is not Healthy | TESTED — PASS | Other malformed inventory shapes are not exhaustively tested | M147-C-02 closed; hostile inventory to M147-D |
| Incomplete, unavailable or failed scanner coverage | Feature tests cover unavailable vulnerability source, partial coverage and scanner lifecycle; posture now requires every stored coverage entry complete | TESTED — PASS | No exhaustive corruption/replay/concurrent-write campaign | M147-C-02 closed; M147-D |
| Core integrity checksum: known-good, modified, missing and extra files | Existing Connector integration suite tests clean core checksum, modified file, and missing/extra core files | TESTED — PASS | WordPress.org transport is mocked; no live checksum service or upstream availability tested | M147-F03 / AS-16 |
| Plugin integrity reference present/absent | Existing Connector integration test covers plugin integrity with and without a reference | TESTED — PASS | Theme-specific checksum behavior and upstream format drift are not established by this evidence | M147-F03 / AS-16 |
| Inert malware/suspicious-file rules and benign lookalikes | Existing Connector suite covers suspicious PHP, weak indicators, integrity corroboration, upload checks, benign constructs, self-rule exclusion, symlinks, database names-only and cron inspection without running events | TESTED — PASS | Selected fixture rules only; no exhaustive malware campaign or real malware sample | M147-F01 / AS-16 |
| Large/unreadable files, Unicode paths, traversal/out-of-root access, interruption and partial filesystem access | Some existing path and symlink cases ran; no full hostile filesystem corpus ran under M147-C | PARTIALLY TESTED | Exhaustive filesystem isolation and interruption cases remain unproven | M147-D / AS-16 |
| Scan lifecycle: success, partial/failure, cancellation/timeout, multipart continuation and malformed reports | Existing Laravel and Connector scan tests were rerun in the focused suite | PARTIALLY TESTED | No dedicated M147-C MySQL process race for scanner requests, concurrent/replayed reports or all recovery paths | M147-D |
| Authorization and synthetic tenant/site boundaries | Existing scan refusal, visibility/permission, foreign object and Connector protocol tests ran | PARTIALLY TESTED | Full cross-tenant/revoked Connector/replay adversarial matrix remains for M147-D | M147-D |
| Incident creation, deduplication and backup request | Selected local lifecycle test uses disposable DB and test-local storage/fakes; no remote destination | PARTIALLY TESTED | Not every detection threshold, repeated scan or failure/order combination was exercised | M147-F04 |
| Quarantine, remediation, plugin deactivation and restore | Existing guarded remediation tests ran; M147-C did not invoke destructive actions or authorize live changes | PARTIALLY TESTED | No exhaustive side-effect matrix; no quarantine/restore operation performed | M147-F04 / M147-D |
| Notifications, external services, queues and storage | Test dependencies were local/faked for the executed paths | PARTIALLY TESTED | No host-level egress firewall proof and not every notification/queue path instrumented | M147-F04 |
| Browser E2E / Stripe-dependent flows | No browser tests ran | NOT TESTED | Requires separate isolated browser authorization; Stripe flows require separately approved TEST infrastructure | M147-F05 |
| Live Wordfence feed, real Connector/customer scan or production scanner coverage | Not authorized and not executed | NOT TESTED | Operational scanner evidence remains required | AS-16 / M147-F01 / M147-F03 |
| Fresh read-only verification of six live M12 gates | Not performed | NOT TESTED | Values remain UNKNOWN; no source defaults are substituted for live values | M147-F02 |
| Independent security review / penetration test | No separate reviewer or pentest was claimed | NOT TESTED | Independent review and future M17 pentest remain external follow-ups | M147-F06 / M147-F08 |

No scenario in this matrix is marked TESTED — FAIL. “Partially tested” is not a pass for the untested portion.

## Findings disposition

| Finding | Closure status |
|---|---|
| M147-C-02 — incomplete coverage could produce false Healthy | **Closed.** Fix merged at `5ec7ac4…`; exact-head and merged-primary CI green. Tests cover unavailable and partial vulnerability coverage and unknown versions. |
| M147-F07 — feed transfer/parser/resource handling | **Closed for the bounded implementation sub-scope only:** HTTPS scheme requirement, disabled redirects, bounded streaming, timeouts, content type, parser validation, atomic index write and synthetic failure preservation. No claim of live feed correctness, host allowlisting, licensing, provenance or production transport. Filesystem write/rename failures and concurrent sync were not injected; these limits remain explicit, and live feed evidence stays under M147-F03 / AS-16. |
| M147-F01 / AS-16 — operational scanner validation gap | **Open.** No real operational scanner coverage. |
| M147-F02 — six live M12 gate values | **Open; UNKNOWN.** No fresh read was performed and no gate was changed. |
| M147-F03 — feed provenance, authorization, freshness and runtime state | **Open.** No live feed/configuration read. |
| M147-F04 — incident/backup and other side-effect combinations | **Open.** Only selected synthetic paths were exercised. |
| M147-F05 — browser coverage | **Open.** No browser test executed. |
| M147-F06 — independent-review residual | **Open.** No independent reviewer is claimed. |
| M147-F08 — independent penetration test | **Open external M17 checkpoint.** No pentest is claimed. |
| M146-F08 | Remains closed only for the previously audited tracked secret-path checker output path; this closure does not broaden that scope. |

No Critical or High defect was confirmed by this bounded M147-C validation. This is not a claim that untested paths are defect-free.

## Authorization and side effects

Performed within Owner-authorized Level 2 scope: local source review, fake vulnerability feeds and HTTP responses, disposable Laravel tests, the existing disposable WordPress/Connector integration harness, deterministic regression tests, bounded fixes and documentation.

No Level 3 activity occurred. No staging, production, customer site, real credential, live Wordfence feed, external scanner, browser, Stripe, external backup, restore, quarantine, remediation, notification, destructive retention/deletion, or deployment was performed. No M12 gate was read or modified. The test evidence records faked/local sinks; it is not a host firewall certification.

## Milestone status and next steps

- **M147-A:** closed by PR #120 merge `a84d03146ac3ba0ba8c47301f61be41052903905`; merged-primary CI 39105703168 passed 7/7.
- **M147-B:** closed by PR #121 merge `0699374432afeb26e036a87477c9a0cad5c5518b` and documentation PR #122 merge `d863da22d3439f2fa52a9988297e7b19a4c31f36`; relevant exact-head and merged-primary CI passed 7/7.
- **M147-C:** closed by PR #123 merge `5ec7ac4d2449d4eb95d98b108210607c40c847ce`; merged-primary CI 39118627595 passed 7/7.
- **M147-D:** not started; requires separate Level 2 Owner approval.
- **M147-E:** not started; staging execution requires separate Level 3 approval, exact target/Connector/operations/cleanup approval and a fresh all-false M12 read.
- **M147-F:** not started; findings retest and closure evidence remain.
- **M14.7:** approximately 50% implementation progress by the six-stage plan (A–C closed, D–F not started); this is a planning estimate, not a security-assurance percentage.
- **M14.8:** not ready. It must follow completion/review of M14.7 and disposition of its remaining findings.

Recommended next actions:
1. Owner reviews this closure evidence and separately authorizes M147-D.
2. Keep M147-F01/F03/F04/F05/F06 and M147-F02 open until their stated evidence or decisions exist.
3. Do not schedule a staging scan until separate operational authorization and a fresh secret-safe readback proves all six M12 gates false.

**M147-C closure outcome:** closed for the scoped synthetic implementation and test evidence; all broader operational and untested scenarios remain explicitly open.

## Atomicity and destination-test boundary

The vulnerability index is written to a same-directory temporary file and promoted with filesystem rename; the tested malformed/failed HTTP and invalid-import cases leave the prior feed/index unchanged. The tests did **not** inject a filesystem write/rename failure or concurrent sync. The raw downloaded-feed cache and generated index are separate files, not a transactional two-file commit; scans consume the generated index. This edge remains a documented limitation and is not described as fault-injection proof.

The configured URL is environment-owned, required to use HTTPS, and redirects are disabled. The tests do not enforce an official-host allowlist or probe private/link-local destinations and DNS rebinding. No customer-controlled endpoint was found in the scanned route/input path. Carry that destination threat-model review into M147-D; do not infer live feed provenance from this closure.
