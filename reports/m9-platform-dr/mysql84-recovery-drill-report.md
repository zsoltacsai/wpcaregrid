# MySQL 8.4 Disposable Platform Recovery Drill

Date: 2026-10-11
Target: disposable local Docker container `mysql:8.4`, bound only to loopback port 33384.
Scope: synthetic test data; no staging/production/customer database and no provider storage.

## Procedure and result

The drill used the actual `PlatformDatabaseBackupService` MySQL snapshot path. A disposable schema was initialized, a consistent dump was created and finalized as a versioned authenticated-encryption artifact, the artifact and authenticated sidecar were verified, and the isolated recovery path decrypted/imported it into a newly generated scratch schema. The test checked restored table presence and representative counts, then removed its temporary work files. Result: **1 test passed, 5 assertions**.

The run used a task-local Docker helper and a task-local MySQL client wrapper to bridge the test's host command expectations into the container. The wrapper and helper contained only disposable test credentials and are not deliverables. They were removed after the run. The named `--rm` container was stopped and removed; no container with that test name remained. A separate scratch SQLite database used for migration reversibility was also removed.

## What this proves

- MySQL 8.4 dump generation through the platform backup code path.
- Artifact encryption, integrity metadata and verification.
- Isolated decryption and import to a clean generated schema.
- Basic schema and representative synthetic data sanity after import.

## What this does not prove

- S3 bucket provisioning, live credentials, provider-side encryption/versioning/Object Lock, or remote upload/readback.
- A replacement SaaS host booted against the restored MySQL database.
- A real production queue or customer dataset recovery.
- APP_KEY escrow/retrieval.
- Remote GitHub CI. The CI workflow includes MySQL 8.4 service coverage but was not dispatched because push/remote CI was not authorized.

## Repeatability and evidence

The PHPUnit case is `tests/Feature/Platform/PlatformDatabaseBackupMysqlTest.php`; CI provisions the MySQL 8.4 service and should run this test with the same environment contract. The test and exact run output remain in the local test evidence/logs; no backup artifact was retained as a production recovery point.

**Result: local disposable MySQL 8.4 import drill PASS, with remote CI and S3 validation still required.**
