# One-time staging recovery operator

This package is repository-only preparation for the interrupted release. It does not
install an account, alter SSH/sudo policy, touch MySQL, run migrations, or open the
staging gate. Those operations require a separate exact-scope Owner GO.

## Fixed scope

The operator is dedicated to `stg1` and application SHA
`ee3d29818ff1647d82087446dfaf017ca688cb77`. It accepts exactly three SSH commands:

| SSH command | Controller phase | Effect |
| --- | --- | --- |
| `preflight` | `recovery-readiness` | Read only; reports independent blocker codes. |
| `continue-migrations` | `continue-migrations` | Single use; executes only the two pinned migrations after all controller gates pass. |
| `finalize` | `finalize` | Single use; completes gated checks and writes the controller's truthful gate-closed receipt. |

The SSH dispatcher reads only `SSH_ORIGINAL_COMMAND`; every other command, argument,
shell request, or environment-based path override is rejected. It invokes one
root-owned runner through `sudo`. The runner validates a root-owned one-time
authorization manifest, exact artifact bytes, expiry, lock, and replay markers, then
uses `runuser` to invoke the recovery controller as `deploy` from the exact application
checkout. Execution is bounded to 20 minutes and output to 64 KiB stdout / 4 KiB
stderr. The Linux runner is a child subreaper: it kills and reaps adopted descendants
on timeout or failure, rejects a nominally successful controller that leaves children,
and fails closed if process cleanup cannot be verified. Only schema-checked JSON or
fixed refusal codes are returned. No raw SQL, environment values, row data, or command
output is emitted.

The runner never connects to MySQL as an administrator and never changes global MySQL
settings. The dedicated SSH identity is not a member of `deploy`, `www-data`, or MySQL
groups and receives no shell, general sudo, database access, or service-control
capability. The existing recovery controller and root helper remain responsible for
their own exact artifact, backup, application, runtime, migration, and safety checks.

## Required DBA interlock

The recovery readiness phase requires both the pinned original backup and a separate
fresh backup of the current partial database, the isolated-restore receipt, exact
partial-schema state, PHP 8.4 and Nginx checks, closed maintenance gate, active worker
and scheduler fences, and a DBA attestation consistent with the observed MySQL global
setting. The `continue-migrations` phase requires the exact reviewed migration-window
attestation, a bounded restoration watchdog attestation containing an exact unit
identifier/hash and an expiry no more than 30 minutes away, and
`log_bin_trust_function_creators=ON`; it runs only the two allowlisted migrations.
The controller revalidates that attestation before each migration. The DBA must restore the original `OFF` value and independently verify
it before the `finalize` phase. No worker is started, scheduler resumed, or gate opened.

The SSH operator cannot perform the DBA setting change. Before enabling `ON`, the DBA
must have an independently verified, time-bounded restoration watchdog or equivalent
out-of-process recovery mechanism. Its root-owned attestation must bind the fixed
restore action, active/independent assertions, exact timer ID and SHA-256, and an
unexpired deadline no more than 30 minutes away. The controller rereads this evidence
before each migration. A shell exit trap alone is insufficient. If that
mechanism or the DBA authorization is unavailable, recovery remains blocked. Do not
grant `SUPER` or other broad privileges to the application account.

## One-time provisioning contract

Provisioning is a distinct, Owner-authorized host operation after this exact source
has passed review and CI. It requires an Owner-supplied public SSH key; this repository
does not generate, select, or disclose a private key.

1. Install `scripts/staging-recovery-operator.py` as
   `/usr/local/sbin/wpcaregrid-staging-recovery-runner`, root:root `0755`.
2. Install `scripts/staging-recovery-operator-dispatch.sh` as
   `/usr/local/libexec/wpcaregrid-staging-recovery-dispatch`, root:root `0755`.
3. Create a locked-password `wpcaregrid-recovery` system account with a root-owned
   home and no supplementary groups. Use a fixed public-key-only SSH entry with
   `restrict,command="/usr/local/libexec/wpcaregrid-staging-recovery-dispatch"`.
   The private key remains with the Owner. The account cannot edit its home, key,
   dispatcher, runner, config, or bundle.
