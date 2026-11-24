#!/usr/bin/env python3
"""Read-only, stable inventory of every www-data (UID 33) process and every service master. Run as root.

Same-UID peers are the whole threat model: any www-data process that is not enforce-attached to a
confinement profile can read the other environment's group-readable secrets. A root-owned service
master that is not confined is just as relevant (it starts the workers), so the inventory contains

* every process with real, effective, saved or filesystem UID 33, **or GID 33, or supplementary group 33**
  (the secrets are group-readable by gid 33, so a deploy shell or any helper in the www-data group can read
  them without being uid 33), and
* every member of every known service cgroup (the masters, which run as root, included),

each with pid, start time, parent, UID tuple, AppArmor label, cgroup unit, executable and every open
descriptor target. The unit list is taken from the package matrix, never from a caller list, and the
matrix hash is recorded so the verifier can prove which list was used.

The inventory is only usable when it is *complete* and *stable*: two consecutive full scans must
agree on (pid, start time, label, unit, UIDs, GIDs and supplementary groups) for every included process, cgroup membership must agree
with each process's own cgroup file, the boot id must not change, and nothing may be unreadable.
Anything else (churn, PID reuse, unreadable data, a cgroup member missing from the scan) sets
``complete`` to false with a reason. Quiesce the services first (maintenance gate); worker churn is a
refusal, not noise to be averaged away.

It also records whether any non-fixture file exists in the production tree (paths only; content is
read solely for files of exactly the canary size) so the pre-production gate can prove production
secrets are absent.

Reads /proc, the cgroup tree and file metadata only. Never signals, traces or modifies anything.
Usage: collect-peers.py <matrix.json>   (JSON on stdout)
"""
from __future__ import annotations

import fnmatch
import hashlib
import json
import os
import re
import sys
import time

TARGET_UID = 33


def read(path: str) -> str:
    with open(path, "rb") as handle:
        return handle.read().decode("utf-8", "replace")


def read_bytes(path: str) -> bytes:
    with open(path, "rb") as handle:
        return handle.read()


def starttime_and_ppid(stat_text: str) -> tuple:
    # The command name (field 2) may contain spaces and parentheses.
    fields = stat_text.rsplit(")", 1)[1].split()
    return int(fields[19]), int(fields[1]), fields[0]


def unit_of(cgroup_text: str) -> str | None:
    for line in cgroup_text.splitlines():
        for part in line.strip().split("/"):
            if part.endswith(".service"):
                return part
    return None


def boot_wall_seconds(proc: str) -> float | None:
    try:
        for line in read(f"{proc}/stat").splitlines():
            if line.startswith("btime "):
                return float(line.split()[1])
    except OSError:
        pass
    return None


def read_process(proc: str, entry: str, units: set, uid: int, reasons: list) -> dict | None:
    base = f"{proc}/{entry}"
    try:
        status = read(f"{base}/status")
        ids = [int(v) for v in re.search(r"^Uid:\s+(.*)$", status, re.M).group(1).split()]
        gids = [int(v) for v in re.search(r"^Gid:\s+(.*)$", status, re.M).group(1).split()]
        groups_line = re.search(r"^Groups:[ \t]*(.*)$", status, re.M)
        groups = [int(v) for v in groups_line.group(1).split()] if groups_line else []
        unit = unit_of(read(f"{base}/cgroup"))
    except FileNotFoundError:
        if os.path.isdir(base):
            reasons.append(f"unreadable status for {entry}")
        return None  # otherwise it exited during the scan: the stable-pair rule catches real churn
    except (OSError, AttributeError, ValueError):
        reasons.append(f"unreadable status for {entry}")
        return None
    if uid not in ids and uid not in gids and uid not in groups and unit not in units:
        return None
    try:
        start, ppid, state = starttime_and_ppid(read(f"{base}/stat"))
        item = {"pid": int(entry), "ppid": ppid, "uids": ids, "gids": gids, "groups": groups, "comm": read(f"{base}/comm").strip(),
                "starttime": start, "label": read(f"{base}/attr/current").strip("\0\n "), "unit": unit,
                "state": state, "cgroup": read(f"{base}/cgroup").strip().splitlines()[0] if read(f"{base}/cgroup").strip() else "",
                "exe": None, "fds": []}
        btime = boot_wall_seconds(proc)
        item["age_s"] = None if btime is None else max(0.0, round(time.time() - (btime + start / os.sysconf("SC_CLK_TCK")), 1))
        raw = read_bytes(f"{base}/cmdline")
        item["argv0"] = raw.split(b"\0", 1)[0].decode("utf-8", "replace")[:200]
        item["cmdline_sha256"] = hashlib.sha256(raw).hexdigest()
        try:
            item["exe"] = os.readlink(f"{base}/exe")
        except OSError:
            item["exe"] = None  # kernel threads have no exe
        item["fds"] = sorted(os.readlink(f"{base}/fd/{fd}") for fd in os.listdir(f"{base}/fd"))
        return item
    except FileNotFoundError:
        if os.path.isdir(base):
            reasons.append(f"unreadable process data for {entry}")
        return None
    except (OSError, ValueError, IndexError):
        reasons.append(f"unreadable process data for {entry}")
        return None


