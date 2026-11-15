# staging-next privileged helper 1.4.10 installation

Helper 1.4.10 is based on helper 1.4.9 and adds a journaled DBA handoff at the
two pinned Phase 2B trigger migrations. It pauses before those migrations, keeps
the existing release fences in place, and resumes only after consuming a
transaction-bound, root-protected DBA receipt. Helper 1.4.9 and older releases
remain unchanged and separately installed.

This procedure installs only the versioned staging-next helper. It does not
install or deploy Laravel, run migrations, enable the Phase B database policy,
change workers or schedules, or touch legacy staging or production. **Nothing in
this document has been executed on any host; installing it needs a separate Owner GO.**

## Pinned source

Source is the GO-8.3 branch candidate tree and the manifest
`deploy/release-manifests/staging-next-helper-1.4.10.json`. These source pins do
not mean the helper is installed on staging; no host has been read or changed.

| Artifact | Source path | SHA-256 |
| --- | --- | --- |
| Entrypoint | `scripts/wpcaregrid-staging-next-deploy-priv-1_4_10.py` | `73ad1cca452aefb76b3f0e0f380ee950d69a50da008719000b41690bcb7dc969` |
| Backend | `scripts/staging_next_deploy_backend_1_4_10.py` | `4ac51b4a57d4647f27c1c62143a5810987123dc209e5fee75c653c51fac10125` |
| Transaction module | `scripts/staging_next_db_transaction_1_4_10.py` | `580a52530c9229ce4be0008a36657bdae113e392e6c21c09ad8125306092b8e9` |
| Target binding | `scripts/deployment_target_binding.py` | `987220381d5ea5d02aea1571e7b150f3f9596b27ff56d4b764a4a9e1bbdbb910` |
| Environment contract | `scripts/deployment_environment_contract.py` | `f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1` |
| Combined helper artifact | Manifest-derived | `00e0d8b618bb1249a6725fa3314f7a3f4495c12ef7654a8942528719d62ebe7f` |
| Root installer | `scripts/install_staging_next_helper_1_4_10.py` | `99742ae44ea8c82e37d1cd5f54d95039ccb32b81354ce482190b900b7e2233f6` |

The compatibility contract is `wpcaregrid-staging-next-deploy-v15`. Recalculate
these values from the final merged Git tree (LF bytes) before any installation; a
different source revision requires a new manifest and review.

## What changes relative to 1.4.8

* Two more root-owned, mode 0644 files are installed next to the helper
  (`deployment_target_binding.py`, `deployment_environment_contract.py`); the helper
  policy pins both (`target_binding_sha256`, `environment_contract_sha256`) and the combined
  artifact digest covers them.
* The privileged entrypoint executes the transaction module and the binding from the
  authenticated bytes and hands the binding the authenticated contract bytes; the privileged
  process does not read any of them from disk again.
* `_safe_profile`, the deploy/preflight lock, every backup path, the fence markers and the
  transaction journal are checked against the one compiled `staging-next` target. A path,
  backup, marker, lock or journal belonging to legacy staging, a symlink in any path
  component, a hardlinked or non-regular backup, wrong owner/mode on the checked leaf, or an
  unknown/`RECOVERY_REQUIRED` journal state is a fixed `target_binding_*` HOLD. Production
  cannot be selected. Owner/mode is checked on the leaf only (not ancestors), and the
  check-to-use gap is covered by the root-owned directory chain and `O_NOFOLLOW` opens.
* Two refusal codes change (both remain HOLD): a missing fence marker is now reported as
  `target_binding_path_missing` (was `phase_b_background_fence_marker_invalid`), and an unknown
  journal state as `target_binding_recovery_phase_unknown` (was
  `phase_b_transaction_state_requires_recovery`).
* The migration loop journals `DBA_HANDOFF_REQUIRED` at the exact pinned Phase 2B
  migration boundary. It will not invoke those migrations as the application account
  until a root-owned, transaction-bound receipt is validated and consumed. DBA grant
  revocation, session termination, and account locking are operator attestations in
  that receipt; the controller does not independently query those facts. Any missing
  or inconsistent attestation leaves the deployment paused.
* Policy file, sudoers file and Phase B policy/credential paths are versioned `1.4.10`.

## Scope of installation

Before a separately authorized Phase B migration, the shared staging-next
application `.env` must contain `DB_PHASE2B_TRIGGER_DEFINER` equal to the
durable `user@host` identity of all six installed guards. Helper 1.4.10 does not
preflight this Laravel setting; an absent or mismatched value fails closed when
the forward verifier migration runs. This note does not approve a definer
identity or authorize a migration.

The root installer is `scripts/install_staging_next_helper_1_4_10.py`. Place it
at `/home/deploy/.private/wpcaregrid-staging-next-helper-installer-1.4.10.py`.
Place exactly the five pinned source artifacts (no extra files or links) in
`/home/deploy/.private/wpcg-staging-next-helper-1.4.10/`, owned by `deploy:deploy`,
directory mode `0700`, file mode `0600`.

On success, the installer creates only these absent targets:

* `/usr/local/libexec/wpcaregrid-deploy/staging-next/1.4.10/` with the pinned
  entrypoint, backend, transaction module, target binding and environment contract.
* `/etc/wpcaregrid-deploy/profiles/staging-next-1.4.10.json`.
* `/etc/sudoers.d/wpcaregrid-staging-next-1.4.10` with exact operation and
  argument restrictions for `deploy-staging-next`.

The Phase B policy file must remain absent. Any existing 1.4.10 destination or
unexpected source file causes a fail-closed stop. A previously installed 1.4.9
helper is not overwritten; its versioned files and sudoers rule remain intact
for a separately controlled rollback. Publication
uses same-filesystem atomic hard links and never replaces a target. On an
installation failure, rollback removes only files whose inode, owner, group,
mode and content still match objects created by that invocation. An identity
mismatch leaves the object untouched and reports `ROLLBACK=HOLD` for manual
review.

## Root-console procedure (Owner GO required)

From a root console on `stg1.wpcaregrid.com`, run this single command in a
subshell so failure does not close the console:

```sh
( set -eu; i=/home/deploy/.private/wpcaregrid-staging-next-helper-installer-1.4.10.py; test -f "$i"; test ! -L "$i"; test "$(stat -c '%U:%G:%a' "$i")" = deploy:deploy:600; test "$(sha256sum "$i" | cut -d' ' -f1)" = 99742ae44ea8c82e37d1cd5f54d95039ccb32b81354ce482190b900b7e2233f6; exec /usr/bin/python3 -I "$i" )
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
5. Separate Owner GO per later step: Phase B policy and credentials (1.4.10 paths), MySQL
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
