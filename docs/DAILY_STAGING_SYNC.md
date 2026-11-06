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
Changed releases that modify the database additionally require a fresh
protected backup, isolated restore proof and migration rehearsal. Unknown,
missing, extra or inconsistent evidence produces HOLD. An unchanged approved
release yields NO-OP. A changed eligible release still yields HOLD in this
initial source release because the approved root helper exposes only a
read-only preflight operation.

Future deployment support requires a separate reviewed helper contract with
the complete backup, migration, application, service, rollback, fence and
postcheck guarantees in #272. It must be installed through the administrator
procedure in [STAGING_NEXT_HELPER_INSTALL.md](STAGING_NEXT_HELPER_INSTALL.md)
after separate Owner GO. This source release does not deploy, migrate, back up,
restore, restart, open gates, or modify a host.
