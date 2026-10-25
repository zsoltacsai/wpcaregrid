# M15 restore isolation gate contract — 2026-10-25

## Decision and status

The `.maintenance` file is a shared WordPress path. Token verification followed by a PHP path operation is not an ownership primitive: unrelated WordPress writers do not have to use the Connector advisory lock. Destructive restore execution is therefore permitted only when a host/provider integration supplies `RestoreIsolationGateProvider` and verifies writer quiescence. There is no production host-provider implementation in this change. On unsupported hosts, a restore may validate and stage its source, but it returns `RESTORE_ISOLATION_UNAVAILABLE` before enabling maintenance or changing live files/tables. This is a compatibility restriction pending a reviewed host integration, not a claim that generic WordPress installations now meet the gate contract.

The PR remains blocked from merge until Integration & QA reviews the contract, provider compatibility, and deployment plan. No staging or production provider was installed or exercised.

## Code contract

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

`RestoreTest` now supplies an explicitly synthetic provider for normal local restore tests. New regressions assert that (a) the absence of a provider returns `RESTORE_ISOLATION_UNAVAILABLE` and leaves the synthetic live file unchanged, (b) writer-quiescence loss immediately before file apply stops the actual `JobRunner::poll()` path, leaves the file unchanged, and retains gate/maintenance evidence, and (c) a recovery handoff retains both generations, resumes a pending generation after simulated process loss, and idempotently commits the previous generation. `FileRestorer::apply()` also accepts an optional guard; restore execution supplies the generation assertion before each planned destination entry and again at file mutation boundaries. Existing normal restore, recovery, token ownership, lease loss, SQL import/swap, and failed-unlink retry tests remain in the suite.

The fake provider models the interface only; it is not evidence that any real host can quiesce all writers. A real provider must pass an independent compatibility review for the target host and prove origin/CDN bypass closure, in-flight draining, and cron/queue/CLI/deploy writer control. No claim of host-gate deployment or operational validation is made.
