#!/usr/bin/env python3
"""Offline verdict for one live acceptance run. Run as root, after the probes.

A probe errno of EACCES/EPERM is NOT evidence of AppArmor on its own. A denial counts only when, for the
same check, ALL of the following hold:

1. the unconfined baseline of the same probe reached the target (so DAC, Yama and existence do not explain
   the denial; baseline-masked checks are inconclusive),
2. the confined run was refused with EACCES/EPERM,
3. exactly one kernel ``apparmor="DENIED"`` record names the probe profile, the pid that made the call (the
   probe or its execve child), an operation appropriate to the check and the exact target (or ptrace/signal
   peer), carries an audit timestamp inside THAT call's own time window, comes from the same boot, and is
   consumed by no other check,
4. the same-UID/service-master inventories taken before and after are complete, stable (two consecutive
   scans agree; no pid reuse or churn between them) and map every process to a permitted class.

Audit input must be ``journalctl -k -o json`` lines (boot id and journal timestamp per record). A record for
the probe profile outside the probe's lifetime, from another boot, with a repeated audit serial, or that no
check explains, rejects the run. Descriptor-transfer outcomes need an actual denial errno and an event for
the exact stage that failed; ENOENT, EIO, a missing event or an unrelated event is a failure.

Anything missing, duplicated, unexpected or unexplained is a failure. Exit 0 means only that THIS run's
evidence is consistent; it is a record, not a standing guarantee.

Phases: ``pre`` = before the production unit exists (production absent and no real production file; the
production profile itself cannot be accepted yet), ``post`` = after the unrouted production unit started.
Usage: verify-acceptance.py <matrix.json> <profile> <pre|post> <baseline.json> <confined.json>
       <inventory-before.json> <inventory-after.json> <audit.jsonl> [--ack-fd-transfer-bypass]
"""
from __future__ import annotations

import errno
import hashlib
import json
import re
import sys

DENIED = (errno.EACCES, errno.EPERM)
EFAULT = errno.EFAULT
EPS_NS = 5_000_000  # audit stamps are millisecond-truncated CLOCK_REALTIME, the same clock the probe reads
LEFTOVER_NS = 1_000_000  # an extra record is tolerated only if it falls inside a denied call's own window
# Same-UID processes allowed to stay unconfined. Empty on purpose and permanent: there is no cron/sh
# exception. A pinned crontab does not confine an unconfined same-UID shell; www-data cron is not permitted.
ACCEPTED_UNCONFINED_SAME_UID_COMM: frozenset = frozenset()
FILE_OPS = {"r": {"open"}, "rw": {"open", "mknod", "mkdir", "truncate", "file_perm"},
            "w": {"open", "mknod", "mkdir", "truncate", "file_perm"}, "x": {"exec"}}
GENERIC_FD = (re.compile(r"^(socket|pipe):\[\d+\]$"), re.compile(r"^anon_inode:"),
              re.compile(r"^/dev/(null|zero|urandom|random)$"), re.compile(r"^/memfd:"),
              re.compile(r"^/(usr|lib|lib64|etc/php|etc/ssl|etc/ld\.so|run/php|tmp|var/log/php)(/|$)"))
CONTROL_PREFIXES = ("/etc/wpcaregrid-deploy", "/home", "/root", "/var/lib/mysql", "/run/mysqld")
ROLE_PREFIXES = {
    "production": ("/var/www/wpcaregrid-production", "/var/lib/wpcaregrid-deploy/production", "/var/log/wpcaregrid-production"),
    "staging-next": ("/var/www/wpcaregrid-staging-new", "/var/lib/wpcaregrid-deploy/staging-next", "/var/log/php8.5-fpm.log"),
    "staging-legacy": ("/var/www/wpcaregrid", "/var/lib/wpcaregrid/"),
}
KV = re.compile(r'(\w+)=(?:"([^"]*)"|(\S+))')
STAMP = re.compile(r"audit\((\d+)\.(\d+):(\d+)\)")


