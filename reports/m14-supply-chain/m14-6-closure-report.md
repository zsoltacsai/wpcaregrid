# M14.6 Closure Report — Staging Validation Blocked

**Status:** M14.6 is not closed. Current verdict is **C — M14.6 VALIDATION / EXTERNAL-SETTING BLOCKER REMAINS**. No production deployment occurred.

## Exact-SHA and merge evidence

- Planning PR #105 merged as `b16f2c53e7b6763bdfd7bcfea4d5162807bc06ad`.
- F02 containment PR #106 merged as `854a26832d864146fc493e75225cf6438ed932b9`.
- Helper status correction PR #107 merged as `dfaa2a9e4209cea310ef2241e58b1fe84e30c3e8`.
- Workflow integrity PR #108 merged as `6e5848fe0d02c70e7684f946c0ad80a8cfd394a1`.
- Exact merged-primary checks for PR #108 passed on run `39034312524`, attempt 2, 7/7. Attempt 1 had a Connector MySQL/MariaDB harness timeout; attempt 2 passed.
- Exact-SHA release authorization/provenance PR #115 candidate `417cf46791b1b13f392454842b7daaebe4d69b7b` passed run `39036319661`, 7/7, including the MySQL process/concurrency gate.
- PR #115 merged as `7d78bd8a44f71178fabc576767bda07a5f75d95d`.
- Merged-primary SHA `7d78bd8a44f71178fabc576767bda07a5f75d95d` passed run `39037273263`, 7/7. The exact-target release verifier independently returned that run ID and seven successful mandatory checks.

## High-finding containment

**M146-F01 — closed.** Ruleset `25300175` applies to deployed branch `claude/ecstatic-wozniak-oemyuf`. Readback confirms PR-only changes, all seven required check contexts, conversation resolution, force-push and branch-deletion blocks, administrator enforcement, and no bypass actors. No direct push was attempted. Required independent approvals remain zero because the eligible maintainer topology is single-person; that residual is explicit and does not reopen the mechanical direct-push/check bypass.

**M146-F02 — closed.** The last staging host readback showed deploy has only the narrow grant for `/usr/local/sbin/wpcaregrid-deploy-priv`, is not in the system `sudo` group, and no longer has `NOPASSWD: ALL`. Installed helper evidence: `root:root`, mode `0755`, SHA-256 `cb71e5ef3a9cdf685cd02515f8484ceffad53750c6748a1335cf1251ab377af1`. Nine harmless arbitrary-root probes and the target-code `sudo -n id` fixture were denied; required fixed helper operations worked.

## Remaining staging blocker

M146-F10's source fix is merged, but the deployed root-owned helper still needs a separate, explicit root/admin update before the reviewed controller can deploy target code. PR #115's helper source at merged SHA `7d78bd8a44f71178fabc576767bda07a5f75d95d` has SHA-256 `7a764d921195b1ce051dd6099c2eace3c24ce587318c3bda9992e1a835bfa910`. It has not been installed on staging.

The Owner selected the platform-console process for this privileged installation. No platform-console control/session is available in this execution environment; browser inspection showed only the staging application and no host console. No SSH authentication workaround, host-key change, sudo broadening, or helper installation was attempted. The latest known executable staging SHA remains `3debba99c677b68465cf439733f440442fbd0f22`. The current staging browser displayed a temporary-unavailable page; no authenticated host readback was made in this turn.

Therefore no new target-controlled Composer/npm/Artisan deployment ran after the reviewed helper update became available. Exact merged-SHA staging validation, post-deploy health, deployment receipt from that candidate, and staging fault-injection evidence remain pending. M14.6 cannot close until an authorized platform-console session installs and verifies the reviewed helper, then the exact merged candidate is deployed through the protected controller and all post-deploy checks pass.

## Implemented M14.6 controls and tests

- Actions are full-SHA pinned; CI policy rejects mutable remote action refs, `pull_request_target`, permission widening, missing required jobs, and untrusted dispatch input forms. CI remains `contents: read` and has no deployment job.
- Gitleaks archive is SHA-256 verified before extraction/execution; Moto is pinned to exact version `5.2.4`. Composer/npm lock, audit, and source policies remain enforced.
- Forward deployment requires target equal the fetched authoritative tip and successful exact-target push CI for all seven checks; rollback is explicit and requires a root-approved SHA. PR-head, canceled, failed, parent, and partial-check runs do not authorize forward deployment.
- Deployment receipt records source/runtime and bounded non-secret release evidence. Connector artifact source/version/package provenance is checked.
- Focused offline tests passed locally: release-CI verifier fixtures, privilege-policy fixtures, deployment receipt fixtures, CI supply-chain policy, Gitleaks archive fixtures, and the deployment HTTP smoke contract (45 scenarios).
- These controls passed exact-head and exact merged-primary CI above. Staging-only fault injection has not been run because the helper update is not installed.

## Safety and handoff state

- No production deploy; no application credential rotation.
- M12 destructive retention, profile anonymization, customer-backup deletion, provider deletion, bulk destructive execution, and normal-staging synthetic execution remain disabled (all six last verified false; no staging mutation occurred in this validation pass).
- Legacy scheduled `backup:retention` remains report/comparison-only; customer backup and provider deletion remain disabled.
- M14.5 has no default provider, no AI product feature enabled, zero authorized AI feature paths, and no customer-data AI egress authorization.
- M14.7 and M14.8 remain separate handoffs. M14.7 must include the workflow, dependency, artifact, secret-exposure, and deployment-origin attack paths; M14.8 must confirm the exact-SHA and host privilege evidence remains current.

## Completion required

1. Use the authorized platform-console process to install the exact reviewed helper source/hash above and the corresponding narrow sudoers state; retain an independent administrative recovery path.
2. Read back helper ownership/mode/hash, `sudo -n -l`, and denied arbitrary-root probes; verify allowed helper operations.
3. Recheck staging health and safety gates before deployment.
4. Use the protected controller from the merged primary tip; verify exact SHA, origin, all seven exact-SHA CI checks, and no dirty checkout.
5. Deploy staging only, verify runtime HEAD and receipt, perform required non-destructive fault injection/health checks, and verify cleanup and all six M12 gates.
6. Reconcile findings and publish the final closure report/PR only after the evidence is complete.
