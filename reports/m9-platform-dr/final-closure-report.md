# M9 Final Infrastructure Validation & Closure

Status: **NOT CLOSED — mandatory Owner infrastructure gates remain open.**
Candidate worktree: `codex/m9-platform-dr`, based on `f007e19bde7ffbceed0f468466b3eebb72013f07`.

## Owner-confirmed blockers

- Owner confirms the Hetzner S3 API is configured. Dedicated platform-backup bucket, separate bucket-scoped credential, and verified staging write/readback: **not confirmed** (Owner is unsure whether the dedicated isolation is complete).
- Independent APP_KEY escrow and retrieval verification: **not completed**.

No external S3 operation, secret inspection, escrow attestation, staging mutation, deployment, or live backup was attempted. The actual APP_KEY was neither read nor copied. CI used only its disposable S3-compatible test service.

## Local implementation and validation

The implementation supports encrypted platform database artifacts, destination-specific status, an isolated platform S3 disk, readback verification, Owner controls, database-backed scheduling, read-only reconciliation, and recovery queue safeguards. Google Drive remains optional and is not implemented as an active destination. Retention expiry metadata and Owner settings exist; automatic object deletion is not enabled. Provider-side versioning/immutability and cross-provider replication remain Owner/provider choices.

Local checks on the candidate source before remote CI:

- Full Laravel: 1,048 passed, 23 skipped, 0 failed; 15,591 assertions.
- Focused platform/Owner suite: 48 passed, 1 skipped, 0 failed; 310 assertions.
- Dedicated disposable MySQL 8.4 backup/import drill: 1 passed, 5 assertions.
- Pint: 745 files passed. PHP syntax: 462 files passed. Composer/npm audits, Vite build, migration fresh/rollback/reapply, Gitleaks targeted scan and `git diff --check`: passed.
- MySQL-only process/concurrency tests require a named MySQL/PCNTL test database; S3/SFTP integration tests require `moto_server`/`sshd`. The platform MySQL 8.4 backup/import case was run separately and passed.

## Remote CI

The first remote run, `38173867682` on candidate `99fcfa6f594e618a55577a6aff0058c11242a7fe`, exposed two fixable issues:

- Manual workflow dispatch left `EVENT_BASE` empty, while this checkout did not contain `origin/main`; the Connector release check therefore exited before evaluating the intended M9 base. The CI workflow now accepts an explicit optional `connector_release_base` input for manual maintenance-branch runs. Push and pull-request behavior is unchanged.
- The SQLite and MySQL suites both showed that the production-local-storage refusal test changed Laravel's already-resolved runtime environment instead of setting the explicit deployment identity. The test now leaves `APP_ENV=testing` intact and sets `platform.deployment_environment=production`, exercising the actual fail-closed branch. Focused local validation after this correction: 12 passed, 51 assertions.

The corrected CI completed successfully:

