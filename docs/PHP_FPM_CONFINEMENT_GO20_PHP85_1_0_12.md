# Production-only PHP-FPM confinement 1.0.12 (Ubuntu 26.04 target)

This immutable package is the production-only successor to 1.0.7. It installs exactly nine files: the shared AppArmor abstraction; production FPM and production build/deploy CLI profiles; the production FPM configuration and unit; and the Nginx profile/drop-in. It does not install a staging-next FPM profile, staging CLI profile, supervisor drop-in, or staging scheduler drop-in. Package 1.0.7 and helper 2.1.6 remain byte-for-byte unchanged.

## Production security domain and Invariant I

The security domain consists of production FPM and Nginx. Both must have their exact AppArmor profile attached to their exact systemd unit and loaded in enforce mode. The root-collected acceptance inventory includes every process with any real/effective/saved/filesystem UID 33, GID 33, or supplementary group 33, plus members of the production FPM and Nginx cgroups. The generic distribution `php8.5-fpm.service` cgroup is scanned as a forbidden sentinel, not a required role. Any process in that unit, any unknown/unconfined peer, unreadable process data, incomplete/churning inventory, or missing expected service peer fails closed.

No production group-readable secret or public route may exist before the full peer set passes live acceptance. Acceptance uses harmless, removable canaries and checks production FPM, Nginx, the production FastCGI socket, and production-only web/TLS paths. Tool-created `fixture-*` canaries are mandatory: missing or inaccessible canaries fail acceptance. Host-state probes for absent legacy/staging paths, PHP 8.4/8.5, database or D-Bus sockets, host-managed TLS, and root-private helper paths may be optional; matching baseline and confined absence/inaccessibility is UNPROVEN, never PASS. The verifier reports profile-qualified UNPROVEN IDs, matrix SHA-256, boot ID, and an evidence SHA-256 covering the phase/profile and exact baseline, confined, inventory, and audit inputs. It exits HOLD unless the Owner reruns it with `--ack-unproven=<matrix-sha256>:<boot-id>:<phase>:<profile>:<evidence-sha256>:<exact sorted profile:id list>`. This is an explicit procedural acknowledgment bound to that evidence set, not a digital signature; retain the output with the acceptance evidence before the secret gate. A missing or mismatched acknowledgment keeps the gate on HOLD. PHP 8.4 socket denials remain defense in depth. Production scheduled jobs remain fenced; no scheduler, supervisor, staging job, or demo vhost is an installation or acceptance prerequisite.

Before acceptance, the stock `php8.5-fpm.service` must be absent or stopped/masked. No other unknown process may hold UID 33, GID 33, or supplementary group 33. Such a peer blocks acceptance; do not add it as an exception.

## Nginx boundary

Nginx is a required enforce-confined peer. Its profile permits the production vhost configuration, the production release `public/` tree, the exact production FPM socket, and TLS material under `wpcaregrid.com` only. It denies generic and PHP 8.4 FPM sockets, staging/demo web roots, unrelated TLS domains, application code, configuration, `.env`, storage, backups, and production state. No broad `ssl_certs`, `/etc/nginx/**`, `/etc/ssl/private/**`, or `/var/www/*/public/**` allowance is used. The deployment must provide a standalone production vhost with no snippet includes. The profile permits enumeration of `/etc/nginx/snippets/` but grants no snippet file access; it does not assume demo vhosts.

## Independent gates

1. Install the immutable package and required production system components while the route is closed and no production secret exists. Do not yet install the helper that creates root-private control directories.
2. Load profiles in enforce mode, bind production FPM and Nginx to their units, create only fixture canaries, and pass the complete live peer acceptance. Remove fixtures and verify none remain.
3. Install and verify the helper after fixture acceptance; its root-private control directories are not prerequisites for baseline acceptance.
4. Only after acceptance may the Owner-provided production configuration and secret be placed.
5. With route closed, separately pass database identity/connectivity and application checks. Production jobs remain fenced.
6. Open the production route only after the preceding gates pass and a separate Owner GO. Then perform external HTTPS verification.

Secret placement, route opening, and database/application validation are separate gates. CI and offline tests do not constitute live acceptance or production authorization.

## Gate 1 — direct Owner root-console installation from verified source

