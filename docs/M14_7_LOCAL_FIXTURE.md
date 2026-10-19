# M14.7 Local Synthetic WordPress Fixture — M147-A

This is a dedicated, disposable local fixture for M147-A setup, bootstrap, and safety validation. It does **not** authorize scanner execution, browser tests, staging or production access, a live vulnerability feed, real side effects, or customer-site activity. No security scan is claimed complete.

## Safety boundary

- Use a clean, dedicated `codex/m14-7-*` worktree and the fixed path `storage/e2e/m14-7-local-fixture` only. The fixture marker binds its target ID, synthetic identities, repository path, branch, commit, and dependency tree digests.
- The supported environment is `local-disposable`. Staging, production, unknown classifications, wrong repository/branch/commit, unmarked paths, path escapes, symlinks, special filesystem nodes, and ownership mismatches fail closed.
- The fixture uses synthetic tenant, website, and WordPress administrator identities. The domain ends in `.example.invalid`. It creates no CareGrid server tenant, customer Connector pairing, shared-storage binding, or real account.
- No web server is started. WordPress outbound requests are blocked by its HTTP API filter; the PHP validation process also disables networking functions and URL wrappers. This is process-level defense in depth, not proof of an OS network namespace. Do not run unrelated code from the fixture.
- Connector source is copied and confirmed discoverable but remains inactive. No application jobs run. Fake queue and storage adapters are in-memory test doubles; destructive methods throw.
- No live Wordfence feed, scanner, malware specimen, remediation, backup/restore, Stripe, AI provider, customer notification, staging/production service, or external storage operation is part of M147-A.

## Locked dependencies and verified local execution

The fixture uses only lockfile-selected packages:

| Package | Version / reference |
|---|---|
| `johnpbloch/wordpress-core` | `7.1.3`, ref `326accad3c1f9aeef9d6e3503c24b5a5ca6ed094` |
| `aaemnnosttv/wp-sqlite-db` | `1.3.3`, ref `6933d833c51f06746eb5af2dc8478565c985f957` |
| Connector | `0.4.7`; source revision `9e330854f0e7d5ef073db846245f7008d7ef6ec5` |
| PHP CLI | `8.4.26`, with PDO SQLite and SQLite3 |
| Node.js | `v24.15.0` |
| Composer | `2.10.2` |

Both Composer installs used the committed lockfiles, official commit-addressed package archives over verified TLS, an isolated temporary Composer home/cache, and `--no-scripts --no-plugins --prefer-dist`. No constraints were relaxed or credentials used. The lockfile package `dist.shasum` fields are empty, so archive checksum verification is **not** claimed; integrity evidence is lock validation, fixed VCS refs, Composer's package metadata checks, and TLS.

On source SHA `5dc8a4618faaff958621ccc6fde5a2795543054c`, the disposable fixture was freshly installed and separately health-validated. WordPress `7.1.3` bootstrapped against its fixture-local SQLite database; the Connector `0.4.7` was discoverable and inactive; the synthetic administrator was present; no plaintext generated password was emitted. The fixture-only database was 258,048 bytes. Dependency tree digests and the generation/target IDs are in [M147-A execution evidence](../reports/m14-security/m14-7-a-execution-evidence.md).

The PHP validation invocation used `-n`, an explicit extension directory, SQLite extensions, `allow_url_fopen=0`, `allow_url_include=0`, and disabled network-capable PHP functions. Starting a service was unnecessary and none was started.

## Commands

From the repository root in the approved dedicated worktree:

```sh
node scripts/m14-7/m147-a.mjs baseline
node scripts/m14-7/m147-a.mjs preflight
node scripts/m14-7/m147-a.mjs prepare
node scripts/m14-7/m147-a.mjs cleanup
npm test
```

Use only repository-provided locked dependency installation instructions to provision `wordpress/vendor`. The validation script accepts only fixed `install` and `health` modes and reads the fixed fixture paths. Do not pass a URL or arbitrary path. `prepare` does not start a server. `cleanup` requires the current marked target and exact source identity; it refuses unrecognized filesystem objects and deletes only the owned fixture contents.