- Run: [38174476887](https://github.com/zsoltacsai/wpcaregrid/actions/runs/38174476887)
- Tested commit: `424c439c431a3b0d9b07a8ede73cf4671a6df4f4`
- All five jobs passed: Quality (lint/style/dependencies/build/secrets); Server tests on MySQL 8.4; Connector WordPress tests on MySQL; Server tests on SQLite; Connector WordPress tests on SQLite.
- MySQL server suite: 1,071 tests, 15,811 assertions, no skips; required independent-process gates additionally passed (13 tests, 145 assertions).
- SQLite server suite: 1,071 tests, 15,629 assertions, 17 expected MySQL/PCNTL-only skips.
- Connector MySQL suite: 202 tests, 1,737 assertions, 3 skips; Connector SQLite suite: 202 tests, 1,401 assertions, 24 expected MySQL/MariaDB-only or 900-second worker-kill opt-in skips.
- The first run, `38173867682`, failed because manual dispatch had no Connector base SHA and because one test changed Laravel's already-resolved runtime environment. The workflow now accepts an explicit base SHA for manual runs, and the test sets the authoritative deployment marker while keeping `APP_ENV=testing`. Both fixes passed in this run.

## Guarded staging validation

Not run. Repository deployment documentation reports no staging server access has been provided and historical staging SSH host verification was not established. Strict SSH host verification will not be bypassed. Independent APP_KEY escrow is absent, and dedicated bucket/credential isolation remains unconfirmed. No staging deployment or runtime check was attempted.

## Required actions before closure

1. Verify that the configured Hetzner S3 API includes a dedicated private platform-backup bucket and a separate least-privilege bucket-scoped credential; then perform guarded staging write/readback validation.
2. Store the current APP_KEY in an independent restricted vault and verify retrieval without exposing the key; record an Owner attestation only.
3. Establish and independently verify the staging SSH host identity/access path. Do not use `ssh-keyscan` output as trusted identity without an independent fingerprint source.
4. After the prerequisites above, use the guarded staging deployment and run a synthetic encrypted platform backup with remote readback/integrity verification, then verify Owner history, audit, readiness, queue and scheduler state.

## Safety

No provider resources, customer data, billing, staging or production were changed. No staging backup or restore was run. M8 evidence and restore gates remain unchanged. The M9 worktree remains isolated from the main checkout.

## Closure decision

**M9 is not closed yet.** Engineering changes are committed and remote CI is green, but independent APP_KEY escrow and dedicated-bucket/credential isolation remain unverified. Staging SSH identity/access is also not established. Do not enable platform backup scheduling or declare recovery readiness until these gates are evidenced.

## 2026-10-12 guarded staging release update

This dated addendum preserves the preceding report as historical evidence and records the later staging validation. It does not close M9.

### Repository and CI

- Implementation commit: `2ec891c5f49a5c28f952ee5155c1e0bf36774cbc` (`Add owner-managed platform backup destination controls`).
- PR #2 merged into `claude/ecstatic-wozniak-oemyuf` as executable candidate `21ff3396a34c0a16ac08f38eda52c1cefb6196e3`.
- CI run `38179799857` passed all five jobs for the exact implementation commit after the manual workflow dispatch supplied the real primary-branch base SHA. PR CI `38180431165` and primary-branch CI `38181025531` also passed all five jobs; the latter covered the exact deployed merge SHA.
- The earlier CI dispatch `38179161870` failed only because an empty manual base input fell back to nonexistent `origin/main` in this repository. The rerun used `f007e19bde7ffbceed0f468466b3eebb72013f07`; no source workaround or check suppression was used.

### SSH identity and staging deployment

- Host `stg1.wpcaregrid.com`, algorithm ED25519, fingerprint `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`.
- Trust source: fingerprint explicitly confirmed by the Owner in this task; the SSH-presented key matched it. SSH used `StrictHostKeyChecking=yes`; no TOFU bypass was used.
- Previous staging SHA: `f007e19bde7ffbceed0f468466b3eebb72013f07`. Deployed SHA: `21ff3396a34c0a16ac08f38eda52c1cefb6196e3`.
- Guarded deploy completed successfully on 2026-10-11 UTC. It created and gzip-validated `/home/deploy/private-backups/wpcaregrid-pre-deploy-20261011T224501Z.sql.gz`, applied pending platform-backup migrations, rebuilt production caches, restarted only the configured Laravel workers, resumed the guarded scheduler, and passed the deployment smoke checks.
- Staging checkout is clean at the deployed SHA. `system:check` reports application, database, queue, scheduler, Stripe, Cloudflare, backup storage, mail, and safety OK. Existing warnings remain: one website is not reporting in and no security scan has completed yet.
- `wpcaregrid-default` and `wpcaregrid-backups` are RUNNING. Read-only state checks: `active_restores=0`, `pending_website_jobs=0`, `failed_laravel_jobs=0`; `backup.restore.enabled=false` and `synthetic_fixtures.restore_enabled=false`.

### Owner Console and remaining gates

- The Owner Platform Backups page loads at `/owner/infrastructure/platform-backups` and distinguishes system backups from customer-site backups.
- The dedicated platform destination is **Not configured**; storage remains the local/test-only driver. Platform-specific access key and secret are **Not configured anywhere**. The separate customer S3-compatible credential is configured on the Service Credentials page, but it is not reused for platform backups.
- No dedicated bucket identity/private-access check, provider connection test, synthetic upload/readback, or platform database backup was performed. The connection-test and manual-backup controls are disabled until the dedicated destination is configured. No provider resource was created or changed.
- APP_KEY is configured, but the Owner escrow attestation is unchecked and recovery retrieval has not been verified. No key value or fingerprint was read, copied, or recorded.
- Recovery readiness remains **Not configured**; no platform backup history exists. Scheduling remains disabled. Google Drive, automatic retention deletion, provider immutability/versioning and cross-provider replication remain deferred as previously specified.

### Decision

**M9 remains open — Owner action required.** The code is integrated, CI is green, and staging deployment is complete. Before the staging backup E2E can run, the Owner must provision a distinct private platform-backup bucket and separate restricted credential, enter those values directly in Owner Console → Infrastructure → Platform Backups (never chat), and verify a connection. Separately, the Owner must escrow the existing APP_KEY in an independent vault, test retrieval, then record the non-secret attestation in that page. After those actions, continue with the synthetic platform-backup write/readback/integrity/decrypt/import validation. Do not enable scheduling or declare M9 closed before all mandatory checks pass.

## 2026-10-12 provider-test blocker release and staging validation

This addendum records the subsequent provider-test fix and staging result. Earlier entries remain unchanged as historical evidence of the state at the time they were written.

### Root cause and behavior

- The Owner page had used the complete primary-backup readiness predicate for both the provider connection probe and backup execution. That predicate also requires a valid platform artifact-encryption key. On staging the S3 destination and dedicated credentials were present, but the artifact key ID/key were not, so the connection-test control was disabled for an unrelated prerequisite.
- Runtime code now separates provider connectivity readiness from backup readiness. The test control requires the dedicated S3 driver, endpoint, region, bucket and platform-specific credentials. Manual backup still requires the valid artifact-encryption key and the existing full readiness checks; the server endpoint also remains fail-closed.
- After deployment the already-open browser tab still displayed the old response until reloaded. The refreshed page showed the provider test enabled and the manual backup explicitly unavailable because the encryption key is missing. This was stale rendered UI, not a second server-side condition.

### Implementation, regression tests and CI

- Provider-gating implementation commit: `3ab887dd9fa210ee003082a8bc1969ef257825bc`; PR #5 merged as primary candidate `357dddfc8574e2626fd4f04259c1378a67624c25`.
- PR #5 implementation CI run `38213323620` passed all five jobs. Local disposable Laravel validation: focused suite 65 passed, 1 skipped, 1,136 assertions; complete suite 1,067 passed, 23 skipped, 15,774 assertions. Local Connector MySQL suite: 202 tests, 1,775 assertions, 3 documented skips; the poll-lock process test was repeated five times (8 tests/229 assertions each).
- The first CI on PR #5's merge commit, `38214100570`, exposed a separate Connector MySQL test-harness race: child-process JSON output could be read before its write completed, and cleanup could remove the shared lock row before all child processes exited. Test-only correction commit `0087d569c2c64dcae1b64f8103cbad16b7f62674` publishes results with same-directory atomic rename and reaps workers before shared-lock cleanup.
- PR #6 CI run `38215692665` passed all five jobs. PR #6 was merged normally into `claude/ecstatic-wozniak-oemyuf` as `f63c47322da48ae37893ed604dfcbd6458f7ca7b`; primary-branch CI run `38216421542` passed all five jobs for that exact SHA. No force push or history rewrite was used.

### Guarded staging deployment and provider test

- Trusted staging identity was rechecked immediately before deploy: `stg1.wpcaregrid.com`, ED25519, `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`. SSH used strict host-key checking.
- Guarded deployment completed on 2026-10-12. Previous SHA: `22947f27c57113d128f65bc3238ed41efb56d4ed`. Deployed SHA: `f63c47322da48ae37893ed604dfcbd6458f7ca7b`. The deploy created `/home/deploy/private-backups/wpcaregrid-pre-deploy-20261012T071935Z.sql.gz`, found no pending migrations, rebuilt production caches, resumed the guarded scheduler, and restarted the two Laravel workers through the deploy procedure.
- Post-deploy checks: clean checkout at the deployed SHA; both workers RUNNING; no pending migrations; app/database/queue/scheduler/Connector API and safety checks OK; `/up` and public health routes returned 200. Both `backup.restore.enabled` and `synthetic_fixtures.restore_enabled` remain false. Active restores 0, active WebsiteJobs 0, failed Laravel jobs 0. Scheduled platform backups remain disabled.
- Owner Console showed endpoint host `fsn1.your-objectstorage.com`, region `fsn1`, bucket `wpcaregrid`, path-style false, dedicated access/secret credentials configured (values not read or exposed). The authorized, non-destructive bucket connection probe passed at `2026-10-12T07:22:59Z`. The matching audit event `platform_backup.s3_connection_tested` records `result=passed`, `error_code=null`, and the expected endpoint host/region/bucket/path-style. The probe is a bucket-access check; it did not create, upload, read back or delete an object.

### Remaining M9 closure gates

- APP_KEY is configured and the Owner Console records escrow/retrieval attestation (`Owner ID 1`, `2026-10-12T06:07:55Z`). No key value was read or copied.
- Platform artifact encryption readiness is **not configured**: current key ID absent, key material invalid/absent, while Sodium is available. Owner Console reports **Encryption key missing** and explicitly blocks manual backup. Do not bypass this gate or create a throwaway key. Configure the stable key ID and encryption keyring in the approved secret-management channel, independently escrow/recover it, and verify readiness without disclosing values.
- No platform backup, upload/readback, decrypt/import drill, or recurring schedule was run/enabled in this validation. The next safe technical phase, after artifact-key configuration and validation, is one controlled manual platform DB backup → remote readback/hash verification → isolated decrypt/import and schema/data checks. Only then reassess M9 closure.
- Decision: provider-test blocker resolved; M9 remains open for the platform artifact-encryption-key Owner prerequisite and the backup/recovery validation gates. Estimated progress is approximately 95%; this is a planning estimate, not a substitute for the remaining evidence.