Owner decision: Confinement 1.0.12 Gate 1 is a direct Owner root-console installation
from a named, verified source. There is no separate Elliot upload-manifest and no
package transfer-only staging path. There is and will be no staging on this host.
Helper 2.1.11 is DEFERRED until after fixture acceptance (Gate 3) and is not part
of Gate 1. Do not use an installed target or the helper transfer directory as a
source or staging destination.

### Source, commit binding, and package integrity

Three distinct levels of verification are required prior to installation:

1. **Owner-verified Git commit binding (pre-condition):**
   The package source is strictly bound to the protected primary commit:
   - Primary commit SHA: `e9a1bc352d54741ae72e2766f6e07e28e798900d`
   - Primary tree package path: `deploy/php-fpm-confinement/1.0.12/`
   The Owner must verify in root console that the host repository checkout is on
   exact commit `e9a1bc352d54741ae72e2766f6e07e28e798900d` prior to setting `PKG`.
   Because the repository checkout location is not specified by primary
   (`SOURCE_PATH_HOST=UNKNOWN_UNTIL_OWNER_SPECIFIES`), the commit verification
   command cannot be hardcoded and is an Owner-managed pre-condition.

2. **Package artifact and hash integrity (verified by the block below):**
   The read-only verification block below proves that the `$PKG` directory is a
   canonical, non-symlink directory whose `SHA256SUMS`, nine package files, and
   `binding.json` match their documented SHA-256 pins. This block proves package
   content integrity; it does not execute `git rev-parse`, verify git HEAD,
   verify route status, or confirm secret absence.

3. **Closed route and absence of production secrets (pre-install STOP conditions):**
   Route closure and the absence of production secrets are separate host
   operational pre-conditions that the Owner must confirm on the host before any
   installation.

Documentation blocker: The protected primary repository defines the tree path
`deploy/php-fpm-confinement/1.0.12/` and immutable artifact hashes, but does not
specify an absolute filesystem checkout path on the host. Because the host
checkout path cannot be established from primary, this is recorded as a
documentation blocker (`SOURCE_PATH_HOST=UNKNOWN_UNTIL_OWNER_SPECIFIES`). The
documentation does not invent a host checkout path.

The `package_owner_mode` in `binding.json` documents the **installed** metadata:
regular file, uid 0, gid 0, mode `0644`, nlink 1. The installed targets are the
nine `installed_files` entries in `binding.json`. Source files do not carry
installed metadata; only installed targets carry `root:root 0644`.

Once the Owner confirms that the host checkout is bound to commit
`e9a1bc352d54741ae72e2766f6e07e28e798900d`, sets `$PKG` to the corresponding
`deploy/php-fpm-confinement/1.0.12` directory, and confirms route closure and
secret absence, the following read-only block validates package artifact
integrity before any target is touched:

```bash
# Owner root console only.
set -euo pipefail
PKG="${PKG:?STOP: PKG path to verified primary deploy/php-fpm-confinement/1.0.12 must be set by Owner}"
test "$(id -u)" -eq 0 || { echo "STOP: root console required"; exit 1; }
test -d "$PKG" && test ! -L "$PKG" || { echo "STOP: package directory invalid or symlink"; exit 1; }
test "$(realpath -e -- "$PKG")" = "$PKG" \
  || { echo "STOP: package path is not canonical or contains a symlink"; exit 1; }
test -f "$PKG/SHA256SUMS" && test ! -L "$PKG/SHA256SUMS" \
  || { echo "STOP: SHA256SUMS missing or symlink"; exit 1; }
test "$(sha256sum "$PKG/SHA256SUMS" | awk '{print $1}')" \
  = "c6d9e20b1ad1c59cb1dab4cef9bc0c0b047dfdf682493fd2de3e2326ad3e1f87" \
  || { echo "STOP: SHA256SUMS pin mismatch"; exit 1; }
( cd "$PKG" && sha256sum -c --strict SHA256SUMS ) \
  || { echo "STOP: package content mismatch"; exit 1; }
while read -r _hash name; do
  test -f "$PKG/$name" && test ! -L "$PKG/$name" \
    || { echo "STOP: package source is not a regular non-symlink file: $name"; exit 1; }
done < "$PKG/SHA256SUMS"
test -f "$PKG/binding.json" && test ! -L "$PKG/binding.json" \
  || { echo "STOP: binding.json is not a regular non-symlink file"; exit 1; }
test "$(sha256sum "$PKG/binding.json" | awk '{print $1}')" \
  = "07202e80dfdde569146738b48354dd01cd96960f67fb32b70e86a20a725fc0c2" \
  || { echo "STOP: binding.json hash mismatch"; exit 1; }
```

