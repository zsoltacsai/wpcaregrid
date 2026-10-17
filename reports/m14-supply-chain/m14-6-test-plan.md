# M14.6 Test Plan

## CI/workflow policy

- Parse all workflow files and reject unreviewed new workflow/entrypoint additions.
- Assert remote Actions are exactly full 40-hex SHAs; local `./` actions may remain local.
- Assert no `pull_request_target`, no PR deployment/job/environment, top-level/job permission ceiling is read-only unless separately approved, seven named check results persist, and MySQL process/concurrency suite remains in Server MySQL.
- Fixture cases: safe baseline, mutable `@vX`, `@main`, write permission, missing job, `pull_request_target`, self-hosted PR runner, secret reference in PR job, deployment command on PR, untrusted GitHub context interpolation, multiline runner command injection, dispatch `connector_release_base` containing leading dash, invalid object/ref, multiline/whitespace and valid full SHA.
- Verify every new workflow is included in the policy test. Workflow changes require independent review/static parser; modified workflow cannot be its sole proof.

## Dependency and binary provenance

- Composer validate/lock consistency and audit root + WordPress; test scripts/plugins against allowlist. Composer lock updates display direct and transitive package/version/source-reference delta.
- `npm ci` lock consistency and npm high/critical audit; test `ignore-scripts=true`, explicit build success, and lifecycle behavior.
- Moto exact locked package closure/hash; checksum mismatch/truncated Gitleaks binary must fail before execution.
- Test stable policies for PHP/Node maintained patches, Composer v2, Ubuntu runner and moving MySQL/MariaDB patch tags; no silent major change.
- Connector: release version/source consistency; per-file manifest and sidecar digest; repeat package and same-version mismatch behavior; exact source revision evidence.

## Deployment and source-to-runtime

- Unit/shell harness for SHA parser/type/ancestry, fixed origin, branch-tip check, clean checkout and post-validation target identity.
- Process-level staging MySQL tests: lock contention, competing deploys, backup integrity, scheduler pause/resume, gate, worker coordination, migration failure and recovery. MySQL/process is required; SQLite is not a concurrency substitute.
- Host test after F02: privilege boundary blocks arbitrary root from Composer/npm/Artisan; helper accepts only enumerated safe action/arguments.
- Fault matrix: wrong origin/host key/key scope; dirty baseline; short/nonancestor target; dependency/network/disk failure; Vite failure; pending/partial migration; worker/scheduler/health failure; rollback failure.
- Verify exact merged SHA CI, exact deployed runtime HEAD, Connector SHA, receipt content/redaction, no customer mutation and staging only.

## Test layers and acceptance

| Layer | Scope |
|---|---|
| Static/unit | YAML policy, pin parser, lock/source policy, dispatch/argument validation, provenance fields. |
| Feature | Connector packaging, release metadata, CI policy invocation, receipt redaction. |
| Server SQLite | workflow/helper behavior that is DB-independent; not race proof. |
| Server MySQL | required full suite plus deterministic process/concurrency/deploy lock fixtures. |
| Server MariaDB | full server regression where currently required. |
| Connector SQLite/MySQL/MariaDB | preserve all three existing Connector test matrix jobs. |
| Staging | exact merged SHA, pinned SSH, origin, receipt, gate/build/migration/worker/health and rollback-safe failure fixtures. No production or customer changes. |

Maintain the current seven CI result jobs and exact check names. Every executable PR requires focused tests, full mandatory CI, `git diff --check`, Pint, Composer validation/audit, frontend build, secret scan and exact-head run; deployment release then additionally requires merged-primary exact-SHA CI. No exact totals are claimed by this planning document.
