# Production marketing release runbook (profile v2)

Status: **PREPARATION ONLY**. The production profile is disabled until a complete
root-owned environment policy, backup/recovery evidence, and a separate exact
release authorization are accepted. This runbook does not authorize host or
database changes.

## Pinned implementation

Source baseline for this runbook: protected primary `cf2a944c27a2a24dbdf1097bf0ba766e6edd48fe`.
The helper/controller/sudoers artifacts below are pinned to that primary.
The root installer was added later in PR #300 at source commit
`7a1dda27ede1b61f657a378d289b9eb48f36f5df`; it is not part of the listed
primary commit, and its separate digest is pinned in the manifest.
Revalidate primary, CI, reviews, artifact manifest, and the application release
SHA immediately before any later operation. The application release SHA is not
implied by the helper merge SHA.

Production helper version `2.0.0`, contract `wpcaregrid-deployment-profile-v2`:

| Artifact | SHA-256 |
| --- | --- |
| Privileged entrypoint | `dde7ee7370e4968284cc10fed8dae8cf33786d93830f9f65c7157e19bbf572a1` |
| Backend | `d1cbde99eef1168e42797268ce170e451b28051d7d980876a6167fb19bf85576` |
| Transaction module | `ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00` |
| Environment contract | `f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1` |
| Combined helper artifact | `0e50791ee708de571dbcaa5420bd06b31698e3b7a8683f7501bee6880ead96fc` |
| Production controller | `a94865d3e6318c7278b6d72e797fc94d5274faeca0ea2073ecc84fad74166283` |
| Sudoers candidate | `fb7716132c9d48a6a55c305ecff932a1bd518a575e7dd974162bc0d4f89fc963` |
| Root installer | `c5c19f0655cfae5f4747264c13c988f7360f3322be32a54b5e1961d538c2bf9d` |

The source manifest is `deploy/release-manifests/production-deployment-helper-2.0.0.json`.
The root entrypoint accepts only `preflight`, `verify-install`, `authorize <40-hex-SHA>`,
`stage <40-hex-SHA> <64-hex-digest>`, and `deploy <40-hex-SHA> <64-hex-digest>`.
The deployment controller accepts only `sync <40-hex-application-SHA>`. PASS and NO_OP return 0;
HOLD returns 3; FAIL returns 4. Never convert HOLD/FAIL to success.

## Required production profile inputs

The helper rejects a missing, incomplete, extra-key, or invalid policy. An
administrator must independently verify and provision these values; this
repository deliberately does not guess them:

| Field group | Required facts and constraints |
| --- | --- |
| Profile identity | `schema=1`, `profile=production`, `enabled=true` only after separate approval; `operations` exactly the five operations above; exact helper artifact hashes; pinned Phase B policy SHA-256. |
| Application paths | Fixed contract paths: `/var/www/wpcaregrid-production`, `/var/www/wpcaregrid-production/current`, `/var/www/wpcaregrid-production/releases`. |
| State paths | Fixed, disjoint roots under `/var/lib/wpcaregrid-deploy/production`: `incoming`, `inbox`, `state`, `database-backups`, and `shared/{.env,storage,vendor}`. Maintenance, worker-disabled, and scheduler-paused markers are exact children of `state`. |
| Database | Production DB name begins `wpcg_prod_`; isolated restore DB begins `wpcg_prod_restore_`; both use `127.0.0.1`, distinct ports and independently verified, different MySQL server UUID SHA-256 fingerprints. MySQL version must be 8.4. |
| Host and PHP | Host is exactly `wpcaregrid.com` or `www.wpcaregrid.com`. Server-side compatibility coverage remains PHP 8.4 and 8.5. Per the current Owner decision, Production G1 targets PHP 8.5; no separate PHP version cutover approval is required for that G1 target. PHP-FPM socket/unit must match the selected version and dedicated production naming contract. |
| Unix identities | Dedicated `deploy-production` UID and dedicated PHP-FPM UID/GID, all positive and distinct from staging-next identities and from one another as required by the validator. No identity may be inferred from staging. |
| Consumers | Exactly two unique `wpcaregrid-production-*` worker program names and a `wpcaregrid-production-*` scheduler unit. Workers remain fenced for a marketing-only release unless a separately authorized profile explicitly defines otherwise. |

The separately pinned Phase B policy is installed at
`/etc/wpcaregrid-deploy/profiles/production-phase-b-2.0.0.json`; its database
credentials belong in
`/etc/wpcaregrid-deploy/credentials/production-database-2.0.0.json`. The Phase B
policy SHA-256 must match `phase_b_policy_sha256` in the root-owned production
profile. Credential contents must never appear in release evidence.