### Pre-install STOP conditions

The Owner must confirm all of the following before any `install` command:

- the host repository checkout is verified on commit `e9a1bc352d54741ae72e2766f6e07e28e798900d`;
- `PKG` is the verified source directory bound to primary commit `e9a1bc352d54741ae72e2766f6e07e28e798900d`;
- the package artifact integrity check block above passed with exit code 0;
- the route is closed;
- no production secret exists or is placed;
- no helper transfer directory or installed target is used as a staging path;
- every installed target is absent and is not a symlink;
- every target parent directory exists, is a real directory, and is not a
  symlink; this procedure does not create missing parents or invent their mode;
- staging does not exist on this host;
- helper 2.1.11 is DEFERRED and not installed;
- no helper installation, profile load/enforce, secret, database/application,
  route, or production action is part of Gate 1.

```bash
# Owner root console only. Stop if any target or parent is not exactly as required.
set -euo pipefail
targets=(
  /etc/apparmor.d/abstractions/wpcaregrid-php-fpm-common
  /etc/apparmor.d/wpcaregrid-nginx
  /etc/apparmor.d/wpcaregrid-php-cli-production-build
  /etc/apparmor.d/wpcaregrid-php-cli-production-deploy
  /etc/apparmor.d/wpcaregrid-php-fpm-production
  /etc/php/8.5/fpm-wpcaregrid-production/php-fpm.conf
  /etc/php/8.5/fpm-wpcaregrid-production/pool.d/wpcaregrid-production.conf
  /etc/systemd/system/nginx.service.d/90-wpcaregrid-confinement.conf
  /etc/systemd/system/wpcaregrid-production-php8.5-fpm.service
)
parents=(
  /etc/apparmor.d/abstractions
  /etc/apparmor.d
  /etc/php/8.5/fpm-wpcaregrid-production
  /etc/php/8.5/fpm-wpcaregrid-production/pool.d
  /etc/systemd/system/nginx.service.d
  /etc/systemd/system
)
for target in "${targets[@]}"; do
  test ! -e "$target" && test ! -L "$target" \
    || { echo "STOP: target exists or is a symlink: $target"; exit 1; }
done
for parent in "${parents[@]}"; do
  test -d "$parent" && test ! -L "$parent" \
    && test "$(realpath -e -- "$parent")" = "$parent" \
    || { echo "STOP: missing/non-canonical/symlinked parent directory: $parent"; exit 1; }
done
```

### Root-console installation order

Every command in this block is an **Owner root-console** operation. Do not
delegate it to Elliot. The order is part of Gate 1; a failed command is a STOP,
not a retry or repair instruction.

```bash
# Owner root console only.
set -euo pipefail
install -o root -g root -m 0644 \
  "$PKG/apparmor/abstractions/wpcaregrid-php-fpm-common" \
  /etc/apparmor.d/abstractions/wpcaregrid-php-fpm-common
install -o root -g root -m 0644 \
  "$PKG/apparmor/wpcaregrid-nginx" \
  /etc/apparmor.d/wpcaregrid-nginx
install -o root -g root -m 0644 \
  "$PKG/apparmor/wpcaregrid-php-cli-production-build" \
  /etc/apparmor.d/wpcaregrid-php-cli-production-build
install -o root -g root -m 0644 \
  "$PKG/apparmor/wpcaregrid-php-cli-production-deploy" \
  /etc/apparmor.d/wpcaregrid-php-cli-production-deploy
install -o root -g root -m 0644 \
  "$PKG/apparmor/wpcaregrid-php-fpm-production" \
  /etc/apparmor.d/wpcaregrid-php-fpm-production
install -o root -g root -m 0644 \
  "$PKG/php-fpm/production/php-fpm.conf" \
  /etc/php/8.5/fpm-wpcaregrid-production/php-fpm.conf
install -o root -g root -m 0644 \
  "$PKG/php-fpm/production/pool.d/wpcaregrid-production.conf" \
  /etc/php/8.5/fpm-wpcaregrid-production/pool.d/wpcaregrid-production.conf
install -o root -g root -m 0644 \
  "$PKG/systemd/nginx.service.d/90-wpcaregrid-confinement.conf" \
  /etc/systemd/system/nginx.service.d/90-wpcaregrid-confinement.conf
install -o root -g root -m 0644 \
  "$PKG/systemd/wpcaregrid-production-php8.5-fpm.service" \
  /etc/systemd/system/wpcaregrid-production-php8.5-fpm.service
```

