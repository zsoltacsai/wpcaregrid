#!/usr/bin/env python3
"""Local tests for the GO-20.39 production-track tools. Sandboxed (temp dirs), unprivileged, stub systemctl, no host access, no real stop/start/kill."""
import hashlib, os, re, shutil, stat, subprocess, sys, tempfile, getpass, time
HERE = os.path.dirname(os.path.abspath(__file__)); OPS = os.path.dirname(HERE)
UID = os.getuid()
def read(name): return open(os.path.join(OPS, name), encoding="utf-8").read()
def sha(p): return hashlib.sha256(open(p, "rb").read()).hexdigest()
RES = []
def case(name, fn):
    try: fn(); RES.append((name, "PASS", ""))
    except AssertionError as e: RES.append((name, "FAIL", str(e)[:300]))
    except Exception as e: RES.append((name, "ERROR", repr(e)[:300]))
def A(c, m="assertion failed"): assert c, m

STUB = r'''#!/usr/bin/env bash
SB="$SBX"; U="$SB/units"
case "$1" in
  show)
    shift; prop=""; unit=""
    while [ $# -gt 0 ]; do case "$1" in -p) prop="$2"; shift 2 ;; --value) shift ;; --) unit="$2"; shift 2 ;; *) shift ;; esac; done
    [ -f "$SB/show_fail_$unit" ] && exit 1
    f="$U/$unit.$prop"; [ -f "$f" ] && cat "$f" || echo ""; exit 0 ;;
  stop)
    shift; [ "$1" = "--" ] && shift; unit="$1"; echo "stop $unit" >> "$SB/calls.log"
    [ -f "$SB/stop_fail" ] && exit 1
    echo inactive > "$U/$unit.ActiveState"; echo "" > "$U/$unit.NextElapseUSecRealtime"; exit 0 ;;
  start)
    shift; [ "$1" = "--" ] && shift; unit="$1"; echo "start $unit" >> "$SB/calls.log"
    [ -f "$SB/start_fail" ] && exit 1
    echo active > "$U/$unit.ActiveState"; [ -f "$SB/no_next" ] || echo "Fri 2026-11-20 12:09:00 CET" > "$U/$unit.NextElapseUSecRealtime"
    [ -f "$SB/flip_enable" ] && echo disabled > "$U/$unit.UnitFileState"; exit 0 ;;
  *) echo "FORBIDDEN $*" >> "$SB/calls.log"; exit 9 ;;
esac
'''
class Box:
    def __init__(self):
        self.d = tempfile.mkdtemp(prefix="g2039-"); os.chmod(self.d, 0o755)
        for p in ("bin", "units", "out"): os.makedirs(os.path.join(self.d, p))
        open(self.d + "/bin/systemctl", "w").write(STUB); os.chmod(self.d + "/bin/systemctl", 0o755)
    def p(self, *a): return os.path.join(self.d, *a)
    def unit(self, name, **kw):
        for k, v in kw.items(): open(self.p("units", "%s.%s" % (name, k)), "w").write(v + "\n")
    def script(self, name, subs=(), patches=()):
        t = read(name)
        for pat, repl in subs:
            n = len(re.findall(pat, t, re.M)); assert n == 1, (pat, n); t = re.sub(pat, lambda m: repl, t, flags=re.M)
        t = t.replace("export PATH=/usr/sbin:/usr/bin:/sbin:/bin", "export PATH=%s:/usr/sbin:/usr/bin:/sbin:/bin" % self.p("bin"), 1)
        for a, b in patches:
            assert t.count(a) == 1, "VACUOUS patch: " + a[:70]; t = t.replace(a, b)
        path = self.p("t_" + name); open(path, "w").write(t); os.chmod(path, 0o700); return path
    def run(self, path, *args, env_extra=None):
        env = dict(os.environ, SBX=self.d); env.update(env_extra or {})
        return subprocess.run(["bash", "--noprofile", "--norc", path, *args], capture_output=True, text=True, env=env, timeout=120)
    def calls(self): return open(self.p("calls.log")).read().splitlines() if os.path.exists(self.p("calls.log")) else []
    def clean(self): shutil.rmtree(self.d, ignore_errors=True)
def boxed(fn):
    def run():
        b = Box()
        try: fn(b)
        finally: b.clean()
    return run
def tree_hash(root):
    h = hashlib.sha256()
    for dp, dn, fn in sorted(os.walk(root)):
        dn.sort()
        for f in sorted(fn):
            p = os.path.join(dp, f); h.update(p.encode()); h.update(open(p, "rb").read())
    return h.hexdigest()

# ======================================================= cold archive + restore fixtures
G1 = "mysql@wpcaregrid-production.service"; G1R = "mysql@wpcaregrid-production-restore.service"
def fixture(b):
    """fake host tree under the sandbox: two datadirs, configs, logs, apparmor files, helper files"""
    r = b.p("root"); 
    dd = {n: r + "/var/lib/mysql-wpcaregrid-" + n for n in ("production", "production-restore")}
    for n, d in dd.items():
        os.makedirs(d + "/mysql", exist_ok=True); os.makedirs(d + "/#innodb_redo", exist_ok=True)
        open(d + "/ibdata1", "wb").write(os.urandom(40000) + n.encode()); open(d + "/mysql/mysql.ibd", "wb").write(os.urandom(9000)); open(d + "/#innodb_redo/#ib_redo5", "wb").write(b"redo" * 300)
        os.chmod(d, 0o750)
    files = {
        "/etc/mysql/mysql.conf.d/zz-wpcaregrid-production.cnf": "[mysqld@wpcaregrid-production]\nport=3308\n", "/etc/mysql/mysql.conf.d/zz-wpcaregrid-production-restore.cnf": "[mysqld@wpcaregrid-production-restore]\nport=3309\n",
        "/etc/systemd/system/mysql@wpcaregrid-production.service.d/90-wpcaregrid-g1.conf": "[Service]\n", "/etc/systemd/system/mysql@wpcaregrid-production-restore.service.d/90-wpcaregrid-g1.conf": "[Service]\n",
        "/var/log/mysql/wpcaregrid-production.log": "init log production\n" * 20, "/var/log/mysql/wpcaregrid-production-restore.log": "init log restore\n" * 10,
        "/usr/local/libexec/wpcaregrid-g1-mysql.py": "#helper\n", "/usr/local/libexec/production_g1_mysql_contract.py": "#contract\n", "/usr/local/libexec/production_g1_mysql_manifest.json": "{}\n",
        "/etc/apparmor.d/wpcaregrid-mysql-production": "profile p {}\n", "/etc/apparmor.d/wpcaregrid-mysql-production-restore": "profile r {}\n"}
    for p, c in files.items():
        os.makedirs(r + os.path.dirname(p), exist_ok=True); open(r + p, "w").write(c)
    os.makedirs(r + "/run/mysqld"); os.makedirs(b.p("out", "root"))
    b.unit(G1, ActiveState="inactive"); b.unit(G1R, ActiveState="inactive")
    return r, dd
def arch_script(b, r, **kw):
    subs = [(r'^ROOT_UID_EXPECT="0"$', 'ROOT_UID_EXPECT="%d"' % UID), (r'^OUT_PARENT="/root"$', 'OUT_PARENT="%s"' % b.p("out", "root")),
            (r'^DATADIRS=.*$', 'DATADIRS="%s/var/lib/mysql-wpcaregrid-production %s/var/lib/mysql-wpcaregrid-production-restore"' % (r, r)),
            (r'^CONFIG_FILES=.*$', 'CONFIG_FILES="%s"' % " ".join(r + x for x in ["/etc/mysql/mysql.conf.d/zz-wpcaregrid-production.cnf", "/etc/mysql/mysql.conf.d/zz-wpcaregrid-production-restore.cnf", "/etc/systemd/system/mysql@wpcaregrid-production.service.d/90-wpcaregrid-g1.conf", "/etc/systemd/system/mysql@wpcaregrid-production-restore.service.d/90-wpcaregrid-g1.conf", "/var/log/mysql/wpcaregrid-production.log", "/var/log/mysql/wpcaregrid-production-restore.log", "/usr/local/libexec/wpcaregrid-g1-mysql.py", "/usr/local/libexec/production_g1_mysql_contract.py", "/usr/local/libexec/production_g1_mysql_manifest.json"])),
            (r'^CONFIG_GLOBS=.*$', 'CONFIG_GLOBS="%s/etc/apparmor.d/wpcaregrid-mysql-production*"' % r), (r'^MARKER_DIR=.*$', 'MARKER_DIR="%s/run/mysqld"' % r)]
    return b.script("g1-cold-archive.sh", subs, kw.get("patches", ()))
def make_archive(b):
    r, dd = fixture(b); s = arch_script(b, r); x = b.run(s, "create")
    A(x.returncode == 0 and x.stdout.startswith("G1_ARCHIVE=PASS"), x.stdout + x.stderr)
    d = re.search(r"DIR=([^;]+)", x.stdout).group(1); return r, dd, s, d, x
