# M147-A Local Fixture Execution Evidence

## Scope and authority

- **Scope:** M147-A local synthetic fixture bootstrap, health validation, and safety/cleanup checks only.
- **Source SHA under test:** `5dc8a4618faaff958621ccc6fde5a2795543054c` (PR #120 implementation branch).
- **Planning baseline:** `0a0d25c2da256015d5cffad5f419603593ed72b5` (PR #119 merge).
- **Environment classification:** `local-disposable`.
- **M12 values:** not read. This local execution does not satisfy the required fresh M12 gate read before any separately authorized staging scanner validation.
- **Scanner coverage:** `NOT_RUN`.

## Locked dependency provenance

The exact committed `wordpress/composer.lock` was used; dependency constraints were not changed. Composer validation passed. The two relevant packages were:

| Package | Locked version | Locked source ref |
|---|---:|---|
| `johnpbloch/wordpress-core` | `7.1.3` | `326accad3c1f9aeef9d6e3503c24b5a5ca6ed094` |
| `aaemnnosttv/wp-sqlite-db` | `1.3.3` | `6933d833c51f06746eb5af2dc8478565c985f957` |
| `composer/installers` | `2.3.0` | `12fb2dfe5e16183de69e784a7b84046c43d97e8e` |
| `phpunit/phpunit` | `13.4.2` | `7b9ba83328f01ce299ef82161720195bd827a4bb` |

Dependencies were fetched from the lockfile-selected official GitHub commit-addressed archives over TLS. The install used a blank temporary Composer home/cache with no auth and `--no-scripts --no-plugins --prefer-dist`; 30 locked packages were installed, with zero updates and zero removals. No package constraint or TLS/integrity control was relaxed. The lockfile `dist.shasum` fields are empty; therefore no archive checksum or signature verification is claimed. Composer lock validation, fixed VCS refs, Composer package metadata validation, and TLS are the provenance evidence available here.

Tool versions: PHP CLI `8.4.26` with PDO SQLite and SQLite3; Node `v24.15.0`; Composer `2.10.2`. The PHP validator ran with `-n`, explicit extension directory, URL wrappers disabled, and network-capable PHP functions disabled.

## Fixture identity

- **Fixed fixture path:** `storage/e2e/m14-7-local-fixture` inside the dedicated implementation worktree.
- **Generation / target ID:** `m147-a-f873d260-cc21-489a-8043-cedc5de365d5`.
- **Synthetic tenant ID:** `synthetic-tenant-aafbf9d3-fbec-49a8-9f83-3fed3f86b034`.
- **Synthetic website ID:** `synthetic-website-cf1034b3-8d2c-452a-9f6b-0d9885168478`.
- **Synthetic WordPress administrator ID:** `synthetic-owner-484c7f0e-30d8-4ebf-8670-a3b922ced9ae`.
- **Synthetic domain:** `<target-id>.example.invalid`.
- **WordPress:** `7.1.3`.
- **Connector:** `0.4.7`, discovered locally and inactive; no pairing was created.
- **WordPress dependency tree SHA-256:** `f071613ec8982ca7e95242629dc36438dd799c16290fee84bd68c49a6012ec38`.
- **Connector source-tree SHA-256:** `07eef2dc36c1bd6e22fd37806b4555fc56fb7c47580ebc74de588ef2ee3b2f98`.
- **SQLite database:** fixture-local, 258,048 bytes; SHA-256 `7B4F37CCAE01C571380333F90029085F7106569A386CA561F4CBD191734D7B00` before cleanup.

## Executed setup and health checks

The fixture was freshly prepared with the M147-A harness using its documented `prepare` flow. The PHP validator ran in two fixed modes, `install` and `health`, without starting a web server.

Observed results:

| Check | Result | Evidence |
|---|---|---|
| Preflight | PASS | Fixed local path, marked worktree, lock-selected WordPress/Connector trees, and SQLite extensions satisfied. |
| WordPress installation/bootstrap | PASS | WordPress reported the expected locked version and created the synthetic administrator. No generated password was printed. |
| SQLite integration | PASS | SQLite drop-in initialized a database at the fixed path below the fixture root; database header and file scope validated. |
| Connector loading | PASS, limited | Connector `0.4.7` was discoverable; it remained inactive and unpaired. |
| Local validator health mode | PASS | WordPress loaded and the required fixture-local identity/database/content/network settings were validated. This was not an HTTP health request. |
| WordPress outbound HTTP filter | PASS | A synthetic external URL was blocked by the WordPress HTTP API pre-request filter; no external request was made. |
| PHP process network boundary | PASS | Validator rejected a run where network functions or URL wrappers were available. The successful run used the constrained CLI configuration. |
| External destination tamper case | PASS | A fixture-only Connector destination changed to `https://example.invalid/connector` was rejected before WordPress boot; the original fixture configuration was restored and compared. |
| Evidence output | PASS | `M147A.LOCAL.01` emitted bounded machine-readable metadata with expected/actual `PASS`, local-disposable classification, and scanner `NOT_RUN`; no credentials or raw request payloads were included. |

Fixture creation was completed at approximately `2026-10-18T06:16:18.876Z`; evidence creation at `2026-10-18T06:18:04.089Z`. The output schema intentionally excludes secret values and customer/site data. No application queue, external storage, customer Connector, provider, feed, Stripe, AI provider, staging or production system was contacted.

## Safety and test results

- `node scripts/m14-7/m147-a.test.mjs`: **11 passed, 0 failed, 0 skipped**. These are synthetic harness tests, including path/environment refusal, symlink/unmarked target handling, idempotent operations, fake in-memory queue/storage behavior, egress policy and evidence validation.
- `npm test`: **20 passed, 0 failed, 0 skipped** (the 11 M147-A tests plus 9 existing marketing analytics tests).
- Node syntax check: passed.
- PHP syntax check (`php -l` under the constrained PHP CLI): passed.
- Pint 1.32.1 on `scripts/m14-7/validate-fixture.php`: passed.
- Repository secret-path/supply-chain policy checks: passed locally with bytecode generation disabled.
- A direct validator negative run with network capability available exited `3` with the expected network-function or URL-wrapper refusal before WordPress boot.
- A PHP request did not connect externally; the deliberate external URL was only passed to the WordPress pre-request blocking filter.

The fake queue/storage assertions are unit-level test doubles; no real Laravel queue or storage driver was invoked. The fixture execution did not run PHPUnit, Connector browser tests, the Playwright suite, scanner rules, feed parsing, malware specimens, or application incident/backup side effects. No OS-level network namespace was configured or claimed.

## Actual cleanup observation

The harness cleanup completed with `PASS / OWNED_FIXTURE_REMOVED`. The marked fixture root was absent after cleanup, while its `storage/e2e` parent remained. Process/listener inspection observed zero listeners on ports 8317 and 8318 and zero PHP processes matching the fixture installer/server. Six unrelated pre-existing PHP processes were observed and left untouched. No test service had been started. The fixture-local database and evidence file were removed with the owned fixture.

No staging, production, customer site, shared storage, backup, credential, or infrastructure was read or modified. No real provider call or external network request was made.

## CI evidence status

For PR head `a688d0333871f2dcb2d3e4406e8c095968ac8029`, mandatory workflow run `39103832139` attempt 1 completed with six jobs successful and Connector WordPress MariaDB failed once: `PollLockConcurrencyTest::test_expired_owner_cannot_release_or_refresh_successor_lease` timed out waiting for a synthetic process synchronization result file. The same test passed on attempt 2, which completed all seven mandatory jobs successfully on the unchanged exact SHA. Server MySQL passed its full test suite and the required process/concurrency gate on attempt 2. The gate is intentionally skipped for SQLite and MariaDB matrix entries; only the MySQL matrix entry is authoritative for that gate. The follow-up documentation update means PR #120 has a newer head; its own exact-head CI must complete 7/7 before merge. No earlier SHA's CI is accepted as evidence for the updated head.

## Findings and boundaries

- M147-A fixture validation: completed locally for the bounded checks above.
- AS-16 scanner coverage: remains open; no scanner run occurred.
- M146-F08 log-integrity adversarial testing: remains open; not part of this fixture run.
- Six M12 live gate values: remain unverified; none were read or changed.
- Independent security review: not claimed.
- M147-B and all later M14.7 stages: not started or authorized by this record.
