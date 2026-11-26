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
       [--ack-unproven=<matrix-sha>:<boot-id>:<phase>:<profile>:<evidence-sha>:<profile:id,...>]
"""
from __future__ import annotations

import errno
import hashlib
import json
import re
import sys
from pathlib import Path, PurePosixPath

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
# Production FPM and Nginx are the only accepted service roles. The distro php8.5-fpm.service is scanned as a forbidden sentinel.
# The /run/php entries are exact pool socket/pid paths: GENERIC_FD allows /run/php, so without them another role's FastCGI
# socket path would pass as "generic".
RUNTIME_PREFIXES = {
    "production": ("/run/php/wpcaregrid-production-php8.5-fpm.sock", "/etc/php/8.5/fpm-wpcaregrid-production"),
}
FOREIGN_PREFIXES = ("/var/www/wpcaregrid-staging-new", "/var/lib/wpcaregrid-deploy/staging-next",
                    "/var/www/wpcaregrid", "/var/lib/wpcaregrid")
ROLE_PREFIXES = {
    "production": ("/var/www/wpcaregrid-production", "/var/lib/wpcaregrid-deploy/production", "/var/log/wpcaregrid-production")
                  + RUNTIME_PREFIXES["production"],
    "nginx": ("/var/www/wpcaregrid-production/releases",
              "/etc/letsencrypt/live/wpcaregrid.com", "/etc/letsencrypt/archive/wpcaregrid.com",
              "/etc/nginx", "/var/log/nginx", "/var/lib/nginx", "/run/nginx"),
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


def identity_problems(matrix: dict) -> list:
    """Fail closed on a matrix that lets two roles share a unit, a pool socket, a pool directory or a profile label.

    Service roles are distinguished by exact (label, unit), never by executable path."""
    problems: list = []
    units = matrix.get("role_units", {})
    if len(set(units.values())) != len(units):
        problems.append("two roles share one systemd unit")
    runtime = matrix.get("role_runtime", {}).get("roles")
    if not isinstance(runtime, dict) or not runtime:
        return problems + ["matrix has no role_runtime: pool/socket identity is not declared"]
    for field in ("socket", "pool_dir"):
        values = [r.get(field) for r in runtime.values()]
        if any(not isinstance(v, str) or not v for v in values) or len(set(values)) != len(values):
            problems.append(f"two FPM roles share one {field} (or it is missing)")
    for role, spec in runtime.items():
        if units.get(role) is None:
            problems.append(f"role_runtime role {role!r} has no unit")
        for prefix_role, prefixes in ROLE_PREFIXES.items():
            if prefix_role != role and (spec.get("socket") in prefixes or spec.get("pool_dir") in prefixes):
                problems.append(f"role {role} socket/pool directory is attributed to {prefix_role} in the descriptor policy")
        if spec.get("socket") not in ROLE_PREFIXES.get(role, ()):
            problems.append(f"role {role} socket is missing from the descriptor policy")
    profiles = matrix.get("role_profiles", {})
    fpm_roles = [r for r in runtime if r in profiles]
    if len({profiles[r] for r in fpm_roles}) != len(fpm_roles):
        problems.append("two FPM roles share one AppArmor profile")
    return problems


def under(target: str, prefix: str) -> bool:
    prefix = prefix.rstrip("/")
    return target == prefix or target.startswith(prefix + "/")


def fd_allowed(role: str, target: str) -> bool:
    target = target.removesuffix(" (deleted)")
    if any(under(target, p) for p in CONTROL_PREFIXES):
        return False
    if role == "production":
        # Production FPM and Nginx intentionally share read access to the
        # immutable release tree and FastCGI socket. Deny only Nginx-exclusive
        # control/config/log/TLS paths instead of treating the shared release
        # prefix as foreign.
        nginx_private = ("/etc/nginx", "/var/log/nginx", "/var/lib/nginx", "/run/nginx",
                         "/etc/letsencrypt/live/wpcaregrid.com", "/etc/letsencrypt/archive/wpcaregrid.com")
        if any(under(target, prefix) for prefix in nginx_private):
            return False
    elif role != "nginx":
        for other, prefixes in ROLE_PREFIXES.items():
            if other != role and any(under(target, p) for p in prefixes):
                return False
    # Do not let the generic runtime prefixes bless foreign PHP pools, arbitrary
    # TLS material, or another FastCGI socket inherited by the production master.
    sensitive_generic = role == "production" and any(
        under(target, prefix) for prefix in ("/etc/php", "/etc/ssl", "/run/php"))
    if not sensitive_generic and any(g.match(target) for g in GENERIC_FD):
        return True
    return any(under(target, p) for p in ROLE_PREFIXES[role])


def fd_ok(role: str, target: str) -> bool:
    """Descriptor policy per role. Edge and job roles are checked for secret fragments, FPM roles by tree."""
    target = target.removesuffix(" (deleted)")
    if any(under(target, p) for p in CONTROL_PREFIXES):
        return False
    if role == "nginx":
        # The descriptor target is a kernel-reported path. Reject path tricks first,
        # then use component-boundary allowlists; broad /usr or arbitrary /public
        # prefixes would let unrelated files pass the live inventory.
        if not target.startswith("/") or "\x00" in target or "//" in target \
                or any(part in (".", "..") for part in target.split("/")):
            return bool(GENERIC_FD[0].fullmatch(target) or GENERIC_FD[1].match(target)
                        or GENERIC_FD[2].fullmatch(target))
        if GENERIC_FD[0].fullmatch(target) or GENERIC_FD[1].match(target) or GENERIC_FD[2].fullmatch(target):
            return True
        if target in {"/etc/nginx/nginx.conf", "/etc/nginx/mime.types", "/etc/nginx/fastcgi_params",
                      "/etc/nginx/sites-available/wpcaregrid-production", "/etc/nginx/sites-enabled/wpcaregrid-production",
                      "/etc/mime.types", "/etc/localtime", "/run/nginx.pid"}:
            return True
        if under(target, "/etc/nginx/modules-enabled") and target.endswith(".conf"):
            return True
        if under(target, "/usr/lib/nginx/modules") and target.count("/") == 5:
            return True
        if under(target, "/usr/lib/x86_64-linux-gnu") and len(PurePosixPath(target).parts) == 5:
            return True
        if under(target, "/usr/share/nginx") and len(PurePosixPath(target).parts) >= 4:
            return True
        if under(target, "/var/log/nginx") or under(target, "/var/lib/nginx") or under(target, "/run/nginx"):
            return True
        if target in {"/run/php/wpcaregrid-production-php8.5-fpm.sock"}:
            return True
        if under(target, "/etc/letsencrypt/archive/wpcaregrid.com"):
            return bool(re.fullmatch(r"/etc/letsencrypt/archive/wpcaregrid\.com/(fullchain|privkey)[0-9]+\.pem", target))
        if target in {"/etc/letsencrypt/live/wpcaregrid.com/fullchain.pem",
                      "/etc/letsencrypt/live/wpcaregrid.com/privkey.pem"}:
            return True
        parts = PurePosixPath(target).parts
        return (len(parts) >= 8 and parts[:4] == ("/", "var", "www", "wpcaregrid-production")
                and parts[4] == "releases" and parts[6] == "public")
    return fd_allowed(role, target)


class Verdict:
    def __init__(self) -> None:
        self.failures: list = []
        self.passes: list = []
        self.notes: list = []
        self.unproven: list = []

    def ok(self, ident: str, detail: str = "") -> None:
        self.passes.append(f"PASS {ident} {detail}".rstrip())

    def fail(self, ident: str, detail: str) -> None:
        self.failures.append(f"FAIL {ident}: {detail}")

    def note(self, ident: str, detail: str) -> None:
        self.notes.append(f"NOTE {ident}: {detail}")

    def note_unproven(self, ident: str, detail: str) -> None:
        self.unproven.append(ident)
        self.note(ident, f"UNPROVEN: {detail}")


def enforced_profile_labels(profile: str) -> tuple:
    """Exact accepted labels: direct enforce, or the observed unconfined//&profile enforce stack."""
    return (f"{profile} (enforce)", f"unconfined//&{profile} (enforce)")