def snapshot(units: dict, proc: str, cgroup: str, uid: int) -> dict:
    reasons: list = []
    unit_names = set(units.values())
    processes = []
    for entry in sorted((e for e in os.listdir(proc) if e.isdigit()), key=int):
        item = read_process(proc, entry, unit_names, uid, reasons)
        if item is not None:
            processes.append(item)
    by_unit: dict = {}
    for unit in sorted(unit_names):
        path = f"{cgroup}/system.slice/{unit}/cgroup.procs"
        try:
            pids = sorted(int(v) for v in read(path).split())
            by_unit[unit] = {"present": True, "pids": pids}
        except FileNotFoundError:
            by_unit[unit] = {"present": False, "pids": []}
        except (OSError, ValueError):
            by_unit[unit] = {"present": False, "pids": []}
            reasons.append(f"unreadable cgroup for {unit}")
    # Cgroup membership must agree, both ways, with each process's own cgroup file and with the scan.
    scanned = {p["pid"]: p for p in processes}
    for unit, info in by_unit.items():
        for pid in info["pids"]:
            member = scanned.get(pid)
            if member is None:
                visible = os.path.isdir(f"{proc}/{pid}")
                reasons.append(f"unit {unit} member {pid} " + ("missing from the scan" if visible else "is listed in cgroup.procs but has no /proc entry"))
            elif member["unit"] != unit:
                reasons.append(f"unit {unit} member {pid} reports cgroup unit {member['unit']!r}")
    for proc_item in processes:
        unit = proc_item["unit"]
        if unit in unit_names and proc_item["pid"] not in by_unit[unit]["pids"]:
            reasons.append(f"process {proc_item['pid']} claims unit {unit} but is not in its cgroup.procs")
    boot = read(f"{proc}/sys/kernel/random/boot_id").strip() if os.path.exists(f"{proc}/sys/kernel/random/boot_id") else ""
    if not boot:
        reasons.append("boot id unreadable")
    return {"processes": processes, "units": by_unit, "reasons": reasons, "boot_id": boot}


def identity(snap: dict) -> frozenset:
    return frozenset((p["pid"], p["starttime"], p["label"], p["unit"], tuple(p["uids"]), tuple(p["gids"]), tuple(p["groups"])) for p in snap["processes"])


def production_surface(roots: list, canary: str, public_glob: str) -> dict:
    """Which production files exist that are not the exact fixture canary (never reads other content)."""
    real, fixtures, present = [], 0, False
    for root in roots:
        if not os.path.isdir(root):
            continue
        present = True
        for current, dirs, files in os.walk(root, followlinks=False):
            if fnmatch.fnmatch(current, public_glob) or fnmatch.fnmatch(current, public_glob + "/*"):
                dirs[:] = []
                continue
            for name in files:
                path = os.path.join(current, name)
                try:
                    info = os.lstat(path)
                    if os.path.islink(path):
                        continue
                    if info.st_size == len(canary):
                        with open(path, "rb") as handle:
                            if handle.read() == canary.encode():
                                fixtures += 1
                                continue
                    real.append(path)
                except OSError:
                    real.append(path)  # unreadable counts as real: never assume it is a fixture
    return {"roots_present": present, "real_files": sorted(real), "fixture_files": fixtures}


def collect(units: dict, proc: str = "/proc", cgroup: str = "/sys/fs/cgroup", uid: int = TARGET_UID, *, matrix_sha256: str = "",
            attempts: int = 5, between=None, pause: float = 0.2, production: dict | None = None) -> dict:
    started = time.time_ns()
    result_reasons: list = []
    final = None
    for attempt in range(attempts):
        first = snapshot(units, proc, cgroup, uid)
        if between is not None:
            between(attempt)
        elif pause:
            time.sleep(pause)
        second = snapshot(units, proc, cgroup, uid)
        problems = first["reasons"] + second["reasons"]
        if first["boot_id"] != second["boot_id"]:
            problems.append("boot id changed between scans")
        if identity(first) != identity(second):
            problems.append("churn: the process set changed between consecutive scans")
        final = second
        if not problems:
            result_reasons = []
            break
        result_reasons = [f"attempt {attempt + 1}: {item}" for item in sorted(set(problems))]
    assert final is not None
    surface = production_surface(production["roots"], production["canary"], production["public_glob"]) if production else None
    return {"schema": 2, "complete": not result_reasons, "reasons": result_reasons, "incomplete_reasons": result_reasons, "matrix_sha256": matrix_sha256,
            "boot_id": final["boot_id"], "collected_start_ns": started, "collected_end_ns": time.time_ns(),
            "scans": 2, "processes": final["processes"], "units": final["units"], "production_surface": surface}


def main(argv: list) -> int:
    if len(argv) != 1 or os.geteuid() != 0:
        print("usage (as root): collect-peers.py <matrix.json>", file=sys.stderr)
        return 2
    text = read(argv[0])
    matrix = json.loads(text)
    fence = matrix["production_fence"]
    production = {"roots": fence["roots"], "canary": matrix["canary"], "public_glob": fence["public_exempt"]}
    print(json.dumps(collect({u: u for u in matrix.get("inventory_units", matrix["role_units"])}, matrix_sha256=hashlib.sha256(text.encode()).hexdigest(),
                             production=production), sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
