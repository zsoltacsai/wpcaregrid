# M14.6 Closure Criteria

M14.6 remains **open**. In addition to the milestone definition, closure requires:

- All tracked/untracked workflows and release entrypoints inventoried; no `pull_request_target` executes untrusted code.
- Public fork PR boundary proven: no repository secrets, read-only token, ephemeral hosted runner; no CI deployment or PR-artifact promotion.
- Active ruleset 25300175 targets `claude/ecstatic-wozniak-oemyuf`; PR path, seven exact checks, conversation resolution, force-push/delete blocks, no bypass actors confirmed by effective-rules API. Approval count 0 is documented for the single-maintainer topology. **M146-F01 closed.**
- Staging `deploy` has only the root-owned fixed helper grant, is not a member of `sudo`, and cannot execute arbitrary root commands. Helper/controller are root-owned 0755 and non-writable to deploy/www-data; nine harmless sudo probes plus target-code fixture denied; safe helper operations passed. **M146-F02 closed with host readback.**
- All external actions full-SHA pinned or justified; verified update procedure and policy test; least token permissions and protected cache behavior preserved.
- Gitleaks checksum/integrity verified before execution; Moto exact lock/integrity; Composer and npm locks, scripts/plugins, audits and source policies evidenced; moving patch/image/runner policy is explicit.
- Required seven-job CI surface and MySQL process/concurrency test remain active and exact check names recorded.
- Merged-primary exact-SHA CI is a staging release prerequisite in addition to PR-head CI; canceled/parent/sibling run is not evidence.
- Connector artifact remains traceable to source SHA/version/per-file manifest/package SHA; deploy receipt binds CI/source/runtime/migration/build and contains no secrets.
- Fixed origin, read-only deploy key, strict GitHub/staging SSH trust, dirty baseline/target/ancestry controls, deployment locks and scheduler/worker/Nginx gates are verified on staging.
- Fault injection for SHA, origin, privilege, dependency/build/migration/health, concurrency and rollback is green; uncertain migration recovery keeps service gated.
- M146-F10 Supervisor status normalization is fixed, installed through root/admin, and guarded exact merged-SHA staging deployment completes; no target code ran before the privilege cutover; no production deployment; source-to-runtime SHA matches.
- All six M12 gates false; M14.4 report-only/no deletion; M14.5 no AI enablement/default/customer egress; no secret rotation.
- No unresolved Critical/High M14.6 finding. M14.7 scanner/attack-path campaign and M14.8 integrated closure remain separate.
