#!/usr/bin/env python3
"""Deterministic simulator for the S7-B wrapper tests: virtual clock + generated state files. No host access."""
import os, sys, json
SB = os.environ["S7B_SANDBOX"]
def rd(n, d=None):
    try: return open(os.path.join(SB, n)).read().strip()
    except FileNotFoundError: return d
def wr(n, v):
    os.makedirs(os.path.dirname(os.path.join(SB, n)), exist_ok=True); open(os.path.join(SB, n), "w").write(v)
SCEN = json.load(open(os.path.join(SB, "scenario.json")))
G1CG = SCEN.get("g1_cg", "/system.slice/system-mysql.slice/mysql@wpcaregrid-production.service")
LCG = SCEN.get("legacy_cg", "/system.slice/mysql.service")
def pick(tbl, t, default):
    cur = default
    for row in tbl:
        if t >= row[0]: cur = row[1]
    return cur
def render():
    t = int(rd("vclock", "0")); stop_at = rd("stop_at"); stop_at = int(stop_at) if stop_at is not None else None
    killed = (rd("cg/%s/cgroup.kill" % G1CG.lstrip("/"), "") or "").strip() == "1"
    g = dict(pick(SCEN["g1"], t, {"A": "inactive", "S": "dead", "pid": 0, "nr": 0}))
    if stop_at is not None and SCEN.get("stop_mode", "never") not in ("none", "ignored"):
        d = SCEN.get("stop_delay")
        if d is not None and t >= stop_at + d: g = {"A": "inactive", "S": "dead", "pid": 0, "nr": g.get("nr", 0)}
        elif g["A"] in ("activating", "active"): g = {"A": "deactivating", "S": "stop-sigterm", "pid": g.get("pid", 0), "nr": g.get("nr", 0)}
    if killed and not SCEN.get("kill_noeffect"): g = {"A": "inactive", "S": "dead", "pid": 0, "nr": g.get("nr", 0)}
    cg = pick(SCEN.get("g1_cg_tbl", []), t, G1CG) if g["A"] != "inactive" else ""
    wr("state/g1.show", "LoadState=" + SCEN.get("g1_load", "loaded") + "\nActiveState=%s\nSubState=%s\nMainPID=%s\nNRestarts=%s\nControlGroup=%s\nResult=%s\nExecMainStatus=0\n" % (g["A"], g["S"], g["pid"], g["nr"], cg, g.get("R", "success")))
    l = pick(SCEN.get("legacy", []), t, {"A": "active", "S": "running", "pid": 574923})
    wr("state/legacy.show", "LoadState=loaded\nActiveState=%s\nSubState=%s\nMainPID=%s\nNRestarts=0\nControlGroup=%s\nResult=success\n" % (l["A"], l["S"], l["pid"], SCEN.get("legacy_cg_val", LCG)))
    mem = pick(SCEN.get("mem", []), t, 5585)
    wr("proc/meminfo", "MemTotal:        7601000 kB\nMemAvailable:   %d kB\nSwapFree:       2814000 kB\n" % (mem * 1024) if mem is not None else "MemTotal: 1 kB\n")
    ok = pick(SCEN.get("oomk", []), t, 0)
    wr("proc/vmstat", ("nr_free_pages 1\noom_kill %s\n" % ok) if ok is not None else "nr_free_pages 1\n")
    procs = SCEN.get("procs", {"4242": "mysqld"}) if g["A"] not in ("inactive",) and not (killed and not SCEN.get("kill_noeffect")) else {}
    cgdir = "cg/%s" % G1CG.lstrip("/")
    if not SCEN.get("cg_missing") and not SCEN.get("cg_symlink"):
        os.makedirs(os.path.join(SB, cgdir), exist_ok=True)
        wr(cgdir + "/cgroup.procs", "".join("%s\n" % p for p in procs))
        if SCEN.get("kill_symlink") and not os.path.lexists(os.path.join(SB, cgdir, "cgroup.kill")):
            wr("kill_real", ""); os.symlink(os.path.join(SB, "kill_real"), os.path.join(SB, cgdir, "cgroup.kill"))
        elif not SCEN.get("no_cgroup_kill") and not os.path.lexists(os.path.join(SB, cgdir, "cgroup.kill")): wr(cgdir + "/cgroup.kill", "")
    for pid, comm in SCEN.get("procs", {"4242": "mysqld"}).items(): wr("proc/%s/comm" % pid, comm + "\n")
