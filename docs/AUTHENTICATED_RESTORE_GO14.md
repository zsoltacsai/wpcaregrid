# GO-14 Workstream B — authenticated recovery points and migration receipts

**State: source and synthetic tests only. Production database recovery is
HOLD / NO-GO.** This tool does not authorize any host operation, backup, restore,
DBA window, migration or release. No live database, host, staging data or
production privilege was used to build or test it, and a local test PASS is not
evidence that recovery works on a real MySQL server.

It extends the versioned restore contract in
[ISOLATED_STAGING_RESTORE_V2.md](ISOLATED_STAGING_RESTORE_V2.md) and closes the
source-side gaps recorded for reviewed baseline `fa13359` (GO-13 Workstream C
review, items 3–6). `scripts/authenticated-restore-v3.py` imports
`scripts/isolated-staging-restore-v2.py` and reuses its protected-file readers,
SQL audit, restore engine, schema/ledger/data fingerprints, DEFINER lifecycle
check and cleanup; it contains no SQL import, `DROP`, `CREATE DATABASE`, `GRANT`
or `REVOKE` of its own. The only v2 change is a behaviour-preserving extraction
of its read-only observation helpers (`observe_schema`, `observe_ledger`,
`observe_row_counts`) so restore verification and migration receipts hash the
same observations.

## Authentication model (and its limit)

Every record and receipt is HMAC-SHA256 over its canonical JSON with a per-purpose
subkey derived from one root-protected key file (regular file, owned by root,
mode `0600`, single link, hex of ≥ 32 bytes, no symlinks or writable ancestors).
The key is generated and held by the reviewed capture/restore channel, not by an
operator-written JSON file. This detects any change by a party without the key
and ties each document to its purpose. It is a **symmetric, same-trust-domain**
control: a key holder can issue any record, so it is not an independent
third-party signature, and it does not prove that a root operator observed the
facts honestly. Every receipt carries `limitations` saying so.

## 1. Authenticated capture record

Source policy (root-protected JSON, schema 1) names the source environment/host,
schema, expected backup account and DEFINER, server UUID hash, loopback MySQL
endpoint and protected defaults file. The source identity query also observes
the selected schema's default character set and collation. `capture` runs a
fixed `/usr/bin/mysqldump` command (single transaction, quick, no tablespaces,
GTID purging OFF, `--skip-set-charset`, triggers included, routines/events
excluded), streams bounded SQL into gzip, requires successful
process exit, and creates the archive exclusively (`0600`, root-owned). SQL and
compressed output are limited to 32 MiB, stderr to 16 KiB, runtime to 300 seconds.
Because single-schema mysqldump omits database selectors, capture adds a
deterministic `CREATE DATABASE`/`USE` header using the authenticated schema
charset/collation. Before signing, it decompresses the exact written archive and
runs the same v2 SQL audit used by restore, including the root-policy-approved
DEFINER. Invalid charset changes, selectors, or other unsafe dump SQL therefore
cannot receive a capture record. The synthetic regression fixture models the
MySQL 8.4 single-schema body and versioned trigger session wrappers, including
the project's strict trigger SQL mode. It is not output captured from a real
mysqldump binary; a real binary and MySQL import remain unverified host gates.
Partial archives remain unsigned and cannot be reused. Older capture records
without controlled-process/deployment evidence are rejected and require a fresh
controlled capture. External `capture-begin`
and `capture-finish` are disabled; archive mtime is never evidence of capture.
The account, database, UUID, version and binlog settings must match before/after.

The policy also requires absolute `deployment_receipt` and `active_release`
paths. The successful deployment channel must publish a protected `0600` receipt:

```json
{"schema_version":1,"result":"PASS","application_sha":"<40 hex>",
 "environment_id":"source-env","host_id":"source-host",
 "release_path":"/protected/releases/<40 hex>"}
```

