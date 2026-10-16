# M14.2 Ordered Implementation Backlog

Planning only; observations are validation needs unless a reproducible defect confirms otherwise.

| ID / priority / area | Attack path and dependency | Files / expected change | Migration / Connector version | Tests and release acceptance |
|---|---|---|---|---|
| M14.2-01 P0 Credentials | stolen old token, response loss; depends on M14.1 | PairConnector, Connector, auth middleware, plugin Pairing/Connection/CredentialStore; define issue/replace/revoke/recovery | conditional schema; bump if protocol changes | Server + WP tests, MySQL concurrency; exact-site synthetic validation; no stale bearer, recoverable lost response |
| M14.2-02 P0 Scope | foreign/stale valid object; depends M14.1 | API routes/controllers, JobQueue/RecordJobResult/BackupUploads; tests first, smallest proven fix | unlikely migration; bump only wire change | Server SQLite/MySQL, process race; zero effects for foreign/stale IDs |
| M14.2-03 P0 Payload | nested/large payload exhausts resources or drives derived state | controllers, Inventory/Health/Manifest/Result validation and plugin response parser; canonical schema/limits | migration only if normalized; likely version if protocol change | bounded adversarial inputs; valid maxima pass; invalid rejected before persistence/dispatch |
| M14.2-04 P0 Admin access | claim replay or revoke race | AdminAccessService/controller, plugin handoff and job handler | conditional migration/wire version | MySQL/process + synthetic WP; at most one session, no foreign job/session |
| M14.2-05 P0 Filesystem | path escape, symlink race, archive exhaustion | PathGuard, FileRestorer, TarReader, TempDir, FilePolicy | likely no DB migration; Connector version bump for plugin runtime change | disposable filesystem; no out-of-root write; bounded work; cleanup and recoverable failures |
| M14.2-06 P0 Egress | untrusted URL/redirect reaches internal target or leaks auth | plugin API/health/checksum/remediation clients; source-to-sink hardening only if needed | plugin bump if changed | fake network/DNS/redirect; no metadata target queried; no auth leak |
| M14.2-07 P0 Webhook | replay/rotation or duplicate race mutates billing twice | AcceptStripeWebhook, inbox processor/model/config | migration only if new state required; no Connector bump | MySQL/process + Stripe TEST; signature/mode/replay safe, exactly one effect |
| M14.2-08 P0 Integrated closure | cross-wave regression | M14 audit evidence, release/package fixtures | package version only if code changes | five required CI gates, exact SHA, synthetic staging, cleanup, gates false, no Critical/High |

Potential Stripe TEST dashboard access for rotation rehearsal is EXTERNAL_ACTION, not a planning blocker. No Owner design decision is required. M14.3 owns general compatibility UX; M14.7 owns full scan campaign.

## Final evidence — 2026-10-16

Exact-head implementation CI passed and the guarded staging source is `c5e0edabd8bd1661f72eb20330c5ca2f4375b9c5`. Isolated synthetic test-runtime results, post-health, all six false M12 gates, and cleanup verification are in [M14.2 closure report](m14-2-closure-report.md). AS-16 remains M14.7 carry-forward. This milestone does not close M14.3 compatibility work, M14.7 security scanning, M15, or production readiness.
