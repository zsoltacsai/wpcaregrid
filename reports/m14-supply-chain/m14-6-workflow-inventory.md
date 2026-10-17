# Workflow and Release Entrypoint Inventory

Baseline: primary `291f486…`. Source inventory used `rg --files .github`, workflow/script/package scans and the staging read-only shell.

## GitHub Actions

| Entry | Trigger / principal | Permissions, trust and inputs | Network/secrets | Output / deployment |
|---|---|---|---|---|
| `.github/workflows/ci.yml` (only tracked workflow) | `push` to authoritative branch or `main`; `pull_request`; `workflow_dispatch`. GitHub-hosted `ubuntu-24.04`, ephemeral jobs. | Top-level `contents: read`; no job-level permission widening. PR workflow executes PR merge code. Manual dispatch accepts optional string `connector_release_base`, passes it through job `env: EVENT_BASE`, then a quoted shell variable to `scripts/check-connector-release.php`; no direct expression interpolation into `run`. No `pull_request_target`. | Public package registries, GitHub release download, Ubuntu apt, npm/Composer/PyPI and GitHub-hosted service network. No `${{ secrets.* }}` reference, no deploy environment, no staging/production credential. Fork `pull_request` receives read-only `GITHUB_TOKEN` and no other repository secrets per GitHub behavior for public forks. | Seven result checks: Quality; Server SQLite/MySQL/MariaDB; Connector WordPress SQLite/MySQL/MariaDB. Connector ZIP is built/tested locally in the job; no artifact upload/download. No deployment authority. |

`cancel-in-progress: true` is scoped by `ci-${{ github.ref }}`. Cancellation on a superseded PR is acceptable; a cancelled primary run is not green evidence. Release policy must require a successful, completed run attached to the exact merged primary SHA, not a prior run on a parent/sibling.

The optional dispatch string is not shell-interpolated: it enters as an environment value and the PHP helper uses argv-style `proc_open` without a shell. It still lacks a strict accepted revision syntax/normalization at the workflow boundary; add full commit/object validation before use and test leading-dash, multiline, whitespace, unknown ref and option-like values. The dispatcher must be authorized to run a workflow; this is not a public unauthenticated endpoint, and the job has no secrets/write token. Current default falls back to a merge base.

The MySQL server job has the strict process/concurrency suite (currently nine named feature tests in the job step). It remains part of the Server MySQL stable check. Do not remove/rename it without a coordinated required-check migration.

## Other executable entrypoints

| Surface | Source/evidence | Release influence / trust boundary |
|---|---|---|
| Composer install and scripts | Root `composer.json`/`composer.lock`; `wordpress/composer.json`/lock. CI and staging run `composer install`; root post-autoload invokes Laravel package discovery; plugin allowlist is explicit. | Dependency install and scripts execute repository/dependency code. Root/Connector locks are committed and installs are lock-respecting. Staging runs this as `deploy` with observed unrestricted NOPASSWD sudo (F02). |
| npm build | `package.json`, `package-lock.json`, `.npmrc`; CI and staging use `npm ci`, then `npm run build`. `.npmrc` sets `ignore-scripts=true`; explicit project build still executes Vite. | Registry packages are integrity-locked; nine package entries declare install scripts but npm config suppresses their lifecycle execution. First-party `build` script is intended. Staging target code runs as deploy under the narrow root-helper boundary; it does not inherit unrestricted root access. |
| Python test service | CI installs `moto[server]==5.2.4` exactly through pipx. | Exact Moto version; transitive package resolution still relies on PyPI registry trust. No secret or deploy authority. |
| OS/service setup | CI apt installs `openssh-server`; GitHub Actions services use `mysql:8.4` and `mariadb:10.11`. | Ubuntu signed repository and moving service image tags on ephemeral test runner. |
| Connector packaging | `app/Console/Commands/PackageConnector.php`, `app/Connector/ConnectorPackageArtifact.php`, `release.json`; CI builds/checks private ZIP; deploy rebuilds/verifies exact deployed source. | ZIP includes per-file manifest; sidecar records source revision/source SHA-256/package SHA-256; same-version changed source fails. Package is not an Actions artifact. |
| Staging deployment | `scripts/deploy-staging.sh`, documented manual invocation from `/var/www/wpcaregrid` by `deploy`, exact full SHA argument. | Fixed branch and expected SSH origin; clean baseline, SHA, ancestry, deployment and scheduler locks, DB backup, service gates, pinned code checkout, rollback. Target Composer/npm/Artisan code executes as deploy. Target commit cannot rewrite already-running shell script, but it supplies build/migration/package code and the installed scheduler wrapper after checks. |
| Scheduler wrapper | `scripts/run-scheduler.sh`, installed to `/usr/local/bin` by root via deploy path; cron for `www-data` uses that wrapper. | Wrapper/source agreement and privileged installation need regression tests; no GitHub Actions path. |
| Package/release helpers | `scripts/check-connector-release.php`, `app/Console/Commands/PackageConnector.php`. | PHP helper passes base revision as a Git argv element using `proc_open` argv/no shell and requires exact source/version consistency. Optional `workflow_dispatch` base input is constrained to empty or a full 40-character hexadecimal SHA by the repository policy test. |
| Git hooks / Docker / custom actions | No tracked `.github/actions`, Dockerfile/Compose, CODEOWNERS, or hook framework found by inventory. Dependabot is configured for reviewed PR updates. | No additional tracked execution mechanism identified. Host-side Git config/hooks remain part of external verification. |

No PR artifact is downloaded by another run, used as deployment input, or served to customers. A change that introduces such a channel requires a new threat-model review.


## Implemented controls and current evidence

Remote Actions are full-SHA pinned with version comments. CI adds repository-owned action/workflow policy tests, locked-source checks, deterministic archive-verifier and secret-path tests, and Gitleaks archive verification before execution. The workflow remains read-only (`contents: read`), GitHub-hosted ephemeral, with no secrets or deployment. PR #118 exact-head run `39050627430` and exact merged-primary run `39051589381` passed the full seven-job matrix. Owner-reported staging validation for that exact merged SHA is summarized in the closure report; this task did not access the staging host.