def t_arch_create(b):
    r, dd, s, d, x = make_archive(b)
    A(oct(os.stat(d).st_mode & 0o777) == "0o700" and all(oct(os.stat(os.path.join(d, f)).st_mode & 0o777) == "0o400" for f in os.listdir(d)), "dir 0700, files 0400")
    A(set(os.listdir(d)) == {"datadirs.tar", "config.tar", "FILES.sha256", "TARBALLS.sha256", "META.txt"}, os.listdir(d))
    v = b.run(s, "verify", d); A(v.stdout.startswith("G1_ARCHIVE_VERIFY=PASS"), v.stdout)
    v = b.run(s, "verify", d, "--deep"); A("G1_ARCHIVE_DEEP=PASS" in v.stdout and v.returncode == 0, v.stdout)
    A("proven restore" in open(d + "/META.txt").read() or "not a proven restore" in open(d + "/META.txt").read(), "meta note")
case("AR01 cold archive: PASS, dir 0700/files 0400, exact file set, verify and deep verify PASS", boxed(t_arch_create))
def t_arch_sources_untouched(b):
    r, dd = fixture(b); before = tree_hash(r); s = arch_script(b, r); x = b.run(s, "create"); A(x.returncode == 0, x.stdout)
    A(tree_hash(r) == before and not b.calls(), "sources unchanged, no systemctl action"); A(all(c.startswith("show") or True for c in b.calls()), "")
case("AR02 sources are byte-identical after archiving and no unit is touched", boxed(t_arch_sources_untouched))
def stop_case(name, code, mut=None, patches=(), args=("create",)):
    def f(b):
        r, dd = fixture(b)
        if mut: mut(b, r, dd)
        s = arch_script(b, r, patches=patches); x = b.run(s, *args)
        A(x.returncode == 1 and ("G1_ARCHIVE=STOP;CODE=%s" % code) in x.stdout, x.stdout + x.stderr)
        A(not any(c.startswith(("stop", "start", "FORBIDDEN")) for c in b.calls()), "no unit action")
    case(name, boxed(f))
stop_case("AR03 production unit active -> STOP", "prod_unit_not_inactive", lambda b, r, d: b.unit(G1, ActiveState="active"))
stop_case("AR04 restore unit activating -> STOP", "restore_unit_not_inactive", lambda b, r, d: b.unit(G1R, ActiveState="activating"))
stop_case("AR05 unit state unreadable -> STOP (fail-closed)", "prod_unit_not_inactive", lambda b, r, d: open(b.p("show_fail_" + G1), "w").close())
stop_case("AR06 runtime marker present -> STOP", "runtime_marker_present", lambda b, r, d: open(r + "/run/mysqld/wpcaregrid-production.pid", "w").close())
stop_case("AR07 datadir is a symlink -> STOP", "datadir_invalid", lambda b, r, d: (shutil.move(d["production"], d["production"] + "-real"), os.symlink(d["production"] + "-real", d["production"])))
stop_case("AR08 configuration file missing -> STOP", "config_file_missing_or_link", lambda b, r, d: os.unlink(r + "/usr/local/libexec/wpcaregrid-g1-mysql.py"))
stop_case("AR09 AppArmor profiles missing -> STOP", "apparmor_profiles_missing", lambda b, r, d: [os.unlink(r + "/etc/apparmor.d/" + n) for n in os.listdir(r + "/etc/apparmor.d")])
stop_case("AR10 not enough free space -> STOP", "not_enough_space", patches=[("MIN_FREE_FACTOR=2", "MIN_FREE_FACTOR=999999999")])
stop_case("AR11 a source file changes while copying -> STOP", "source_changed_during_copy", patches=[("  after=$(listing_hash)", '  echo x >> "$(for d in $DATADIRS; do echo $d/ibdata1; done | head -n 1)"\n  after=$(listing_hash)')])
stop_case("AR12 usage: unknown sub-command", "usage", args=("delete",))
stop_case("AR13 not root", "not_root", patches=[('ROOT_UID_EXPECT="%d"' % UID, 'ROOT_UID_EXPECT="0"')]) if UID != 0 else None
def t_arch_tamper(kind):
    def f(b):
        r, dd, s, d, x = make_archive(b)
        os.chmod(d, 0o700)
        if kind == "tar": os.chmod(d + "/datadirs.tar", 0o600); open(d + "/datadirs.tar", "r+b").write(b"X")
        if kind == "manifest": os.chmod(d + "/FILES.sha256", 0o600); open(d + "/FILES.sha256", "a").write("0" * 64 + "  etc/none\n")
        if kind == "mode": os.chmod(d, 0o755)
        v = b.run(s, "verify", d); A(v.returncode == 1 and "G1_ARCHIVE=STOP" in v.stdout, v.stdout)
    return f
case("AR14 verify detects a modified datadirs.tar", boxed(t_arch_tamper("tar")))
case("AR15 verify detects a modified manifest", boxed(t_arch_tamper("manifest")))
case("AR16 verify refuses a directory with the wrong mode", boxed(t_arch_tamper("mode")))
# ------------------------------------------------------- restore
def rest_script(b, r, subs_extra=()):
    subs = [(r'^ROOT_UID_EXPECT="0"$', 'ROOT_UID_EXPECT="%d"' % UID), (r'^MARKER_DIR=.*$', 'MARKER_DIR="%s/run/mysqld"' % r), (r'^LOG_DIR=.*$', 'LOG_DIR="%s/var/log/mysql"' % r)]
    t = b.script("g1-restore-datadir.sh", subs)
    txt = open(t).read().replace('datadir="/var/lib/mysql-$name"', 'datadir="%s/var/lib/mysql-$name"' % r); open(t, "w").write(txt); return t
def aid(d): return re.search(r"ARCHIVE_ID=(.+)", open(d + "/META.txt").read()).group(1)
def t_restore_ok(b):
    r, dd, s, d, x = make_archive(b); rs = rest_script(b, r); other_before = tree_hash(dd["production-restore"])
    # simulate a failed start: files changed, new files, log appended
    open(dd["production"] + "/ibdata1", "ab").write(b"POST-START-WRITE"); open(dd["production"] + "/new-file", "w").write("x"); open(r + "/var/log/mysql/wpcaregrid-production.log", "a").write("failed start lines\n")
    x2 = b.run(rs, "--instance", "production", "--archive", d, "--confirm", "RESTORE production " + aid(d)); A(x2.returncode == 0 and x2.stdout.startswith("G1_RESTORE=PASS"), x2.stdout + x2.stderr)
    manifest = [l for l in open(d + "/FILES.sha256") if "var/lib/mysql-wpcaregrid-production/" in l or l.rstrip().endswith("var/log/mysql/wpcaregrid-production.log")]
    A(len(manifest) >= 4, "manifest lines for the instance: %d" % len(manifest))
    for l in manifest:
        h, p = l.rstrip("\n").split("  ", 1); A(sha(os.path.join("/", p)) == h, "restored file differs: " + p)
    A(not os.path.exists(dd["production"] + "/new-file"), "post-start file gone from the restored tree")
    asides = [n for n in os.listdir(r + "/var/lib") if ".pre-restore-" in n]; A(len(asides) == 1 and os.path.exists(r + "/var/lib/" + asides[0] + "/new-file"), "the failed-start datadir is kept aside, not deleted")
    la = [n for n in os.listdir(r + "/var/log/mysql") if ".pre-restore-" in n]; A(len(la) == 1 and "failed start lines" in open(r + "/var/log/mysql/" + la[0]).read(), "the failed-start log is kept aside")
    A(tree_hash(dd["production-restore"]) == other_before, "the other instance is untouched"); A(not any(c.startswith(("start", "stop")) for c in b.calls()), "no unit action")
case("RE01 restore: reverts datadir and log to the archive, keeps the failed-start state aside, other instance untouched, no unit action", boxed(t_restore_ok))
def rstop(name, code, mut=None, args=None):
    def f(b):
        r, dd, s, d, x = make_archive(b); rs = rest_script(b, r)
        if mut: mut(b, r, dd, d)
        a = args(d) if args else ("--instance", "production", "--archive", d, "--confirm", "RESTORE production " + aid(d))
        before = tree_hash(r); y = b.run(rs, *a)
        A(y.returncode == 1 and ("G1_RESTORE=STOP;CODE=%s" % code) in y.stdout, y.stdout + y.stderr)
        if code not in ("rename_log_failed",): A(tree_hash(r) == before, "a refused restore changes nothing")
    case(name, boxed(f))
rstop("RE02 wrong confirmation phrase", "confirmation_mismatch", args=lambda d: ("--instance", "production", "--archive", d, "--confirm", "RESTORE restore " + aid(d)))
rstop("RE03 unit active", "unit_not_inactive", lambda b, r, dd, d: b.unit(G1, ActiveState="active"))
rstop("RE04 runtime marker present", "runtime_marker_present", lambda b, r, dd, d: open(r + "/run/mysqld/wpcaregrid-production.sock", "w").close())
rstop("RE05 archive modified", "archive_hash_mismatch", lambda b, r, dd, d: (os.chmod(d + "/config.tar", 0o600), open(d + "/config.tar", "r+b").write(b"Z")))
rstop("RE06 usage: missing argument", "usage", args=lambda d: ("--instance", "production", "--archive", d))
rstop("RE07 usage: unknown instance", "usage", args=lambda d: ("--instance", "legacy", "--archive", d, "--confirm", "RESTORE legacy " + aid(d)))
rstop("RE08 archive path relative", "archive_not_absolute", args=lambda d: ("--instance", "production", "--archive", os.path.relpath(d), "--confirm", "RESTORE production " + aid(d)))
rstop("RE09 datadir is a symlink", "datadir_invalid", lambda b, r, dd, d: (shutil.move(dd["production"], dd["production"] + "-real"), os.symlink(dd["production"] + "-real", dd["production"])))
def t_restore_restore_instance(b):
    r, dd, s, d, x = make_archive(b); rs = rest_script(b, r); prod_before = tree_hash(dd["production"])
    open(dd["production-restore"] + "/ibdata1", "ab").write(b"W")
    y = b.run(rs, "--instance", "restore", "--archive", d, "--confirm", "RESTORE restore " + aid(d)); A(y.returncode == 0, y.stdout)
    A(tree_hash(dd["production"]) == prod_before, "production datadir untouched when restoring the restore instance")
