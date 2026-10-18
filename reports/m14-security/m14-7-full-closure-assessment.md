# M14.7 Full-Closure Assessment — 2026-10-18

## Verdict

**B — PARTIAL / OWNER ACTION REQUIRED.** Previously closed engineering stages have evidence within their stated scopes, but M14.7 is not fully closed. Operational scanner validation, fresh live M12 readback and side-effect validation have not occurred.

## Repository and CI baseline

- Authoritative primary: claude/ecstatic-wozniak-oemyuf
- Primary SHA: 9e11c2035cd343b8cc1615fee4dbcf4a34c16cd9
- PR #129: [open and unmerged](https://github.com/zsoltacsai/wpcaregrid/pull/129)
- Reviewed head: 3a525fdfe548b0b24915a0bb6424bba8a6d9264e; base matches primary.
- Exact-head run [39158228230](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39158228230): 7/7 success. Server MySQL required process-concurrency step passed.
- Connector MariaDB on that run: 210 tests, 1,828 assertions, 3 skipped, 0 failed.
- This documentation commit changes the PR head, so a new exact-head run is required before claiming current-head CI green.

## Stage status

| Stage | Status |
|---|---|
| M147-A | CLOSED within approved synthetic fixture/evidence scope. |
| M147-B | CLOSED within audited checker scope. |
| M147-C | CLOSED within bounded local synthetic security-engine validation. |
| M147-D | CLOSED within bounded local synthetic attack-path validation. |
| M147-E.1 | CLOSED — planning only. |
| M147-E.2a | PARTIAL/BLOCKED: fingerprint reconciliation recorded, but authenticated identity and RO-01–RO-04 remain incomplete. |
| M147-E.2b | NOT AUTHORIZED / NOT EXECUTED. |
| M147-F | NOT STARTED. |
| M14.8 | NOT READY. |

M14.7 estimated implementation progress remains approximately 65%, not a security-assurance score.

## SSH identity and authorized-operation status

The latest Owner-provided RSA fingerprint SHA256:Cy8+tFoYq7gsUPjpPBCqnNOvdzksvB6VsLE4AwdunkU matches a fresh local calculation from public id_rsa.pub. The Owner reports that the same value came from Rackforest console checks of root and deploy authorized_keys. This is public-key identity evidence only.

The local private-key file exists by metadata; its contents were not opened. No authentication or credential test occurred. Deploy authorization remains UNKNOWN; root is not approved.

The first Windows OpenSSH RO-00 attempt failed KEX negotiation and yielded no host key. In a later, separately authorized RO-00 attempt, the verified Git for Windows keyscan returned an ED25519 public key; its observed fingerprint did not match the then-supplied, mistranscribed expected value, so that run's comparison gate failed. The Owner later independently confirmed the observed fingerprint SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ in Rackforest Console. This reconciles the key identity after execution but does not retroactively change the original comparison result to PASS. No new connection or keyscan occurred in this closure review.

| Operation | Result |
|---|---|
| RO-00 | Historical observation reconciled; original gate result remains FAILED. |
| RO-01–RO-04 | NOT EXECUTED / NO-GO; no new account/credential/window approval. |
| RO-05–RO-09 | NOT AUTHORIZED / NOT EXECUTED. |
| Six live M12 gates | UNKNOWN; not read. |
| M147-E.2b | NO-GO / NOT AUTHORIZED. |

## Local SSH client

Current absolute-path Git for Windows hashes match the recorded verified package comparison: ssh.exe C84594A323EE6AFC04D9FAF614A05BE6DC9A2FAECD856268A854084FEA17FD44; ssh-keyscan.exe CD3561C764F577EFC235942CC66314889D288F619F464D6F21CF185DEAA45BB4; ssh-keygen.exe 604D127D97502B11ED2D1F6F8191BFE86F443D821322E4AB285C961D0E06F5E1. OpenSSH 10.5p1 / OpenSSL 3.5.8 supports required sntrup KEX, Curve25519 and ED25519 locally. This does not prove any network negotiation.

## CI-REL-01

Run [39153976426](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39153976426), head 14dec63646d293ad2fec87228b22fa2150dbb676, failed only Connector MariaDB: 210 tests, 1,824 assertions, 1 failure, 3 skips. PollLockConcurrencyTest::test_expired_owner_cannot_release_or_refresh_successor_lease timed out waiting 20 seconds for a child hold-*.result file. The database was reported healthy; logs do not show whether the child exited, stalled or was delayed. The io_uring warning/libaio fallback does not establish causality.

