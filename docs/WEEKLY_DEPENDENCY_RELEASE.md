# Weekly dependency and release management

This workflow extends the existing protected-primary and host deployment paths. It does not provide a second updater. The scheduled production job is dormant until the first marketing-only launch, recovery evidence and recurring-policy activation are recorded by the Owner.

## Budapest operating schedule

| Local schedule | Workflow phase | Gate and outcome |
|---|---|---|
| Friday 19:00 | Dependabot and advisory intake | Classifies open Dependabot PRs against open advisories. Critical/high fixes are marked for expedited protected PR handling. Unpatched critical/high alerts or unclassified security PRs produce HOLD. |
| Saturday 18:00 | Candidate admission and staging qualification | Requires the latest primary SHA, latest exact-SHA primary CI success, merged-PR provenance, exact-head V3 QA/AppSec artifacts, no open Dependabot PRs, and a fresh root-owned release-bound recovery receipt. Only then may the existing staging controller run. |
| Sunday 20:00 | Production promotion | Disabled unless the first launch is recorded and recurring production is explicitly enabled. It requires the exact staged SHA and successful deployment status, current recovery receipt, clean incident/lock gates, and the installed production helper 2.1.0. The helper still enforces its own admission and transaction controls. |
| Monday 08:00 | Owner handoff | Creates an issue with the exact primary SHA and CI result, latest staging/production deployment SHA and state, dependency intake, outstanding high/critical alerts, and recurring-policy state. |

Dependabot checks and Actions schedules use `Europe/Budapest`, so daylight-saving changes preserve the requested local wall-clock times. A `security-hotfix` dispatch runs the same intake, exact-SHA candidate admission and (when enabled) staging qualification outside the weekly schedule. An Owner-triggered production hotfix additionally requires `WPCG_SECURITY_HOTFIX_ENABLED=true`, completed first launch, fresh production recovery/rollback evidence, incident clearance and successful exact-SHA staging. It does not bypass protected PR reviews, required CI, V3 QA/AppSec or the installed updater.

## Candidate and deployment evidence

Candidate admission runs on protected primary before Saturday staging and is repeated on Sunday before production promotion. It verifies the latest completed CI run for that exact SHA. A later failed or in-progress run invalidates admission even if an earlier run passed. The candidate manifest records lockfile digests, merged-PR identity, V3 admission digest and CI/workflow run IDs; its checksum accompanies the artifact.

Staging executes only on the dedicated `wpcg-staging` self-hosted runner, as `deploy`, from `/var/www/wpcaregrid`, through the root-owned and hash-pinned installed controller. The controller performs its own exact-SHA CI, backup, migration, traffic-gate, worker and rollback checks. Afterward, an HTTPS HU/EN marketing smoke checks localization, HSTS and disabled customer/checkout/Connector routes. A successful smoke and receipt mark that exact SHA `FROZEN`; the resulting GitHub deployment status is the promotion reference. A failed deployment or smoke records a failed deployment status and remains HOLD for operator recovery through the existing controller.

Production runs only on the dedicated `wpcg-production` runner as `deploy-production`, using the installed immutable helper/controller. The workflow calls `sync` with an exact SHA; it does not run raw migrations or grant database privileges. The helper must still find the candidate to be the current primary tip, so a newer merge after the Saturday freeze holds Sunday promotion and requires a new staging qualification.

Recovery receipts live outside the application tree at `/var/lib/wpcaregrid-release/recovery/{staging,production}.json`. They must be root-owned mode `0640`, bounded, unexpired, and bind environment plus exact candidate SHA, backup/restore/migration digests, rollback SHA, database compatibility, RPO and RTO. The receipt must refer to actual release-bound evidence; synthetic CI receipts are not accepted as production recovery evidence.

The receipt contract also requires a non-secret immutable `backup_id`, a distinct isolated `destination_id`, the source application SHA (which must equal `rollback_sha`), and hashes for secure-transfer evidence, configuration/key recovery, restored data integrity and trigger inventory. The `app_key_recovery_sha256` is only a digest of a protected verification receipt; neither the APP_KEY nor protected configuration values belong in this JSON.

Minimal receipt shape (all digest/SHA values are lowercase hex; times are UTC ISO 8601):

