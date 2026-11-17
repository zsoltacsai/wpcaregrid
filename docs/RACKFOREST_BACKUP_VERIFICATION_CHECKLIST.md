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
- Effect on gates: any gate requiring verified backup evidence remains HOLD,
  including a future GO-5.4a execution GO.

## Decision vocabulary

- `BACKUP_VERIFIED=PASS` — checklist items 1–6 recorded with evidence and the
  residual risks in item 7 explicitly accepted by the Owner for the gated
  action.
- `BACKUP_VERIFIED=HOLD` — any item missing (default state).
- `RACKFOREST=OWNER_CONFIRMED_NOT_RESTORE_VERIFIED` — current standing state;
  changes only through this checklist.