def parse_audit(text: str) -> tuple:
    """Return (records, problems) from ``journalctl -k -o json`` lines. Non-JSON input is a problem, never ignored."""
    records, problems, serials = [], [], set()
    for number, line in enumerate(text.splitlines(), 1):
        if not line.strip():
            continue
        try:
            entry = json.loads(line)
            message, boot = str(entry["MESSAGE"]), str(entry["_BOOT_ID"]).replace("-", "")
            journal_ns = int(entry["__REALTIME_TIMESTAMP"]) * 1000
        except (ValueError, KeyError, TypeError):
            problems.append(f"line {number}: not a journalctl -o json record with MESSAGE, _BOOT_ID and __REALTIME_TIMESTAMP")
            continue
        if 'apparmor="DENIED"' not in message:
            continue
        record = {m.group(1): (m.group(2) if m.group(2) is not None else m.group(3)) for m in KV.finditer(message)}
        stamp = STAMP.search(message)
        if "pid" not in record or not str(record["pid"]).isdigit() or stamp is None:
            problems.append(f"line {number}: DENIED record without pid or audit stamp")
            continue
        record["pid"] = int(record["pid"])
        record["_ts_ns"] = int(stamp.group(1)) * 1_000_000_000 + int(stamp.group(2).ljust(9, "0")[:9])
        record["_serial"] = (stamp.group(1), stamp.group(2), stamp.group(3))
        record["_boot"], record["_journal_ns"] = boot, journal_ns
        if record["_serial"] in serials:
            problems.append(f"line {number}: repeated audit serial {stamp.group(3)} (replayed event)")
            continue
        serials.add(record["_serial"])
        records.append(record)
    return records, problems


def under(target: str, prefix: str) -> bool:
    prefix = prefix.rstrip("/")
    return target == prefix or target.startswith(prefix + "/")


def fd_allowed(role: str, target: str) -> bool:
    target = target.removesuffix(" (deleted)")
    if any(under(target, p) for p in CONTROL_PREFIXES):
        return False
    for other, prefixes in ROLE_PREFIXES.items():
        if other != role and any(under(target, p) for p in prefixes):
            return False
    if any(g.match(target) for g in GENERIC_FD):
        return True
    return role == "staging-legacy" or any(under(target, p) for p in ROLE_PREFIXES[role])


def fd_ok(role: str, target: str) -> bool:
    """Descriptor policy per role. Edge and job roles are checked for secret fragments, FPM roles by tree."""
    target = target.removesuffix(" (deleted)")
    if any(under(target, p) for p in CONTROL_PREFIXES):
        return False
    if role == "nginx":
        # An allowlist: sockets, logs, its own state and TLS material, and files under a public web root. A
        # descriptor to application code, config, storage or an .env is never legitimate for nginx.
        return bool(GENERIC_FD[0].match(target) or GENERIC_FD[1].match(target) or GENERIC_FD[2].match(target)
                    or GENERIC_FD[3].match(target) or target.startswith(("/etc/nginx", "/etc/letsencrypt", "/etc/ssl", "/usr/",
                                                                         "/var/log/nginx", "/var/lib/nginx", "/run/nginx"))
                    or "/public/" in target + "/")
    if role.startswith("jobs-"):
        return not any(under(target, p) for p in ROLE_PREFIXES["production"])
    return fd_allowed(role, target)


class Verdict:
    def __init__(self) -> None:
        self.failures: list = []
        self.passes: list = []
        self.notes: list = []

    def ok(self, ident: str, detail: str = "") -> None:
        self.passes.append(f"PASS {ident} {detail}".rstrip())

    def fail(self, ident: str, detail: str) -> None:
        self.failures.append(f"FAIL {ident}: {detail}")

    def note(self, ident: str, detail: str) -> None:
        self.notes.append(f"NOTE {ident}: {detail}")


def identity(inv: dict) -> frozenset:
    return frozenset((p["pid"], p["starttime"], p["label"], p["unit"], tuple(p["uids"]), tuple(p["gids"]), tuple(p.get("groups", [])))
                     for p in inv["processes"])


