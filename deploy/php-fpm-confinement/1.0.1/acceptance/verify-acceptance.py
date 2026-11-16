#!/usr/bin/env python3
"""Offline verdict for one live acceptance run. Run as root, after the probes.

A probe errno of EACCES/EPERM is NOT evidence of AppArmor on its own. A denial
counts only when all of the following hold for the same check:

1. the unconfined baseline of the same probe reached the target (so DAC, Yama and
   existence do not explain the denial; baseline-masked checks are inconclusive),
2. the confined run was refused with EACCES/EPERM,
3. a kernel audit record ``apparmor="DENIED"`` names the probe profile, the probe
   pid, an operation appropriate to the check and the target (or ptrace/signal peer),
4. the same-UID peer inventory taken before and after the run is complete, every
   www-data process is enforce-attached to a confinement profile, and every peer pid
   the probe used is in both inventories with the same start time.

Anything missing, duplicated, unexpected or unexplained is a failure. Exit 0 means
only that THIS run's evidence is consistent; it is a record, not a standing guarantee.
Usage: verify-acceptance.py <matrix.json> <profile> <baseline.json> <confined.json>
       <inventory-before.json> <inventory-after.json> <audit.txt> [--ack-fd-transfer-bypass]
"""
from __future__ import annotations

import errno
import hashlib
import json
import re
import sys

DENIED = (errno.EACCES, errno.EPERM)
# Same-UID processes that may stay unconfined. Empty on purpose: accepting nginx workers, Supervisor
# CLI workers or the scheduler is an Owner decision, not a default (see the runbook).
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


def parse_audit(text: str) -> list:
    records = []
    for line in text.splitlines():
        if 'apparmor="DENIED"' not in line:
            continue
        record = {m.group(1): (m.group(2) if m.group(2) is not None else m.group(3)) for m in KV.finditer(line)}
        if "pid" in record and str(record["pid"]).isdigit():
            record["pid"] = int(record["pid"])
            records.append(record)
    return records


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


def fd_ok(matrix: dict, role: str, target: str) -> bool:
    """Descriptor policy per role. Edge and job roles are checked for secret fragments, FPM roles by tree."""
    target = target.removesuffix(" (deleted)")
    if any(under(target, p) for p in CONTROL_PREFIXES):
        return False
    if role == "nginx":
        return not any(f in target for f in ("/shared/", "/.env", "/storage/", "/bootstrap/", "/state/", "/database-backups"))
    if role.startswith("jobs-"):
        return not any(under(target, p) for p in ROLE_PREFIXES["production"])
    return fd_allowed(role, target)


def check_inventory(v: Verdict, matrix: dict, inv: dict, phase: str, required_roles: list) -> dict:
    """Every uid-33 process must map to exactly one explicitly permitted class; anything else blocks."""
    label_roles: dict = {}
    for role, profile in matrix["role_profiles"].items():
        label_roles.setdefault(f"{profile} (enforce)", []).append(role)
    probe_labels = {f"{p}-probe (enforce)" for p in matrix["role_profiles"].values()}
    if not isinstance(inv, dict) or inv.get("schema") != 1 or inv.get("complete") is not True:
        v.fail(f"inventory:{phase}", f"peer inventory incomplete or malformed: {inv.get('reasons') if isinstance(inv, dict) else 'n/a'}")
        return {}
    by_pid = {}
    seen: dict = {}
    for proc in inv["processes"]:
        by_pid[proc["pid"]] = proc
        if proc["label"] in probe_labels:
            continue
        roles = [r for r in label_roles.get(proc["label"], []) if matrix["role_units"][r] == proc["unit"]]
        if len(roles) == 1:
            role = roles[0]
            seen[role] = seen.get(role, 0) + 1
            for target in proc["fds"]:
                if not fd_ok(matrix, role, target):
                    v.fail(f"fd-inventory:{phase}", f"pid {proc['pid']} ({role}) holds {target!r}")
            continue
        if label_roles.get(proc["label"]):
            v.fail(f"inventory:{phase}", f"pid {proc['pid']} carries label {proc['label']!r} outside its permitted unit ({proc['unit']!r})")
            continue
        permitted = [c for c in matrix["permitted_unconfined_classes"]
                     if proc["label"] == "unconfined" and proc["unit"] == c["unit"] and proc["comm"] in c["comm"]]
        if permitted:
            v.note(f"inventory:{phase}", f"permitted unconfined class {permitted[0]['class']} pid {proc['pid']} ({permitted[0]['condition']})")
            continue
        if proc["comm"] in ACCEPTED_UNCONFINED_SAME_UID_COMM:
            v.note(f"inventory:{phase}", f"accepted unconfined {proc['comm']} pid {proc['pid']}")
            continue
        v.fail(f"inventory:{phase}", f"same-UID process pid {proc['pid']} comm {proc['comm']!r} label {proc['label']!r} unit "
               f"{proc['unit']!r} maps to no permitted class: it can read the other environment's secrets")
    for role in required_roles:
        unit = matrix["role_units"][role]
        if matrix["role_required"].get(role) and (not inv["units"].get(unit, {}).get("present") or seen.get(role, 0) < 1):
            v.fail(f"inventory:{phase}", f"role {role} ({unit}) has no identified www-data process: peer enumeration incomplete")
    return by_pid