4. Install a root:root `0600` one-time manifest at
   `/etc/wpcaregrid/staging-recovery-operator.json`. Its exact keys are defined by
   `CONFIG_KEYS` in the runner. Pin the application SHA, recovery-tool SHA, controller
   and verifier byte hashes, installed helper hash, original backup hash, the distinct
   pre-recovery backup hash, MySQL UUID fingerprint, and
   `restore_receipt_sha256` for the root-owned isolated-restore receipt. Include a
   random 128-bit nonce and an expiry no more than 24 hours away. The controller revalidates
   the pinned receipt during migration and finalization. Pin both the original backup
   hash and `pre_recovery_backup_sha256` for the current partial database. The root runner opens the
   root-only receipt with `O_NOFOLLOW`, hashes that descriptor, and passes only that
   descriptor to the deploy controller. The controller rehashes those same bytes.
   The separate partial-state backup is addressed only by the deterministic
   `wpcaregrid-pre-recovery-<application-sha>-<sha-prefix>.sql.gz` name. Both digests
   are returned as migration evidence and included in the final release receipt.
5. Install the exact reviewed recovery bundle below
   `/var/lib/wpcaregrid/staging-recovery-bundles/<recovery-tool-sha>` as root-owned,
   group `deploy`, directory mode `0750`; controller and verifier files are root:deploy
   `0440`. The controller's embedded application/helper/backup/MySQL pins must match
   the manifest. Do not change the application checkout.
6. Create `/var/lib/wpcaregrid/staging-recovery-operator-used` root:root `0700` and
   `/run/lock/wpcaregrid-staging-recovery-operator.lock` root:root `0600`.
7. Add a sudoers rule for `wpcaregrid-recovery` with `env_reset,!setenv`, authorizing
   only the root runner with each exact single argument `preflight`, `migrate`, and
   `finalize`. Validate with `visudo -c`; do not add a shell, interpreter, `runuser`,
   service command, MySQL client, wildcard, or `ALL` grant to this identity.
8. Verify the SSH public-key forced command and restriction flags, exact `sudo -l`
   allowlist, denial of every other argument, runner/bundle hashes, and lack of access
   to the application, backup and DB except through the fixed runner. Revoke the
   account, key, sudoers rule and manifest after the one-time recovery is complete or
   expires; preserve only the sanitized recovery receipt and approved audit record.

The final sudoers command lines must be rendered with the exact installed runner path
and reviewed before provisioning. Never use a wildcard or permit caller-supplied SHAs,
paths, SQL, shell text, or environment variables.

## Operator sequence after a separate consolidated GO

1. Run `ssh <approved-alias> preflight`. It must report a pinned application/tool
   identity, `MUTATIONS=NONE`, a closed gate, no active consumers, the exact backup,
   a valid isolated-restore receipt, and all required runtime/schema/DBA checks.
   `BLOCKED` means stop; it is not a degraded PASS.
2. The authorized DBA independently verifies the dedicated MySQL identity, backup,
   original setting and active restoration watchdog; only then sets the global value
   to `ON` and installs the exact migration-window attestation.
3. Run `ssh <approved-alias> continue-migrations` once. The root runner consumes the
   migration phase authorization before invoking the controller. It cannot repeat
   this phase with the same nonce. The controller validates the partial schema and
   exact pending migration set, then verifies both ledger entries and all six trigger
   definitions. On failure, the gate remains closed and the one-time phase is consumed.
4. The DBA restores `OFF`, independently verifies the observed global value, preserves
   the migration-window evidence, and installs the restoration-phase attestation.
5. Run `ssh <approved-alias> finalize` once. The controller verifies PHP, Nginx,
   Composer, marketing-only configuration, full migration/trigger state, DB restoration
   attestation and health, rebuilds the missing Laravel config cache, and writes the
   release receipt only after all checks pass.
6. Confirm `RECOVERY=VERIFIED_GATE_CLOSED`. The HTTP maintenance gate remains closed;
   both workers remain inert/disabled and the scheduler remains paused. Public staging
   acceptance and gate removal need a separate Owner GO.

Any identity mismatch, expired manifest, replay, concurrent operation, malformed
evidence, active consumer, failed check, timeout, or inability to verify `OFF` is a
STOP. Do not rerun the ordinary deployment controller, edit migration history, drop
tables, run `migrate:rollback`, start workers, resume the scheduler, or remove a gate.

## CI rehearsal boundary

The isolated MySQL CI rehearsal uses a separate, digest-pinned MySQL 8.4.11 service.
It reproduces the two-migration partial state and errno 1419, runs the reviewed
continuation against that database, checks the target schemas, migration ledger and
six trigger bodies, and verifies the simulated global setting is OFF before gated
finalization. Its prior migration ledger is a synthetic fixture; it does not execute
the complete historical migration baseline. Host identity, backup and restore
receipts, DBA attestations, helper/runtime/worker/Nginx gates, and final host commands
are stubbed in that database test and covered by separate deterministic controller
and operator policy tests. It is not evidence of a staging backup restore, live-host
recovery, or deployment readiness.