case("RE10 restoring the restore instance leaves the production instance untouched", boxed(t_restore_restore_instance))
# ======================================================= T8 timers
T1, T2, S1, S2 = "phpsessionclean.timer", "wpcg-staging-next-scheduler.timer", "phpsessionclean.service", "wpcg-staging-next-scheduler.service"
def t8(b, patches=()):
    for t in (T1, T2): b.unit(t, ActiveState="active", UnitFileState="enabled", NextElapseUSecRealtime="Fri 2026-11-20 12:07:00 CET")
    for s in (S1, S2): b.unit(s, ActiveState="inactive")
    subs = [(r'^ROOT_UID_EXPECT="0"$', 'ROOT_UID_EXPECT="%d"' % UID), (r'^STATE_FILE=.*$', 'STATE_FILE="%s"' % b.p("paused"))]
    return b.script("t8-timers.sh", subs, [("    sleep 5\n", "    :\n")] + list(patches))
def t_t8_cycle(b):
    s = t8(b); x = b.run(s, "pause"); A(x.returncode == 0 and "T8_TIMERS=PAUSED" in x.stdout and "ENABLEMENT=unchanged" in x.stdout, x.stdout)
    A(b.calls() == ["stop " + T1, "stop " + T2], b.calls()); A(os.path.exists(b.p("paused")), "marker")
    st = b.run(s, "status"); A("active=inactive" in st.stdout and "enabled=enabled" in st.stdout and "T8_PAUSED_MARKER=present" in st.stdout, st.stdout)
    y = b.run(s, "restore"); A(y.returncode == 0 and "T8_TIMERS=RESTORED" in y.stdout, y.stdout)
    A(b.calls()[-2:] == ["start " + T1, "start " + T2], b.calls()); A(not any(c.startswith("FORBIDDEN") for c in b.calls()), "only stop/start of the two timers")
case("TM01 pause then restore: stops and starts exactly the two timers, enablement untouched", boxed(t_t8_cycle))
def tstop(name, code, mut, cmd="pause", pre=None):
    def f(b):
        s = t8(b)
        if pre: b.run(s, *pre)
        mut(b); x = b.run(s, cmd); A(x.returncode == 1 and ("T8_TIMERS=STOP;CODE=%s" % code) in x.stdout, x.stdout + x.stderr)
    case(name, boxed(f))
tstop("TM02 pause refused when a timer is not active", "timer_not_active_before", lambda b: b.unit(T1, ActiveState="inactive"))
tstop("TM03 pause refused when a timer is not enabled", "timer_not_enabled", lambda b: b.unit(T2, UnitFileState="disabled"))
tstop("TM04 second pause refused (marker present)", "already_paused", lambda b: None, pre=("pause",))
tstop("TM05 restore refused when this tool did not pause", "not_paused_by_this_tool", lambda b: None, cmd="restore")
tstop("TM06 stop failure -> STOP", "stop_failed", lambda b: open(b.p("stop_fail"), "w").close())
def t_service_busy(b):
    s = t8(b); b.unit(S2, ActiveState="active"); x = b.run(s, "pause"); A(x.returncode == 1 and "CODE=service_still_active" in x.stdout, x.stdout)
case("TM07 a service still running after the stop blocks the window (STOP)", boxed(t_service_busy))
def t_restore_fail(b):
    s = t8(b); b.run(s, "pause"); open(b.p("start_fail"), "w").close(); x = b.run(s, "restore"); A(x.returncode == 1 and "CODE=start_failed" in x.stdout, x.stdout)
case("TM08 restore start failure is reported (STOP), marker kept", boxed(t_restore_fail))
def t_restore_no_next(b):
    s = t8(b); b.run(s, "pause"); open(b.p("no_next"), "w").close(); x = b.run(s, "restore"); A(x.returncode == 1 and "CODE=no_next_elapse" in x.stdout, x.stdout)
case("TM09 restore verifies a next elapse exists", boxed(t_restore_no_next))
def t_restore_enablement(b):
    s = t8(b); b.run(s, "pause"); open(b.p("flip_enable"), "w").close(); x = b.run(s, "restore"); A(x.returncode == 1 and "CODE=enablement_changed" in x.stdout, x.stdout)
case("TM10 restore detects a changed enablement", boxed(t_restore_enablement))
def t_t8_static():
    c = "\n".join(l for l in read("t8-timers.sh").splitlines() if not l.lstrip().startswith("#"))
    for bad in (r"systemctl\s+(disable|mask|enable|edit|daemon-reload|kill|reset-failed|reboot)", r"\brm\b", r"/proc/sys", r"set-property"): A(not re.search(bad, c), "banned: " + bad)
case("TM11 static: no disable/mask/enable/edit/daemon-reload/kill/rm/set-property in the T8 tool", t8_static := t_t8_static)
# ======================================================= PKG-STATE
def pkg(b, procs=None, locks="", unit_a="inactive", unit_b="inactive", lockfiles=()):
    proc = b.p("proc"); os.makedirs(proc)
    for pid, (comm, cmd, st) in (procs or {}).items():
        d = os.path.join(proc, str(pid)); os.makedirs(d); open(d + "/comm", "w").write(comm + "\n")
        if cmd is not None: open(d + "/cmdline", "wb").write(cmd.replace(" ", "\0").encode() + b"\0")
        open(d + "/stat", "w").write("%s (%s) %s 1 1 1 0 -1\n" % (pid, comm, st))
    lf = []
    for i, n in enumerate(lockfiles):
        p = b.p("locks_f%d" % i); open(p, "w").write("x"); lf.append(p)
    open(b.p("proc_locks"), "w").write(locks); b.unit("apt-daily.service", ActiveState=unit_a); b.unit("apt-daily-upgrade.service", ActiveState=unit_b)
    subs = [(r'^ROOT_UID_EXPECT="0"$', 'ROOT_UID_EXPECT="%d"' % UID), (r'^PROCROOT=.*$', 'PROCROOT="%s"' % proc), (r'^LOCKS_FILE=.*$', 'LOCKS_FILE="%s"' % b.p("proc_locks")), (r'^LOCK_FILES=.*$', 'LOCK_FILES="%s"' % " ".join(lf))]
    return b.script("pkg-state.sh", subs), lf
RES_HELPER = {1060: ("unattended-upgr", "/usr/bin/python3 /usr/share/unattended-upgrades/unattended-upgrade-shutdown --wait-for-signal", "S")}
def pk(name, expect, procs=None, **kw):
    def f(b):
        s, lf = pkg(b, procs, **{k: v for k, v in kw.items() if k != "lockheld"})
        if kw.get("lockheld"): open(b.p("proc_locks"), "w").write("1: FLOCK  ADVISORY  WRITE 99 08:01:%s 0 EOF\n" % os.stat(lf[0]).st_ino)
        x = b.run(s); A(x.stdout.strip().splitlines()[-1] == expect, x.stdout)
    case(name, boxed(f))
pk("PK01 resident helper only -> CLEAR_RESIDENT_ONLY", "PKG_STATE=CLEAR_RESIDENT_ONLY", RES_HELPER)
pk("PK02 no package process at all -> CLEAR", "PKG_STATE=CLEAR_RESIDENT_ONLY", {})
pk("PK03 a real unattended-upgrade run -> STOP", "PKG_STATE=STOP;REASON=upgrade_run_active", {1060: RES_HELPER[1060], 2000: ("unattended-upgr", "/usr/bin/python3 /usr/bin/unattended-upgrade --verbose", "R")})
pk("PK04 apt-get running -> STOP", "PKG_STATE=STOP;REASON=apt_or_dpkg_active", {**RES_HELPER, 2001: ("apt-get", "apt-get upgrade", "S")})
pk("PK05 dpkg running -> STOP", "PKG_STATE=STOP;REASON=apt_or_dpkg_active", {2002: ("dpkg", "dpkg --configure -a", "S")})
pk("PK06 the helper not sleeping (state R) is not accepted as resident -> STOP", "PKG_STATE=STOP;REASON=apt_or_dpkg_active", {1060: ("unattended-upgr", "/usr/bin/python3 /usr/share/unattended-upgrades/unattended-upgrade-shutdown", "R")})
pk("PK07 an unattended-upgr process of unknown kind -> STOP", "PKG_STATE=STOP;REASON=apt_or_dpkg_active", {1060: ("unattended-upgr", "/usr/bin/python3 /opt/other.py", "S")})
pk("PK08 unreadable command line -> STOP (UNKNOWN is never green)", "PKG_STATE=STOP;REASON=process_unknown", {1060: ("unattended-upgr", None, "S")})
pk("PK09 apt-daily.service active -> STOP", "PKG_STATE=STOP;REASON=apt_unit_active_or_unknown", RES_HELPER, unit_a="active")
pk("PK10 apt-daily-upgrade.service state unreadable -> STOP", "PKG_STATE=STOP;REASON=apt_unit_active_or_unknown", RES_HELPER, unit_b="")
pk("PK11 a dpkg lock held (listed in /proc/locks) -> STOP", "PKG_STATE=STOP;REASON=package_lock_held", RES_HELPER, lockfiles=("a",), lockheld=True)
pk("PK12 an unheld lock file is not a lock", "PKG_STATE=CLEAR_RESIDENT_ONLY", RES_HELPER, lockfiles=("a",), locks="1: FLOCK  ADVISORY  WRITE 99 08:01:7 0 EOF\n")
def t_pk_locks_unreadable(b):
    s, lf = pkg(b, RES_HELPER); t = open(s).read().replace('LOCKS_FILE="%s"' % b.p("proc_locks"), 'LOCKS_FILE="%s"' % b.p("nope")); open(s, "w").write(t)
    x = b.run(s); A(x.stdout.strip().splitlines()[-1] == "PKG_STATE=STOP;REASON=lock_state_unknown", x.stdout)
