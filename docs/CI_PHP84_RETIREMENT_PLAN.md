# Laravel PHP 8.4 CI retirement — preparation only

**Status:** `CI_PHP84_RETIREMENT=PREPARATION` / `REMOVAL_GATE=HOLD`
**Planning baseline:** `c9b1378cce26ab243bce2690749a57ecb15ad8dd`
**Owner decision:** [Issue #285](https://github.com/zsoltacsai/wpcaregrid/issues/285)

This document prepares a focused change; it does not remove, rename, or weaken any current check. Retirement is blocked until PHP 8.5 staging Laravel/runtime acceptance is formally recorded and the PHP 8.4 rollback window is explicitly closed by the Owner and Integration & QA.

## Current protected-primary evidence

The active [primary ruleset](https://github.com/zsoltacsai/wpcaregrid/rules/25300175) requires pull requests, stale-review dismissal, resolved review threads, strict required status checks, and has no bypass actors. It currently requires:

1. `Quality (lint, style, dependencies, build, secrets)`
2. `Server tests (PHP 8.4, mysql)`
3. `Connector tests in WordPress (PHP 8.4, sqlite)`
4. `Connector tests in WordPress (PHP 8.4, mysql)`
5. `Connector tests in WordPress (PHP 8.4, mariadb)`
6. `Server SQLite regression gate (PHP 8.4)`
7. `Server tests (PHP 8.5, mysql)`

The current [CI workflow](https://github.com/zsoltacsai/wpcaregrid/blob/c9b1378cce26ab243bce2690749a57ecb15ad8dd/.github/workflows/ci.yml) sets `PHP_VERSION: '8.4'`; the server MySQL matrix includes `['8.4', '8.5']`. The server SQLite regression context is separately named for PHP 8.4. MySQL 8.4 is a database-engine version and remains required independently of PHP retirement.

## Changes eligible only after both release gates pass

Candidate application workflow changes are limited to:

- Quality server runtime: PHP 8.4 → PHP 8.5.
- Laravel MySQL server matrix: PHP `['8.4', '8.5']` → `['8.5']`.
- Laravel SQLite regression lane: PHP 8.4 → PHP 8.5, retaining the SQLite gate.
- Keep MySQL 8.4 and all migration, concurrency, restore, and failure-path coverage.
- Inspect the PHP 8.4 Connector package-build use separately before changing it.
- Preserve the WordPress Connector customer-runtime PHP 8.2–8.5 matrix unchanged.

The check-name transition must be coordinated with Integration & QA and the ruleset owner. The replacement PHP 8.5 SQLite context must be present and required before the old PHP 8.4 SQLite context can be removed; no interval may silently omit a required check. Repository PR review alone cannot update an administrative ruleset.

## Evidence required before preparing the retirement PR

Record links and exact release identity for all of the following:

- Accepted PHP 8.5 staging web/FPM and CLI runtime.
- PHP 8.5 Laravel HTTP and application acceptance.
- Queue and scheduler compatibility acceptance for the deployed operating profile.
- Current verified backup, isolated restore, migration rehearsal, and tested recovery/rollback evidence.
- Formal Owner and Integration & QA closure of the PHP 8.4 rollback window.

Installed PHP 8.5 packages, CI success, helper preflight, or `artisan system:check` do not satisfy these operational gates by themselves.

## Planned integration sequence

1. Recheck the authoritative primary, exact workflow, required contexts, and ruleset immediately before editing.
2. After both release gates are accepted, update the focused workflow lanes while retaining all Connector and MySQL-engine coverage.
3. Coordinate the replacement required-context name with the ruleset administrator before the old context is retired.
4. Run PHP 8.5 server MySQL/SQLite tests, all Connector PHP 8.2–8.5 lanes, MySQL 8.4, concurrency/restore gates, and exact-HEAD CI.
5. Obtain independent Integration & QA and M17 AppSec exact-diff reviews; use only the protected merge workflow.
6. Verify exact merge-SHA CI and update the #174 ledger. Remove PHP 8.4 checks only after the formal rollback-window closure is recorded.

No staging or production operation is authorized by this preparation document.
