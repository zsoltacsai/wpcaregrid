# M14.6 Closure Criteria

M14.6 remains **open**. In addition to the milestone definition, closure requires:

- All tracked/untracked workflows and release entrypoints inventoried; no `pull_request_target` executes untrusted code.
- Public fork PR boundary proven: no repository secrets, read-only token, ephemeral hosted runner; no CI deployment or PR-artifact promotion.
- Active ruleset/classic branch protection targets `claude/ecstatic-wozniak-oemyuf`; PR review and all seven stable status checks required; force push/delete restricted; bypass actor explicit and minimal; direct push rejection read back. **M146-F01 is closed only with evidence.**
- Staging `deploy` no longer has broad NOPASSWD root. Target-controlled dependency/build/migration code cannot elevate to arbitrary root; **M146-F02 is closed only with host adversarial proof.**
- All external actions full-SHA pinned or justified; verified update procedure and policy test; least token permissions and protected cache behavior preserved.
- Gitleaks checksum/integrity verified before execution; Moto exact lock/integrity; Composer and npm locks, scripts/plugins, audits and source policies evidenced; moving patch/image/runner policy is explicit.
- Required seven-job CI surface and MySQL process/concurrency test remain active and exact check names recorded.
- Merged-primary exact-SHA CI is a staging release prerequisite in addition to PR-head CI; canceled/parent/sibling run is not evidence.
- Connector artifact remains traceable to source SHA/version/per-file manifest/package SHA; deploy receipt binds CI/source/runtime/migration/build and contains no secrets.
- Fixed origin, read-only deploy key, strict GitHub/staging SSH trust, dirty baseline/target/ancestry controls, deployment locks and scheduler/worker/Nginx gates are verified on staging.
- Fault injection for SHA, origin, privilege, dependency/build/migration/health, concurrency and rollback is green; uncertain migration recovery keeps service gated.
- Exact merged SHA guarded staging validation passes; no production deployment; source-to-runtime SHA matches.
- All six M12 gates false; M14.4 report-only/no deletion; M14.5 no AI enablement/default/customer egress; no secret rotation.
- No unresolved Critical/High M14.6 finding. M14.7 scanner/attack-path campaign and M14.8 integrated closure remain separate.
