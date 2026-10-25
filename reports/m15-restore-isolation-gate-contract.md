# M15 restore isolation gate contract — 2026-10-25

## Decision and status

The `.maintenance` file is a shared WordPress path. Token verification followed by a PHP path operation is not an ownership primitive: unrelated WordPress writers do not have to use the Connector advisory lock. Destructive restore execution is therefore permitted only when a host/provider integration supplies `RestoreIsolationGateProvider` and verifies writer quiescence. There is no production host-provider implementation in this change. On unsupported hosts, a restore may validate and stage its source, but it returns `RESTORE_ISOLATION_UNAVAILABLE` before enabling maintenance or changing live files/tables. This is a compatibility restriction pending a reviewed host integration, not a claim that generic WordPress installations now meet the gate contract.

The PR remains blocked from merge until Integration & QA reviews the contract, provider compatibility, and deployment plan. No staging or production provider was installed or exercised.

## Owner disposition — 2026-10-25

The Owner accepts the fail-closed `RestoreIsolationGate` architecture for PR #182, with a concrete, reviewed provider remaining a launch blocker. This accepts the compatibility behavior (live restore stops before mutation when the provider is absent); it does **not** mean the generic PHP `.maintenance` path race has been solved, that a provider exists, or that a host is compatible. The provider implementation and its host-specific compatibility evidence are separate required work before launch.

Required provider review evidence must identify the exact host/runtime and prove: all public-origin and CDN/cache routes are gated; in-flight mutating requests are drained; WordPress updater, plugin/theme updater, WP-Cron, Action Scheduler, queue workers, CLI, deployment and recovery writers are fenced; the gate persists through process loss; acquire/commit/release operations are generation-bound and idempotent; loss is observable before every live DB/file mutation boundary; and no unrelated actor can clear or bypass the gate. Review must include provider-specific concurrency/crash tests and an operational recovery procedure. An advisory Connector `flock()` or a fake test provider is not sufficient evidence.

## Code contract

### Platform readiness and refusal

The Connector inventory includes only the bounded restore_isolation_provider_id (or null when no valid provider is registered). It does not acquire a gate or claim an active restore lease. The platform accepts this readiness identity only when the snapshot is current, matches the active Connector/site versions, is within the high-impact operation TTL, and its provider ID is explicitly present in compatibility.restore_isolation_provider_ids. The allowlist comes from COMPATIBILITY_RESTORE_ISOLATION_PROVIDER_IDS as a comma-separated list, is empty by default, and ignores malformed IDs. No production/fake provider or hardcoded supported state is enabled by this PR. Before a real provider ID is added to staging or production configuration, its implementation and host compatibility evidence must be independently reviewed and approved; provider_id() must be stable, non-secret, side-effect-free, and syntactically bounded.

This single platform readiness decision drives the Customer and Operator restore affordances, RestoreService::request(), and Restore job admission. Unknown, absent, malformed, stale, or unreviewed provider identity returns the stable RESTORE_ISOLATION_UNAVAILABLE refusal before safety-backup creation, restore-row/job creation, or enqueue. Existing backups, downloads, backup creation, and non-destructive source validation/staging remain separate operations. A provider signal is readiness evidence only: the Connector must still acquire and assert the real host gate before each live mutation. A successful earlier page render or readiness snapshot never substitutes for those execution-time checks.

Adding an ID to the platform allowlist is not deployment approval. It requires provider-specific review evidence described above, covering the exact supported hosting/runtime topology and all bypass/writer paths. The browser-visible restore contract remains fail-closed until that work is accepted.

The WordPress filter `wp_caregrid_restore_isolation_gate` must provide a `RestoreIsolationGateProvider`. It must implement `provider_id()`, idempotent `acquire()`, `assert_active()`, two-phase `commit()`, and generation-bound `release()` as declared in `wordpress/wp-caregrid-connector/includes/Backups/RestoreIsolationGateProvider.php`.

`acquire()` may return success only after the host has atomically:

1. Closed all public request paths, including origin and CDN/cache bypasses.
2. Drained or safely quiesced in-flight requests that could write site state.
3. Paused or fenced supported WordPress core/plugin/theme updater paths, WP-Cron and Action Scheduler, queue workers, CLI operations, and deployment/maintenance writers.
4. Persisted a gate that survives Connector process death and does not expire automatically while restore state is uncertain.

Recovery handoff is two-phase to avoid an unreferenced generation if the process stops between host-gate acquisition and JSON-marker publication. `acquire()` must keep the previous generation active and be idempotent for the same restore UUID/scope, returning the same pending generation after a pre-publication crash. The Connector durably publishes the new generation and a `pending_isolation_releases` list before calling idempotent `commit(current, previous)` to retire prior generations. If a process stops after the new marker is published but before commit, later polling retries the recorded commit. If any writer class or bypass path cannot be bounded, acquisition must fail. Provider identity and generation are persisted only in the restricted maintenance marker; they must never be logged or returned to the platform.

