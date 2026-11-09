# staging-next helper 1.4.9 - Owner runbook (preflight, staging, install, verify, rollback)

Source: merge commit `49234694f062168a27b471fc43774409e6c4219a` (PR #333). Companion documents:
`docs/STAGING_NEXT_HELPER_1_4_9_INSTALL.md` (what the installer does) and
`scripts/staging-149-preflight.py` (read-only host check). **Nothing here has been run on a host.**
Every step below that changes the host is behind its own Owner GO; steps 0-1 are read-only.

## Pinned values (LF bytes of the merge commit)

| File | SHA-256 |
| --- | --- |
| `scripts/wpcaregrid-staging-next-deploy-priv-1_4_9.py` | `7b4fda009e1d098b7886866b6b0cafc4166dccf480c5cead595c89beabadd798` |
| `scripts/staging_next_deploy_backend_1_4_9.py` | `68bc80ad8a87b06e19a2f9be1a52dc50f80db25183f66a39b1936f8434b1975f` |
| `scripts/staging_next_db_transaction_1_4_9.py` | `809faa581019a9349eaae09ed18198f164781354ba5a89de55a858258dd7d290` |
| `scripts/deployment_target_binding.py` | `987220381d5ea5d02aea1571e7b150f3f9596b27ff56d4b764a4a9e1bbdbb910` |
| `scripts/deployment_environment_contract.py` | `f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1` |
| `scripts/install_staging_next_helper_1_4_9.py` (installer) | `d26120cb4f8066aef8a754315c1cd5a5252e2f69fcc338578b44694fe09558e5` |
| helper artifact digest (printed by the installer/probe) | `d88de19203770680e919000484b4d26f916ee03b906c5d8a55ff70d6887d229f` |

## Step 0 - read-only preflight (permitted now; no GO needed beyond the read-only authorization)

Run `scripts/staging-149-preflight.py` as root on `stg1.wpcaregrid.com` (`/usr/bin/python3 -I -B -` with the file on stdin, as in the hand-over block, or `python3 -I -B staging-149-preflight.py`).
Required result before anything else: `STAGING_149_PREFLIGHT=PASS`. Save the full output (it contains no
secrets) and the `legacy.fingerprint` value. `BLOCKED` = a prerequisite is missing (do not "fix" it ad hoc:
PHP 8.5, FPM socket, nginx route, application directory, installer directories and users each need their own
reviewed step); `STOP` = an unexpected or active state (existing 1.4.9 files, fence/lock/gate, workers,
unexpected sudoers rule, symlink or wrong ownership in the staging-next tree): stop and report.

Note: the preflight parses the nginx configuration files itself (it never runs `nginx`). The installer later
runs the authoritative `nginx -t`/`nginx -T` check; a preflight PASS therefore does not replace that check.

The preflight accepts exactly one pre-existing staging-next sudoers rule besides the 1.4.x forms: the helper 1.0.2
`preflight` rule (`deploy-staging-next ALL=(root) NOSETENV: NOPASSWD: .../staging-next/1.0.2/wpcaregrid-deploy-staging-next-priv preflight`,
either tag order - sudo tags are order-independent). It is accepted only while the installed 1.0.2 entrypoint and
profiles module match the audited sha256 pins (deploy/release-manifests/staging-next-helper.json), are root-owned with
modes 0755/0644, and their directory chain is root-owned and not group/other writable; that helper only performs a
read-only `preflight` (lstat of the pinned application directory). Any other, broader or modified rule is a STOP.

## Step 1+2 - Owner GO #1: stage the six pinned files (one command, run on the Owner workstation)

`scripts/staging-149-stage-sources.sh` (paste block in the hand-over) runs inside a clone of the repository. It
exports the six files from the pinned merge commit `49234694f062168a27b471fc43774409e6c4219a`, verifies every sha256
pin locally (STOP on any difference, before SSH is used), and streams them over SSH to a python program that runs on
the host **as the `deploy` login user** (it refuses root and any other user). The host program changes only
`/home/deploy/.private`: it creates the directory if absent (0700), writes the five sources into
`wpcg-staging-next-helper-1.4.9/` (0700, files 0600) and the installer as
`wpcaregrid-staging-next-helper-installer-1.4.9.py` (0600), create-only (an existing destination is a STOP), publishes
atomically, rolls back only its own objects on failure, re-reads and re-hashes everything, and prints no content.

* `SSH_TARGET` (default `caregrid-staging`) must log in as `deploy`; anything else is a STOP on the host.
* `MODE=check` verifies all preconditions and the payload on the host and writes **nothing**; run it first.
* Result line: `STAGING_149_STAGE=CHECK_PASS` / `PASS` / `STOP;CODE=...`.

Afterwards re-run the read-only root preflight: `source.staged` must be `REQUIRED_PRESENT` and the result `PASS`.

## Step 3 - Owner GO #2: root installation (privileged change: root-owned files + sudoers rule)

From a root console, the single command in `docs/STAGING_NEXT_HELPER_1_4_9_INSTALL.md` ("Root-console
procedure"). It creates only absent targets (`/usr/local/libexec/wpcaregrid-deploy/staging-next/1.4.9/` with five
files, `/etc/wpcaregrid-deploy/profiles/staging-next-1.4.9.json`, `/etc/sudoers.d/wpcaregrid-staging-next-1.4.9`),
validates sudoers before publishing it, and rolls back what it created on any failure.

## Step 4 - verification (read-only)

Accept only: `INSTALL=PASS`, the artifact digest above, `NEGATIVE_AUTHORIZATION=PASS`, `INSTALLATION_PROBE=PASS`,
`PHASE_B_POLICY=DISABLED`. Then read back owner/mode/hash of all five installed files (root:root, `0755` for the
entrypoint, `0644` for the others), `visudo -c`, and re-run the preflight: all `destination.*` lines now report
`MISMATCH` (the files exist) and the result is `STOP;CODE=destination_exists_helper_dir` - that is the expected,
safe post-install state. `legacy.fingerprint` must equal the Step 0 value. `verify-install` is a read-only identity
probe; it also evaluates the target binding: a `target_binding_*` code means the host layout differs from the
compiled `staging-next` contract - STOP and report, do not edit the layout to satisfy it.

## Rollback and STOP

* The installer rolls back its own newly created files if a step fails. A reported `ROLLBACK=HOLD` means an object
  no longer matched what it created: leave it untouched and report.
* After a successful install a rollback is a separate Owner decision: removing `1.4.9` means deleting the sudoers
  file first, then the policy file, then the five files in the version directory and finally the (then empty)
  version directory itself (all root-owned, all created by this installer);
  older helper versions and the legacy staging tools are untouched by this installation.
* STOP on: any hash mismatch, any preflight `STOP`, any unexpected file in the source directory, any non-`PASS`
  installer/probe line, an active deploy lock, fence marker, maintenance gate, running worker or scheduler.

## What this runbook does NOT authorize

Phase B policy and credentials, MySQL isolation (G1 is a separate runbook, PR #331), staging database
creation, backup/restore rehearsal, worker or scheduler start, `preflight`/`stage`/`deploy` of the helper, any
production action. Each needs its own Owner GO.
