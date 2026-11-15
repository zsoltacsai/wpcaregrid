# Production deployment helper 2.1.0 — V3 release-review successor

Status: **PREPARATION ONLY**. This procedure does not authorize host changes,
production activation, application deployment, database work, or public traffic.
Nothing described here has been installed on any host.

## What this is

Helper **2.1.0** is the successor of production helper 2.0.0
(`docs/PRODUCTION_HELPER_INSTALL_2_0_0.md`). The 2.0.0 source files, manifest,
sudoers template, installer and runbook are **unchanged and byte-identical**;
an installed 2.0.0 is never edited or replaced in place. 2.1.0 is a new,
separately versioned artifact set with its own install paths.

The single behavioural change is release-review admission. 2.0.0 required two
non-author GitHub `APPROVED` reviews plus `WPCG_RELEASE_REVIEW_V2` ledger
records. That contract was retired by Owner decision
`DEC-2026-11-09-SOLO-OWNER-REVIEW-001` (`docs/RELEASE_REVIEW_POLICY.md`). 2.1.0
admits a release only through `verify_agent_release_reviews` of
`scripts/release_review_policy_v3.py`.

### Unchanged (kept exactly)

* Dedicated `deploy-production` caller identity, `SUDO_UID`/policy UID match, root requirement.
* Five fixed operations: `authorize`, `deploy`, `preflight`, `stage`, `verify-install`.
* Hash-pinned entrypoint, backend, transaction module and environment contract; exact policy schema; pinned Phase B policy digest.
* Protected-primary latest-SHA check, exact merged-PR check, exact source-tree match,
  required CI on the exact PR head and on the primary push, artifact/manifest binding,
  backup, restore rehearsal, fences, migrations, acceptance and receipts.
* `deployment_transaction_v2.py` and `deployment_environment_contract.py` are the **same
  bytes** as in 2.0.0 (identical digests below), so the Phase B policy/credential file
  names remain those fixed by that module (`production-phase-b-2.0.0.json`,
  `production-database-2.0.0.json`). Those names belong to the transaction module's
  contract, not to the helper version; both stay absent at install time.

### Changed

| Area | 2.0.0 | 2.1.0 |
| --- | --- | --- |
| Review evidence | >= 2 non-author GitHub `APPROVED` reviews on the exact head, plus V2 ledger records (`m17_appsec`, review IDs) | Owner-authored `WPCG_RELEASE_REVIEW_V3` ledger records only; no GitHub review object is read |
| Roles | `integration_qa`, `m17_appsec` | `integration_qa`, `appsec` |
| Agent identity | GitHub reviewer logins | `agent=` labels (`[a-z0-9][a-z0-9._-]{2,63}`), distinct per role |
| Decision | `PASS` / `CHANGES_REQUIRED` | `PASS` / `CHANGES_REQUIRED` / `BLOCKED`; latest record per role decides |
| Ledger read | reviews list + ledger comments, 20 x 100 | ledger only, full pages of 100, count checked before and after the read |
| `_github_json` path guard | rejected the exact repository path | accepts `/repos/zsoltacsai/wpcaregrid` and paths below it |
| Helper contract id | `wpcaregrid-deployment-profile-v2` | `wpcaregrid-deployment-profile-v2.1` |
| Install paths | `.../production/2.0.0/`, `production-v2.0.0.json`, `wpcaregrid-production-v2.0.0` | `.../production/2.1.0/`, `production-v2.1.0.json`, `wpcaregrid-production-v2.1.0` |
| Pinned artifacts | 4 helper files | 5 helper files: adds `release_review_policy_v3.py` (own pin in the policy schema and in the combined artifact digest) |

The `_github_json` guard fix is required: 2.0.0 rejected the repository
resource path, so the repository-owner lookup (needed to attribute OWNER-authored
records) could never succeed.

## V3 admission rules (enforced by the privileged helper)

For the merged PR that produced the candidate primary SHA, taking the PR's **exact head
SHA** from GitHub, ledger issue #174 must contain, as comments by the repository owner
with `author_association=OWNER`:

```
WPCG_RELEASE_REVIEW_V3
role=integration_qa|appsec
pr=<number>
head_sha=<40 hex>
agent=<agent label>
reviewer_kind=ai_agent
decision=PASS|CHANGES_REQUIRED|BLOCKED
evidence_sha256=<64 hex>
```

* Exact PR and exact head: records for another PR or head are never reused; any new PR
  commit invalidates earlier evidence.
