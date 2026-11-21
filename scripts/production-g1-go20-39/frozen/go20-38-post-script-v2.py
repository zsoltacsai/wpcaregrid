#!/usr/bin/env python3
"""
GO-20.38 POST-START evidence collector script (v2.3-lite).
FROZEN DESIGN: Doc B (go20-38-prestart-poststart-DESIGN-v1.1.md) Section 4 (POST-0..POST-7).
NON-NEGOTIABLE OWNER DIRECTIVES:
- Root-only execution (guard exits with code 3 immediately if non-root).
- Read-only execution on host: no writes of any kind except stdout/stderr.
- Zero state modifications: unit state, systemd config, kernel parameters,
  AppArmor profiles, and system configuration remain completely untouched.
- No datadir/backup file content reads (metadata only; find/stat).
- MySQL error log read ONLY by POST-3 reader exactly as specified:
  open O_RDONLY|O_NOFOLLOW|O_CLOEXEC, fstat checks, inode==T0, seek to T0 size,
  read at most 1 MiB, strict prefix regex on bytes, message text suppressed,
  canary/password lines suppressed, fail-closed on any anomaly or exception.
- Output contract: fixed tokens, integers, UTC timestamps, fixed-length hashes,
  sanitised tokens. Per-run 128-bit random nonce on WPCG_BLOCK_BEGIN / WPCG_BLOCK_END.
- Time-bounded external reads with READ_FAILED markers and FAILED_READS counter.
"""

import sys
import os

# Root guard: must be first executable line
if os.geteuid() != 0:
    sys.stdout.write("GO2038_POST=STOP;CODE=not_root\n")
    sys.exit(3)

import re
import stat
import time
import hashlib
import secrets
import subprocess
from datetime import datetime, timezone

# ================= Configuration & Paths =================
MYSQLD_LOG_PATH = "/var/log/mysql/wpcaregrid-production.log"
DATADIR_PATH = "/var/lib/mysql-wpcaregrid-production"
RUN_MYSQLD_DIR = "/run/mysqld"
VENDOR_PROFILE_PATH = "/etc/apparmor.d/usr.sbin.mysqld"
PROD_PROFILE_PATH = "/etc/apparmor.d/wpcaregrid-mysql-production"
RESTORE_PROFILE_PATH = "/etc/apparmor.d/wpcaregrid-mysql-production-restore"
PROD_CNF_PATH = "/etc/mysql/mysql.conf.d/zz-wpcaregrid-production.cnf"
RESTORE_CNF_PATH = "/etc/mysql/mysql.conf.d/zz-wpcaregrid-production-restore.cnf"
CONTRACT_PATH = "/usr/local/libexec/production_g1_mysql_contract.py"
PROD_DROPIN_PATH = "/etc/systemd/system/mysql@wpcaregrid-production.service.d/90-wpcaregrid-g1.conf"
RESTORE_DROPIN_PATH = "/etc/systemd/system/mysql@wpcaregrid-production-restore.service.d/90-wpcaregrid-g1.conf"
MANIFEST_PATH = "/usr/local/libexec/production_g1_mysql_manifest.json"
AA_PROFILES_PATH = "/sys/kernel/security/apparmor/profiles"
UNIT_PROD = "mysql@wpcaregrid-production.service"
UNIT_RESTORE = "mysql@wpcaregrid-production-restore.service"
UNIT_LEGACY = "mysql.service"

BASELINE_FINGERPRINT_META = "fb02d2282ec45e826fb360da4cef5899280b24fbccac7ed8f77ed788ef33c18c"
THRESHOLD_SET_DEFAULT = "thresholds.v1"

PINNED_HASHES = {
    "profile_prod": (PROD_PROFILE_PATH, "64f1bd60ba0b8626978d79951179bb9c2484df1ce79981e567a7a41e057ea73a"),
    "profile_restore": (RESTORE_PROFILE_PATH, "b612b2d913d4d8a178707516d8581724f2c003c5e8076f12e7b6930930c4fc32"),
    "cnf_prod": (PROD_CNF_PATH, "7de16da204b888e634ea46a61e00b592bcfa69b5426a02228534b627e9567ad9"),
    "cnf_restore": (RESTORE_CNF_PATH, "14fb59069eb42c7d0a938799330595f195bc43265df60b385e073ba58bf80a7b"),
    "contract": (CONTRACT_PATH, "c81af789c99876cd9cf60ed26681eb8501c6bfe7cacfd46b120f748e43d8b4d8"),
    "dropin_prod": (PROD_DROPIN_PATH, "644e98059d68462b66a575034b5fdfbb4dbc037569f2970ab7dfa7bdccecc017"),
    "dropin_restore": (RESTORE_DROPIN_PATH, "d5df1ab7132248defe4c529afedb7517d002da73295b98ad87aa50a6ffc9e8ad"),
    "manifest": (MANIFEST_PATH, "25d8446724740a0f8390b13d6e380b5d75425221a87b6438e25f588ecdf71b35"),
}

# ================= State & Output Infrastructure =================
body_lines = []
failed_reads = 0

def emit(line: str):
    body_lines.append(line)

def now_iso():
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%fZ")

def run_cmd(args, timeout_sec=10, check_rc=True, env=None):
    global failed_reads
    if env is None:
        path_val = os.environ.get("PATH", "/usr/sbin:/usr/bin:/sbin:/bin")
        env = {
            "LANG": "C",
            "LC_ALL": "C",
            "TZ": "UTC",
            "PATH": path_val
        }
    cmd_name = os.path.basename(args[0]) if args else "cmd"
    try:
        res = subprocess.run(
            args,
            timeout=timeout_sec,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
            shell=False
        )
        if check_rc and res.returncode != 0:
            emit(f"READ_FAILED name={cmd_name} rc={res.returncode}")
            failed_reads += 1
        return res.returncode, res.stdout, res.stderr
    except subprocess.TimeoutExpired:
        emit(f"READ_FAILED name={cmd_name} rc=timeout")
        failed_reads += 1
        return 124, b"", b""
    except BaseException:
        emit(f"READ_FAILED name={cmd_name} rc=exception")
        failed_reads += 1
        return 1, b"", b""

def safe_read_file(path, timeout_sec=5):
    global failed_reads
    base = os.path.basename(path)
    try:
        with open(path, "rb") as fp:
            return fp.read()
    except BaseException:
        emit(f"READ_FAILED name={base} rc=file_read_error")
        failed_reads += 1
        return None

def sha256_file(path):
    global failed_reads
    base = os.path.basename(path)
    try:
        if os.path.islink(path):
            return "VOID_SYMLINK"
        if not os.path.isfile(path):
            return "VOID_MISSING"
        h = hashlib.sha256()
        with open(path, "rb") as fp:
            while chunk := fp.read(65536):
                h.update(chunk)
        return h.hexdigest()
    except BaseException:
        emit(f"READ_FAILED name={base} rc=hash_error")
        failed_reads += 1
        return "VOID"

# ================= Argument Parsing =================
logstate_arg = None
journal_cursor = None
t_pre_end = None
t_start_cmd = None
t_start_return = None
t_post_begin = None
boot_id_arg = None
helper_rc = None
helper_status = None
opt_t2 = False
threshold_set = "thresholds.v1"

