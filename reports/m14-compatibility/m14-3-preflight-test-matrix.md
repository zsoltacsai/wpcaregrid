# M14.3 Preflight Test Matrix

| ID | Fixture / injected fact | Expected capability result | Layer | Side effects / proof |
|---|---|---|---|---|
| PF-01 | current single-site standard layout; tested WP/PHP/MySQL; writable paths; enough disk; outbound TLS; fresh poll | relevant capabilities PASS where verified | real WordPress SQLite and MySQL + server feature | harmless checks only; no live mutation |
| PF-02 | below evidence-backed PHP/WP floor | dependent operation BLOCK with stable code; unaffected connection where possible | Connector integration + evaluator | no job queued |
| PF-03 | missing one required extension | only dependent capabilities BLOCK/UNKNOWN | isolated PHP configuration | no mutation; capability-specific assertion |
| PF-04 | read-only content, readable files | monitoring PASS; backup reflects exact scope; update/restore/remediation BLOCK | filesystem fixture | sentinel unchanged |
| PF-05 | cannot create/rename/delete in probe target | affected mutation BLOCK; no live target probe | disposable temp/target fixture | probe confined, removed and absence verified |
| PF-06 | disk healthy / near reserve / insufficient / measurement unavailable | PASS / WARN / BLOCK / UNKNOWN respectively per formula | quota abstraction/fault injection | no disk filling; no operation starts on BLOCK/UNKNOWN |
| PF-07 | MySQL and MariaDB fixture, charset/prefix variants; unsupported engine/version | supported tested cases PASS; unknown version WARN/UNKNOWN; unsupported engine BLOCK for DB restore | real WordPress DB CI | destructive restore only disposable DB |
| PF-08 | DNS failure, TLS invalid, timeout, proxy, slow response | connection-dependent UNKNOWN/BLOCK with reason; no leaked secrets; proxy only PASS when actual path works | fake HTTP/network | bounded timeouts; no external probing |
| PF-09 | API/WAF-like 403/406/413/429 | protocol unavailable/limited diagnostic with observed status class; no blame attribution | fake server/proxy | retry/resource limits asserted |
| PF-10 | normal WP-Cron; disabled WP-Cron with real cron; no scheduling; stale polls | support if effective scheduler/poll is fresh; otherwise only task delivery degraded | WordPress fixture/process | no destructive task dispatch |
| PF-11 | Multisite | detectable; diagnostic allowed only proven-safe; ambiguous restore/network-wide/destructive actions BLOCK | disposable Multisite | no real network mutation |
| PF-12 | Bedrock/Composer/custom docroot | unsupported detected or root UNKNOWN; mutation/restore BLOCK | representative filesystem fixture | outside-root sentinel unchanged |
| PF-13 | internal contained symlink vs unresolved root/content symlink | only M14.2-proven contained case may pass relevant operation; unresolved root BLOCK | filesystem fixture | reuse M14.2 path guards, no symlink escape |
| PF-14 | Connector 0.4.5 staging-compatible fixture and 0.4.6 current | per-capability version compatibility; do not globally reject 0.4.5 absent reason | real WP integration | no re-pair of live synthetic fixture |
| PF-15 | heartbeat stale/offline, newer unknown Connector, malformed optional facts | stale/unknown; not optimistic PASS; protocol-independent read state preserved | server feature | no job queued for unsafe action |
| PF-16 | persistent object cache/drop-in | pass unless reproducible impacted operation; verify post-restore cache behavior only where needed | integration when available | no broad unsupported classification |
| PF-17 | Basic Auth/maintenance/WAF external probe blocked while Connector outbound polling works | only external probe capability degrades; connection/backup not falsely blocked | fake network/site | separate external probe from polling |
| PF-18 | capability PASS, then disk/permission/conflict changes before restore | operation-time recheck blocks with current reason | MySQL/process + fake disk fact | no restore job or target mutation |

Every test records policy/evaluator version, fixture versions, reason codes and side-effect assertions. Compatibility checks are harmless; restore/backup mutation tests run only against disposable WordPress/filesystem/database fixtures.