def matches_enforced_profile_label(label: str, profile: str) -> bool:
    return label in enforced_profile_labels(profile)


def role_label_map(role_profiles: dict) -> dict:
    labels: dict = {}
    for role, profile in role_profiles.items():
        for label in enforced_profile_labels(profile):
            labels.setdefault(label, []).append(role)
    return labels


def optional_allow_unproven(item: dict, baseline_errno: int, confined_errno: int) -> bool:
    """An optional allow probe is inconclusive when DAC blocks both attempts."""
    return bool(item.get("optional") and baseline_errno == confined_errno
                and baseline_errno in (errno.ENOENT, errno.EACCES, errno.EPERM))


def identity(inv: dict) -> frozenset:
    return frozenset((p["pid"], p["starttime"], p["label"], p["unit"], tuple(p["uids"]), tuple(p["gids"]), tuple(p.get("groups", [])))
                     for p in inv["processes"])


def check_inventory(v: Verdict, matrix: dict, inv: dict, phase: str, required_roles: list, probe_phase: str,
                    matrix_hash: str, boot: str) -> dict:
    """Every uid-33 process and every service master must map to exactly one permitted class; anything else blocks."""
    label_roles = role_label_map(matrix["role_profiles"])
    probe_labels = {label for p in matrix["profiles"] for label in enforced_profile_labels(f"{p}-probe")}
    transient = {c["label"]: c for c in matrix.get("transient_classes", [])}
    known_units = set(matrix.get("inventory_units", matrix["role_units"]))
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
            v.fail(f"inventory:{phase}", f"pid {proc['pid']} still carries a probe-profile label ({proc['label']}): the probe profile grants python and "
                   "production secrets, so no probe process or loaded probe profile may exist when an inventory is taken")
            continue
        if proc["label"] in transient:
            cls = transient[proc["label"]]
            bad = []
            if proc.get("exe") != cls["exe"] or proc.get("argv0") != cls["argv0"]:
                bad.append(f"exe/argv0 {proc.get('exe')!r}/{proc.get('argv0')!r}")
            if proc["uids"] != cls["uids"] or proc["gids"] != cls["gids"] or set(proc.get("groups", [])) - set(cls["groups"]):
                bad.append(f"ids uid {proc['uids']} gid {proc['gids']} groups {proc.get('groups')}")
            if proc["unit"] is not None or not re.match(cls["cgroup_regex"], str(proc.get("cgroup", ""))):
                bad.append(f"cgroup {proc.get('cgroup')!r} unit {proc['unit']!r} (must be the root-console session scope, never a service)")
            if not re.fullmatch(r"[0-9a-f]{64}", str(proc.get("cmdline_sha256", ""))):
                bad.append("no command line hash")
            if not isinstance(proc.get("age_s"), (int, float)) or proc["age_s"] > cls["max_age_seconds"]:
                bad.append(f"age {proc.get('age_s')!r}s is not short-lived (limit {cls['max_age_seconds']}s)")
            for target in proc["fds"]:
                db_socket = target == "/run/mysqld/wpcaregrid-production.sock"
                allowed_db_socket = cls["class"] == "production-db-probe" and db_socket
                if (any(under(target, p) for p in FOREIGN_PREFIXES + RUNTIME_PREFIXES["production"])
                        or (any(under(target, p) for p in CONTROL_PREFIXES) and not allowed_db_socket)):
                    bad.append(f"descriptor {target!r}")
            if bad:
                v.fail(f"inventory:{phase}", f"pid {proc['pid']} carries the {cls['class']} label but is not the permitted class: " + "; ".join(bad))
            elif probe_phase not in cls["phases"]:
                v.fail(f"inventory:{phase}", f"pid {proc['pid']}: a {cls['class']} process exists in phase {probe_phase!r}: production is not allowed to run PHP yet")
            else:
                v.note(f"inventory:{phase}", f"permitted transient {cls['class']} pid {proc['pid']} (short-lived root-console child)")
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
        production_labels = set(enforced_profile_labels(matrix["role_profiles"]["production"]))
        if inv["units"][production_unit]["pids"] or any(p["unit"] == production_unit or p["label"] in production_labels
                                                        for p in inv["processes"]):
            v.fail(f"inventory:{phase}", "the production unit already has processes: the pre-production gate requires it absent")
        surface = inv.get("production_surface")
        if not isinstance(surface, dict) or surface.get("complete") is not True or surface.get("real_files"):
            v.fail(f"inventory:{phase}", "real (non-fixture) production files exist or the scan is missing: production secrets must not "
                   f"exist before every peer is confined ({(surface or {}).get('real_files', [])[:3]}; "
                   f"scan_reasons={(surface or {}).get('reasons', [])[:3]})")
    return by_pid


