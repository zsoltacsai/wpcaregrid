# Restore fault-injection harness

This harness exists only under `wordpress/tests/` and is loaded through the test Composer autoloader. It has no WordPress REST route, Owner control, customer setting, deployment variable, or production UI.

## Trust boundary

The normal plugin registry constructs `RestoreHandler` with no test dependencies. Its default `WordPressBackupIndexLookup` reads the Connector's canonical `BackupIndex`; restore payload UUIDs and size/SHA-256 values must still match that index, and downloaded bytes are still independently verified. The injectable lookup and boundary observer are constructor dependencies used by the integration tests only.

`RestoreHarnessGuard` requires all of the following before a test variant or fault plan can be created:

- `APP_ENV=testing`;
- `WP_CAREGRID_RESTORE_HARNESS=1`;
- a real `WP_CAREGRID_TEST_ROOT` below the OS temp directory with the `wp-caregrid-tests-` prefix;
- a loopback WordPress `siteurl`;
- the explicit synthetic fixture marker option.

The integration-test bootstrap creates a fresh disposable WordPress site. MySQL restore runs must use a separately provisioned test database; the bootstrap drops/recreates that database. Never point `WP_CAREGRID_TEST_MYSQL` at the active M8 site, staging, or production.

## Variants and hooks

`RestoreVariantIndex` copies artifacts created by the normal test backup helper, verifies their canonical size and hash, then registers a new UUID only in memory. Supported copied variants are `copy`, `corrupt_database`, `corrupt_files`, `missing_database`, `missing_files`, `database_checksum_mismatch`, and `files_checksum_mismatch`. Variant directories are constrained under the disposable test root and can be removed with `cleanup()`; the source option/index and source artifacts are not rewritten.

`OneShotRestoreFault` can throw a sanitized `BackupFailure` at named restore boundaries. It is test-only, requires the same environment guard, records reached boundaries, and consumes an armed failure once. The production registry passes no hook object. Current points include source-index validation, verified download, file/DB preparation, pre-mutation, post-DB-swap, file-copy boundaries, and pre-health-check.

The local smoke deliberately stops at `before_destructive_mutation`, before maintenance mode, table swap, or file copy. It goes through the Connector's actual job poll, job store, restore handler, download, checksum, and archive-preparation path.

## Process concurrency and hard interruption harness

`RestoreRequestConcurrencyTest` starts independent Laravel CLI processes against an explicitly named local MySQL test database. Both call `RestoreService::request()` for one site while the owning process holds the same `Website` row lock; it covers the same verified backup and two different verified backups. The assertions require one accepted request, one `RESTORE_IN_PROGRESS` refusal, one active lifecycle, and no lock bypass.

`restore-process-worker.php` boots only an existing owned loopback WordPress test root and runs the real Connector `JobRunner::poll()` path with the real restore handler. The fake CareGrid API intercept is test-only, refuses every host except `app.caregrid.test`, and only serves verified artifact copies rooted under that temporary test site. `ProcessRestoreBarrier` publishes a PID/restore/boundary marker and waits no more than 30 seconds for the parent test. Separate opt-in tests send SIGKILL to that exact PHP worker PID before mutation or at `after_database_swap`; neither test kills MySQL or another service.

The interrupted Connector poll lock has a 900-second TTL. Tests do not rewrite lock state or fake time: they wait for its real expiry before a second normal poll redelivers the same job. A per-job, non-autoloaded WordPress option is the monotonic source of truth for destructive mutation; the matching job record mirrors it. Restore progress resolves the payload restore UUID to the distinct queue job UUID. The marker is persisted immediately before `RENAME TABLE` and included in the Connector-state snapshot carried through the atomic swap. Tests verify that a pre-mutation interruption reports `destructive_mutation_started=false`, while a kill after the database table swap reports `true` and `failure_stage=database_swap`. A fresh normal test restore then returns the disposable WordPress state to its verified baseline. This exercises Connector persisted-state/redelivery semantics; Laravel's existing MySQL restore tests separately verify that confirmed post-mutation failures queue at most one compensating safety restore.

The poll lease itself is stored in the unique `wp_options.option_name` row `wp_caregrid_poll_lock`. New lease values contain a fixed-width expiry and a per-process 256-bit random owner token. Initial acquisition uses an insert-ignore statement and stale reclaim uses one conditional SQL update matching the observed value and an expired timestamp; each operation trusts only an affected-row count of one. Refresh and release also compare the exact owner value, so an expired process cannot refresh or release a successor's lease. Legacy timestamp-only values remain readable and can be reclaimed atomically after their original 900-second lifetime. Deactivation and uninstall cleanup intentionally leave the lock row alone; a non-owner lifecycle hook cannot invalidate another poller's active lease.

`PollLockConcurrencyTest` requires the isolated MySQL test configuration and starts real PHP OS processes behind filesystem barriers. It checks fresh acquisition, 100 five-contender stale-reclaim races, non-expired legacy lease protection, stale-owner refresh/release, uninstall cleanup, and the harmless `SITE_HEALTH` WebsiteJob polling path. SQLite runs skip these MySQL-specific ownership proofs.

Each process E2E is opt-in so the regular Connector suite remains bounded. Set `WP_CAREGRID_RUN_PRE_MUTATION_INTERRUPTION_E2E=1` to run only the pre-mutation scenario, or `WP_CAREGRID_RUN_HARD_INTERRUPTION_E2E=1` to run only the post-swap scenario, together with the dedicated MySQL fixture variables. Each waits for the real 900-second poll-lock TTL; CI reports these long destructive-process tests as skipped unless a job explicitly opts in.

These process tests are test-only files, absent from the plugin's runtime package, REST routes, Owner controls, settings, and production registry. They require POSIX process support and a dedicated loopback MySQL test database. Do not point either suite at the active M8 fixture, staging, production, or customer sites.

The tests do not constitute the complete Phase 4 failure matrix. Corrupt/missing object cases, provider/database failure variants, and broader timeout/retry scenarios remain separate work.

## Run

With PHP 8.2+, Composer test dependencies installed, and a dedicated loopback MySQL database if exercising MySQL restore:

```sh
cd wordpress
APP_ENV=testing WP_CAREGRID_RESTORE_HARNESS=1 \
  WP_CAREGRID_TEST_MYSQL='user:password@127.0.0.1:port/dedicated_test_db' \
  WP_CAREGRID_TEST_STRICT=1 vendor/bin/phpunit --filter RestoreTest
```

The harness smoke is not a substitute for the full M8 failure matrix. Do not run it against a customer, staging, or production site.
