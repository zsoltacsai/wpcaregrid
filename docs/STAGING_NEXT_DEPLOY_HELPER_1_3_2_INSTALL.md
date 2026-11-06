# Staging-next deployment helper 1.3.2

**Preparation only.** This procedure does not authorize installation, a Laravel
deployment, database changes, or schedule activation. Each requires its own
Owner GO. It applies only to `staging-next`; legacy staging and production are
excluded.

## Exact artifact pins

Recompute all values from the approved merge tree before use. Stop on a mismatch.

| Artifact | Source path | SHA-256 | Installed owner/mode |
| --- | --- | --- | --- |
| Entrypoint | `scripts/wpcaregrid-staging-next-deploy-priv.py` | `8717b948d912c8e9fda3e151fd2d656d4da9d4042fb43390d45d11c36fd38fa7` | `root:root 0755` |
| Backend | `scripts/staging_next_deploy_backend.py` | `c818578853df42323701ae77635d0a2aefd6becfe294db445dcb63a781b65721` | `root:root 0644` |
| Combined helper artifact | `deploy/release-manifests/staging-next-helper-1.3.2.json` | `9d3c82a3d6d7e6dfaae17e586a9c7488ca7b68326d92f8044e57d038e39b68a9` | Pinned in policy and receipts |

Version: `1.3.2`. Compatibility contract remains
`wpcaregrid-staging-next-deploy-v3`. Version 1.3.2 is a new immutable
installation; do not overwrite 1.3.0.

## Fixed profile

* Application link: `/var/www/wpcaregrid-staging-new`
* Database: `wpcg_staging_next`
* Host: `staging-next.wpcaregrid.com`
* Entrypoint: `/usr/local/libexec/wpcaregrid-deploy/staging-next/1.3.2/wpcaregrid-deploy-staging-next-priv`
* Backend: `/usr/local/libexec/wpcaregrid-deploy/staging-next/1.3.2/staging_next_deploy_backend.py`
* Policy: `/etc/wpcaregrid-deploy/profiles/staging-next-1.3.2.json`
* Sudoers: `/etc/sudoers.d/wpcaregrid-staging-next-1.3.2`
* Operations: `preflight`, `authorize <exact-primary-sha>`,
  `stage <exact-primary-sha> <artifact-sha256>`, and
  `deploy <exact-primary-sha> <artifact-sha256>`.

The root-owned profile must bind the actual dedicated deploy UID, MySQL server
UUID fingerprint, independent QA/AppSec reviewer identities, PHP-FPM UID/GID,
fixed paths, operations, version, contract and artifact hashes. The helper
refuses all other targets and operations. The controller runs as
`deploy-staging-next`, never root.

## Installation gate

Separate installation GO is required. Use a fresh version-specific directory
and no-overwrite publication. Verify all source hashes and target absence,
then install root-owned files with the tabled modes. Validate both temporary
and installed sudoers files with `visudo`, run all negative authorization
tests, and run only the positive `preflight`. If any destination exists or any
identity check fails, stop without replacing it. Rollback may remove only
objects created by that installation after device, inode, type, owner, mode
and hash checks; leave versions 1.2.0, 1.3.0, legacy staging and production
untouched.

## Phase A behavior and recovery limits

The controller accepts only the latest eligible exact primary SHA after
required CI and independent reviews. It builds an immutable archive, binds its
content manifest and helper artifact identity, and stages without overwrite.
The helper serializes deployment, validates source and artifact hashes, checks
the fixed staging-next profile, prepares the immutable release, switches the
active link atomically, reloads bounded PHP-FPM, and records observed HTTP
acceptance. Unauthenticated `/hu` and `/en` probes must return 401; authenticated
fixed acceptance probes must match their expected statuses. A repeat of an
accepted release is `NO_OP`; a pointer switch without a success receipt is
reloaded and reconciled only after the fixed runtime and HTTP checks pass.

If a process stops after publishing but before activation, a retry resumes the
inactive candidate only after rechecking its base SHA, application SHA,
artifact digest, file manifest, primary source bytes, shared inputs and helper
identity. Any mismatch remains `HOLD`. Database-changing releases remain
`HOLD` until Phase B backup, isolated restore, migration and crash-recovery
gates are implemented and accepted. This source change does not install the
helper, deploy Laravel, or activate a schedule.

On interrupted activation, a release without a durable PASS receipt is rechecked. If it fails runtime or HTTP/BasicAuth acceptance, the helper restores the pinned base release, reloads PHP-FPM, verifies the restored runtime and both authenticated and unauthenticated probes, and records a failure receipt. Any rollback verification failure remains FAIL/HOLD; no gate is opened by the helper.
