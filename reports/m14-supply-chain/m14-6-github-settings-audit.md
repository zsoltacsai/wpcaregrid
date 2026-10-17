# GitHub Settings Audit — Read-only

Readback date: 2026-10-17. Repository `zsoltacsai/wpcaregrid` (owner type personal account), public. No setting was changed.

| Setting | Observed result | Implication |
|---|---|---|
| Default branch | `claude/ecstatic-wozniak-oemyuf` | This is the deployed authoritative branch; protect it explicitly. `main` is not an adequate substitute. |
| Repository rulesets | `GET /repos/.../rulesets` returns `[]` | No repository-level ruleset observed. Owner is a user account; organization ruleset endpoint returns 404. |
| Classic branch protection | `GET /branches/claude/ecstatic-wozniak-oemyuf` returns `protected:false`; protection endpoint 404 | No enforced PR review, required status checks, force-push restriction or deletion protection is present through classic branch protection. Direct push by repository writers is possible. This is M146-F01. |
| Required checks | None enforced by branch protection/ruleset | CI produces Quality, Server SQLite, Server MySQL, Server MariaDB, Connector SQLite, Connector MySQL and Connector MariaDB. Their existence does not make them required. MySQL process/concurrency is embedded in Server MySQL. |
| Actions policy | `enabled:true`, `allowed_actions:"all"`, `sha_pinning_required:false` | Any action source can be referenced; full SHA policy is not enforced server-side. Pin and static-test in repo. |
| Merge methods | Merge, squash and rebase all enabled | Do not alter in this planning pass. Exact merged primary SHA must receive its own successful CI regardless of method. |
| Auto-delete head branches | `false` | No routine head-branch deletion on merge. Not a release bypass. |
| Workflow permissions | Workflow source explicitly grants `contents: read`; no job override | Minimized repository token. Fork PRs retain read-only behavior. |
| Fork PR secret setting | Public-repo event behavior: fork PR `pull_request` gets read-only `GITHUB_TOKEN` and no other secrets; workflow has no secret references | No staging or production secret reaches this CI path. The separate private-fork setting endpoint is not applicable to public repository and returned not found. |
| Environments / bypass actors | No deployment environment or deploy job in workflow. No repo rulesets to enumerate bypass actors. | Deployment stays manual/outside Actions. Admin-controlled branch bypass cannot be excluded because there is no branch rule; resolve through F01. |
| Artifact retention | No uploaded Actions artifacts found | Actions retention setting is not material to a deployment artifact path in current workflow. Preserve evidence in repo reports and run metadata. |

GitHub’s public-fork event documentation says secrets other than `GITHUB_TOKEN` are not sent and fork PR tokens are read-only: [events that trigger workflows](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows). The workflow declares `contents: read`, and GitHub documents explicit least-permission grants: [GITHUB_TOKEN authentication](https://docs.github.com/en/actions/tutorials/authenticate-with-github_token). No `pull_request_target` occurs; GitHub documents the privileged risk of running fork code in that event: [securely using pull_request_target](https://docs.github.com/en/actions/reference/security/securely-using-pull_request_target).

GitHub documents that fork PR caches are scoped to the pull-request merge ref and cannot be consumed by the base branch/other PRs. The current `setup-node` cache is npm download cache keyed from the lockfile; it is not a trusted build artifact and contains no secrets. Continue treating restored cache data as untrusted and do not cache `node_modules`, secrets or executable build output: [dependency caching reference](https://docs.github.com/en/actions/reference/workflows-and-actions/dependency-caching).

## Required administrative resolution before M14.6 closure

Create an active ruleset/classic protection targeting exactly `claude/ecstatic-wozniak-oemyuf`: PR required; named seven checks required; stale approvals/checks handled; force push and deletion blocked; bypass set to the smallest emergency actor set with audit; no direct push for routine changes. Verify by read-only API/UI after application and test rejection using a non-admin staging/test branch workflow. If administrators must bypass for emergencies, require incident record, exact SHA CI, and immediate post-incident review. Required check names must not be renamed without simultaneous ruleset migration.