### Gate 1 post-install read-only checks

These checks are strictly read-only Owner root-console commands. They verify the
installed metadata (`stat -c '%U:%G %a %h'` = `root:root 644 1`) and sha256
hashes of all nine targets. They do not load a profile, reload systemd, start a
service, install the helper, place a secret, touch the database/application,
open the route, or activate production.

```bash
# Owner root console only, read-only.
set -euo pipefail
check() {
  path=$1
  want=$2
  test -f "$path" && test ! -L "$path" \
    || { echo "STOP: not a regular non-symlink file: $path"; exit 1; }
  test "$(sha256sum "$path" | awk '{print $1}')" = "$want" \
    || { echo "STOP: installed hash mismatch: $path"; exit 1; }
  test "$(stat -c '%U:%G %a %h' "$path")" = "root:root 644 1" \
    || { echo "STOP: installed metadata mismatch: $path"; exit 1; }
}
check /etc/apparmor.d/abstractions/wpcaregrid-php-fpm-common bb20c8ab0118e6a0b0cf4d1ee9df1fde58720434c601bfdbaac794da188423f6
check /etc/apparmor.d/wpcaregrid-nginx 4cb95fb117747ec93f6b3e3d00f1c64486169046f2e79aa24de1235c53da2c41
check /etc/apparmor.d/wpcaregrid-php-cli-production-build bf88590c0b389899354bb32725bab4606eb025721fc84865d4311b8d396c4dfc
check /etc/apparmor.d/wpcaregrid-php-cli-production-deploy 1c846f8cd95c8aa53ef62f95fe593024e0c37dd531de14e932e88274097354f4
check /etc/apparmor.d/wpcaregrid-php-fpm-production 21b3607c7f5f984aeaa47bb26c4c29f6b116341c9068dbc7a75dc69c2fadda17
check /etc/php/8.5/fpm-wpcaregrid-production/php-fpm.conf ea60818bdb5f20b7030596100c2d1f3fc582dc5145bb7b506d58e27d5de94d2a
check /etc/php/8.5/fpm-wpcaregrid-production/pool.d/wpcaregrid-production.conf bb3d595b00efba81c317b3e855b1158bade325b4cb1e00523db13dd94b85e64c
check /etc/systemd/system/nginx.service.d/90-wpcaregrid-confinement.conf e78e529cdbc49ab58875e0bac0be59a204d39ebbd9cbc6e23c5e4771f2528816
check /etc/systemd/system/wpcaregrid-production-php8.5-fpm.service c638125ca47c1bf6461606ed82443f11c928d8ac7a8e84aa76366269354304dc
```

### Gate 1 STOP/HOLD

Gate 1 is `HOLD` if:
- `PKG` is unset, not verified, or the host checkout is not confirmed at primary commit `e9a1bc352d54741ae72e2766f6e07e28e798900d`;
- the host checkout path is not established from primary (documentation blocker);
- any artifact hash or checksum differs;
- any target exists or is a symlink before installation;
- any parent directory is missing or not a canonical non-symlink directory;
- the route is open;
- any production secret exists;
- any attempt is made to use an installed target or helper transfer directory for package staging.

Gate 1 is `STOP` on any command failure; do not retry, repair, or continue to
profile load/enforce, live acceptance, helper install, secret placement,
database/application checks, route opening, or production activation.

## Ubuntu 26.04 validation

The release CI includes a dedicated Ubuntu 26.04 runner that compiles every 1.0.12 AppArmor profile without loading profiles, runs `systemd-analyze verify` on the production FPM unit and Nginx drop-in with the runner's packaged service units, and runs production-only matrix/collector/verifier tests. Ubuntu 24.04 results alone do not establish Ubuntu 26.04 compatibility. No host-side tests are part of this repository change.

Unmerged draft packages 1.0.8–1.0.11 and helpers 2.1.7–2.1.10 are removed from this PR; only package 1.0.12/helper 2.1.11 are proposed. Do not use files or host staging copied from earlier PR heads.

The package binding and its SHA256SUMS cover every package artifact. `scripts/deployment_environment_contract_2_1_11.py` and helper 2.1.11 bind the exact immutable package bytes, service roles, and CLI transient profiles.
