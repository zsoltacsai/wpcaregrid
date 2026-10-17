# M14.3 Hosting Compatibility & Preflight Closure Report

**Disposition: B — M14.3 functionally complete; non-critical host-evidence residual remains.** The implemented capability controls, regression, merge, and exact-SHA staging deployment are complete. Initial support claims are restricted to the tested fixture envelope. Declared PHP 8.1 and WordPress 6.4 minima, plus several edge-host fixtures, remain explicitly unverified and fail closed for affected high-impact operations. This report does not claim universal host compatibility, guaranteed restore, or production-pilot proof.

## Release lineage

| Milestone | Evidence |
|---|---|
| Planning PR #92 | Reviewed head `7481dedf96cbf2ea22339f9bbbc9565aa6af8cb4`; exact-head CI `38968572184` passed 5/5; merged to primary as `80642e369e86e022f2a2f9d32b6b54d4f2eb28a9`. |
| Implementation PR #93 | Branch `codex/m14-3-capability-preflight`; candidate `d213b5a6bb7adda9911b02f70fff6769dc6c1fd8`; exact-head CI `38983714492` passed; merged as `9e330854f0e7d5ef073db846245f7008d7ef6ec5`. |
| Merge-head CI | Run `38984808200` passed Quality, Server SQLite/MySQL/MariaDB, Connector WordPress SQLite/MySQL/MariaDB. The MySQL process/concurrency step passed. |
| Staging deployment | Guarded `scripts/deploy-staging.sh 9e330854f0e7d5ef073db846245f7008d7ef6ec5`; completed 2026-10-17 00:21:10 UTC. No production deployment. |
| Connector | `0.4.6` → `0.4.7`; Connector API remains `v1`. Staging package verification matched source SHA `9e330854…`; package SHA-256 was verified by the deploy script. |
| Migration | `2026_10_16_000001_create_website_capability_snapshots`; creates one latest snapshot per site and bounded transition history. Existing sites were not backfilled and remain UNKNOWN until evidence is received. |

## Implemented control model

The server now persists a versioned per-site vector for `CONNECTION_READY`, `MONITORING_READY`, `UPDATE_READY`, `BACKUP_READY`, `RESTORE_READY`, `SECURITY_SCAN_READY`, `SECURITY_REMEDIATION_READY`, and `ADMIN_ACCESS_READY`. States are PASS/WARN/BLOCK/UNKNOWN. Initial policy is `compatibility-policy-v1`, evaluator version `1`; dynamic evidence TTL is 1,800 seconds and high-impact operation TTL is 300 seconds. Snapshot history is capped at the latest 50 meaningful transitions.

Connector 0.4.7 sends bounded factual inventory evidence only. Laravel owns compatibility policy. Missing, expired, malformed, or version-mismatched evidence does not grant mutating readiness. Queue and delivery paths recheck high-impact capability separately from actor authorization, entitlement/workflow state, locks, verified backup requirements, and safety gates. No Owner/Operator force override was added.

The single-source release-version finding M143-F01 was not an actual runtime defect: `config/connector.php` derives the current Connector version from `wordpress/wp-caregrid-connector/release.json`. A regression test now guards that relationship. The earlier `0.4.1` observation was stale planning evidence; no duplicated version override was added.

## Tested envelope and compatibility disposition

| Dimension | Tested evidence | Initial disposition |
|---|---|---|
| Connector | 0.4.7 real WordPress jobs under PHP 8.4 with SQLite, MySQL, and MariaDB CI modes; 0.4.5 server-evaluator case and live staging read-only classification | 0.4.7 is the tested current source release. 0.4.5 remains supported-old only for proven read/monitor behavior; mutation capabilities that need the newer contract block. Newer unknown versions do not pass optimistically. |
| Customer-host PHP | PHP 8.4.26 WordPress harness in CI | 8.4 is the tested branch. Header-declared PHP >=8.1 is not a tested range; untested branches are UNKNOWN for high-impact readiness. |
| WordPress | Real WordPress 7.1.2 fixture (7.1.x CI dependency) | 7.1.x is the tested line. Header-declared WordPress >=6.4 is not a tested range; 6.4 is not promoted to supported by metadata alone. |
| Customer DB | MySQL 8.4 and MariaDB 10.11 real WordPress CI; SQLite test mode | These exact CI branches are exercised. No broader minimum or provider-specific database claim is made. Unsupported/unknown DB evidence cannot pass database backup/restore readiness. |
| Layout/topology | Standard fact fixture; custom, unknown, and Multisite evaluator/classifier cases | Standard single-site is the intended baseline. Multisite and custom/root-ambiguous layouts block affected mutation/restore. Bedrock-like and unresolved root/symlink edge fixtures remain explicitly unverified; unknown layout blocks. |
| Filesystem/resources | Standard facts, custom layout and operation-specific low/unknown disk evaluator cases; Connector temporary probe is confined to its dedicated temporary directory and cleans both probe names | No live-content write probe. Unverified permission/rename/container variants remain UNKNOWN/BLOCK; no arbitrary host support claim. |
| Network/polling | Authenticated Connector heartbeat/poll path, bounded payload/protocol CI, staging current connection health | A fresh established connection is evidence for connection readiness. No separate provider/WAF compatibility certification or brand claim. |

The capability truth-table and operation-gating evidence is strongest for standard fixtures, unsupported Multisite/custom layout, missing/stale snapshots, version mismatch, unknown runtime/database branches, low/unknown disk, stale operation-time evidence, and no-queue side effects. The broader planning matrix includes edge-host variants (minimum PHP 8.1, minimum WP 6.4, missing-extension permutations, read-only/rename-denied hosts, quota containers, DB privilege limits, root-symlink and Bedrock fixtures) that were not all run as standalone host containers. They remain unsupported/unknown until such fixtures exist; they are not silently promoted by this closure.

