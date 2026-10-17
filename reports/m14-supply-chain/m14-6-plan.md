# M14.6 — CI/CD Supply-Chain and Deployment Trust Plan

**Audit type:** read-only planning, source audit and staging/GitHub state review.
**Audited primary:** `claude/ecstatic-wozniak-oemyuf` at `291f486db41ecc5dcc9c152db5ef0b35d634b404`.
**Staging readback:** `3debba99c677b68465cf439733f440442fbd0f22`, clean working tree, expected origin.
**Repository:** public `zsoltacsai/wpcaregrid`; default branch is the deployed authoritative branch.
**Disposition:** **D — confirmed High release/deployment trust-chain blockers**. No control or setting was changed.

## Executive result

The CI workflow is a single non-deploying workflow with seven required validation results, a workflow-wide `contents: read` token, no `pull_request_target`, no deployment credentials, and no artifact upload/download path. Fork pull requests therefore run untrusted code in a read-only/no-repository-secret boundary. The MySQL process/concurrency test set is inside Server MySQL and is part of that job.

Two confirmed High control failures interrupt the intended source-to-staging trust chain:

1. GitHub API readback says the authoritative branch is `protected: false`; repository rulesets are empty. The deploy script accepts a full SHA if it is an ancestor of the fetched authoritative branch tip, and the manual staging command does not query a reviewed PR or exact-SHA GitHub check result. A principal with branch write access can push a commit directly and have it deployed without enforced review or required checks.
2. Staging `sudo -l` for `deploy` returns `(ALL : ALL) ALL` and `NOPASSWD: ALL`. Target commit code is executed by Composer post-autoload scripts, `npm run build`, Artisan migrations and package/cache commands as `deploy`; those processes inherit an unrestricted non-interactive root path. A malicious or compromised release can therefore cross the application/deployment-user boundary to root on staging.

These are confirmed control-path findings, not proof that either was exploited. Their combination meets the task's High-path definition. Before executable M14.6 hardening can close, require PR/check enforcement on the authoritative branch and remove broad deploy-user root access through a tightly scoped privileged helper/allowlist that does not grant target-controlled build/migration code arbitrary root.

## Verified baseline and constraints

- M14.5 closure PR #104 merged to the authoritative branch at `291f486…`; closure exact-head run `39023730830` passed 7/7 (baseline supplied and checked against current primary).
- Connector release metadata is `0.4.7`, API v1 (source `wordpress/wp-caregrid-connector/release.json` and repository compatibility evidence).
- Current executable staging SHA read over strict host-key-checked SSH is `3debba99…`; staging `origin` is `git@github.com-wpcaregrid:zsoltacsai/wpcaregrid.git`; status is clean.
- No action in this audit contacted production or changed repository/host settings.
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
