# M14.7 Full-Closure Assessment — 2026-10-18

> **Final audit reconciliation (2026-10-24):** use
> [`M14 final audit`](../m14/m14-final-audit-2026-10-24.md) as the authoritative
> current matrix. The Owner-approved scan of `kiszov-szeged.hu` completed as
> Integrity scan `01a16dfa-afed-735f-a4fd-2eafcd0a8a3d`, Connector job 48,
> with 5,930 verified, 0 modified, 0 extra, 1 missing, 4 unavailable, and 0
> findings. This closes the operational Integrity execution criterion only.
> The historic notification delta and per-path reasons for the aggregate
> missing/unavailable counts were not persisted and remain unknown. No
> malware-free or full-coverage claim is made. Current primary merge CI run
> [40101414072](https://github.com/zsoltacsai/wpcaregrid/actions/runs/40101414072)
> passed 30/30, including the required MySQL process-concurrency step. The
> final audit UI correction is PR #171 and remains pending exact-head CI and
> protected staging deployment/read-only smoke; do not mark M14 closed before
> those gates complete.

> **Later evidence update (2026-10-24):** this assessment remains historically
> accurate as of its original review date. Its statement that no operational
> scan had occurred is superseded only for the operational Integrity-execution
> criterion by the Owner-approved, completed Path B scan recorded in
> [`reports/m14/owner-real-site-integrity-acceptance-2026-10-24.md`](../m14/owner-real-site-integrity-acceptance-2026-10-24.md).
> Other M14.7 findings, the audit gaps, and M14.8 status are not closed by that
> scan.

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


## Owner-authorized RO-01–RO-04 preflight attempt — 2026-10-18

This addendum records the latest restricted preflight attempt. It does not change the historical RO-00 records or authorize RO-05–RO-09, scanner execution, M147-E.2b, deployment, or configuration changes.

- Owner-approved target/account: `stg1.wpcaregrid.com` / `85.155.243.164:22`, user `deploy`; application path `/var/www/wpcaregrid`.
- Recorded interactive-session authorization window: 2026-10-18 21:42:45 UTC through 2026-10-18 23:42:45 UTC (Europe/Budapest: 23:42:45 through 2026-10-19 01:42:45). The Owner's stop authority was retained.
- Fresh local SHA-256 values matched the previously verified Git for Windows package evidence: `ssh.exe` C84594A323EE6AFC04D9FAF614A05BE6DC9A2FAECD856268A854084FEA17FD44; `ssh-keygen.exe` 604D127D97502B11ED2D1F6F8191BFE86F443D821322E4AB285C961D0E06F5E1; `ssh-keyscan.exe` CD3561C764F577EFC235942CC66314889D288F619F464D6F21CF185DEAA45BB4. OpenSSH 10.5p1 / OpenSSL 3.5.8 locally reported the approved sntrup KEX, Curve25519, and ED25519 support.
- A fresh local read of the existing public `known_hosts` record for `85.155.243.164` yielded one ED25519 key with fingerprint `SHA256:7eMFqVIvqAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`, matching the Owner-confirmed independent Rackforest-console value. The record is an existing public-key entry; no trust-on-first-use or new key acceptance was used.
- The existing public `id_rsa.pub` fingerprint recalculated as `SHA256:Cy8+tFoYq7gsUPjpPBCqnNOvdzksvB6VsLE4AwdunkU`, matching the Owner-provided server-side fingerprint. The corresponding private-key file was checked only for path existence metadata; its contents were not opened. No SSH authentication was attempted.
- The authorized bounded SSH invocation was rejected by the Codex execution tool's policy before process start (`CreateProcess ... Rejected ... blocked by policy`). There was no network connection and no remote command execution. We did not try another execution mechanism to bypass that block.
- RO-01–RO-04 result: **BLOCKED / NOT EXECUTED**. No hostname, remote user, deployed SHA, remote PHP version, or remote filesystem capacity was observed. RO-05–RO-09 were not run. All six live M12 gate values remain **UNKNOWN**.

## Local browser and CI reliability follow-up — 2026-10-18

- Browser E2E was **NOT RUN**. The repository's Windows Connector browser runner accepts only two hard-coded worktree path/branch pairs. Both designated worktrees exist and are clean but point to separate 2026-10-09 commits, not the current PR source. We did not change either checkout or bypass the guard; no local services were started.
- CI run [39153976426](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39153976426), head `14dec63646d293ad2fec87228b22fa2150dbb676`, failed the Connector MariaDB test `WPCareGrid\\Connector\\Tests\\Integration\\PollLockConcurrencyTest::test_expired_owner_cannot_release_or_refresh_successor_lease`: it waited 20 seconds for a child `hold-*.result` synchronization file. MariaDB initialization completed; logs include an io_uring permission warning and libaio fallback, which do not establish causation. This is evidence of a real one-run harness synchronization timeout, but not enough to distinguish runner contention from a child-process/harness defect.
- On the isolated local WSL MariaDB environment, that lock-succession test passed five consecutive runs (1 test / 31 assertions each). The current pre-addendum PR head `0c525652d9310f1a6f9f80de75f1080fb3371995` passed all seven required jobs in [run 39167315766](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39167315766); the explicit **Server MySQL process-concurrency gate** step passed. Connector MariaDB also passed on that run.
- **CI-REL-01 remains OPEN.** No timeout/assertion was weakened and no reliability root cause is claimed. Follow-up should capture child-process exit/diagnostic state and runner resource metrics for any recurrence, then reproduce under bounded repeated CI-equivalent MariaDB runs before considering a fix.

M14.7 remains **PARTIAL / OWNER ACTION REQUIRED**. M147-E.2b is **NOT AUTHORIZED / NOT EXECUTED**. AS-16 / M147-F01, M147-F02, M147-F03, M147-F04, M147-F05, M147-F06, M147-F08, and CI-REL-01 remain open at their documented scopes. No M12 gate value has been read. No scanner, feed sync, queue job, incident, notification, backup, restore, remediation, deployment, production access, or customer-site access occurred.


## Owner-provided staging evidence update — 2026-10-19

This addendum does not rewrite the 2026-10-18 state. The Owner now reports that, from the Rackforest console, the staging checkout was `be427a17320ca7aba9ec292d2c288813df5f7c07`, PHP CLI was 8.4.26, the non-root `deploy` account owned the checkout, and the worktree was clean. The Owner also reports `70,385,975,296` available bytes and execution of the standalone audit runner at the SHA-256 currently recorded in this repository. The original full runner JSON (including its top/header), audit identifier and exact UTC time are not archived; the disk value cannot be reconciled to a complete source artifact yet.

In a separately authorized configuration inspection, the Owner reports that the effective Laravel `config:show privacy` output explicitly showed all six M12 booleans as false. The exact UTC time/full output are unavailable and Codex did not perform the operation. Therefore M147-F02's gate-readback subcriterion is OWNER-REPORTED PASS for this one reported configuration/revision only; M147-F02 remains OPEN pending timestamped evidence archival and a fresh pre-scan read after any approved release.

At the verified primary `5e1e8f68951b2925e57dd406a9b3fcd1a7923799`, merged-primary workflow [39255687374](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39255687374) passed all seven mandatory jobs. The explicit Server MySQL process-concurrency step passed (2-test/13-assertion precheck and 1-test/6-assertion gate). The primary/staging relation is linear: staging `be427a…` is an ancestor and is 104 commits behind primary. Scanner-related changes in that interval include bounded/no-redirect feed transfer and atomic validated index replacement, stricter incomplete vulnerability coverage handling, and posture logic requiring every declared scanner in a completed fresh scan to be complete. No Laravel migrations changed in the interval. The tracked `wordpress/` delta is limited to package manifests/lock and tests; no Connector runtime source file changed. The actual installed staging Connector artifact/version/pairing remains unverified.

Consequently, no scanner result on the reported staging revision would validate current-primary behavior, and no staging update or scan is authorized here. M147-E.2b remains NO-GO. AS-16/M147-F01, M147-F03/F04/F05/F06/F08, and CI-REL-01 remain OPEN; M147-F07, M147-C-02 and M146-F08 retain only their previously bounded closures. M14.7 remains incomplete and M14.8 NOT READY. No staging action was performed by Codex.

## Local synthetic and browser validation update — 2026-10-19

On the merged-primary source tree, the Laravel Security feature directory passed **103 tests / 1,783 assertions / 0 failures**, including a new filesystem-failure injection for feed cache publication. The focused feed/posture suite passed **15 tests / 170 assertions / 0 failures**, the standalone staging audit runner suite passed **8 tests / 55 assertions**, the M147-A Node safety suite passed **11/11**, and the guarded local browser harness passed **14/14** at PR-head `61f7d07f78c4bc4b065d8b3126bd4983e9759388` whose tree matches primary `5e1e8f68951b2925e57dd406a9b3fcd1a7923799`. Local browser tests used only synthetic identities, separate SQLite stores and loopback services; no Stripe, external service, staging or production access occurred.

The feed regression identified that a failure to rename the validated raw-cache file could occur after the active vulnerability index had already been replaced. A narrow candidate fix stages the validated index separately and makes the active index the last atomic publication step; a deterministic test now proves the last-known-good index is retained when raw-cache publication fails. This is a confirmed failure-state integrity defect, not evidence of live exploitation. The correction remains unmerged and exact-head CI has not yet run. M147-F07 is reopened for this newly demonstrated path and remains open for final active-index rename failure, concurrent sync, live provenance/licensing, and DNS/private-address behavior.

CI-REL-01 remains open: merged-primary CI passed, but no fresh local MariaDB fault-injection or repeated stress run was completed here, and the original runner root cause is not established. The newly updated primary worktree cannot close it by inference from unrelated scanner tests.


## Exact updated-head CI reconciliation — 2026-10-19

The preceding documentation update created PR-head commit `bfee9cecf28672c273fe918462a9bd92426d2a2a`.

- PR-triggered exact-head run [39169331051](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39169331051) completed successfully on that SHA: Quality, Server SQLite, Server MySQL, Server MariaDB, Connector WordPress SQLite, Connector WordPress MySQL, and Connector WordPress MariaDB all passed. The Server MySQL job's **Required MySQL process-concurrency gate** step passed.
- A separate workflow-dispatch run [39169346161](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39169346161) on the same SHA failed only in Quality's Connector package version/source consistency step. Its dispatch inputs had no event base; the checker then attempted `git merge-base origin/main HEAD` and failed because `origin/main` is absent in this repository. This is a dispatch-context/base-resolution failure, not evidence of a Connector package mismatch. The run's six other required jobs passed, including the Server MySQL gate. The PR-triggered run is the applicable exact-head result.
- No production code, configuration, dependency, or workflow was changed by this evidence update.

The updated PR exact head after this addendum requires its own PR-triggered CI; no status is inferred from the prior SHA.

## PR #132 final feed-integrity review follow-up — 2026-10-19

This section supersedes only the immediately preceding working-tree account of raw-cache publication; earlier evidence remains historical. In the old sequence, raw-cache rename could succeed and active-index rename fail, leaving the raw cache and authoritative index from different downloads. The scanner itself continues reading the prior validated index, and repository search found no runtime reader of the raw cache. The inconsistency therefore did not establish a false CLEAN result, but could mislead manual provenance/freshness review.

The candidate now treats the index as the sole active artifact. Downloaded feed bytes stay temporary. A legacy raw cache is removed before the index switch only if it is a regular non-symlink file; an unsafe path or failed unlink stops before publication. `AtomicIndexPublisher` performs one checked same-filesystem rename to activate the complete index. A deterministic injected publication failure preserved the previous index; temporary files were cleaned; the following successful synthetic sync activated the new index. A directory at the legacy cache path is rejected before active replacement. Two concurrent local publisher processes were tested against an active-file reader; every observed hash matched the old or one complete staged document.

Local validation on this candidate: focused feed/posture suite 17 tests / 858 assertions; full Security feature suite 105 tests / 2,503 assertions; Pint pass. These checks do not establish command-level freshness ordering among concurrent downloads, process termination exactly at the filesystem syscall boundary, feed licensing/provenance, or live production filesystem behavior. M147-F07 remains OPEN. M147-F02 remains OPEN despite Owner-reported false values for one prior staging observation; archival and fresh pre-scan verification remain outstanding. CI-REL-01 remains OPEN; this work does not resolve its earlier Connector MariaDB timeout root cause.

No staging/production access, live scanner/feed, M12 read or change, queue, incident, notification, backup, restore, deployment or external-service call occurred. PR #132 remains the sole focused integration candidate and must stay unmerged pending Owner review. Exact-head CI result is recorded in the final current PR review evidence only after it completes.
