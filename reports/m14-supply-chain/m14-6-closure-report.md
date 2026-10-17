# M14.6 Closure Report — Staging Validation and Formal Closeout

**Status:** Staging validation is complete based on owner-supplied evidence for exact merged primary SHA `be427a17320ca7aba9ec292d2c288813df5f7c07`. This report is the documentation closeout. Formal closure is established when PR #117 is merged and exact merged-primary CI passes; GitHub records the merge SHA and run. No production deployment occurred.

## Release identity and CI evidence

- M14.6 planning PR #105 merged as `b16f2c53e7b6763bdfd7bcfea4d5162807bc06ad`.
- High-finding containment and CI hardening were delivered through PRs #106, #107 and #108. Exact heads and merged-primary runs are recorded below and in the findings register.
- Release authorization/provenance PR #115 merged as `7d78bd8a44f71178fabc576767bda07a5f75d95d`; exact-head run `39036319661` and merged-primary run `39037273263` passed all seven mandatory checks.
- Documentation/evidence PR #116 merged as `9439ecbb3ec8b1cf5ad8daa20fa37168add6e97b`; exact-head run `39038373087` and exact merged-primary run `39039308572` passed 7/7.
- Receipt provenance fix PR #118 merged as `be427a17320ca7aba9ec292d2c288813df5f7c07`. Its exact-head CI run `39050627430` passed 7/7; the exact merged-primary run `39051589381` completed successfully with all seven required jobs. The Server MySQL job's required process/concurrency gate passed.
- The exact merged primary SHA `be427a17320ca7aba9ec292d2c288813df5f7c07` is the owner-reported staging target. Pre-deployment baseline was `3debba99c677b68465cf439733f440442fbd0f22`.

## High-finding containment

**M146-F01 — closed.** Ruleset `25300175` applies to deployed branch `claude/ecstatic-wozniak-oemyuf`. The recorded API readback confirms PR-only changes, all seven required check contexts, conversation resolution, force-push and branch-deletion blocks, administrator enforcement, and no bypass actors. No direct push was attempted. Independent approvals remain unrequired because the eligible maintainer topology is single-person; that review residual is explicit and does not reopen the mechanical direct-push/check bypass.

**M146-F02 — closed.** The recorded staging host readback showed deploy limited to `/usr/local/sbin/wpcaregrid-deploy-priv`, no membership in the system `sudo` group, and no `NOPASSWD: ALL`. The installed helper was root-owned (`root:root`, mode `0755`, SHA-256 `cb71e5ef3a9cdf685cd02515f8484ceffad53750c6748a1335cf1251ab377af1`). Nine harmless arbitrary-root probes and the target-code `sudo -n id` fixture were denied; required fixed helper operations worked.

## Staging validation evidence

The following is the owner's supplied staging validation evidence; this task did not access staging or independently retrieve the private receipt. The evidence identifies a forward deployment from baseline `3debba99c677b68465cf439733f440442fbd0f22` to target `be427a17320ca7aba9ec292d2c288813df5f7c07`, with deployment exit code 0. Receipt `release-20261017T175616.507178Z-be427a17320ca7aba9ec292d2c288813df5f7c07.json` reports health and migrations passed. The reported Laravel runtime HEAD matches the target SHA.

The owner reports Connector version `0.4.7`, with immutable Connector source revision `9e330854f0e7d5ef073db846245f7008d7ef6ec5`. This is intentionally distinct from the Laravel application target SHA. PR #118 corrected receipt validation to preserve both provenance facts. The previous receipt check incorrectly required the Connector artifact's source revision to equal the Laravel target SHA. Because the unchanged Connector package was reused, its legitimate source revision predated the application release. The fix validates Connector source/package provenance independently, records `connector.source_revision`, and retains `target_sha == runtime HEAD`. The successful receipt on the final target confirms that this provenance mismatch no longer prevents receipt generation; it does not rewrite earlier failed deployment attempts as successful.

Reported HTTP smoke results: `/` returned 301; `/en/`, `/up`, and localized pricing pages returned 200. The protected `.env` and `.git/config` paths returned 403. Guest access to Owner Integrations returned the expected 302 authentication redirect. Both named Supervisor workers were RUNNING. All three maintenance/scheduler markers were ABSENT. `system:check` reported 13 OK and one pre-existing security-scanner WARNING. The pre-deployment database backup was retained.

