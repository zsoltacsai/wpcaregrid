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
- Release-authorizer offline matrix: exact branch-tip push success; parent SHA; PR-head event; wrong branch/workflow; queued/in-progress/canceled/failure; missing/duplicate required job; failed MySQL concurrency step; incomplete pagination; malformed/oversized/API timeout/redirect. No token header or customer data is sent.
- Rollback: explicit `--rollback` only; target must be an ancestor of current baseline and fetched branch and match the root-owned `approved-rollback-sha`; malformed, missing, symlinked, wrongly owned/mode or mismatched approval fails closed.
- Deployment receipt fixtures: exact runtime SHA; all three lock hashes; release version and Connector source/package sidecar binding; Vite manifest digest; no `.env` sentinel; mode 0600; safe private directory; duplicate receipt refusal.
- Process-level staging MySQL tests: lock contention, competing deploys, backup integrity, scheduler pause/resume, gate, worker coordination, migration failure and recovery. MySQL/process is required; SQLite is not a concurrency substitute.
- Host test after F02: privilege boundary blocks arbitrary root from Composer/npm/Artisan; helper accepts only enumerated safe action/arguments.
- Fault matrix: wrong origin/host key/key scope; dirty baseline; short/nonancestor target; dependency/network/disk failure; Vite failure; pending/partial migration; worker/scheduler/health failure; rollback failure.
- Verify exact merged SHA CI, exact deployed runtime HEAD, Connector SHA, receipt content/redaction, no customer mutation and staging only. Forward promotion must be current primary tip; PR-head/parent/sibling green runs are insufficient.

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

Maintain the current seven CI result jobs and exact check names. Every executable PR requires focused tests, full mandatory CI, `git diff --check`, Pint, Composer validation/audit, frontend build, secret scan and exact-head run; deployment release then additionally requires merged-primary exact-SHA CI. PR #118 exact-head run `39050627430` and exact merged-primary run `39051589381` passed 7/7. No per-suite test totals are inferred here.


## Candidate static security tests

The repository's local/CI policy scripts cover mutable Action refs, forbidden `pull_request_target`, permission widening, required matrix/job drift, MySQL process-gate removal, obvious deployment inclusion, untrusted event expressions in shell, Composer repositories/HTTP sources, npm non-registry URLs, Gitleaks checksum/truncation/path traversal/symlink behavior, and tracked secret-path newline/annotation sentinels. The latest exact merged-primary CI run `39051589381` passed all seven required jobs. Its job-level success is recorded; this report does not infer individual test counts from the run summary.

## Owner-reported staging execution evidence

The owner reports exact merged-SHA deployment with exit code 0, a successful receipt/health and migrations, runtime HEAD matching `be427a17320ca7aba9ec292d2c288813df5f7c07`, the enumerated HTTP checks, workers RUNNING and markers absent. This is staging validation evidence, not evidence that every fault-injection row above was run. See the closure report for exact results and attribution.
