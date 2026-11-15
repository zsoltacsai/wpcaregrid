# Phase 2B trigger migration — DBA-controlled procedure

**State: operational design; not a production authorization.** The MySQL 8.4.11
synthetic qualification measured error 1419 for the ordinary migration account
with binary logging enabled and `log_bin_trust_function_creators=OFF`. The tested
privilege ladder is reported as `UNRESOLVED_SUPER_ONLY`; this document does not
claim that `SUPER` is the only possible path on every MySQL release.

This procedure is not executable on staging or production until the release
controller has a reviewed pause/resume path for the 1419 boundary, the target
identity and backup/restore receipt are approved, and the exact release SHA is
authorized. The existing recovery implementation must not use its historical
temporary trust-ON path under the current policy.

## Identities and invariants

- **Application/migration account:** retains only approved database-scoped
  application and migration grants; never receives global `SUPER`.
- **Guard definer / DBA executor:** a dedicated durable `user@host` identity,
  distinct from the application account. The trigger migrations omit an
  explicit `DEFINER`, so the login used to create them becomes the definer.
- **Runtime:** ordinary web and worker processes never receive the DBA
  credential. The current application has one DB connection, so a separate
  DML-only production runtime identity is not yet implemented by this runbook.
- Keep `log_bin=ON`, `binlog_format=ROW`, and
  `log_bin_trust_function_creators=OFF`. Do not change these settings.
- The persistent definer needs the tested `SELECT` grants on
  `deployment_transition_fences`, `transition_v2_requests`, and
  `transition_history_events`, plus schema-scoped `TRIGGER`. After installation
  it must have no global privilege beyond `USAGE` and must be locked against
  interactive login. Do not drop or rename it: trigger execution then fails
  closed.

## Required evidence before opening a DBA window

1. Pin the candidate commit and migration SHA-256 values for
   `2026_11_02_000002_create_transition_v2_requests_table.php` and
   `2026_11_02_000003_create_transition_history_events_table.php`.
2. Confirm target server identity, version, binlog/trust settings, database,
   migration ledger, and current trigger inventory through the approved DBA
   channel. Stop on any mismatch or shared/replicated instance whose scope is
   not explicitly approved.
3. Produce a fresh protected database backup, hash and verify it, then restore
   it to an isolated target and record the restore receipt. A provider backup
   statement or synthetic CI run is not this evidence.
4. Close the HTTP release gate; stop and independently verify workers and
   schedulers. Record the release controller's retry checkpoint and the
   expected partial-migration state. No ledger edits and no `down()` rollback.
5. Approve the exact durable definer `user@host`; verify it differs from the
   application identity and is not an existing unrelated account. Configure
   `DB_PHASE2B_TRIGGER_DEFINER` to that exact string in protected application
   configuration before the forward verifier is reached.

## DBA window sequence (operator-owned, not yet controller-automated)

1. Connect through the approved DBA channel and confirm `CURRENT_USER()` is the
   approved definer identity. Confirm the exact candidate database and the
   three MySQL invariants above. Do not put the DBA password in shell history,
   CI variables, application `.env`, web/worker environment, or command-line
   arguments.
2. Grant the dedicated account only the required schema-scoped migration
   privileges plus temporary global `SUPER`. Record the grant time and the
   DBA identity in a protected attestation. If policy or server version
   rejects this grant, stop; do not enable trust or disable binlogging.
3. Run only the two hash-verified trigger migrations against the approved
   database, in order, using Laravel's migration repository and the isolated
   DBA execution environment. Do not run the full migration set under the DBA
   account. Confirm each migration is recorded by Laravel; do not write the
   ledger manually.
4. Revoke global `SUPER` immediately. Terminate every session opened by that
   identity during the grant window; `REVOKE` and `ACCOUNT LOCK` alone do not
   end an already-open session. Confirm the account has no global privilege
   beyond `USAGE`.
5. Revoke unnecessary schema grants. Retain only the three table `SELECT`
   grants and schema `TRIGGER`; lock the account. Confirm the exact account
   still exists and `CURRENT_USER()`/`information_schema.TRIGGERS.DEFINER`
   agree with the approved `user@host`.
6. Resume the remaining migrations as the application account through the
   reviewed controller state machine. Do not invoke a second, improvised
   migration path. The verifier must accept all six trigger definers before
   release admission can continue.

## Stop and recovery conditions

Stop and keep traffic/workers fenced if the target identity or settings change,
the migration ledger differs from the recorded state, either migration fails,
the trigger inventory is not exactly six with the reviewed shape and definer,
`SUPER` remains, an elevated session cannot be terminated, or the final app
migration/verifier fails. Preserve evidence and the database state. Do not
retry blindly, edit the Laravel ledger, run `down()`, relax the global trust
setting, or open the release gate. Recover only through the reviewed isolated
restore and controller recovery procedure.

## Evidence to retain

Record exact release and migration SHAs/hashes, approved target/database
identity, before/after settings and grants, ledger and trigger inventory,
binlog evidence, grant/revoke/session-termination times, verifier result,
restore receipt/hash, cleanup result, and operator identities. Redact passwords
and connection secrets. Synthetic CI receipts must be labelled
**synthetic-only** and must never be represented as a production backup or
restore receipt.
