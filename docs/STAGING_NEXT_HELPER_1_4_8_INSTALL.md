# staging-next privileged helper 1.4.8 installation

This procedure installs only the versioned staging-next helper. It does not
install or deploy Laravel, run migrations, enable the Phase B database policy,
change workers or schedules, or touch legacy staging or production.

## Pinned source

Source is the exact protected-primary commit containing this document and the
manifest `deploy/release-manifests/staging-next-helper-1.4.8.json`.

| Artifact | Source path | SHA-256 |
| --- | --- | --- |
| Entrypoint | `scripts/wpcaregrid-staging-next-deploy-priv-1_4_8.py` | `417115f5d0c491beae718c224f67dad6bf296cf517fa353366044d0f5506da16` |
| Backend | `scripts/staging_next_deploy_backend_1_4_8.py` | `46dd532f9156e6237b3ec4c5dfaf732b2ad4a12fcd531537f5ac3d110001a3b8` |
| Transaction module | `scripts/staging_next_db_transaction_1_4_8.py` | `053f5a5249c6a5fbd7a1320c4b96e343f3f2af0123bee68e20e7d87d1f0d981a` |
| Combined helper artifact | Manifest-derived | `c9d8c9361191433367fcaf5b9516f876609916f8defe742167d3b6812f181232` |
| Root installer | `scripts/install_staging_next_helper_1_4_8.py` | `00b40a2cc4d2f3a577aaa06dbfcd0329f21043da55c44e690b3717b1c842be5c` |

The compatibility contract is `wpcaregrid-staging-next-deploy-v13`. Recalculate
these values from the final merged Git tree before any later installation; a
different source revision requires a new manifest and review.

## Scope of installation

The root installer is `scripts/install_staging_next_helper_1_4_8.py`. Place it
at `/home/deploy/.private/wpcaregrid-staging-next-helper-installer-1.4.8.py`.
Place exactly the three pinned source artifacts (no extra files or links) in
`/home/deploy/.private/wpcg-staging-next-helper-1.4.8/`, owned by `deploy:deploy`,
directory mode `0700`, file mode `0600`.

On success, the installer creates only these absent targets:

* `/usr/local/libexec/wpcaregrid-deploy/staging-next/1.4.8/` with the pinned
  entrypoint, backend and transaction module.
* `/etc/wpcaregrid-deploy/profiles/staging-next-1.4.8.json`.
* `/etc/sudoers.d/wpcaregrid-staging-next-1.4.8` with exact operation and
  argument restrictions for `deploy-staging-next`.

The Phase B policy file must remain absent. Existing destinations, old helper
versions and any unexpected source files cause a fail-closed stop. Publication
uses same-filesystem atomic hard links and never replaces a target. On an
installation failure, rollback removes only files whose inode, owner, group,
mode and content still match objects created by that invocation. An identity
mismatch leaves the object untouched and reports `ROLLBACK=HOLD` for manual
review.

## Root-console procedure

From a root console on `stg1.wpcaregrid.com`, run this single command in a
subshell so failure does not close the console:

```sh
( set -eu; i=/home/deploy/.private/wpcaregrid-staging-next-helper-installer-1.4.8.py; test -f "$i"; test ! -L "$i"; test "$(stat -c '%U:%G:%a' "$i")" = deploy:deploy:600; test "$(sha256sum "$i" | cut -d' ' -f1)" = 32f271560182de34c896d06bce31b69ef5bd918aa722cabb2edb2691a5913dc4; exec /usr/bin/python3 -I "$i" )
```

The script itself checks the exact source hashes and directory contents,
existing G1 identity, target absence, PHP-FPM socket owner/group, staging-next
HTTPS-to-PHP 8.5 routing, sudoers syntax, negative authorization cases, the
read-only helper identity probe, and that Phase B remains disabled. It does not
run `preflight`: that command can reconcile an unreceipted active release,
reload PHP-FPM, change the active application link, or create a gate/receipt.
All checks finish before the exact validated sudoers file is published; that
no-overwrite publication is the privilege-activation commit point. It prints
only structured status and fixed reason codes.

## Acceptance

Accept only `INSTALL=PASS`, the exact helper artifact hash above,
`NEGATIVE_AUTHORIZATION=PASS`, `INSTALLATION_PROBE=PASS`, and
`PHASE_B_POLICY=DISABLED`.
Also read back owner/mode/hash for all installed files and run `visudo -c`.
Any mismatch is STOP; do not retry with altered pins or relax a check.

This helper installation alone does not authorize an application deployment.
Database-changing deployment remains HOLD until fresh protected backup,
isolated restore and migration-rehearsal evidence are present and the Phase B
policy receives its separately reviewed, exact identity.

## Exact-release review evidence

Before the protected merge, independent Integration & QA and M17 AppSec agents
must each PASS the exact PR head. These reviews remain separate release
acceptance gates and must be refreshed after any source change.

The helper does not require external GitHub `APPROVED` reviews or
`WPCG_RELEASE_REVIEW_V2` records in issue #174. Runtime authorization still
requires the exact merged PR on the protected primary branch, successful
required CI for the PR head and merge SHA, a GitHub primary-branch ref reporting
`protected: true`, and matching source and helper hashes. Missing or
inconsistent merge, protection, CI, source, or artifact evidence is a HOLD.
Branch protection and the protected merge workflow remain enabled.
Production deployment requires separate Owner approval.
