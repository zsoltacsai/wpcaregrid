# Production G1 start procedure (GO-20.39): one ordered, reversible sequence

Status: tooling implemented and locally tested (scripts/production-g1-go20-39); **nothing here has been run on any host**. Every host step below needs its own Owner GO; none is granted by this document. `GO-5.4 START=NO-GO`, `PRODUCTION_G1=HOLD` until the Owner signs the start GO (step 9).
Host: `stg1.wpcaregrid.com` (production preparation environment). The Contabo VPS is a CI runner only and is not part of this procedure.

## 0. What is built (all in `scripts/production-g1-go20-39/`, tests: `tests/test_go2039_ops.py`, 87 cases, sandboxed)
| Tool | Purpose | Replaces |
|---|---|---|
| `s7b-containment.sh` (v2, sha256 `71cdc74c24e7…fb51`, own harness `s7b/tests`, 106 cases) | Console B: supervise the start (5 s samples, 300 s window), hang (150 s), restart loop, memory (256 MiB), OOM, legacy MySQL unchanged, one stop, typed-confirmation-gated `cgroup.kill` | the missing S7 containment wrapper |
| `g1-cold-archive.sh` (`create`, `verify [--deep]`) | root-only archive of both G1 datadirs + configuration, error logs, helper files, AppArmor profiles, per-file SHA-256 manifest; sources untouched; units must be inactive | gate G-A1 / S1 recovery evidence |
| `g1-restore-datadir.sh` | restore ONE instance's datadir and error log from the archive: current state renamed aside (never deleted), archived state extracted, every file re-hashed, typed confirmation, never touches units or the other instance | the rollback/restore requirement |
| `t8-timers.sh` (`status`, `pause`, `restore`) | stop (never disable/mask) `phpsessionclean.timer` and `wpcg-staging-next-scheduler.timer`, wait for their services, verify; restore and verify next elapse | STOP reason T8 |
| `pkg-state.sh` | fail-closed classifier: resident `unattended-upgrade-shutdown` (state S) vs a real apt/dpkg/unattended-upgrade run, apt-daily units, dpkg/apt locks (read-only; security updates stay on) | STOP reason package process |
| `rebaseline-prescript.py` | deterministic successor of the frozen pre-script: only `BASELINE_BOOT_ID`/`PIN_*` constants and (optional) the resident-helper-aware package block change; prints the exact diff and the new sha256 | reboot re-baseline + package check |
| `g1-start-console-a.sh` | console A: all hash gates, console B armed in `contain` mode, T8 paused, PKG-STATE, final pre run + gate, ONE helper start, POST + gate; no loop, no retry, no systemctl | the start sequence |
| `make-bundle.sh`, `install-toolkit.sh` | flat hash-verified bundle and root-only read-only installation under `/root/wpcg-g1-start-toolkit` | delivery |
Frozen approved artifacts are included unchanged (`frozen/`, hashes asserted by test): pre-script v3c `4da0c131…`, gate v4a `1780fd1e…`, thresholds `d5a029bd…`, post-script v2 `14c875db…`, post-gate v2 `7be4b22a…`, collector v2.2 `25c98527…`.

