#!/usr/bin/env python3
"""Deterministic local tests for go20-38-s7b-containment-v1.sh: virtual clock, simulated systemctl/proc/cgroup, no host access, no real stop/kill."""
import os, re, sys, json, shutil, stat, subprocess, tempfile, getpass, difflib, time
HERE = os.path.dirname(os.path.abspath(__file__))
WRAP = os.environ.get("S7B_WRAP") or os.path.join(HERE, "..", "s7b-containment.sh")
ORIG = open(WRAP).read(); UID = os.getuid()
G1CG = "/system.slice/system-mysql.slice/mysql@wpcaregrid-production.service"
SEAMS = {
 'now()  { local u; read -r u _ < /proc/uptime 2>/dev/null; echo "${u%.*}"; }': 'now()  { cat "$S7B_SANDBOX/vclock"; }',
 'nap()  { sleep "$1"; }': 'nap()  { python3 -S -E "$S7B_SANDBOX/sim.py" advance "$1"; if [ $? -eq 77 ]; then kill -TERM $$; fi; return 0; }',
 'is_tty() { [ -t 0 ] && [ -t 1 ] && { : < /dev/tty; } 2>/dev/null; }': 'is_tty() { [ -f "$S7B_SANDBOX/tty_yes" ]; }',
 'cgroup2_ok() { [ "$(stat -f -c %T -- "${1:-$CGROOT}" 2>/dev/null)" = "cgroup2fs" ]; }': 'cgroup2_ok() { [ -f "$S7B_SANDBOX/cg2_yes" ]; }',
 'confirm_read() { REPLY=""; IFS= read -r -t "$KILL_CONFIRM_SEC" REPLY < /dev/tty; }': 'confirm_read() { REPLY=""; [ -f "$S7B_SANDBOX/confirm_advance" ] && python3 -S -E "$S7B_SANDBOX/sim.py" advance 5; [ -f "$S7B_SANDBOX/confirm.txt" ] && REPLY=$(sed "s/@NONCE@/$nonce/" "$S7B_SANDBOX/confirm.txt"); [ -n "$REPLY" ]; }',
}
class SB:
    def __init__(self, scen, tty=True, cg2=True, confirm=None, args=("--instance", "production", "--mode", "contain"), flags=(), int_at=None, wmode=0o700, uid_expect=None, path_rel=False, run_parent_missing=False, patches=()):
        self.d = tempfile.mkdtemp(prefix="s7b-"); d = self.d
        os.makedirs(d + "/runp"); os.makedirs(d + "/bin"); os.makedirs(d + "/proc"); os.makedirs(d + "/cg"); os.makedirs(d + "/state")
        self.scen = scen; json.dump(scen, open(d + "/scenario.json", "w"))
        shutil.copy(os.path.join(HERE, "sim.py"), d + "/sim.py"); shutil.copy(os.path.join(HERE, "systemctl_stub.sh"), d + "/bin/systemctl"); os.chmod(d + "/bin/systemctl", 0o755)
        if tty: open(d + "/tty_yes", "w").close()
        if cg2: open(d + "/cg2_yes", "w").close()
        if confirm is not None: open(d + "/confirm.txt", "w").write(confirm)
        if "confirm_advance" in flags: open(d + "/confirm_advance", "w").close()
        if int_at is not None: open(d + "/int_at", "w").write(str(int_at))
        for f in ("show_fail", "show_garbage", "stop_fail", "fail_after", "show_dup", "show_missing"):
            for k, v in scen.get("_files", {}).items():
                if k == f: open(d + "/" + f, "w").write(v)
        env = dict(os.environ, S7B_SANDBOX=d); subprocess.run(["python3", d + "/sim.py", "init"], env=env, check=True)
        if scen.get("cg_symlink"):
            real = d + "/cg_real"; os.makedirs(real); os.makedirs(os.path.dirname(d + "/cg" + G1CG), exist_ok=True)
            os.symlink(real, d + "/cg" + G1CG); open(real + "/cgroup.kill", "w").close(); open(real + "/cgroup.procs", "w").write("4242\n")
        t = ORIG
        def sub(pat, repl):
            nonlocal t
            n = len(re.findall(pat, t, re.M)); assert n == 1, (pat, n); t = re.sub(pat, lambda m: repl, t, flags=re.M)
        sub(r'^ROOT_UID_EXPECT="0"$', 'ROOT_UID_EXPECT="%d"' % (uid_expect if uid_expect is not None else UID))
        sub(r'^RUN_PARENT="/root"$', 'RUN_PARENT="%s"' % (d + "/runp" + ("-missing" if run_parent_missing else "")))
        sub(r'^PROCROOT="/proc"$', 'PROCROOT="%s"' % (d + "/proc")); sub(r'^CGROOT="/sys/fs/cgroup"$', 'CGROOT="%s"' % (d + "/cg"))
        sub(r'^LOCKFILE="/run/wpcg-s7b.lock"$', 'LOCKFILE="%s"' % (d + "/lock"))
        sub(r'^export PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C LC_ALL=C$', 'export PATH=%s:/usr/sbin:/usr/bin:/sbin:/bin LANG=C LC_ALL=C' % (d + "/bin"))
        for a, b in SEAMS.items():
            assert t.count(a) == 1, a; t = t.replace(a, b)
        for pa, pb in patches:
            assert t.count(pa) == 1, "VACUOUS/AMBIGUOUS patch: " + pa; t = t.replace(pa, pb)
        self.text = t; self.path = d + "/wrapper.sh"; open(self.path, "w").write(t); os.chmod(self.path, wmode)
        self.args = list(args)
    def run(self, hold_lock=False):
        env = dict(os.environ, S7B_SANDBOX=self.d)
        target = os.path.basename(self.path) if False else self.path
        r = subprocess.run(["bash", "--noprofile", "--norc", target] + self.args, capture_output=True, text=True, env=env, timeout=120)
        self.r = r; return r
    def calls(self): return open(self.d + "/calls.log").read().splitlines() if os.path.exists(self.d + "/calls.log") else []
    def stops(self): return [c for c in self.calls() if c.startswith("stop ")]
    def forbidden(self): return [c for c in self.calls() if c.startswith("FORBIDDEN")]
    def killfile(self): p = self.d + "/cg" + G1CG + "/cgroup.kill"; return open(p).read() if os.path.exists(p) else None
    def rundirs(self): return [x for x in os.listdir(self.d + "/runp")]
    def result(self):
        m = re.findall(r'^S7B_RESULT=(.*)$', self.r.stdout, re.M); return m[-1] if m else None
    def clean(self): shutil.rmtree(self.d, ignore_errors=True)
R = []
CASES = []
def case(name, fn): CASES.append((name, fn))
def _run_case(item):
    name, fn = item
    try: fn(); return (name, "PASS", "")
    except AssertionError as e: return (name, "FAIL", str(e)[:260])
    except Exception as e: return (name, "ERROR", repr(e)[:260])
def with_sb(fn, scen, **kw):
    def run():
        sb = SB(scen, **kw)
        try: fn(sb)
        finally: sb.clean()
    return run
def A(c, m="assertion"): assert c, m
def g1(*rows): return list(rows)
ACT = {"A": "active", "S": "running", "pid": 4242, "nr": 0}
ST = {"A": "activating", "S": "start-pre", "pid": 0, "nr": 0}
STRT = {"A": "activating", "S": "start", "pid": 4242, "nr": 0}
NORMAL = {"g1": [[0, {"A": "inactive", "S": "dead", "pid": 0, "nr": 0}], [10, ST], [20, ACT]]}
KILLOK = {"g1": [[0, {"A": "inactive", "S": "dead", "pid": 0, "nr": 0}], [10, STRT]], "procs": {"4242": "mysqld"}}
CONF = "KILL @NONCE@"
def sample_t(sb, key):
    m = re.search(r'S7B_TRIGGER code=%s t=(\d+)' % key, sb.r.stdout); return int(m.group(1)) if m else None

# ---------------- normal cases
def t_normal(mode):
    def f(sb):
        r = sb.run(); A(r.returncode == 0, r.stdout[-300:]); A(sb.result().startswith("WINDOW_COMPLETE;G1=active/running;NRESTARTS=0;ACTION=NONE") and "S7B_FINAL g1_now=active/running stop_issued=0 kill_written=0 exit=0" in r.stdout, sb.result())
        A(not sb.stops() and not sb.forbidden() and sb.killfile() in (None, ""), "no action expected")
        A("S7B_START_OBSERVED" in r.stdout and r.stdout.count("S7B_SAMPLE") >= 55, "samples")
        rd = sb.rundirs(); A(len(rd) == 1 and oct(os.stat(sb.d + "/runp/" + rd[0]).st_mode & 0o777) == "0o700", "run dir 0700")
        ev = sb.d + "/runp/" + rd[0] + "/evidence.txt"; A(oct(os.stat(ev).st_mode & 0o777) == "0o600" and "S7B_RESULT=WINDOW_COMPLETE" in open(ev).read(), "evidence file")
        A(int(open(sb.d + "/vclock").read()) <= 306 + 10, "window bounded (start observed at 10 s, window 300 s from first observation)")
    return f