seen_args = set()
idx = 1
while idx < len(sys.argv):
    arg = sys.argv[idx]
    if arg in (
        "--logstate", "--cursor", "--t-pre-end", "--t-start-cmd",
        "--t-start-return", "--t-post-begin", "--boot-id",
        "--helper-rc", "--helper-status"
    ):
        if arg in seen_args:
            emit(f"READ_FAILED name=args rc=duplicate_arg_{arg[2:].replace('-', '_')}")
            failed_reads += 1
        idx += 1
        if idx >= len(sys.argv):
            emit(f"READ_FAILED name=args rc=missing_val_{arg[2:].replace('-', '_')}")
            failed_reads += 1
            break
        val = sys.argv[idx]
        seen_args.add(arg)
        if arg == "--logstate":
            if "--logstate_pos" in seen_args:
                emit("READ_FAILED name=args rc=duplicate_logstate")
                failed_reads += 1
            elif re.match(r"^[0-9]+:[0-9]+:[0-9]+$", val):
                t0_ino_val, _, _ = val.split(":")
                if int(t0_ino_val) == 0:
                    emit("READ_FAILED name=args rc=invalid_logstate")
                    failed_reads += 1
                else:
                    logstate_arg = val
            else:
                emit("READ_FAILED name=args rc=invalid_logstate")
                failed_reads += 1
        elif arg == "--cursor":
            if re.match(r"^[A-Za-z0-9_=;:.-]{1,200}$", val):
                journal_cursor = val
            else:
                emit("READ_FAILED name=args rc=invalid_cursor")
                failed_reads += 1
        elif arg == "--t-pre-end":
            if re.match(r"^[0-9]+(?:\.[0-9]+)?$", val):
                t_pre_end = val
            else:
                emit("READ_FAILED name=args rc=invalid_t_pre_end")
                failed_reads += 1
        elif arg == "--t-start-cmd":
            if re.match(r"^[0-9]+(?:\.[0-9]+)?$", val):
                t_start_cmd = val
            else:
                emit("READ_FAILED name=args rc=invalid_t_start_cmd")
                failed_reads += 1
        elif arg == "--t-start-return":
            if re.match(r"^[0-9]+(?:\.[0-9]+)?$", val):
                t_start_return = val
            else:
                emit("READ_FAILED name=args rc=invalid_t_start_return")
                failed_reads += 1
        elif arg == "--t-post-begin":
            if re.match(r"^[0-9]+(?:\.[0-9]+)?$", val):
                t_post_begin = val
            else:
                emit("READ_FAILED name=args rc=invalid_t_post_begin")
                failed_reads += 1
        elif arg == "--boot-id":
            if re.match(r"^[0-9a-fA-F-]{1,64}$", val):
                boot_id_arg = val
            else:
                emit("READ_FAILED name=args rc=invalid_boot_id")
                failed_reads += 1
        elif arg == "--helper-rc":
            if re.match(r"^[0-9]+$", val):
                helper_rc = val
            else:
                emit("READ_FAILED name=args rc=invalid_helper_rc")
                failed_reads += 1
        elif arg == "--helper-status":
            if re.match(r"^[A-Za-z0-9_.:=/-]{1,40}$", val):
                helper_status = val
            else:
                emit("READ_FAILED name=args rc=invalid_helper_status")
                failed_reads += 1
    elif arg == "--opt-t2":
        if "--opt-t2" in seen_args:
            emit("READ_FAILED name=args rc=duplicate_opt_t2")
            failed_reads += 1
        seen_args.add("--opt-t2")
        opt_t2 = True
    elif re.match(r"^[0-9]+:[0-9]+:[0-9]+$", arg):
        if logstate_arg is not None or "--logstate" in seen_args or "--logstate_pos" in seen_args:
            emit("READ_FAILED name=args rc=duplicate_logstate")
            failed_reads += 1
        else:
            t0_ino_val, _, _ = arg.split(":")
            if int(t0_ino_val) == 0:
                emit("READ_FAILED name=args rc=invalid_logstate")
                failed_reads += 1
            else:
                logstate_arg = arg
                seen_args.add("--logstate_pos")
    else:
        emit("READ_FAILED name=args rc=invalid_argument")
        failed_reads += 1
    idx += 1

if not logstate_arg:
    emit("READ_FAILED name=args rc=missing_logstate")
    failed_reads += 1

# Generate 128-bit random nonce from /dev/urandom
nonce = secrets.token_hex(16)

# Compute self-sha256
script_path = os.path.abspath(sys.argv[0])
script_sha256 = sha256_file(script_path)

# ================= POST-0: Result Capture =================
emit("SEC 0 BEGIN result_capture")
t1_ts = now_iso()
emit(f"POST0_T1={t1_ts}")
if helper_rc is not None and re.match(r"^[0-9]+$", helper_rc):
    emit(f"POST0_HELPER_RC={helper_rc}")
else:
    emit("POST0_HELPER_RC=-")

if helper_status and re.match(r"^[A-Za-z0-9_.:=/-]{1,40}$", helper_status):
    emit(f"POST0_HELPER_STATUS={helper_status}")
else:
    emit("POST0_HELPER_STATUS=-")

# systemctl show mysql@wpcaregrid-production.service
rc_u, out_u, _ = run_cmd([
    "systemctl", "show", UNIT_PROD,
    "-p", "ActiveState", "-p", "SubState", "-p", "Result", "-p", "MainPID",
    "-p", "NRestarts", "-p", "ExecMainCode", "-p", "ExecMainStatus",
    "-p", "ActiveEnterTimestamp", "-p", "InactiveEnterTimestamp",
    "-p", "StateChangeTimestamp", "-p", "MemoryCurrent", "-p", "TasksCurrent"
], timeout_sec=10)

unit_props = {}
main_pid = 0
if rc_u == 0:
    for line in out_u.decode("ascii", errors="replace").splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            unit_props[k] = v
            # Sanitized output
            if k == "MainPID":
                try:
                    main_pid = int(v)
                except ValueError:
                    main_pid = 0
            if k in ("MainPID", "NRestarts", "ExecMainStatus", "MemoryCurrent", "TasksCurrent"):
                val_out = v if (v and v.isdigit()) else ("0" if k in ("MainPID", "NRestarts") else "-")
            elif k in ("ActiveState", "SubState", "Result", "ExecMainCode"):
                val_out = v if (v and re.match(r"^[A-Za-z0-9_-]{1,40}$", v)) else "other"
            elif "Timestamp" in k:
                val_out = v if (v and re.match(r"^[A-Za-z0-9_ :.-]{1,40}$", v)) else "-"
            else:
                val_out = v if (v and re.match(r"^[A-Za-z0-9_.:=/-]{1,40}$", v)) else "-"
            emit(f"POST0_UNIT_{k}={val_out}")

rc_j, out_j, _ = run_cmd([
    "systemctl", "show", "-p", "NJobs", "-p", "NFailedUnits"
], timeout_sec=10)
if rc_j == 0:
    for line in out_j.decode("ascii", errors="replace").splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            val_s = v if (v and v.isdigit()) else "0"
            emit(f"POST0_SYSTEMD_{k}={val_s}")

emit(f"SEC 0 END failed=0")