The production policy is root-owned mode `0644`, has exactly the contract fields
plus the pinned hash/operations fields, and must be atomically installed only by
an authorized administrator after validating every value. `phase_b_default` is
`DISABLED`; database-changing deployment remains HOLD until the reviewed Phase B
policy and evidence are present. Do not give the Laravel account broad MySQL
privileges or enable global MySQL settings as a workaround.

## Installation and execution gates

### Gate P0 — evidence and release admission (read-only)

1. Confirm the exact protected primary and exact application SHA; verify PR
   merge ancestry, exact-head required CI, applicable reviews, and release
   consistency evidence. Confirm no automatic workflow will deploy this change.
2. Recompute the helper hashes from the exact Git objects and compare the full
   production manifest. Verify the installed helper and policy identities with
   the dedicated production administrator; do not use the staging helper or
   staging profile.
3. Verify the production host, path, service, database and Unix identity facts
   listed above. Missing, shared, overlapping, or mismatched identity means
   STOP. Keep the policy disabled.
4. Verify a fresh protected application/configuration/database backup, exact
   backup digests, successful import into the independently identified restore
   MySQL instance, schema/data checks, and a tested recovery plan. A gzip check
   or backup command exit code alone is not restore evidence.
5. Rehearse every migration on the isolated restore copy. Any migration,
   restore, or recovery uncertainty means STOP. Do not use automatic
   `migrate:rollback` as the recovery plan.

No production commands are issued at P0. Installation and profile activation
need a separate exact-scope production authorization.

### Gate P1 — administrator installation (separate authorization required)

The versioned artifacts install only under
`/usr/local/libexec/wpcaregrid-deploy/production/2.0.0/`; the policy is
`/etc/wpcaregrid-deploy/profiles/production-v2.0.0.json`; the sudoers candidate
is `deploy/sudoers/wpcaregrid-production-v2.0.0`. Install with no-overwrite
publication, verify every installed SHA, owner and mode, and run `visudo -c`.
The dedicated `deploy-production` identity receives only the five exact
allowlisted operations in that file. Run the installer negative authorization
tests for other users and invalid arguments, then verify the profile remains
disabled. While `enabled=false`, positive `verify-install` and `preflight` are
expected to return a fail-closed HOLD; do not enable the profile to make these
checks pass. Those positive checks are deferred until a complete environment
policy and its separate authorization are available. On any mismatch, use only
an identity-checked rollback to the previous recorded files; do not alter other
profiles or directories.

The no-overwrite root installer is `scripts/install_production_helper_2_0_0.py`;
SHA-256 `c5c19f0655cfae5f4747264c13c988f7360f3322be32a54b5e1961d538c2bf9d` is pinned in the release manifest.
It accepts no command-line arguments, requires an already provisioned dedicated
`deploy-production` account, and installs a schema-complete but disabled
production profile. It never enables that profile, installs Phase B credentials,
or invokes any helper operation. Its exact pinned source set must be placed in
`/home/deploy/.private/wpcg-production-helper-2.0.0-cf2a944c/` with owner
`deploy:deploy`, directory mode `0700`, and file mode `0600`. Never execute the
installer directly from a deploy-owned path as root. The installer is staged separately at
`/home/deploy/.private/wpcg-production-helper-installer-2.0.0-cf2a944c/install_production_helper_2_0_0.py`,
also owned by `deploy:deploy`, mode `0600`. An authorized administrator must
copy it into a root-owned private temporary directory and verify the copied
bytes before execution. Example for a separately authorized root console:

```sh
(
  set -eu
  src=/home/deploy/.private/wpcg-production-helper-installer-2.0.0-cf2a944c/install_production_helper_2_0_0.py
  expected=c5c19f0655cfae5f4747264c13c988f7360f3322be32a54b5e1961d538c2bf9d
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

The installer checks every source hash, target absence, parent ownership/mode,
existing sudo policy, and `visudo`; it publishes files atomically without
replacement and adds the sudoers fragment last. It verifies installed hashes,
ownership, modes, and the exact five-command allowlist, then tests that the
ordinary deploy identity and invalid production operations are denied. Failure
triggers identity-checked rollback of only files/directories created by that
run. A rollback mismatch is a HOLD requiring administrator inspection. The
installer does not grant a positive helper operation while the profile is
disabled. No installer has been executed on a host by this runbook update.

### Gate P2 — application deployment (separate exact-release authorization)

Only after P0 and P1 pass and the production release receives an exact-SHA GO.
Set `AUTHORIZED_APPLICATION_SHA` to the 40-character SHA named by that GO, then
verify it still equals the protected primary immediately before invoking the
controller. The controller and privileged helper independently recheck that
the supplied SHA is the current primary release; any mismatch returns HOLD.
Do not replace it with a moving `latest` value or retry after a HOLD without a
new authorization.

```sh
sudo -u deploy-production -- /usr/bin/python3 \
  /usr/local/libexec/wpcaregrid-deploy/production/2.0.0/production_deploy_controller.py \
  sync "$AUTHORIZED_APPLICATION_SHA"