```json
{
  "schema": "wpcg-release-recovery-receipt-v1",
  "result": "PASS",
  "environment": "staging",
  "candidate_sha": "<40-hex-candidate-sha>",
  "backup_id": "<immutable-backup-id>",
  "backup_sha256": "<64-hex>",
  "transfer_receipt_sha256": "<64-hex>",
  "source_application_sha": "<40-hex-current-baseline-sha>",
  "configuration_recovery_sha256": "<64-hex>",
  "app_key_recovery_sha256": "<64-hex-secret-safe-check-receipt>",
  "destination_id": "<approved-isolated-destination-id>",
  "destination_isolated": true,
  "restore_evidence_sha256": "<64-hex>",
  "data_integrity_sha256": "<64-hex>",
  "trigger_inventory_sha256": "<64-hex>",
  "migration_rehearsal_sha256": "<64-hex>",
  "rollback_sha": "<same-40-hex-baseline-sha>",
  "rollback_database_compatible": true,
  "rpo_seconds": 3600,
  "rto_seconds": 7200,
  "issued_at": "2026-11-15T12:00:00Z",
  "expires_at": "2026-11-16T12:00:00Z"
}
```

## Repository/environment configuration

Required GitHub repository variables are intentionally unset by default:

| Variable | Purpose |
|---|---|
| `WPCG_WEEKLY_STAGING_ENABLED=true` | Enables Saturday staging job after all candidate gates pass. |
| `WPCG_STAGING_RECOVERY_GATE_READY=true` | Confirms the Owner-approved staging recovery prerequisite is active. |
| `WPCG_STAGING_CONTROLLER_SOURCE_SHA` | Full source SHA of the installed staging controller copy. |
| `WPCG_STAGING_CONTROLLER_SHA256` | SHA-256 of that root-owned installed controller. |
| `WPCG_FIRST_PRODUCTION_LAUNCH_SHA` | Exact SHA of the completed first production marketing-only launch. |
| `WPCG_RECURRING_PRODUCTION_ENABLED=true` | Explicit Owner activation after launch and verified recovery. |
| `WPCG_SECURITY_HOTFIX_ENABLED=true` | Separate Owner-controlled switch for out-of-schedule emergency security hotfix promotion after the first launch. |
| `WPCG_PRODUCTION_RECOVERY_GATE_READY=true` | Confirms current production recovery evidence is ready. |
| `WPCG_PRODUCTION_ROLLBACK_READY=true` | Confirms exact-SHA rollback readiness. |
| `WPCG_PRODUCTION_INCIDENTS_CLEAR=true` | Confirms no active incident or recovery work. |

The production environment must also retain its GitHub environment protection rules and approved runner labels. The installed hosts must have the documented controller paths and root-owned recovery receipts. Missing, malformed or stale values keep the job skipped or HOLD; they are never inferred from a branch name.

### Production activation checklist

1. Complete the first marketing-only production launch through the existing approved one-time procedure and record its exact successful SHA in `WPCG_FIRST_PRODUCTION_LAUNCH_SHA`.
2. Complete and independently verify a fresh production release-bound backup, isolated restore, configuration/key recovery and compatible database rollback receipt for the intended candidate.
3. Confirm helper 2.1.0 host compatibility, installed artifact/sudoers pins, protected environment approvals, exact staging acceptance and production rollback readiness.
4. Confirm no active incident or recovery transaction; only then set `WPCG_PRODUCTION_RECOVERY_GATE_READY`, `WPCG_PRODUCTION_ROLLBACK_READY`, and `WPCG_PRODUCTION_INCIDENTS_CLEAR` to `true`.
5. Set `WPCG_RECURRING_PRODUCTION_ENABLED=true` as the explicit Owner activation. From then on, the Sunday schedule proceeds without per-run Owner approval only when the job and installed helper gates all pass.
6. Enable `WPCG_SECURITY_HOTFIX_ENABLED` only if an out-of-schedule emergency path is desired; it still cannot bypass any release gate.
7. Remove/disable the recurring/hotfix variable and leave the job on HOLD whenever a recovery, incident, compatibility or evidence gate becomes uncertain.

## Security and dependency policy

Dependabot groups security and routine updates separately for GitHub Actions, Composer root, WordPress Composer and npm. Security advisory severity comes from the Dependabot alerts API, not PR title text. Critical/high alerts are expedited; they still use normal protected PR, full CI and independent exact-head QA/AppSec gates. No update is auto-merged. An unresolved high/critical alert with no matching update holds candidate admission. Incompatible updates require an explicit triage outcome before the open Dependabot PR is closed.

The workflow uses the repository `GITHUB_TOKEN` with job-scoped read access for intake/admission. Only the staging job can write GitHub deployment records; only Monday handoff can create an issue. No long-lived GitHub token is stored on either host. The production helper retains its own release-review evidence and controller checks.

## Operator STOP conditions

Stop promotion if the SHA or branch tip changes, exact-SHA CI is not green, a V3 artifact is missing or mismatched, the latest staging deployment is not a successful exact-SHA smoke, a recovery receipt is invalid/expired, a deployment lock or incident is active, a host identity/controller pin differs, or the installed helper refuses. Do not retry around a failed gate. Preserve the traffic fence and use the existing recovery runbook. Production remains disabled until the first launch and recovery have passed and the Owner explicitly enables the recurring variables.
