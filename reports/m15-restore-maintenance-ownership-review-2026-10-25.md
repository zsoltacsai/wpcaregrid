# M15 restore maintenance ownership review — 2026-10-25

## Scope and disposition

This note addresses Integration & QA review comment `6398043793` on PR #182. It records one narrowly fixed retry defect and one unresolved filesystem ownership blocker. It does not claim that the `.maintenance` TOCTOU issue is fixed, and it does not authorize staging, production, or real restore activity.

## Unlink failure: corrected

`Maintenance::disable()` now retains the durable `maintenance.json` marker if removing the verified Connector `.maintenance` file fails. It only removes the JSON marker after the maintenance path is absent, and it leaves the marker in place if marker removal itself fails. A later `JobRunner::poll()` can retry from the persisted, completed restore state. Repeating successful cleanup is idempotent.

The deterministic regression injects an unlink failure for the owned `.maintenance` path, verifies both files remain, then permits unlink and verifies that a retry removes both files and a further cleanup is harmless. This test does not simulate or prove safe conditional deletion under a concurrent path replacement.

## TOCTOU ownership: unresolved by current PHP path APIs

The Connector advisory lock serializes Connector workers only. WordPress core updaters and other site processes are not required to acquire it or honor PHP `flock()` calls. `flock()` is advisory; it cannot make another process's `file_put_contents()`, `rename()`, or `unlink()` cooperate.

The current shared-path operations cannot provide the requested compare-and-delete guarantee. PHP's `unlink($path)` removes whichever directory entry names `$path` at the instant of the system call; it cannot condition the unlink on the inode and bytes previously read. A second `stat()`, `file_get_contents()`, or token check only creates another check/use interval. Likewise, `ftruncate()` on a path-opened file can overwrite data changed in place by a non-cooperating writer after the ownership read. PHP's ordinary path-based filesystem API does not expose an atomic “unlink this name only if it still refers to this verified inode and ownership token” operation.

Therefore a foreign `.maintenance` file can still be at risk if an independent process replaces or mutates the path in the interval between Connector verification and the subsequent path operation. This remains a merge blocker. No test result or passing CI should be represented as closing it.

## Architecture decision requested from Integration & QA

Review one of these designs before claiming the ownership guarantee:

1. **Shared maintenance coordinator:** all supported WordPress maintenance writers, including core-upgrader paths and CareGrid restore/recovery, use one coordinator and lock protocol. The compatibility review must prove that supported core and host writers cannot bypass the protocol. Connector-only locking is insufficient.
2. **Host-provided request gate:** move restore protection to an independently managed web-server/host gate with an atomic, generation-bound state and an owner-controlled release operation. The host integration must fail closed when unavailable; do not silently fall back to unsafe deletion of the shared `.maintenance` path.

The current plugin cannot unilaterally establish either guarantee on arbitrary WordPress hosting. Until Integration & QA selects and validates an architecture, `Maintenance::disable()` still has the reported path-based TOCTOU and PR #182 is not ready to merge. Existing v2 token checks, legacy timestamp recognition, restore lease checks, and SQL-import lease isolation remain in place; these do not solve the shared-path race.

## Validation boundary

The unlink-failure/retry regression is added to `wordpress/tests/Integration/RestoreTest.php`. The earlier `JobRunner::poll()` restore lifecycle, valid-v2 token ownership, invalid-v2 fail-closed, legacy fallback, and unrelated maintenance-file tests remain in the suite. A deterministic passing test for the uncoordinated foreign-file replacement cannot be truthfully supplied until an architecture provides a safe ownership primitive; adding a test seam around an unsafe `unlink()` would only demonstrate the race, not fix it.

No staging/production access, database restore, queue dispatch outside tests, or operational file mutation occurred.
