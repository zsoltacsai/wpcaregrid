# M14.7 Local Synthetic WordPress Fixture — M147-A

This document describes the M147-A disposable local WordPress scaffold and safe evidence harness. It does **not** authorize scanner execution, browser tests, staging access, production access, a live vulnerability feed, real side effects, or customer-site activity. No M14.7 scanner campaign is claimed complete.

## Scope and safety properties

- The fixture path is fixed at `storage/e2e/m14-7-local-fixture` in a dedicated `codex/m14-7-*` worktree. The implementation was prepared on `codex/m14-7-baseline-harness`.
- Setup copies the WordPress core and Connector sources from this repository's locked Composer package and Connector tree. It records their source versions and hashes in a marker.
- The target, tenant, website, and user identifiers are synthetic. The site domain ends in `.example.invalid`; mail is a no-op; WordPress HTTP is blocked except explicitly permitted HTTP loopback ports 8317 and 8318. WordPress cron and automatic updates are disabled.
- Setup does not start a web server, pair a Connector, contact the CareGrid application, run a scanner, or call any provider.
- Setup and cleanup reject staging/production classifications, unknown classifications, symlinked fixture paths, unmarked directories, and markers that do not match the fixed repository/worktree. Cleanup removes only the marked fixture directory and leaves its parent and siblings intact.
- Fake queue and storage adapters are in-memory only. Delete and restore operations always throw. These adapters are test doubles, not application queue or storage bindings.
- Evidence records have an allowlisted schema and bounded values. Free-form payloads, secret-like values, and control sequences are rejected. Evidence records scanner coverage as `NOT_RUN` unless an explicitly authorized future stage proves otherwise.

These application-level guards do not create an OS network namespace. Do not start arbitrary test code or a server from the fixture unless a separate approved harness establishes outbound network denial at the operating-system/container layer. M147-A preparation itself makes no outbound requests.

## Prerequisites

- Node.js 22 (or a maintained Node 22 patch release).
- Git and a clean checkout of the reviewed M147-A branch.
- The repository's locked WordPress Composer dependencies, including `johnpbloch/wordpress-core` and `aaemnnosttv/wp-sqlite-db`.
- PHP 8.4 with SQLite support only if a later approved local stage installs or runs WordPress.

M147-A's own safety tests require Node.js only and use temporary synthetic Git repositories. In this execution, no PHP/Composer executable was available through the implementation environment's normal PATH. An offline Composer install using a blank temporary Composer home, network disabled, and a local package cache was attempted; the required WordPress core and locked packages were absent from that cache. No network fallback was permitted. Therefore the live scaffold was **not created** in this validation run; preflight returned `BLOCKED` for missing prerequisites. The fixture code is prepared, and this limitation is recorded rather than represented as a successful fixture execution.

## Commands

Run from the repository root on a dedicated `codex/m14-7-*` branch:

```sh
node scripts/m14-7/m147-a.mjs baseline
node scripts/m14-7/m147-a.mjs preflight
node scripts/m14-7/m147-a.mjs prepare
node scripts/m14-7/m147-a.mjs cleanup
npm test
```

`baseline` emits source/version references and M12 key names, not live gate values. `preflight` and `prepare` accept only the default `local-disposable` classification; passing `staging`, `production`, or any other value is blocked. Do not add an arbitrary path or URL argument. `prepare` requires the locked local WordPress/SQLite packages and creates a marked source scaffold. `cleanup` is idempotent and will refuse an unmarked or symlinked target. Preserve the marker until cleanup has been verified.

The scaffold is not a paired Connector browser-test environment. It does not launch either loopback service, create a CareGrid tenant in a server database, or authorize tests from `docs/CONNECTOR_E2E_LOCAL.md`. Browser tests, scanner execution, fake-feed integration, and test execution that exercises application side effects remain separate approval stages.

## Evidence format

`m147-a-evidence-v1` permits only:

- test identifier and bounded scope;
- repository SHA, Connector version and source-tree SHA-256;
- synthetic target identifier and `local-disposable` environment classification;
- execution timestamp;
- expected and actual classifications (`PASS`, `FAIL`, `BLOCKED`, `UNAVAILABLE`);
- scanner coverage (`NOT_RUN`, `UNAVAILABLE`, `PARTIAL`, `COMPLETE`);
- cleanup status;
- optional GitHub Actions run ID and canonical run URL.

Evidence is written only under the fixed marked fixture's `evidence/` directory, with create-only filenames. It does not accept raw output, arbitrary request/response bodies, file contents, credentials, secret environment values, or unbounded diagnostic text. Store CI artifacts only after applying the same review and redaction rules.

## Validation and limitations

The focused Node suite exercises environment refusal, missing-prerequisite blocking, idempotent marked setup/cleanup against synthetic package trees, symlink and unmarked-target refusal, in-memory fake side effects, loopback URL allowlisting, and evidence schema/redaction checks. It does not run WordPress, a Connector, a scanner, a browser, a real queue/storage driver, or a provider feed. It does not verify OS-level egress isolation. No M12 gate values were read; the six M12 gates remain required fresh read-only checks before any separately authorized staging scanner validation. AS-16 and M146-F08 remain open.

## Troubleshooting

- `BLOCKED` with `WORDPRESS_CORE_NOT_INSTALLED` or `SQLITE_DROPIN_NOT_INSTALLED`: install the repository's locked WordPress Composer dependencies using an approved local package source; do not enable network access just to make the fixture pass.
- `UNAPPROVED_WORKTREE`: run only from the dedicated M147-A worktree/branch and verify its clean reviewed source.
- `UNMARKED_FIXTURE_PATH_REFUSED`, `FIXTURE_MARKER_MISMATCH`, or any symlink refusal: stop and inspect the local path manually. Do not delete or overwrite it to force setup.
- If cleanup reports a marker/path error, preserve the directory and request review; never broaden cleanup to a parent directory.
