# WordPress Connector compatibility policy

This policy describes the PHP runtime on the **customer-hosted WordPress site** where the Connector executes. It does not describe the Laravel SaaS server runtime, which remains governed by the root Composer requirements and its own CI lane.

## Evidence and promotion rule

The Connector CI matrix runs the real WordPress integration suite across PHP 8.2, 8.3, 8.4 and 8.5; WordPress 6.8 and 7.1; and the explicitly listed MySQL and MariaDB branches in `.github/workflows/ci.yml`. Each lane exercises inventory and environment-fact collection, authenticated polling and job/result handling, security scanning, file and database backup/restore paths, and their filesystem, credential, tenant and authorization tests. The lane asserts the actual PHP, WordPress and database versions so a misconfigured runner cannot produce a false green result. A branch remains unpromoted in the server capability policy until its exact-head matrix passes.

CI evidence is necessary but not sufficient to enable a runtime/database branch in `config/compatibility.php`: add the branch only after the relevant exact-head CI matrix passes. Keep unknown branches as `UNKNOWN`, especially for backup, restore, remediation and other high-impact operations. Do not infer support from a neighboring PHP minor or from Laravel server tests.

The connector test harness selects PHPUnit versions that can run on each PHP branch. The Laravel server test suite stays on its separately declared PHP 8.4 runtime.

## Operation-specific support

Runtime and database facts are evaluated separately for each capability. Read-only inventory or security scanning may be supported while database backup/restore is not. WordPress upstream documents MySQL 5.5.5+ as its legacy minimum for current releases and recommends MySQL 8.0+ or MariaDB 10.11+. That core minimum does not certify Connector behavior or authorize backups. MySQL versions below 5.0 are unsupported; MySQL 5.5.5+ is not automatically supported for every Connector operation. Database backup requires a known engine and an exact tested major/minor branch, `mysqli`, and the normal capability, storage and authorization checks. EOL databases should be upgraded; test evidence is not a promise of security maintenance. A passing PHP result alone never implies database backup or restore support.

## PHP 8.1 legacy policy

PHP 8.1 is an optional legacy-recognition branch only. It is end-of-life upstream and must show an explicit `RUNTIME_VERSION_EOL` warning. It is not in the tested support matrix and must remain `UNKNOWN` for capability authorization until a separately reviewed legacy test policy establishes a narrower safe operation set. Do not advertise it as a supported secure runtime.

## Extending the matrix

To add a PHP, WordPress, MySQL or MariaDB branch, extend the GitHub Actions matrix and its exact-runtime assertions, run the full Connector integration suite for the relevant combinations, inspect each job result, then update the matching tested branch in `config/compatibility.php` only after those fixtures pass. Unsupported or unavailable fixture combinations remain untested; no production capability may be inferred for them. The Laravel SaaS server database matrix is separate and is not evidence for customer-hosted Connector databases.
