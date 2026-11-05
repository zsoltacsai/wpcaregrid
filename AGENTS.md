# WP CareGrid — agent operating contract

Read `docs/AUTONOMOUS_DEVELOPMENT.md`, `docs/RELEASE_CHECKLIST.md`, and the relevant deployment/security documentation before working. More restrictive current Owner decisions, incident gates, branch protections and issue-specific instructions take precedence.

## Two execution modes

- **DAY / Owner-controlled:** incident recovery, staging/production, releases, infrastructure, database/migrations, credentials, security-boundary changes, and architecture decisions require separate explicit Owner authorization for each named effect.
- **NIGHT / repository-only:** independently implement 2–4 compatible, explicitly Owner-approved low-risk tasks from the nightly queue. Use separate branches and PRs, tests and reviews. Never infer infrastructure authorization from code-merge permission.

Night eligibility is opt-in per task; repository access and a GitHub issue alone do not grant it. Require a clearly recorded Owner approval specifying scope, affected surfaces, and whether **conditional merge** is authorized. If absent or ambiguous, prepare a proposal or draft PR only; do not change protected runtime systems.

## Non-negotiable merge gates

- Verify authoritative primary and fresh baseline before changes; preserve unrelated branches/worktrees.
- Keep PRs narrowly scoped, avoid concurrent writers to shared files, and serialize dependency/lockfile updates.
- Run required PHP 8.4/8.5, SQLite/MySQL/MariaDB, Connector, concurrency, browser and security checks as applicable. Do not skip, suppress or weaken failures.
- Integration & QA and M17 AppSec must approve the **exact final diff**. Re-review and rerun impacted checks after changes, rebases or integration.
- Validate cross-artifact provenance: independently hash final bytes and match all controller/helper/launcher/verifier pins and environment baseline constraints. Missing proof is **HOLD**, never PASS. A future automated consistency check must not be claimed active before its CI integration is proven.
- Conditional merge is permitted **only** when the task's Owner GO expressly authorizes it and all required exact-head gates, protection rules and reviews pass; verify exact merge-SHA CI afterward.
- If a gate fails, stop that task, preserve evidence and continue only unrelated approved tasks.

## Never authorized by the NIGHT mode alone

No staging/production SSH, deployment, service/worker/scheduler control, PHP cutover, live database access/migration/restore, secrets/permissions, billing/payment activation, external customer actions, bypassing controls or loosening GitHub protection. Current staging recovery remains HOLD and production NO-GO until superseded by explicit Owner authorization.

Record a concise handoff: PRs, final SHAs, CI/review evidence, merge state, blockers, and the next Owner decision. Do not claim work happened merely because it was scheduled.