def check_inventory(v: Verdict, matrix: dict, inv: dict, phase: str, required_roles: list, probe_phase: str,
                    matrix_hash: str, boot: str) -> dict:
    """Every uid-33 process and every service master must map to exactly one permitted class; anything else blocks."""
    label_roles: dict = {}
    for role, profile in matrix["role_profiles"].items():
        label_roles.setdefault(f"{profile} (enforce)", []).append(role)
    probe_labels = {f"{p}-probe (enforce)" for p in matrix["role_profiles"].values()}
    known_units = set(matrix["role_units"].values())
    if not isinstance(inv, dict) or inv.get("schema") != 2 or inv.get("complete") is not True or inv.get("scans", 0) < 2:
        v.fail(f"inventory:{phase}", f"inventory incomplete, unstable or malformed: {inv.get('reasons') if isinstance(inv, dict) else 'n/a'}")
        return {}
    if inv.get("matrix_sha256") != matrix_hash:
        v.fail(f"inventory:{phase}", "inventory was collected with a different matrix (unit list) than this run")
        return {}
    if inv.get("boot_id", "").replace("-", "") != boot:
        v.fail(f"inventory:{phase}", "inventory is from a different boot than the probe")
        return {}
    if set(inv["units"]) != known_units:
        v.fail(f"inventory:{phase}", "inventory does not cover exactly the package's service cgroups (caller-supplied list?)")
        return {}
    by_pid: dict = {}
    seen: dict = {}
    for proc in inv["processes"]:
        if proc["pid"] in by_pid:
            v.fail(f"inventory:{phase}", f"duplicate pid {proc['pid']} in one snapshot")
        by_pid[proc["pid"]] = proc
        if proc["label"] in probe_labels:
            continue
        roles = [r for r in label_roles.get(proc["label"], []) if matrix["role_units"][r] == proc["unit"]]
        if len(roles) == 1:
            seen[roles[0]] = seen.get(roles[0], 0) + 1
            for target in proc["fds"]:
                if not fd_ok(roles[0], target):
                    v.fail(f"fd-inventory:{phase}", f"pid {proc['pid']} ({roles[0]}) holds {target!r}")
            continue
        if proc["unit"] in known_units:
            v.fail(f"inventory:{phase}", f"pid {proc['pid']} ({proc['comm']!r}, uids {proc['uids']}) in service cgroup {proc['unit']!r} "
                   f"carries label {proc['label']!r}: a service master or worker is not enforce-attached to its profile")
            continue
        if label_roles.get(proc["label"]):
            v.fail(f"inventory:{phase}", f"pid {proc['pid']} carries label {proc['label']!r} outside its permitted unit ({proc['unit']!r})")
            continue
        if proc["comm"] in ACCEPTED_UNCONFINED_SAME_UID_COMM:
            v.note(f"inventory:{phase}", f"accepted unconfined {proc['comm']} pid {proc['pid']}")
            continue
        if 33 not in proc["uids"] and proc["uids"][1] in matrix.get("permitted_group33_holders", []):
            v.note(f"inventory:{phase}", f"permitted trusted group-33 holder uid {proc['uids'][1]} pid {proc['pid']}")
            continue
        holds = [name for name, ids in (("uid", proc["uids"]), ("gid", proc["gids"]), ("supplementary group", proc.get("groups", []))) if 33 in ids]
        v.fail(f"inventory:{phase}", f"process pid {proc['pid']} comm {proc['comm']!r} (holds {', '.join(holds) or 'a service cgroup'} 33) label "
               f"{proc['label']!r} unit {proc['unit']!r} maps to no permitted class (there is no cron/sh exception): it can read the other "
               "environment's group-readable secrets")
    for role in required_roles:
        unit = matrix["role_units"][role]
        if matrix["role_required"].get(role) and (not inv["units"].get(unit, {}).get("present") or seen.get(role, 0) < 1):
            v.fail(f"inventory:{phase}", f"role {role} ({unit}) has no identified process: enumeration incomplete")
    if probe_phase == "pre":
        production_unit = matrix["role_units"]["production"]
        production_label = f"{matrix['role_profiles']['production']} (enforce)"
        if inv["units"][production_unit]["pids"] or any(p["unit"] == production_unit or p["label"] == production_label
                                                        for p in inv["processes"]):
            v.fail(f"inventory:{phase}", "the production unit already has processes: the pre-production gate requires it absent")
        surface = inv.get("production_surface")
        if not isinstance(surface, dict) or surface.get("real_files"):
            v.fail(f"inventory:{phase}", "real (non-fixture) production files exist or the scan is missing: production secrets must not "
                   f"exist before every peer is confined ({(surface or {}).get('real_files', [])[:3]})")
    return by_pid


