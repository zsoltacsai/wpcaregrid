# Deployment target binding

`scripts/deployment_target_binding.py` is a pure, fail-closed policy module for
the staging deployment and recovery tooling. It is source-only: nothing in it is
installed on a host, and no existing helper imports it yet.

## Current bindings (inventory)

| Item | `legacy-staging` | `staging-next` |
| --- | --- | --- |
| Application | `/var/www/wpcaregrid` | `/var/www/wpcaregrid-staging-new` |
| Database | `wpcaregrid` | `wpcg_staging_next` |
| Markers | `/var/lib/wpcaregrid/{deploy-maintenance,workers-disabled,scheduler-paused}` | `/var/lib/wpcaregrid-deploy/staging-next/state/{maintenance,workers-disabled,scheduler-paused}` |
| Lock | `/home/deploy/private-backups/.deploy.lock`, `/var/lib/wpcaregrid/scheduler.lock` | `/var/lib/wpcaregrid-deploy/staging-next/deploy.lock` |
| Backups | `/home/deploy/private-backups/wpcaregrid-pre-{deploy,recovery}-*.sql.gz` | `/var/lib/wpcaregrid-deploy/staging-next/database-backups/staging-next-<sha>-*.sql.gz` |

`staging-next` values come from the compiled `STAGING_NEXT` environment contract
(`scripts/deployment_environment_contract.py`), not a second copy. The legacy
values mirror `recover-staging-deployment.py`, `deploy-staging.sh`,
`run-scheduler.sh` and `wpcaregrid-deploy-priv.py`; a test asserts they match.

## Rules

* Target is selected by exact name from a closed registry; unknown, missing,
  differently-cased or non-string names are refused.
* `production` is never selectable here (`production_target_not_selectable`).
* The application directory and database are one bound pair
  (`validate_pair`); a value belonging to the other target is
  `target_pair_cross_environment`.
* Callers cannot supply paths. `check_path` refuses non-normalised, relative,
  traversal, NUL, outside-target and other-target paths, any symlink component,
  and (with `expected_uid`) wrong owner or group/other-writable mode.
* Backups must be in the target's backup root and match its name pattern;
  another target's backup is `backup_belongs_to_other_target`.
* A marker/journal document must name its own target.
* Fences are checked per target; the other target's fences never count.
* `recovery_decision` classifies an interrupted deploy deterministically: only
  an in-flight phase with maintenance gate and both fences still in place is
  `recover`; anything else is a STOP.

## Known limits

* Ownership/mode checks (`expected_uid`) apply to the leaf only; ancestor
  directory permissions and check-to-use races (TOCTOU) remain the caller's
  responsibility (open with `O_NOFOLLOW`, re-verify after open).
* Marker and lock kinds match exact files only. Backups must be regular,
  single-link files. Target objects must be the registered instances.

## Not done / needs separate approval

Wiring the module into `wpcaregrid-deploy-priv.py`, `recover-staging-deployment.py`
or a new helper version changes pinned helper bytes and release manifests and
requires an Owner-approved helper release and host install. This PR changes none
of that and performs no host operation.