def window_hit(records: list, used: set, probe_profile: str, boot: str, pid: int, t0: int, t1: int, ops: set,
               name=None, peer: str | None = None, mention: str | None = None, fields: dict | None = None):
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
        if fields is not None and any(r.get(key) != value for key, value in fields.items()):
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
    for net in spec.get("network_checks", []):
        expected[f"net:{net['id']}"] = ("net", net)
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
    for problem in identity_problems(matrix):
        v.fail("identity", problem)
    if v.failures:
        return v
    if phase not in matrix["phases"]:
        v.fail("phase", f"unknown phase {phase!r}")
        return v
    if phase == "pre" and profile == "wpcaregrid-php-fpm-production":
        v.fail("phase", "the production profile can only be accepted after the unrouted production unit starts (phase post)")
        return v
    probe_profile = f"{profile}-probe"
    role_profile = matrix["role_profiles"]
    digest = hashlib.sha256(matrix_text.encode()).hexdigest()
    for name, doc in (("baseline", baseline), ("confined", confined)):
        label_ok = doc.get("label") == "unconfined" if name == "baseline" else matches_enforced_profile_label(doc.get("label", ""), f"{probe_profile}")
        if doc.get("schema") != 2 or doc.get("mode") != name or doc.get("profile") != profile \
                or not label_ok or doc.get("matrix_sha256") != digest:
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
    required = [r for r in [spec["profile_role"]] if r in matrix["role_units"]] + peer_roles
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
            if proc is not None and (not matches_enforced_profile_label(proc["label"], role_profile[role]) or proc["uids"][1] != 33
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
        if kind == "socket" and item.get("banner"):
            # This sibling socket is a root-run harmless canary, not an optional production service.
            # A successful connect is insufficient: baseline must read the exact constant banner so a
            # stale pathname, wrong listener, or ECONNREFUSED can never masquerade as reachability.
            if rb.get("banner_ok") is not True:
                v.fail(ident, f"unconfined baseline did not read the exact sibling canary banner (errno={b})")
                continue
            if rc.get("banner_ok") is not False:
                v.fail(ident, "confined probe result is missing its explicit denied-canary outcome")
                continue
        if kind == "control" or (kind in ("file", "socket", "net") and item["expect"] == "allow"):
            if optional_allow_unproven(item, b, c):
                reason = "does not exist yet" if b == errno.ENOENT else "is DAC-inaccessible"
                v.note_unproven(f"{profile}:{ident}", f"optional target {reason} (checked again when it is reachable)")
            elif b == 0 and c == 0:
                v.ok(ident, "allowed in both runs")
            else:
                v.fail(ident, f"allowed access failed (baseline={b} confined={c})")
            continue
        optional = kind in ("file", "socket", "net") and item.get("optional", False)
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
                v.note_unproven(f"{profile}:{ident}", f"baseline errno {b}; DAC masks the fixture (rule covered by the model only)")
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
        elif kind == "net":
            family = {"AF_INET": "inet", "AF_INET6": "inet6", "AF_UNIX": "unix", "AF_NETLINK": "netlink"}[item["family"]]
            sock_type = item["type"].removeprefix("SOCK_").lower()
            action_ops = {"create": {"create", "socket_create"}, "bind": {"bind", "socket_bind"}, "connect": {"connect", "socket_connect"}}
            ops = action_ops.get(item["action"], set())
            hit = window_hit(*args, ops, fields={"family": family, "sock_type": sock_type})
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
    ack_flags = [a for a in flags if a.startswith("--ack-unproven=")]
    if len(args) != 8 or len(ack_flags) > 1 or any(a not in {"--ack-fd-transfer-bypass"} and not a.startswith("--ack-unproven=") for a in flags):
        print(__doc__, file=sys.stderr)
        return 2
    ack_payload = ack_flags[0].split("=", 1)[1] if ack_flags else None
    def read_json(path: str) -> dict:
        with open(path, encoding="utf-8") as source:
            return json.load(source)

    matrix_text = Path(args[0]).read_text(encoding="utf-8")
    baseline, confined = read_json(args[3]), read_json(args[4])
    inv_before, inv_after = read_json(args[5]), read_json(args[6])
    audit_text = Path(args[7]).read_text(encoding="utf-8")
    evidence_hashes = {
        "baseline": hashlib.sha256(Path(args[3]).read_bytes()).hexdigest(),
        "confined": hashlib.sha256(Path(args[4]).read_bytes()).hexdigest(),
        "inventory_before": hashlib.sha256(Path(args[5]).read_bytes()).hexdigest(),
        "inventory_after": hashlib.sha256(Path(args[6]).read_bytes()).hexdigest(),
        "audit": hashlib.sha256(Path(args[7]).read_bytes()).hexdigest(),
    }
    evidence_digest = hashlib.sha256(json.dumps(
        evidence_hashes, sort_keys=True, separators=(",", ":")).encode("utf-8")).hexdigest()
    verdict = verify(matrix_text, args[1], args[2], baseline, confined, inv_before, inv_after,
                     audit_text, "--ack-fd-transfer-bypass" in flags)
    for line in verdict.passes + verdict.notes + verdict.failures:
        print(line)
    if verdict.unproven:
        print(f"UNPROVEN_COUNT={len(verdict.unproven)}")
        expected_ack = sorted(set(verdict.unproven))
        print("UNPROVEN_IDS=" + ",".join(expected_ack))
        print("UNPROVEN_MATRIX_SHA256=" + hashlib.sha256(matrix_text.encode("utf-8")).hexdigest())
        print("UNPROVEN_BOOT_ID=" + str(inv_before.get("boot_id", "UNKNOWN")))
        matrix_digest = hashlib.sha256(matrix_text.encode("utf-8")).hexdigest()
        boot_id = str(inv_before.get("boot_id", "UNKNOWN"))
        print("UNPROVEN_EVIDENCE_SHA256=" + evidence_digest)
        required_payload = f"{matrix_digest}:{boot_id}:{args[2]}:{args[1]}:{evidence_digest}:{','.join(expected_ack)}"
        if ack_payload is None:
            print("ACCEPTANCE=HOLD;REASON=owner_unproven_ack_required", file=sys.stderr)
            print("ACK_REQUIRED=--ack-unproven=" + required_payload, file=sys.stderr)
            return 3
        if ack_payload != required_payload:
            print("ACCEPTANCE=HOLD;REASON=unproven_ack_mismatch", file=sys.stderr)
            return 1
        print("OWNER_UNPROVEN_ACKNOWLEDGMENT=" + ",".join(expected_ack))
    elif ack_payload is not None:
        print("ACCEPTANCE=HOLD;REASON=unproven_ack_without_unproven_ids", file=sys.stderr)
        return 1
    if verdict.failures:
        print(f"REJECTED: {len(verdict.failures)} failure(s)", file=sys.stderr)
        return 1
    print("EVIDENCE-CONSISTENT for this run only: kernel enforcement is shown by the PASS lines above, not by this tool.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