The active symlink and all release-directory ancestors must be root-controlled;
it must resolve to that receipt's release path, named by the SHA. Inside the
release, protected `.wpcg-release.json` must contain exactly `application_sha`
and `deployment_receipt_sha256` (the hash of the receipt's actual file bytes).
The receipt environment/host/SHA must match policy. Receipt hash and current
active identity are checked before and after the dump. The record retains the
receipt hash and successful dump exit status. Defaults files must be root-owned,
single-link regular `0600` files with protected ancestors; symlinks and include
directives are refused before client use. A `[mysqldump]` defaults group is
also refused so the fixed command remains the sole authority for dump-format
options. Policy/defaults drift also stops signing.

This observes the deployment receipt and active release identity. **The source
application SHA is attested by the trusted successful-deployment publisher**;
it is not a DB observation, a full application-tree hash, or something proven by
a root policy field alone. Provisioning these deployment artifacts requires a
separately approved deployment-channel integration; this patch deploys nothing.
Controlled capture currently requires a deployed application. Explicit legacy
pre-bootstrap records remain non-restorable; capture refuses that state.

The record binds application state/SHA, source schema, backup account, server
UUID hash, MySQL version, capture interval, archive ID/size/hash and config
recovery reference. Maximum lifetime is seven days; capture age is at most 72 h.

## 2. Config recovery reference

Only fingerprints and opaque identifiers: `reference_id`, `custody:
protected_escrow`, `locator_id` (`label:identifier`), the APP_KEY and
full-config SHA-256 fingerprints and `verified_at`. Any extra field, path, URL,
`@`, `=`, key/password/token-looking text or long hex/base64-looking identifier is
refused with a fixed code that never echoes the value. At restore the recovered
protected config must reproduce both fingerprints.

## 3. Authenticated restore

`restore` requires, before any database access: a valid, fresh capture record;
the v2 manifest and destination policy; and these bindings — archive
ID/size/hash, source application SHA (both `backup.source_application_sha` and
`source_identity.application_sha`), source schema, source UUID hash, source
environment/host, config fingerprints, manifest issued after capture end, and a
destination whose UUID hash, environment and host all differ from the source and
whose target schema differs from the source schema. It then runs the v2
restore (target schema absent before import, disposable container policy,
trust OFF, DEFINER account locked with exact grants, full schema/trigger/ledger/data
verification) and writes `<receipt>.authenticated.json`, chained by hash to the
capture record, manifest, destination policy and v2 receipt. A failure after the
v2 receipt (chain mismatch, receipt write failure) removes the restored schema
through v2's owner-bound cleanup, or leaves an explicit
`RESTORE_FAILED_CLEANUP_HOLD` with the schema, v2 receipt and journal preserved.
Existing evidence paths are never overwritten, so a retry needs a reviewed
procedure. Limitation inherited from v2: source schema `wpcaregrid` and a
disposable `wpcg_restore_*` container destination only; the production G1
loopback service and schema `wpcg_prod_main` are **not** supported and were
deliberately not forced through v2.

## 4. Migration rehearsal receipt (on a clone)

Restore the same capture record twice with distinct target schemas: a baseline
(never migrated) and a clone. `migration-receipt` then proves, from observation
where possible:

- both restore receipts are authenticated, fresh (≤ 24 h), chained to their v2
  files and share backup hash, capture, source and destination instance;
- the baseline still has its recorded schema, ledger and data fingerprints;
- the authenticated candidate `application_sha` is read from the fixed root-owned
  bare Git store `/var/lib/wpcaregrid/candidate.git`, never a caller worktree or
  migration directory. Its exact `database/migrations` tree (≤ 512 entries,
  ≤ 256 KiB per blob, only mode `100644`, valid migration names) is fully applied: the final ledger equals the candidate inventory,
  nothing unknown, nothing regressed, pending set = final − baseline ledger;
- checkpoints (supplied by the migration controller) are the exact pending
  sequence with canonical sorted-ledger hashes and a final schema hash that
  matches the observed clone;
- if the pinned Phase 2B pair is in the candidate, both file hashes match the
  runbook pins, exactly the six guard triggers match name, event, target table,
  BEFORE timing, ROW orientation, approved definer and exact reviewed MySQL
  ACTION_STATEMENT SHA-256,
  and the definer's SELECT tables are exactly the three reviewed transition
  tables; the definer is locked with exact `TRIGGER` + `SELECT` grants (v2 check);
- the app account has global `USAGE` only, schema-confined grants restricted to the explicit `SELECT`, `INSERT`, `UPDATE`,
  `DELETE` allowlist. DDL, TRIGGER, SUPER, unknown privileges, grant option and
  roles are refused online and offline; the DBA executor has no
  global privilege other than `USAGE`; no foreground session remains for the
  definer/executor; app, definer and executor identities are separated;
- MySQL settings are unchanged (binlog ON, ROW, trust OFF).

DBA grant/revoke/termination times and the guarded allowed/rejected write results
come from the protected rehearsal input and are recorded under `attested`, not
`observed`. Before any rehearsal DB observation a durable authenticated
`IN_PROGRESS` marker is created under the fixed pre-provisioned root-owned
`/var/lib/wpcaregrid/rehearsal-holds/` directory. Its filename hashes the clone
server UUID hash and schema, independent of output path or receipt reissuance.
Failure to persist it stops before database work. Exceptions, process interruption,
validation failures and receipt publication failures retain it. Retries refuse
`FAILED_REHEARSAL_HOLD_PRESENT`; only publication and offline validation of a
durable PASS receipt clears it (directory fsynced). Operators must review and
resolve failed holds outside this tool; no CLI bypass is provided.
`verify-migration-receipt` re-derives consistency and privilege/trigger constraints.

The bare Git store and all contents must be root-owned without group/other write
access, symlinks or special files. Alternates, grafts and shallow stores are
refused; replace objects and inherited Git configuration are disabled. Populate
it through the approved artifact/deployment channel. The commit must exist as a
commit object; no branch/ref resolution or caller path is accepted.

## Commands (root, never run here)

```text
authenticated-restore-v3.py validate-config-reference --config-reference F [--config-path ENV]
authenticated-restore-v3.py capture --source-policy P --archive A --archive-id ID \
        --config-reference F --capture-record R --signing-key K
authenticated-restore-v3.py verify-capture --capture-record R --signing-key K
authenticated-restore-v3.py restore  --release-manifest M --destination-policy D --config-path ENV \
        --receipt RECEIPT --capture-record R --signing-key K
authenticated-restore-v3.py verify-restore-receipt --receipt RECEIPT --signing-key K
authenticated-restore-v3.py migration-receipt --baseline-receipt RB --clone-receipt RC \
        --clone-manifest MC --destination-policy D --rehearsal-inputs I \
        --output OUT --signing-key K
authenticated-restore-v3.py verify-migration-receipt --migration-receipt OUT --signing-key K
authenticated-restore-v3.py cleanup  --release-manifest M --destination-policy D --receipt RECEIPT --signing-key K
```

## Not proven here

Real MySQL import, real grants and `SHOW GRANTS` output on the pinned 8.4 release,
backup-account least-privilege grants for the fixed capture command, key custody/rotation, the production destination mechanism, RPO/RTO,
a clean-bootstrap (no ledger) rehearsal, and production pause/resume integration
remain open items from the GO-13 Workstream C review.