* Latest record per role decides; both roles must be `PASS`; agent labels must differ.
* A malformed `WPCG_RELEASE_REVIEW_V3` record by the owner (or with a non-`OWNER`
  association) fails closed, even when valid PASS records exist. Records by other users are
  ignored. V2 records do not count.
* The only tolerated malformed records are the exact, Owner-invalidated legacy records
  allowlisted in the policy module (PR #377); nothing else is normalised.
* An incomplete or changing ledger (comment count differs from comments read, before or
  after the read; more than 5000 comments) is `HOLD`, never evidence.
* For each accepted role, the helper also reads the Owner-authored
  `WPCG_RELEASE_REVIEW_ARTIFACT_V3` comment, decodes its report bytes, verifies its SHA-256,
  and requires its role, agent, PR, head and base to match the V3 record and merged PR.

Stable refusal codes returned on `HOLD`: `review_subject_invalid`,
`review_evidence_invalid`, `review_evidence_ambiguous`, `review_correction_invalid`,
`review_correction_target_invalid`, `review_correction_not_append_only`,
`review_correction_subject_mismatch`, `review_correction_ambiguous`,
`review_record_invalid`, `review_evidence_missing`, `review_not_pass`,
`review_roles_not_separate`, plus ledger codes `release_review_ledger_unavailable`,
`release_review_ledger_invalid`, `release_review_ledger_changed`,
`release_review_ledger_incomplete` and `release_review_authority_unavailable`.
Any other policy failure is the fixed code `review_evidence_invalid`.

An agent `PASS` never authorizes a host operation; installation, activation and every
production deployment remain separate Owner decisions.

## Pinned source

Base: protected primary `7ba182229560927dd9cb20ce9db82aa302e47255` (after PR #399).
The source commit for installation is the protected-primary merge commit of the
successor pull request. Recompute every digest from that exact Git tree immediately
before source staging; the installer authenticates source bytes by these SHA-256 pins,
not by directory name.

| Source file | SHA-256 |
| --- | --- |
| `scripts/wpcaregrid-deploy-production-priv-2_1_0.py` | `c303a4d84a282ffc7211bc8781a5442e62cb90deeef4651b450113ad7a912f30` |
| `scripts/deployment_backend_v2_1_0.py` | `c820872fdee721716278a66109045c7dc994bfdc0f8dc4de1312480799ad63b2` |
| `scripts/deployment_transaction_v2.py` (identical to 2.0.0) | `ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00` |
| `scripts/deployment_environment_contract.py` (identical to 2.0.0) | `f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1` |
| `scripts/release_review_policy_v3.py` | `1961bea6ff4fdf6ff116c5a454ed04c878e4b628ac5260ffe89073b512ccc792` |
| Combined helper artifact (derived) | `283350d3160d016c6e4fb55577b6e5b8d5d47fbcd4e099d6ee11bb25b074dda7` |
| `scripts/production_deploy_controller_2_1_0.py` | `0cea983a4aa4ff6d733e404885abe1b7d5b836071d8a68155c55fd41ca3ab867` |
| `deploy/sudoers/wpcaregrid-production-v2.1.0` | `33f82b453161adde3ab231e9dfa1e7eae71e1ac69d88d0ed9b326b1af03aa105` |
| Root installer `scripts/install_production_helper_2_1_0.py` | `63fd84b9b1b7a397f0eeeb2300a353f68e325e843eb37f6fc4fede964757d0ad` |

The combined helper artifact digest is the SHA-256 of the canonical JSON
(`sort_keys`, compact separators) of `schema=1`, `helper_version`,
`compatibility_contract` and the five helper file digests; it is recorded in
`deploy/release-manifests/production-deployment-helper-2.1.0.json`.

`release_review_policy_v3.py` is shared with the repository's release admission
tooling. Any change to it (for example a new Owner correction allowlist entry) changes
its digest and therefore requires a new helper version and manifest; it is never edited
under an installed helper.

The six helper/controller/policy-template inputs plus the policy module (seven files)
are staged under `/home/deploy/.private/wpcg-production-helper-2.1.0/`, owned by
`deploy:deploy`, directory mode `0700`, files mode `0600`. The root installer is a
separate file at
`/home/deploy/.private/wpcg-production-helper-installer-2.1.0/install_production_helper_2_1_0.py`,
owned by `deploy:deploy`, mode `0600`. Never add the installer to the source directory and
never execute it directly from that deploy-owned path as root.

## Installation effect, if separately authorized

Same effect and safety model as 2.0.0: requires root and a pre-existing
`deploy-production` identity (UID distinct from `deploy` and `deploy-staging-next`); creates
no accounts, databases, application directories, services or credentials; never
overwrites; complete exact installation is read-only verified as `NO_OP`; partial or
mismatched state is `HOLD`. It may create only the absent root-owned `0755` directories
`/usr/local/libexec/wpcaregrid-deploy/production/` and `.../production/2.1.0/`, and installs:

* entrypoint `wpcaregrid-deploy-production-priv` (`0755`)
* `deployment_backend_v2_1_0.py`, `deployment_transaction_v2.py`,
  `deployment_environment_contract.py`, `release_review_policy_v3.py`,
  `production_deploy_controller.py` (the 2.1.0 controller) and the disabled policy
  `/etc/wpcaregrid-deploy/profiles/production-v2.1.0.json` (`0644`)
* sudoers `/etc/sudoers.d/wpcaregrid-production-v2.1.0` (`0440`), last

The profile has `enabled=false`, all environment values unset, and no Phase B policy or
credentials; the helper refuses every production operation until a separately reviewed
and authorized environment policy is installed.

### Coexistence with 2.0.0

The installer **refuses** (`legacy_production_helper_2_0_0_present`) while any of these
exists: `/usr/local/libexec/wpcaregrid-deploy/production/2.0.0`,
`/etc/wpcaregrid-deploy/profiles/production-v2.0.0.json`,
`/etc/sudoers.d/wpcaregrid-production-v2.0.0`. It also keeps the 2.0.0 requirement that
`deploy-production` has no pre-existing sudo rules. Since the immutable 2.0.0 installer
cannot detect a later 2.1.0 installation, the Owner/operator must also prohibit running
the 2.0.0 installer after 2.1.0 is installed and verify that the 2.0.0 paths remain absent
at every host preflight. This successor does not edit or delete 2.0.0; any removal is a
separate Owner-approved action.

### Release artifacts and the controller

The 2.1.0 controller (`production_deploy_controller.py` in the versioned directory)
calls only the 2.1.0 helper and packages artifacts whose manifest binds
`helper_version=2.1.0`, `helper_contract=wpcaregrid-deployment-profile-v2.1` and the combined
artifact digest above. An artifact built for 2.0.0 is refused by 2.1.0 and vice versa.
It still requires the separate clean `deploy-production`-owned source checkout and incoming
directory described in the 2.0.0 runbook.

### Command sequence for a separately authorized root console

```sh
(
  set -eu
  src=/home/deploy/.private/wpcg-production-helper-installer-2.1.0/install_production_helper_2_1_0.py
  expected=63fd84b9b1b7a397f0eeeb2300a353f68e325e843eb37f6fc4fede964757d0ad
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
`INSTALL=NO_OP;VERIFY=PASS`. On failure the installer rolls back only objects it created
and only when each still matches its recorded identity; `ROLLBACK=HOLD` means stop and
retain state for review. The installer never calls `preflight`, `verify-install`,
`authorize`, `stage` or `deploy`. No installation has occurred as part of this source change.

## Gates that remain (none are satisfied by this change)

1. Independent exact-head QA and AppSec agent PASS records for the successor pull request.
2. Merge into the protected primary and exact merge-SHA CI by the Owner's process.
3. Owner approval to install helper 2.1.0 on a specific host, and Owner approval for what
   happens to any installed 2.0.0.
4. Provisioning of the `deploy-production` identity, the controller source checkout and
   incoming directory, and the separately reviewed environment policy + Phase B policy,
   recovery evidence and database isolation (unchanged production gates).
5. A real-host rehearsal of `verify-install` and `preflight` before any `authorize`.
6. Owner approval of every production deployment. Not release-ready.

## Known limitations

* The helper calls the GitHub API unauthenticated (its environment is cleared), so GitHub's
  anonymous per-IP quota applies. With the ledger at ~1.3k comments, each full admission
  uses about 29 API requests; `sync` performs two admissions (`authorize` and `deploy`),
  for roughly 58 requests. This is close to the 60-request hourly limit and can be exhausted
  by other requests from the same IP. On `github_evidence_unavailable`, stop and retry only
  after the rate-limit window resets; do not treat the HOLD as authorization.
* The ledger is fixed to issue #174 and capped at 5000 comments; beyond that the helper
  holds until a new helper version raises or changes the bound.
