# Production G1 MySQL — GO-5.4a preflight and installation readiness

Status: **preparation only.** This document prepares and records the preflight
for the GO-5.4a AppArmor socket-lock transaction. It authorizes nothing. No
host execution, AppArmor load or reload, MySQL start or initialization, or
GO-5.4a transaction may be performed from this document. A separate, exact-scope
Owner GO is required, and per GO-20.8 the GO-5.4a installation itself remains
**HOLD** until the preflight passes, independent Integration QA and AppSec pass,
and the Owner explicitly decides.

Related: [GO-5.4a hotfix](PRODUCTION_G1_MYSQL_GO54A_APPARMOR_HOTFIX.md)
(transaction scope, OLD/NEW pins, rollback semantics) and
[Rackforest backup verification checklist](RACKFOREST_BACKUP_VERIFICATION_CHECKLIST.md)
(backup evidence gate).

## Verified toolkit baseline (2026-11-17, GLM-5.3 session)

- Transaction source of record: `scripts/production-g1-ops/go54a-upgrade-apparmor.py`
  and `go54a-fetch-package.sh` at protected-primary ancestor commit
  `0951d9e829364296796a1d4acd303feef698cbc7`; verified byte-identical at current
  primary `ef4e1d1ea6f4bc928d52bf06137ca242312e23a7` (no drift).
- Payloads pinned by `scripts/SHA256SUMS` (digest
  `0846e20f10495edfececc96aa373a7dca774777ccc4cca6645c63f183d39fb85`) equal the
  NEW `/usr/local/libexec` targets in the hotfix document.
- New read-only diagnostics (correction round v1.2.0 after the independent
  reviews; review pending) live in their own toolkit directory
  `scripts/production-g1-diagnostics/` with its own `SHA256SUMS` (the
  `production-g1-inventory` precedent). The frozen, GO-0-anchored
  `scripts/production-g1-ops/` transaction toolkit and its `SHA256SUMS`
  (digest `70f92e3a2aba84ca13f5d6c81f732be605c50a09531308878991f7b671c48a9e`,
  23 entries) remain byte-identical and untouched:
  - `scripts/production-g1-diagnostics/go54a-readiness.py`
    (SHA-256 `56982ee0dadc6422b94138bfb70318eaaa7e1ec12d7868894a0681cff0fe606d`)
  - `scripts/production-g1-diagnostics/g1-notify-evidence.py`
    (SHA-256 `3c0b7841a7885fd2e19cc03453ef4173e23d0b41874d39df9d8824a3c8d4dc87`)
  - `scripts/production-g1-diagnostics/SHA256SUMS` (file digest
    `555bc0f805fc01fd4343309cd66807bf598bb78885ef49f2ab843e42cd2b0539`)
  - Reviewer corrections included in v1.1.0: the installed `MANIFEST` path now
    matches the transaction tool exactly; parent-directory safety checks for
    `/usr/local/libexec` and `/etc/apparmor.d`; an upgrader-grade datadir walk
    (every entry stat'ed and every file opened through the walk's directory
    FD with identity comparison, closing the path-reopen TOCTOU); the `/root`
    staging scan runs in both modes (an explicit `--package` no longer hides
    an unverified incoming directory); duplicate/empty `--package` arguments
    fail closed; companion verification supports the sibling layout without
    confusing this directory's own `SHA256SUMS` with a partial ops companion
    set; the notify collector requires the approved root console (non-root is
    UNKNOWN, never a verdict), counts malformed DENIED lines as visible
    unrecognized instead of target evidence, and accepts `--profile NAME`
    basenames under `/etc/apparmor.d/` only.
  - Reviewer corrections included in v1.2.0 (AppSec round 2): the datadir
    aggregate bound is charged against bytes actually read and a file that
    mutates between the walk's stat and the completed read fails closed as
    UNKNOWN (`datadir_changed_during_read`); notify targets require one of
    the two EXACT G1 profile names and a path/address field value that is
    EXACTLY the notify path (prefix lookalikes and near-miss paths never
    count); the recognized audit shape requires both masks plus pid/comm; ANY
    unrecognized DENIED record makes the overall status UNKNOWN (valid rows
    stay visible as context, never a PASS); the journal read is byte-capped
    (`MAX_JOURNAL_BYTES`, streaming read, kill on overflow →
    `journal_exceeds_bounds` UNKNOWN); untrusted evidence and rejected
    arguments are control-character sanitized in the output.
  - Reviewer corrections included in v1.3.0 (AppSec round 3): the journal
    read enforces its deadline while stdout is idle (selector-based reads,
    kill and reap on timeout — a stalling producer can no longer hold the
    collector); the datadir post-read consistency check compares device,
    inode, size, mtime AND ctime for both the still-open descriptor and the
    re-stat'ed pathname (same-size inode replacement or an in-place rewrite
    with restored mtime are caught through the never-restorable ctime and
    inode identity); the accepted `--since` value is sanitized before it
    reaches the output.
  - Reviewer corrections included in v1.4.0 (AppSec round 4): the datadir
    walk records each enumerated directory's identity/change metadata and
    re-verifies every traversed directory after the whole walk (an entry
    added, removed or replaced during traversal fails closed instead of being
    omitted from the fingerprint); the log check binds validation and hashing
    to the SAME opened descriptor (`read_regular_stable`) with a post-read
    identity/ctime comparison (concurrent replacement or rewrite fails closed
    as UNKNOWN); the bounded journal read re-checks the deadline after a
    readability event (a late EOF at or past the deadline is a timeout, never
    a success); output sanitization covers DEL, C1 controls and Unicode
    line/paragraph separators, and is applied to systemctl unit lines,
    profile-source lines and unit-property values; the notify exit-code
    contract is documented (exit 0 = collection completed; the final line
    carries the classification — gate on the line, not the exit code alone).
  - Reviewer corrections included in v1.5.0 (AppSec round 5, readiness tool):
    every child directory's opened identity/change metadata is compared
    against the stat its PARENT recorded before the walk descended (closing
    the window between a parent's listing and the child's own level capture;
    an unvisited-directory mismatch also fails closed); all
    mutation-during-read conditions classify uniformly as UNKNOWN; the log
    check verifies that the pathname still names the opened descriptor
    (initial lstat vs descriptor, plus a post-read path stat vs the still-open
    descriptor); and the emission boundary sanitizes every result detail,
    staged-package name, companion directory and vendor profile line.
    The notify tool is unchanged in this round (v1.4.0).
  - Reviewer corrections included in v1.6.0 (AppSec round 6, readiness tool):
    the four critical transaction-relevant checks (lock state, both G1 units,
    the installed baseline, transaction leftovers) are RE-RUN quietly at the
    end and any deviation from the initial observation classifies the whole
    evidence set as stale (UNKNOWN `evidence_stale`) — a concurrent GO-5.4a
    transaction can no longer hide between the first observations and the
    final verdict; the open-identity datadir mutation now classifies as
    UNKNOWN uniformly; the log pathname stat happens while the descriptor is
    still open; the per-level enumeration bound residual is documented.
    The notify tool is unchanged in this round too (v1.4.0).
  - Neither is approved by its local synthetic tests; independent Integration QA
    and AppSec PASS records are required before their output is used in a GO
    decision.