These results establish the supplied staging validation target and operational checks. They do not claim that every fault-injection case in the plan was exercised; only the checks enumerated above and the exact-SHA CI evidence are recorded as completed.

## Failed attempts and guarded recovery history

The earlier failures remain part of the release record:

1. The first guarded deployment attempt stopped the two named workers and then failed closed because the installed helper interpreted Supervisor's valid `STOPPED` response as a failure. It did not switch the application code. Recovery used the fixed helper operations to restore workers/scheduler and reopen the gate; the earlier run is recorded as failed, not successful. PR #107 corrected the status handling.
2. A later guarded attempt reached receipt generation but failed because the receipt writer required the immutable Connector artifact's `source_revision` to equal the Laravel `target_sha`. The guarded rollback path ran; that deployment attempt remains failed. PR #118 corrected the provenance model.
3. The final owner-reported attempt from the stated baseline completed forward with exit code 0 and a successful receipt on the exact merged target SHA.

The existence of the successful final attempt does not erase or relabel the prior failed attempts or their guarded rollback/recovery actions.

## Findings and residual risks

- M146-F01 and M146-F02 are closed by the recorded effective branch rules and staging privilege evidence.
- M146-F10's fail-closed Supervisor status defect is fixed in PR #107 and its successful deployment path is now represented in the owner-reported final validation.
- M146-F11's Connector/Laravel provenance mismatch is fixed in PR #118 and validated by the successful final receipt evidence.
- The single-maintainer review limitation remains documented: no independent approval requirement is configured. M14.7/M17 should retain independent security review where available.
- M146-F08 remains a low/medium log-integrity validation gap concerning untrusted filenames in CI output. It is not represented as tested or fixed by this staging deployment.
- Bounded-moving toolchain inputs and upstream action/tool trust remain monitored supply-chain residuals as recorded in the dependency/action reports.
- Host-level SSH/key/ACL/rotation details that are not established by the evidence cited above remain subject to the external-state limitations in the findings and GitHub/deployment audit reports.

## Implemented controls and CI evidence

- External Actions are full-SHA pinned; the repository CI policy rejects mutable remote refs, `pull_request_target`, permission widening, missing mandatory jobs, unsafe dispatch input forms, and deployment-path regressions. CI retains `contents: read` and has no deployment job.
- Gitleaks is downloaded with SHA-256 verification and bounded safe extraction; Moto is pinned to `5.2.4`. Composer/npm locks, audits, and source policies remain in place.
- Forward deployment requires the exact authoritative branch tip and successful exact-target CI for all seven required checks; rollback is explicit and separately constrained.
- The deployment receipt records bounded non-secret release evidence, including distinct Laravel runtime and Connector source provenance.
- PR #118 exact-head run `39050627430` and exact merged-primary run `39051589381` passed 7/7. The merged-primary MySQL process/concurrency gate passed. The separately reported local fixtures and prior CI results remain documented in the findings register; no additional tests are inferred from staging smoke results.

## Safety and handoff state

- No production deployment occurred; no application credential rotation occurred.
- The latest previously recorded M12 readback showed all six gates false: destructive retention, profile anonymization, customer-backup deletion, provider deletion, bulk destructive execution, and normal-staging synthetic execution. A new post-deploy readback was not included in the Owner evidence supplied for this documentation task, so this report does not claim a fresh six-gate check.
- Legacy scheduled `backup:retention` remains report/comparison-only; customer-backup deletion and provider deletion remain disabled.
- M14.5 still has no default provider, no AI product feature enabled, zero authorized AI feature paths, and no customer-data AI egress authorization. No real AI credential was introduced into CI/deployment by this work.
- M14.7 and M14.8 remain separate handoffs. M14.7 should include the workflow, dependency, artifact, secret-exposure, and deployment-origin attack paths. M14.8 should confirm that exact-SHA and host-privilege evidence remains current.

## Dependabot sequencing

The controlled dependency sweep has not started. Dependabot PRs remain unmerged, as instructed. No Dependabot merge is part of this closure documentation.

## Closure decision

**Staging validation: complete, based on owner-supplied evidence. Formal documentation closure: PR #117 merge plus successful exact merged-primary CI.** After that evidence is present, M14.6 is ready for the M14.7 handoff, subject to the recorded residuals. Do not begin M14.7 before this closure PR is merged and merged-primary CI passes. No production action is authorized by this report.
