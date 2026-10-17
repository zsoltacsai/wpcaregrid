# GitHub Settings Audit — Read-only

Readback date: 2026-10-17. Repository `zsoltacsai/wpcaregrid` (owner type personal account), public. Ruleset `25300175` was added specifically to remediate M146-F01; no unrelated setting was changed.

| Setting | Observed result | Implication |
|---|---|---|
| Default branch | `claude/ecstatic-wozniak-oemyuf` | This is the deployed authoritative branch; protect it explicitly. `main` is not an adequate substitute. |
| Repository rulesets | Before: `[]`. After: active ruleset `25300175`, target `refs/heads/claude/ecstatic-wozniak-oemyuf`, `bypass_actors:[]`, `current_user_can_bypass:"never"` | Ruleset applies the release controls to the deployed branch and repository owner/admin. |
| Classic branch protection | Before: branch `protected:false`; after: branch API `protected:true` with effective ruleset | PR path required; force-push and deletion blocked; administrators are not bypass actors. Direct push was not attempted; effective API readback is the safe proof. |
| Required checks | Seven exact contexts required with strict up-to-date policy | Quality; Server SQLite, MySQL, MariaDB; Connector SQLite, MySQL, MariaDB. MySQL process/concurrency remains inside mandatory Server MySQL. |
| PR approval | Required approvals: 0 | Collaborator readback lists only the owner with push permission. Zero is deliberate to avoid an impossible sole-maintainer merge rule; independent review remains a M14.7/M17 residual. Conversation resolution is required. |
| Actions policy | `enabled:true`, `allowed_actions:"all"`, `sha_pinning_required:false` | Any action source can be referenced; full SHA policy is not enforced server-side. Pin and static-test in repo. |
| Merge methods | Merge, squash and rebase all enabled | Do not alter in this planning pass. Exact merged primary SHA must receive its own successful CI regardless of method. |
| Auto-delete head branches | `false` | No routine head-branch deletion on merge. Not a release bypass. |
| Workflow permissions | Workflow source explicitly grants `contents: read`; no job override | Minimized repository token. Fork PRs retain read-only behavior. |
| Fork PR secret setting | Public-repo event behavior: fork PR `pull_request` gets read-only `GITHUB_TOKEN` and no other secrets; workflow has no secret references | No staging or production secret reaches this CI path. The separate private-fork setting endpoint is not applicable to public repository and returned not found. |
| Environments / bypass actors | No deployment environment or deploy job in workflow. No repo rulesets to enumerate bypass actors. | Deployment stays manual/outside Actions. Admin-controlled branch bypass cannot be excluded because there is no branch rule; resolve through F01. |
| Artifact retention | No uploaded Actions artifacts found | Actions retention setting is not material to a deployment artifact path in current workflow. Preserve evidence in repo reports and run metadata. |

GitHub’s public-fork event documentation says secrets other than `GITHUB_TOKEN` are not sent and fork PR tokens are read-only: [events that trigger workflows](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows). The workflow declares `contents: read`, and GitHub documents explicit least-permission grants: [GITHUB_TOKEN authentication](https://docs.github.com/en/actions/tutorials/authenticate-with-github_token). No `pull_request_target` occurs; GitHub documents the privileged risk of running fork code in that event: [securely using pull_request_target](https://docs.github.com/en/actions/reference/security/securely-using-pull_request_target).

GitHub documents that fork PR caches are scoped to the pull-request merge ref and cannot be consumed by the base branch/other PRs. The current `setup-node` cache is npm download cache keyed from the lockfile; it is not a trusted build artifact and contains no secrets. Continue treating restored cache data as untrusted and do not cache `node_modules`, secrets or executable build output: [dependency caching reference](https://docs.github.com/en/actions/reference/workflows-and-actions/dependency-caching).

## M146-F01 readback evidence

Effective rule readback returned `deletion`, `non_fast_forward`, `pull_request`, and `required_status_checks`; all seven contexts matched current successful workflow evidence. No bypass actor is configured. No unsafe direct-push attempt was made. Exact merged-primary CI run `39027391253` passed on `b16f2c53e7b6763bdfd7bcfea4d5162807bc06ad`. The sole-maintainer approval residual does not restore the direct-push or missing-check path.