def window_hit(records: list, used: set, probe_profile: str, boot: str, pid: int, t0: int, t1: int, ops: set,
               name=None, peer: str | None = None, mention: str | None = None):
    """Index of one unconsumed record that matches profile, pid, operation, target and the call's own window."""
    for index, r in enumerate(records):
        if index in used or r.get("profile") != probe_profile or r["pid"] != pid or r.get("operation") not in ops:
            continue
        if r["_boot"] != boot or not (t0 - EPS_NS <= r["_ts_ns"] <= t1 + EPS_NS):
            continue
        if name is not None and r.get("name") not in (name if isinstance(name, (set, tuple, list)) else {name}):
            continue
        if peer is not None and not str(r.get("peer", "")).startswith(peer):
            continue
        if mention is not None and mention not in " ".join(str(x) for x in r.values()):
            continue
        return index
    return None


def expected_checks(matrix: dict, profile: str, peers: dict, phase: str = "post") -> tuple:
    """Every result id the probe must report, with its kind. Shared contract between probe and verdict."""
    spec = matrix["profiles"][profile]
    excluded = set(matrix["phases"][phase]["excluded_roles"])
    expected: dict = {}
    targets: set = set()
    for check in spec["checks"]:
        if check.get("live", True) and check.get("probe_scope") != "original_profile":
            expected[check["id"]] = ("file", check)
            targets.add(check["path"])
    for sock in spec["sockets"]:
        expected[f"socket:{sock['id']}"] = ("socket", {**sock, "access": "rw"})
        targets.add(sock["path"])
    for i, target in enumerate(spec["live_symlink_escape_targets"]):
        expected[f"symlink-escape:{i}"] = ("symlink", {"expect": "deny", "path": target, "access": "r"})
        targets.add(target)
    expected["abstract-connect"] = ("abstract", {"expect": "deny"})
    for role in spec["peer_roles"]:
        if role in excluded:
            continue
        for p in peers.get(role, []):
            for entry in matrix["peer_entries"]:
                expected[f"peer:{role}:{p}:{entry}"] = ("peer", {"role": role, "pid": int(p), "entry": entry})
            expected[f"peer:{role}:{p}:signal"] = ("peer-signal", {"role": role, "pid": int(p)})
            expected[f"peer:{role}:{p}:process_vm_readv"] = ("peer-vm", {"role": role, "pid": int(p)})
    for ident in ("control:self-maps", "calibration:child-signal"):
        expected[ident] = ("control", {})
    for ident in ("calibration:child-maps", "calibration:child-environ", "calibration:child-process_vm_readv",
                  "calibration:child-ptrace-seize"):
        expected[ident] = ("calibration", {})
    return expected, targets


