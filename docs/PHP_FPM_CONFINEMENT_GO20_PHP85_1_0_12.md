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

## Ubuntu 26.04 validation

The release CI includes a dedicated Ubuntu 26.04 runner that compiles every 1.0.12 AppArmor profile without loading profiles, runs `systemd-analyze verify` on the production FPM unit and Nginx drop-in with the runner's packaged service units, and runs production-only matrix/collector/verifier tests. Ubuntu 24.04 results alone do not establish Ubuntu 26.04 compatibility. No host-side tests are part of this repository change.

Unmerged draft packages 1.0.8–1.0.11 and helpers 2.1.7–2.1.10 are removed from this PR; only package 1.0.12/helper 2.1.11 are proposed. Do not use files or host staging copied from earlier PR heads.

The package binding and its SHA256SUMS cover every package artifact. `scripts/deployment_environment_contract_2_1_11.py` and helper 2.1.11 bind the exact immutable package bytes, service roles, and CLI transient profiles.
