# M14 CI/CD Supply-Chain Audit — Planning Inventory

**Status:** `PARTIAL`. Source workflow/deploy script inspected; repository-host protections and artifact provenance settings require read-only GitHub settings evidence during M14 execution.

## Observed controls

- `.github/workflows/ci.yml` is the primary workflow and grants `contents: read`; concurrency is branch-scoped with cancellation; workflow is not a deployment workflow.
- Quality job runs Composer validation/audit for server and Connector harness, PHP lint, Pint, frontend build/npm audit, full-history redacted Gitleaks, Connector package/version checks and M13 analytics contract tests.
- Server jobs cover SQLite and MySQL 8.4; Connector jobs cover SQLite and MySQL; missing required service coverage is configured to fail rather than silently skip. MySQL process/concurrency tests are part of the required service gate.
- Staging deployment is a separate guarded `scripts/deploy-staging.sh`: hard-coded app dir/branch/staging URL, lock/gate files, baseline capture, pre-deploy private DB backup, strict migration checks, worker/scheduler coordination and rollback protections. It explicitly is not the production deploy procedure.
- CI checks tracked local secret/database/key files and Gitleaks scans history with redaction.

## Risks / evidence still needed

| ID | Topic | Observed evidence | Gap / disposition | M14 proof |
|---|---|---|---|---|
| CI-01 | Action pinning | Workflow references version tags (`actions/checkout@v7`, `actions/setup-node@v7`, `shivammathur/setup-php@v2`) | tags are mutable references; no compromise observed; consider reviewed full-SHA pinning and Dependabot policy | inventory every `uses:` reference and external download; pin with documented update process |
| CI-02 | Gitleaks download integrity | fixed version URL piped directly into tar; version is in workflow | downloaded binary checksum/signature is not verified in workflow source | pin checksum/signature and test fail-closed handling; no findings output exposure |
| CI-03 | Branch protection / required jobs | repository process says five mandatory jobs | GitHub rulesets, required checks, bypass actors, environment approvals and PR review rules are external settings, not in Git source | export current settings/actors through authorized read-only GH API/UI; record date and exact branch |
| CI-04 | Secrets / deploy identity | CI workflow has only read repository permission; deploy script uses a staging Git SSH alias | GitHub environment secret names/scopes, SSH key permissions and production separation need host-side evidence; no secret values read | inventory names only, environment restrictions, key owner, rotate/revoke evidence, staging target host allowlist |
| CI-05 | Artifact provenance | Connector artifact/package has source/version hashes and release gate | no general build provenance/attestation verified in this source review | identify release artifacts and whether deploy rebuilds from exact SHA; consider attestation for future release chain |
| CI-06 | Dependency updates | Composer/npm locked and audited | maintenance ownership, alert SLA and update cadence not proven by workflow alone | define Dependabot/security advisory response and reproducible lockfile update tests |
| CI-07 | Deployment rollback | script performs prebackup, target validation and gated rollback with DB migration caution | operational proof and access separation remain staging operations evidence | fault-injection rehearsal on staging before production pilot; production procedure stays separate |

## Required M14 audit checklist

1. Verify protected primary rules, required five job contexts, review count, bypass actors and signed commit policy if available.
2. Confirm CI trigger coverage for PR and protected primary; no PR path has deployment credentials or write scope.
3. Inventory all third-party actions and downloaded tools, pin integrity, versions and update cadence.
4. Trace exact candidate SHA → CI run → merged SHA → staging deploy SHA → smoke evidence. No branch-name-only deploy.
5. Review artifacts, caches and logs for credentials, customer data, test snapshots and unsafe retention.
6. Exercise failed deployment before and after migration attempt, retaining rollback gates and verifying the pinned backup.
