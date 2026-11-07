# staging-next privileged helper 1.4.7 installation

This procedure installs only the versioned staging-next helper. It does not
install or deploy Laravel, run migrations, enable the Phase B database policy,
change workers or schedules, or touch legacy staging or production.

## Pinned source

Source is the exact protected-primary commit containing this document and the
manifest `deploy/release-manifests/staging-next-helper-1.4.7.json`.

| Artifact | Source path | SHA-256 |
| --- | --- | --- |
| Entrypoint | `scripts/wpcaregrid-staging-next-deploy-priv-1_4_7.py` | `7eecc1876c52c187229187db00d410cb9a80d6f1d0df7ad91a5344f71b45fb2b` |
| Backend | `scripts/staging_next_deploy_backend_1_4_7.py` | `6e5a6559eed5fcc5dbcb079d89afb15edc5c7e4982d46440aa4499c489b0b52c` |
| Transaction module | `scripts/staging_next_db_transaction_1_4_7.py` | `89b5505ec7d05c58ed30d87d7e9b07d44fa0286894b346eeeb9948dbfacfa71a` |
| Combined helper artifact | Manifest-derived | `d38a3c21c611e84d0741c2ab39401da31f60f17659902aa33e503a4bbae58837` |
| Root installer | `scripts/install_staging_next_helper_1_4_7.py` | `83a982de1eb862f1fe231cad3001f41aa32c68995d9cc8153184c26fd7e2a7cb` |

The compatibility contract is `wpcaregrid-staging-next-deploy-v11`. Recalculate
these values from the final merged Git tree before any later installation; a
different source revision requires a new manifest and review.

## Scope of installation

The root installer is `scripts/install_staging_next_helper_1_4_7.py`. Place it
at `/home/deploy/.private/wpcaregrid-staging-next-helper-installer-1.4.7.py`.
Place exactly the three pinned source artifacts (no extra files or links) in
`/home/deploy/.private/wpcg-staging-next-helper-1.4.7/`, owned by `deploy:deploy`,
directory mode `0700`, file mode `0600`.

On success, the installer creates only these absent targets:

* `/usr/local/libexec/wpcaregrid-deploy/staging-next/1.4.7/` with the pinned
  entrypoint, backend and transaction module.
* `/etc/wpcaregrid-deploy/profiles/staging-next-1.4.7.json`.
* `/etc/sudoers.d/wpcaregrid-staging-next-1.4.7` with exact operation and
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
( set -eu; i=/home/deploy/.private/wpcaregrid-staging-next-helper-installer-1.4.7.py; test -f "$i"; test ! -L "$i"; test "$(stat -c '%U:%G:%a' "$i")" = deploy:deploy:600; test "$(sha256sum "$i" | cut -d' ' -f1)" = d6c7decc427860d671b1bf03c9e74af45391b12a599a304bfc13e1f619edac61; exec /usr/bin/python3 -I "$i" )
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