case("PK13 an unreadable /proc/locks -> STOP", boxed(t_pk_locks_unreadable))
def t_pk_no_args_printed(b):
    s, lf = pkg(b, {1060: ("unattended-upgr", "/usr/bin/python3 /usr/share/unattended-upgrades/unattended-upgrade-shutdown --SECRET-TOKEN-ARG", "S")}); x = b.run(s)
    A("SECRET-TOKEN-ARG" not in x.stdout + x.stderr, "no command line may reach the output")
case("PK14 command lines never appear in the output", boxed(t_pk_no_args_printed))
def t_pk_static():
    c = "\n".join(l for l in read("pkg-state.sh").splitlines() if not l.lstrip().startswith("#"))
    for bad in (r"\bflock\b", r"systemctl\s+(start|stop|restart|kill|mask|disable|enable)", r"\brm\b", r"\bapt(-get)?\s", r"dpkg\s+-", r"kill\s"): A(not re.search(bad, c), "banned: " + bad)
case("PK15 static: read-only (no lock attempt, no unit action, no apt/dpkg invocation)", t_pk_static)
# ======================================================= re-baseline generator + frozen artifacts
def reb(*a): return subprocess.run([sys.executable, os.path.join(OPS, "rebaseline-prescript.py"), *a], capture_output=True, text=True)
FROZEN = os.path.join(OPS, "frozen", "go20-38-pre-script-v3c.sh")
def t_reb_ok():
    d = tempfile.mkdtemp()
    try:
        out = os.path.join(d, "v3d.sh"); r = reb("--in", FROZEN, "--out", out, "--set", "BASELINE_BOOT_ID=" + "ab" * 16)
        A(r.returncode == 0 and "REBASELINE=PASS" in r.stdout and "CHANGED=1" in r.stdout, r.stdout + r.stderr)
        a = open(FROZEN).read().splitlines(); c = open(out).read().splitlines(); diff = [i for i in range(len(a)) if a[i] != c[i]]
        A(len(a) == len(c) and len(diff) == 1 and c[diff[0]] == 'BASELINE_BOOT_ID="%s"' % ("ab" * 16), "exactly one line differs")
        A(sha(out) == re.search(r"OUT_SHA256=([0-9a-f]{64})", r.stdout).group(1), "printed hash is the file hash")
    finally: shutil.rmtree(d)
case("RB01 re-baseline changes exactly the boot-id line and prints the new hash", t_reb_ok)
def t_reb_stop(args, code):
    def f():
        d = tempfile.mkdtemp()
        try:
            r = reb("--in", FROZEN, "--out", os.path.join(d, "o.sh"), *args); A(r.returncode != 0 and code in r.stdout + r.stderr, r.stdout + r.stderr); A(not os.path.exists(os.path.join(d, "o.sh")), "no output on refusal")
        finally: shutil.rmtree(d)
    return f
case("RB02 a name outside the allow-list is refused (e.g. the collector hash)", t_reb_stop(["--set", "COLLECTOR_V22_EXPECTED_HASH=" + "a" * 64], "name_not_allowed"))
case("RB03 a malformed boot id is refused", t_reb_stop(["--set", "BASELINE_BOOT_ID=xyz"], "value_format"))
case("RB04 an unchanged value is refused", t_reb_stop(["--set", "BASELINE_BOOT_ID=cf8eed31d9b2464cb33be129ec40df1d"], "value_unchanged"))
case("RB05 duplicate names are refused", t_reb_stop(["--set", "BASELINE_BOOT_ID=" + "ab" * 16, "--set", "BASELINE_BOOT_ID=" + "cd" * 16], "duplicate_name"))
def t_reb_exists():
    d = tempfile.mkdtemp()
    try:
        out = os.path.join(d, "o.sh"); open(out, "w").write("x"); r = reb("--in", FROZEN, "--out", out, "--set", "BASELINE_BOOT_ID=" + "ab" * 16); A(r.returncode != 0 and "output_exists" in r.stdout + r.stderr and open(out).read() == "x", r.stdout)
    finally: shutil.rmtree(d)
case("RB06 an existing output file is never overwritten", t_reb_exists)
# ======================================================= console A (start orchestration), all stubs
import fcntl
CALLS = "calls_a.log"
import threading
ARM_RID = "20261120T100000Z-aaaaaaaaaaaa"
def b_ack_line(rid, nonce, pid, w): return "START_ACK v1 run=%s nonce=%s pid=%d w=%d\n" % (rid, nonce, pid, w)
def b_responder(ad, mode, lk, run):
    """Emulates console B's handshake side in the test process (a real B is covered by s7b/tests HS*). Modes: see the CG cases."""
    dead = subprocess.Popen(["true"]); dead.wait(); dead_pid = dead.pid
    def go():
        intent, ack = ad + "/START_INTENT", ad + "/START_ACK"
        for _ in range(4000):
            if os.path.exists(intent) and os.path.getsize(intent) > 0: break
            time.sleep(0.005)
        else: return
        time.sleep(0.01)
        m = re.match(r"START_INTENT v1 run=(\S+) nonce=([0-9a-f]{32})\n$", open(intent).read()); nonce = m.group(2) if m else "0" * 32
        def put(text, md=0o600):
            with open(ack, "x") as f: f.write(text)
            os.chmod(ack, md)
        def release(): fcntl.flock(lk, fcntl.LOCK_UN); lk.close()
        ok = b_ack_line(ARM_RID, nonce, os.getpid(), 250)
        if mode == "ack": put(ok)
        elif mode == "none": pass
        elif mode == "late":
            time.sleep(2.5)
            try: put(ok)
            except OSError: pass
        elif mode == "wrong_nonce": put(b_ack_line(ARM_RID, "f" * 32, os.getpid(), 250))
        elif mode == "wrong_run": put(b_ack_line("20261119T100000Z-bbbbbbbbbbbb", nonce, os.getpid(), 250))
        elif mode == "short_commit": put(b_ack_line(ARM_RID, nonce, os.getpid(), 249))
        elif mode == "bad_mode": put(ok, 0o644)
        elif mode == "two_lines": put(ok + ok)
        elif mode == "no_newline": put(ok.rstrip("\n"))
        elif mode == "pid_gone": put(b_ack_line(ARM_RID, nonce, dead_pid, 250))
        elif mode == "garbage": put("hello\n")
        elif mode == "symlink":
            real = ad + "/ack_real"; open(real, "w").write(ok); os.chmod(real, 0o600); os.symlink(real, ack)
        elif mode == "release_before_ack": release()
        elif mode == "release_after_ack": put(ok); release()
        elif mode == "finish_after_ack": put(ok); open(ad + "/evidence.txt", "a").write("S7B_RESULT=NO_START_OBSERVED;WAITED=120s;ACTION=NONE;G1_NOT_SUPERVISED\n")
        elif mode == "newdir_after_ack":
            nd = run + "/wpcg-s7b-20261120T100500Z-bbbbbbbbbbbb"; os.makedirs(nd); open(nd + "/evidence.txt", "w").write("S7B_ARMED version=s7b-containment-v3 instance=production mode=contain unit=x run_dir=%s\n" % nd)
            time.sleep(0.05); put(ok)   # the newer directory exists BEFORE the ACK is visible, so the outcome does not depend on timing
    th = threading.Thread(target=go, daemon=True); th.start(); return th
