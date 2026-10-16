# M14.1 Closure Criteria

M14.1 may close only when all applicable criteria have current evidence on the exact implementation SHA:

- Expanded route and command inventory reconciled; every state-changing route has auth, ability, object scope, CSRF/API boundary, limiter and test mapped.
- Two-tenant negative tests cover customer web, Owner, Operator, Connector/API, exports, backups/restores, billing, privacy cases, provider tasks and jobs; no foreign data, mutation, queued effect or audit leakage.
- Password reset/activation and staff invitation token use are single-use under concurrency; stale sessions and remember credentials are invalidated according to verified policy across guards.
- Session fixation/regeneration, logout, role revocation, recent re-auth, approval binding and CSRF checks pass.
- Rate-limit coverage is complete for sensitive/expensive routes; shared cache behavior is verified on the deployed topology; abuse returns safe 429s without account enumeration.
- Queue jobs and commands re-resolve IDs/relationships and gates at effect time; stale/deleted/reparented objects fail safely.
- No confirmed Critical/High M14.1 issue remains. Any new finding has root-cause fix, regression, CI and staging retest.
- Required CI green on exact SHA, including MySQL process/concurrency and Connector jobs. Staging validation uses synthetic data and strict pinned SSH.
- M12 global/profile/backup/provider/bulk/normal-staging-synthetic destructive gates remain false before and after.
- Updated audit matrices distinguish tested PASS from source-observed PARTIAL and carry-forward work.

Not M14.1 closure claims: full pentest, full dependency/scanner campaign, deep Connector SSRF/payload/credential audits, filesystem/archive safety, production DR audit, M15 pilot authorization or M17 readiness.

## M14.1 final closure disposition — 2026-10-16

Focused implementation, exact-SHA CI and guarded staging validation are complete on `e4659f30140669c9e229c8e583d35e6bb377a979`; see [`m14-1-closure-report.md`](../m14-security/m14-1-closure-report.md). Disposition is **B — functionally complete, non-critical audit residual remains**. The password-reset session revocation and stale queued backup-scope findings are fixed and validated. The exhaustive route-to-control mapping and two-tenant audit are not complete, and the staging security scanner still reports no completed scan. These residuals remain M14 work; no full AppSec/tenant audit or M14 closure is claimed.
