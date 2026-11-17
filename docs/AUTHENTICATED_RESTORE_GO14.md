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
schema, the exact expected backup account `user@host`, the expected server-UUID
SHA-256, a loopback MySQL endpoint and option file, and the **observed deployed
application SHA** — or the explicit state `pre_bootstrap` with no SHA (a SHA is
never invented; the two states are cross-checked).

```text
capture-begin   authenticate the connection (CURRENT_USER(), DATABASE(), @@server_uuid,
                version, log_bin/ROW/trust OFF) against the policy; write a signed begin record
   ... the existing reviewed backup path produces the private 0600 archive ...
capture-finish  re-observe; stop if anything differs from the begin record; check the archive
                (protected, ≤ 32 MiB, gzip-valid, ≤ 32 MiB expanded, mtime inside the capture
                window); validate the config recovery reference; write the signed capture record
```

The record binds: application state/SHA, source schema, backup account, server
UUID **hash only**, MySQL version, capture start/end, observation digest, archive
ID/size/SHA-256 and the config recovery reference. It is valid for at most 7 days
and is refused as stale when the capture finished more than 72 hours ago. A
`pre_bootstrap_empty` recovery point can be recorded but is refused by restore
(`PRE_BOOTSTRAP_RESTORE_UNSUPPORTED`): the v2 manifest requires tables and a
nonempty ledger and cannot certify it.

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
- the candidate migration directory (≤ 512 regular files, ≤ 256 KiB each, no
  symlinks) is fully applied: the final ledger equals the candidate inventory,
  nothing unknown, nothing regressed, pending set = final − baseline ledger;
- checkpoints (supplied by the migration controller) are the exact pending
  sequence with canonical sorted-ledger hashes and a final schema hash that
  matches the observed clone;
- if the pinned Phase 2B pair is in the candidate, both file hashes match the
  runbook pins, exactly the six guard triggers exist with the approved definer,
  and the definer's SELECT tables are exactly the three reviewed transition
  tables; the definer is locked with exact `TRIGGER` + `SELECT` grants (v2 check);
- the app account has global `USAGE` only, schema-confined grants, no `SUPER`,
  `FILE`, `GRANT OPTION`, account administration or roles; the DBA executor has no
  global privilege other than `USAGE`; no foreground session remains for the
  definer/executor; app, definer and executor identities are separated;
- MySQL settings are unchanged (binlog ON, ROW, trust OFF).

DBA grant/revoke/termination times and the guarded allowed/rejected write results
come from the protected rehearsal input and are recorded under `attested`, not
`observed`. Any refusal after observation begins writes a signed
`<receipt>.failed.json` HOLD marker; a later attempt refuses
(`FAILED_REHEARSAL_HOLD_PRESENT`) until it is reviewed. `verify-migration-receipt`
re-derives all internal consistency offline, so a correctly signed but
inconsistent receipt is still refused.

## Commands (root, never run here)

```text
authenticated-restore-v3.py validate-config-reference --config-reference F [--config-path ENV]
authenticated-restore-v3.py capture-begin  --source-policy P --begin B --signing-key K
authenticated-restore-v3.py capture-finish --source-policy P --begin B --archive A --archive-id ID \
        --config-reference F --capture-record R --signing-key K
authenticated-restore-v3.py verify-capture --capture-record R --signing-key K
authenticated-restore-v3.py restore  --release-manifest M --destination-policy D --config-path ENV \
        --receipt RECEIPT --capture-record R --signing-key K
authenticated-restore-v3.py verify-restore-receipt --receipt RECEIPT --signing-key K
authenticated-restore-v3.py migration-receipt --baseline-receipt RB --clone-receipt RC \
        --clone-manifest MC --destination-policy D --rehearsal-inputs I --migrations-dir DIR \
        --output OUT --signing-key K
authenticated-restore-v3.py verify-migration-receipt --migration-receipt OUT --signing-key K
authenticated-restore-v3.py cleanup  --release-manifest M --destination-policy D --receipt RECEIPT --signing-key K
```

## Not proven here

Real MySQL import, real grants and `SHOW GRANTS` output on the pinned 8.4 release,
backup-account least-privilege grants for `mysqldump --routines --events
--triggers`, key custody/rotation, the production destination mechanism, RPO/RTO,
a clean-bootstrap (no ledger) rehearsal, and production pause/resume integration
remain open items from the GO-13 Workstream C review.
