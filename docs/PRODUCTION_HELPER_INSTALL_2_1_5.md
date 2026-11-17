# Production helper 2.1.5 — PHP 8.5 successor (binds confinement package 1.0.6)

Status: **source only. Not installed, not enabled, not release-ready.** Nothing in this change touches a host, sudoers, a service, a database or production. Every host step below needs its own exact-scope Owner GO after merge and independent QA/AppSec review.

## Owner decision and purpose

WP CareGrid **production runs PHP 8.5** in the dedicated unit `wpcaregrid-production-php8.5-fpm.service` (FPM configuration `/etc/php/8.5/fpm-wpcaregrid-production/`, socket `/run/php/wpcaregrid-production-php8.5-fpm.sock`, CLI `/usr/bin/php8.5`). Production helper **2.1.4 is immutable** and stays bound to confinement package 1.0.5 (PHP 8.4); it cannot admit the 1.0.6 layout. Helper 2.1.5 is the narrowest versioned successor: it binds the merged package `deploy/php-fpm-confinement/1.0.6/` (`docs/PHP_FPM_CONFINEMENT_GO20_PHP85.md`) and changes **only what PHP 8.5 requires**. Legacy staging stays on PHP 8.4, staging-next on PHP 8.5; no new Linux user, group or FPM UID (`www-data` 33/33 under the confinement model).

Frozen and untouched by this change: every 2.1.0–2.1.4 helper file and manifest, the staging helper, `scripts/deployment_transaction_v2.py`, `scripts/release_review_policy_v3_1.py` and confinement packages 1.0.1–1.0.6. 2.1.5 imports/pins the two shared modules by hash exactly as 2.1.4 does; it duplicates no logic.

## What changes against 2.1.4 (and only this)

| Area | 2.1.4 | 2.1.5 |
| --- | --- | --- |
| Confinement pin | package 1.0.5 (tree `e8e59b769ed6c6acd85a3bf259364bace5ec374b`) | package **1.0.6**: `package_version` `1.0.6`, `SHA256SUMS` SHA-256 `bf6e734ef0489c34006862c706a6d0fc59d62b8c18b00bbe04a276512c0a5e18`, `package_tree` `6d239b64d5637971bf28ec7a2de6d7a5686804df` (`git rev-parse HEAD:deploy/php-fpm-confinement/1.0.6`) |
| Production unit / pool / socket | `wpcaregrid-production-php8.4-fpm.service`, `/etc/php/8.4/fpm-wpcaregrid-production/`, `…php8.4-fpm.sock` | `wpcaregrid-production-php8.5-fpm.service`, `/etc/php/8.5/fpm-wpcaregrid-production/{php-fpm.conf,pool.d/wpcaregrid-production.conf}`, `/run/php/wpcaregrid-production-php8.5-fpm.sock` |
| Policy `php_version` | `8.4` | `8.5` (`8.4` now refuses `production_php_version_invalid`) |
| Production FPM executable class | `/usr/sbin/php-fpm8.4`, `/usr/bin/kill` | `/usr/sbin/php-fpm8.5`, `/usr/bin/kill` |
| Application PHP run by the helper | three exact `/usr/bin/php8.4` command lines | the same three exact command lines under **`/usr/bin/php8.5`** |
| Paths and versions | `…/production/2.1.4/`, `production-v2.1.4.json`, inbox `2.1.4` | `/usr/local/libexec/wpcaregrid-deploy/production/2.1.5/`, `/etc/wpcaregrid-deploy/profiles/production-v2.1.5.json`, inbox `…/production-helper-inbox/2.1.5` |
| Installer | refuses legacy 2.0.0/2.1.0–2.1.2 | identical legacy set; installs **alongside** an installed 2.1.4 (see below) |

The three exact argv (each through `runuser --user www-data --group www-data -- /usr/bin/aa-exec -p <profile> --`):

| Class | Profile | Exact argv |
| --- | --- | --- |
| `production-deploy-cli` | `wpcaregrid-php-cli-production-deploy` | `/usr/bin/php8.5 -r <runtime identity probe bound to the policy database socket>` |
| `production-build-cli` | `wpcaregrid-php-cli-production-build` | `/usr/bin/php8.5 artisan package:discover --ansi --no-interaction` and `/usr/bin/php8.5 artisan config:cache --no-interaction` |

