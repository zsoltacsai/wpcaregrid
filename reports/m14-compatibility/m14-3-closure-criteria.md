# M14.3 Closure Criteria

M14.3 can close only when all applicable rows below have evidence at exact merged SHA and no unresolved compatibility-induced Critical/High safety issue remains.

- Canonical per-site capability evaluator exists; no global site-supported flag; status/reason semantics and policy/evaluator versions are stable.
- Static/dynamic fact freshness is implemented; stale required facts become UNKNOWN; onboarding, refresh and operation-time cost tiers are bounded.
- Connector facts are bounded, optional/additive, secret-free, treated as untrusted; server owns support policy. Connector 0.4.5 and 0.4.6 are classified per operation from evidence; source latest-version default reconciled.
- Evidence-backed tested bounds for customer PHP/WP/MySQL/MariaDB are recorded. Server PHP/DB CI is not presented as customer host support.
- Standard single-site baseline passes the fixtures; Multisite and OQ-03 excluded layouts are detected or safely UNKNOWN and block affected mutation/restore. No root is guessed.
- Filesystem read/write/rename/containment, disk formula/reserve, DB support, outbound TLS/protocol, polling and scheduling have capability-specific fixtures. Optional caches/REST/loopback do not create blanket blocks absent concrete dependency.
- Backup readiness distinguishes readable/created/verified artifact; restore readiness requires same-site verified source, compatible manifest/objects, fresh host facts, safety backup and no conflict/quarantine. Authorization/entitlement/safety gates remain separate and rechecked.
- UPDATE_READY, SECURITY_SCAN_READY, SECURITY_REMEDIATION_READY and ADMIN_ACCESS_READY are independently evaluated. Scanner readiness never implies a scan completed or no findings.
- Customer-facing localized messages are accurate and redacted; Operator details are useful and scoped; no force-restore override; any Owner summary is minimal and authorized.
- Matrix fixtures and tests pass across required Laravel SQLite/MySQL, Connector real WordPress SQLite/MySQL, process/concurrency, filesystem and fake-network layers; cleanup verified.
- Guarded staging validation runs exact merged executable SHA using strict SSH pin and synthetic site only. Six M12 gates read false before and after; health and cleanup verified.
- Hosting Compatibility Audit has explicit evidence-backed disposition (SUPPORTED BASELINE / SUPPORTED_WITH_WARNING / UNSUPPORTED / UNTESTED-UNKNOWN), not universal host claims.
- Exact-head five-job CI green; audit and M14 backlog updated; M14.4, M14.7 and M15 handoffs remain explicit.

Closure means capability-specific engineering evidence, not guaranteed recovery, universal hosting support, completed security scan, or pilot/production proof. All production destructive/privacy gates remain disabled.

## Final evidence reconciliation — 2026-10-17

| Closure area | Result | Evidence / caveat |
|---|---|---|
| Capability domain, versions, freshness, snapshot migration defaults | `PASS` | `compatibility-policy-v1`, evaluator 1; no migration PASS/backfill; stale/no snapshot denied. |
| Eight capabilities and operation-time gates | `PASS` | Evaluator and queue/delivery gates preserve independent authorization, entitlement, conflicts and safety. |
| Connector fact contract/package | `PASS` | Bounded optional facts; Connector 0.4.7/API v1; exact package consistency CI. |
| Tested support envelope | `PARTIAL / UNKNOWN edges` | Exact PHP 8.4, WP 7.1.x/7.1.2, MySQL 8.4, MariaDB 10.11 fixtures documented; declared PHP 8.1/WP 6.4 minima and specialized host fixtures not tested. |
| Multisite/custom layout | `PASS for fail-closed policy` | Evaluator/classifier coverage; Bedrock and unresolved root-symlink dedicated fixture remain a residual. |
| Filesystem, extension, disk, network, polling | `PARTIAL / scoped` | Disk unknown/threshold, inventory facts, Connector transport and health evidence exist; dedicated missing-extension, read-only/rename, cron replacement and several host cases remain untested. |
| Backup/update/restore/security/admin readiness | `PASS for implemented fail-closed contract; no live restore` | CI tests and existing synthetic restore feature evidence; actual staging restore was not run. Scanner warning remains open. |
| Diagnostics | `PASS in authenticated CI feature tests` | Customer and Operator scoping/redaction asserted; no disposable role credentials were created on staging. |
| Exact CI/staging/gates/cleanup | `PASS` | Merge-head CI and guarded exact-SHA deployment passed; transaction probe left zero snapshots/transitions; all six M12 gates false. |

Disposition is **B — functionally complete with non-critical host-evidence residuals**. Do not widen claims or proceed on UNKNOWN/BLOCK operations. These residuals are excluded from the initial supported envelope and do not bypass the separate M14.4 entitlement work or M15 pilot validation.
