# Rackforest backup verification checklist (Owner evidence record)

Status: **checklist only.** This document defines what the Owner must record and
verify before a Rackforest snapshot or backup may be treated as recovery
evidence for any WP CareGrid gate (including GO-5.4a). It authorizes no host
operation, no restore attempt on the running VPS, and no production action.

**Standing rule:** the Rackforest snapshot's existence is **OWNER-CONFIRMED
only**. A backup's existence alone must never produce `BACKUP_VERIFIED=PASS`.
Only recorded, reviewable restore evidence can. Until then every gate that
depends on it stays HOLD and the status remains
`RACKFOREST=OWNER_CONFIRMED_NOT_RESTORE_VERIFIED`.

## Why this exists

The GO-20.8 preflight and the GO-5.4a hotfix acceptance both require verified
recoverable backup evidence before an execution GO. Host read-only tools cannot
verify recoverability: they can only confirm that files exist. Restore proof
requires an isolated test, and the running VPS must never be the target.

## Checklist

Record each item with concrete evidence. An item without evidence is marked
NOT VERIFIED, never assumed.

| # | Item | Evidence required | Status |
|---|---|---|---|
| 1 | Backup/snapshot identifier | Provider snapshot ID or backup file name, plus the provider console reference (screen or export) | NOT VERIFIED |
| 2 | Creation timestamp | Exact provider timestamp (UTC) of the snapshot/backup creation, from the provider, not from memory | NOT VERIFIED |
| 3 | Exact VPS and disk scope | Which VPS (name/IP/UUID) and which disks/volumes are included; full-image vs files-only; whether the backup contains the boot disk, the MySQL datadir volume, or only parts | NOT VERIFIED |
| 4 | Retention and recovery availability | Retention policy (how many copies, how long), the retention schedule, and confirmation the snapshot is within its retention window at the planned recovery time | NOT VERIFIED |
| 5 | Restore method | The exact provider-documented restore path (new VPS from snapshot, volume re-attach, file-level restore) and its prerequisites | NOT VERIFIED |
| 6 | Isolated restore test evidence | A restore performed into a **disposable, isolated** environment (never the running VPS): what was restored, when, by whom, and the verification performed afterwards (boot/disk checksums, application/database readability, `mysqld` startup on the disposable target only) | NOT VERIFIED |
| 7 | Remaining risks and missing proof | Explicit list: what the isolated test did NOT prove (RPO window between snapshot and incident, application-level integrity, encryption key custody, provider-side consistency), and which of those still gate the decision | NOT VERIFIED |

## Evidence rules

- Restore tests run only in a disposable environment. Nothing may be restored
  to, mounted on, or overlaid onto the running VPS.
- The isolated restore test must state whether the restored MySQL data was
  actually opened (server started on the disposable target and key tables
  checked) or merely extracted. "Files present" is not restore proof.
- Record the snapshot's age at test time and the data-loss window it implies.
- Evidence older than the most recent destructive or semi-destructive host
  operation is stale and must be refreshed before it gates anything.
- Every PASS claim needs a pointer (provider reference, isolated-test record
  with date and operator) that an independent reviewer can inspect.

## Current status (2026-11-17)

- Existence: **OWNER-CONFIRMED** (Rackforest performs backups; snapshot exists).
- Recoverability: **NOT VERIFIED** — no restore evidence available yet.
- **Owner risk-acceptance (2026-11-17, GO-20.9 amendment):** for the GO-5.4a
  preparation scope the restore-test requirement is **OWNER-WAIVED** and
  recorded below as `BACKUP_WAIVER_ACCEPTED` (with explicit residual risks).
  This does not verify anything and does not produce `BACKUP_VERIFIED=PASS`.
- Effect on gates: the GO-5.4a preparation/decision gate may record the waiver
  as the Owner's backup-evidence decision; every other backup-dependent gate
  remains HOLD.

## Owner risk-acceptance exception (GO-5.4a preparation scope only — 2026-11-17)

The Owner has issued a narrowly scoped amendment (GO-20.9): for the GO-5.4a
**preparation** (the read-only preflight and any future decision about the
AppArmor socket-lock hotfix transaction on the G1 instances), the isolated
restore-test requirement is **waived**. No isolated restore test may be
requested or performed under this waiver, and none was performed.

| Field | Record |
|---|---|
| Backup existence | OWNER-CONFIRMED (Rackforest VPS backup exists) |
| Restore tested | NO |
| Restore-test requirement | OWNER-WAIVED (GO-20.9 amendment, 2026-11-17; no restore test requested or performed) |
| Existing backup contents and recoverability | NOT INDEPENDENTLY VERIFIED (identifier, timestamp, exact disk scope, retention and provider restore method remain unrecorded — checklist items 1–6 are NOT VERIFIED) |
| Recorded status | `BACKUP_WAIVER_ACCEPTED` — **not** `BACKUP_VERIFIED=PASS`; PASS stays reserved for recorded restore evidence |

### Explicitly documented residual recovery risks (accepted by the Owner for this scope)

1. **Unknown snapshot age (RPO window):** the age of the newest recoverable
   backup is not recorded, so the data-loss window for any incident is unknown.
2. **Unknown content scope:** whole-image versus partial (which disks/volumes)
   is not recorded; a future restore might not contain everything expected.
3. **Untested recoverability:** the restore path has never been exercised; it
   may fail or be incomplete precisely when needed.
4. **Consistency and custody unverified:** crash-consistent versus
   application-consistent snapshots, and any encryption/key custody, are
   unrecorded.
5. **Mitigation context for this specific scope:** the GO-5.4a transaction
   itself does not read or write either datadir or log; its primary recovery
   path is the transaction tool's own hash-checked rollback to the exact OLD
   five-file state. The provider backup is defense-in-depth for failures
   outside the tool's scope, and its unverified state is the accepted risk.

### Scope boundary (no weakening of unrelated gates)

- This waiver applies **only** to the GO-5.4a preparation/decision gate in
  [PRODUCTION_G1_MYSQL_GO54A_PREFLIGHT.md](PRODUCTION_G1_MYSQL_GO54A_PREFLIGHT.md)
  and the matching precondition of
  [PRODUCTION_G1_MYSQL_RUNBOOK.md](PRODUCTION_G1_MYSQL_RUNBOOK.md).
- It does **not** satisfy or relax: the staging deployment backup/restore
  gates in [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md) (fresh verified
  app/config/database backup bound to the release, restore proof, migration
  rehearsal), any GO-5.4 MySQL start or restore activity, or any future gate
  that names `BACKUP_VERIFIED=PASS`.
- Any later execution GO for GO-5.4a must re-confirm this waiver is still the
  Owner's position; it is not a standing approval.

## Decision vocabulary

- `BACKUP_VERIFIED=PASS` — checklist items 1–6 recorded with evidence and the
  residual risks in item 7 explicitly accepted by the Owner for the gated
  action.
- `BACKUP_VERIFIED=HOLD` — any item missing (default state).
- `BACKUP_WAIVER_ACCEPTED` — the recorded Owner risk-acceptance for the
  GO-5.4a preparation scope (see the exception section above); explicitly NOT
  `BACKUP_VERIFIED=PASS` and never usable where a gate demands verified
  restore evidence.
- `RACKFOREST=OWNER_CONFIRMED_NOT_RESTORE_VERIFIED` — current standing state;
  changes only through this checklist.
