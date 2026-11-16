#!/usr/bin/env python3
"""Read-only inventory of every process that runs as www-data (UID 33). Run as root.

Same-UID peers are the whole threat model: any www-data process that is not
enforce-attached to one of the confinement profiles can read the other
environment's group-readable secrets. The inventory is only usable when it is
*complete*; any unreadable process, missing unit cgroup file that should exist
or vanished unit member sets ``complete`` to false and the verifier refuses.

Reads /proc and the cgroup tree only. Never signals, traces or modifies anything.
Usage: collect-peers.py <matrix.json>   (JSON on stdout)
"""
from __future__ import annotations

import json
import os
import re
import sys

TARGET_UID = 33


def read(path: str) -> str:
    with open(path, "rb") as handle:
        return handle.read().decode("utf-8", "replace")


def starttime(stat_text: str) -> int:
    # Field 22; the command name (field 2) may contain spaces and parentheses.
    return int(stat_text.rsplit(")", 1)[1].split()[19])


def unit_of(cgroup_text: str) -> str | None:
    for line in cgroup_text.splitlines():
        match = re.search(r"/([^/]+\.service)$", line.strip())
        if match:
            return match.group(1)
    return None


def collect(units: dict, proc: str = "/proc", cgroup: str = "/sys/fs/cgroup", uid: int = TARGET_UID) -> dict:
    reasons: list = []
    processes: list = []
    for entry in sorted((e for e in os.listdir(proc) if e.isdigit()), key=int):
        base = f"{proc}/{entry}"
        try:
            ids = [int(v) for v in re.search(r"^Uid:\s+(.*)$", read(f"{base}/status"), re.M).group(1).split()]
        except FileNotFoundError:
            if os.path.isdir(base):
                reasons.append(f"unreadable status for {entry}")
            continue  # otherwise it exited during the scan: benign
        except (OSError, AttributeError, ValueError):
            reasons.append(f"unreadable status for {entry}")
            continue
        if uid not in ids:
            continue
        try:
            item = {"pid": int(entry), "uids": ids, "comm": read(f"{base}/comm").strip(),
                    "starttime": starttime(read(f"{base}/stat")),
                    "label": read(f"{base}/attr/current").strip("\0\n "),
                    "unit": unit_of(read(f"{base}/cgroup")), "exe": None, "fds": []}
            try:
                item["exe"] = os.readlink(f"{base}/exe")
            except OSError:
                pass  # kernel threads have no exe; fds below decide completeness for real processes
            item["fds"] = sorted(os.readlink(f"{base}/fd/{fd}") for fd in os.listdir(f"{base}/fd"))
        except FileNotFoundError:
            if os.path.isdir(base):
                reasons.append(f"unreadable process data for {entry}")
            continue  # otherwise it exited during the scan: benign
        except (OSError, ValueError, IndexError):
            reasons.append(f"unreadable process data for {entry}")
            continue
        processes.append(item)
    seen = {p["pid"] for p in processes}
    members: dict = {}
    for unit in sorted(set(units.values())):
        path = f"{cgroup}/system.slice/{unit}/cgroup.procs"
        try:
            pids = sorted(int(v) for v in read(path).split())
            members[unit] = {"present": True, "pids": pids}
        except FileNotFoundError:
            members[unit] = {"present": False, "pids": []}
        except (OSError, ValueError):
            members[unit] = {"present": False, "pids": []}
            reasons.append(f"unreadable cgroup for {unit}")
    for unit, info in members.items():
        for pid in info["pids"]:
            if pid not in seen and os.path.isdir(f"{proc}/{pid}"):
                try:
                    if uid in [int(v) for v in re.search(r"^Uid:\s+(.*)$", read(f"{proc}/{pid}/status"), re.M).group(1).split()]:
                        reasons.append(f"unit {unit} member {pid} missing from inventory")
                except (OSError, AttributeError, ValueError):
                    reasons.append(f"unit {unit} member {pid} unreadable")
    return {"schema": 1, "complete": not reasons, "reasons": reasons, "processes": processes, "units": members}


def main(argv: list) -> int:
    if len(argv) != 1 or os.geteuid() != 0:
        print("usage (as root): collect-peers.py <matrix.json>", file=sys.stderr)
        return 2
    matrix = json.loads(read(argv[0]))
    print(json.dumps(collect(matrix["role_units"]), sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