def ca_env(b, pre_gate_rc=0, pre_outcome="NO_BLOCKER_FOUND", pkg_last="PKG_STATE=CLEAR_RESIDENT_ONLY", helper_rc=0, armed_mode="contain", with_t8=True, logstate=True, hold_lock=True,
           b_mode="ack", patches=(), max_intent=30, pre_sleep=0, release=None, preexist=None, evidence_extra="", run_dir_in_evidence=True):
    tk = b.p("toolkit"); os.makedirs(tk); run = b.p("runroot"); os.makedirs(run)
    def w(path, text, mode=0o755): open(path, "w").write(text); os.chmod(path, mode); return path
    log = b.p(CALLS)
    w(tk + "/go20-38-pre-gate-v4a.sh", '#!/bin/bash\necho gate >> %s\necho "GATE=OK"\necho "GATE_RC=%d"\nexit %d\n' % (log, pre_gate_rc, pre_gate_rc))
    w(tk + "/go20-38-pre-thresholds-v1.txt", "thresholds\n")
    w(tk + "/go20-38-post-script-v2.py", '#!/usr/bin/env python3\nimport sys\nopen(%r, "a").write("post " + " ".join(sys.argv[1:]) + "\\n")\nprint("POST_STUB_OK")\n' % log)
    w(tk + "/go20-38-post-gate-v2.sh", '#!/bin/bash\necho postgate >> %s\necho "POSTGATE=OK"\nexit 0\n' % log)
    w(tk + "/pkg-state.sh", '#!/bin/bash\necho pkgstate >> %s\necho "%s"\n[ "%s" = "PKG_STATE=CLEAR_RESIDENT_ONLY" ]\n' % (log, pkg_last, pkg_last))
    pre = w(b.p("pre-script.sh"), '#!/bin/bash\necho pre >> %s\n%secho "WPCG_BLOCK_BEGIN stub"\necho "PRE_OUTCOME=%s"\n%s\necho "WPCG_BLOCK_END stub"\n' % (log, ("sleep %s\n" % pre_sleep) if pre_sleep else "", pre_outcome, 'echo "LOGSTATE inode=654096 size=2345 t0=1795171284"' if logstate else ""))
    helper = w(b.p("helper.py"), '#!/usr/bin/env python3\nimport sys\nopen(%r, "a").write("helper " + " ".join(sys.argv[1:]) + "\\n")\nprint("START=PASS;INSTANCE=wpcaregrid-production" if %d == 0 else "G1_MYSQL=STOP;CODE=command_failed")\nsys.exit(%d)\n' % (log, helper_rc, helper_rc))
    coll = w(b.p("collector.sh"), "#!/bin/bash\n"); w(b.p("bin/journalctl"), '#!/bin/bash\necho "-- cursor: s=abc;i=1;b=2;m=3;t=4;x=5"\n')
    if with_t8: open(b.p("t8_marker"), "w").write("paused\n")
    open(b.p("s7b_lock"), "a").close()
    if armed_mode:
        ad = run + "/wpcg-s7b-" + ARM_RID; os.makedirs(ad); open(ad + "/evidence.txt", "w").write("S7B_ARMED version=s7b-containment-v3 instance=production mode=%s unit=x run_dir=%s\n%s" % (armed_mode, ad if run_dir_in_evidence else "y", evidence_extra))
        if preexist == "ack": open(ad + "/START_ACK", "w").write(b_ack_line(ARM_RID, "0" * 32, os.getpid(), 250)); os.chmod(ad + "/START_ACK", 0o600)
        if preexist == "intent": open(ad + "/START_INTENT", "w").write("START_INTENT v1 run=%s nonce=%s\n" % (ARM_RID, "0" * 32)); os.chmod(ad + "/START_INTENT", 0o600)
    shas = {k: sha(v) for k, v in {"gate": tk + "/go20-38-pre-gate-v4a.sh", "thr": tk + "/go20-38-pre-thresholds-v1.txt", "post": tk + "/go20-38-post-script-v2.py", "postgate": tk + "/go20-38-post-gate-v2.sh", "helper": helper, "coll": coll}.items()}
    subs = [(r'^ROOT_UID_EXPECT="0"$', 'ROOT_UID_EXPECT="%d"' % UID), (r'^TOOLKIT=.*$', 'TOOLKIT="%s"' % tk), (r'^RUN_PARENT=.*$', 'RUN_PARENT="%s"' % run), (r'^HELPER=.*$', 'HELPER="%s"' % helper), (r'^HELPER_SHA=.*$', 'HELPER_SHA="%s"' % shas["helper"]),
            (r'^COLLECTOR_PATH=.*$', 'COLLECTOR_PATH="%s"' % coll), (r'^COLLECTOR_SHA=.*$', 'COLLECTOR_SHA="%s"' % shas["coll"]), (r'^GATE_SHA=.*$', 'GATE_SHA="%s"' % shas["gate"]), (r'^THRESHOLDS_SHA=.*$', 'THRESHOLDS_SHA="%s"' % shas["thr"]),
            (r'^POST_SHA=.*$', 'POST_SHA="%s"' % shas["post"]), (r'^POSTGATE_SHA=.*$', 'POSTGATE_SHA="%s"' % shas["postgate"]), (r'^S7B_LOCK=.*$', 'S7B_LOCK="%s"' % b.p("s7b_lock")), (r'^T8_MARKER=.*$', 'T8_MARKER="%s"' % b.p("t8_marker")),
            ]
    if re.search(r'^ACK_POLL_SEC=1; MAX_INTENT_TO_START_SEC=30; ACK_MIN_COMMIT_SEC=250$', read("g1-start-console-a.sh"), re.M):   # absent in the pre-CG-1 source: the same scenarios then run against it unchanged
        subs.append((r'^ACK_POLL_SEC=1; MAX_INTENT_TO_START_SEC=30; ACK_MIN_COMMIT_SEC=250$', 'ACK_POLL_SEC=0.02; MAX_INTENT_TO_START_SEC=%s; ACK_MIN_COMMIT_SEC=250' % max_intent))
    script = b.script("g1-start-console-a.sh", subs, patches)
    lk = None
    if hold_lock: lk = open(b.p("s7b_lock"), "w"); fcntl.flock(lk, fcntl.LOCK_EX)
    if lk and armed_mode: b_responder(run + "/wpcg-s7b-" + ARM_RID, b_mode, lk, run)
    if lk and release == "during_pre": threading.Timer(0.5, lambda: (fcntl.flock(lk, fcntl.LOCK_UN), lk.close())).start()
    if lk and release == "after_gate":
        def watch():
            for _ in range(4000):
                if os.path.exists(log) and "gate" in open(log).read(): fcntl.flock(lk, fcntl.LOCK_UN); lk.close(); return
                time.sleep(0.005)
        threading.Thread(target=watch, daemon=True).start()
    return script, pre, sha(pre), run, log, lk
def ca_args(pre, ps): return ["--pre-script", pre, "--pre-sha", ps, "--confirm", "START production " + ps[:12]]
def ca_exec(b, script, args): return subprocess.run(["bash", "--noprofile", "--norc", script, *args], capture_output=True, text=True, env=dict(os.environ, PATH=b.p("bin") + ":" + os.environ["PATH"]), timeout=120)
def ca_run(b, args=None, patch_sha=False, **kw):
    script, pre, ps, run, log, lk = ca_env(b, **kw)
    a = args or (ca_args(pre, "0" * 64) if patch_sha else ca_args(pre, ps))
    x = ca_exec(b, script, a); calls = open(log).read().splitlines() if os.path.exists(log) else []
    return x, calls, run, lk
def t_ca_happy(b):
    x, calls, run, lk = ca_run(b); A(x.returncode == 0 and "G1_START=DONE" in x.stdout, x.stdout + x.stderr)
    order = [c.split()[0] for c in calls]; A(order == ["pkgstate", "pre", "gate", "helper", "post", "postgate"], order)
    A(sum(1 for c in calls if c.startswith("helper start --instance wpcaregrid-production")) == 1, "exactly one helper start")
    post = [c for c in calls if c.startswith("post ")][0]; A("654096:2345:1795171284" in post and "--t-pre-end" in post and "--t-start-cmd" in post and "--t-start-return" in post and "--t-post-begin" in post and "--helper-rc 0" in post and "--cursor s=abc" in post, post)
    rd = [d for d in os.listdir(run) if d.startswith("wpcg-g1-start-")]; A(len(rd) == 1 and oct(os.stat(run + "/" + rd[0]).st_mode & 0o777) == "0o700" and "NO_RETRY=yes" in x.stdout, rd)
case("CA01 happy path: gates, pkg-state, pre run + gate, ONE helper start, POST + gate, in this order; anchors and logstate passed to POST", boxed(t_ca_happy))
def cstop(name, code, **kw):
    def f(b):
        x, calls, run, lk = ca_run(b, **kw); A(x.returncode in (1, 2) and ("G1_START=STOP;CODE=%s" % code) in x.stdout, x.stdout + x.stderr)
        A(not any(c.startswith("helper") for c in calls), "the helper must not run after a refused gate: %s" % calls)
    case(name, boxed(f))
cstop("CA02 wrong confirmation phrase: nothing runs", "confirmation_mismatch", args=["--pre-script", "/x", "--pre-sha", "a" * 64, "--confirm", "START production wrong"])
cstop("CA03 console B (S7-B) not armed: the lock is not held -> STOP", "console_b_not_armed", hold_lock=False)
cstop("CA04 console B armed in observe mode only -> STOP", "console_b_not_in_contain_mode", armed_mode="observe")
cstop("CA05 console B never wrote an armed record -> STOP", "console_b_not_in_contain_mode", armed_mode=None)
cstop("CA06 the T8 timers are not paused -> STOP", "t8_timers_not_paused", with_t8=False)
cstop("CA07 PKG-STATE not clear -> STOP before the pre run", "package_state_not_clear", pkg_last="PKG_STATE=STOP;REASON=upgrade_run_active")
cstop("CA08 final pre gate not clean (rc 1) -> STOP", "pre_gate_not_clean", pre_gate_rc=1)
cstop("CA09 pre-script outcome not green -> STOP", "pre_outcome_not_green", pre_outcome="STOP;REASON=psi_memory_full_avg60_exceeds_1")
cstop("CA10 logstate line missing -> STOP", "logstate_missing", logstate=False)
cstop("CA11 pre-script hash differs from the approved hash -> STOP", "pre_script_hash", patch_sha=True)
def t_ca_toolkit(b):
    script, pre, ps, run, log, lk = ca_env(b); open(b.p("toolkit/go20-38-pre-gate-v4a.sh"), "a").write("# tampered\n")
    x = ca_exec(b, script, ca_args(pre, ps)); A("CODE=gate_hash" in x.stdout and not os.path.exists(log), x.stdout)