## Capability and staging results

The deployed server release reports Connector latest version `0.4.7`. The existing synthetic M8 WordPress site is still Connector `0.4.5`; it was not re-paired, replaced, or upgraded. It had no persisted capability snapshot before or after validation, which is the safe migration default.

A transaction-scoped staging evaluator/persistence probe used that synthetic site and its actual 0.4.5 version, then rolled back. The resulting policy vector was: CONNECTION PASS; MONITORING WARN; UPDATE BLOCK; BACKUP BLOCK; RESTORE BLOCK; SECURITY_SCAN UNKNOWN; SECURITY_REMEDIATION BLOCK; ADMIN_ACCESS UNKNOWN. After rollback the staging DB had 0 capability snapshots and 0 transition rows. This is version-drift evidence, not a claim that 0.4.5 supports high-risk operations.

Exact-SHA GitHub feature tests passed 15 compatibility readiness tests, including canonical version configuration, no-snapshot/stale refusal with no queued mutation, Multisite/custom-layout blocks, 0.4.5 per-capability behavior, capability-hash invalidation, bounded history, stale-policy behavior, runtime/database UNKNOWN, malformed secret rejection, and authenticated Customer/Operator view scoping/redaction. The authenticated view checks were CI feature tests; no disposable staging Customer/Operator credentials were created for an additional browser login walkthrough.

Guarded deployment checks: exact merged SHA checked out clean; migration completed; Connector 0.4.7 package verified; homepage, HU/EN home and pricing, `/up`, guest Owner auth redirect, and protected `/.env`/`/.git/config` paths passed. Post-deploy `system:check`: application, database, queue, scheduler, Connector, Stripe TEST, Cloudflare, backup storage, mail, safety, privacy retention, privacy restore safety, and privacy exports were OK. Workers restarted successfully. The security scanner remains WARNING: **“No completed security scan yet.”** This remains M14.7 / AS-16.

## CI totals

Exact merge-head run `38984808200`:

| Job | Tests / assertions | Skips |
|---|---:|---:|
| Server SQLite | 1,338 / 19,705 | 19; external/integration tests unavailable in that DB mode, including dedicated process checks |
| Server MySQL | 1,338 / 19,903 | 0 |
| Server MariaDB | 1,338 / 19,705 | 19; same documented mode-dependent skips |
| Required MySQL process/concurrency gate | 15 / 161 across dedicated subprocess cases | 0 |
| Connector real WordPress SQLite | 210 / 1,454 | 24; MySQL-specific backup and poll-lock concurrency cases |
| Connector real WordPress MySQL | 210 / 1,828 | 3: “only meaningful without MySQL” branch and two opt-in hard-kill/redelivery tests requiring the 900-second poll-lock TTL |
| Connector real WordPress MariaDB | 210 / 1,828 | Same 3 documented MySQL-only / 900-second hard-kill cases |

All seven merge-head CI jobs passed. SQLite exclusions for DB backup/poll-lock concurrency are exercised in the required MySQL mode; the Server SQLite/MariaDB skips are the existing explicit MySQL/PCNTL-gated process tests. Quality passed syntax, Pint, dependency and npm audits, build, package consistency, and full-history secret scan. The current “No completed security scan yet” staging warning is not reinterpreted by CI.

## Safety, cleanup, and residuals

The approved staging Ed25519 key was verified as `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`; SSH used strict known-host checking. Pre/post deploy health was read. All six M12 privacy execution settings were false before and after deployment/validation:

- `M12_DESTRUCTIVE_RETENTION_ENABLED=false`
- profile anonymization execution=false
- customer backup deletion=false
- provider deletion=false
- bulk destructive execution=false
- normal-staging synthetic profile execution=false

The separate pre-existing M8 `synthetic_fixtures.controlled_execution_enabled` setting is a site-UUID/purpose-scoped fixture control, not an M12 privacy gate; M14.3 did not exercise it, and its restore setting remains false. No temporary staging users, sites, DBs, files, or jobs were created. The transaction-only snapshot probe was rolled back and absence verified. The guarded deploy retained its routine protected pre-deploy database backup for rollback safety.

Non-critical engineering evidence residual: the package-declared PHP 8.1 and WordPress 6.4 minima, plus standalone fixtures for missing-extension permutations, read-only/rename-denied hosting, Bedrock, and unresolved root-symlink layouts have not been run. These variants remain UNKNOWN/BLOCK for affected write/restore capabilities; they are not included in the supported baseline. M15 must validate actual pilot hosts independently. No new Owner decision is required.

No new compatibility-induced Critical/High issue was observed. No actual restore, backup mutation, update, remediation, admin session creation, provider action, or privacy destruction was run on staging. No production destructive functionality was enabled.

## Handoffs and final disposition

- **M14.4:** may begin after this closure report is merged; owns commercial backup/GFS entitlements and Owner retention controls. Capability readiness remains independent of commercial retention entitlement.
- **M14.7:** AS-16 full detect-only security scan, broader attack-path validation, and independent review remain open. The scanner warning remains visible.
- **M15:** validate compatibility and DR on actual approved pilot hosts; this fixture/CI evidence is not production-host proof.

**M14.3 verdict: B — M14.3 functionally complete, non-critical host-evidence residual remains.** This status does not mean all hosts are compatible, restore is guaranteed, the scanner is complete, or production is ready. M14.4 and M15 are separate milestones; this task does not start them.
