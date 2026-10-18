# M147-C Security Engine Synthetic Validation — Execution Evidence

**Status:** Implementation/test candidate; exact PR-head CI and Owner review are pending. This report records the bounded local execution only. It is not a production scanner certification or an M14.7 closure report.

## Authorization and baseline

- Owner authorized M147-C Level 2 local synthetic security-engine testing, mocked external HTTP, isolated test DB/storage/queues, in-scope fixes, documentation, and a dedicated PR. The detailed boundary is in [the Owner decision register](m14-7-owner-decisions.md).
- Authoritative branch: `claude/ecstatic-wozniak-oemyuf`.
- Exact implementation base: `d863da22d3439f2fa52a9988297e7b19a4c31f36`.
- Implementation branch: `codex/m147-c-security-engine-validation`.
- M147-A PR #120 and M147-B PR #121 are merged. Their evidence remains historical and is linked from [the plan](m14-7-plan.md).
- No staging/production/customer system, live secret, real Wordfence feed, external scanner, remote backup, Stripe, AI provider, or browser test was accessed or used.

## Implementation inventory and data flow

The inventory below reflects source inspected for this stage; it does not assert that every production deployment has the same runtime configuration.

| Area | Source behavior observed | Existing coverage / M147-C result |
|---|---|---|
| Initiation and authorization | Operator security routes call `SecurityScanService`; operator permissions gate scan/view actions. Scheduled scans use `ScheduleScans`; no public customer scanner route was identified in this path. | `SecurityVisibilityAndPermissionsTest` and `SecurityScanTest` included in the 98-test focused run. Broader cross-tenant attack campaign remains M147-D. |
| Lifecycle | `SecurityScanService` creates queued state, runs vulnerability matching locally, queues Connector parts, accepts reports via `ScanResultIngest`, advances or finishes, and handles cancel/timeout/failure. Only completed scanner coverage resolves missing findings. | Focused Laravel suites include lifecycle, malformed/partial results, cancellation/timeouts, authorization and finding lifecycle. Not every race/worker-loss case was newly added in M147-C. |
| Vulnerability matching | `VulnerabilityScanner` reads the site's stored WordPress/core/plugin/theme version inventory and calls the configured `VulnerabilitySource`; it does not issue model/inference calls. | Added vulnerable/patched core/plugin/theme, missing/unknown version partial status, duplicate/multiple advisory, and not-healthy-on-unavailable tests. |
| Wordfence feed | `WordfenceFeed` imports configured Wordfence v2 data into an index; `SyncVulnerabilities` uses only configured HTTPS URL/key or an explicit local `--file`. No feed is configured by this test. | Added strict shape/empty/size validation, fake response failures, and prior-index preservation tests. No actual feed endpoint or credential was used. |
| WordPress core integrity | Connector `IntegrityChecker` calls `Checksums::core` for WordPress.org checksum data, compares files under the authorized WordPress root, and labels missing/modified/extra versus unavailable. Core files that are symlinks are not trusted as regular files. | Existing Connector integration suite was run with its WordPress.org transport fake. Live checksum availability and real-site coverage remain untested. |
| Plugin/theme integrity | Connector checks plugin checksum data when available; themes/premium/custom components can be unavailable rather than verified. Plugin scans distinguish modified/missing/extra files. | Existing synthetic Connector `SecurityScanTest` passed. The actual upstream checksum service was not called. Theme checksum verification is absent in source; no such capability is claimed. |
| Malware and suspicious paths | Connector `FileInspector`, `RuleSet`, `FileRules`, and `FileWalker` analyze bounded files/paths and report suspicious/integrity findings with confidence. They do not execute a specimen. Quarantine/repair are separate job handlers. | Existing Connector integration suite passed with inert synthetic test content. Exhaustive malware-signature coverage, all Unicode/path edge cases, and real-world false-negative rates were not established. |
| Incident and backup effects | `SecurityScanService::finish` calls `IncidentService::openForDetection`; high/critical high-confidence suspicious findings can auto-open an incident and request a full recovery backup when the config flags permit it. Remediation/quarantine remain separate operations. | Existing Laravel incident lifecycle tests ran using synthetic DB results and the test suite's per-test local temporary storage. This proves the test path, not remote storage isolation in every environment. No real Connector/site or remote backup was involved. No destructive action was enabled. |
| Connector communication | Platform queues `SECURITY_SCAN` Connector jobs; Connector reports bounded multipart findings/coverage. Connector code contains no feed secret. | Local Connector WordPress+SQLite integration test passed. It used synthetic fixtures and mocked WordPress.org transport. No outgoing live request was permitted for test execution. |

## Changes made

1. **Bounded feed download.** `SyncVulnerabilities` now uses a 3-second connect timeout and 10-second total timeout, disables redirects, requests a streaming response, and reads at most the configured byte cap (hard-capped at 64 MiB) plus one byte before rejecting. HTTPS remains required; default TLS verification remains enabled. It accepts JSON media types only, rejects empty/non-success responses, and uses a same-directory temporary file.
2. **Safe feed/index replacement.** `WordfenceFeed::import` rejects empty, oversized, malformed, incomplete, unsupported, missing-title, and unsafe-identifier records plus invalid version ranges. It writes a temporary index in the destination directory and renames only after validation. A rejected source leaves the previous index unchanged. Download import is validated before the raw cache file is replaced.
3. **Unknown component version is not treated as patched.** Missing/unknown/unusable versions increment `skipped` and make vulnerability coverage `partial` with reason `COMPONENT_VERSION_UNAVAILABLE`.
4. **Incomplete scanner coverage cannot support Healthy posture.** The latest scan must be completed, recent, non-empty in coverage, and every declared scanner must report completed. A partial/unavailable/missing scanner no longer passes this health predicate.