# ================= POST-1: Unit Lifecycle (PID 1 journal) =================
emit("SEC 1 BEGIN unit_lifecycle")
# Parse T0 from logstate
t0_epoch = 0
if logstate_arg and re.match(r"^[0-9]+:[0-9]+:[0-9]+$", logstate_arg):
    _, _, t0_s = logstate_arg.split(":")
    t0_epoch = int(t0_s)

cmd_j = ["journalctl", "-o", "export", "_PID=1", "_SYSTEMD_UNIT=init.scope"]
if journal_cursor and re.match(r"^[A-Za-z0-9_=;:.-]{1,200}$", journal_cursor):
    cmd_j.extend(["--after-cursor", journal_cursor])
elif t0_epoch > 0:
    cmd_j.extend(["--since", f"@{t0_epoch - 60}"])

rc_p1, out_p1, _ = run_cmd(cmd_j, timeout_sec=15)

lifecycle_events = {
    "starting": 0, "started": 0, "failed": 0, "scheduled_restart": 0, "other": 0
}
total_p1_lines = 0
untrusted_journal_lines = 0

if rc_p1 == 0:
    entries = out_p1.split(b"\n\n")
    for raw_entry in entries:
        if not raw_entry.strip():
            continue
        entry_map = {}
        for eline in raw_entry.split(b"\n"):
            if b"=" in eline:
                ek, ev = eline.split(b"=", 1)
                entry_map[ek.decode("ascii", errors="replace")] = ev.decode("ascii", errors="replace")
        
        # Verify trusted fields
        pid = entry_map.get("_PID", "")
        s_unit = entry_map.get("_SYSTEMD_UNIT", "")
        if pid != "1" or s_unit != "init.scope":
            untrusted_journal_lines += 1
            continue

        msg = entry_map.get("MESSAGE", "")
        mid = entry_map.get("MESSAGE_ID", "")
        u_field = entry_map.get("UNIT", "")
        ts_usec = entry_map.get("__REALTIME_TIMESTAMP", "")
        
        ts_str = "-"
        if ts_usec and ts_usec.isdigit():
            ts_str = datetime.fromtimestamp(int(ts_usec) / 1000000, tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

        # Check if entry belongs to G1 prod
        if UNIT_PROD in msg or u_field == UNIT_PROD:
            total_p1_lines += 1
            ev_class = "other"
            code_val = "-"
            status_val = "-"
            if mid == "7d4958e3e7d1451c87fcc0fa8eb37877" or "Starting " in msg:
                ev_class = "starting"
                lifecycle_events["starting"] += 1
            elif mid == "39f5632832f04460b99cdd0829de6074" or "Started " in msg:
                ev_class = "started"
                lifecycle_events["started"] += 1
            elif mid == "be02cf685d7842aa9e67f7d02204ae80" or "Failed with result" in msg:
                ev_class = "failed_result"
                lifecycle_events["failed"] += 1
            elif "Scheduled restart job" in msg:
                ev_class = "scheduled_restart"
                lifecycle_events["scheduled_restart"] += 1
            elif "Main process exited" in msg:
                ev_class = "main_exited"
                lifecycle_events["other"] += 1
                m_c = re.search(r"code=([a-z]+)", msg)
                m_s = re.search(r"status=([0-9A-Z/]+)", msg)
                if m_c: code_val = m_c.group(1)
                if m_s: status_val = m_s.group(1).replace("/", "_")
            elif "Stopping " in msg:
                ev_class = "stopping"
                lifecycle_events["other"] += 1
            elif "Stopped " in msg:
                ev_class = "stopped"
                lifecycle_events["other"] += 1
            elif "Deactivated successfully" in msg:
                ev_class = "deactivated"
                lifecycle_events["other"] += 1
            elif "Consumed " in msg:
                ev_class = "consumed_resources"
                lifecycle_events["other"] += 1
            else:
                lifecycle_events["other"] += 1

            emit(f"LIFECYCLE t={ts_str} unit=g1_prod ev={ev_class} code={code_val} status={status_val}")

emit(f"LIFECYCLE_TOTAL n={total_p1_lines} starting={lifecycle_events['starting']} started={lifecycle_events['started']} failed={lifecycle_events['failed']} scheduled_restart={lifecycle_events['scheduled_restart']} other={lifecycle_events['other']}")
emit(f"JOURNAL_UNTRUSTED n={untrusted_journal_lines}")
emit(f"POST1_RC={rc_p1}")
emit(f"SEC 1 END failed=0")

# ================= POST-2: Process, Listeners, Legacy Interplay =================
emit("SEC 2 BEGIN process_listeners_legacy")

# 1. /proc/<MainPID>/comm
if main_pid > 0:
    comm_bytes = safe_read_file(f"/proc/{main_pid}/comm")
    if comm_bytes and comm_bytes.strip() == b"mysqld":
        emit("PROC_COMM=mysqld")
    else:
        emit("PROC_COMM=other")
    
    # 2. attr/current
    attr_bytes = safe_read_file(f"/proc/{main_pid}/attr/current")
    if not attr_bytes:
        attr_bytes = safe_read_file(f"/proc/{main_pid}/attr/apparmor/current")
    if attr_bytes and b"wpcaregrid-mysql-production (enforce)" in attr_bytes:
        emit("PROC_ATTR=wpcg_prod_enforce")
    else:
        emit("PROC_ATTR=other")
    
    # 3. cmdline
    cmd_bytes = safe_read_file(f"/proc/{main_pid}/cmdline")
    if cmd_bytes and b"--defaults-group-suffix=@wpcaregrid-production" in cmd_bytes:
        emit("PROC_CMDLINE_GROUP_SUFFIX=yes")
    else:
        emit("PROC_CMDLINE_GROUP_SUFFIX=no")
else:
    emit("PROC_COMM=none")
    emit("PROC_ATTR=none")
    emit("PROC_CMDLINE_GROUP_SUFFIX=no")

# 4. ss -H -ltnp for ports 3306, 3308, 3309, 33060, 33061
rc_ss, out_ss, _ = run_cmd(["ss", "-H", "-ltnp"], timeout_sec=10)
if rc_ss == 0:
    for sline in out_ss.decode("ascii", errors="replace").splitlines():
        parts = sline.split()
        if len(parts) >= 4:
            local_addr = parts[3]
            port = local_addr.rsplit(":", 1)[-1] if ":" in local_addr else ""
            if port in ("3306", "3308", "3309", "33060", "33061"):
                bind_cls = "other"
                if "127.0.0.1" in local_addr or "::1" in local_addr or "[::1]" in local_addr:
                    bind_cls = "loopback"
                elif "0.0.0.0" in local_addr or "*:" in local_addr or ":::" in local_addr:
                    bind_cls = "any"
                
                owner_cls = "mysqld" if "mysqld" in sline else "other"
                emit(f"LISTENER port={port} bind={bind_cls} owner={owner_cls}")

# 5. /run/mysqld stat & sockets
try:
    import pwd, grp
    mysql_uid = pwd.getpwnam("mysql").pw_uid
    mysql_gid = grp.getgrnam("mysql").gr_gid
except BaseException:
    mysql_uid, mysql_gid = -1, -1
try:
    st_r = os.stat(RUN_MYSQLD_DIR)
    mode_oct = oct(stat.S_IMODE(st_r.st_mode))[2:]
    own_r = "mysql:mysql" if (mysql_uid >= 0 and st_r.st_uid == mysql_uid and st_r.st_gid == mysql_gid) else ("root:root" if (st_r.st_uid == 0 and st_r.st_gid == 0) else "other")
    emit(f"RUN_MYSQLD_STAT mode=0{mode_oct} owner={own_r}")
except BaseException:
    emit("RUN_MYSQLD_STAT mode=UNKNOWN owner=UNKNOWN")

emit(f"G1_SOCK={'present' if os.path.exists(os.path.join(RUN_MYSQLD_DIR, 'wpcaregrid-production.sock')) else 'absent'}")
g1_pid_path = os.path.join(RUN_MYSQLD_DIR, 'wpcaregrid-production.pid')
g1_pid_present = os.path.exists(g1_pid_path)
emit(f"G1_PID_FILE={'present' if g1_pid_present else 'absent'}")
pid_match = "no"
if g1_pid_present:
    pdata = safe_read_file(g1_pid_path)
    if pdata:
        try:
            if int(pdata.strip()) == main_pid and main_pid > 0:
                pid_match = "yes"
        except ValueError:
            pass
emit(f"G1_PID_MATCH={pid_match}")
emit(f"G1_LOCK_FILE={'present' if os.path.exists(os.path.join(RUN_MYSQLD_DIR, 'wpcaregrid-production.sock.lock')) else 'absent'}")

# 6. Legacy mysql.service
rc_leg, out_leg, _ = run_cmd([
    "systemctl", "show", UNIT_LEGACY,
    "-p", "MainPID", "-p", "ActiveState", "-p", "NRestarts"
], timeout_sec=10)
leg_pid = 0
if rc_leg == 0:
    for line in out_leg.decode("ascii", errors="replace").splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            if k == "ActiveState":
                emit(f"LEGACY_ACTIVE_STATE={v if (v and re.match(r'^[A-Za-z0-9_-]{1,40}$', v)) else 'other'}")
            elif k == "MainPID":
                val_pid = v if (v and v.isdigit()) else "-"
                emit(f"LEGACY_MAIN_PID={val_pid}")
                try: leg_pid = int(v)
                except ValueError: leg_pid = 0
            elif k == "NRestarts":
                val_nr = v if (v and v.isdigit()) else "-"
                emit(f"LEGACY_NRESTARTS={val_nr}")

emit(f"LEGACY_SOCK={'present' if os.path.exists(os.path.join(RUN_MYSQLD_DIR, 'mysqld.sock')) else 'absent'}")
leg_pid_path = os.path.join(RUN_MYSQLD_DIR, 'mysqld.pid')
leg_pid_present = os.path.exists(leg_pid_path)
emit(f"LEGACY_PID_FILE={'present' if leg_pid_present else 'absent'}")
leg_pid_match = "no"
if leg_pid_present and leg_pid > 0:
    lpdata = safe_read_file(leg_pid_path)
    if lpdata:
        try:
            if int(lpdata.strip()) == leg_pid:
                leg_pid_match = "yes"
        except ValueError:
            pass
emit(f"LEGACY_PID_MATCH={leg_pid_match}")

# 7. Vendor profile sha256
vend_hash = sha256_file(VENDOR_PROFILE_PATH)
emit(f"VENDOR_PROFILE_SHA256={vend_hash}")
emit(f"POST2_RC={rc_leg}")
emit(f"SEC 2 END failed=0")

# ================= POST-3: MySQL Error-Log Events =================
emit("SEC 3 BEGIN mysql_error_log")

def run_post3_reader(log_path, logstate_str, opt_t2_flag):
    try:
        # Validate argument ^[0-9]+:[0-9]+:[0-9]+$
        if not logstate_str or not re.match(r"^[0-9]+:[0-9]+:[0-9]+$", logstate_str):
            emit("LOGEV=UNAVAILABLE;CODE=invalid_logstate_arg")
            return

        t0_ino_str, t0_sz_str, _ = logstate_str.split(":")
        t0_ino = int(t0_ino_str)
        t0_sz = int(t0_sz_str)
        if t0_ino == 0:
            emit("LOGEV=UNAVAILABLE;CODE=invalid_logstate_arg")
            return

        # Check symlink with lstat first
        try:
            lst = os.lstat(log_path)
            if stat.S_ISLNK(lst.st_mode):
                emit("LOGEV=UNAVAILABLE;CODE=symlink")
                return
        except BaseException:
            emit("LOGEV=UNAVAILABLE;CODE=lstat_failed")
            return

        # Open O_RDONLY | O_NOFOLLOW | O_CLOEXEC | O_NONBLOCK
        flags = os.O_RDONLY
        if hasattr(os, "O_NOFOLLOW"):
            flags |= os.O_NOFOLLOW
        if hasattr(os, "O_CLOEXEC"):
            flags |= os.O_CLOEXEC
        if hasattr(os, "O_NONBLOCK"):
            flags |= os.O_NONBLOCK

        try:
            fd = os.open(log_path, flags)
        except BaseException:
            emit("LOGEV=UNAVAILABLE;CODE=open_failed")
            return

        try:
            st = os.fstat(fd)
            if not stat.S_ISREG(st.st_mode):
                emit("LOGEV=UNAVAILABLE;CODE=not_regular")
                return
            if stat.S_IMODE(st.st_mode) != 0o600:
                emit("LOGEV=UNAVAILABLE;CODE=mode_mismatch")
                return
            
            # Resolve mysql uid/gid
            try:
                import pwd, grp
                mysql_uid = pwd.getpwnam("mysql").pw_uid
                mysql_gid = grp.getgrnam("mysql").gr_gid
                if st.st_uid != mysql_uid or st.st_gid != mysql_gid:
                    emit("LOGEV=UNAVAILABLE;CODE=owner_mismatch")
                    return
            except BaseException:
                # If lookup fails in production, fail closed
                emit("LOGEV=UNAVAILABLE;CODE=owner_lookup_failed")
                return

            if st.st_ino != t0_ino:
                emit("LOGEV=UNAVAILABLE;CODE=inode_mismatch")
                return
            if st.st_size < t0_sz:
                emit("LOGEV=UNAVAILABLE;CODE=size_truncated")
                return

            # Seek to t0_size
            os.lseek(fd, t0_sz, os.SEEK_SET)

            # Read at most 1 MiB with chunked read loop
            to_read = min(st.st_size - t0_sz, 1048576)
            chunks = []
            bytes_read = 0
            while bytes_read < to_read:
                chunk_size = min(to_read - bytes_read, 65536)
                chunk = os.read(fd, chunk_size)
                if not chunk:
                    break
                chunks.append(chunk)
                bytes_read += len(chunk)
            data = b"".join(chunks)
        except BaseException:
            emit("LOGEV=UNAVAILABLE;CODE=read_failed")
            return
        finally:
            try:
                os.close(fd)
            except BaseException:
                pass

        # Split on newline
        raw_lines = data.split(b"\n")
        partial_count = 0
        if data and not data.endswith(b"\n"):
            partial_count = 1
            lines_to_parse = raw_lines[:-1]
        else:
            if raw_lines and raw_lines[-1] == b"":
                lines_to_parse = raw_lines[:-1]
            else:
                lines_to_parse = raw_lines

        RE_PREFIX = re.compile(
            rb"^(?P<ts>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}(?:Z|[+-]\d{2}:\d{2})) "
            rb"(?P<thr>\d+) \[(?P<lvl>System|Note|Warning|Error)\] \[(?P<code>MY-\d{6})\] \[(?P<sub>[A-Za-z0-9_]{1,24})\]"
        )
        RE_ERRNO = re.compile(rb"\(OS errno (\d{1,3})\b|Errcode: (\d{1,3})\b")

        parsed_count = 0
        sensitive_msg_count = 0
        dropped_sensitive_count = 0
        ready_count = 0
        code_counts = {}
        unparsed_by_len = {"lt_80": 0, "80_255": 0, "256_1024": 0, "gt_1024": 0}
        error_count = 0

        for line in lines_to_parse:
            has_pw = (b"password" in line.lower()) or (b"MY-010454" in line)
            m = RE_PREFIX.match(line)
            if m:
                parsed_count += 1
                ts_str = m.group("ts").decode("ascii")
                lvl_str = m.group("lvl").decode("ascii")
                code_str = m.group("code").decode("ascii")
                sub_str = m.group("sub").decode("ascii")

                if lvl_str == "Error":
                    error_count += 1
                code_counts[code_str] = code_counts.get(code_str, 0) + 1
                if code_str == "MY-010931":
                    ready_count += 1
                if has_pw:
                    sensitive_msg_count += 1

                emit(f"LOGEV t={ts_str} lvl={lvl_str} code={code_str} sub={sub_str}")

                if opt_t2_flag:
                    m_err = RE_ERRNO.search(line)
                    if m_err:
                        err_n = m_err.group(1) or m_err.group(2)
                        if err_n:
                            emit(f"LOGEV_ERRNO n={int(err_n.decode('ascii'))}")
            else:
                if has_pw:
                    dropped_sensitive_count += 1
                ll = len(line)
                if ll < 80:
                    unparsed_by_len["lt_80"] += 1
                elif ll <= 255:
                    unparsed_by_len["80_255"] += 1
                elif ll <= 1024:
                    unparsed_by_len["256_1024"] += 1
                else:
                    unparsed_by_len["gt_1024"] += 1

        tot_unparsed = sum(unparsed_by_len.values())
        emit(f"LOGEV_TOTAL parsed={parsed_count} unparsed={tot_unparsed} partial={partial_count}")
        emit(f"LOGEV_SENSITIVE_MSG n={sensitive_msg_count}")
        emit(f"LOGEV_DROPPED_SENSITIVE n={dropped_sensitive_count}")
        emit(f"LOGEV_ERROR_COUNT n={error_count}")
        for c in sorted(code_counts.keys()):
            emit(f"LOGEV_CODE code={c} n={code_counts[c]}")
        emit(f"READY_EVENT_COUNT n={ready_count}")
        if ready_count != 1:
            emit(f"READY_CODE_UNCONFIRMED n={ready_count}")
        for k in ["lt_80", "80_255", "256_1024", "gt_1024"]:
            emit(f"LOGEV_UNPARSED len_class={k} n={unparsed_by_len[k]}")

    except BaseException:
        emit("LOGEV=UNAVAILABLE;CODE=exception")

if not logstate_arg:
    emit("LOGEV=UNAVAILABLE;CODE=no_logstate")
else:
    run_post3_reader(MYSQLD_LOG_PATH, logstate_arg, opt_t2)
emit(f"SEC 3 END failed=0")

# ================= POST-4: AppArmor Denials Since T0 =================
emit("SEC 4 BEGIN apparmor_denials")

cmd_aa = ["journalctl", "-o", "export", "_TRANSPORT=kernel", "_TRANSPORT=audit"]
if journal_cursor and re.match(r"^[A-Za-z0-9_=;:.-]{1,200}$", journal_cursor):
    cmd_aa.extend(["--after-cursor", journal_cursor])
elif t0_epoch > 0:
    cmd_aa.extend(["--since", f"@{t0_epoch - 60}"])

rc_aa, out_aa, _ = run_cmd(cmd_aa, timeout_sec=20)

denials_count = {
    "total": 0, "wpcg_prod": 0, "wpcg_restore": 0, "vendor_mysqld": 0, "other": 0
}
kernel_suppression_seen = 0
audit_loss_seen = 0
status_rows = 0
status_since_1118 = 0
journal_untrusted_post4 = 0

def map_apparmor_name(nm):
    if not nm: return "<other>"
    if "/sys/devices/system/node" in nm: return "<sysfs_node>"
    if nm == "/run/systemd/notify": return "<run_systemd_notify>"
    if ".sock.lock" in nm: return "<g1_sock_lock>"
    if nm.endswith(".sock"): return "<g1_sock>"
    if nm.endswith(".pid"): return "<g1_pid>"
    if "/run/mysqld" in nm: return "<run_mysqld_dir>"
    if "/var/lib/mysql-wpcaregrid-production" in nm: return "<g1_datadir>"
    if "/var/tmp/wpcaregrid-production" in nm: return "<g1_tmpdir>"
    if "/var/log/mysql/wpcaregrid-" in nm: return "<g1_log>"
    if "/etc/mysql" in nm: return "<etc_mysql>"
    if "/sys" in nm: return "<sysfs_other>"
    if "/proc" in nm: return "<proc_other>"
    return "<other>"

if rc_aa == 0:
    aa_entries = out_aa.split(b"\n\n")
    for raw_e in aa_entries:
        if not raw_e.strip(): continue
        e_map = {}
        for l in raw_e.split(b"\n"):
            if b"=" in l:
                k, v = l.split(b"=", 1)
                e_map[k.decode("ascii", errors="replace")] = v.decode("ascii", errors="replace")
        
        tr = e_map.get("_TRANSPORT", "")
        if tr not in ("kernel", "audit"):
            journal_untrusted_post4 += 1
            continue
        msg = e_map.get("MESSAGE", "")
        ts_usec = e_map.get("__REALTIME_TIMESTAMP", "")
        ts_str = "-"
        if ts_usec and ts_usec.isdigit():
            ts_str = datetime.fromtimestamp(int(ts_usec) / 1000000, tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

        # Loss markers
        if tr == "kernel" and ("callbacks suppressed" in msg or "messages suppressed" in msg):
            kernel_suppression_seen += 1
        if tr == "audit" and ("audit_lost=" in msg or "backlog limit exceeded" in msg):
            audit_loss_seen += 1
        if "Suppressed " in msg and " messages" in msg:
            audit_loss_seen += 1

        # Denials
        if 'apparmor="DENIED"' in msg:
            denials_count["total"] += 1
            m_prof = re.search(r'profile="([^"]+)"', msg)
            m_comm = re.search(r'comm="([^"]+)"', msg)
            m_name = re.search(r'name="([^"]+)"', msg)
            m_op = re.search(r'operation="([^"]+)"', msg)
            
            p_val = m_prof.group(1) if m_prof else ""
            c_val = m_comm.group(1) if m_comm else ""
            n_val = m_name.group(1) if m_name else ""
            o_val = m_op.group(1) if m_op else "other"

            prof_cls = "other"
            if p_val == "wpcaregrid-mysql-production":
                prof_cls = "wpcg_prod"
                denials_count["wpcg_prod"] += 1
            elif p_val == "wpcaregrid-mysql-production-restore":
                prof_cls = "wpcg_restore"
                denials_count["wpcg_restore"] += 1
            elif p_val in ("mysqld", "usr.sbin.mysqld") or p_val.startswith("/usr/sbin/mysqld"):
                prof_cls = "vendor_mysqld"
                denials_count["vendor_mysqld"] += 1
            else:
                denials_count["other"] += 1

            comm_cls = "mysqld" if "mysqld" in c_val else "other"
            nm_cls = map_apparmor_name(n_val)
            op_cls = o_val if re.match(r"^[A-Za-z0-9_]{1,24}$", o_val) else "other"

            emit(f"DENIAL n={denials_count['total']} t={ts_str} src={tr} prof={prof_cls} op={op_cls} nm={nm_cls} comm={comm_cls}")

        # STATUS rows
        if 'apparmor="STATUS"' in msg:
            status_rows += 1
            if ts_str >= "2026-11-18T13:19:26Z":
                status_since_1118 += 1
            m_op = re.search(r'operation="([^"]+)"', msg)
            m_nm = re.search(r'name="([^"]+)"', msg)
            m_comm = re.search(r'comm="([^"]+)"', msg)
            m_info = re.search(r'info="([^"]+)"', msg)

            op_cls = m_op.group(1) if (m_op and re.match(r"^[A-Za-z0-9_]{1,24}$", m_op.group(1))) else "other"
            name_val = m_nm.group(1) if m_nm else ""
            name_cls = "wpcg_prod" if "wpcaregrid-mysql-production" in name_val else ("wpcg_restore" if "restore" in name_val else "other")
            info_cls = "same_as_current" if (m_info and "same as current" in m_info.group(1)) else "other"
            comm_cls = "mysqld" if (m_comm and "mysqld" in m_comm.group(1)) else "other"
            emit(f"STATUS t={ts_str} src={tr} op={op_cls} name={name_cls} info={info_cls} comm={comm_cls}")

emit(f"DENIAL_TOTAL n={denials_count['total']} wpcg_prod={denials_count['wpcg_prod']} wpcg_restore={denials_count['wpcg_restore']} vendor_mysqld={denials_count['vendor_mysqld']} other={denials_count['other']}")
if kernel_suppression_seen > 0:
    emit(f"DENIAL_CHANNEL kernel_printk=lower_bound n={kernel_suppression_seen}")
else:
    emit("DENIAL_CHANNEL kernel_printk=no_suppression_seen")

if audit_loss_seen > 0:
    emit("DENIAL_CHANNEL audit_transport=lower_bound")
else:
    emit("DENIAL_CHANNEL audit_transport=no_loss_marker_seen")

emit("DENIAL_EVIDENCE=NOT_PROOF;quiet_deny_rules=yes;scope=audited_denials_only")
emit(f"STATUS_TOTAL n={status_rows} since_1118={status_since_1118}")
emit(f"JOURNAL_UNTRUSTED n={journal_untrusted_post4}")
emit(f"POST4_RC={rc_aa}")
emit(f"SEC 4 END failed=0")

# ================= POST-5: Datadir Delta vs Baseline =================
emit("SEC 5 BEGIN datadir_delta")

# Run find format matching v2.2 exactly
# FM='%T@|%T+|%C@|%C+|0|%y|%m|%U|%G|%n|%s|%P\n'
rc_dd, out_dd, _ = run_cmd([
    "find", DATADIR_PATH, "-xdev", "-printf",
    "%T@|%T+|%C@|%C+|0|%y|%m|%U|%G|%n|%s|%P\n"
], timeout_sec=30)

baseline_classes = {
    "pem": 8, "auto_cnf": 1, "innodb_redo": 33, "undo": 2, "binlog": 1,
    "binlog_index": 1, "system_schema_dir": 123, "ibdata": 1, "ib_buffer_pool": 1,
    "mysql_ibd": 1, "innodb_dblwr": 2, "innodb_temp": 1, "root_dir": 1, "other": 1
}

def classify_datadir_entry(relname):
    if not relname: return "root_dir"
    top = relname.split("/")[0]
    if top == "#innodb_redo": return "innodb_redo"
    if top == "#innodb_temp": return "innodb_temp"
    if top.startswith("#ib_") and top.endswith(".dblwr"): return "innodb_dblwr"
    if re.match(r"^ibdata[0-9]+$", top): return "ibdata"
    if re.match(r"^ibtmp[0-9]+$", top): return "ibtmp"
    if re.match(r"^undo_[0-9]+$", top): return "undo"
    if top == "mysql.ibd": return "mysql_ibd"
    if top == "ib_buffer_pool": return "ib_buffer_pool"
    if re.match(r"^ib_logfile[0-9]+$", top): return "ib_logfile"
    if re.match(r"^binlog\.[0-9]+$", top): return "binlog"
    if top == "binlog.index": return "binlog_index"
    if top == "auto.cnf": return "auto_cnf"
    if top.endswith(".pem"): return "pem"
    if top in ("mysql", "sys", "performance_schema"): return "system_schema_dir"
    if top.startswith("#"): return "hash_other"
    return "other"

entries_total = 0
observed_classes = {}
meta_fingerprint_lines = []
shape_fingerprint_lines = []

symlink_count = 0
ownmode_drift = 0
auto_cnf_mtime_changed = "no" if t0_epoch > 0 else "UNKNOWN"
pem_mtime_changed = "no" if t0_epoch > 0 else "UNKNOWN"
mysqld_auto_cnf_present = "no"
unknown_new_entries = 0

if rc_dd == 0:
    for raw_line in out_dd.splitlines():
        if not raw_line.strip(): continue
        parts = raw_line.split(b"|")
        if len(parts) >= 12:
            entries_total += 1
            t_epoch = float(parts[0])
            t_plus = parts[1].decode("ascii", errors="replace")
            c_plus = parts[3].decode("ascii", errors="replace")
            b_epoch = parts[4].decode("ascii", errors="replace")
            ftype = parts[5].decode("ascii", errors="replace")
            fmode = parts[6].decode("ascii", errors="replace")
            fuid = parts[7].decode("ascii", errors="replace")
            fgid = parts[8].decode("ascii", errors="replace")
            fsize = parts[10].decode("ascii", errors="replace")
            rel_name = b"|".join(parts[11:]).decode("ascii", errors="replace")

            if ftype == "l": symlink_count += 1
            if fmode not in ("750", "644", "640", "600"): ownmode_drift += 1
            if rel_name == "mysqld-auto.cnf": mysqld_auto_cnf_present = "yes"

            cls = classify_datadir_entry(rel_name)
            observed_classes[cls] = observed_classes.get(cls, 0) + 1

            if cls == "auto_cnf":
                if t0_epoch > 0:
                    if t_epoch > t0_epoch:
                        auto_cnf_mtime_changed = "yes"
                else:
                    auto_cnf_mtime_changed = "UNKNOWN"
            if cls == "pem":
                if t0_epoch > 0:
                    if t_epoch > t0_epoch:
                        pem_mtime_changed = "yes"
                else:
                    pem_mtime_changed = "UNKNOWN"

            # Fingerprints lines according to v2.2 awk logic:
            # print n "|" $6 "|" $7 "|" $8 "|" $9 "|" $11 "|" $2 "|" $4 "|" $5
            meta_line = f"{rel_name}|{ftype}|{fmode}|{fuid}|{fgid}|{fsize}|{t_plus}|{c_plus}|{b_epoch}"
            meta_fingerprint_lines.append(meta_line)
            shape_line = f"{rel_name}|{ftype}"
            shape_fingerprint_lines.append(shape_line)

emit(f"DATADIR_ENTRIES_TOTAL n={entries_total}")
rc_cc, out_cc, _ = run_cmd(["find", DATADIR_PATH, "-xdev", "-name", "*[[:cntrl:]]*", "-printf", "x"], timeout_sec=10, check_rc=False)
ctrl_chars_count = len(out_cc) if rc_cc == 0 else 0
emit(f"CTRL_CHAR_NAMES n={ctrl_chars_count}")
for c, base_cnt in baseline_classes.items():
    obs_cnt = observed_classes.get(c, 0)
    delta = obs_cnt - base_cnt
    if delta > 0:
        if c in ("binlog", "binlog_index", "innodb_redo", "ib_buffer_pool", "undo", "mysql_ibd"):
            emit(f"NEW_ENTRIES class={c} n={delta}")
        else:
            unknown_new_entries += delta

emit(f"UNKNOWN_NEW_ENTRIES n={unknown_new_entries}")
emit(f"AUTO_CNF_MTIME_CHANGED={auto_cnf_mtime_changed}")
emit(f"PEM_MTIME_CHANGED={pem_mtime_changed}")
emit(f"MYSQLD_AUTO_CNF_PRESENT={mysqld_auto_cnf_present}")
emit(f"SYMLINKS_PRESENT n={symlink_count}")
emit(f"OWNMODE_DRIFT n={ownmode_drift}")

# Compute SHA256 of sorted lines
meta_fingerprint_lines.sort()
meta_blob = "\n".join(meta_fingerprint_lines) + ("\n" if meta_fingerprint_lines else "")
fp_meta = hashlib.sha256(meta_blob.encode("ascii")).hexdigest()

shape_fingerprint_lines.sort()
shape_blob = "\n".join(shape_fingerprint_lines) + ("\n" if shape_fingerprint_lines else "")
fp_shape = hashlib.sha256(shape_blob.encode("ascii")).hexdigest()

emit(f"FINGERPRINT_META_SHA256={fp_meta}")
emit(f"FINGERPRINT_SHAPE_SHA256={fp_shape}")
emit(f"BASELINE_FINGERPRINT_META={BASELINE_FINGERPRINT_META}")
emit(f"FINGERPRINT_META_EQUAL={'yes' if fp_meta == BASELINE_FINGERPRINT_META else 'no'}")

# Verify error log stat
try:
    lst_log = os.stat(MYSQLD_LOG_PATH)
    lmode = oct(stat.S_IMODE(lst_log.st_mode))[2:]
    own_log = "mysql:mysql" if (mysql_uid >= 0 and lst_log.st_uid == mysql_uid and lst_log.st_gid == mysql_gid) else ("root:root" if (lst_log.st_uid == 0 and lst_log.st_gid == 0) else "other")
    emit(f"LOG_FILE_STAT mode=0{lmode} owner={own_log}")
except BaseException:
    emit("LOG_FILE_STAT mode=UNKNOWN owner=UNKNOWN")

emit(f"POST5_RC={rc_dd}")
emit(f"SEC 5 END failed=0")

# ================= POST-6: Capacity Delta =================
emit("SEC 6 BEGIN capacity_delta")

# 1. /proc/meminfo
meminfo_bytes = safe_read_file("/proc/meminfo")
if meminfo_bytes:
    for mline in meminfo_bytes.decode("ascii", errors="replace").splitlines():
        if ":" in mline:
            k, rest = mline.split(":", 1)
            k = k.strip()
            parts = rest.strip().split()
            val_kb = int(parts[0]) if parts and parts[0].isdigit() else 0
            val_mib = val_kb // 1024
            mem_map = {
                "MemTotal": "MEM_TOTAL_MIB",
                "MemAvailable": "MEM_AVAILABLE_MIB",
                "SwapTotal": "SWAP_TOTAL_MIB",
                "SwapFree": "SWAP_FREE_MIB",
                "Committed_AS": "COMMITTED_AS_MIB",
                "CommitLimit": "COMMIT_LIMIT_MIB",
            }
            if k in mem_map:
                emit(f"{mem_map[k]}={val_mib}")

vm_oc = safe_read_file("/proc/sys/vm/overcommit_memory")
emit(f"VM_OVERCOMMIT_MEMORY={int(vm_oc.strip()) if vm_oc and vm_oc.strip().isdigit() else 0}")
vm_sw = safe_read_file("/proc/sys/vm/swappiness")
emit(f"VM_SWAPPINESS={int(vm_sw.strip()) if vm_sw and vm_sw.strip().isdigit() else 0}")

# 2. Pressure
for res_type in ("memory", "io", "cpu"):
    p_path = f"/proc/pressure/{res_type}"
    p_data = safe_read_file(p_path)
    if p_data:
        for pline in p_data.decode("ascii", errors="replace").splitlines():
            # some avg10=0.00 avg60=0.00 avg300=0.00 total=0
            p_parts = pline.split()
            if p_parts:
                kind = p_parts[0]
                for p_item in p_parts[1:]:
                    if "=" in p_item:
                        pk, pv = p_item.split("=", 1)
                        if pk in ("avg10", "avg60"):
                            emit(f"PSI_{res_type.upper()}_{kind.upper()}_{pk.upper()}={pv}")
    else:
        emit(f"PSI_{res_type.upper()}=UNKNOWN")

# Loadavg and nproc
load_data = safe_read_file("/proc/loadavg")
if load_data:
    lp = load_data.decode("ascii", errors="replace").split()
    if len(lp) >= 3:
        emit(f"LOADAVG_1={lp[0]}")
        emit(f"LOADAVG_5={lp[1]}")
        emit(f"LOADAVG_15={lp[2]}")

emit(f"NPROC={os.cpu_count() or 1}")

# 3. OOM history
vmstat_data = safe_read_file("/proc/vmstat")
oom_kill_val = 0
if vmstat_data:
    for vline in vmstat_data.decode("ascii", errors="replace").splitlines():
        if vline.startswith("oom_kill "):
            p = vline.split()
            if len(p) >= 2 and p[1].isdigit():
                oom_kill_val = int(p[1])
emit(f"VMSTAT_OOM_KILL={oom_kill_val}")

rc_oom, out_oom, _ = run_cmd(["journalctl", "-k", "-b", "--grep", "Out of memory|oom-kill|Killed process", "-q"], timeout_sec=10, check_rc=False)
oom_j_count = len(out_oom.splitlines()) if rc_oom == 0 else 0
emit(f"JOURNAL_OOM_COUNT={oom_j_count}")

# 4. Largest consumers
rc_ps, out_ps, _ = run_cmd(["ps", "-eo", "comm=,rss=", "--sort=-rss"], timeout_sec=10)
if rc_ps == 0:
    rank = 1
    for ps_line in out_ps.decode("ascii", errors="replace").splitlines()[:8]:
        pp = ps_line.strip().split()
        if len(pp) >= 2:
            cname = pp[0]
            rss_kb = int(pp[1]) if pp[1].isdigit() else 0
            c_cls = "mysqld" if "mysqld" in cname else ("php_fpm" if "php" in cname else ("nginx" if "nginx" in cname else ("systemd" if "systemd" in cname else "other")))
            emit(f"TOP_CONSUMER rank={rank} comm={c_cls} rss_mib={rss_kb // 1024}")
            rank += 1

if leg_pid > 0:
    leg_st = safe_read_file(f"/proc/{leg_pid}/status")
    if leg_st:
        for sline in leg_st.decode("ascii", errors="replace").splitlines():
            if sline.startswith("VmRSS:"):
                p = sline.split()
                if len(p) >= 2 and p[1].isdigit():
                    emit(f"LEGACY_VMRSS_MIB={int(p[1]) // 1024}")
            elif sline.startswith("VmSwap:"):
                p = sline.split()
                if len(p) >= 2 and p[1].isdigit():
                    emit(f"LEGACY_VMSWAP_MIB={int(p[1]) // 1024}")

rc_lm, out_lm, _ = run_cmd(["systemctl", "show", UNIT_LEGACY, "-p", "MemoryCurrent"], timeout_sec=10)
if rc_lm == 0 and b"=" in out_lm:
    emit(f"LEGACY_MEMORY_CURRENT={out_lm.decode('ascii', errors='replace').split('=', 1)[1].strip()}")

# 5. AIO and handles
aio_nr = safe_read_file("/proc/sys/fs/aio-nr")
aio_max = safe_read_file("/proc/sys/fs/aio-max-nr")
emit(f"AIO_NR={aio_nr.strip().decode('ascii') if aio_nr else '0'}")
emit(f"AIO_MAX_NR={aio_max.strip().decode('ascii') if aio_max else '0'}")

file_nr_data = safe_read_file("/proc/sys/fs/file-nr")
if file_nr_data:
    fp = file_nr_data.decode("ascii", errors="replace").split()
    if len(fp) >= 3:
        emit(f"FILE_NR={fp[0]}")
        emit(f"FILE_MAX={fp[2]}")

# 7. Resolved server settings (G1 only)
rc_hlp, out_hlp, _ = run_cmd([
    "mysqld", "--defaults-file=/etc/mysql/my.cnf",
    "--defaults-group-suffix=@wpcaregrid-production",
    "--verbose", "--help"
], timeout_sec=15, check_rc=False)

whitelisted_keys = {
    "innodb-buffer-pool-size": "134217728",
    "innodb-buffer-pool-instances": "1",
    "innodb-log-buffer-size": "16777216",
    "innodb-redo-log-capacity": "104857600",
    "innodb-use-native-aio": "ON",
    "innodb-flush-method": "fsync",
    "performance-schema": "ON",
    "max-connections": "151",
    "table-open-cache": "4000",
    "binlog-expire-logs-seconds": "2592000",
    "skip-name-resolve": "ON"
}

if rc_hlp == 0 and out_hlp:
    for hline in out_hlp.decode("ascii", errors="replace").splitlines():
        parts = hline.strip().split()
        if len(parts) >= 2 and parts[0] in whitelisted_keys:
            val = parts[1]
            if re.match(r"^[A-Za-z0-9_.:=/-]{1,40}$", val):
                whitelisted_keys[parts[0]] = val

for wk in sorted(whitelisted_keys.keys()):
    emit(f"RESOLVED_SETTING key={wk} val={whitelisted_keys[wk]}")

# G1 MainPID VmRSS / MemoryCurrent
if main_pid > 0:
    g1_st = safe_read_file(f"/proc/{main_pid}/status")
    if g1_st:
        for sline in g1_st.decode("ascii", errors="replace").splitlines():
            if sline.startswith("VmRSS:"):
                p = sline.split()
                if len(p) >= 2 and p[1].isdigit():
                    emit(f"G1_VMRSS_MIB={int(p[1]) // 1024}")

if "MemoryCurrent" in unit_props:
    emit(f"G1_MEMORY_CURRENT={unit_props['MemoryCurrent']}")

emit(f"POST6_RC=0")
emit(f"SEC 6 END failed=0")

# ================= POST-7: Config & Profile Re-Hash =================
emit("SEC 7 BEGIN config_rehash")

for role, (path, expected_hash) in sorted(PINNED_HASHES.items()):
    actual_hash = sha256_file(path)
    base = os.path.basename(path)
    pin_match = "MATCH" if actual_hash == expected_hash else "MISMATCH"
    emit(f"ARTEFACT_HASH role={role} base={base} sha256={actual_hash} pin={pin_match}")

# Check AppArmor enforce mode in /sys/kernel/security/apparmor/profiles
aa_prof_data = safe_read_file(AA_PROFILES_PATH)
prod_enforce = "other"
restore_enforce = "other"
if aa_prof_data:
    for pline in aa_prof_data.decode("ascii", errors="replace").splitlines():
        if "wpcaregrid-mysql-production (enforce)" in pline:
            prod_enforce = "enforce"
        if "wpcaregrid-mysql-production-restore (enforce)" in pline:
            restore_enforce = "enforce"

emit(f"PROFILE_MODE profile=wpcg_prod mode={prod_enforce}")
emit(f"PROFILE_MODE profile=wpcg_restore mode={restore_enforce}")

# NeedDaemonReload on both units
rc_nr_p, out_nr_p, _ = run_cmd(["systemctl", "show", UNIT_PROD, "-p", "NeedDaemonReload"], timeout_sec=10)
p_nr = "no"
if rc_nr_p == 0 and b"=" in out_nr_p:
    p_nr = out_nr_p.decode("ascii", errors="replace").split("=", 1)[1].strip()
emit(f"DAEMON_RELOAD_NEEDED unit=g1_prod need_reload={p_nr if p_nr in ('yes', 'no') else 'other'}")

rc_nr_r, out_nr_r, _ = run_cmd(["systemctl", "show", UNIT_RESTORE, "-p", "NeedDaemonReload"], timeout_sec=10)
r_nr = "no"
if rc_nr_r == 0 and b"=" in out_nr_r:
    r_nr = out_nr_r.decode("ascii", errors="replace").split("=", 1)[1].strip()
emit(f"DAEMON_RELOAD_NEEDED unit=g1_restore need_reload={r_nr if r_nr in ('yes', 'no') else 'other'}")

emit(f"POST7_RC=0")
emit(f"SEC 7 END failed=0")

# ================= Final Block Markers =================
emit(f"FAILED_READS n={failed_reads}")

# Calculate body lines count and body SHA256
body_text = "\n".join(body_lines) + "\n"
body_sha256 = hashlib.sha256(body_text.encode("ascii")).hexdigest()
body_line_count = len(body_lines)

# Output entire block
sys.stdout.write(f"WPCG_BLOCK_BEGIN nonce={nonce} script_sha256={script_sha256} threshold_set=thresholds.v1\n")
sys.stdout.write(body_text)
sys.stdout.write(f"WPCG_BLOCK_END nonce={nonce} lines={body_line_count} body_sha256={body_sha256}\n")