def inject_intents():
    """CG-1 tests: write START_INTENT (or a hostile variant) into the wrapper's run directory when the virtual clock reaches `at`.
    scenario["intent"] = [{"tag": "a", "at": 100, "mode": "ok", "nonce": "<32 hex>"}, ...]; a row is skipped until the run directory exists."""
    import stat as _st
    for row in SCEN.get("intent", []):
        tag = row["tag"]
        if rd("inj_" + tag) is not None or int(rd("vclock", "0")) < row["at"]: continue
        runp = os.path.join(SB, "runp"); dirs = sorted(x for x in os.listdir(runp) if x.startswith("wpcg-s7b-")) if os.path.isdir(runp) else []
        if not dirs: continue
        d = os.path.join(runp, dirs[-1]); rid = dirs[-1][len("wpcg-s7b-"):]; nonce = row.get("nonce", "a" * 32); mode = row.get("mode", "ok")
        path = os.path.join(d, "START_INTENT"); ok = "START_INTENT v1 run=%s nonce=%s\n" % (rid, nonce)
        if mode == "ok": open(path, "x").write(ok); os.chmod(path, 0o600)
        elif mode == "replace": open(path, "w").write(ok); os.chmod(path, 0o600)
        elif mode == "wrong_run": open(path, "x").write("START_INTENT v1 run=20250101T000000Z-aaaaaaaaaaaa nonce=%s\n" % nonce); os.chmod(path, 0o600)
        elif mode == "bad_format": open(path, "x").write("START_INTENT v2 run=%s nonce=%s\n" % (rid, nonce)); os.chmod(path, 0o600)
        elif mode == "bad_mode": open(path, "x").write(ok); os.chmod(path, 0o644)
        elif mode == "two_lines": open(path, "x").write(ok + ok); os.chmod(path, 0o600)
        elif mode == "no_newline": open(path, "x").write(ok.rstrip("\n")); os.chmod(path, 0o600)
        elif mode == "short_nonce": open(path, "x").write("START_INTENT v1 run=%s nonce=%s\n" % (rid, nonce[:31])); os.chmod(path, 0o600)
        elif mode == "symlink":
            real = os.path.join(d, "intent_real"); open(real, "w").write(ok); os.chmod(real, 0o600); os.symlink(real, path)
        elif mode == "preack": open(os.path.join(d, "START_ACK"), "x").write("pre-existing\n")
        wr("inj_" + tag, "1")
if __name__ == "__main__":
    cmd = sys.argv[1]
    if cmd == "init": wr("vclock", "0"); render()
    elif cmd == "advance":
        _n = int(rd("naps", "0") or 0) + 1; wr("naps", str(_n))
        before = int(rd("vclock", "0")); after = before + int(sys.argv[2])
        cj = SCEN.get("clock_jump")
        if cj and before < cj[0] <= after: after += cj[1]
        wr("vclock", str(after))
        lim = rd("int_at")
        render()
        inject_intents()
        if lim is not None and int(rd("vclock")) >= int(lim) and not os.path.exists(os.path.join(SB, "int_sent")):
            wr("int_sent", "1"); sys.exit(77)
    elif cmd == "stop": wr("stop_at", rd("vclock", "0")); open(os.path.join(SB, "calls.log"), "a").write("stop %s\n" % rd("vclock")); render()
    elif cmd == "render": render()
