# Phase 2B trigger migration — DBA-controlled procedure

**State: operational design; not a production authorization.** The MySQL 8.4.11
synthetic qualification measured error 1419 for the ordinary migration account
with binary logging enabled and `log_bin_trust_function_creators=OFF`. The tested
privilege ladder is reported as `UNRESOLVED_SUPER_ONLY`; this document does not
claim that `SUPER` is the only possible path on every MySQL release.

The staging-next 1.4.9 source controller now journals a hold immediately before
the first pinned Phase 2B trigger migration and accepts a root-owned receipt to
resume the Laravel migration sequence. This source integration is not a
staging/production authorization. Target identity and backup/restore receipt
must still be approved, and the exact release SHA must be authorized. The
existing recovery implementation must not use its historical temporary
trust-ON path under the current policy.

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

## DBA window sequence

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
6. Produce the receipt below after independently checking the resulting
   database. Install it as `/var/lib/wpcaregrid-deploy/staging-next/state/phase2b-dba-receipt-<transaction-id>.json`, owned by root, mode `0400`, with one link. The transaction ID is printed in the root-owned `db-release-<transaction-id>.json` journal at `DBA_HANDOFF_REQUIRED`. The deployment retry reads this file while holding the normal deployment and database locks.
7. Resume the remaining migrations as the application account through the
   same controller transaction. Do not invoke a second migration path. The
   controller verifies the current target fingerprints and reads the live
   trigger names/definers before consuming the receipt. The verifier must
   accept all six trigger definitions before release admission can continue.

## Controller receipt contract

The controller stops at `DBA_HANDOFF_REQUIRED` after all earlier release
migrations have passed their rehearsal and target checks. It records the exact
pre-trigger schema, ledger, and data fingerprints and keeps the maintenance
gate, worker stop, and scheduler pause in place. Calling the normal app loop
again cannot cross this boundary without successful receipt consumption.

The receipt is a strict JSON object with these exact top-level keys:

```json
{
  "schema": 1,
  "transaction_id": "<32 lowercase hex>",
  "application_sha": "<40 lowercase hex release commit>",
  "artifact_sha256": "<64 lowercase hex release artifact hash>",
  "source_sha256": "<40 lowercase hex previous application commit>",
  "target_uuid_sha256": "<64 lowercase hex target server UUID hash>",
  "database": "wpcg_staging_next",
  "mysql": {"log_bin": true, "binlog_format": "ROW", "trust_function_creators": "OFF"},
  "migration_files": [
    {"name": "2026_11_02_000002_create_transition_v2_requests_table.php", "sha256": "f616d65af26cce1677582b48d3165b8e5811169f1a8735d221b8e5d82ae494da"},
    {"name": "2026_11_02_000003_create_transition_history_events_table.php", "sha256": "fec0ae70fcd373bce7f21ec5dc274c9f53d9961d5c0833b92ab437912741e5c1"}
  ],
  "migration_checkpoints": [
    {"name": "2026_11_02_000002_create_transition_v2_requests_table", "sha256": "f616d65af26cce1677582b48d3165b8e5811169f1a8735d221b8e5d82ae494da", "schema_sha256": "<rehearsed hash>", "ledger_sha256": "<rehearsed hash>", "data_sha256": "<rehearsed hash>", "migrations": ["<complete ledger plus first migration>"]},
    {"name": "2026_11_02_000003_create_transition_history_events_table", "sha256": "fec0ae70fcd373bce7f21ec5dc274c9f53d9961d5c0833b92ab437912741e5c1", "schema_sha256": "<rehearsed hash>", "ledger_sha256": "<rehearsed hash>", "data_sha256": "<rehearsed hash>", "migrations": ["<complete ledger plus both migrations>"]}
  ],
  "before": {"schema_sha256": "<hash>", "ledger_sha256": "<hash>", "data_sha256": "<hash>", "migrations": ["<complete Laravel ledger before the pair>"]},
  "after": {"schema_sha256": "<hash>", "ledger_sha256": "<hash>", "data_sha256": "<hash>", "migrations": ["<complete Laravel ledger after the pair>"]},
  "dba": {"approved_definer": "<user@host>", "temporary_super_revoked": true, "elevated_sessions_terminated": true, "global_privileges_only_usage": true, "account_locked": true, "definer_durable": true, "schema_grants_minimized": true},
  "trigger_inventory": [
    {"name": "<trigger name>", "definer": "<user@host>", "valid": true}
  ],
  "receipt_sha256": "<canonical JSON SHA-256 excluding this field>"
}
```

Each migration checkpoint contains `name`, `sha256`, `schema_sha256`,
`ledger_sha256`, `data_sha256`, and the complete Laravel `migrations` ledger as
observed immediately after that migration. The application reads the definer
from the protected shared Laravel environment and requires it to match every
inventory entry; repeat the inventory object six times, once for each trigger.
The post-DBA ledger must be exactly the paused ledger plus the
two named migrations; the final schema, ledger, and data hashes must match the
isolated rehearsal. It also reads the actual trigger names and definers from
`information_schema` and compares them to the receipt. The migration schema
fingerprint must match the rehearsal result.

The self-hash detects accidental corruption; it is not receipt authentication.
Receipt authority comes from the protected root-only state directory, exact
transaction-bound filename, root ownership, mode `0400`, and single-link check.
Missing, stale, malformed, altered, or mismatching evidence leaves the durable
pause intact and the deployment held. Repeating a completed resume does not
advance the cursor again; the receipt is ignored once the journal has passed
the pause state.

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
