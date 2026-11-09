# staging-next privileged helper 1.4.9 installation

Helper 1.4.9 is 1.4.8 plus the closed deployment **target binding**
(`scripts/deployment_target_binding.py`, docs/DEPLOYMENT_TARGET_BINDING.md). The binding is
used on the helper's real operation paths: application/database identity, state directories,
lock, backup, worker/scheduler fence markers, transaction journal identity and resumption.
Older helper versions (<= 1.4.8) and the legacy staging tools are unchanged.

This procedure installs only the versioned staging-next helper. It does not
install or deploy Laravel, run migrations, enable the Phase B database policy,
change workers or schedules, or touch legacy staging or production. **Nothing in
this document has been executed on any host; installing it needs a separate Owner GO.**

## Pinned source

Source is the exact protected-primary commit containing this document and the
manifest `deploy/release-manifests/staging-next-helper-1.4.9.json`.

| Artifact | Source path | SHA-256 |
| --- | --- | --- |
| Entrypoint | `scripts/wpcaregrid-staging-next-deploy-priv-1_4_9.py` | `59357e552f282ee0ee3936953014b085bdea22e24da23336afb36b39dfa47750` |
| Backend | `scripts/staging_next_deploy_backend_1_4_9.py` | `68bc80ad8a87b06e19a2f9be1a52dc50f80db25183f66a39b1936f8434b1975f` |
| Transaction module | `scripts/staging_next_db_transaction_1_4_9.py` | `809faa581019a9349eaae09ed18198f164781354ba5a89de55a858258dd7d290` |
| Target binding | `scripts/deployment_target_binding.py` | `2038beb3e1a3d74582af5e9a5a08b096d8d7021b94f7f392a22391e67ea3c3bc` |
| Environment contract | `scripts/deployment_environment_contract.py` | `f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1` |
| Combined helper artifact | Manifest-derived | `bf49a138e9f9933ddcad8bb41cd931a97bb4cb297604c621c88a499d5f8fd9e4` |
| Root installer | `scripts/install_staging_next_helper_1_4_9.py` | `d08cbb91cf5aa622fd4cabcf074c46380dcc3bd87b24d04eeea935533d6e36e2` |

The compatibility contract is `wpcaregrid-staging-next-deploy-v14`. Recalculate
these values from the final merged Git tree (LF bytes) before any installation; a
different source revision requires a new manifest and review.

## What changes relative to 1.4.8

* Two more root-owned, mode 0644 files are installed next to the helper
  (`deployment_target_binding.py`, `deployment_environment_contract.py`); the helper
  policy pins both (`target_binding_sha256`, `environment_contract_sha256`) and the combined
  artifact digest covers them.
* The privileged entrypoint executes the binding from the authenticated bytes and hands it
  the authenticated contract bytes; the privileged process does not read either from disk again.
* `_safe_profile`, the deploy/preflight lock, every backup path, the fence markers and the
  transaction journal are checked against the one compiled `staging-next` target. A path,
  backup, marker, lock or journal belonging to legacy staging, a symlink or hardlink
  component, wrong owner/mode, or an unknown/`RECOVERY_REQUIRED` journal state is a fixed
  `target_binding_*` HOLD. Production cannot be selected.
* Policy file, sudoers file and Phase B policy/credential paths are versioned `1.4.9`.

## Scope of installation

The root installer is `scripts/install_staging_next_helper_1_4_9.py`. Place it
at `/home/deploy/.private/wpcaregrid-staging-next-helper-installer-1.4.9.py`.
Place exactly the five pinned source artifacts (no extra files or links) in
`/home/deploy/.private/wpcg-staging-next-helper-1.4.9/`, owned by `deploy:deploy`,
directory mode `0700`, file mode `0600`.

On success, the installer creates only these absent targets:

* `/usr/local/libexec/wpcaregrid-deploy/staging-next/1.4.9/` with the pinned
  entrypoint, backend, transaction module, target binding and environment contract.
* `/etc/wpcaregrid-deploy/profiles/staging-next-1.4.9.json`.
* `/etc/sudoers.d/wpcaregrid-staging-next-1.4.9` with exact operation and
  argument restrictions for `deploy-staging-next`.

The Phase B policy file must remain absent. Existing destinations, old helper
versions and any unexpected source files cause a fail-closed stop. Publication
uses same-filesystem atomic hard links and never replaces a target. On an
installation failure, rollback removes only files whose inode, owner, group,
mode and content still match objects created by that invocation. An identity
mismatch leaves the object untouched and reports `ROLLBACK=HOLD` for manual
review.

## Root-console procedure (Owner GO required)

From a root console on `stg1.wpcaregrid.com`, run this single command in a
subshell so failure does not close the console:

```sh
( set -eu; i=/home/deploy/.private/wpcaregrid-staging-next-helper-installer-1.4.9.py; test -f "$i"; test ! -L "$i"; test "$(stat -c '%U:%G:%a' "$i")" = deploy:deploy:600; test "$(sha256sum "$i" | cut -d' ' -f1)" = d08cbb91cf5aa622fd4cabcf074c46380dcc3bd87b24d04eeea935533d6e36e2; exec /usr/bin/python3 -I "$i" )
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
Also read back owner/mode/hash for all **five** installed files and run `visudo -c`.
Any mismatch is STOP; do not retry with altered pins or relax a check.

The installation probe runs `verify-install`, which now also evaluates the identity binding
(state directories, symlink and owner/mode checks) read-only. A `target_binding_*` code at
this point means the host layout differs from the compiled `staging-next` contract: STOP and
report; do not edit the layout to satisfy the check without a reviewed plan.

This helper installation alone does not authorize an application deployment.
Database-changing deployment remains HOLD until fresh protected backup,
isolated restore and migration-rehearsal evidence are present and the Phase B
policy receives its separately reviewed, exact identity.

## Verified installation plan (every step after step 0 needs an explicit Owner GO)

0. Source only (done by this change): merge, exact merge-SHA CI, review evidence on #174.
1. Owner confirms the installed-helper inventory is still read-only consistent (no change).
2. Owner GO #1: copy the installer and the five pinned files to
   `/home/deploy/.private/` as above and verify their hashes against this table (read-only).
3. Owner GO #2: run the root-console command (creates only absent files; fail-closed rollback).
4. Read-only acceptance per the section above; do not run `preflight`/`deploy`.
5. Separate Owner GO per later step: Phase B policy and credentials (1.4.9 paths), MySQL
   isolation (G1), fresh backup and isolated restore rehearsal, first `stage`/`deploy`.

## Release review evidence (policy V3)

Source release requires green required CI on the exact head and, bound to that exact head,
separate Integration & QA and AppSec AI-agent verdicts recorded on the release ledger as
`WPCG_RELEASE_REVIEW_V3` (docs/RELEASE_REVIEW_POLICY.md). They are internal AI-agent checks,
not human reviews, and never authorize a host operation. The helper does not require GitHub
`APPROVED` reviews or `WPCG_RELEASE_REVIEW_V2` records; runtime authorization still requires
the exact merged PR on the protected primary branch, successful required CI for the PR head
and merge SHA, a GitHub primary-branch ref reporting `protected: true`, and matching source
and helper hashes. Missing or inconsistent evidence is a HOLD. Production deployment and any
privileged host action require separate Owner approval.