Run [39157541326](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39157541326), head 077d5e75976cde0d0138727254bee7eb8a9ed8fb, was canceled while the Server MySQL concurrency gate was in progress; it is not a pass. Current reviewed head 3a525fdfe548b0b24915a0bb6424bba8a6d9264e passed 7/7; Connector MariaDB passed 210 tests/1,828 assertions with 3 skips and the MySQL gate passed. Earlier same-head attempts include failures followed by pass without source changes.

Root cause is not established. CI-REL-01 remains OPEN as a reliability/diagnosability gap, not a confirmed application vulnerability. No code change or timeout increase is justified. A narrowly scoped future improvement should report child-process running/exit status on timeout, then repeat the MariaDB process test. Local PHP/Composer/Docker were unavailable; local PHP test reproduction was NOT RUN.

## Open findings and residuals

- AS-16 / M147-F01 — OPEN; no operational scanner campaign.
- M147-F02 — OPEN; all six live M12 gates UNKNOWN.
- M147-F03 — OPEN; live feed provenance/licensing/freshness and runtime HTTP controls unverified.
- M147-F04 — OPEN; incident, backup, queue, notification, quarantine and remediation combinations not operationally validated.
- M147-F05 — OPEN; browser/cross-origin validation not performed.
- M147-F06 — OPEN; independent review not completed.
- M147-F08 — OPEN; external M17 penetration-test checkpoint.
- CI-REL-01 — OPEN; intermittent MariaDB process synchronization timeout, cause unproven.
- M147-F07 — closed only within its previously tested synthetic feed-handling scope.
- M147-C-02 — previously closed within its tested false-Healthy scope.
- M146-F08 — closed only for the audited tracked checker-output path.
- No new Critical/High issue was confirmed in this offline review; untested paths remain UNKNOWN, not presumed secure.

## Owner decisions required

Before RO-01–RO-04, complete and approve the existing draft with exact date/window, operator, target, non-root account (explicitly confirm whether deploy is authorized), existing credential-use mechanism without sharing secret values, trusted host public-key record or separately approved RO-00 retrieval, exact commands, output/time limits, evidence handling and stop authority. The draft is NOT APPROVED.

RO-05–RO-09 each need a reviewed side-effect-free read method and separate scope approval. Scanner execution needs separate Level 3 authorization after all six M12 values are freshly verified false and target, fixtures, side effects, cleanup and stop conditions are bounded.

## Authorization compliance

This review used repository/GitHub evidence and local metadata only. No staging/production connection; Rackforest Console interaction; SSH authentication or private-key inspection; M12 read; scanner/feed/queue/scheduler/incident/notification/backup/restore/remediation/quarantine/configuration action; deployment; or PR merge occurred. Server-side fingerprints are Owner-reported, not directly read by Codex.

M147-E.2b remains NO-GO. M147-F and M14.8 remain unstarted.


## CI reliability and exact-head test-harness update — 2026-10-18

After the earlier timeout evidence, a narrowly scoped change was made only to `wordpress/tests/Integration/PollLockConcurrencyTest.php`: waits that own a worker now inspect the child process and fail immediately with sanitized PID/running/exit status when the worker exits without its result file; timeout output includes the same status. A regression test covers an unsupported synthetic worker mode and asserts the early-exit diagnostic. The application runtime, assertions about the lock, and 20-second synchronization bounds were not weakened.

Chronology is preserved: run [39160096901](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39160096901) reproduced the MariaDB synchronization timeout before the harness change; run [39160455557](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39160455557) failed because the first patch encoded PHPUnit namespace separators incorrectly; run [39160618085](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39160618085) passed all Connector database jobs and PHP syntax but Pint found one fully-qualified-name style issue. Both implementation mistakes were corrected in subsequent commits and are retained as failed CI history.

At code head `049a08bdd2377d82e7f29bce7a319c0c3768dc4c`, exact-head run [39160827416](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39160827416) passed all seven required jobs. The Server MySQL `Required MySQL process-concurrency gate` step explicitly passed. Connector MariaDB ran 211 tests, 1,834 assertions, 3 skips, 0 failures. This run did not reproduce the earlier timeout.

This is evidence that the added diagnostics regression and current matrix passed once; it does not identify the earlier timeout's cause or establish that it cannot recur. CI-REL-01 therefore remains OPEN for reliability investigation. No timeout was increased to hide the symptom, and no application runtime code changed. Local PHP/Composer/Docker are unavailable, so local PHP execution was NOT RUN.

