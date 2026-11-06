# Daily staging-next synchronization contract

**Initial release: synthetic policy tests and read-only preflight only. Automatic deployment is not enabled.**

The daily sync decision compares only the fixed staging-next profile:

- application path `/var/www/wpcaregrid-staging-new`;
- database `wpcg_staging_next`;
- host `staging-next.wpcaregrid.com`;
- helper contract `wpcaregrid-deploy-profile-v1` and the exact installed helper
  artifacts pinned separately in `deploy/release-manifests/staging-next-helper.json`.

The helper version and hashes do not change merely because a new Laravel
commit is accepted. An installed helper mismatch or unknown contract is HOLD.
The existing legacy helper remains pinned to `/var/www/wpcaregrid` and is not
called by this workflow. Production has a separate inert manifest with no
target, helper, allowlist, or operations; no staging parameter can select it.

The pure decision evaluator requires a fixed schema, exact target identity,
application and artifact hashes, helper hash/contract equality, exact-head and
merge-SHA CI, Integration & QA and AppSec PASS, and verified target identity.
Each PASS record is bound to its relevant identity: environment evidence names
the fixed profile/path/database/host; artifact evidence names the exact release
SHA and artifact digest; CI records bind to the exact PR head and merge SHA and
include positive workflow run IDs; QA/AppSec decisions bind to the exact PR
head. Independent acceptance blockers are returned together in a stable order;
one PASS record cannot mask another missing or mismatched record. A no-op also
requires the candidate release artifact digest to equal the deployed artifact
digest. Unknown, missing, extra or inconsistent evidence produces HOLD.

For every changed release the policy requires a fresh protected backup,
isolated restore proof and migration rehearsal. The `database_changes` manifest
field is a caller declaration rather than authenticated migration-scope proof,
so setting it to `false` cannot waive those requirements. A future optimized
no-migration path needs a trusted, artifact-bound migration-scope attestation
and its own reviewed validation; a boolean is insufficient. An unchanged
approved release yields NO-OP. A changed eligible release still yields HOLD
because the installed v1.0.2 root helper exposes only a read-only preflight
operation.

Future deployment support requires a separate reviewed helper contract with
the complete backup, migration, application, service, rollback, fence and
postcheck guarantees in #272. The Owner-provided receipt records that helper
v1.0.2 was installed with `preflight` only; the receipt is recorded in #279
and #174 and is not an independent observation by CI. Any deployment-capable
helper version must be separately reviewed and installed after a separate
Owner GO. This source release does not deploy, migrate, back up, restore,
restart, open gates, activate a schedule, or modify a host.