Any other argv, including the Phase B migration runner, refuses as `deploy_cli_command_not_allowed`. The artifact manifest keeps `php_minimum` `8.4` (the application's Composer floor); the helper only admits it when the runtime PHP is at least that version.

### Class identity with a shared executable

Production and staging-next now both run `/usr/sbin/php-fpm8.5`. The contract therefore never infers an environment from an executable, `argv0`, `comm` or PHP version. Every same-UID peer and every service member is mapped by the exact **(AppArmor label, systemd unit/cgroup)** pair: a production-labelled process in `php8.5-fpm.service`, a staging-next-labelled process in the production unit, `unconfined`/`complain` labels and a PHP 8.4 executable in a production class are all refused (tests pin these). The executable is only a secondary per-class allowlist.

### Binding

`CONFINEMENT_PIN` in `scripts/deployment_environment_contract_2_1_5.py` is a `ConfinementPin(package_version, sha256sums_sha256, package_tree, file_sha256)` for package 1.0.6 with the schema-3 binding: **17** installed files (`CONFINEMENT_FILES`, taken from `binding.json` `installed_files` with the per-file hashes from `SHA256SUMS`), **seven** service roles (`production`, `staging-next`, `staging-legacy`, `nginx`, `jobs-supervisor`, `jobs-scheduler`, `jobs-legacy-scheduler`) and **two** transient roles (`production-deploy-cli`, `production-build-cli`). Canonical binding digest (`sha256` of `confinement_binding()` with sorted keys and compact separators): `c349684665ac8a563d9b948dd4a03bc63ecd3e06e396fcd34daf5894ca33df4d`. The manifest, controller and installer carry that digest; the installer writes it into its receipt; a pin that does not match refuses with `confinement_package_pin_invalid`.

## Security properties preserved unchanged

Root-console-only invocation (no sudoers file, `SUDO_*`/`PKEXEC_UID`/`DOAS_USER` refused, isolated interpreter); `deploy` is untrusted and transfer-only (untrusted-input descriptor handling, hash pins, root-owned inbox copies) and keeps its existing staging grant unchanged; no-overwrite atomic publication with exact-inode rollback; verified receipts; Phase B disabled and its policy/credentials absent; production policy installed disabled with every environment value and `php_fpm_confinement` null; production database socket `/run/mysqld/wpcaregrid-production.sock` unix-only with no TCP fallback; production jobs fence checked first before every operation; same-UID peer inventory, service-member inventory and no www-data cron/at; release admission (exact latest protected primary, CI, V3.1 records and artifacts, fresh QA/AppSec evidence). `SECURITY_REMEDIATION_ENABLED=false`, `ADMIN_ACCESS_OPERATOR_FULL_ADMIN=false`, no live restore. The behaviour of these paths is the 2.1.4 behaviour; `scripts/tests/production-helper-2-1-5-full-test.py` re-runs the whole inherited suite against the 2.1.5 bytes with PHP 8.5 identities.

## Pinned artifacts

<!-- PINS:BEGIN -->
| Artifact | SHA-256 |
| --- | --- |
| `scripts/wpcaregrid-deploy-production-priv-2_1_5.py` | `d45e516071aa17a1c9c8b77bc4a6b6a332a3c6bd5d5ac30e15bc645f8ca07275` |
| `scripts/deployment_backend_v2_1_5.py` | `c1eef0ca22d5937c395169bd9e08bcc6fb69a20fcb2bba8c1d27cd7011ad3b63` |
| `scripts/deployment_transaction_v2.py` | `ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00` |
| `scripts/deployment_environment_contract_2_1_5.py` | `afdbccf5bfc7d734b383044a93363c1c4ecf50eb56eae4b3385bc765d7c3b7aa` |
| `scripts/release_review_policy_v3_1.py` | `5559217003066b77fb2533c8a113fa6d19c497924add59e1db3e023641143d6f` |
| helper artifact digest | `66ef7cda0639b3b65cc242037f9be0746dcc2f8d58c007debb371ac129f1cf43` |
| `scripts/production_deploy_controller_2_1_5.py` | `bfa09face75ee85553f60f89b23e421abe16cf66836ef42781455f9ad08f0503` |
| `scripts/install_production_helper_2_1_5.py` | `8de386533746194a4b194cb017b62db557a8fdf937b2a230886d2f79b6d20b57` |
<!-- PINS:END -->

The six installer `SOURCE_HASHES` are the entrypoint, backend, transaction module, environment contract, review-policy module and controller rows above (the controller is installed as `production_deploy_controller.py`). Manifest: `deploy/release-manifests/production-deployment-helper-2.1.5.json`. Tests: `scripts/tests/production-helper-2-1-5-full-test.py`, `scripts/tests/production-helper-2-1-5-coexistence-test.py`.

## Installed layout (when separately approved)

All root-owned; no sudoers file.

* `/usr/local/libexec/wpcaregrid-deploy/production/2.1.5/` (`0755`): `wpcaregrid-deploy-production-priv` (`0755`), `deployment_backend_v2_1_5.py`, `deployment_transaction_v2.py`, `deployment_environment_contract_2_1_5.py`, `release_review_policy_v3_1.py`, `production_deploy_controller.py` (all `0644`)
* `/etc/wpcaregrid-deploy/profiles/production-v2.1.5.json` (`0644`): disabled schema-2 policy, every environment value and `php_fpm_confinement` null, `privileged_invocation` `root-console-direct`

### Installing alongside the immutable 2.1.4 (conservative decision)

Helper 2.1.4 is already installed on the host and is never modified. The 2.1.5 installer:

* does **not** treat the 2.1.4 directory, its policy or any 2.1.3/2.1.4 path as a conflict, and never reads, repairs, links, renames or overwrites any of them; its own install state is derived only from the 2.1.5 targets (the 2.1.5 helper directory, the 2.1.5 disabled policy and the 2.1.5 private inbox);
* keeps refusing `legacy_production_helper_present` for 2.0.0 and 2.1.0–2.1.2 (directory, policy or sudoers file), exactly as 2.1.4, and still refuses while the Phase B policy or production database credentials exist;
* creates its own **disabled** profile `production-v2.1.5.json`; it never enables a profile and never copies or edits the 2.1.4 policy;
* refuses any pre-existing or partial 2.1.5 state as `partial_installation_detected;RECOVERY=<code>` and never repairs it; a complete exact 2.1.5 installation is verified read-only (`INSTALL=NO_OP`, also with `--verify`);
* on any failure removes only the inodes and empty directories it created (`ROLLBACK=PASS`), otherwise `ROLLBACK=HOLD`.

Both helpers being installed is not both being usable: while 2.1.4 binds package 1.0.5, the first host state that satisfies the 1.0.6 binding refuses every 2.1.4 operation at its confinement pin, and a host still on 1.0.5 refuses every 2.1.5 operation. Only one helper can pass the gate for a given host state; neither is selected by this change. Retiring 2.1.4 is a separate Owner decision.

## Command sequences (each needs its own Owner GO)

### Transfer (as `deploy`, untrusted)

Copy the six source files named in the installer `SOURCE_HASHES` from the reviewed merge SHA into `/home/deploy/.private/wpcg-production-helper-2.1.5` (`deploy:deploy 0700`, files `0600`, nothing else in the directory). Copy `scripts/install_production_helper_2_1_5.py` separately (for example to `/home/deploy/.private/wpcg-production-helper-installer-2.1.5/`).

### Install (Owner root console, not sudo)

```sh
(
  set -eu
  src=/home/deploy/.private/wpcg-production-helper-installer-2.1.5/install_production_helper_2_1_5.py
  expected=<root_installer_sha256 from the table above>
  d=$(/usr/bin/mktemp -d /root/wpcg-production-installer.XXXXXX)
  trap '/usr/bin/rm -f -- "$d/install.py"; /usr/bin/rmdir -- "$d"' EXIT
  test "$(/usr/bin/stat -c '%U:%G:%a' "$d")" = root:root:700
  /usr/bin/install -o root -g root -m 700 -- "$src" "$d/install.py"
  test "$(/usr/bin/sha256sum "$d/install.py" | /usr/bin/cut -d' ' -f1)" = "$expected"
  /usr/bin/env -i /usr/bin/python3 -I "$d/install.py"
)
```

Expected: `INSTALL=PASS`, `PRODUCTION_PROFILE=DISABLED`, `PRODUCTION_SUDOERS=ABSENT`, `DEPLOY_STAGING_SUDO=UNCHANGED` and a JSON receipt. Re-check later with the same command plus `--verify`. The installer never runs a helper operation. The four existing parent directories it requires are those already provisioned for 2.1.4 (`docs/PRODUCTION_HELPER_INSTALL_2_1_4_PARENT_DIRS.md`); no new provisioner is needed.

### Release (after an approved schema-2 production policy and recovery evidence exist)

Same flow as 2.1.4 with the 2.1.5 path: root-console `preflight`, `deploy`-side `package <app-SHA> <base-SHA>`, root-console `release <app-SHA> <artifact-SHA-256>`, all through `/usr/local/libexec/wpcaregrid-deploy/production/2.1.5/production_deploy_controller.py`. The root side never runs git, npm, a shell or a deploy-owned file.

## Gates that remain (none satisfied by this change)

1. Independent exact-head QA and AppSec V3 records for this pull request; merge and exact merge-SHA CI by the Owner's process.
2. Owner GO for installing 2.1.5 alongside 2.1.4 on a named host, and a separate Owner decision on 2.1.4's later retirement.
3. Confinement package 1.0.6 staged cutover and live acceptance on that host (`docs/PHP_FPM_CONFINEMENT_GO20_PHP85.md`); until then production must not run on the 1.0.6 layout.
4. A separately reviewed schema-2 production policy (echoing the schema-3 binding), Phase B policy, recovery evidence and database isolation.
5. A real-host rehearsal of `verify-install` and `preflight` before any `authorize`.
6. `.github/workflows/weekly-dependency-release.yml` still names the 2.1.0 controller; re-pointing it is a separate Owner-approved change.

## Known limitations

* Same GitHub API quota and ledger cap as 2.1.0–2.1.4. The observation proves recorded state, not kernel enforcement.
* Host assumptions of package 1.0.6 are unproven by source tests: `/usr/sbin/php-fpm8.5`, `/usr/bin/php8.5`, `/etc/php/8.5/{fpm,cli,mods-available}`, and that FPM needs only the shared `php.ini` and `conf.d/` of the packaged directory.
* Production and staging-next share `/etc/php/8.5/fpm/php.ini` and `conf.d/` (accepted residual of package 1.0.6).
* The 2.1.4 operator parent-directory provisioner is reused as-is; a separate 2.1.5 provisioner was deliberately not created because the installer's parent directories are version independent.