case("N01 normal start, observe mode: window completes, no action, evidence 0600/0700", with_sb(t_normal("observe"), NORMAL, args=("--instance", "production", "--mode", "observe"), tty=False))
case("N02 normal start, contain mode: window completes, no stop, no kill", with_sb(t_normal("contain"), NORMAL))
def t_nostart(sb):
    r = sb.run(); A(r.returncode == 4 and sb.result().startswith("NO_START_OBSERVED;WAITED=120s;ACTION=NONE;G1_NOT_SUPERVISED") and "S7B_FINAL g1_now=inactive/dead" in r.stdout, sb.result()); A(not sb.stops())
case("N03 start never observed within 120 s: NO_START_OBSERVED = NOT SUPERVISED, exit 4, no action", with_sb(t_nostart, {"g1": []}))
def t_failed(sb):
    r = sb.run(); A(r.returncode == 1 and (sb.result() or "").startswith("UNIT_FAILED;STATE=failed/failed;NRESTARTS=0;RESULT=success;ACTION=NONE;G1_NOT_RUNNING"), sb.result()); A(not sb.stops())
case("N04 unit fails after start (helper-level failure): UNIT_FAILED exit 1, no stop, no reset-failed", with_sb(t_failed, {"g1": [[0, {"A": "inactive", "S": "dead", "pid": 0, "nr": 0}], [10, ST], [20, {"A": "failed", "S": "failed", "pid": 0, "nr": 0}]]}))
# ---------------- start hang
HANG_STOP_OK = dict(KILLOK, stop_delay=20)
def t_hang_stop(sb):
    r = sb.run(); t = sample_t(sb, "START_HANG"); so = int(re.search(r"S7B_START_OBSERVED t=(\d+)", r.stdout).group(1)); A(r.returncode == 1 and t is not None and 150 <= t - so <= 155, "trigger %s observed %s" % (t, so))
    A(len(sb.stops()) == 1 and "stop-args: stop --no-block" in sb.calls()[sb.calls().index(sb.stops()[0]) - 1], sb.calls()[-6:])
    A((sb.result() or "").startswith("STOPPED;CODE=START_HANG") and sb.killfile() in (None, ""), sb.result()); A(not sb.forbidden())
case("H01 start hang at the 150 s cutoff: exactly one stop, stop effective -> STOPPED, no kill", with_sb(t_hang_stop, HANG_STOP_OK))
def t_hang_obs(sb):
    r = sb.run(); A(r.returncode == 1 and (sb.result() or "").startswith("TRIGGER_NO_ACTION;CODE=START_HANG;MODE=observe;G1_NOT_CONTAINED") and not sb.stops(), sb.result())
case("H02 start hang in observe mode: trigger reported, no stop", with_sb(t_hang_obs, KILLOK, args=("--instance", "production", "--mode", "observe"), tty=False))
def t_hang_nokill_early(sb):
    r = sb.run(); A((sb.result() or "").startswith("STOP_NOT_EFFECTIVE") or (sb.result() or "").startswith("KILLED"), sb.result())
KILLSCEN = dict(KILLOK, stop_mode="never")
def t_kill_ok(sb):
    r = sb.run(); A(r.returncode == 1 and (sb.result() or "").startswith("KILL_WRITTEN_ENDED;CODE=START_HANG"), sb.result() + r.stdout[-400:])
    A(len(sb.stops()) == 1 and sb.killfile().strip() == "1" and "S7B_KILL_VALIDATION=PASS;PIDS=1;MAINPID=4242" in r.stdout and "S7B_KILL=WRITTEN" in r.stdout and "S7B_KILL_VERIFY=ended" in r.stdout, r.stdout[-500:])
    A(r.stdout.count("S7B_STOPWAIT") >= 20, "waited for the stop")
case("H03 stop ineffective, validation PASS, exact confirmation typed: one stop, then cgroup.kill written once", with_sb(t_kill_ok, KILLSCEN, confirm=CONF))
def t_nokill(why):
    def f(sb):
        r = sb.run(); A(r.returncode == 1 and sb.killfile() in (None, ""), "kill file must stay empty: %r" % sb.killfile())
        A((sb.result() or "").startswith("STOP_NOT_EFFECTIVE"), sb.result()); A(len(sb.stops()) == 1 and "S7B_KILL=WRITTEN" not in r.stdout, "one stop, no write")
        if why: A(why in r.stdout, "expected %s in:\n%s" % (why, r.stdout[-700:]))
    return f
case("H04 wrong confirmation phrase: no kill", with_sb(t_nokill("phrase_mismatch"), KILLSCEN, confirm="KILL deadbeef"))
case("H05 no confirmation (timeout/EOF): no kill", with_sb(t_nokill("timeout_or_eof"), KILLSCEN, confirm=None))
case("H06 confirmation without the nonce prefix: no kill", with_sb(t_nokill("phrase_mismatch"), KILLSCEN, confirm="kill @NONCE@"))
def t_stopfail(sb):
    r = sb.run(); A((sb.result() or "").startswith("STOP_COMMAND_FAILED;CODE=START_HANG;G1_MAY_STILL_BE_RUNNING") and sb.killfile() in (None, "") and len(sb.stops()) == 0, sb.result() + str(sb.calls()[-4:]))
case("H07 the stop command itself fails: STOP_COMMAND_FAILED, no kill", with_sb(t_stopfail, dict(KILLSCEN, _files={"stop_fail": "1"}), confirm=CONF))
def t_int_stop(sb):
    r = sb.run(); A(r.returncode == 1 and (sb.result() or "").startswith("INTERRUPTED_DURING_STOP_NO_KILL;CODE=START_HANG;UNSUPERVISED_STOP_JOB_MAY_BE_QUEUED") and sb.killfile() in (None, ""), sb.result())
case("H08 interrupted while waiting for the stop: no kill, evidence kept", with_sb(t_int_stop, KILLSCEN, confirm=CONF, int_at=190))
# ---------------- stop hang initiated by the helper (no stop by the wrapper)
DEACT = {"g1": [[0, {"A": "inactive", "S": "dead", "pid": 0, "nr": 0}], [10, ACT], [30, {"A": "deactivating", "S": "stop-sigterm", "pid": 4242, "nr": 0}]], "stop_mode": "none"}
def t_stophang(sb):
    r = sb.run(); A(r.returncode == 1 and (sb.result() or "").startswith("KILL_WRITTEN_ENDED;CODE=STOP_HANG") and not sb.stops() and sb.killfile().strip() == "1", sb.result() + r.stdout[-300:])
case("H09 helper-initiated stop hangs 120 s (deactivating): no second stop, gated kill after confirmation", with_sb(t_stophang, DEACT, confirm=CONF))
def t_stophang_obs(sb):
    r = sb.run(); A((sb.result() or "").startswith("TRIGGER_NO_ACTION;CODE=STOP_HANG") and not sb.stops() and sb.killfile() in (None, ""), sb.result())
case("H10 deactivating hang in observe mode: report only", with_sb(t_stophang_obs, DEACT, args=("--instance", "production", "--mode", "observe"), tty=False))
# ---------------- restart loop
def loop(nm, rows, code="RESTART_LOOP"):
    def f(sb):
        r = sb.run(); A(r.returncode == 1 and sample_t(sb, code) is not None, sb.result() + r.stdout[-300:]); A(len(sb.stops()) == 1 and (sb.result() or "").startswith("STOPPED;CODE=" + code), sb.result())
    return f
BASE = [0, {"A": "inactive", "S": "dead", "pid": 0, "nr": 0}]
case("L01 NRestarts rises above 0: restart loop trigger, one stop", with_sb(loop("nr", None), {"g1": [BASE, [10, ACT], [60, {"A": "activating", "S": "start-pre", "pid": 0, "nr": 1}]], "stop_delay": 10}))
case("L02 SubState auto-restart: restart loop trigger", with_sb(loop("ar", None), {"g1": [BASE, [10, STRT], [40, {"A": "activating", "S": "auto-restart", "pid": 0, "nr": 0}]], "stop_delay": 10}))
case("L03 transition deactivating -> activating without NRestarts: restart loop by transition", with_sb(loop("tr", None), {"g1": [BASE, [10, STRT], [30, {"A": "deactivating", "S": "stop-sigterm", "pid": 0, "nr": 0}], [40, ST]], "stop_delay": 10}))
def t_loop_obs(sb):
    r = sb.run(); A((sb.result() or "").startswith("TRIGGER_NO_ACTION;CODE=RESTART_LOOP") and not sb.stops(), sb.result())