case("CA12 a tampered toolkit file stops everything before any script runs", boxed(t_ca_toolkit))
def t_ca_helper_fail(b):
    x, calls, run, lk = ca_run(b, helper_rc=1); A(x.returncode == 1 and "HELPER_START rc=1" in x.stdout and "G1_START=DONE;HELPER_RC=1" in x.stdout, x.stdout)
    A([c.split()[0] for c in calls] == ["pkgstate", "pre", "gate", "helper", "post", "postgate"], "POST must still run after a failed start: %s" % calls); A(sum(1 for c in calls if c.startswith("helper")) == 1, "no retry")
case("CA13 a failed helper start still runs POST and is never retried", boxed(t_ca_helper_fail))
def t_ca_stale(b):
    script, pre, ps, run, log, lk = ca_env(b); t = open(script).read().replace("MAX_PRE_TO_START_SEC=1800", "MAX_PRE_TO_START_SEC=0"); open(script, "w").write(t)
    x = ca_exec(b, script, ca_args(pre, ps)); calls = open(log).read().splitlines()
    A("CODE=pre_run_stale" in x.stdout and not any(c.startswith("helper") for c in calls), x.stdout)
case("CA14 a stale pre run (older than the allowed gap) stops before the start", boxed(t_ca_stale))
def t_ca_static():
    c = "\n".join(l for l in read("g1-start-console-a.sh").splitlines() if not l.lstrip().startswith("#") and 'while [ "$#" -gt 0 ]' not in l)
    for bad in (r"systemctl", r"\brm\b", r"\bkill\b", r"reset-failed", r"daemon-reload", r"\bwhile\b", r"\buntil\b", r"sysctl\b|>+\s*/proc/sys", r"apparmor|aa-"): A(not re.search(bad, c), "banned: " + bad)
    A(len(re.findall(r'"\$HELPER" start', c)) == 1, "exactly one helper start call site")
case("CA15 static: no systemctl/rm/kill/loops; exactly one helper start call site", t_ca_static)
# ======================================================= CG-1: console A <-> console B (S7 containment fail-open), handshake + liveness
def hcalls(calls): return [c for c in calls if c.startswith("helper")]
def cg_stop(name, code, **kw):
    def f(b):
        x, calls, run, lk = ca_run(b, **kw)
        A(x.returncode in (1, 2) and ("G1_START=STOP;CODE=%s" % code) in x.stdout, x.stdout + x.stderr)
        A(not hcalls(calls), "the helper must NOT run: %s" % calls)
    case(name, boxed(f))
def t_cg_happy(b):
    x, calls, run, lk = ca_run(b); A(x.returncode == 0 and "G1_START=DONE" in x.stdout, x.stdout + x.stderr)
    A([c.split()[0] for c in calls] == ["pkgstate", "pre", "gate", "helper", "post", "postgate"] and len(hcalls(calls)) == 1, calls)
    ad = run + "/wpcg-s7b-" + ARM_RID; it = open(ad + "/START_INTENT").read(); m = re.fullmatch(r"START_INTENT v1 run=%s nonce=([0-9a-f]{32})\n" % ARM_RID, it); A(m, it)
    A(oct(os.stat(ad + "/START_INTENT").st_mode & 0o777) == "0o600", "intent mode")
    bsrc = open(os.path.join(OPS, "s7b", "s7b-containment.sh")).read(); bm = re.search(r'\[\[ "\$line" =~ (\^START_INTENT\\ .*?\$) \]\]', bsrc); A(bm, "intent grammar not found in console B")
    A(re.compile(bm.group(1)).fullmatch(it.rstrip("\n")), "console B's intent grammar must accept exactly what console A writes: %r" % it)
    rd = [d for d in os.listdir(run) if d.startswith("wpcg-g1-start-")][0]; res = open(run + "/" + rd + "/result.txt").read()
    A(("START_HANDSHAKE run=%s nonce=%s ack=valid" % (ARM_RID, m.group(1))) in res, res)
    return m.group(1)
case("CG01 happy path with the handshake: one helper start; intent (0600, run id + 128-bit nonce) written; handshake recorded in result.txt", boxed(t_cg_happy))
def t_cg_nonce(b):
    n = []
    for _ in range(2):
        bb = Box()
        try: n.append(t_cg_happy(bb))
        finally: bb.clean()
    A(n[0] != n[1], "every start attempt uses a fresh nonce (no replay)")
case("CG02 a fresh nonce for every run (an ACK from an earlier run can never match)", boxed(t_cg_nonce))
cg_stop("CG03 console B exits DURING A's pre-run (PoC 2 of the finding): STOP before the helper", "console_b_changed_before_start", release="during_pre", pre_sleep=1.5)
cg_stop("CG04 console B exits after the pre run and the gate, before the handshake (PoC 3): STOP before the helper", "console_b_changed_before_start", release="after_gate")
cg_stop("CG05 console B exits after the intent, before any ACK: STOP (no ACK)", "console_b_no_ack", b_mode="release_before_ack")
cg_stop("CG06 console B never ACKs: bounded wait, STOP (no retry, no second intent)", "console_b_no_ack", b_mode="none")
cg_stop("CG07 a late ACK (after the bounded wait) is not accepted: STOP", "console_b_no_ack", b_mode="late")
for _m in ("wrong_nonce", "wrong_run", "short_commit", "bad_mode", "two_lines", "no_newline", "pid_gone", "garbage", "symlink"):
    cg_stop("CG08-%s invalid ACK (%s): STOP before the helper" % (_m, _m), "console_b_bad_ack", b_mode=_m)
cg_stop("CG09 REQUIRED CASE: console B exits AFTER the ACK, before the helper (lock released): STOP, helper not called", "console_b_changed_before_start", b_mode="release_after_ack")
cg_stop("CG10 console B wrote its result line after the ACK (finished): STOP, helper not called", "console_b_changed_before_start", b_mode="finish_after_ack")
cg_stop("CG11 a different, newer console B run directory appears after the ACK: STOP (the ACK belongs to another instance)", "console_b_changed_before_start", b_mode="newdir_after_ack")
cg_stop("CG12 a pre-existing START_ACK (replay from an earlier run) is refused before the intent is written", "start_handshake_preexisting", preexist="ack")
cg_stop("CG13 a pre-existing START_INTENT is refused", "start_handshake_preexisting", preexist="intent")
cg_stop("CG14 an ACK that arrives but is older than the intent->helper cap is expired: STOP", "start_ack_expired", max_intent=0)
cg_stop("CG15 the evidence of console B names a different run directory: STOP at the gates", "console_b_state_invalid", run_dir_in_evidence=False)
cg_stop("CG16 console B already reports a start observation at the gates: STOP", "console_b_state_invalid", evidence_extra="S7B_START_OBSERVED t=1 g1=activating/start-pre\n")
cg_stop("CG17 console B already reports a result at the gates (finished): STOP", "console_b_state_invalid", evidence_extra="S7B_RESULT=NO_START_OBSERVED;WAITED=120s;ACTION=NONE;G1_NOT_SUPERVISED\n")
def t_cg_static():
    src = read("g1-start-console-a.sh"); c = "\n".join(l for l in src.splitlines() if not l.lstrip().startswith("#") and 'while [ "$#" -gt 0 ]' not in l)
    A(re.search(r"^ACK_POLL_SEC=1; MAX_INTENT_TO_START_SEC=30; ACK_MIN_COMMIT_SEC=250$", src, re.M), "production handshake constants")
    loops = re.findall(r"^\s*for n in ([0-9 ]+); do$", c, re.M); A(len(loops) == 1 and loops[0].split() == [str(i) for i in range(1, 21)], "exactly one bounded ACK loop with the literal bound 20: %s" % loops)
    A(not re.search(r"\bwhile\b|\buntil\b|\bretry\b|\bgoto\b", c), "no unbounded wait, no retry")
    A(len(re.findall(r'"\$HELPER" start', c)) == 1, "one helper call site")
    lines = c.splitlines(); i = [k for k, l in enumerate(lines) if '"$HELPER" start' in l][0]
    A("b_check || stop console_b_changed_before_start" in lines[i - 1], "the very last command before the helper is the liveness check of the exact B instance")
    h = [k for k, l in enumerate(lines) if l.lstrip().startswith("printf 'START_HANDSHAKE")][0]
    between = [l.strip() for l in lines[h + 1:i - 1]]
    A(len(between) == 3 and all(re.match(r"^(T_START_CMD=\$\(date -u \+%s\.%N\)|awk -v a=\"\$T_(PRE_END|INTENT)\" .* stop (pre_run_stale|start_ack_expired))$", l) for l in between), "between the verified ACK and the helper only the timestamp and the two freshness checks run: %s" % between)
    j = [k for k, l in enumerate(lines) if "journalctl" in l][0]; A(j < [k for k, l in enumerate(lines) if 'PHASE="start_handshake"' in l][0], "the variable-time journal cursor is taken BEFORE the handshake")
    p1 = [k for k, l in enumerate(lines) if "pre-gate-v4a.sh" in l and "RUN/pre.out" in l][0]; A(p1 < h, "the handshake comes after PKG-STATE, pre run and pre gate")
    A('timeout -k 10 "$START_TIMEOUT"' in src and re.search(r"START_TIMEOUT=180;", src), "helper timeout 180 s with a 10 s kill grace")
    s7 = open(os.path.join(OPS, "s7b", "s7b-containment.sh")).read(); A(re.search(r"^INTENT_WAIT_SEC=250\b", s7, re.M), "console B commits 250 s")
    A(250 >= 30 + 180 + 10 + 5 + 25, "commitment covers intent->helper cap + helper timeout + kill grace + sample + reserve")