```

This is the sole controller invocation. It checks the fixed source checkout and
helper, verifies the caller-supplied authorized SHA equals the latest protected
primary, authorizes that exact SHA using GitHub CI/review evidence, packages the exact Git source, builds Vite assets in
a private disposable directory with the lockfile and pinned Node/npm major
versions, stages the immutable artifact by SHA-256, and asks the helper to
deploy it. Do not supply a path, database name, host, branch, or arbitrary
artifact digest. A second invocation is not an automatic retry: first inspect
the durable result and obtain authorization if the first operation entered a
mutating phase.

The previously prepared Laravel ZIP is not accepted as input by this controller.
Keep it unchanged as a candidate artifact and compare its embedded source SHA,
manifest and contents with the exact release before deciding whether a reviewed
controller change is needed. Do not substitute it manually or report it as the
controller-generated artifact. The controller currently creates the canonical
archive from Git source and records its observed digest in the helper receipt.

Expected outcomes are structured JSON `PASS`, `NO_OP`, `HOLD`, or `FAIL`.
`NO_OP` is acceptable only when the active application SHA equals the latest
eligible primary SHA and the active artifact digest is valid. `HOLD` means no
release acceptance; `FAIL` requires preserving backup/journal/fences and
following the recorded recovery procedure, not blind re-execution.

## Database and recovery rules

* Marketing-only does not imply database-free. If the exact release adds
  migrations, the helper must find an enabled, hash-pinned Phase B policy and
  the matching current transaction evidence; otherwise deployment returns
  HOLD.
* Before a DB-changing release, preserve the previous app/config/DB recovery
  point, import a fresh DB backup into a separate disposable restore instance,
  verify it, rehearse the exact migrations, and verify bounded recovery before
  authorizing the migration window.
* The Phase B sequence must retain the maintenance gate, worker/scheduler
  fences, exclusive lock, durable checkpoints, bounded migration list, and
  truthful receipt. A partial or interrupted migration stays gated and must be
  resumed only through the reviewed transaction state machine.
* Never point the restore database at production, drop application tables,
  edit migration history manually, grant broad app-user privileges, or use
  automatic destructive rollback.
* Keep `deploy-production` worker/scheduler execution disabled for the
  marketing-only profile. No live payment, webhook provisioning, registration,
  Connector onboarding, customer processing, or automated schedule is enabled
  by this runbook.

## Acceptance and rollback

After a later authorized deployment, retain the helper's hashed release
receipt and verify both application SHA and artifact digest. Run production
smoke checks using the approved authenticated probe path:

* HTTPS returns 200 for HU and EN marketing landing, pricing, contact, legal,
  and privacy pages; locale switch and pricing selectors behave correctly.
* Security headers, canonical/noindex policy as approved for launch, and
  production `APP_DEBUG=false` are correct. No secrets are printed in evidence.
* Registration, customer/operator APIs, checkout, Stripe/webhook provisioning,
  Connector onboarding and SaaS background jobs remain denied or disabled.
* Verify runtime PHP-FPM unit/socket matches the profile (PHP 8.4 baseline),
  logs have no new application errors, and worker/scheduler fences remain
  active.
* Confirm receipt outcome and HTTP/function checks before any separately
  authorized public activation.

For a failure before database mutation, keep the existing maintenance gate and
fences, preserve the backup and durable evidence, and use the controller's
fail-closed result. After database mutation begins, do not restore application
code or roll back migrations automatically. Use the Phase B checkpoint and the
verified pre-deploy recovery point under a separate operator authorization.
Public traffic, workers and scheduler are never released by this runbook.

## Release-ready decision

`PRODUCTION_MARKETING_RELEASE=NOT_READY` until all of the following are proven:

1. Complete production profile values and isolated server/database identities.
2. Exact helper installation, root-owned policy, least-privilege sudoers,
   negative/positive operator tests, and rollback evidence.
3. Fresh protected backup plus successful isolated restore and exact migration
   rehearsal for any database-changing release.
4. Exact release SHA, canonical artifact digest, CI, QA/AppSec evidence,
   marketing acceptance and separate production execution authorization.

Current limitations: only deploy-readable host inventory is available; the
production deploy identity was absent from deploy-visible account lookup and
root-only service/database/sudo policy remain unverified. The root installer is
pinned in the manifest but has not been installed. No production
backup/restore evidence was supplied; the prepared ZIP has no verified digest
or demonstrated compatibility with the controller input contract. No server,
database, helper, account, payment, or DNS change has been performed.