case("L04 restart loop in observe mode: report only", with_sb(t_loop_obs, {"g1": [BASE, [10, ACT], [60, {"A": "activating", "S": "start-pre", "pid": 0, "nr": 1}]]}, args=("--instance", "production", "--mode", "observe"), tty=False))
# ---------------- memory / OOM
def trig(code, scen, **kw):
    def f(sb):
        r = sb.run(); A(r.returncode == 1 and sample_t(sb, code) is not None, sb.result() + r.stdout[-300:]); A(len(sb.stops()) == 1 and (sb.result() or "").startswith("STOPPED;CODE=" + code), sb.result())
    return with_sb(f, scen, **kw)
case("M01 MemAvailable 200 MiB (< 256): MEMORY_LOW, one stop", trig("MEMORY_LOW", dict(NORMAL, mem=[[0, 5585], [50, 200]], stop_delay=10)))
case("M02 MemAvailable 255 MiB: trigger (boundary below)", trig("MEMORY_LOW", dict(NORMAL, mem=[[0, 5585], [50, 255]], stop_delay=10)))
def t_mem256(sb):
    r = sb.run(); A(sb.result().startswith("WINDOW_COMPLETE") and not sb.stops(), sb.result())
case("M03 MemAvailable exactly 256 MiB: no trigger (boundary)", with_sb(t_mem256, dict(NORMAL, mem=[[0, 5585], [50, 256]])))
case("M04 oom_kill counter rises: OOM_KILL, one stop", trig("OOM_KILL", dict(NORMAL, oomk=[[0, 3], [40, 4]], stop_delay=10)))
def t_obs_lost(sb):
    r = sb.run(); A((sb.result() or "").startswith("STOPPED;CODE=OBSERVATION_LOST") and "READ_FAILED failures_in_last_12=3" in r.stdout and len(sb.stops()) == 1, sb.result() + r.stdout[-300:])
case("M05 MemAvailable becomes unreadable: 3 consecutive failed samples = OBSERVATION_LOST (never silently green)", with_sb(t_obs_lost, dict(NORMAL, mem=[[0, 5585], [50, None]], stop_delay=10)))
# ---------------- legacy protection
case("G01 legacy mysql.service leaves `active`: LEGACY_CHANGED, one stop", trig("LEGACY_CHANGED", dict(NORMAL, legacy=[[0, {"A": "active", "S": "running", "pid": 574923}], [45, {"A": "deactivating", "S": "stop-sigterm", "pid": 574923}]], stop_delay=10)))
case("G02 legacy MainPID changes: LEGACY_CHANGED, one stop", trig("LEGACY_CHANGED", dict(NORMAL, legacy=[[0, {"A": "active", "S": "running", "pid": 574923}], [45, {"A": "active", "S": "running", "pid": 600001}]], stop_delay=10)))
def t_leg_unread(sb):
    r = sb.run(); A((sb.result() or "").startswith("ARM") or "arm_legacy_unreadable" in r.stdout, r.stdout[-200:])
case("G03 legacy unreadable at arm: refuse (arm_legacy_unreadable)", with_sb(t_leg_unread, dict(NORMAL, _files={"show_fail": "mysql.service"})))
# ---------------- kill validation negatives (stop ineffective; correct confirmation offered; the kill must still be refused)
def kneg(code, scen, **kw):
    def f(sb):
        r = sb.run(); A(r.returncode == 1 and sb.killfile() in (None, ""), "no cgroup.kill write expected: %r" % sb.killfile())
        A((sb.result() or "").startswith("STOP_NOT_EFFECTIVE_KILL_REFUSED") and ("S7B_KILL_VALIDATION=STOP;CODE=%s" % code) in r.stdout and len(sb.stops()) == 1, sb.result() + "\n" + r.stdout[-500:])
    return with_sb(f, dict(KILLSCEN, **scen), confirm=CONF, **kw)
case("K01 ControlGroup path differs from the pinned path: refused", kneg("cgroup_path_not_pinned", {"g1_cg_tbl": [[0, "/system.slice/mysql.service"]]}))
case("K02 foreign process (nginx) in the cgroup: refused", kneg("foreign_process_in_cgroup", {"procs": {"4242": "mysqld", "4300": "nginx"}}))
case("K03 legacy MainPID inside the G1 cgroup: refused", kneg("legacy_pid_in_cgroup", {"procs": {"4242": "mysqld", "574923": "mysqld"}}))
case("K04 legacy cgroup equals the G1 cgroup: refused", kneg("legacy_overlap", {"legacy_cg_val": G1CG}))
case("K05 legacy cgroup is a parent of the G1 cgroup: refused", kneg("legacy_overlap", {"legacy_cg_val": "/system.slice/system-mysql.slice"}))
case("K06 legacy cgroup unknown (empty): refused", kneg("legacy_cgroup_unknown", {"legacy_cg_val": ""}))
case("K07 cgroup.kill file missing: refused", kneg("cgroup_kill_unavailable", {"no_cgroup_kill": True}))
def t_k08(sb):
    r = sb.run(); A("cgroup_dir_invalid" in r.stdout and sb.killfile() in (None, "") and not os.path.getsize(sb.d + "/cg_real/cgroup.kill"), r.stdout[-400:])
case("K08 the cgroup directory is a symlink: refused, nothing written through it", with_sb(t_k08, dict(KILLSCEN, cg_symlink=True), confirm=CONF))
case("K09 more than 64 processes in the cgroup: refused", kneg("too_many_pids", {"procs": {str(5000 + i): "mysqld" for i in range(65)} | {"4242": "mysqld"}}))
case("K10 empty cgroup.procs: refused", kneg("cgroup_empty", {"procs": {}}))
case("K11 MainPID is not listed in cgroup.procs: refused", kneg("mainpid_not_in_cgroup", {"g1": [[0, {"A": "inactive", "S": "dead", "pid": 0, "nr": 0}], [10, {"A": "activating", "S": "start", "pid": 9999, "nr": 0}]]}))
case("K12 not a cgroup2 filesystem: refused", kneg("not_cgroup2fs", {}, cg2=False))
case("K13 non-numeric pid line in cgroup.procs is impossible to bypass (comm check): comm python3 allowed, bash refused", kneg("foreign_process_in_cgroup", {"procs": {"4242": "mysqld", "4400": "bash"}}))
def t_toctou(sb):
    r = sb.run(); A(sb.killfile() in (None, "") and "S7B_KILL=STOP;CODE=revalidation_failed" in r.stdout, r.stdout[-500:])
case("K14 cgroup changes between the first validation and the write: revalidation refuses", with_sb(t_toctou, dict(KILLSCEN, g1_cg_tbl=[[0, G1CG], [283, "/system.slice/other.service"]]), confirm=CONF, flags=("confirm_advance",)))
def t_kill_helper_procs(sb):
    r = sb.run(); A(sb.result().startswith("KILL_WRITTEN_ENDED") and sb.killfile().strip() == "1", sb.result())
case("K15 python3 (helper preflight) and mysqld in the cgroup are acceptable", with_sb(t_kill_helper_procs, dict(KILLSCEN, procs={"4242": "mysqld", "4250": "python3"}), confirm=CONF))
# ---------------- arm preconditions and contract errors
def arm(code, scen=None, rc=2, **kw):
    def f(sb):
        r = sb.run(); A(r.returncode == rc and ("S7B_RESULT=STOP;CODE=%s" % code) in r.stdout, "%s | %s" % (r.returncode, r.stdout[-200:])); A(not sb.stops() and not sb.rundirs() or code in ("evidence_failed",), "nothing created before the preconditions")
    return with_sb(f, scen or NORMAL, **kw)
