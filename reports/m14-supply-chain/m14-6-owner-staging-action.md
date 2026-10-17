# M14.6 staging Owner action packet

**Purpose:** safely diagnose the current staging 503, install the reviewed root helper revision through the authorized platform-console process, and return sanitized evidence. This is not a deployment authorization. Do not run a release or clear a gate based only on this packet.

## Current evidence

- Rechecked 2026-10-17: `https://staging.wpcaregrid.com/`, `/owner/login`, and `/robots.txt` return HTTP 503; `/up` returns HTTP 200. The root response includes `Retry-After: 60` and `Cache-Control: no-cache, private`.
- This split is consistent with Laravel maintenance mode while the health route remains available, but it does **not** establish the cause. A deployment gate, interrupted deployment, upstream issue, or other host state still needs readback.
- Do not run `php artisan up`, remove either deployment marker, resume the scheduler, restart workers, or start deployment until the diagnostic state has been reviewed. The scheduler lock file may exist while unlocked; its existence alone does not mean a deployment is active or stale.
- The last known executable staging SHA is `3debba99c677b68465cf439733f440442fbd0f22`; current host HEAD is unknown.

## Reviewed helper artifact

- Source commit: `7d78bd8a44f71178fabc576767bda07a5f75d95d` (merged PR #115; the current primary contains this same executable source).
- Source file: `scripts/wpcaregrid-deploy-priv.py`.
- Pinned HTTPS source URL: `https://raw.githubusercontent.com/zsoltacsai/wpcaregrid/7d78bd8a44f71178fabc576767bda07a5f75d/scripts/wpcaregrid-deploy-priv.py`.
- Expected SHA-256: `7a764d921195b1ce051dd6099c2eace3c24ce587318c3bda9992e1a835bfa910`.
- Destination: `/usr/local/sbin/wpcaregrid-deploy-priv`, owner `root:root`, mode `0755`.
- Installation is a separate root/admin action. Normal deployment does not install or update this helper. Do not edit its source on staging.

## Stage 1 — read-only diagnosis

Run this first from the authorized platform console as root. It does not print `.env`, application logs, or credentials and does not change host state:

```bash
set -eu
APP=/var/www/wpcaregrid
HELPER=/usr/local/sbin/wpcaregrid-deploy-priv
printf 'UTC: '; date -u +'%Y-%m-%dT%H:%M:%SZ'
printf 'HEAD: '; git -C "$APP" rev-parse --verify 'HEAD^{commit}'
printf 'ORIGIN: '; git -C "$APP" remote get-url origin
if [ -z "$(git -C "$APP" status --porcelain --untracked-files=all)" ]; then echo 'TREE: CLEAN'; else echo 'TREE: DIRTY (do not deploy; return only this classification)'; fi
for path in /var/lib/wpcaregrid/deploy-maintenance /var/lib/wpcaregrid/scheduler-paused "$APP/storage/framework/maintenance.php"; do
  if [ -L "$path" ]; then printf 'STATE: symlink %s\n' "$path"; elif [ -e "$path" ]; then printf 'STATE: present %s\n' "$path"; else printf 'STATE: absent %s\n' "$path"; fi
done
if [ -f /home/deploy/private-backups/.deploy.lock ] && [ ! -L /home/deploy/private-backups/.deploy.lock ]; then
  if flock -n /home/deploy/private-backups/.deploy.lock /usr/bin/true; then echo 'DEPLOY_LOCK: free'; else echo 'DEPLOY_LOCK: held or unreadable'; fi
else
  echo 'DEPLOY_LOCK: lock file absent or unsafe; do not create/replace it during diagnosis'
fi
for unit in nginx php8.4-fpm mysql cron supervisor; do printf 'SERVICE %s: ' "$unit"; systemctl is-active "$unit" 2>/dev/null || true; done
printf 'HELPER: '
if [ -f "$HELPER" ] && [ ! -L "$HELPER" ]; then stat -c '%U:%G %a %n' "$HELPER"; sha256sum "$HELPER" | awk '{print $1}'; else echo 'absent or not a regular file'; fi
printf 'DISK: '; df -h / /var/www/wpcaregrid /var/lib/wpcaregrid | tail -n +2
```

Also capture public HTTP status without response bodies, from an ordinary trusted shell:

```bash
for path in / /owner/login /up /robots.txt; do
  printf '%s ' "$path"
  curl --silent --show-error --output /dev/null --write-out '%{http_code}\n' --connect-timeout 5 --max-time 15 "https://staging.wpcaregrid.com${path}"
done
```

Do not paste logs, environment values, database dumps, cookies, or `.env`. If a service is inactive, the deployment lock is held, the checkout is dirty, origin differs from `git@github.com-wpcaregrid:zsoltacsai/wpcaregrid.git`, or either gate/pause marker exists, stop after diagnosis and return the classifications. Keep the gate and pause marker in place. Review `docs/DEPLOYMENT.md`'s gated recovery sequence before any recovery operation.

## Stage 2 — explicit helper update

Proceed only after Stage 1 confirms no deployment process holds the deployment lock and an administrator has determined that replacing the helper is safe. This replaces only the root helper; it does not touch the application checkout, database, gates, workers, scheduler, or sudoers policy.

Run as root in the authorized platform console. The temporary download and install file are created in `/usr/local/sbin` so the final rename is on the same filesystem. No redirect is followed. The old helper is retained in a root-only backup before replacement.

```bash
set -Eeuo pipefail
umask 077
URL='https://raw.githubusercontent.com/zsoltacsai/wpcaregrid/7d78bd8a44f71178fabc576767bda07a5f75d/scripts/wpcaregrid-deploy-priv.py'
EXPECTED='7a764d921195b1ce051dd6099c2eace3c24ce587318c3bda9992e1a835bfa910'
DEST='/usr/local/sbin/wpcaregrid-deploy-priv'
DOWNLOAD="$(mktemp /usr/local/sbin/.wpcaregrid-deploy-priv.download.XXXXXX)"
NEW="$(mktemp /usr/local/sbin/.wpcaregrid-deploy-priv.new.XXXXXX)"
trap 'rm -f -- "$DOWNLOAD" "$NEW"' EXIT
if [ -L "$DEST" ] || { [ -e "$DEST" ] && [ ! -f "$DEST" ]; }; then echo 'Refusing unsafe existing helper path' >&2; exit 1; fi
if [ -f "$DEST" ]; then
  BACKUP="/root/wpcaregrid-deploy-priv.pre-m14-6.$(date -u +%Y%m%dT%H%M%SZ)"
  install -o root -g root -m 0700 -- "$DEST" "$BACKUP"
  echo "Previous helper retained at $BACKUP (root-only)."
fi
curl --fail --silent --show-error --proto '=https' --tlsv1.2 --connect-timeout 5 --max-time 30 --output "$DOWNLOAD" "$URL"
ACTUAL="$(sha256sum "$DOWNLOAD" | awk '{print $1}')"
[ "$ACTUAL" = "$EXPECTED" ] || { echo 'Source checksum mismatch; helper not changed' >&2; exit 1; }
install -o root -g root -m 0755 -- "$DOWNLOAD" "$NEW"
[ "$(sha256sum "$NEW" | awk '{print $1}')" = "$EXPECTED" ]
mv -fT -- "$NEW" "$DEST"
[ "$(sha256sum "$DEST" | awk '{print $1}')" = "$EXPECTED" ]
[ "$(stat -c '%U:%G:%a' "$DEST")" = 'root:root:755' ]
echo 'Reviewed helper installed and verified.'
stat -c '%U:%G %a %n' "$DEST"
sha256sum "$DEST" | awk '{print $1}'
visudo -c
```

If download, checksum, path, ownership, mode, or `visudo` validation fails, stop. Do not edit sudoers as part of this helper update and do not restore any broad grant. The expected existing deploy rule is exactly the helper-only rule below; verify it from a deploy-user session after installation:

```text
(root) NOPASSWD: /usr/local/sbin/wpcaregrid-deploy-priv *
```

No other broad command grant should appear. In particular, no `ALL`, `NOPASSWD: ALL`, shell, `env`, PHP, Composer, npm, Node, Git, install, move/copy/remove, chmod/chown/setfacl, crontab, or unrestricted Supervisor command.

## Stage 3 — fixed helper and privilege checks

Run these as the `deploy` user after helper replacement. The first group is read-only. Do not run marker-changing commands (`gate-on/off`, `scheduler-pause/resume`), scheduler installation/cron mutation, worker start/stop, or database dump for this validation packet.

```bash
set -eu
sudo -n -l
sudo -n /usr/local/sbin/wpcaregrid-deploy-priv nginx-check
sudo -n /usr/local/sbin/wpcaregrid-deploy-priv nginx-gate-check
sudo -n /usr/local/sbin/wpcaregrid-deploy-priv cron-service-check
sudo -n /usr/local/sbin/wpcaregrid-deploy-priv worker status-all
sudo -n /usr/local/sbin/wpcaregrid-deploy-priv scheduler-lock-check
sudo -n /usr/local/sbin/wpcaregrid-deploy-priv vendor-readable
sudo -n /usr/local/sbin/wpcaregrid-deploy-priv preflight
bash /var/www/wpcaregrid/scripts/tests/fixtures/deploy-sudo-denial-fixture.sh
```

The harmless fixture does only `sudo -n /usr/bin/id` and succeeds only when that arbitrary root command is denied. Then run the remaining no-effect denial probes as `deploy`; each command must fail at sudo authorization, before the target program runs:

```bash
deny() {
  if "$@" >/dev/null 2>&1; then echo "UNEXPECTEDLY ALLOWED: $*" >&2; return 1; fi
  echo "denied: $*"
}
deny sudo -n /usr/bin/id
deny sudo -n /bin/sh -c true
deny sudo -n /usr/bin/env id
deny sudo -n /usr/bin/php -r 'exit(0);'
deny sudo -n /usr/bin/install --version
deny sudo -n /usr/bin/supervisorctl status unrelated-service
deny sudo -n /usr/bin/crontab -u root -l
deny sudo -n /usr/bin/env PATH=/tmp /usr/local/sbin/wpcaregrid-deploy-priv worker status-all
deny sudo -n /usr/local/sbin/wpcaregrid-deploy-priv worker status unrelated-service
deny sudo -n /usr/local/sbin/wpcaregrid-deploy-priv file-check www-data read ../.env
```

The last two commands must be refused by the helper. These tests are not permission to run arbitrary target code or a deployment.

## Return only this evidence

Return a short sanitized report with:

```text
UTC timestamp:
Public HTTP statuses (/ /owner/login /up /robots.txt):
Host HEAD:
Origin exact-match (yes/no):
Tree clean (yes/no):
Deployment process/lock (inactive/free, active/held, or unknown):
Deployment gate marker (present/absent/symlink):
Scheduler pause marker (present/absent/symlink):
Laravel maintenance file (present/absent/symlink):
Service states (nginx, php8.4-fpm, mysql, cron, supervisor):
Disk-space classification:
Installed helper owner/mode/hash:
visudo validation (pass/fail):
sudo -n -l summary (redact usernames if desired; do not include unrelated policy text):
Fixed read-only helper checks (pass/fail per command):
Arbitrary-sudo/helper-abuse probes denied (out of 10):
Target-code sudo-denial fixture (pass/fail):
Secrets/log bodies included: no
```

Do not return `.env`, passwords, keys, cookies, SSH material, database contents, raw logs, or private backup/receipt contents. Once these facts are reviewed, the 503 recovery cause and safe next action can be decided. Do not deploy until the latest helper hash and privilege boundary are proven and staging's failure state has a reviewed recovery plan.
