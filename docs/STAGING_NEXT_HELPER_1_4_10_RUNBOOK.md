# staging-next helper 1.4.10 - operator readiness status

**Status: HOST HOLD.** Version-specific 1.4.10 source-staging tooling is now in
the source tree, but this does not establish that the staging host passes its
identity preflight or authorize installation/deployment. The `staging-149-*`
tools are pinned to 1.4.9 and must not be used for 1.4.10.

The hashes below identify the 1.4.10 source artifacts. They are not a host
observation, install authorization, deployment authorization, or proof that the
staging layout matches the helper contract. Nothing in this change has been run
on a host.

## GO-8.3 source candidate pins (recalculate from the final tree)

| File | SHA-256 |
| --- | --- |
| `scripts/wpcaregrid-staging-next-deploy-priv-1_4_10.py` | `893b77919aa87c1103ee0ecc54bd433a376e31cc85b797638618ed13e9344d65` |
| `scripts/staging_next_deploy_backend_1_4_10.py` | `0857a9d81c5d368da634bdab846accd40dd24f296057d4234706b5b4e52ca66f` |
| `scripts/staging_next_db_transaction_1_4_10.py` | `d1a382728180526ae6a0b27f70abc5e42b512fb389eb8339b9c2c544c7567848` |
| `scripts/deployment_target_binding_1_4_10.py` | `e985227ea1307b5d9ff5b737cf9b3586fe5ebbd7c7b194632078237a8c732e16` |
| `scripts/deployment_environment_contract.py` | `f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1` |
| `scripts/install_staging_next_helper_1_4_10.py` (installer) | `f58c9c556ae8b52a793cd818c023253c9c20a69dd8b2dd612181ad8c8fefbe3c` |
| helper artifact digest (printed by the installer/probe) | `f5a8e7e468af2b0950e2dfc3d5ed9f0c3b6baeb9759ba71866bc41cb4f6717b8` |

## Exact-source staging

[`scripts/staging-next-helper-1-4-10-stage-sources.sh`](../scripts/staging-next-helper-1-4-10-stage-sources.sh)
exports the six pinned files from exact primary commit
`8ff594bd7ca4e8e6963923c49b605bfcdc24d7ab`, checks their SHA-256 values locally,
and sends a framed payload over the existing `caregrid-staging` SSH alias. SSH
host-key checking is required. The remote program refuses root and accepts only
the `deploy` login. It writes only the versioned source directory and installer
under `/home/deploy/.private`, using create-only publication and independent
read-back. The driver refuses any SSH alias except `caregrid-staging`, verifies
its own tracked bytes against its current Git commit, and requires
`EXPECTED_TOOL_COMMIT` to equal the checked-out full commit SHA. The operator
must take that value from the reviewed merge SHA recorded in issue #174; never
use a worktree commit or an arbitrary revision. Run the transfer only after
this tool is merged into protected primary. It does not invoke the root
installer or change the application, database, helpers, sudoers, services, or
`/var/lib` state.

Run first in a repository clone containing the pinned commit:

```sh
EXPECTED_TOOL_COMMIT=<reviewed-protected-primary-merge-sha> \
  MODE=check scripts/staging-next-helper-1-4-10-stage-sources.sh
```

Only after exact-source review and the source-transfer target identity checks
pass, the separately authorized source transfer is:

```sh
EXPECTED_TOOL_COMMIT=<reviewed-protected-primary-merge-sha> \
  MODE=stage scripts/staging-next-helper-1-4-10-stage-sources.sh
```

If staging is interrupted or a post-write check fails, the tool does not
delete by pathname. It reports `ROLLBACK=HOLD;NO_AUTOMATIC_DELETE` and leaves
the inert, versioned objects for inspection. Do not retry or remove them until
an operator has reviewed their exact owner, mode, inode and hashes and follows
a separately approved cleanup step.

The remote precondition checks only the existing staging application directory,
root-owned profile directory, and deploy user's source-staging destination. It
does not check or create `/var/lib/wpcaregrid-deploy`; source transfer is
separate from runtime layout provisioning. The Owner-reported absence of
`/var/lib/wpcaregrid-deploy` therefore remains a **root installation STOP**,
not a reason to recreate or populate that path during source transfer.

## Host operations: HOLD

### Preflight

The source-staging script performs only read-only checks of the existing app,
profile, and source-staging identities. It is not the helper's runtime preflight.
Do not use `scripts/staging-149-preflight.py` to infer 1.4.10 readiness.

### Source staging

If the exact-source staging precondition returns anything other than
`STAGING_1410_STAGE=CHECK_PASS` or `STAGING_1410_STAGE=PASS`, stop. Do not
substitute the historical 1.4.9 tools or manually transfer files.

### Installation and verification

**HOLD.** The root installer procedure in
`docs/STAGING_NEXT_HELPER_1_4_10_INSTALL.md` assumes its installer and five
pinned source files have already been safely staged. Do not run that procedure
unless the 1.4.10 source-staging step returned `STAGING_1410_STAGE=PASS` and a
separate root-authorized runtime-layout acceptance also passed.

Important limitation: the installer's `verify-install` probe confirms the
installed helper's profile/caller and artifact identity; it does not validate
that `/var/lib/wpcaregrid-deploy/staging-next` and its release/state roots
exist. Its deployment `preflight` does inspect those roots but may reconcile an
interrupted release or create gates/receipts, so it is not an installation
probe. Therefore installation must remain HOLD until a separate reviewed,
root-authorized, read-only environment-layout check proves every required path
exists with the contract's owner/mode and the existing application/data
relationship is preserved. Never create missing roots ad hoc to make the helper
pass. Source-staging PASS does not satisfy this check or authorize installation.

## Rollback and STOP

* The installer rolls back its own newly created files if a step fails. A reported `ROLLBACK=HOLD` means an object
  no longer matched what it created: leave it untouched and report.
* After a successful install a rollback is a separate Owner decision: removing `1.4.10` means deleting the sudoers
  file first, then the policy file, then the five files in the version directory and finally the (then empty)
  version directory itself (all root-owned, all created by this installer);
  older helper versions and the legacy staging tools are untouched by this installation.
* **No 1.4.9 transition action is authorized while this runbook is on HOLD.** If a separate
  Owner GO later authorizes a reviewed 1.4.10 installation procedure, that procedure must
  specify how to disable the legacy Phase B policy and `wpcaregrid-staging-next-1.4.9` sudoers
  route while preserving the helper binaries. This document does not authorize or describe
  those host changes.
* If an existing release journal reaches `DBA_HANDOFF_REQUIRED`, do not switch the active
  controller back to 1.4.9: that older controller does not implement receipt consumption and
  cannot safely resume the journal. Keep traffic and background work fenced and escalate for an
  approved recovery procedure; this runbook does not provide one.
* STOP on: any hash mismatch, any preflight `STOP`, any unexpected file in the source directory, any non-`PASS`
  installer/probe line, an active deploy lock, fence marker, maintenance gate, running worker or scheduler.

## What this runbook does NOT authorize

Phase B policy and credentials, MySQL isolation (G1 is a separate runbook, PR #331), staging database
creation, backup/restore rehearsal, worker or scheduler start, `preflight`/`stage`/`deploy` of the helper, any
production action. Each needs its own Owner GO.