Operational disposition is unchanged: PR #129 remains open/unmerged; RO-01–RO-04 were not executed; RO-05–RO-09 remain unauthorized; all six live M12 values are UNKNOWN; M147-E.2b is NOT AUTHORIZED; no staging/production, live scanner, feed, queue, backup, restore, remediation or deployment action occurred. M14.7 is not fully closed.
## Local validation update — 2026-10-18 (PR head `1e17e5c1253b0236f733ebd312a670c7a2bd8dfe`)

This addendum supersedes only the earlier statement that local PHP/Composer/Docker execution was unavailable. It records new isolated local test evidence; it does not change any operational or milestone closure status.

Environment: WSL Ubuntu 24.04; PHP 8.4.26; Composer 2.10.3; PHPUnit 12.5.36 (Laravel) and 13.4.2 (Connector); rootless Docker; MariaDB 10.11.19 from the exact digest observed in the CI service logs (`sha256:0d0945c57f75b3a3b9ebe71aedaaad005fa5305054e8fca3d2e42a8c92b43b5e`). The MariaDB container was a new, disposable local instance bound to `127.0.0.1:3306`; the application/Connector tests used synthetic data and a dedicated `wordpress` test database. No staging, production, customer site, live feed, external backup, or real credential was used.

| Validation | Result |
|---|---|
| Laravel PHPUnit, SQLite | PASS — 1,404 tests, 20,486 assertions, 25 skipped, 0 failed. Skips require separately provisioned MySQL/PCNTL, S3 `moto_server`, SFTP `sshd`, or the MySQL 8.4-specific dump test. |
| Connector PHPUnit, MariaDB 10.11.19 | PASS — 211 tests, 1,834 assertions, 3 skipped, 0 failed. The skips are the MySQL-only restore case and two opt-in 900-second hard-kill recovery cases. |
| CI-REL lock successor regression | PASS — 5 consecutive executions of `PollLockConcurrencyTest::test_expired_owner_cannot_release_or_refresh_successor_lease`, 1 test and 31 assertions each (155 assertions total). No timeout reproduced locally. |
| Connector package fixture | PASS — `connector:package` generated version 0.4.7 from source revision `1e17e5c1253b0236f733ebd312a670c7a2bd8dfe`; local ZIP SHA-256 `1eb62f0617e52a2162f0d73cef6051612b27526ff811498ec3945828a97ab14e`. The initial strict Connector run failed because the required generated ZIP environment variable was absent; after generating the package as the CI workflow does, the full suite passed. This initial setup failure was not an application test failure. |
| Pint | PASS — 1,000 files checked. |
| Composer validation and audit | PASS — root and `wordpress/` manifests valid; no security advisories reported. |
| npm audit / tests / build | PASS — 0 vulnerabilities; 20 tests passed, 0 failed/skipped; Vite 8.3.4 production build succeeded. `npm ci` used the committed lockfile and existing `ignore-scripts=true` policy. |

CI-REL-01 remains OPEN. The exact previously failing worker-exit test passed repeatedly in this one local MariaDB environment, and the current PR exact-head CI also passed; these results reduce evidence for a deterministic defect but do not identify why the earlier CI child failed to produce its result file, nor establish that the intermittent timeout cannot recur. No lock assertion, timeout, retry policy, or application behavior was weakened. Preserve the existing timeout history and investigate further only if new failure evidence appears.

Browser/cross-origin E2E was NOT RUN. The repository's Windows Connector browser harness is deliberately restricted to designated worktree paths/branches and a matching local service record, PHP runtime, and isolated Apache configuration. This validation clone is outside those allowlisted worktrees. I did not bypass that guard or start those services. Stripe/browser tests requiring public HTTPS, real Stripe, Mailpit, or external callbacks were also not run.

At the time of this addendum, the PR head was `1e17e5c1253b0236f733ebd312a670c7a2bd8dfe`; exact-head run [39164201676](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39164201676) passed all seven required jobs, including the explicit Server MySQL process-concurrency gate. Any documentation commit changes the PR head and therefore requires new exact-head CI evidence before calling the updated head green.

The assessment remains **PARTIAL / OWNER ACTION REQUIRED**. RO-01–RO-04 remain NOT EXECUTED / NO-GO; all six live M12 values remain UNKNOWN; M147-E.2b remains NOT AUTHORIZED. No staging/production access, SSH authentication, M12 readback, scanner, feed sync, queue, backup, restore, remediation, deployment, or PR merge occurred. M14.7 is not fully closed.