def verify(matrix_text: str, profile: str, phase: str, baseline: dict, confined: dict, inv_before: dict, inv_after: dict,
           audit_text: str, ack_fd_transfer: bool = False) -> Verdict:
    v = Verdict()
    matrix = json.loads(matrix_text)
    spec = matrix["profiles"][profile]
    if phase not in matrix["phases"]:
        v.fail("phase", f"unknown phase {phase!r}")
        return v
    if phase == "pre" and profile == "wpcaregrid-php-fpm-production":
        v.fail("phase", "the production profile can only be accepted after the unrouted production unit starts (phase post)")
        return v
    probe_profile = f"{profile}-probe"
    role_profile = matrix["role_profiles"]
    digest = hashlib.sha256(matrix_text.encode()).hexdigest()
    for name, doc, label in (("baseline", baseline, "unconfined"), ("confined", confined, f"{probe_profile} (enforce)")):
        if doc.get("schema") != 2 or doc.get("mode") != name or doc.get("profile") != profile \
                or doc.get("label") != label or doc.get("matrix_sha256") != digest:
            v.fail(f"run:{name}", f"wrong schema/mode/profile/label/matrix hash (label {doc.get('label')!r})")
            return v
        if not all(type(doc.get(k)) is int for k in ("pid", "started_ns", "ended_ns", "starttime_ticks", "kernel_start_ns")) \
                or doc["started_ns"] > doc["ended_ns"] or abs(doc["kernel_start_ns"] - doc["started_ns"]) > 10_000_000_000:
            v.fail(f"run:{name}", "probe lifetime is not bound to its kernel start time")
            return v
    boot = str(confined.get("boot_id", "")).replace("-", "")
    if not boot or boot != str(baseline.get("boot_id", "")).replace("-", ""):
        v.fail("run", "baseline and confined runs are not from one boot")
        return v
    if baseline["pid"] == confined["pid"] and baseline["starttime_ticks"] == confined["starttime_ticks"]:
        v.fail("run", "baseline and confined are the same process")
        return v
    excluded = set(matrix["phases"][phase]["excluded_roles"])
    peer_roles = [r for r in spec["peer_roles"] if r not in excluded]
    required = [spec["profile_role"]] + peer_roles
    required += [r for r in matrix["role_required"] if matrix["role_required"][r] and r not in required and r not in excluded]
    before = check_inventory(v, matrix, inv_before, "before", required, phase, digest, boot)
    after = check_inventory(v, matrix, inv_after, "after", required, phase, digest, boot)
    if before and after:
        if identity(inv_before) != identity(inv_after):
            diff = sorted(identity(inv_before) ^ identity(inv_after))[:3]
            v.fail("inventory", f"process set changed between the before and after inventories (churn or pid reuse): {diff}")
        if inv_before["collected_end_ns"] > min(baseline["started_ns"], confined["started_ns"]):
            v.fail("inventory", "the before inventory was not finished before the probes started")
        if inv_after["collected_start_ns"] < max(baseline["ended_ns"], confined["ended_ns"]):
            v.fail("inventory", "the after inventory started before the probes ended")
    peers_used = {int(p) for pids in confined["peers"].values() for p in pids}
    if confined["peers"] != baseline["peers"] or set(confined["peers"]) - set(peer_roles):
        v.fail("peers", "the two runs were not given identical peer pids for exactly this phase's roles")
    for role in peer_roles:
        if not confined["peers"].get(role):
            v.fail(f"peers:{role}", "no worker pid was given for this role")
    for p in peers_used:
        a, b = before.get(p), after.get(p)
        if a is None or b is None or a["starttime"] != b["starttime"]:
            v.fail(f"peer-identity:{p}", "pid missing from an inventory or its start time changed (pid reuse)")
    for role, pids in confined["peers"].items():
        for p in pids:
            proc = before.get(int(p))
            if proc is not None and (proc["label"] != f"{role_profile[role]} (enforce)" or proc["uids"][1] != 33
                                     or proc["unit"] != matrix["role_units"][role]):
                v.fail(f"peer-identity:{p}", f"pid is not an enforced uid-33 {role} worker in {matrix['role_units'][role]}")
    base = {r["id"]: r for r in baseline["results"]}
    conf = {r["id"]: r for r in confined["results"]}
    if len(base) != len(baseline["results"]) or len(conf) != len(confined["results"]):
        v.fail("results", "duplicate result ids")
    expected, targets = expected_checks(matrix, profile, confined["peers"], phase)
    records, problems = parse_audit(audit_text)
    for problem in problems:
        v.fail("audit", problem)
    lifetime = (confined["started_ns"], confined["ended_ns"])
    child_pids = {r["pid"] for r in confined["results"]} | {r["child"] for r in confined["results"] if r.get("child")}
    own_records = []
    for r in records:
        if r.get("profile") != probe_profile:
            continue
        if r["_boot"] != boot:
            v.fail("audit", f"record for the probe profile is from another boot (serial {r['_serial'][2]})")
        elif not (lifetime[0] - EPS_NS <= r["_ts_ns"] <= lifetime[1] + EPS_NS):
            v.fail("audit", f"stale record for the probe profile outside this run's lifetime (serial {r['_serial'][2]})")
        elif abs(r["_ts_ns"] - r["_journal_ns"]) > 60_000_000_000:
            v.fail("audit", f"audit stamp and journal timestamp disagree (serial {r['_serial'][2]})")
        else:
            own_records.append(r)
    used: set = set()
    yama_masked = False
    explained: set = set()
    for ident in sorted(expected):
        if ident not in base or ident not in conf:
            v.fail(ident, "missing from baseline or confined results")
            continue
        kind, item = expected[ident]
        rb, rc = base[ident], conf[ident]
        b, c = rb["errno"], rc["errno"]
        if kind == "control" or (kind in ("file", "socket") and item["expect"] == "allow"):
            if item.get("optional") and b == errno.ENOENT and c == errno.ENOENT:
                v.note(ident, "UNPROVEN: optional target does not exist yet (checked again when it does)")
            elif b == 0 and c == 0:
                v.ok(ident, "allowed in both runs")
            else:
                v.fail(ident, f"allowed access failed (baseline={b} confined={c})")
            continue
        optional = kind in ("file", "socket") and item.get("optional", False)
        reached = (b == 0) or (kind == "peer-vm" and b == EFAULT)
        if kind == "peer-vm" and b in DENIED and c in DENIED:
            yama_masked = True
            v.note(ident, "baseline already refused (Yama/DAC): not attributable to AppArmor; valid only with the child calibration")
            continue
        if ident == "calibration:child-process_vm_readv" and b != 0:
            v.fail(ident, f"calibration read of a mapped byte did not succeed unconfined (errno {b}): EFAULT or refusal proves nothing")
            continue
        if not reached:
            if optional and c == 0:
                v.fail(ident, "confined access succeeded although the unconfined baseline could not reach it")
            elif optional:
                v.note(ident, f"UNPROVEN: baseline errno {b}; DAC masks the fixture (rule covered by the model only)")
            else:
                v.fail(ident, f"baseline could not reach the target (errno {b}): a confined denial would prove nothing")
            continue
        if c not in DENIED:
            v.fail(ident, f"confined access not refused (errno {c})")
            continue
        pid, t0, t1 = rc["pid"], rc["t0"], rc["t1"]
        child = rc.get("child")
        hit = None
        args = (own_records, used, probe_profile, boot, pid, t0, t1)
        if kind in ("file", "symlink"):
            hit = window_hit(*args, FILE_OPS[item["access"]], name=item["path"])
        elif kind == "socket":
            hit = window_hit(*args, {"connect", "open", "file_perm", "sendmsg"}, name=item["path"])
        elif kind == "abstract":
            hit = window_hit(*args, {"connect", "sendmsg"}, mention="wpcg-probe-")
        elif kind == "peer":
            target = f"/proc/{item['pid']}/{item['entry']}"
            hit = window_hit(*args, {"open", "readlink", "file_perm", "getattr"}, name={target, target.rstrip("/"), target.rstrip("/") + "/"})
            if hit is None:
                hit = window_hit(*args, {"ptrace"}, peer=role_profile[item["role"]])
        elif kind in ("peer-signal",):
            hit = window_hit(*args, {"signal"}, peer=role_profile[item["role"]])
        elif kind == "peer-vm":
            hit = window_hit(*args, {"ptrace"}, peer=role_profile[item["role"]])
        elif ident == "calibration:child-environ":
            hit = window_hit(*args, {"open"}, name=f"/proc/{child}/environ")
        elif ident == "calibration:child-maps":
            hit = window_hit(*args, {"ptrace"}, peer=probe_profile) or window_hit(*args, {"open"}, name=f"/proc/{child}/maps")
        else:
            hit = window_hit(*args, {"ptrace"}, peer=probe_profile)
        if hit is not None:
            used.add(hit)
            explained.add(ident)
            v.ok(ident, f"baseline={b} confined={c} audit=DENIED(serial {own_records[hit]['_serial'][2]})")
        else:
            v.fail(ident, "refused by errno but no AppArmor DENIED record binds to this call (profile, pid, operation, target, boot, own time window)")
    if yama_masked and "calibration:child-process_vm_readv" not in explained:
        v.fail("calibration", "child process_vm_readv calibration did not prove an AppArmor ptrace denial; Yama-masked peer results are void")
    f0b, f0c = baseline["fd0"], confined["fd0"]
    if not (f0b.get("state") == "read" and f0b.get("errno") == 0 and f0b.get("marker_ok") is True):
        v.fail("fd0", f"baseline did not read the inherited canary on fd 0: {f0b}")
    elif f0c.get("state") != "read":
        v.fail("fd0", f"confined run did not exercise an inherited canary on fd 0: {f0c}")
    elif f0c.get("errno") == 0 and f0c.get("marker_ok") is True:
        v.note("fd0", "INHERITED-FD-READABLE: AppArmor did not revoke the inherited descriptor; safety rests on units "
               "inheriting none (fd inventory), never on exec-time revocation")
        v.ok("fd0", "recorded: readable")
    else:
        v.note("fd0", f"inherited descriptor refused (errno {f0c.get('errno')}); not relied upon")
        v.ok("fd0", "recorded: refused")
    scm_check(v, matrix, baseline, confined, own_records, used, probe_profile, boot, ack_fd_transfer)
    windows = [(r["pid"], r["t0"] - LEFTOVER_NS, r["t1"] + LEFTOVER_NS) for r in confined["results"] if r["errno"] in DENIED]
    windows.append((confined["pid"], confined["scm"]["t0"] - LEFTOVER_NS, confined["scm"]["t1"] + LEFTOVER_NS))
    for index, r in enumerate(own_records):
        if index in used:
            continue
        if r["pid"] not in child_pids | {confined["pid"]}:
            v.fail("unexplained-denial", f"AppArmor denied pid {r['pid']}, which is not the probe or one of its children: {r.get('operation')}")
        elif not any(p == r["pid"] and lo <= r["_ts_ns"] <= hi for p, lo, hi in windows):
            v.fail("unexplained-denial", f"AppArmor denied something no check expected: {r.get('operation')}:{r.get('name') or r.get('peer')}")
    return v