No product feature, security default, remediation setting, M12 gate, live feed, or operational configuration was changed.

## Confirmed findings and disposition

| ID | Severity | Root cause / reproduction | Fix and regression | Disposition |
|---|---|---|---|---|
| M147-C-01 / M147-F07 | Medium control gap (not a demonstrated exploit) | Before this change the configured feed sync had a long timeout, no byte cap, default redirect behavior, and accepted some structurally empty/unusable data. Reproduced with fake redirects, over-limit content, truncated/empty/HTML content, 503 and connection failure. | Bounded stream, 3s/10s timeouts, redirects disabled, JSON content type, strict parser, same-directory temp and atomic index replacement. Tests assert the last good raw feed/index survive each failure and temporary files are removed. | Fixed for the inspected sync/parser path; exact CI/review pending. **Live feed behavior and external provenance remain unverified.** |
| M147-C-02 | High domain safety defect | A full scan could complete an available Connector scanner while vulnerability coverage was unavailable; `SecurityPosture` treated any one completed scanner as sufficient to show Healthy. Reproduced with a synthetic full scan and no vulnerability source. | Health now requires all declared scanner coverage to be complete; missing/unknown component versions are explicitly partial. Regressions assert unavailable/partial coverage cannot produce Healthy. | Fixed in candidate; exact CI/review pending. |

No confirmed Critical exploit was found in this bounded review. This is not a broad attack-path assurance statement. M147-F03 (live source/provenance), M147-F04 (full side-effect boundary), M147-F05 (browser coverage), M147-F06 (independent review), and AS-16 remain open or residual as appropriate.

## Executed validation

### Laravel security suites

Environment: WSL Ubuntu 24.04, PHP 8.4.26, Laravel PHPUnit configuration, disposable SQLite test database, test-local temporary storage. The source was copied from the implementation worktree into a clean native-filesystem checkout at the exact base commit before running; no `.env` from a developer or deployed environment was used.

Command:

```sh
APP_ENV=testing vendor/bin/phpunit \
  tests/Feature/Security/VulnerabilityAndPostureTest.php \
  tests/Feature/Security/SecurityScanTest.php \
  tests/Feature/Security/IncidentLifecycleTest.php \
  tests/Feature/Security/FindingLifecycleTest.php \
  tests/Feature/Security/RemediationTest.php \
  tests/Feature/Security/SecurityVisibilityAndPermissionsTest.php
```

Result: **98 tests, 1,124 assertions, passed.** This run was after the final content-type and strict-record validation cases were added. An earlier run caught an invalid synthetic success fixture with no required title; that fixture was corrected and the entire focused suite was rerun successfully.

### WordPress Connector scanner integration

Environment: WSL Ubuntu 24.04, PHP 8.4.26, WordPress `7.1.3`, Connector `0.4.7`, SQLite and the locked `aaemnnosttv/wp-sqlite-db` drop-in `1.3.3`, PHPUnit `13.4.2`. The disposable test harness reported WordPress installed and Connector active. Its WordPress.org HTTP behavior was faked; the test emitted no allowed reason to contact external services.

Command:

```sh
cd wordpress
vendor/bin/phpunit tests/Integration/SecurityScanTest.php
```

Result: **22 tests, 159 assertions, passed.** An earlier command used an incorrect test path and exited before running tests; it is not counted as a test result.

### Fixture cleanup observation

The previously used unique temporary directory `/tmp/wpcg-m147c-20261018-6f42a0c1` was absent after the test attempt. The Linux checkout remained at baseline SHA `d863da22d3439f2fa52a9988297e7b19a4c31f36` with only the seven intended implementation files modified. This observation does not certify cleanup of any other system path.

## Not tested / unavailable

- No live Wordfence feed, live API credential, live feed timeout, actual remote redirect, real feed license/access method, or production feed freshness was tested.
- Synthetic HTTP responses test consumer behavior; they are not a packet-level proof of transport buffering or upstream encoding behavior.
- No real WordPress.org core/plugin checksum request was made. Live checksum source outage and upstream format drift remain unverified.
- No exhaustive malware scanner campaign, external malware sample, browser test, staging scan, customer site, real backup/restore, quarantine, repair, remediation, notification, M12 gate read, or independent review occurred.
- MySQL/MariaDB M147-C focused tests were not run locally; the required CI matrix is pending. Existing MySQL process/concurrency evidence from earlier stages is not reused as M147-C evidence.
- No local OS-level egress firewall proof was performed. Test transport fakes and lack of network-dependent code paths are not a claim of network isolation enforcement.

## Findings and next evidence

- M147-F07 is narrowly remediated for bounded configured feed sync, redirect rejection, parser validation, and atomic index preservation. Keep live feed/provenance availability open under M147-F03 / AS-16.
- M147-F01 / AS-16 remains open: no operational scanner coverage has been established.
- M147-F02 remains open: all six M12 live values are unverified and unchanged.
- M147-F04 remains open: the test suite covers selected synthetic incident/backup lifecycle behavior; it does not exhaust all dangerous side-effect combinations or prove remote deployment isolation.
- M147-F05 remains open: no browser tests ran. M147-F06 remains an independent-review residual.
- No customer data or M14.5 AI/Stripe credential/path was used. No M147-D/E/F or M14.8 work began.

**Implementation source SHA:** `d863da22d3439f2fa52a9988297e7b19a4c31f36` base; code/test/evidence candidate `1dc8c93a96640a251d0744927ff963e01a6544f9`. Exact-head GitHub Actions run [39116313678](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39116313678) passed 7/7 on that SHA. The Server MySQL process/concurrency step passed. The evidence-only revision that records this result is separately gated by exact-head CI; its current run is shown in PR #123 checks and the final status report. No earlier run is substituted for a changed head.