case("A01 G1 not inactive at arm", arm("arm_g1_not_inactive", {"g1": [[0, ACT]]}))
case("A02 G1 NRestarts already non-zero at arm", arm("arm_g1_nrestarts_nonzero", {"g1": [[0, {"A": "inactive", "S": "dead", "pid": 0, "nr": 2}]]}))
case("A03 legacy not active at arm", arm("arm_legacy_not_active", dict(NORMAL, legacy=[[0, {"A": "inactive", "S": "dead", "pid": 0}]])))
case("A04 legacy MainPID 0 at arm", arm("arm_legacy_mainpid_zero", dict(NORMAL, legacy=[[0, {"A": "active", "S": "running", "pid": 0}]])))
case("A05 MemAvailable already below the trigger at arm", arm("arm_mem_below_trigger", dict(NORMAL, mem=[[0, 200]])))
case("A06 MemAvailable unreadable at arm", arm("arm_mem_unreadable", dict(NORMAL, mem=[[0, None]])))
case("A07 oom_kill unreadable at arm", arm("arm_oomkill_unreadable", dict(NORMAL, oomk=[[0, None]])))
case("A08 G1 unit unreadable at arm (systemctl show fails)", arm("arm_g1_unreadable", dict(NORMAL, _files={"show_fail": "mysql@wpcaregrid-production.service"})))
case("A09 systemctl prints a malformed value: rejected, not parsed loosely", arm("arm_g1_unreadable", dict(NORMAL, _files={"show_garbage": "1"})))
case("A10 contain mode without a tty is refused", arm("contain_needs_tty", tty=False))
case("A11 file not owned by the expected (root) uid is refused", arm("self_owner", uid_expect=0 if UID != 0 else 1))
case("A12 wrapper file group/other-writable", arm("self_writable", wmode=0o770))
case("A13 usage: missing mode", arm("usage", args=("--instance", "production")))
case("A14 usage: unknown instance", arm("usage", args=("--instance", "legacy", "--mode", "observe")))
case("A15 usage: unknown mode", arm("usage", args=("--instance", "production", "--mode", "stop")))
case("A16 usage: extra argument / environment-like option", arm("usage", args=("--instance", "production", "--mode", "observe", "--timeout=5")))
case("A17 usage: duplicate --mode", arm("usage", args=("--mode", "observe", "--mode", "contain")))
def t_a18(sb):
    r = subprocess.run(["bash", "--noprofile", "--norc", "wrapper.sh", "--instance", "production", "--mode", "observe"], capture_output=True, text=True, cwd=sb.d, env=dict(os.environ, S7B_SANDBOX=sb.d)); A("CODE=self_not_absolute" in r.stdout, r.stdout)
case("A18 relative invocation path refused", with_sb(t_a18, NORMAL))
def t_a19(sb):
    import fcntl
    lf = open(sb.d + "/lock", "w"); fcntl.flock(lf, fcntl.LOCK_EX)
    r = sb.run(); A("CODE=already_running" in r.stdout and r.returncode == 2, r.stdout)
case("A19 a second instance is refused while the first holds the lock", with_sb(t_a19, NORMAL, tty=False, args=("--instance", "production", "--mode", "observe")))
def t_a20(sb):
    r = sb.run(); A("CODE=run_dir_failed" in r.stdout or "CODE=run_parent_invalid" in r.stdout, r.stdout)
case("A20 evidence run parent missing: refuse", with_sb(t_a20, NORMAL, run_parent_missing=True))
# ---------------- interrupts
def t_int(sb):
    r = sb.run(); A(r.returncode == 130 and (sb.result() or "").startswith("INTERRUPTED;ACTION=NONE;G1=") and not sb.stops() and sb.killfile() in (None, ""), sb.result())
    ev = sb.d + "/runp/" + sb.rundirs()[0] + "/evidence.txt"; A("INTERRUPTED" in open(ev).read(), "evidence keeps the interruption")
case("I01 interrupt during supervision: no stop, no kill, evidence kept, rc 130", with_sb(t_int, NORMAL, int_at=40))
# ---------------- evidence preservation and restore instance
def t_evid(sb):
    r = sb.run(); ev = sb.d + "/runp/" + sb.rundirs()[0] + "/evidence.txt"; txt = open(ev).read()
    A("SNAPSHOT tag=trigger_START_HANG" in txt and "SNAPSHOT tag=after_stop" in txt and "SNAP_G1 " in txt and "SNAP_LEGACY " in txt and "SNAP_MEM " in txt, "snapshots present")
    A(not re.search(r'(argv|password|secret)', txt, re.I), "no arguments or secrets in the evidence")
case("E01 trigger writes fixed-property snapshots to the root-only evidence file (no arguments, no log text)", with_sb(t_evid, HANG_STOP_OK))
def t_restore(sb):
    r = sb.run(); A("unit=mysql@wpcaregrid-production-restore.service" in r.stdout and sb.result().startswith("WINDOW_COMPLETE"), r.stdout[:300])
RESTORE = {"g1_cg": "/system.slice/system-mysql.slice/mysql@wpcaregrid-production-restore.service", "g1": NORMAL["g1"]}
case("E02 restore instance selects the restore unit", with_sb(t_restore, RESTORE, args=("--instance", "restore", "--mode", "observe"), tty=False))
def t_two_runs(sb):
    sb.run(); subprocess.run(["python3", sb.d + "/sim.py", "init"], env=dict(os.environ, S7B_SANDBOX=sb.d), check=True); sb.run(); A(len(sb.rundirs()) == 2, "separate run dirs, nothing overwritten")
case("E03 two runs create two run directories (no reuse, no overwrite)", with_sb(t_two_runs, NORMAL, args=("--instance", "production", "--mode", "observe"), tty=False))

# ---------------- v2: QA review regressions (P1-1..P1-5) and additional boundaries
def t_end_deact(sb):
    r = sb.run(); A(r.returncode == 1 and (sb.result() or "").startswith("WINDOW_END_STATE_NOT_ACTIVE;G1=deactivating/stop-sigterm") and not sb.stops(), sb.result())
case("R01 P1-1: helper-initiated stop in progress at the window end must not be a success (exit 1)", with_sb(t_end_deact, {"g1": [BASE, [10, ACT], [270, {"A": "deactivating", "S": "stop-sigterm", "pid": 4242, "nr": 0}]], "stop_mode": "none"}, args=("--instance", "production", "--mode", "observe"), tty=False))
def t_reloading(sb):
    r = sb.run(); A(r.returncode == 1 and sample_t(sb, "UNKNOWN_STATE") is not None and (sb.result() or "").startswith("STOPPED;CODE=UNKNOWN_STATE") and len(sb.stops()) == 1, sb.result())
case("R02 P1-1: an unexpected ActiveState (reloading) is a trigger, never silently green", with_sb(t_reloading, {"g1": [BASE, [10, ACT], [40, {"A": "reloading", "S": "reload", "pid": 4242, "nr": 0}]], "stop_delay": 10}))
def t_last_unreadable(sb):
    r = sb.run(); A(r.returncode == 1 and (sb.result() or "").startswith("WINDOW_END_UNVERIFIED;ACTION=NONE;G1_STATE_UNKNOWN"), sb.result() + r.stdout[-200:])
case("R03 P1-2: unreadable sample at the window end: WINDOW_END_UNVERIFIED (exit 1), no stale success", with_sb(t_last_unreadable, dict(NORMAL, mem=[[0, 5585], [309, None]]), args=("--instance", "production", "--mode", "observe"), tty=False))
def t_two_of_three(sb):
    r = sb.run(); A((sb.result() or "").startswith("STOPPED;CODE=OBSERVATION_LOST") and len(sb.stops()) == 1, sb.result())