def scm_check(v: Verdict, matrix: dict, baseline: dict, confined: dict, records: list, used: set, probe_profile: str,
              boot: str, ack: bool) -> None:
    """Descriptor transfer from an unconfined same-UID peer: only an actual denial, with its own event, passes."""
    canary, sock = matrix["scm"]["canary"], confined.get("scm_socket")
    sb, sc, pid = baseline["scm"], confined["scm"], confined["pid"]
    if not (sb.get("state") == "received" and sb.get("errno") == 0 and sb.get("read_ok") is True):
        v.fail("scm-rights", f"baseline did not receive and read the canary descriptor: {sb}")
        return
    state, stage, err = sc.get("state"), sc.get("stage"), sc.get("errno")
    t0, t1 = sc.get("t0", 0), sc.get("t1", 0)
    if state == "received" and err == 0 and sc.get("read_ok") is True:
        if ack:
            v.note("scm-rights", "descriptor transfer bypass observed and explicitly acknowledged")
        else:
            v.fail("scm-rights", "a confined process read a descriptor handed over by an unconfined same-UID peer")
        return
    if err not in DENIED:
        v.fail("scm-rights", f"inconclusive: state {state!r} stage {stage!r} errno {err!r} is not a denial (ENOENT, EIO and "
               "'nothing received' prove nothing about AppArmor)")
        return
    if state == "received" and stage == "read":
        ops, name = {"file_receive", "file_perm", "open", "read"}, canary
    elif state == "channel-failed" and stage == "recv":
        ops, name = {"file_receive"}, canary
    elif state == "channel-failed" and stage == "connect":
        ops, name = {"connect", "open", "file_perm"}, sock
    else:
        v.fail("scm-rights", f"unexplained descriptor-transfer outcome: {sc}")
        return
    hit = window_hit(records, used, probe_profile, boot, pid, t0, t1, ops, name=name)
    if hit is None:
        v.fail("scm-rights", f"denial errno {err} at stage {stage} has no AppArmor DENIED record for this exact stage "
               f"(operation in {sorted(ops)}, target {name!r}, probe pid, own window)")
        return
    used.add(hit)
    v.ok("scm-rights", f"{state}/{stage} refused (errno {err}) with its own audit event")


def main(argv: list) -> int:
    flags = [a for a in argv if a.startswith("--")]
    args = [a for a in argv if not a.startswith("--")]
    if len(args) != 9 or set(flags) - {"--ack-fd-transfer-bypass"}:
        print(__doc__, file=sys.stderr)
        return 2
    load = lambda p: json.load(open(p, encoding="utf-8"))  # noqa: E731
    matrix_text = open(args[0], encoding="utf-8").read()
    verdict = verify(matrix_text, args[1], args[2], load(args[3]), load(args[4]), load(args[5]), load(args[6]),
                     open(args[7], encoding="utf-8").read(), "--ack-fd-transfer-bypass" in flags)
    for line in verdict.passes + verdict.notes + verdict.failures:
        print(line)
    if verdict.failures:
        print(f"REJECTED: {len(verdict.failures)} failure(s)", file=sys.stderr)
        return 1
    print("EVIDENCE-CONSISTENT for this run only: kernel enforcement is shown by the PASS lines above, not by this tool.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
