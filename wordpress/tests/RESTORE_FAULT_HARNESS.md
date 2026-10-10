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

## Duplicate, concurrency, and interruption limits

The smoke sends the same restore job UUID twice through the real `JobRunner` poll and verifies the restore handler runs only once. Existing Connector tests also cover duplicate job delivery, the per-site polling lock, and persisted interrupted-job boundaries. No new multi-process concurrent-different-restore orchestrator or hard process-kill tool is added here. Those destructive interleaving scenarios remain for the separately authorized M8 failure/recovery E2E phase.

## Run

With PHP 8.2+, Composer test dependencies installed, and a dedicated loopback MySQL database if exercising MySQL restore:

```sh
cd wordpress
APP_ENV=testing WP_CAREGRID_RESTORE_HARNESS=1 \
  WP_CAREGRID_TEST_MYSQL='user:password@127.0.0.1:port/dedicated_test_db' \
  WP_CAREGRID_TEST_STRICT=1 vendor/bin/phpunit --filter RestoreTest
```

The harness smoke is not a substitute for the full M8 failure matrix. Do not run it against a customer, staging, or production site.
