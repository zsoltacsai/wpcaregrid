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