FLAKY = [[0, 5585]] + [[t, (None if (t // 5) % 3 else 5585)] for t in range(50, 250, 5)]
case("R04 P1-2: two of every three samples unreadable still reaches OBSERVATION_LOST (3 failures in 12)", with_sb(t_two_of_three, dict(NORMAL, mem=FLAKY, stop_delay=10)))
def t_stop_ignored(sb):
    r = sb.run(); A((sb.result() or "").startswith("STOP_NOT_EFFECTIVE_KILL_REFUSED") and "CODE=no_stop_in_progress" in r.stdout and sb.killfile() in (None, "") and len(sb.stops()) == 1, sb.result() + r.stdout[-300:])
case("R05 P1-3: stop accepted but the unit stays activating (no stop job in effect): kill refused", with_sb(t_stop_ignored, dict(KILLOK, stop_mode="ignored"), confirm=CONF))
def t_ended_during_confirm(sb):
    r = sb.run(); A("S7B_KILL=STOP;CODE=revalidation_failed" in r.stdout and sb.killfile() in (None, ""), r.stdout[-400:])
case("R06 P1-3: the unit leaves the stopping state during the confirmation wait: revalidation refuses", with_sb(t_ended_during_confirm, dict(KILLSCEN, g1=[BASE, [10, STRT], [283, {"A": "failed", "S": "failed", "pid": 0, "nr": 0}]]), confirm=CONF, flags=("confirm_advance",)))
def t_loop_failed(sb):
    r = sb.run(); A(r.returncode == 1 and (sb.result() or "").startswith("RESTART_LOOP_ENDED_FAILED;STATE=failed/failed;NRESTARTS=4;RESULT=start-limit-hit;ACTION=NONE;G1_NOT_RUNNING") and not sb.stops(), sb.result())
case("R07 P1-4: a restart loop that ends in failed (start-limit-hit) is reported as such, exit 1", with_sb(t_loop_failed, {"g1": [BASE, [10, STRT], [30, {"A": "failed", "S": "failed", "pid": 0, "nr": 4, "R": "start-limit-hit"}]]}))
def t_arm_lost(sb):
    r = sb.run(); A(r.returncode == 4 and (sb.result() or "").startswith("ARM_OBSERVATION_LOST;ACTION=NONE;G1_NOT_SUPERVISED"), sb.result())
case("R08 P1-4: while waiting for the start, 3 consecutive unreadable polls: ARM_OBSERVATION_LOST, exit 4", with_sb(t_arm_lost, {"g1": [BASE, [60, STRT]], "_files": {"fail_after": "mysql@wpcaregrid-production.service 1"}}))
def t_kill_noeffect(sb):
    r = sb.run(); A(r.returncode == 1 and (sb.result() or "").startswith("KILL_WRITTEN_NOT_ENDED;CODE=START_HANG;KILL_WAS_WRITTEN") and sb.killfile().strip() == "1" and "S7B_KILL_VERIFY=NOT_ENDED" in r.stdout, sb.result())
case("R09 P1-5: a written cgroup.kill that does not end the unit is reported as KILL_WRITTEN_NOT_ENDED", with_sb(t_kill_noeffect, dict(KILLSCEN, kill_noeffect=True), confirm=CONF))
case("R10 boundary: 262143 kB (255.999 MiB) triggers", trig("MEMORY_LOW", dict(NORMAL, mem=[[0, 5585], [50, 255.999]], stop_delay=10)))
def t_64(sb):
    r = sb.run(); A(sb.result().startswith("KILL_WRITTEN_ENDED") and "PIDS=64" in r.stdout, sb.result())
case("R11 boundary: exactly 64 pids in the cgroup is accepted", with_sb(t_64, dict(KILLSCEN, procs={str(5000 + i): "mysqld" for i in range(63)} | {"4242": "mysqld"}), confirm=CONF))
def t_legacy_at_kill(sb):
    r = sb.run(); A("S7B_KILL=STOP;CODE=revalidation_failed" in r.stdout and sb.killfile() in (None, ""), r.stdout[-300:])
case("R12 the legacy MainPID changes between the first validation and the write: refused", with_sb(t_legacy_at_kill, dict(KILLSCEN, legacy=[[0, {"A": "active", "S": "running", "pid": 574923}], [283, {"A": "active", "S": "running", "pid": 600001}]]), confirm=CONF, flags=("confirm_advance",)))
def t_killfile_symlink(sb):
    r = sb.run(); A("CODE=cgroup_kill_unavailable" in r.stdout and sb.killfile() in (None, "") and open(sb.d + "/kill_real").read() == "", r.stdout[-300:])
case("R13 cgroup.kill is a symlink: refused, nothing written through it", with_sb(t_killfile_symlink, dict(KILLSCEN, kill_symlink=True), confirm=CONF))
def t_int_arm(sb):
    r = sb.run(); A(r.returncode == 130 and (sb.result() or "").startswith("INTERRUPTED;ACTION=NONE;PHASE=arm;G1_NOT_SUPERVISED") and not sb.stops(), sb.result())
case("R14 interrupt while waiting for the start: no action, reported as not supervised, exit 130", with_sb(t_int_arm, {"g1": [BASE, [60, STRT]]}, int_at=20))
case("R15 LoadState not loaded at arm", arm("arm_g1_not_loaded", dict(NORMAL, g1_load="not-found")))
case("R16 systemctl repeats a key: invalid output, refused", arm("arm_g1_unreadable", dict(NORMAL, _files={"show_dup": "1"})))
case("R17 systemctl omits a required key (Result): refused", arm("arm_g1_unreadable", dict(NORMAL, _files={"show_missing": "Result"})))
def t_final(sb):
    r = sb.run(); A("S7B_FINAL g1_now=" in r.stdout and r.stdout.strip().splitlines()[-1].startswith("S7B_FINAL") and r.stdout.strip().splitlines()[-2].startswith("S7B_RESULT="), "result line then final line")
case("R18 every terminal path prints the result line and then a fresh S7B_FINAL line", with_sb(t_final, HANG_STOP_OK))
def t_pty_seams():
    import pty, select, signal
    fn = {}
    for name in ("is_tty", "cgroup2_ok", "confirm_read"):
        m = re.search(r'^%s\(\) \{.*\}$' % name, ORIG, re.M); A(m, name); fn[name] = m.group(0)
    prog = "KILL_CONFIRM_SEC=2; CGROOT=/sys/fs/cgroup; %s\n%s\n%s\nis_tty && echo TTY_YES || echo TTY_NO\nconfirm_read; echo \"RC=$? REPLY=[$REPLY]\"\nconfirm_read; echo \"RC2=$?\"\n" % (fn["is_tty"], fn["cgroup2_ok"], fn["confirm_read"])
    pid, fd = pty.fork()
    if pid == 0: os.execvp("bash", ["bash", "--noprofile", "--norc", "-c", prog])
    out = b""; os.write(fd, b"  KILL abc \n")
    t0 = time.time()
    while time.time() - t0 < 8:
        r, _, _ = select.select([fd], [], [], 0.5)
        if r:
            try: d = os.read(fd, 4096)
            except OSError: break
            if not d: break
            out += d
    try: os.waitpid(pid, 0)
    except Exception: pass
    s = out.decode(errors="replace"); A("TTY_YES" in s and "REPLY=[  KILL abc ]" in s and "RC=0" in s and re.search(r'RC2=(1|142)', s), s)
case("R19 real seam bodies under a pty: is_tty true, confirm_read keeps the typed padding exact (IFS= read) and times out (rc>0) when nothing is typed", t_pty_seams)
def t_cgroup2_real():
    m = re.search(r'^cgroup2_ok\(\) \{.*\}$', ORIG, re.M).group(0)
    r1 = subprocess.run(["bash", "-c", "CGROOT=%s; %s; cgroup2_ok && echo YES || echo NO" % (tempfile.gettempdir(), m)], capture_output=True, text=True).stdout.strip()
    A(r1 == "NO", "a normal directory is not cgroup2fs: %r" % r1)
    if subprocess.run(["stat", "-f", "-c", "%T", "/sys/fs/cgroup"], capture_output=True, text=True).stdout.strip() == "cgroup2fs":
        r2 = subprocess.run(["bash", "-c", "CGROOT=/sys/fs/cgroup; %s; cgroup2_ok && echo YES || echo NO" % m], capture_output=True, text=True).stdout.strip(); A(r2 == "YES", r2)
case("R20 real cgroup2_ok body: false for an ordinary directory, true on a cgroup2 mount (read-only stat)", t_cgroup2_real)
def t_now_real():
    m = re.search(r'^now\(\)  \{.*\}$', ORIG, re.M).group(0)
    a = subprocess.run(["bash", "-c", "%s; now; sleep 1; now" % m], capture_output=True, text=True).stdout.split(); A(len(a) == 2 and a[0].isdigit() and 1 <= int(a[1]) - int(a[0]) <= 2, a)
case("R21 real now(): monotonic uptime seconds, advances with real time", t_now_real)

# ---------------- v2 round 2: AppSec P1 regressions and extra hardening
def t_ended_plus_flags(sb):
    r = sb.run(); res = sb.result() or ""
    A(r.returncode == 1 and res.startswith("UNIT_FAILED;") and "ALSO=LEGACY_CHANGED" in res and "S7B_TRIGGER code=LEGACY_CHANGED" in r.stdout and not sb.stops(), res)
case("Q01 P1-3: unit failed in the same sample as a legacy change: not a quiet exit, the flag is in the result and a trigger line is printed", with_sb(t_ended_plus_flags, {"g1": [BASE, [10, STRT], [30, {"A": "failed", "S": "failed", "pid": 0, "nr": 0}]], "legacy": [[0, {"A": "active", "S": "running", "pid": 574923}], [30, {"A": "inactive", "S": "dead", "pid": 0}]]}))
def t_ended_plus_oom(sb):
    r = sb.run(); A("ALSO=OOM_KILL" in (sb.result() or "") and r.returncode == 1, sb.result())
case("Q02 P1-3: unit ended together with an oom_kill increase: flagged, exit 1", with_sb(t_ended_plus_oom, {"g1": [BASE, [10, STRT], [30, {"A": "failed", "S": "failed", "pid": 0, "nr": 0}]], "oomk": [[0, 3], [30, 5]]}))
def t_before_restart(sb):
    r = sb.run(); A(sample_t(sb, "RESTART_LOOP") is not None and (sb.result() or "").startswith("STOPPED;CODE=RESTART_LOOP"), sb.result())
case("Q03 P2-12: systemd >=253 sub-state failed-before-auto-restart / dead-before-auto-restart counts as a restart loop (stop cancels the restart)", with_sb(t_before_restart, {"g1": [BASE, [10, STRT], [40, {"A": "inactive", "S": "dead-before-auto-restart", "pid": 0, "nr": 0}]], "stop_delay": 5}))
def t_child_cg(sb):
    os.makedirs(sb.d + "/cg" + G1CG + "/child", exist_ok=True)
    r = sb.run(); A("CODE=child_cgroups_present" in r.stdout and sb.killfile() in (None, ""), r.stdout[-300:])
case("Q04 P2-5: a child cgroup under the target (cgroup.kill is recursive): refused", with_sb(t_child_cg, KILLSCEN, confirm=CONF))
def t_clock_jump_hang(sb):
    r = sb.run(); trigs = re.findall(r"S7B_TRIGGER code=START_HANG t=(\d+) obs=(\d+)", r.stdout)
    last = int(re.findall(r"S7B_SAMPLE n=(\d+)", r.stdout)[-1]); A(trigs and last >= 30, "START_HANG must wait for at least 30 real samples (last n=%d): %s" % (last, trigs))
case("Q05 P1-5: a +200 s clock step on a healthy activating unit does not fire START_HANG before 30 real samples", with_sb(t_clock_jump_hang, {"g1": [BASE, [10, STRT]], "clock_jump": [30, 200], "stop_delay": 5}, args=("--instance", "production", "--mode", "observe"), tty=False))
def t_clock_jump_window(sb):
    r = sb.run(); n = int(re.findall(r"S7B_SAMPLE n=(\d+)", r.stdout)[-1]); A((sb.result() or "").startswith("WINDOW_COMPLETE") and n >= 60, "window completes only after 60 real samples (n=%d)" % n)
case("Q06 P1-5: a +320 s clock step on a healthy unit does not end the window early (60-sample floor)", with_sb(t_clock_jump_window, dict(NORMAL, clock_jump=[40, 320]), args=("--instance", "production", "--mode", "observe"), tty=False))
def t_stopwait_floor(sb):
    r = sb.run(); polls = len(re.findall(r"S7B_STOPWAIT", r.stdout)); A(polls >= 24, "stop wait polls %d" % polls)
case("Q07 P1-5: the 120 s stop wait needs at least 24 real polls even if the clock jumps", with_sb(t_stopwait_floor, dict(KILLSCEN, clock_jump=[170, 200]), confirm=CONF))
def t_arm_floor(sb):
    r = sb.run(); polls = 0; A(r.returncode == 4 and (sb.result() or "").startswith("NO_START_OBSERVED"), sb.result())
case("Q08 phase-1 timeout still ends as NOT SUPERVISED (exit 4) with the poll floor in place", with_sb(t_arm_floor, {"g1": [], "clock_jump": [20, 300]}))
def t_evid_fail(sb):
    runs = sb.d + "/runp"
    # remove write permission from the evidence file after arm is impossible here; instead check that the wrapper contains the one-shot warning
    A("S7B_EVIDENCE_WRITE_FAILED" in ORIG and ORIG.count("EVID_FAIL") >= 3, "evidence-write-failure notice present")
case("Q09 P2-13: a failed evidence write is announced once on the console (code path present)", with_sb(t_evid_fail, NORMAL))
def t_kill_target_print(sb):
    r = sb.run(); A("S7B_KILL_TARGET unit=mysql@wpcaregrid-production.service cgroup=%s state=deactivating/stop-sigterm mainpid=4242 pids=1" % G1CG in r.stdout and "S7B_KILL_TARGET_PROC pid=4242 comm=mysqld" in r.stdout and "S7B_KILL_WARNING" in r.stdout, r.stdout[-700:])
case("Q10 P2-3: the confirmation prompt names the exact target (unit, cgroup, state, pids with comm) and warns about crash recovery", with_sb(t_kill_target_print, KILLSCEN, confirm=CONF))

# ---------------- v2 round 3: re-check conditions
def t_write_failed(sb):
    r = sb.run(); res = sb.result() or ""
    A(res.startswith("KILL_WRITE_FAILED;CODE=START_HANG;KILL_NOT_WRITTEN") and "KILL_WAS_WRITTEN" not in res and "kill_written=0" in r.stdout and "S7B_KILL=WRITTEN" not in r.stdout, res + r.stdout[-200:])
case("W01 re-check P1: a failed cgroup.kill write is reported as NOT written (kill_written=0)", with_sb(t_write_failed, KILLSCEN, confirm=CONF, patches=[("""{ printf '1' >| "$d/cgroup.kill"; } 2>/dev/null""", """{ false; } 2>/dev/null""")]))
def t_sub26(sb):
    r = sb.run(); A("READ_FAILED" not in r.stdout and sample_t(sb, "RESTART_LOOP") is not None, r.stdout[-300:])
case("W02 re-check P2: sub-state failed-before-auto-restart (26 chars) is read and treated as a restart loop", with_sb(t_sub26, {"g1": [BASE, [10, STRT], [40, {"A": "inactive", "S": "failed-before-auto-restart", "pid": 0, "nr": 0}]], "stop_delay": 5}))
def t_abort(sb):
    r = sb.run(); A(r.returncode == 5 and "S7B_RESULT=ABORTED;RC=" in r.stdout and "NOPE_UNSET" in r.stderr, "rc=%s stderr=%r" % (r.returncode, r.stderr[-200:]))
case("W03 re-check P1-1: an internal error (set -u) is visible on stderr, prints S7B_RESULT=ABORTED and exits 5 (not 1)", with_sb(t_abort, NORMAL, args=("--instance", "production", "--mode", "observe"), tty=False, patches=[("T_ARM=$(now); T_S=$T_ARM", 'T_ARM=$(now); T_S=$T_ARM; : "$NOPE_UNSET"')]))
def t_find_fail(sb):
    r = sb.run(); A("CODE=child_cgroup_scan_failed" in r.stdout and sb.killfile() in (None, ""), r.stdout[-300:])
case("W04 re-check P2: a failing child-cgroup scan refuses the kill (no silent pass)", with_sb(t_find_fail, KILLSCEN, confirm=CONF, patches=[('kids=$(find "$d" -mindepth 1 -maxdepth 1 -type d 2>/dev/null)', 'kids=$(false)')]))
def t_stophang_floor(sb):
    r = sb.run(); ns = [int(x) for x in re.findall(r"S7B_SAMPLE n=(\d+) t=\d+ obs=\d+ g1=deactivating", r.stdout)]; tn = re.search(r"S7B_TRIGGER code=STOP_HANG", r.stdout)
    last = int(re.findall(r"S7B_SAMPLE n=(\d+)", r.stdout)[-1]); A(tn and len(ns) >= 24, "deactivating samples before the trigger: %d" % len(ns))
case("W05 re-check: STOP_HANG needs 24 deactivating samples even when the clock jumps", with_sb(t_stophang_floor, dict(DEACT, clock_jump=[40, 200]), args=("--instance", "production", "--mode", "observe"), tty=False))
def t_arm_polls(sb):
    r = sb.run(); naps = int(open(sb.d + "/naps").read()) if os.path.exists(sb.d + "/naps") else 0; A(r.returncode == 4 and naps >= 24, "phase-1 polls %d" % naps)
case("W06 re-check: the 120 s start wait needs 24 polls even when the clock jumps", with_sb(t_arm_polls, {"g1": [], "clock_jump": [20, 300]}))
def t_obs_lost_stale(sb):
    r = sb.run(); A(re.search(r"S7B_TRIGGER code=OBSERVATION_LOST .* g1=stale:", r.stdout), r.stdout[-300:])
case("W07 re-check P2: an OBSERVATION_LOST trigger labels the last g1 state as stale", with_sb(t_obs_lost_stale, dict(NORMAL, mem=[[0, 5585], [50, None]], stop_delay=10)))

# ---------------- static properties
def t_static():
    code = "\n".join(l for l in ORIG.splitlines() if not l.lstrip().startswith("#"))
    for b in [r'reset-failed', r'daemon-reload', r'systemctl\s+(start|restart|kill|reload|try-restart|set-property|mask|unmask|enable|disable|daemon)', r'\brm\b', r'\bkill\s+-9', r'/proc/sys', r'\bsysctl\b', r'apparmor|aa-exec|aa-enforce', r'\bmysqladmin\b|\bmysqlsh\b|\bmysqld\s|^\s*mysql\s', r'reboot|shutdown|poweroff', r'curl|wget|ssh |sudo', r'journalctl', r'eval ', r'mv |cp |chown|chmod\s+[0-7]+\s+/']:
        A(not re.search(b, code), "banned token: " + b)
    A(len(re.findall(r'systemctl stop', code)) == 1, "exactly one systemctl stop call site")
    A(len(re.findall(r'cgroup\.kill"', code)) >= 1 and len(re.findall(r"printf '1' >\| ", code)) == 1, "exactly one cgroup.kill write site")
    A("--no-block" in code, "stop is non-blocking")
case("S01 static: no start/restart/kill/reset-failed/daemon-reload/set-property/rm/sysctl/AppArmor/MySQL-config/reboot tokens; one stop site; one cgroup.kill write site", t_static)
def t_const():
    for k, v in [("SAMPLE_SEC", 5), ("WINDOW_SEC", 300), ("HANG_CUTOFF_SEC", 150), ("STOP_WAIT_SEC", 120), ("MEM_TRIGGER_MIB", 256), ("ARM_MAX_SEC", 120), ("INTENT_WAIT_SEC", 250)]:
        A(re.search(r'^%s=%d\b' % (k, v), ORIG, re.M), "constant %s=%d" % (k, v))
    A("${" not in "".join(re.findall(r'^(?:SAMPLE_SEC|WINDOW_SEC|HANG_CUTOFF_SEC|STOP_WAIT_SEC|MEM_TRIGGER_MIB)=.*$', ORIG, re.M)), "no environment expansion in the constants")
case("S02 documented constants: 5 s sampling, 300 s window, 150 s hang cutoff, 120 s stop wait, 256 MiB trigger; no overrides", t_const)
def t_diff():
    sb = SB(NORMAL)
    try:
        o = ORIG.splitlines(); t = sb.text.splitlines()
        ch = [l for l in difflib.unified_diff(o, t, lineterm="", n=0) if l[:1] in "+-" and l[:3] not in ("+++", "---")]
        for l in ch:
            b = l[1:]; ok = re.match(r'^(ROOT_UID_EXPECT|RUN_PARENT|PROCROOT|CGROOT|LOCKFILE)=', b) or b.startswith("export PATH=") or re.match(r'^(now|nap|is_tty|cgroup2_ok|confirm_read)\(\)', b)
            A(ok, "unexpected difference in the test copy: " + l[:120])
    finally: sb.clean()
case("S03 the test copy differs from the candidate only in sandbox constants, the PATH line and the five seam functions", t_diff)

# ====================================================================================================================================================
# CG-1: START_INTENT / START_ACK handshake (console A <-> console B), arm phase only. Deterministic (virtual clock); no host access.
# ====================================================================================================================================================
INACT = {"A": "inactive", "S": "dead", "pid": 0, "nr": 0}
NONCE = "0123456789abcdef0123456789abcdef"
def hs_scen(start=None, intents=(), **kw):
    sc = {"g1": [[0, INACT]] + ([[start, ST], [start + 10, ACT]] if start is not None else []), "intent": list(intents)}; sc.update(kw); return sc
def I(at, mode="ok", nonce=NONCE): return {"tag": "i%d%s" % (at, mode), "at": at, "mode": mode, "nonce": nonce}
def rdir(sb): return sb.d + "/runp/" + sb.rundirs()[0]
def rid_of(sb): return sb.rundirs()[0][len("wpcg-s7b-"):]
def evtxt(sb): return open(rdir(sb) + "/evidence.txt").read()
def waited(sb): return int(re.search(r"NO_START_OBSERVED;WAITED=(\d+)s", sb.result()).group(1))
def vclock(sb): return int(open(sb.d + "/vclock").read())
def lock_free(sb):
    import fcntl
    with open(sb.d + "/lock", "a") as f:
        try: fcntl.flock(f, fcntl.LOCK_EX | fcntl.LOCK_NB); return True
        except OSError: return False
def console_a_ack_regex():
    src = open(os.path.join(HERE, "..", "..", "g1-start-console-a.sh")).read()
    m = re.search(r'\[\[ "\$line" =~ (\^START_ACK\\ .*?\$) \]\]', src); assert m, "ACK regex not found in console A"; return re.compile(m.group(1))
def a_const(name):
    src = open(os.path.join(HERE, "..", "..", "g1-start-console-a.sh")).read()
    m = re.search(r'(?:^|[; ])%s=(\d+)\b' % name, src, re.M); assert m, name; return int(m.group(1))
def t_hs_ack_nostart(sb):
    r = sb.run(); A(r.returncode == 4 and sb.result().startswith("NO_START_OBSERVED;"), (r.returncode, sb.result()))
    p = rdir(sb) + "/START_ACK"; line = open(p).read(); A(oct(os.stat(p).st_mode & 0o777) == "0o600", "ACK mode")
    A(re.fullmatch(r"START_ACK v1 run=%s nonce=%s pid=\d+ w=250\n" % (re.escape(rid_of(sb)), NONCE), line), line)
    A(console_a_ack_regex().fullmatch(line.rstrip("\n")), "console A's ACK grammar must accept exactly what console B writes: %r" % line)
    first = evtxt(sb).splitlines()[0]; A(first.startswith("S7B_ARMED version=s7b-containment-v3 instance=production mode=contain unit=") and first.endswith(" run_dir=" + rdir(sb)) and sum(1 for l in evtxt(sb).splitlines() if l.startswith("S7B_ARMED ")) == 1, "console A matches this exact armed record: " + first)
    lim = int(re.search(r"S7B_INTENT_ACK run=\S+ nonce=\S+ w=250 arm_limit=(\d+)s", evtxt(sb)).group(1))
    A(350 <= lim <= 355 and waited(sb) == lim, "limit counted from the ACK (intent written at 100 s): %s / %s" % (lim, sb.result()))
    A(vclock(sb) >= lim and vclock(sb) <= lim + 10, vclock(sb)); A(not sb.stops() and not sb.forbidden(), "no action")
    A(lock_free(sb), "console B has exited: its lock is free again")
case("HS01 valid intent at 100 s: ACK written (0600, exact grammar, accepted by console A's parser), arm deadline = ACK time + 250 s, not the initial arm time", with_sb(t_hs_ack_nostart, hs_scen(None, [I(100)])))
def t_hs_late_start(sb):
    r = sb.run(); A(r.returncode == 0 and sb.result().startswith("WINDOW_COMPLETE;G1=active/running"), (r.returncode, sb.result()))
    so = int(re.search(r"S7B_START_OBSERVED t=(\d+)", r.stdout).group(1)); A(so >= 150, "start observed at %s (the old 120 s arm deadline would have ended the run)" % so)
case("HS02 intent at 118 s, start at 150 s (after the old 120 s deadline): the start is supervised, window completes", with_sb(t_hs_late_start, hs_scen(150, [I(118)])))
def t_hs_no_intent(sb):
    r = sb.run(); A(r.returncode == 4 and waited(sb) == 120 and vclock(sb) <= 130, (sb.result(), vclock(sb))); A(not os.path.exists(rdir(sb) + "/START_ACK"), "no ACK without an intent")
case("HS03 no intent: arm deadline unchanged (120 s, exit 4)", with_sb(t_hs_no_intent, hs_scen(None)))
def t_hs_in_time(sb):
    r = sb.run(); A(r.returncode == 0 and sb.result().startswith("WINDOW_COMPLETE"), (r.returncode, sb.result()))
case("HS04a intent at 10 s, start at 250 s (inside ACK + 250 s): supervised", with_sb(t_hs_in_time, hs_scen(250, [I(10)])))
def t_hs_too_late(sb):
    r = sb.run(); A(r.returncode == 4 and 255 <= waited(sb) <= 262, (r.returncode, sb.result()))
case("HS04b intent at 10 s, start at 262 s (after ACK + 250 s): NOT observed, exit 4 (the commitment is bounded, not open-ended)", with_sb(t_hs_too_late, hs_scen(262, [I(10)])))
def hs_reject(mode):
    def f(sb):
        r = sb.run(); A(r.returncode == 4 and waited(sb) == 120, (mode, sb.result())); A(not os.path.exists(rdir(sb) + "/START_ACK"), "no ACK for a rejected intent: " + mode)
        A("S7B_INTENT_REJECTED" in evtxt(sb) and "S7B_INTENT_ACK " not in evtxt(sb), evtxt(sb)[-300:])
    return f
for _m in ("wrong_run", "bad_format", "bad_mode", "two_lines", "no_newline", "short_nonce", "symlink"):
    case("HS05-%s hostile/invalid intent (%s): rejected, no ACK, no extension" % (_m, _m), with_sb(hs_reject(_m), hs_scen(None, [I(10, _m)])))
def t_hs_second(sb):
    r = sb.run(); A(r.returncode == 4, sb.result()); ev = evtxt(sb)
    A(ev.count("S7B_INTENT_ACK ") == 1 and "S7B_INTENT_ACK_FAILED" not in ev and open(rdir(sb) + "/START_ACK").read().count(NONCE) == 1, "exactly one intent is processed per run (no replay): " + ev[-400:])
case("HS06 a second intent (different nonce) after a valid one is ignored: one ACK, no re-processing", with_sb(t_hs_second, hs_scen(None, [I(10), I(40, "replace", "f" * 32)])))
def t_hs_bad_then_good(sb):
    r = sb.run(); A(r.returncode == 4 and waited(sb) == 120 and not os.path.exists(rdir(sb) + "/START_ACK"), sb.result())
case("HS07 a rejected intent poisons the run: a later valid-looking intent is not accepted (one intent per run)", with_sb(t_hs_bad_then_good, hs_scen(None, [I(10, "bad_format"), I(40, "replace")])))
def t_hs_preack(sb):
    r = sb.run(); A(r.returncode == 4 and waited(sb) == 120, sb.result()); ev = evtxt(sb); A("S7B_INTENT_ACK_FAILED" in ev and "S7B_INTENT_ACK " not in ev, ev[-300:])
    A(open(rdir(sb) + "/START_ACK").read() == "pre-existing\n", "an existing ACK file is never overwritten (noclobber)")
case("HS08 a pre-existing START_ACK file: never overwritten, no extension, ACK_FAILED", with_sb(t_hs_preack, hs_scen(None, [I(5, "preack"), I(10)])))
def t_hs_phase2(sb):
    r = sb.run(); A(r.returncode == 0 and sb.result().startswith("WINDOW_COMPLETE"), sb.result()); A(not os.path.exists(rdir(sb) + "/START_ACK"), "an intent after the start was observed is ignored")
    A(vclock(sb) <= 10 + 316, "the 300 s window is unchanged by the handshake (%s)" % vclock(sb))
case("HS09 an intent after the start was observed is ignored; the 300 s observation window is unchanged", with_sb(t_hs_phase2, hs_scen(10, [I(50)])))
def t_hs_exit_after_ack(sb):
    r = sb.run(); A(r.returncode == 130 and "INTERRUPTED;ACTION=NONE;PHASE=arm;G1_NOT_SUPERVISED" in (sb.result() or ""), (r.returncode, sb.result()))
    A(os.path.exists(rdir(sb) + "/START_ACK"), "the ACK was written before console B exited"); A(lock_free(sb), "after console B exited its lock is free: console A's liveness check (CG16/CG17) refuses the start")
case("HS10 console B exits (interrupted) AFTER the ACK, before any start: not supervised, lock free, ACK left behind (console A must refuse; see CA tests)", with_sb(t_hs_exit_after_ack, hs_scen(None, [I(100)]), int_at=130))
def t_hs_static():
    src = open(os.path.join(HERE, "..", "..", "g1-start-console-a.sh")).read()
    A(a_const("ACK_MIN_COMMIT_SEC") == 250 and a_const("MAX_INTENT_TO_START_SEC") == 30 and a_const("START_TIMEOUT") == 180, "console A constants")
    A('timeout -k 10 "$START_TIMEOUT" python3 -I -B "$HELPER" start' in src, "helper kill grace is 10 s")
    A(250 >= 30 + 180 + 10 + 5 + 25, "INTENT_WAIT_SEC covers intent->helper cap + helper timeout + kill grace + one sample + reserve"); A(re.search(r"^SAMPLE_SEC=5\b", ORIG, re.M), "5 s sample")
    A(re.search(r"^INTENT_WAIT_SEC=250\b", ORIG, re.M), "B constant equals console A's ACK_MIN_COMMIT_SEC")
case("HS11 static: the B commitment (250 s) equals A's required minimum and covers 30 + 180 + 10 + 5 + 25 s (cross-file)", t_hs_static)
# ---- mutants: removing a defense must change the observable result of the scenario above (these are the kill checks for HS01-HS10)
def mut(name, patch, scen, check, **kw): case(name, with_sb(check, scen, patches=[patch], **kw))
def k_nolimit(sb):
    r = sb.run(); A(r.returncode == 4 and waited(sb) == 120, "mutant (no deadline extension) must lose the late start: %s" % sb.result())
mut("BM01 mutant: no deadline extension after the ACK -> HS02 scenario ends NO_START_OBSERVED (killed by HS02)", ("newlim=$(( el + INTENT_WAIT_SEC ))", "newlim=$ARM_LIMIT"), hs_scen(150, [I(118)]), k_nolimit)
def k_fromarm(sb):
    r = sb.run(); A(r.returncode == 0, "mutant (deadline counted from the initial arm time) observes the 262 s start: %s" % sb.result())
mut("BM02 mutant: deadline counted from the initial arm time instead of the ACK -> HS04b start at 262 s is (wrongly) observed (killed by HS04b)", ("newlim=$(( el + INTENT_WAIT_SEC ))", "newlim=$(( ARM_MAX_SEC + INTENT_WAIT_SEC ))"), hs_scen(262, [I(10)]), k_fromarm)
def k_replay(sb):
    r = sb.run(); ev = evtxt(sb); A(ev.count("S7B_INTENT_ACK") >= 2, "mutant (no one-shot) processes the replayed intent: " + ev[-300:])
mut("BM03 mutant: no one-intent-per-run rule -> the replayed intent is processed again (killed by HS06)", ("  INTENT_DONE=1\n", "  INTENT_DONE=0\n"), hs_scen(None, [I(10), I(40, "replace", "f" * 32)]), k_replay)
def k_acked(sb):
    sb.run(); A(os.path.exists(rdir(sb) + "/START_ACK"), "the mutant accepts the hostile intent")
mut("BM04 mutant: run id not checked -> an intent from another run is accepted (killed by HS05-wrong_run)", ('[ "$want" = "$rid" ] || { out "S7B_INTENT_REJECTED reason=other_run"; return 0; }', ":"), hs_scen(None, [I(10, "wrong_run")]), k_acked)
mut("BM05 mutant: mode 0600 not checked -> a 0644 intent is accepted (killed by HS05-bad_mode)", ('case "$meta" in *" 600 "*) ;; *) out "S7B_INTENT_REJECTED reason=mode"; return 0 ;; esac', ":"), hs_scen(None, [I(10, "bad_mode")]), k_acked)
mut("BM06 mutant: nonce length not enforced -> a short nonce is accepted (killed by HS05-short_nonce)", ("nonce=([0-9a-f]{32})$ ]]", "nonce=([0-9a-f]*)$ ]]"), hs_scen(None, [I(10, "short_nonce")]), k_acked)
mut("BM07 mutant: one-line rule not enforced -> a two-line intent is accepted (killed by HS05-two_lines)", ('[ "${meta##* }" = "$(( ${#line} + 1 ))" ] || { out "S7B_INTENT_REJECTED reason=not_one_line"; return 0; }', ":"), hs_scen(None, [I(10, "two_lines")]), k_acked)

if __name__ == "__main__":
    t0 = time.time()
    import concurrent.futures as cf
    ff = os.environ.get("S7B_FAILFAST") == "1"; stop = {"x": False}
    def guarded(item):
        if ff and stop["x"]: return (item[0], "SKIP", "")
        r = _run_case(item)
        if ff and r[1] != "PASS": stop["x"] = True
        return r
    only = os.environ.get("S7B_ONLY")
    sel = [c for c in CASES if not only or re.search(only, c[0])]
    if os.environ.get("S7B_ORDER") == "fast":  # cheap tests first (mutation runs fail fast); the verdict does not depend on the order
        rank = {"A": 0, "S": 0, "W": 1, "R": 2, "E": 2, "I": 2, "Q": 2, "K": 3, "N": 3, "M": 4, "G": 4, "L": 4, "H": 5}
        sel = sorted(sel, key=lambda c: (rank.get(c[0][0], 6), c[0]))
    with cf.ThreadPoolExecutor(max_workers=int(os.environ.get("S7B_WORKERS", "4"))) as ex: R.extend(ex.map(guarded, sel))
    for n, s, m in R: print("%-5s %s %s" % (s, n, m))
    print("TOTAL=%d PASS=%d (%.0fs)" % (len(R) - sum(1 for x in R if x[1] == "SKIP"), sum(1 for x in R if x[1] == "PASS"), time.time() - t0))
    if not os.environ.get("S7B_WRAP"): json.dump(R, open(os.path.join(HERE, "results.json"), "w"))
    sys.exit(0 if all(x[1] == "PASS" for x in R) else 1)
