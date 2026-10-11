# M9 Final Infrastructure Validation & Closure

Status: **NOT CLOSED — mandatory Owner infrastructure gates remain open.**
Candidate worktree: `codex/m9-platform-dr`, based on `f007e19bde7ffbceed0f468466b3eebb72013f07`.

## Owner-confirmed blockers

- Dedicated platform S3 bucket and bucket-scoped credential: **not configured**.
- Independent APP_KEY escrow and retrieval verification: **not completed**.

No S3 operation, secret inspection, escrow attestation, staging mutation, deployment, or backup was attempted. The actual APP_KEY was neither read nor copied.

## Local implementation and validation

The implementation supports encrypted platform database artifacts, destination-specific status, an isolated platform S3 disk, readback verification, Owner controls, database-backed scheduling, read-only reconciliation, and recovery queue safeguards. Google Drive remains optional and is not implemented as an active destination. Retention expiry metadata and Owner settings exist; automatic object deletion is not enabled. Provider-side versioning/immutability and cross-provider replication remain Owner/provider choices.

Local checks on the candidate source before remote CI:

- Full Laravel: 1,048 passed, 23 skipped, 0 failed; 15,591 assertions.
- Focused platform/Owner suite: 48 passed, 1 skipped, 0 failed; 310 assertions.
- Dedicated disposable MySQL 8.4 backup/import drill: 1 passed, 5 assertions.
- Pint: 745 files passed. PHP syntax: 462 files passed. Composer/npm audits, Vite build, migration fresh/rollback/reapply, Gitleaks targeted scan and `git diff --check`: passed.
- MySQL-only process/concurrency tests require a named MySQL/PCNTL test database; S3/SFTP integration tests require `moto_server`/`sshd`. The platform MySQL 8.4 backup/import case was run separately and passed. Remote CI is the remaining validation for these service-backed jobs.

## Remote CI

The first remote run, `38173867682` on candidate `99fcfa6f594e618a55577a6aff0058c11242a7fe`, exposed two fixable issues:

- Manual workflow dispatch left `EVENT_BASE` empty, while this checkout did not contain `origin/main`; the Connector release check therefore exited before evaluating the intended M9 base. The CI workflow now accepts an explicit optional `connector_release_base` input for manual maintenance-branch runs. Push and pull-request behavior is unchanged.
- The SQLite and MySQL suites both showed that the production-local-storage refusal test changed Laravel's already-resolved runtime environment instead of setting the explicit deployment identity. The test now leaves `APP_ENV=testing` intact and sets `platform.deployment_environment=production`, exercising the actual fail-closed branch. Focused local validation after this correction: 12 passed, 51 assertions.

Both Connector jobs passed in the first remote run. The complete corrected remote run is pending and must be recorded here before any closure decision.

## Guarded staging validation

Not run. Repository deployment documentation reports no staging server access has been provided and historical staging SSH host verification was not established. Strict SSH host verification will not be bypassed. More importantly, Owner-confirmed S3 and APP_KEY prerequisites are absent. No staging deployment or runtime check was attempted.

## Required actions before closure

1. Owner reports the Hetzner S3 API is configured. Verify the dedicated private platform-backup bucket, bucket-scoped separate credential, and successful staging write/readback independently; do not infer these from API configuration alone.
2. Store the current APP_KEY in an independent restricted vault and verify retrieval without exposing the key; record an Owner attestation only.
3. Establish and independently verify the staging SSH host identity/access path. Do not use `ssh-keyscan` output as trusted identity without an independent fingerprint source.
4. Run remote CI for the exact candidate commit.
5. After the prerequisites above, use the guarded staging deployment and run a synthetic encrypted platform backup with remote readback/integrity verification, then verify Owner history, audit, readiness, queue and scheduler state.

## Safety

No provider resources, customer data, billing, staging or production were changed. No staging backup or restore was run. M8 evidence and restore gates remain unchanged. The M9 worktree remains isolated from the main checkout.
