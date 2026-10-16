# M14.3 Restore Readiness Matrix

Customer-facing status is concise (“Ready for this recovery point”, “Needs attention”, “Not supported for this operation”, or “Check unavailable”). Operator sees coded vector and timestamps. No status guarantees restore success; M14.2 restore execution and recovery semantics remain authoritative.

| Readiness component / reason family | Status when condition holds | Customer message | Operator evidence | Recheck / test |
|---|---|---|---|---|
| Connector/protocol absent or revoked (`CONNECTOR_OFFLINE`, `CONNECTOR_UNSUPPORTED`) | BLOCK or UNKNOWN | Check unavailable | version, presence, last contact; no credential | heartbeat + operation start; offline fixture |
| Connector old or unknown-newer | WARN, BLOCK or UNKNOWN by required capability | Update Connector / check compatibility | installed/server expected version, affected capability | operation version matrix; never global age-only block |
| Multisite detected | BLOCK for unsupported ambiguous restore/network mutation | This site layout is not supported for this recovery | topology enum; no paths | disposable Multisite fixture |
| Bedrock/custom/unresolved root (`CUSTOM_LAYOUT_UNSUPPORTED`, `CANONICAL_ROOT_UNKNOWN`, `SYMLINK_ROOT_UNRESOLVED`) | BLOCK | This installation layout is not supported for this operation | layout category and reason only | layout fixtures; verify outside-root sentinel |
| Filesystem destination/temp cannot be proven writable/contained | BLOCK / UNKNOWN | Required site files cannot be safely changed | operation/path category, probe time/result; no absolute path | fresh safe preflight + path guard tests |
| Disk reserve insufficient/unknown | BLOCK / UNKNOWN | Not enough verified workspace for this recovery / Check unavailable | free/required byte categories, estimator version | remeasure immediately before restore; quota fixtures |
| DB engine/version/schema unsupported/unknown | BLOCK / UNKNOWN for DB restore | Database is not ready for this recovery | engine/version/charset/prefix; no credentials | read-only metadata check and disposable MySQL/MariaDB restore fixtures |
| Backup missing/unverified/wrong site/object missing/incompatible manifest (`BACKUP_NOT_VERIFIED`, `BACKUP_OBJECT_MISSING`, `MANIFEST_INCOMPATIBLE`) | BLOCK | Recovery point is not ready | backup UUID, verified status, manifest version, object-state codes | fresh storage HEAD/integrity and site scope check |
| Required safety backup unavailable/not protected | BLOCK | A required safety copy is not ready | linked safety-backup state | immediately before execution; failure fixture |
| Active backup/update/restore/remediation or recovery quarantine (`RESTORE_CONFLICT`, `QUARANTINE_ACTIVE`) | BLOCK | Another site operation must finish first | conflict kind/state/time | recheck lock at enqueue and connector execution |
| Authorization/entitlement absent | BLOCK, separately from capability | Action unavailable | actor/policy outcome, no foreign-site details | existing M14.1 RBAC tests; do not expose capability override |
| Safety feature gate/restore disabled | BLOCK | Recovery is currently unavailable | gate identifier/state for authorized internal viewer | server-side gate assertion; never expose override |
| Scanner not run/feed unavailable | does not itself imply restore PASS/BLOCK unless required operation dependency | Security checks are unavailable | scanner readiness distinct from scan result | scanner availability fixture; AS-16 remains M14.7 |
| Stale policy/facts | UNKNOWN | Recheck required | age, policy version | refresh static/dynamic facts; stale-PASS race test |

## Happy path

RESTORE_READY may PASS only for a tested standard single-site layout, compatible active Connector, currently verified same-site recovery point with required objects and compatible manifest, operation-specific file/disk/DB facts freshly proven, required safety backup protected/available, no conflict/quarantine, and applicable authorization/entitlement/safety gates independently satisfied. Readiness is an explainable precondition vector, not a promise or an execution authorization.

## Required negative coverage

Connector offline/unsupported; Multisite; Bedrock/custom/symlink root; unreadable or read-only destination; insufficient/unknown disk; unsupported DB; unverified or missing backup object; active restore/update/backup/quarantine; missing authorization; unmet safety backup; stale fact/policy; unknown/malformed payload. Every negative case must assert no unsafe job dispatch and no destination mutation.