The provider must verify the same generation before the database swap and before each file-apply entry, destination-directory creation, temporary-file write, and final rename. The Connector reasserts the provider lease at these boundaries; the provider itself must keep the host gate durable and must not let it expire or be released by an unrelated process while an apply is active. A failed assertion stops the worker and preserves marker/recovery state. The gate must not be opened merely because a Connector process or lease disappeared.

`flock()` is not accepted as the host gate. It is advisory and does not stop WordPress processes that do not participate.

## Operation compatibility matrix

| Operation | Host gate required? | Supported without provider | Failure boundary |
|---|---:|---|---|
| Create/read a backup | No restore gate | Yes, subject to the existing backup authorization and storage controls | Backup's own preconditions and authorization |
| Validate backup manifest, checksums, scope, archive paths | No | Yes | Reject malformed or untrusted source before mutation |
| Download and stage restore objects / attempt-scoped SQL staging | No host request gate; Connector lease and bounded staging remain required | Yes | Stop and clean only current-attempt artifacts while lease is held; preserve on lease loss |
| Dry-run/preflight plan | No | Yes where the operation is actually read-only | No live writes; current Connector has no separate user-facing restore dry-run job |
| Files-only live apply | **Yes** | **No** | `RESTORE_ISOLATION_UNAVAILABLE` before maintenance/live apply |
| Database table swap and live option restoration | **Yes** | **No** | Same; no `RENAME TABLE` or live option mutation without active gate |
| Full files + database apply | **Yes, one gate held across both scopes** | **No** | No partial apply if gate cannot be acquired; if lost after first mutation, fail-stop and retain gate/artifacts |
| Destructive compensation/rollback | **Yes** | **No** | Preserve protection and require operator recovery if gate cannot be re-established |
| Post-apply health checks | Gate remains held | No independent release | Keep gate until durable success is recorded and reconciled |
| Release/unfreeze | Exact active generation and no pending old-generation handoff | **No** | If generation, durable job state, or cleanup is uncertain, keep host gate closed and retain marker for retry/operator recovery |

The Connector does not automatically run destructive rollback. A host gate does not itself authorize backup, notification, remediation, or customer-visible side effects; those remain subject to their existing controls.

## Recovery and failure behavior

- Unsupported provider, failed quiescence, wrong provider on recovery, or failed gate assertion stops before the next live mutation. If a new generation was acquired but not durably recorded, the old generation remains active; the unpersisted generation is released only while the Connector still owns its ordinary job lease.
- A lost gate raises fail-stop lease loss. The Connector does not continue to the next file/table operation, release the host gate, delete the maintenance marker, or discard recovery artifacts.
- Ambiguous/destructive restore state keeps both the host gate and durable restore marker until a verified recovery succeeds or an authorized operator performs audited recovery.
- Successful cleanup removes only a token-matching Connector marker while the matching host generation is active, then asks the provider to release that exact generation. A failed unlink or provider release retains the durable marker for reconciliation. Recovery generation transfer retains both gates until the new lease is recorded, then commits the old generation; commit retries are idempotent.
- A foreign `.maintenance` path fails closed. The Connector never attempts to adopt it without a matching durable ownership marker and active host gate.

## Regression coverage

`RestoreTest` now supplies an explicitly synthetic provider for normal local restore tests. Provider-absent actual polling tests cover files-only, database-only, full, recovery, and compensating restore paths; they assert the refusal occurs before the destructive marker, preserve live fixture state and durable recovery protection, and never attempt a live swap/apply. Another regression verifies providerless compensation leaves a partially restored synthetic site's files, database value, maintenance file, and recovery marker unchanged. Customer and Operator request tests assert the same fail-closed decision precedes safety-backup/restore job creation and that normal backups remain available. Writer-quiescence loss immediately before file apply also stops the actual `JobRunner::poll()` path and retains gate/maintenance evidence. Recovery handoff tests retain both generations, resume a pending generation after simulated process loss, and idempotently commit the previous generation. `FileRestorer::apply()` also accepts an optional guard; restore execution supplies the generation assertion before each planned destination entry and again at file mutation boundaries. Existing normal restore, recovery, token ownership, lease loss, SQL import/swap, and failed-unlink retry tests remain in the suite.

The fake provider models the interface only; it is not evidence that any real host can quiesce all writers. A real provider must pass an independent compatibility review for the target host and prove origin/CDN bypass closure, in-flight draining, and cron/queue/CLI/deploy writer control. No claim of host-gate deployment or operational validation is made.