def correlate(records: list, probe_profile: str, pid: int, ops: set, name: str | None = None,
              peer: str | None = None, name_prefix: str | None = None, mention: str | None = None) -> bool:
    for r in records:
        if r.get("profile") != probe_profile or r["pid"] != pid or r.get("operation") not in ops:
            continue
        if name is not None and r.get("name") != name:
            continue
        if name_prefix is not None and not str(r.get("name", "")).startswith(name_prefix):
            continue
        if peer is not None and not str(r.get("peer", "")).startswith(peer):
            continue
        if mention is not None and mention not in " ".join(str(x) for x in r.values()):
            continue
        return True
    return False


def expected_checks(matrix: dict, profile: str, peers: dict) -> tuple:
    """Every result id the probe must report, with its kind. Shared contract between probe and verdict."""
    spec = matrix["profiles"][profile]
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


def verify(matrix_text: str, profile: str, baseline: dict, confined: dict, inv_before: dict, inv_after: dict,
           audit_text: str, ack_fd_transfer: bool = False) -> Verdict:
    v = Verdict()
    matrix = json.loads(matrix_text)
    spec = matrix["profiles"][profile]
    probe_profile = f"{profile}-probe"
    role_profile = matrix["role_profiles"]
    digest = hashlib.sha256(matrix_text.encode()).hexdigest()
    for name, doc, label in (("baseline", baseline, "unconfined"), ("confined", confined, f"{probe_profile} (enforce)")):
        if doc.get("schema") != 1 or doc.get("mode") != name or doc.get("profile") != profile \
                or doc.get("label") != label or doc.get("matrix_sha256") != digest:
            v.fail(f"run:{name}", f"wrong mode/profile/label/matrix hash (label {doc.get('label')!r})")
            return v
    records = parse_audit(audit_text)
    pid = confined["pid"]
    required = [spec["profile_role"]] + list(spec["peer_roles"])
    required = required + [r for r in matrix["role_required"] if matrix["role_required"][r] and r not in required]
    before = check_inventory(v, matrix, inv_before, "before", required)
    after = check_inventory(v, matrix, inv_after, "after", required)
    peers_used = {int(p) for pids in confined["peers"].values() for p in pids}
    for role in spec["peer_roles"]:
        if not confined["peers"].get(role) or confined["peers"] != baseline["peers"]:
            v.fail(f"peers:{role}", "probe was not given identical, non-empty worker pids for this role in both runs")
    for p in peers_used:
        a, b = before.get(p), after.get(p)
        if a is None or b is None or a["starttime"] != b["starttime"]:
            v.fail(f"peer-identity:{p}", "pid missing from an inventory or its start time changed (pid reuse)")
    for role, pids in confined["peers"].items():
        for p in pids:
            proc = before.get(int(p))
            if proc is not None and (proc["label"] != f"{role_profile[role]} (enforce)" or proc["uids"][1] != 33):
                v.fail(f"peer-identity:{p}", f"pid is not an enforced uid-33 {role} worker")
    base = {r["id"]: r for r in baseline["results"]}
    conf = {r["id"]: r for r in confined["results"]}
    if len(base) != len(baseline["results"]) or len(conf) != len(confined["results"]):
        v.fail("results", "duplicate result ids")
    expected, targets = expected_checks(matrix, profile, confined["peers"])
    children = {r.get("child") for r in confined["results"] if r.get("child")}
    explained: set = set()
    yama_masked = False
    for ident in sorted(expected):
        if ident not in base or ident not in conf:
            v.fail(ident, "missing from baseline or confined results")
            continue
        kind, item = expected[ident]
        b, c = base[ident]["errno"], conf[ident]["errno"]
        if kind == "control" or (kind in ("file", "socket") and item["expect"] == "allow"):
            if item.get("optional") and b == errno.ENOENT and c == errno.ENOENT:
                v.note(ident, "UNPROVEN: optional target does not exist yet (checked again when it does)")
            elif b == 0 and c == 0:
                v.ok(ident, "allowed in both runs")
            else:
                v.fail(ident, f"allowed access failed (baseline={b} confined={c})")
            continue
        optional = kind in ("file", "socket") and item.get("optional", False)
        if kind == "peer-vm" and b in DENIED and c in DENIED:
            yama_masked = True
            v.note(ident, "baseline already refused (Yama/DAC): not attributable to AppArmor; valid only with the child calibration")
            continue
        if b != 0:
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
        child = conf[ident].get("child")
        if kind in ("file", "symlink"):
            found = correlate(records, probe_profile, pid, FILE_OPS[item["access"]], name=item["path"])
        elif kind == "socket":
            found = correlate(records, probe_profile, pid, {"connect", "open", "file_perm", "sendmsg"}, name=item["path"])
        elif kind == "abstract":
            found = correlate(records, probe_profile, pid, {"connect", "sendmsg"}, mention="wpcg-probe-")
        elif kind == "peer":
            found = correlate(records, probe_profile, pid, {"open", "readlink", "file_perm"}, name_prefix=f"/proc/{item['pid']}/") \
                or correlate(records, probe_profile, pid, {"ptrace"}, peer=role_profile[item["role"]])
        elif kind == "peer-signal":
            found = correlate(records, probe_profile, pid, {"signal"}, peer=role_profile[item["role"]])
        elif kind == "peer-vm":
            found = correlate(records, probe_profile, pid, {"ptrace"}, peer=role_profile[item["role"]])
        elif ident == "calibration:child-environ":
            found = correlate(records, probe_profile, pid, {"open"}, name=f"/proc/{child}/environ")
        elif ident == "calibration:child-maps":
            found = correlate(records, probe_profile, pid, {"ptrace"}, peer=probe_profile) \
                or correlate(records, probe_profile, pid, {"open"}, name=f"/proc/{child}/maps")
        else:
            found = correlate(records, probe_profile, pid, {"ptrace"}, peer=probe_profile)
        if found:
            explained.add(ident)
            v.ok(ident, f"baseline=0 confined={c} audit=DENIED")
        else:
            v.fail(ident, "refused by errno but no matching AppArmor DENIED audit record (profile, pid, operation, target)")
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
    sb, sc = baseline["scm"], confined["scm"]
    own_records = [r for r in records if r.get("profile") == probe_profile and r["pid"] == pid]
    if not (sb.get("state") == "received" and sb.get("errno") == 0 and sb.get("read_ok") is True):
        v.fail("scm-rights", f"baseline did not receive and read the canary descriptor: {sb}")
    elif sc.get("state") == "received" and sc.get("errno") == 0 and sc.get("read_ok") is True:
        if ack_fd_transfer:
            v.note("scm-rights", "descriptor transfer bypass observed and explicitly acknowledged")
        else:
            v.fail("scm-rights", "a confined process read a descriptor handed over by an unconfined same-UID peer")
    elif sc.get("state") == "received" or (sc.get("state") == "channel-failed" and sc.get("errno") in DENIED and own_records):
        v.ok("scm-rights", f"transfer or read refused ({sc})")
    else:
        v.fail("scm-rights", f"channel not exercised or unexplained: {sc}")
    known = peers_used | {c for c in children if c}
    for r in own_records:
        op, name = r.get("operation"), str(r.get("name", ""))
        if op in ("ptrace", "signal") and any(str(r.get("peer", "")).startswith(p) for p in role_profile.values()) \
                or any(name.startswith(f"/proc/{k}/") or name == f"/proc/{k}" for k in known) \
                or name in targets or "wpcg-probe-" in " ".join(str(x) for x in r.values()) \
                or (op == "file_receive"):
            continue
        v.fail("unexplained-denial", f"AppArmor denied something no check expected: {op}:{name or r.get('peer')}")
    return v


def main(argv: list) -> int:
    flags = [a for a in argv if a.startswith("--")]
    args = [a for a in argv if not a.startswith("--")]
    if len(args) != 7 or set(flags) - {"--ack-fd-transfer-bypass"}:
        print(__doc__, file=sys.stderr)
        return 2
    load = lambda p: json.load(open(p, encoding="utf-8"))  # noqa: E731
    matrix_text = open(args[0], encoding="utf-8").read()
    verdict = verify(matrix_text, args[1], load(args[2]), load(args[3]), load(args[4]), load(args[5]),
                     open(args[6], encoding="utf-8").read(), "--ack-fd-transfer-bypass" in flags)
    for line in verdict.passes + verdict.notes + verdict.failures:
        print(line)
    if verdict.failures:
        print(f"REJECTED: {len(verdict.failures)} failure(s)", file=sys.stderr)
        return 1
    print("EVIDENCE-CONSISTENT for this run only: kernel enforcement is shown by the PASS lines above, not by this tool.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
