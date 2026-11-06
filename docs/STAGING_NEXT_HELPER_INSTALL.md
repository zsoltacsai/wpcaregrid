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
| Entrypoint | `/usr/local/libexec/wpcaregrid-deploy/staging-next/1.0.2/wpcaregrid-deploy-staging-next-priv` |
| Application | `/var/www/wpcaregrid-staging-new` |
| Database | `wpcg_staging_next` |
| Host | `staging-next.wpcaregrid.com` |
| Policy | `/etc/wpcaregrid-deploy/profiles/staging-next.json` |
| Permitted helper operation | `preflight` only |

Source artifacts at this change's current candidate bytes (reconfirm from the
final merged SHA before installation):

- `scripts/wpcaregrid_deploy_profiles.py` —
  `80bbe2cb1ff40a1fc8f3ad6e04e6e7cdc1b5438a490e4720e165839cb5c82947`
- `scripts/wpcaregrid-deploy-env-priv.py` —
  `c874dd85b8555fdcac94372f3b488857115bcd08e4f3548e74286bfdea3dbf57`

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
3. Before writing anything, use `lstat`-equivalent checks to verify that the
   exact version directory, both final artifact paths, the profile policy
   path, and the sudoers drop-in path are all absent and are not symlinks.
   If any destination exists, stop without changing it; do not back it up or
   overwrite it. Verify every already-existing parent directory is
   root-owned, a directory, and not group/world writable. Create only the
   version directory with a single `mkdir` (which must fail if it exists),
   then verify root:root `0755`. Transfer the two verified files to a private
   administrator-owned staging directory. Publish each artifact from a
   same-filesystem temporary file using an atomic no-replace operation
   (for example, hard-link creation at the final name, which fails if that
   name already exists); never use a check-then-overwrite sequence. Set the
   entrypoint root:root `0755` and the module root:root `0644` before
   publication. Verify both final hashes, ownership, modes, regular-file
   types, and absence of symlinks.
4. Verify `/etc/wpcaregrid-deploy` and its `profiles` child already exist as
   root:root `0755` directories, are not symlinks, and have safe parent
   metadata. If either directory is absent or differs, stop for a separately
   reviewed provisioning procedure; this installation must not create or
   alter shared policy directories. Prepare one root-owned `0644` JSON policy
   in a same-filesystem temporary file, then publish it at the exact path
   above using an atomic no-replace operation. The path must still be absent
   at publication time. It must
   have exactly these keys: `schema`, `profile`, `enabled`, `allowed_uid`,
   `app_dir`, `database`, `host`, `operations`, `helper_version`,
   `compatibility_contract`, `entrypoint_sha256`, `profiles_module_sha256`.
   Set schema `1`, profile
   `staging-next`, enabled `true`, the verified numeric nonzero deploy UID,
   the four compiled target values above, and operations `['preflight']`.
   Pin `helper_version=1.0.2`, `compatibility_contract=wpcaregrid-deploy-profile-v1`,
   and the exact two installed file hashes from the merged source manifest.
   Verify root ownership, mode `0644`, strict JSON with no duplicate or unknown
   keys, exact schema and matching helper bytes. Do not place credentials in
   the policy.
5. Prepare one root:root `0440` sudoers drop-in in a same-filesystem
   temporary file and validate that temporary file with `visudo -cf`.
   Publish it to `/etc/sudoers.d/wpcaregrid-staging-next` using an atomic
   no-replace operation; if the destination appeared or existed at any point,
   stop and do not overwrite it. The drop-in contains one rule for
   `deploy-staging-next` and only the
   exact versioned entrypoint plus literal `preflight` argument shown above;
   use `NOSETENV`, no wildcards, no shell, and no other command. Validate the
   published drop-in again with `visudo -cf` before considering installation
   complete. Do not modify the legacy
   sudoers rule or create any production rule.
6. Verify from the dedicated staging-next deploy identity that the no-argument,
   `--profile`, alternate path, database, service and production-entrypoint
   requests are denied. Verify the exact `preflight` invocation returns
   `RESULT=PASS`, fixed `PROFILE=staging-next`, and no path or secret values.
7. Record each object created by this attempt: absolute path, device/inode,
   file type, owner/group, mode, and SHA-256 for files. On failure, rollback
   only those exact objects, and only after rechecking that each still has
   the recorded identity and remains beneath its approved target directory.
   Remove files before the version directory; never recursively delete a
   computed path. If an object is missing, changed, replaced, or cannot be
   safely identified, stop and leave it for administrator review. No
   pre-existing destination is backed up or restored because the procedure
   refuses to proceed when any final destination already exists. Do not touch
   legacy staging files or services.

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
