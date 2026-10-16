# M14.2 Closure Criteria

Close only after evidence proves:

1. Connector credential issue, storage, use, rotation/re-pair, revoke, disconnect and failure recovery are documented and tested; revoked tokens are unusable; secrets are absent from logs/UI/exceptions/package.
2. Exact-site binding holds across all 13 Connector route declarations; valid foreign site/job/backup/admin IDs are denied with zero DB, queue, mail, storage/provider, Connector, Stripe, restore or audit-data side effects.
3. Job result and backup transfer are idempotent and revalidate Connector, website, job, object, restore and provider scope immediately before effects.
4. Ingress payloads have evidence-based byte/depth/member bounds and strict schemas; invalid, oversized, unknown-state and stale data fail before mutation/dispatch.
5. Admin claim is one-time, tenant-scoped, expiry checked and safe under duplicate/concurrent claims, revocation and Connector replacement.
6. Egress destinations, redirects, DNS/IP treatment, TLS, timeouts and response sizes are tested; no credential reaches unintended host.
7. Filesystem tests prove path containment, symlink/race resistance, archive type/collision policy, explicit resource ceilings, disk/permission handling and cleanup on disposable WP fixture. Unsupported Multisite/Bedrock/custom roots stay blocked.
8. Stripe signature/time/mode, event uniqueness, transactional idempotency, duplicate/concurrent work, redrive and rotation semantics pass TEST fixtures only.
9. Focused and exact-head GitHub CI is green: Quality/security/build, Server SQLite/MySQL, MySQL process/concurrency, Connector SQLite/MySQL; no unexplained failure/skip.
10. Synthetic staging uses exact merged executable SHA, strict SSH pin, isolated WP/site, fake/TEST providers, cleanup verification and post-health; all six M12 gates false.
11. M14.1, M8/M9, M11/M12 and M13 regressions remain green. No unresolved Critical/High M14.2 defect.

Closure is not penetration-test certification, M14.7 scan completion, launch approval, M15 authorization or production destructive enablement. Preserve “No completed security scan yet” until AS-16 evidence exists.

## Final status — 2026-10-16

Implementation, exact-head CI, guarded staging deployment, isolated synthetic staging tests, post-test health/gate readback, and cleanup are complete as detailed in [M14.2 closure report](m14-2-closure-report.md). The package passed real WordPress Connector tests in CI at 0.4.6. The staging synthetic paired site remains at 0.4.5 because a safe reversible upgrade/re-pair path was unavailable; the closure report classifies this as a bounded validation limitation. All M12 destructive gates are false. AS-16 remains `M14.7_CARRY_FORWARD`.
