# staging-next privileged helper 1.4.5 installation

This procedure installs only the versioned staging-next helper. It does not
install or deploy Laravel, run migrations, enable the Phase B database policy,
change workers or schedules, or touch legacy staging or production.

## Pinned source

Source is the exact protected-primary commit containing this document and the
manifest `deploy/release-manifests/staging-next-helper-1.4.5.json`.

| Artifact | Source path | SHA-256 |
| --- | --- | --- |
| Entrypoint | `scripts/wpcaregrid-staging-next-deploy-priv.py` | `1d3e24894938e70906f349cecabe2918876b90064ecb291bcf4040bf3f972bed` |
| Backend | `scripts/staging_next_deploy_backend.py` | `ee4c1402b358e4028d0ad4609a16a275cb9cc7ae60cc3c8b4bbfc9df357b8c11` |
| Transaction module | `scripts/staging_next_db_transaction.py` | `4cb84c11bb4aee1778c8cd4166f35c051b2025627bd81d01d4fbd6ad5a6223a0` |
| Combined helper artifact | Manifest-derived | `996ca70b9c99807fe6b8035dd60a7309ba401ca9b86be0444a724a2967ec6221` |
| Root installer | `scripts/install_staging_next_helper_1_4_5.py` | `f9bd85bc23399ebfe37bacc66e7a32fda4abf81dba7b862e627ed23c76a8a367` |

The compatibility contract is `wpcaregrid-staging-next-deploy-v9`. Recalculate
these values from the final merged Git tree before any later installation; a
different source revision requires a new manifest and review.

## Scope of installation

The root installer is `scripts/install_staging_next_helper_1_4_5.py`. Place it
at `/home/deploy/.private/wpcaregrid-staging-next-helper-installer-1.4.5.py`.
Place exactly the three pinned source artifacts (no extra files or links) in
`/home/deploy/.private/wpcg-staging-next-helper-1.4.5/`, owned by `deploy:deploy`,
directory mode `0700`, file mode `0600`.

On success, the installer creates only these absent targets:

* `/usr/local/libexec/wpcaregrid-deploy/staging-next/1.4.5/` with the pinned
  entrypoint, backend and transaction module.
* `/etc/wpcaregrid-deploy/profiles/staging-next-1.4.5.json`.
* `/etc/sudoers.d/wpcaregrid-staging-next-1.4.5` with exact operation and
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
( set -eu; i=/home/deploy/.private/wpcaregrid-staging-next-helper-installer-1.4.5.py; test -f "$i"; test ! -L "$i"; test "$(stat -c '%U:%G:%a' "$i")" = deploy:deploy:600; test "$(sha256sum "$i" | cut -d' ' -f1)" = f9bd85bc23399ebfe37bacc66e7a32fda4abf81dba7b862e627ed23c76a8a367; exec /usr/bin/python3 -I "$i" )
```

The script itself checks the exact source hashes and directory contents,
existing G1 identity, target absence, PHP-FPM socket owner/group, unambiguous
staging-next HTTPS-to-PHP 8.5 routing, sudoers syntax, negative authorization
cases, the positive read-only preflight, and that Phase B remains disabled. It
prints only structured status and fixed reason codes.

## Acceptance

Accept only `INSTALL=PASS`, the exact helper artifact hash above,
`NEGATIVE_AUTHORIZATION=PASS`, `PREFLIGHT=PASS`, and `PHASE_B_POLICY=DISABLED`.
Also read back owner/mode/hash for all installed files and run `visudo -c`.
Any mismatch is STOP; do not retry with altered pins or relax a check.

This helper installation alone does not authorize an application deployment.
Database-changing deployment remains HOLD until fresh protected backup,
isolated restore and migration-rehearsal evidence are present and the Phase B
policy receives its separately reviewed, exact identity.