## 1. The sequence (each line: step, who/what, Owner GO, STOP and rollback)
1. **Deliver the toolkit.** Stage-A style transfer of the bundle (`make-bundle.sh` output + its sha256) to a new `/home/deploy/wpcg-go20-39-incoming/` (0700, transfer only); then root console `install-toolkit.sh --bundle <path> --sha <sha>`. *GO: "Toolkit transfer and placement".* STOP: any hash/manifest/frozen mismatch (nothing is created before the checks). Rollback: none needed (new read-only directory; removal needs a separate OK).
2. **Recovery position (S1).** (a) Owner takes a provider restore point and records id and time (must be newer than 2026-11-18 13:19 UTC); (b) root console `g1-cold-archive.sh create`, then `verify <dir> --deep`; (c) the Owner records the printed `TARBALLS_SHA256` and archive id in #174 and, if wanted, copies the archive off the host. *GO: "G-A1 cold archive" (reads the datadirs, writes about twice their size under /root; units must be inactive).* STOP: unit not inactive, runtime marker present, not enough space, source changed during copy. The archive is evidence and a before-copy, **not a proven restore** until step 3.
3. **Restore rehearsal on the restore instance (recommended).** `g1-restore-datadir.sh --instance restore --archive <dir> --confirm "RESTORE restore <archive-id>"`: proves the restore path and hashes on the host without touching the production instance (the renamed-aside copy stays). *GO: "Restore rehearsal".* STOP: any check; rollback: rename the aside copy back (documented in the tool output).
4. **Reboot maintenance (kernel `linux-image-7.0.0-43-generic`).** Prerequisites: steps 1-3 done, provider console open, staging downtime accepted (legacy MySQL restarts). Sequence: clean `systemctl stop mysql.service` (Owner), `systemctl reboot`, then verify: new kernel, `/run/reboot-required` gone, legacy active with 3306 loopback, G1 units inactive and 3308/3309/33061 free, both G1 profiles enforce, `/run/mysqld` `755 mysql:mysql` (created by tmpfiles). *GO: "Reboot maintenance".* STOP: any verification fails; rollback: previous kernel from the provider console.
5. **Re-baseline.** Read the new boot id (`cat /proc/sys/kernel/random/boot_id` on the root console), then on the workstation `rebaseline-prescript.py --in frozen/go20-38-pre-script-v3c.sh --out <v3d> --set BASELINE_BOOT_ID=<32 hex> --resident-helper-aware`; a reviewer checks the printed diff (two small regions), the Owner approves the printed `OUT_SHA256`. *GO: "Successor pre-script hash".* STOP: unexpected diff.
6. **Dry pre-start read (optional, recommended).** Root console: `env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin bash --noprofile --norc <v3d> > out.txt`, then `bash go20-38-pre-gate-v4a.sh out.txt <v3d-sha> d5a029bd…`: shows whether PSI and the remaining conditions are green after the reboot (read-only, the old G-R1 pattern). *GO: "Post-reboot dry pre run".*
7. **Quiet window.** `t8-timers.sh pause` (stops only the two timers; the staging scheduler heartbeat shows critical after 5 minutes; no catch-up for it). *GO: part of the start GO.*
8. **Console B** (second root console, ideally a second person): `env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin bash --noprofile --norc /root/wpcg-g1-start-toolkit/s7b-containment.sh --instance production --mode contain` under `script(1)`; wait for `S7B_ARMED`.
9. **Console A** (only after the Owner signed the start GO): `bash /root/wpcg-g1-start-toolkit/g1-start-console-a.sh --pre-script <v3d> --pre-sha <v3d-sha> --confirm "START production <first 12 hex>"`. It runs PKG-STATE, the final pre run and gate, the ONE helper start and POST; it never retries. *GO: "G1 production start (GO-5.4 scope)": one start, instance left running if `START=PASS`.*
10. **Close.** Record the result lines in #174; `t8-timers.sh restore` after POST and the hold period (35 minutes minimum, longer if console B reported anything); keep every run directory.

## 2. If something fails (rollback and containment)
| Situation | Action | Tool |
|---|---|---|
| Any gate refuses before the helper start | nothing was started; fix the cause, new decision | console A STOP code |
| Start hang / restart loop / memory / OOM / legacy change during the window | console B issues one `systemctl stop --no-block`, waits 120 s; the Owner may type the confirmation for a validated `cgroup.kill`; never `reset-failed` | S7-B (`contain`) |
| Helper start failed (unit failed or inactive), datadir opened once more | leave the unit state alone; collect POST evidence; roll the datadir and log back to the archive | `g1-restore-datadir.sh --instance production …` (units inactive) |
| Console drop / interruption | B and A never stop or kill on interruption; Owner runs a process check (CTP), then decides | S7-B contract |
| Everything else | provider console: previous kernel or restore point | Owner |

## 3. The four STOP reasons of GO-20.38 and their resolution
PSI memory full: transient (0.00 at 11:04Z, whole boot stall 2.1 s); decided by the final pre run in step 6/9. Package process: permanent artefact of the old check; resolved by `--resident-helper-aware` plus PKG-STATE (real apt/dpkg/upgrade runs and held locks still stop). Reboot required: step 4. T8 timers: step 7 (the frozen pre-script counts only active timers).

## 4. What still needs an Owner decision (the S7-A route)
S7-A (stop-path rehearsal) is not required by this sequence: with the cold archive, the restore tool and `contain` mode, the untested stop path is a bounded risk (the G1 datadir holds system tables only). The Owner either **accepts** this (A3, recommended) or orders a rehearsal on the restore instance first (A2, a start/stop of `mysql@wpcaregrid-production-restore` under the same console-B supervision).
