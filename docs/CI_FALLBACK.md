# CI execution fallback policy

GitHub Actions is the authoritative CI and release system for WP CareGrid. GitLab at `fountainbridge-group/wpcaregrid` is an execution fallback only; it is not an independent development fork, source of truth, or release branch.

## When fallback is allowed

Use GitLab only when GitHub Actions cannot execute for an external platform/account limitation (for example, a documented billing, quota, runner, or service outage) and the required checks cannot be started. A failed test, quality check, security check, or product job is not a reason to switch providers or bypass a red result.

## Exact candidate rule

The validated GitLab ref must have the same Git commit as the current GitHub PR head whenever possible. If exact commit preservation is technically impossible, compare the full tracked tree and all CI-relevant configuration; record both commit and tree IDs. Any GitLab CI configuration required for validation must first be committed to the GitHub candidate branch so it is part of the candidate tree. Never add validation-only files on GitLab and then claim that the GitHub candidate passed.

Before running the pipeline, record:

- GitHub PR number, head SHA, and tree SHA;
- GitLab project, branch/ref, commit SHA, and tree SHA;
- equality proof (prefer identical commit SHA; otherwise identical tree and tracked-file/submodule/lockfile inventory).

If the source tree or CI configuration differs, stop and synchronize the exact candidate before creating the pipeline.

## Mandatory gates

Fallback CI must preserve the mandatory jobs in `.github/workflows/ci.yml`:

1. Quality: tracked-secret/local-file guard, Composer validation, PHP syntax, M8 fixture contract, Connector release-version gate, Pint, Composer audits, Node 22 build, npm audit, and full-history Gitleaks scan.
2. Server SQLite: migrations, application caches, complete PHPUnit suite, system check, HTTP smoke test, and required S3/SFTP test services. Only the database-engine concurrency tests that GitHub intentionally defers to MySQL may be skipped on SQLite.
3. Server MySQL 8.4: the same server checks, zero skipped tests, and every required process/concurrency test.
4. Connector SQLite: build the reviewed connector package and run the complete real-WordPress Connector suite with strict tests enabled.
5. Connector MySQL 8.4: same Connector package and test suite against MySQL.

No gate, strict skip setting, concurrency test, security check, migration check, audit, build, or smoke test may be omitted or weakened. Inspect every required job, not only the pipeline summary. Preserve failure artifacts/logs without exposing secrets.

## Running a fallback pipeline

1. Fetch GitHub and identify the current PR head and its base SHA.
2. Ensure `.gitlab-ci.yml` is part of that GitHub PR head and inspect it against the workflow above.
3. Push the exact GitHub candidate commit to a new, clearly named GitLab branch (for example `ci/m12-wave6-candidate`). Do not force-push or overwrite unrelated refs.
4. Verify GitLab branch commit SHA equals the GitHub PR head SHA. If Git history transport changes the commit ID, prove tree SHA equality and full tracked-file/submodule/lockfile equivalence.
5. Set the repository-controlled `WPCG_RELEASE_BASE_SHA` in the candidate's `.gitlab-ci.yml` to the exact GitHub PR base SHA (or use GitLab's merge-request diff base when running an actual GitLab merge-request pipeline). This project currently disallows pipeline-variable overrides, so do not depend on a manually supplied variable. The job must verify that the base exists and is an ancestor of the candidate.
6. Inspect all five mandatory jobs and record pipeline/job IDs, names, status, links, source SHA/tree, and any documented environmental differences.
7. Return to GitHub. Confirm the PR head has not changed since the validated candidate. If it changed, synchronize and rerun the entire fallback pipeline.
8. Merge only through normal GitHub PR history after equivalent exact-candidate CI is green. GitHub remains the primary branch and merge authority.

If a required gate cannot be reproduced or equivalence cannot be established, the fallback is invalid and release remains blocked. Do not merge or deploy on partial evidence.

## Evidence and release lineage

Record why GitHub Actions was unavailable, original GitHub run IDs, candidate SHA/tree, GitLab project/ref, pipeline ID/link, every required job result, merge SHA, final primary SHA, and post-merge tree comparison. Do not call GitHub Actions green when GitLab fallback ran. If the GitHub account block continues, exact-candidate GitLab CI may substitute for unavailable GitHub execution under this policy; it does not replace GitHub as the source of truth.

## GitLab project baseline

The GitLab project is private and currently configured with shared/group runners enabled. Actual runner availability, container execution, services, and quotas must be established by a real pipeline; configuration flags alone are not evidence that a runner is online. The GitLab pipeline must never deploy staging or production.