## Readiness evidence collector

Run from the approved root console, read-only:

```
python3 -I -B /path/to/scripts/production-g1-diagnostics/go54a-readiness.py [--package DIR]
```

`--package DIR` additionally validates an already staged GO-5.4a package
directory (root:root 0700, exactly the three payloads plus `SHA256SUMS`,
root:root 0600 each, hashes equal to the NEW pins). Without it, `/root` is
scanned for `wpcg-go54a-*` staging directories; an unverified
`*.incoming-UNVERIFIED` directory is a HOLD, an unrecognized or invalid staged
directory is a HOLD, and nothing is accepted implicitly.

Classification is fail-closed:

- `G1_GO54A_READINESS=PASS` (exit 0) — every host-technical item verified.
- `G1_GO54A_READINESS=HOLD` (exit 3) — a precondition is violated or an unsafe
  state is detected (including the expected post-transaction
  `already_upgraded_baseline=new`).
- `G1_GO54A_READINESS=UNKNOWN` (exit 4) — evidence could not be collected
  (for example not run as root, AppArmor state file unreadable, datadir beyond
  the tool's bounded walk).

`BACKUP_EVIDENCE=UNKNOWN` is always reported: a host read cannot verify backup
recoverability. It is a separate Owner item (see the Rackforest checklist) and
never turns a technical PASS into a false GO.

## Complete preflight checklist

Every item is required before the Owner may issue a GO-5.4a execution GO.

| # | Preflight item | Required state | Evidence |
|---|---|---|---|
| 1 | Both G1 systemd units | `mysql@wpcaregrid-production.service` and `mysql@wpcaregrid-production-restore.service` exactly `LoadState=loaded`, `ActiveState=inactive` (masked, not-found, active or failed are HOLD) | readiness `g1_units_inactive` |
| 2 | Five installed artifacts | Each of the five pinned paths exists as a regular root:root file with the exact mode and the exact OLD SHA-256; any NEW hash is reported as already-upgraded (HOLD for this transaction), anything else is a mismatch | readiness `installed_baseline` (`baseline=old`) |
| 3 | Both G1 AppArmor profiles | `wpcaregrid-mysql-production` and `wpcaregrid-mysql-production-restore` each loaded exactly once and `(enforce)`; the vendor `/usr/sbin/mysqld` loaded line present exactly once | readiness `apparmor_enforced` |
| 4 | Vendor MySQL identity | `mysql.service` active with a numeric MainPID whose `/proc/<pid>/comm` is `mysqld`, and the MainPID stable across the run | readiness `vendor_mysql_service` + `vendor_mysql_stability` |
| 5 | Vendor profile source | `/etc/apparmor.d/usr.sbin.mysqld` regular root:root, digest recorded for pre/post comparison | readiness `vendor_profile_source` |
| 6 | Datadirs preserved | Both instance datadirs present, mysql:mysql, no group/other-write entries, no symlinks or special entries, bounded combined fingerprint recorded | readiness `datadirs_intact` |
| 7 | Logs preserved | Both instance logs regular mysql:mysql 0600, bounded combined fingerprint recorded (no log content is printed) | readiness `mysql_logs_intact` |
| 8 | No active/interrupted transaction | No upgrade journal or journal temporary file, no leftover temporary files, transaction lock absent or free, not held | readiness `transaction_leftovers`, `lock_state` |
| 9 | Toolkit/package intake | Toolkit companions beside the tool match the reviewed pins (a mismatched companion is HOLD); any staged package validates exactly against the NEW pins; nothing unrecognized is accepted | readiness `toolkit_companions`, `package_intake` |
| 10 | Backup evidence decision | The Owner records either verified restore evidence per the Rackforest checklist, or — for this GO-5.4a preparation scope only — the recorded Owner risk-acceptance waiver (`BACKUP_WAIVER_ACCEPTED`: existence OWNER-CONFIRMED, restore tested NO, requirement OWNER-WAIVED, residual risks documented, contents/recoverability NOT INDEPENDENTLY VERIFIED). Existence alone is not PASS, and the waiver is not `BACKUP_VERIFIED=PASS` | `BACKUP_EVIDENCE` + [checklist](RACKFOREST_BACKUP_VERIFICATION_CHECKLIST.md) |
| 11 | Independent reviews | Current exact-artifact Integration QA and AppSec PASS records for the readiness/notify tools and the unchanged transaction toolkit | #174 ledger V3 records |
| 12 | Notify investigation state | `/run/systemd/notify` denials collected and necessity decided; no profile widening without proof | `g1-notify-evidence.py` output + review |

The readiness tool deliberately does not perform `apparmor_parser` compilation,
generated-profile hash regeneration, or package acceptance: those are executed
by the transaction tool itself at execution time, and the readiness notes say so.

## Exact installation procedure (DO NOT EXECUTE without a separate Owner GO)

1. **Freeze scope.** Confirm the protected primary and the reviewed toolkit
   commit; verify `scripts/production-g1-ops/SHA256SUMS` against this reviewed
   merge. Nothing below is authorized by reading or preparing it.
2. **Collect evidence.** From the approved root console run
   `go54a-readiness.py` and archive its full output. Required verdict for the
   next step: `G1_GO54A_READINESS=PASS` (technical) with
   `installed_baseline PASS baseline=old`.
3. **Notify evidence.** Run `g1-notify-evidence.py` and archive its output;
   the GO-5.4a transaction does not depend on it, but the open investigation
   must stay visible and no profile may be widened meanwhile.
4. **Stage the package.** On the host, from the root console:
   `bash go54a-fetch-package.sh 0951d9e829364296796a1d4acd303feef698cbc7`
   (or the exact reviewed commit recorded for this release). The script
   refuses existing targets, downloads the three payloads plus `scripts/SHA256SUMS`
   from the immutable commit, enforces root:root 0600 0700 metadata, verifies the
   pinned checksum-file digest and every payload digest, and promotes
   `/root/wpcg-go54a-<sha7>` only when everything matches.
5. **Validate intake.** `python3 -I -B go54a-readiness.py --package /root/wpcg-go54a-<sha7>`
   must report `package_intake PASS package_verified`.
6. **Owner GO.** Only the Owner may issue the execution GO, and only after
   items 1–12 above pass, including the backup-evidence decision.
7. **Execute the transaction.** From the approved root console:
   `python3 -I -B go54a-upgrade-apparmor.py /root/wpcg-go54a-<sha7>`.
   It re-verifies everything itself, snapshots vendor identity, both datadirs
   and logs, replaces only the five pinned files atomically, reloads only the
   two G1 profiles, verifies the new helper statically, checks every invariant
   again, and on failure performs a hash-checked rollback and reports whether
   the rollback was verified. An interrupted journal is recovered and the run
   then stops for manual review.
8. **Post-transaction verification.** Re-run `go54a-readiness.py`; the expected
   result is `installed_baseline HOLD already_upgraded_baseline=new` with every
   other check PASS, the five NEW hashes on disk, both profiles enforced, the
   vendor PID unchanged and identical datadir/log fingerprints. Any other
   result: stop and investigate; do not retry.
9. **Separate follow-up decisions.** A later GO-5.4 start of the production G1
   instance is a separate Owner decision and must never be chained to this
   transaction. The `/run/systemd/notify` investigation remains open until the
   collected audit records prove or refute necessity.

## Stop conditions

Stop without retry and preserve evidence when: any readiness check is HOLD or
UNKNOWN; any hash, owner, mode, unit state, profile state, PID, fingerprint or
lock precondition differs from the above; the transaction reports
`STOP;CODE=...;ROLLED_BACK=NO` (manual recovery, the journal is preserved); or
an interrupted journal is discovered. Unknown state is never treated as pass.
