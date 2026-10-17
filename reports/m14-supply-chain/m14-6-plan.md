# M14.6 — CI/CD Supply-Chain and Deployment Trust Plan

**Audit type:** read-only planning, source audit and staging/GitHub state review.
**Audited primary:** `claude/ecstatic-wozniak-oemyuf` at `291f486db41ecc5dcc9c152db5ef0b35d634b404`; planning PR #105 merged as `b16f2c53e7b6763bdfd7bcfea4d5162807bc06ad`.
**Staging readback:** `3debba99c677b68465cf439733f440442fbd0f22`, clean working tree, expected origin.
**Repository:** public `zsoltacsai/wpcaregrid`; default branch is the deployed authoritative branch.
**Disposition at planning time:** M146-F01 and M146-F02 were closed with direct GitHub/staging readback. PR #106 merged as `854a26832d864146fc493e75225cf6438ed932b9`; root helper v1 and helper-only sudo policy were installed. The first deployment attempt failed closed on a confirmed Supervisor status-handling defect (M146-F10); baseline was restored and healthy with no code switch. This planning report is historical; later final staging validation and the formal-closeout condition are recorded in the [M14.6 closure report](m14-6-closure-report.md).

## Executive result

The CI workflow is a single non-deploying workflow with seven required validation results, a workflow-wide `contents: read` token, no `pull_request_target`, no deployment credentials, and no artifact upload/download path. Fork pull requests therefore run untrusted code in a read-only/no-repository-secret boundary. The MySQL process/concurrency test set is inside Server MySQL and is part of that job.

At initial audit, two confirmed High control failures interrupted the intended source-to-staging trust chain. Both are now closed; the subsequent guarded deploy surfaced a separate fail-closed control defect:

1. **Closed 2026-10-17:** repository ruleset `25300175` now requires PRs and seven exact checks, blocks force push and deletion, requires conversation resolution, and has no bypass actors. The branch API reports `protected:true`; no direct-push probe was attempted. Only the owner is push-capable, so approval count is zero and independent review remains a residual.
2. **Closed 2026-10-17:** staging broad sudoers rule removed and deploy removed from `sudo` group. `sudo -n -l` now lists only the fixed root helper; arbitrary command probes and harmless target-code fixture are denied. The reviewed controller attempt stopped before code switch and helper status handling treated a valid STOPPED state as an error. M146-F10 tracks the correction.

These are confirmed control-path findings, not proof that either was exploited. F01's direct-push/check-bypass route is mechanically closed by effective GitHub rules. F01/F02 are closed. M146-F10 blocks further staging release validation until a reviewed helper correction is installed via a separate root/admin path; general CI supply-chain work may proceed.

## Verified baseline and constraints

- M14.5 closure PR #104 merged to the authoritative branch at `291f486…`; closure exact-head run `39023730830` passed 7/7 (baseline supplied and checked against current primary).
- Connector release metadata is `0.4.7`, API v1 (source `wordpress/wp-caregrid-connector/release.json` and repository compatibility evidence).
- Current executable staging SHA read over strict host-key-checked SSH is `3debba99…`; staging `origin` is `git@github.com-wpcaregrid:zsoltacsai/wpcaregrid.git`; status is clean.
- No production host was contacted. Repository setting change is limited to the F01-specific ruleset. Staging host changes are limited to installing the reviewed helper/controller and replacing broad deploy sudo with the helper-only rule; no application release switch completed.
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

This is not M14.7, M15, a full scanner campaign, or a production release. The M14.6-authorized F01 ruleset and F02 staging privilege boundary are now changed and read back. No production setting or credential was changed or rotated; the first app deployment attempt failed before code switch and staging is healthy on its baseline.
