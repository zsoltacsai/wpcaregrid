# Laravel PHP 8.4 CI retirement

**Scope:** CI configuration only. This change does not install, remove, or switch PHP on any host, and it does not authorize a staging or production operation.

The protected primary was `08cf12c1be9810a2be4afeb73dd335233830a2f5` when this change was prepared. The workflow change sets Quality, Laravel MySQL server tests, SQLite regressions, and Connector release-artifact packaging to PHP 8.5. The Connector customer-runtime matrix remains PHP 8.2–8.5 with its existing WordPress/database combinations. MySQL 8.4 remains the Laravel integration database.

## Protected check status

Ruleset [25300175](https://github.com/zsoltacsai/wpcaregrid/rules/25300175) was inspected and not modified. It currently requires:

1. `Quality (lint, style, dependencies, build, secrets)`
2. `Server tests (PHP 8.4, mysql)`
3. `Connector tests in WordPress (PHP 8.4, sqlite)`
4. `Connector tests in WordPress (PHP 8.4, mysql)`
5. `Connector tests in WordPress (PHP 8.4, mariadb)`
6. `Server SQLite regression gate (PHP 8.4)`
7. `Server tests (PHP 8.5, mysql)`

Removing the PHP 8.4 server and SQLite lanes means the two currently required PHP 8.4 contexts will not be produced by the revised workflow. Their check names are not aliased to PHP 8.5 runs. Unless the protected ruleset is changed through its separate authorized process, strict required-check enforcement therefore blocks merging this change. The review must explicitly resolve that administrative dependency without changing it in this repository task.

## Coverage retained

- PHP 8.5 runs the complete Laravel MySQL suite, including migrations, process concurrency, storage services, and failure-path checks.
- PHP 8.5 retains the targeted SQLite regression gate.
- The WordPress Connector continues testing customer PHP 8.2–8.5, WordPress 6.8/7.1, MySQL 5.7/8.0/8.4, MariaDB 10.11, and its existing protected PHP 8.4 contexts.
- PHP 8.4 references for runtime packages, rollback, staging, and production are operational policy and are outside this CI-only change.

No branch protection setting, required-check name, runner installation/configuration, or production VPS is changed by this work.
