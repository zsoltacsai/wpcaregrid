# staging-next privileged helper 1.4.5 installation

This procedure installs only the versioned staging-next helper. It does not
install or deploy Laravel, run migrations, enable the Phase B database policy,
change workers or schedules, or touch legacy staging or production.

## Pinned source

Source is the exact protected-primary commit containing this document and the
manifest `deploy/release-manifests/staging-next-helper-1.4.5.json`.

| Artifact | Source path | SHA-256 |
| --- | --- | --- |
| Entrypoint | `scripts/wpcaregrid-staging-next-deploy-priv.py` | `535c27bab3d36c4de39f528d1f02767c3a7e874e457d498f19d3a5698cfedb79` |
| Backend | `scripts/staging_next_deploy_backend.py` | `56b731914127b60e99f50f601930bbae8953490e1427003759225a9571a7135b` |
| Transaction module | `scripts/staging_next_db_transaction.py` | `4cb84c11bb4aee1778c8cd4166f35c051b2025627bd81d01d4fbd6ad5a6223a0` |
| Combined helper artifact | Manifest-derived | `7c8eeab748381b2b6de4ade68160ce8d4d79c2748fc99366bf9f584358bedebf` |
| Root installer | `scripts/install_staging_next_helper_1_4_5.py` | `b30e9981f6a1c50badbfb9d4dacab4f4957702d979fdc9fbf82137cd17e3da91` |

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
( set -eu; i=/home/deploy/.private/wpcaregrid-staging-next-helper-installer-1.4.5.py; test -f "$i"; test ! -L "$i"; test "$(stat -c '%U:%G:%a' "$i")" = deploy:deploy:600; test "$(sha256sum "$i" | cut -d' ' -f1)" = b30e9981f6a1c50badbfb9d4dacab4f4957702d979fdc9fbf82137cd17e3da91; exec /usr/bin/python3 -I "$i" )
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

The helper does not infer QA/AppSec roles from reviewer-authored labels. It
requires two distinct, non-author GitHub `APPROVED` reviews on the exact PR
head, then an exact `WPCG_RELEASE_REVIEW_V2` record for each role in issue #174.
Each record must be posted by the repository owner account (`author_association`
`OWNER`), identify the reviewer login and GitHub review ID, match the PR and
head SHA, and be timestamped no earlier than the referenced approval. The
helper cross-checks each ID, reviewer, state, timestamp and commit against the
authenticated GitHub review API response. Missing or inconsistent evidence is
a HOLD. This preserves two independent reviewer approvals while preventing
reviewers or other contributors from assigning themselves QA/AppSec roles.
