# M14.6 — CI/CD Supply-Chain and Deployment Trust Plan

**Audit type:** read-only planning, source audit and staging/GitHub state review.
**Audited primary:** `claude/ecstatic-wozniak-oemyuf` at `291f486db41ecc5dcc9c152db5ef0b35d634b404`; planning PR #105 merged as `b16f2c53e7b6763bdfd7bcfea4d5162807bc06ad`.
**Staging readback:** `3debba99c677b68465cf439733f440442fbd0f22`, clean working tree, expected origin.
**Repository:** public `zsoltacsai/wpcaregrid`; default branch is the deployed authoritative branch.
**Disposition:** **D — M146-F02 remains a confirmed High blocker.** M146-F01 was closed by active ruleset `25300175`; staging sudoers remains broad pending PR #106 and explicit host cutover.

## Executive result

The CI workflow is a single non-deploying workflow with seven required validation results, a workflow-wide `contents: read` token, no `pull_request_target`, no deployment credentials, and no artifact upload/download path. Fork pull requests therefore run untrusted code in a read-only/no-repository-secret boundary. The MySQL process/concurrency test set is inside Server MySQL and is part of that job.

At initial audit, two confirmed High control failures interrupted the intended source-to-staging trust chain. F01 is now closed; F02 remains open:

1. **Closed 2026-10-17:** repository ruleset `25300175` now requires PRs and seven exact checks, blocks force push and deletion, requires conversation resolution, and has no bypass actors. The branch API reports `protected:true`; no direct-push probe was attempted. Only the owner is push-capable, so approval count is zero and independent review remains a residual.
2. **Still open:** staging `sudo -l` for `deploy` returns `(ALL : ALL) ALL` and `NOPASSWD: ALL`. Target Composer/npm/Artisan code runs as deploy and can therefore reach staging root. PR #106 prepares a fixed-operation helper/controller conversion, but no helper or sudoers change has been installed.

These are confirmed control-path findings, not proof that either was exploited. F01's direct-push/check-bypass route is mechanically closed by effective GitHub rules. F02 still blocks all new target-code deployment and all remaining M14.6 work until the reviewed helper is installed, broad sudo is removed, and required-operation/arbitrary-sudo probes prove the new boundary.

## Verified baseline and constraints

- M14.5 closure PR #104 merged to the authoritative branch at `291f486…`; closure exact-head run `39023730830` passed 7/7 (baseline supplied and checked against current primary).
- Connector release metadata is `0.4.7`, API v1 (source `wordpress/wp-caregrid-connector/release.json` and repository compatibility evidence).
- Current executable staging SHA read over strict host-key-checked SSH is `3debba99…`; staging `origin` is `git@github.com-wpcaregrid:zsoltacsai/wpcaregrid.git`; status is clean.
- No production host was contacted. The only repository setting change was the F01-specific ruleset; no staging host setting has yet changed.
- Security gates remain fail-closed: M12 destructive retention and all related deletion/anonymization/synthetic gates false; M14.4 `backup:retention` report-only; M14.5 has no product AI feature/default/provider authorization.

## Recommended implementation sequence

1. **14.6-A — Release gate and workflow policy:** introduce a verified required-check/review rule on `claude/ecstatic-wozniak-oemyuf`; require PRs, dismiss stale approvals as appropriate, disallow force-push/deletion and broad bypass; require both PR-head and merged-primary exact-SHA CI before staging. Add a repository-owned static workflow policy checker. The rule is a GitHub admin action and must be confirmed active before relying on it.
2. **14.6-B — Deployment privilege boundary:** replace deployer's unrestricted sudo with a small root-owned, argument-validating deployment helper/commands or a carefully audited command allowlist. Target-controlled Composer/npm/Artisan code must not acquire root authority. Keep web worker unable to modify executable source and preserve current Nginx/scheduler/worker gates.
3. **14.6-C — CI inputs and provenance:** immutable pin all Actions by reviewed full SHA; checksum-verify Gitleaks; pin Moto including transitive integrity; formalize moving patch/tool/image policy; add update automation without auto-merge; preserve audits and all seven checks.
4. **14.6-D — Release evidence:** retain Connector SHA-256/source provenance, add a protected machine-readable deployment receipt, verify CI and host origin/target, and fault-inject failures on staging.
5. **14.6-E — Exact-SHA staging and closure:** only after A–D and all externally administered controls are proven. Do not deploy production.

Two executable PRs are the minimum sensible split: CI/workflow controls and deploy-host privilege/release controls should be independently reviewed; a third provenance/documentation PR is justified if artifact/receipt changes are substantial. The ruleset/admin change must be independently confirmed and cannot be treated as satisfied by a code PR.

## Evidence references

Detailed source and state evidence is in the companion inventory, action pin, dependency, settings, deployment, provenance, fault-injection, test, backlog, and closure-criteria reports. GitHub behavior references are linked in `m14-6-github-settings-audit.md`; mutable upstream release facts are observations at audit time and must be rechecked before pin/update implementation.

## Scope boundary

This is not M14.7, M15, a full scanner campaign, a production release, or an authorization to change settings. The high findings are escalation conditions for M14.6 implementation review. No workflow/runtime code, deployment setting, production setting, or credential was changed or rotated.
