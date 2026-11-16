# staging-next helper 1.4.10 - operator readiness status

**Status: HOLD. This runbook is not an executable staging or installation procedure.**
The `staging-149-*` preflight and source-staging tools are pinned to the historical
1.4.9 helper and must not be used for 1.4.10. No 1.4.10-specific host preflight
or source-staging utility has been implemented or reviewed. Do not copy files to
the staging host or run the 1.4.10 installer by following this document.

The hashes below identify the current source candidate only. They are not a
host observation, install authorization, deployment authorization, or proof that
the staging layout matches the helper contract. A future executable procedure
must use versioned 1.4.10 preflight and source-staging tooling, be independently
reviewed, and receive its required Owner GO. Nothing here has been run on a host.

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

## Host operations: HOLD

### Preflight

**Not available for 1.4.10.** `scripts/staging-149-preflight.py` is historical
and does not verify the complete 1.4.10 install contract. Do not use it to infer
1.4.10 readiness. No host facts are claimed by this runbook.

### Source staging

**Not available for 1.4.10.** The historical `staging-149-stage-sources.sh`
and `staging-149-stage-on-host.py` are pinned to the 1.4.9 source artifacts.
They are explicitly prohibited for this candidate. There is currently no
reviewed, exact-SHA 1.4.10 staging path. Therefore there is no command to run
for source staging; do not substitute the old scripts or manually improvise a
transfer procedure.

### Installation and verification

**HOLD.** The root installer procedure in
`docs/STAGING_NEXT_HELPER_1_4_10_INSTALL.md` assumes its installer and five
pinned source files have already been safely staged. Because the 1.4.10 staging
step is unavailable, do not run that procedure. This runbook's rollback section
records intended recovery boundaries only; it does not establish an installation
or authorize host changes.

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
