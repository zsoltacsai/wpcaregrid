# Production deployment helper 2.1.1 — corrected V3 review-policy successor

Status: **PREPARATION ONLY**. This procedure does not authorize host changes,
production activation, application deployment, database work, or public traffic.
Nothing described here has been installed on any host.

## What this is

Helper **2.1.1** is the successor of production helper 2.1.0
(`docs/PRODUCTION_HELPER_INSTALL_2_1_0.md`), which itself succeeded 2.0.0. Every 2.1.0
artifact — entrypoint, backend, controller, sudoers template, root installer, manifest and
runbook — is **unchanged and byte-identical**, and 2.1.0 is **superseded, not replaced in
place**. 2.1.1 is a new, separately versioned artifact set with its own install paths.

2.1.0 pins `scripts/release_review_policy_v3.py` at SHA-256
`1961bea6ff4fdf6ff116c5a454ed04c878e4b628ac5260ffe89073b512ccc792`. That policy module has since
been corrected (exact Owner-ledger correction allowlist for six malformed review records,
PR #410), so its bytes and digest changed. A privileged helper must never be installed from
bytes that differ from what its manifest pins, and a pinned file must never be edited under
an installed helper. 2.1.1 therefore pins the corrected policy bytes
(`e50785233d46d7d03e3f734a15fc64764ab85196482789db0c0157cb4307c9db`) and carries a new
combined artifact digest. **2.1.0 must not be installed**: its installer can no longer
authenticate its policy source from the current repository tree.

The 2.1.0 historical pin remains independently verifiable: the exact policy bytes are kept as
`deploy/release-snapshots/release_review_policy_v3-helper-2.1.0.py.snapshot` (immutable; not
Python source, never imported by any helper) and equal Git blob
`f2f971fb622ca86022a7d9e0242a4f0a364f2fd0` of commit `063419d0631c9e42b9162fc7b9015641d099a67b`.

### Unchanged (kept exactly)

* Everything listed as unchanged for 2.1.0: dedicated `deploy-production` identity, the five
  fixed operations (`authorize`, `deploy`, `preflight`, `stage`, `verify-install`), pinned
  files and exact policy schema, protected-primary/merged-PR/CI/backup/restore/fence/migration
  controls, V3 admission rules and refusal codes, GitHub API/ledger limits.
* Backend, entrypoint and controller code differ from 2.1.0 only in the helper version, the
  versioned install paths and documentation text. The authorization logic is the same.
* `deployment_transaction_v2.py` and `deployment_environment_contract.py` are the same bytes as
  in 2.0.0 and 2.1.0. The Phase B policy and database credential names remain
  `production-phase-b-2.0.0.json` and `production-database-2.0.0.json` (the transaction
  module's contract, not the helper version); both stay absent at install time.
* Helper contract id `wpcaregrid-deployment-profile-v2.1`.

### Changed

| Area | 2.1.0 | 2.1.1 |
| --- | --- | --- |
| Helper version | `2.1.0` | `2.1.1` |
| `release_review_policy_v3.py` pin | `1961bea6…c792` | `e5078523…07db` (corrected allowlist) |
| Combined artifact digest | `283350d3160d016c6e4fb55577b6e5b8d5d47fbcd4e099d6ee11bb25b074dda7` | below |
| Install paths | `.../production/2.1.0/`, `production-v2.1.0.json`, `wpcaregrid-production-v2.1.0` | `.../production/2.1.1/`, `production-v2.1.1.json`, `wpcaregrid-production-v2.1.1` |
| Installer legacy check | refuses 2.0.0 artifacts | refuses 2.0.0 **and** 2.1.0 artifacts (`legacy_production_helper_present`) |

An artifact built for 2.1.0 is refused by 2.1.1 and vice versa (different helper version and
combined artifact digest).

## Pinned source

Base: PR #410 head `744b55225af5f7b063c9817ffd2aef25b6b9d6de`. The source commit for
installation is the protected-primary merge commit of the successor pull request. Recompute
every digest from that exact Git tree immediately before source staging; the installer
authenticates source bytes by these SHA-256 pins, not by directory name.

| Source file | SHA-256 |
| --- | --- |
| `scripts/wpcaregrid-deploy-production-priv-2_1_1.py` | `dbdd262380dc1cde619ac56a67a3014ae8b8a48ab0a5334259217e6feab67a57` |
| `scripts/deployment_backend_v2_1_1.py` | `c4e0f058fb30baef09f137f2c1a5a97d80bf8ace560dc8581b9f8c648409a094` |
| `scripts/deployment_transaction_v2.py` (identical to 2.0.0/2.1.0) | `ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00` |
| `scripts/deployment_environment_contract.py` (identical to 2.0.0/2.1.0) | `f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1` |
| `scripts/release_review_policy_v3.py` | `e50785233d46d7d03e3f734a15fc64764ab85196482789db0c0157cb4307c9db` |
| Combined helper artifact (derived) | `eaf083c286b441c2a538b4627debccd008ce5cec28bb9b79da025a4ca8ac3b2d` |
| `scripts/production_deploy_controller_2_1_1.py` | `f14e077572927f360d50e07d4dab03a1220f6d3758bc35bf9b7799a2f544b575` |
| `deploy/sudoers/wpcaregrid-production-v2.1.1` | `88a3d346b34b4353791a2594d5e445840bd0ae87ae9e151305d9c436f1d419a4` |
| Root installer `scripts/install_production_helper_2_1_1.py` | `a0c721f4f5facd6fea3369a509063cf0dc596e4bd41335a3b5d369eb44485524` |

The combined helper artifact digest is the SHA-256 of the canonical JSON
(`sort_keys`, compact separators) of `schema=1`, `helper_version`,
`compatibility_contract` and the five helper file digests; it is recorded in
`deploy/release-manifests/production-deployment-helper-2.1.1.json`.

`release_review_policy_v3.py` is shared with the repository's release admission tooling.
Any further change to it (for example another Owner correction allowlist entry) changes its
digest and therefore requires a new helper version (2.1.2 or later) and manifest; it is never
edited under an installed helper. The 2.1.1 tests deliberately fail when the live policy
source no longer matches the 2.1.1 pin.

The seven source files are staged under
`/home/deploy/.private/wpcg-production-helper-2.1.1/`, owned by `deploy:deploy`, directory
mode `0700`, files mode `0600`. The root installer is a separate file at
`/home/deploy/.private/wpcg-production-helper-installer-2.1.1/install_production_helper_2_1_1.py`,
owned by `deploy:deploy`, mode `0600`. Never add the installer to the source directory and
never execute it directly from that deploy-owned path as root.

## Installation effect, if separately authorized

Same effect and safety model as 2.1.0: requires root and a pre-existing `deploy-production`
identity (UID distinct from `deploy` and `deploy-staging-next`); creates no accounts,
databases, application directories, services or credentials; never overwrites; a complete
exact installation is read-only verified as `NO_OP`; partial or mismatched state is `HOLD`.
It may create only the absent root-owned `0755` directories
`/usr/local/libexec/wpcaregrid-deploy/production/` and `.../production/2.1.1/`, and installs:

* entrypoint `wpcaregrid-deploy-production-priv` (`0755`)
* `deployment_backend_v2_1_1.py`, `deployment_transaction_v2.py`,
  `deployment_environment_contract.py`, `release_review_policy_v3.py`,
  `production_deploy_controller.py` (the 2.1.1 controller) and the disabled policy
  `/etc/wpcaregrid-deploy/profiles/production-v2.1.1.json` (`0644`)
* sudoers `/etc/sudoers.d/wpcaregrid-production-v2.1.1` (`0440`), last

The profile has `enabled=false`, all environment values unset, and no Phase B policy or
credentials; the helper refuses every production operation until a separately reviewed and
authorized environment policy is installed.

### Coexistence with 2.0.0 and 2.1.0

The installer **refuses** (`legacy_production_helper_present`) while any of these exists:
`/usr/local/libexec/wpcaregrid-deploy/production/2.0.0`,
`/etc/wpcaregrid-deploy/profiles/production-v2.0.0.json`,
`/etc/sudoers.d/wpcaregrid-production-v2.0.0`,
`/usr/local/libexec/wpcaregrid-deploy/production/2.1.0`,
`/etc/wpcaregrid-deploy/profiles/production-v2.1.0.json`,
`/etc/sudoers.d/wpcaregrid-production-v2.1.0`. The immutable 2.0.0 and 2.1.0 installers cannot
detect a later 2.1.1 installation, so the Owner/operator must prohibit running either of them
after 2.1.1 is installed and verify at every host preflight that the 2.0.0 and 2.1.0 paths
remain absent. This successor does not edit or delete either; any removal is a separate
Owner-approved action.

### Release artifacts and the controller

The 2.1.1 controller (`production_deploy_controller.py` in the versioned directory) calls only
the 2.1.1 helper and packages artifacts whose manifest binds `helper_version=2.1.1`,
`helper_contract=wpcaregrid-deployment-profile-v2.1` and the combined artifact digest above.
It still requires the separate clean `deploy-production`-owned source checkout and incoming
directory described in the 2.0.0 runbook.

### Command sequence for a separately authorized root console

```sh
(
  set -eu
  src=/home/deploy/.private/wpcg-production-helper-installer-2.1.1/install_production_helper_2_1_1.py
  expected=a0c721f4f5facd6fea3369a509063cf0dc596e4bd41335a3b5d369eb44485524
  d=$(/usr/bin/mktemp -d /root/wpcg-production-installer.XXXXXX)
  trap '/usr/bin/rm -f -- "$d/install.py"; /usr/bin/rmdir -- "$d"' EXIT
  test "$(/usr/bin/stat -c '%U:%G:%a' "$d")" = root:root:700
  test ! -e "$d/install.py" && test ! -L "$d/install.py"
  /usr/bin/install -o root -g root -m 700 -- "$src" "$d/install.py"
  test "$(/usr/bin/stat -c '%U:%G:%a' "$d/install.py")" = root:root:700
  test "$(/usr/bin/sha256sum "$d/install.py" | /usr/bin/cut -d' ' -f1)" = "$expected"
  /usr/bin/python3 -I "$d/install.py"
)
```

Expected output: `INSTALL=PASS`, `PRODUCTION_PROFILE=DISABLED`, `PHASE_B_POLICY=DISABLED`,
`SUDOERS_ALLOWLIST=PASS`; a re-run on a complete exact installation prints
`INSTALL=NO_OP;VERIFY=PASS`. On failure the installer rolls back only objects it created and
only when each still matches its recorded identity; `ROLLBACK=HOLD` means stop and retain
state for review. The installer never calls `preflight`, `verify-install`, `authorize`,
`stage` or `deploy`. No installation has occurred as part of this source change.

## Gates that remain (none are satisfied by this change)

1. Independent exact-head QA and AppSec agent PASS records for the successor pull request
   (`WPCG_RELEASE_REVIEW_V3`, see `docs/RELEASE_REVIEW_POLICY.md`).
2. Merge into the protected primary and exact merge-SHA CI by the Owner's process.
3. Owner approval to install helper 2.1.1 on a specific host, and Owner approval for what
   happens to any installed 2.0.0.
4. Provisioning of the `deploy-production` identity, the controller source checkout and
   incoming directory, and the separately reviewed environment policy + Phase B policy,
   recovery evidence and database isolation (unchanged production gates).
5. A real-host rehearsal of `verify-install` and `preflight` before any `authorize`.
6. Owner approval of every production deployment. Not release-ready.
7. `.github/workflows/weekly-dependency-release.yml` and `docs/WEEKLY_DEPENDENCY_RELEASE.md`
   still name the 2.1.0 helper/controller path; re-pointing them to 2.1.1 is a separate,
   Owner-approved change and is **not** made here.

## Known limitations

Identical to 2.1.0: unauthenticated GitHub API quota (about 29 requests per admission, two
admissions per `sync`), and a ledger fixed to issue #174 capped at 5000 comments.
