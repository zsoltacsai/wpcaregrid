# Staging-next deployment helper 1.3.0

**Preparation only.** This procedure does not authorize installation, a Laravel
deployment, a database operation, or schedule activation. Each needs its own
Owner GO. It applies only to `staging-next`; legacy staging and production are
excluded.

## Pinned source artifacts

Recompute all values from the approved merge tree immediately before use.
Stop on any mismatch.

| Artifact | Path | SHA-256 | Installed owner/mode |
| --- | --- | --- | --- |
| Entrypoint | `scripts/wpcaregrid-staging-next-deploy-priv.py` | `68fcd4e56f14662cef77379abf2f24e4472e392a750ddfdae1f1f6e32fb792c0` | `root:root 0755` |
| Backend | `scripts/staging_next_deploy_backend.py` | `f9e61e91856683e947f25deb4c3848ea6cceb47c7d761f8c8afd6af3050ae1dd` | `root:root 0644` |
| Helper artifact | Manifest-derived | `1a983827398775d74b2b623ba3dd4853f6fc8a987da56db888a498999a2c9917` | Recorded in policy and receipts |

Helper version is `1.3.0`, compatibility contract is
`wpcaregrid-staging-next-deploy-v3`. The matching manifest is
`deploy/release-manifests/staging-next-helper-1.3.0.json`; the controller
independently pins the same derived artifact digest.

## Fixed profile and operations

* Application link: `/var/www/wpcaregrid-staging-new`
* Database identity: `wpcg_staging_next`
* Host: `staging-next.wpcaregrid.com`
* Entrypoint: `/usr/local/libexec/wpcaregrid-deploy/staging-next/1.3.0/wpcaregrid-deploy-staging-next-priv`
* Backend: `/usr/local/libexec/wpcaregrid-deploy/staging-next/1.3.0/staging_next_deploy_backend.py`
* Policy: `/etc/wpcaregrid-deploy/profiles/staging-next-1.3.0.json`
* Sudoers drop-in: `/etc/sudoers.d/wpcaregrid-staging-next-1.3.0`
* Allowed operations: `preflight`, `authorize <exact-primary-sha>`,
  `stage <exact-primary-sha> <artifact-sha256>`, `deploy <exact-primary-sha>
  <artifact-sha256>`.

The root-owned policy must bind the actual dedicated deploy UID, MySQL server
UUID fingerprint, distinct Integration & QA and M17 AppSec reviewer identities,
PHP-FPM UID/GID, exact paths, operations, version, contract, and all three
artifact hashes. It contains no credentials. It must be strict-schema parsed,
root-owned, mode `0644`, and symlink-free. The helper clears the inherited
environment before loading the backend and refuses all other profiles and
operations. The controller runs as `deploy-staging-next`, never root.

## Installation outline — separate installation GO required

1. On the approved primary, verify the exact merge commit, its successful
   merge-SHA CI, exact-head reviews, and the tabled artifact hashes above.
2. Check the dedicated `deploy-staging-next` UID and the already provisioned
   root-owned parents. Record target metadata without reading secrets. Stop if
   any target differs, the caller is root, or a required parent is absent or
   unsafe.
3. Verify every final destination is absent with `lstat`. Do not overwrite or
   replace an existing 1.2.0 or 1.3.0 artifact, policy, or sudoers file.
4. Stage source bytes in a root-only directory, verify their hashes, owner and
   modes, and publish each destination atomically with no-replace semantics.
   Create only the version-specific `1.3.0` directory if absent. Do not change
   the installed 1.2.0 helper, legacy staging, or shared policy directories.
5. Install the exact root-owned policy. Add only the fixed 1.3.0 entrypoint to
   the dedicated account's sudo rule with `NOSETENV`; helper policy remains the
   authoritative operation allowlist. Validate the temporary and published
   sudoers files with `visudo -cf` and the complete policy with the helper's
   negative authorization tests.
6. Run `preflight` from the dedicated account. Require exact helper version,
   contract and artifact digest, staging-next target identities, PHP 8.5
   runtime and current immutable release identity. The deployment operation
   performs the fixed HTTP acceptance after activation.
7. On failure, remove only files created by this installation after verifying
   their recorded device/inode, type, owner, mode and hash. Remove the version
   directory only when empty and still matching its recorded identity. Stop if
   any identity changed; do not roll back by overwriting a pre-existing object.

No installation command is provided until the exact merged artifact and a
separate installation authorization are available. The new helper is not
installed by this source change.

## Deployment contract and limits

The daily controller has a single `sync` operation. It verifies the immutable
protected-primary SHA, successful exact-commit CI and two independent reviews
before packaging. It builds the frontend from that commit, writes a canonical
content manifest, hashes the immutable archive, and stages it without
overwrite. The helper rechecks source-tree identity, archive and per-file
hashes, profile identity and runtime before activation. It uses an exclusive
lock, an atomic active-link swap, bounded PHP-FPM reload and bounded HTTP
acceptance. It writes receipts from observed state; exact active release is
`NO_OP`. Failures after activation restore and verify the prior immutable
release and record the outcome.

This release intentionally returns `HOLD` for every candidate that changes
application files outside the Phase A marketing-only allowlist. It does not
back up or restore a real deployment database, rehearse or execute application
migrations, manage maintenance gates, workers or scheduler, or recover a
crash during a database migration. Those Phase B capabilities remain a
release blocker for database-changing deployments and require a separately
reviewed helper contract and isolated disposable-service evidence. It does not
activate a timer or deploy to the VPS.

## Rollback and acceptance

The installation rollback is limited to the exact newly created 1.3.0 files,
policy and sudoers drop-in; the 1.2.0 version stays in place. Application
rollback is limited to an atomic pointer restore to the prior immutable
release after a database-unchanged deployment. It is not a database recovery
procedure. Require successful helper preflight, negative authorization tests,
source/installed hash agreement, `visudo` validation, runtime checks and all
fixed acceptance URLs before reporting helper installation PASS. Keep
scheduled execution disabled until a separate Owner GO.