case("CG18 static: bounded ACK loop (20, literal), no while/until/retry, one helper call site, the liveness check is the command right before the helper, cursor before the handshake, handshake after the pre gate, constants consistent with console B", t_cg_static)
FIN = '  b_check || stop console_b_changed_before_start   # LAST check: the exact B instance is still armed and alive; the helper call is the very next command'
def t_cg_residual(b):
    path = b.p("s7b_lock"); open(path, "a").close(); holder = subprocess.Popen(["flock", path, "sleep", "30"])
    try:
        for _ in range(400):
            if subprocess.run(["flock", "-n", path, "true"]).returncode != 0: break
            time.sleep(0.01)
        script, pre, ps, run, log, lk = ca_env(b, hold_lock=False, patches=[(FIN, FIN + "\n  kill %d; sleep 0.3" % holder.pid)])
        b_responder(run + "/wpcg-s7b-" + ARM_RID, "ack", None, run)
        x = ca_exec(b, script, ca_args(pre, ps)); calls = open(log).read().splitlines()
        A(len(hcalls(calls)) == 1 and "G1_START=DONE" in x.stdout, "characterisation: console B ending after the last check lets the helper run: " + x.stdout[-300:])
    finally: holder.kill(); holder.wait()
case("CGR1 KNOWN RESIDUAL, NOT CLOSABLE IN CODE (CG-1 stays OPEN): console B ends between the last liveness check and the helper -> the helper still starts (this test documents the window, it does not accept it)", boxed(t_cg_residual))
def cgm(name, patch, **kw):
    def f(b):
        x, calls, run, lk = ca_run(b, patches=[patch], **kw); A(len(hcalls(calls)) == 1 and "G1_START=DONE" in x.stdout, "the mutant must let the helper run in the scenario the defense stops: " + x.stdout[-250:] + str(calls))
    case(name, boxed(f))
AGE = "  awk -v a=\"$T_INTENT\" -v b=\"$T_START_CMD\" -v m=\"$MAX_INTENT_TO_START_SEC\" 'BEGIN{exit (b-a<=m)?0:1}' || stop start_ack_expired"
CG_RESLINE = "  if grep -qE '^(S7B_RESULT=|S7B_FINAL |S7B_START_OBSERVED |S7B_TRIGGER )' \"$ev\" 2>/dev/null; then BC_WHY=\"b_already_started_or_finished\"; return 1; fi"
cgm("CGM01 mutant: no last liveness check -> CG09 scenario (B exits after the ACK) lets the helper run (killed by CG09)", (FIN, "  :"), b_mode="release_after_ack")
cgm("CGM02 mutant: lock not checked -> CG03 scenario (B gone during pre-run) lets the helper run (killed by CG03/CG04/CG09)", ('  if flock -n 8; then BC_WHY="lock_free"; return 1; fi', "  :"), release="during_pre", pre_sleep=1.5)
cgm("CGM03 mutant: nonce not compared -> wrong_nonce ACK accepted (killed by CG08-wrong_nonce)", ('[ "${BASH_REMATCH[2]}" = "$H_NONCE" ] || { ACK_WHY="nonce"; return 1; }', ":"), b_mode="wrong_nonce")
cgm("CGM04 mutant: run id not compared -> an ACK from another run accepted (killed by CG08-wrong_run)", ('[ "${BASH_REMATCH[1]}" = "$H_RID" ] || { ACK_WHY="other_run"; return 1; }', ":"), b_mode="wrong_run")
cgm("CGM05 mutant: commitment not checked -> a 249 s ACK accepted (killed by CG08-short_commit)", ('[ "${BASH_REMATCH[4]}" -ge "$ACK_MIN_COMMIT_SEC" ] || { ACK_WHY="commitment_short"; return 1; }', ":"), b_mode="short_commit")
cgm("CGM06 mutant: ACK wait skipped -> no ACK needed (killed by CG06)", ("    if ack_valid; then ack_ok=1; break; fi", "    ack_ok=1; break"), b_mode="none")
cgm("CGM07 mutant: intent->helper age not enforced -> expired ACK accepted (killed by CG14)", (AGE, "  :"), max_intent=0)
cgm("CGM08 mutant: run directory identity not compared -> a newer B run accepted (killed by CG11)", ('  [ -n "$ARMED_DIR" ] && [ "$cur" = "$ARMED_DIR" ] || { BC_WHY="run_dir_changed"; return 1; }', "  :"), b_mode="newdir_after_ack")
cgm("CGM09 mutant: B result line not checked -> a finished B accepted (killed by CG10)", (CG_RESLINE, "  :"), b_mode="finish_after_ack")
cgm("CGM10 mutant: B process liveness (pid) not checked -> an ACK naming a dead pid accepted (killed by CG08-pid_gone)", ('[ -d "/proc/${BASH_REMATCH[3]}" ] || { ACK_WHY="b_process_gone"; return 1; }', ":"), b_mode="pid_gone")
cgm("CGM11 mutant: ACK file mode not checked -> a 0644 ACK accepted (killed by CG08-bad_mode)", ('case "$meta" in *" 600 "*) ;; *) ACK_WHY="mode"; return 1 ;; esac', ":"), b_mode="bad_mode")
cgm("CGM12 mutant: one-line rule not checked -> a two-line ACK accepted (killed by CG08-two_lines)", ('[ "${meta##* }" = "$(( ${#line} + 1 ))" ] || { ACK_WHY="not_one_line"; return 1; }', ":"), b_mode="two_lines")
# resident-helper-aware package block, executed against /proc fixtures
def pkg_block():
    d = tempfile.mkdtemp(); out = os.path.join(d, "o.sh"); reb("--in", FROZEN, "--out", out, "--resident-helper-aware"); t = open(out).read(); shutil.rmtree(d)
    a = t.index("# Package manager processes"); b = t.index('out "PACKAGE_PROCESS_COUNT'); return t[a:b]
def run_block(b, procs, counts=None):
    proc = b.p("p"); os.makedirs(proc)
    for pid, (cmd, st) in procs.items():
        d = os.path.join(proc, str(pid)); os.makedirs(d); open(d + "/cmdline", "wb").write(cmd.replace(" ", "\0").encode() + b"\0"); open(d + "/stat", "w").write("%s (unattended-upgr) %s 1\n" % (pid, st))
    open(b.p("bin/pgrep"), "w").write('#!/bin/bash\ncase "$*" in\n  "-c -x apt") echo %d;; "-c -x apt-get") echo %d;; "-c -x dpkg") echo %d;;\n  "-x unattended-upgr") for p in %s; do echo $p; done;;\nesac\n' % ((counts or (0, 0, 0)) + (" ".join(str(p) for p in procs),))); os.chmod(b.p("bin/pgrep"), 0o755)
    blk = pkg_block(); n = blk.count('"/proc/$pkg_pid/'); assert n == 2, n; blk = blk.replace('"/proc/$pkg_pid/', '"%s/$pkg_pid/' % proc)
    prog = 'is_int() { case "$1" in \'\'|*[!0-9]*) return 1 ;; esac; return 0; }\n' + blk + 'echo "COUNT=$PKG_CNT"\n'
    r = subprocess.run(["bash", "-c", prog], capture_output=True, text=True, env=dict(os.environ, PATH=b.p("bin") + ":" + os.environ["PATH"])); return r.stdout.strip()
RES_CMD = "/usr/bin/python3 /usr/share/unattended-upgrades/unattended-upgrade-shutdown --wait-for-signal"
def t_blk_resident(b): A(run_block(b, {1060: (RES_CMD, "S")}) == "COUNT=0", "resident helper only")
def t_blk_run(b): A(run_block(b, {1060: (RES_CMD, "S"), 2000: ("/usr/bin/python3 /usr/bin/unattended-upgrade -v", "R")}) == "COUNT=1", "a real upgrade run counts")
def t_blk_state(b): A(run_block(b, {1060: (RES_CMD, "R")}) == "COUNT=1", "helper not sleeping counts")
def t_blk_unread(b): A(run_block(b, {1060: ("", "S")}) == "COUNT=1", "empty command line counts (fail-closed)")
def t_blk_apt(b): A(run_block(b, {1060: (RES_CMD, "S")}, counts=(1, 0, 2)) == "COUNT=3", "apt and dpkg still count")
case("RB07 patched package block: resident helper (state S) is not counted", boxed(t_blk_resident))
case("RB08 patched package block: a real unattended-upgrade run is counted", boxed(t_blk_run))
case("RB09 patched package block: the helper in a non-sleeping state is counted", boxed(t_blk_state))
case("RB10 patched package block: an empty command line is counted (fail-closed)", boxed(t_blk_unread))
case("RB11 patched package block: apt / apt-get / dpkg processes are still counted", boxed(t_blk_apt))
def t_rb_pkgonly():
    d = tempfile.mkdtemp()
    try:
        out = os.path.join(d, "o.sh"); r = reb("--in", FROZEN, "--out", out, "--resident-helper-aware"); A(r.returncode == 0 and "CHANGED=1" in r.stdout, r.stdout + r.stderr)
        a = open(FROZEN).read().splitlines(); c = open(out).read().splitlines(); A(a[:1263] == c[:1263] and a[1276:] == c[1285:], "only the package block differs")
        A(reb("--in", FROZEN, "--out", os.path.join(d, "none.sh")).returncode != 0, "nothing to do is refused")
    finally: shutil.rmtree(d)