For the PHP validation command and its required isolation flags, use the command recorded in the execution evidence and implementation test harness; do not substitute a normal PHP configuration that restores network functions. If a prerequisite or isolation check fails, stop before setup.

## Evidence and cleanup

Evidence schema `m147-a-evidence-v1` is allowlisted and bounded. It records test ID/scope, source SHA, Connector version/tree digest, synthetic target ID, `local-disposable` classification, timestamp, expected/actual result, scanner coverage, cleanup result, and optional CI reference. It rejects arbitrary payloads, secret-like values, and unsafe control sequences. No credentials, account secrets, customer data, request bodies, or unnecessary file contents are recorded.

Setup and health returned `PASS`. Negative checks confirmed that network-capable PHP configuration is rejected and an altered external Connector destination is rejected before WordPress boot. The actual cleanup removed only the marked fixture; the fixture root was absent afterward, its parent remained, no listeners existed on ports 8317/8318, and no fixture installer/server PHP process remained. Six unrelated pre-existing PHP processes were observed and left untouched. See the execution evidence for exact identifiers, timestamps, test counts, and limits.

The harness does not provide OS-level network namespace isolation or execute the Connector browser harness. Connector discovery/inactive state is not a browser test. Scanner coverage remains `NOT_RUN`; no feed was configured. The six M12 gate values were not read. A fresh secret-safe read-only confirmation of all six false values remains mandatory before any separately authorized staging scanner validation. AS-16 and M146-F08 remain open.

## Separate local Connector browser E2E harness

The Windows browser harness is a separate local-only environment. It starts
Laravel and a disposable WordPress site on `127.0.0.1:8317` and
`127.0.0.1:8318`, uses separate SQLite files and synthetic users, disables
WordPress cron, blocks non-loopback WordPress HTTP, and configures application
integrations as local fakes or disabled. The browser suite performs actual UI
pairing and recovery flows; it does not run against staging or production.

The harness retains its two historical worktree path/branch allowlist entries.
The recovery path is one explicit identity, fixed to
`D:\-=AI=-\-=Git=-\wpcaregrid-m147-recovery` and branch
`codex/m147-closure-recovery`. The integrated review path is a separate exact
identity, fixed to
`D:\-=AI=-\-=Git=-\wpcaregrid-m147-integration-review` and branch
`codex/m147-pr-integration-review`. No arbitrary path or branch can register.
Register the exact committed SHA before any local server is started, then pass
that same 40-character SHA to the runner:

```powershell
$expected = (git rev-parse --verify HEAD).Trim()
& .\scripts\e2e\windows\Register-ConnectorBrowserWorktree.ps1 -ExpectedCommit $expected
& .\scripts\e2e\windows\Invoke-ConnectorBrowserE2E.ps1 -ExpectedCommit $expected
```

Registration refuses a different directory or branch, a dirty source tree,
unexpected active service/fixture state, or a HEAD mismatch. Every entry point
rechecks the repository path, branch and recorded exact HEAD; browser execution
also requires the explicit `-ExpectedCommit`. The E2E runner first bootstraps
its pinned local PHP runtime and dependencies, then verifies the synthetic
configuration, database separation, loopback ports and egress guard before
starting services. No existing unrelated service is reused or stopped.

The browser harness only covers its declared Connector pairing/recovery
specification. Stripe and live-service tests remain excluded. A test result
does not prove staging state or operational scanner safety.

## Troubleshooting

- Missing locked `wordpress/vendor` packages: install from the committed `wordpress/composer.lock` using the approved Composer procedure. Do not switch versions, disable TLS/integrity, or use `--ignore-platform-reqs`.
- `BLOCKED` or an isolation refusal: stop; do not bypass the check or broaden the target path.
- Marker/path/ownership/symlink refusal: preserve the path for review. Never remove or overwrite its parent or an unmarked target.
- Partial cleanup failure: marker preservation allows a safe retry; do not manually broaden cleanup.
