# Staging-next privileged helper installation procedure

**Status: source-level procedure only. No host installation or deployment is authorized.**

## Contract

The shared source is `scripts/wpcaregrid_deploy_profiles.py` plus the fixed
entrypoint `scripts/wpcaregrid-deploy-env-priv.py`. The legacy
`/usr/local/sbin/wpcaregrid-deploy-priv` and `/var/www/wpcaregrid` recovery
environment are outside this profile and must remain unchanged.

The only enabled profile in this release is `staging-next`, bound in source to:

| Identity | Pinned value |
| --- | --- |
| Entrypoint | `/usr/local/libexec/wpcaregrid-deploy/staging-next/1.0.1/wpcaregrid-deploy-staging-next-priv` |
| Application | `/var/www/wpcaregrid-staging-new` |
| Database | `wpcg_staging_next` |
| Host | `staging-next.wpcaregrid.com` |
| Policy | `/etc/wpcaregrid-deploy/profiles/staging-next.json` |
| Permitted helper operation | `preflight` only |

Source artifacts at this change's current candidate bytes (reconfirm from the
final merged SHA before installation):

- `scripts/wpcaregrid_deploy_profiles.py` —
  `27c122e11de038c2bf28f9bc462e4b78c695777a8d592a23428f17b5dc819bf7`
- `scripts/wpcaregrid-deploy-env-priv.py` —
  `0bc491358c8cbda56e88eef0d6e0fff0b944bd533eca5583555e02048afd7533`

Production is represented by a disabled profile with no path, database, host,
policy file, caller UID, or operation. No production helper, sudoers entry,
allowlist, target values, or activation is part of this release. The staging
entrypoint rejects profile/target parameters; its profile comes only from its
exact installed absolute path. The root-owned policy must independently match
the compiled staging-next identities and operation allowlist. Its caller UID
must be the separately approved staging-next deploy identity.

## Administrator installation, after a separate exact-scope Owner GO

1. Verify the protected primary merge SHA and exact merge-SHA CI. Recompute
   both source hashes from that Git tree and compare them with the current
   helper manifest; stop on any mismatch. Do not copy files from a mutable
   checkout.
2. Confirm or provision under the separate G1 approval a dedicated
   `deploy-staging-next` Unix identity. Record its numeric UID with `id -u`;
   stop for UID 0, missing identity, or ambiguity. Do not reuse the legacy
   `deploy` account. Confirm staging-next's target directory, database name,
   BasicAuth vhost, FPM route, gate paths and service names from approved
   read-only baseline evidence. Stop if any target value differs from the
   compiled profile or if any value remains unknown. The current helper has
   no service-control operation, so service names are recorded for the future
   deploy contract but are not allowlisted here.
3. Confirm the versioned destination does not exist. Create the exact
   `/usr/local/libexec/wpcaregrid-deploy/staging-next/1.0.1` directory
   root:root `0755`; transfer only the two verified files into a private
   administrator-owned staging directory; install the entrypoint as root:root
   `0755` and the module as root:root `0644`. Refuse every pre-existing file.
   Verify parent directories are root-owned and not group/world writable,
   both file hashes match, and neither path is a symlink.
4. Verify or create `/etc/wpcaregrid-deploy` and its `profiles` child as
   root:root `0755`; refuse symlinks or pre-existing unexpected metadata.
   Verify the staging-next policy path does not exist. Install one root-owned
   `0644` JSON policy at the exact path above. It must
   have exactly these keys: `schema`, `profile`, `enabled`, `allowed_uid`,
   `app_dir`, `database`, `host`, `operations`, `helper_version`,
   `compatibility_contract`, `entrypoint_sha256`, `profiles_module_sha256`.
   Set schema `1`, profile
   `staging-next`, enabled `true`, the verified numeric nonzero deploy UID,
   the four compiled target values above, and operations `['preflight']`.
   Pin `helper_version=1.0.1`, `compatibility_contract=wpcaregrid-deploy-profile-v1`,
   and the exact two installed file hashes from the merged source manifest.
   Verify root ownership, mode `0644`, strict JSON with no duplicate or unknown
   keys, exact schema and matching helper bytes. Do not place credentials in
   the policy.
5. Install one root:root `0440` drop-in at
   `/etc/sudoers.d/wpcaregrid-staging-next` with one rule for
   `deploy-staging-next` and only the
   exact versioned entrypoint plus literal `preflight` argument shown above;
   use `NOSETENV`, no wildcards, no shell, and no other command. Validate the
   drop-in with `visudo -cf` before activation. Do not modify the legacy
   sudoers rule or create any production rule.
6. Verify from the dedicated staging-next deploy identity that the no-argument,
   `--profile`, alternate path, database, service and production-entrypoint
   requests are denied. Verify the exact `preflight` invocation returns
   `RESULT=PASS`, fixed `PROFILE=staging-next`, and no path or secret values.
7. If any installation check fails, remove only the new version directory,
   new policy and new sudoers drop-in after verifying their exact identities;
   restore only staging-next-specific files from the recorded root-only
   backup. Do not touch legacy staging files or services.

## Current implementation boundary

This initial helper exposes no deployment, migration, database, service,
worker, scheduler, gate, Nginx, PHP-FPM, backup, restore, rollback or production
operation. `preflight` checks root policy authorization and the fixed
application path without following symlinks. It does not claim that the
application, database, restore evidence, or release is ready. The daily
release decision continues to require the complete #272 exact-SHA, artifact,
backup/restore, migration-rehearsal and acceptance gates. An eligible release
must remain HOLD until a later reviewed helper contract implements the
approved deployment operation and its rollback guarantees.

Helper version and compatibility contract are pinned separately from the
Laravel application SHA in the release manifest. A helper update is required
only when the helper contract changes or the installed helper is not the
approved pinned version; an application-only commit does not imply helper
replacement.