case("RB12 the package patch changes only the package block (all other lines identical)", t_rb_pkgonly)

FROZEN_HASHES = {"go20-38-pre-script-v3c.sh": "4da0c13117320b946d6cad4a4df905310e9a111119fb5519ae312985cc9e8282", "go20-38-pre-gate-v4a.sh": "1780fd1e30800b6ca6a0799aa3e94b1b1f105a549e293d84c858a30afa9ef326", "go20-38-pre-thresholds-v1.txt": "d5a029bda6f51c97d73f99180a864307b8a95ddbd8a4ffd4780b10565bf57260",
                 "go20-38-post-script-v2.py": "14c875db52a4cc910ad04f32a5b7033bfedc6f9ce301d89de7f381609fa122a4", "go20-38-post-gate-v2.sh": "7be4b22aa01da7f5bbae6ba41854c85a4db5dbdd6a264d8113be43150e3910fd", "go20-37-collector-v2.2.sh": "25c985276f1016eabe8a0338caaecc1134c38999d84180170308a76ea9f54956"}
def t_frozen():
    for n, h in FROZEN_HASHES.items(): A(sha(os.path.join(OPS, "frozen", n)) == h, "frozen artifact changed: " + n)
    A(sha(os.path.join(OPS, "s7b", "s7b-containment.sh")) == "924b2f9634625629e757231fda2ebaed32f1a203485662116e5ca09e4cfd60a6", "S7-B wrapper hash")
case("FZ01 frozen approved artifacts and the S7-B wrapper equal their approved sha256 values", t_frozen)
def t_s7b_subset():
    env = dict(os.environ, S7B_ONLY=r"^(A|S0|W03)", S7B_WORKERS="2")
    r = subprocess.run([sys.executable, os.path.join(OPS, "s7b", "tests", "test_s7b.py")], capture_output=True, text=True, env=env, timeout=600)
    last = [l for l in r.stdout.splitlines() if l.startswith("TOTAL=")][-1]; m = re.match(r"TOTAL=(\d+) PASS=(\d+)", last); A(m and m.group(1) == m.group(2) and int(m.group(1)) >= 20, last + r.stdout[-300:])
if not os.environ.get("G2039_SKIP_S7B"): case("S7B01 the S7-B wrapper's arm/contract/static tests pass in the repo copy", t_s7b_subset)
# ======================================================= bundle + toolkit installer
import tarfile, io
def make_bundle(b):
    out = b.p("bundle", "b"); os.makedirs(b.p("bundle")); r = subprocess.run(["bash", os.path.join(OPS, "make-bundle.sh"), out], capture_output=True, text=True); A(r.returncode == 0 and "BUNDLE=PASS" in r.stdout, r.stdout + r.stderr)
    return out + ".tar", open(out + ".tar.sha256").read().strip()
def inst_script(b):
    return b.script("install-toolkit.sh", [(r'^ROOT_UID_EXPECT="0"$', 'ROOT_UID_EXPECT="%d"' % UID), (r'^TOOLKIT=.*$', 'TOOLKIT="%s"' % b.p("toolkit"))])
def t_bn_ok(b):
    tar, h = make_bundle(b); s = inst_script(b); x = b.run(s, "--bundle", tar, "--sha", h); A(x.returncode == 0 and "TOOLKIT_INSTALL=PASS" in x.stdout, x.stdout + x.stderr)
    tk = b.p("toolkit"); A(oct(os.stat(tk).st_mode & 0o777) == "0o500" and all(not (os.stat(os.path.join(tk, f)).st_mode & 0o222) for f in os.listdir(tk)), "read-only toolkit")
    for n, hh in FROZEN_HASHES.items(): A(sha(os.path.join(tk, n)) == hh, n)
    A(os.path.exists(tk + "/pkg-state.sh") and os.path.exists(tk + "/g1-start-console-a.sh") and os.path.exists(tk + "/t8-timers.sh"), "tools installed")
    y = b.run(s, "--bundle", tar, "--sha", h); A(y.returncode == 1 and "CODE=toolkit_exists" in y.stdout, "no overwrite: " + y.stdout)
case("BN01 bundle builds, installs read-only with all frozen hashes verified, and never overwrites", boxed(t_bn_ok))
def t_bn_badsha(b):
    tar, h = make_bundle(b); x = b.run(inst_script(b), "--bundle", tar, "--sha", "0" * 64); A(x.returncode == 1 and "CODE=bundle_hash" in x.stdout and not os.path.exists(b.p("toolkit")), x.stdout)
case("BN02 a wrong bundle hash stops before anything is created", boxed(t_bn_badsha))
def evil_tar(b, name, kind="file", mutate=None):
    tar, h = make_bundle(b); src = tarfile.open(tar); out = b.p("evil.tar"); dst = tarfile.open(out, "w")
    for m in src.getmembers(): dst.addfile(m, src.extractfile(m) if m.isfile() else None)
    if kind == "file": ti = tarfile.TarInfo(name); ti.size = 1; ti.mode = 0o600; dst.addfile(ti, io.BytesIO(b"x"))
    if kind == "symlink": ti = tarfile.TarInfo(name); ti.type = tarfile.SYMTYPE; ti.linkname = "/etc/passwd"; dst.addfile(ti)
    dst.close(); return out, sha(out)
def t_bn_unsafe(b):
    out, h = evil_tar(b, "../evil.sh"); x = b.run(inst_script(b), "--bundle", out, "--sha", h); A("CODE=unsafe_member_name" in x.stdout and not os.path.exists(b.p("toolkit")), x.stdout)
case("BN03 a bundle member with a path traversal is refused", boxed(t_bn_unsafe))
def t_bn_symlink(b):
    out, h = evil_tar(b, "./link", kind="symlink"); x = b.run(inst_script(b), "--bundle", out, "--sha", h); A("CODE=non_regular_member" in x.stdout and not os.path.exists(b.p("toolkit")), x.stdout)
case("BN04 a symlink member is refused", boxed(t_bn_symlink))
def t_bn_manifest(b):
    tar, h = make_bundle(b); src = tarfile.open(tar); out = b.p("mod.tar"); dst = tarfile.open(out, "w")
    for m in src.getmembers():
        data = src.extractfile(m).read() if m.isfile() else None
        if m.name == "./pkg-state.sh": data += b"# changed\n"; m.size = len(data)
        dst.addfile(m, io.BytesIO(data) if data is not None else None)
    dst.close(); x = b.run(inst_script(b), "--bundle", out, "--sha", sha(out)); A("CODE=manifest_mismatch" in x.stdout, x.stdout)
case("BN05 a file changed after the manifest was made is detected", boxed(t_bn_manifest))
def t_bn_frozen(b):
    tar, h = make_bundle(b); src = tarfile.open(tar); out = b.p("mod2.tar"); dst = tarfile.open(out, "w"); files = {}
    for m in src.getmembers():
        files[m.name] = (m, src.extractfile(m).read() if m.isfile() else None)
    files["./go20-38-pre-gate-v4a.sh"] = (files["./go20-38-pre-gate-v4a.sh"][0], files["./go20-38-pre-gate-v4a.sh"][1] + b"# x\n")
    man = "".join("%s  %s\n" % (hashlib.sha256(v[1]).hexdigest(), k[2:]) for k, v in sorted(files.items()) if v[1] is not None and k != "./MANIFEST.sha256").encode(); files["./MANIFEST.sha256"] = (files["./MANIFEST.sha256"][0], man)
    for k, (m, d) in files.items(): m.size = len(d) if d is not None else 0; dst.addfile(m, io.BytesIO(d) if d is not None else None)
    dst.close(); x = b.run(inst_script(b), "--bundle", out, "--sha", sha(out)); A("CODE=frozen_hash_go20-38-pre-gate-v4a.sh" in x.stdout, x.stdout)
case("BN06 a frozen approved file that differs (even with a matching manifest) is refused", boxed(t_bn_frozen))
def t_bn_noroot(b):
    tar, h = make_bundle(b); s = b.script("install-toolkit.sh", [(r'^ROOT_UID_EXPECT="0"$', 'ROOT_UID_EXPECT="0"' if UID != 0 else 'ROOT_UID_EXPECT="1"'), (r'^TOOLKIT=.*$', 'TOOLKIT="%s"' % b.p("toolkit"))]); x = b.run(s, "--bundle", tar, "--sha", h); A("CODE=not_root" in x.stdout, x.stdout)
case("BN07 not root is refused", boxed(t_bn_noroot))

if __name__ == "__main__":
    for n, s, m in RES: print("%-5s %s %s" % (s, n, m))
    # (a mutant run sets G2039_FAILFAST only to shorten its own output)
    print("TOTAL=%d PASS=%d" % (len(RES), sum(1 for x in RES if x[1] == "PASS")))
    sys.exit(0 if all(x[1] == "PASS" for x in RES) else 1)
