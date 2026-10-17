# Staging deployment privilege boundary

The staging deployment controller is run by `deploy`. Its target-controlled Composer, npm, and Artisan steps also run as `deploy`; they must never inherit unrestricted root access. `scripts/wpcaregrid-deploy-priv.py` is the reviewed source template for the root-owned helper at `/usr/local/sbin/wpcaregrid-deploy-priv`.

## Installation boundary

The normal deployment script does not install or update this helper. A staging administrator installs it explicitly from an exact reviewed, merged primary SHA. The administrator verifies the source commit and SHA-256 before installation, then verifies the installed file is `root:root`, mode `0755`, and not writable by `deploy` or `www-data`. Helper updates require the same separate reviewed administrative process.

During the one-time transition, keep an independent administrative recovery path available. Install and test the helper while the existing rule still permits the current reviewed administrator session. Add the narrow sudoers rule, run `visudo -c`, exercise each needed helper operation, and only then remove the old unrestricted `deploy` grant. Do not retain the unrestricted rule as a fallback. Re-run `sudo -n -l` and the denied-command probes before any target-code deployment. If a step fails, stop and use the independent root/console recovery path; do not broaden sudo again as a deployment workaround.

The intended sudoers grant is limited to the helper executable and is not itself the operation allowlist:

```sudoers
deploy ALL=(root) NOPASSWD: /usr/local/sbin/wpcaregrid-deploy-priv *
```

The root-owned helper strictly validates every subcommand and argument. No repository deployment step invokes `install`, `mv`, `rm`, `chmod`, `chown`, `setfacl`, `crontab`, `supervisorctl`, `php`, a shell, `env`, or another general executable through sudo.

## Fixed helper operations

The current helper exposes only:

- status/start/stop for `wpcaregrid-default` and `wpcaregrid-backups`;
- initialize/check the fixed scheduler lock and deployment gate paths;
- install the single fixed scheduler wrapper, replace only the exact reviewed `www-data` cron line, and return a bounded cron-observation result;
- run the fixed scheduler lock probe as `www-data`;
- create/remove the fixed deployment and scheduler-pause markers;
- validate the fixed Nginx gate and cron service;
- check read/write/execute access for `www-data` or non-readability for `nobody` on canonical paths under the application directory;
- apply ACLs only to `bootstrap/cache` and `storage/framework/views`, or secure a PHP cache file directly inside those directories;
- verify vendor PHP readability as `www-data`;
- perform a fixed MySQL ping and emit a pre-deploy dump of the hard-coded staging database `wpcaregrid`.

The helper has no generic command, path, service, user, database, shell, environment, cron-content, or file-write interface. It sets a minimal environment, invokes absolute executable paths without a shell, suppresses raw external error text, and refuses unknown operations. All marker and cache paths are fixed; application paths are canonicalized and symlink-checked. The scheduler wrapper executes as `www-data`, never as root.

## Required cutover evidence

Before any new target-controlled code executes on staging, retain evidence of:

1. the merged source SHA and helper source SHA-256;
2. helper owner/mode and sudoers syntax validation;
3. `sudo -n -l` showing no `ALL`, `NOPASSWD: ALL`, generic shell/environment/tool command, or other broad grant;
4. successful required helper operations;
5. rejected `sudo id`, shell, `env`, PHP, generic install/chmod/setfacl, unrelated Supervisor, and root crontab probes;
6. successful harmless target-code fixture attempting only `sudo -n id` and observing denial;
7. independent recovery access remains available.

This repository document is not proof of live host state. M146-F02 was closed by the recorded staging sudo/helper readback and negative probes. The subsequent reviewed helper revision requires a separate root/admin installation and revalidation before target-controlled deployment; that staging validation is tracked by M146-F10 and does not reopen F02 unless new evidence contradicts the prior privilege-boundary proof.
