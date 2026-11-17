#!/usr/bin/env python3
"""Read-only AppArmor audit evidence collector for the /run/systemd/notify denial.

The separate GO-5.4a socket-lock hotfix deliberately adds no systemd-notify
rule to the G1 MySQL profiles. Before anyone proposes widening a profile, the
complete audit records must be available and their necessity established
(docs/PRODUCTION_G1_MYSQL_GO54A_APPARMOR_HOTFIX.md). This tool collects that
evidence without changing anything:

  - bounded kernel-journal read (default and maximum 20000 lines, optional
    --since window), parsed into explicit columns so a missing rule, an
    abstract/disconnected-socket problem (error/info/addr fields) and other
    causes stay distinguishable;
  - the #include/abi lines and any existing /run/systemd/notify rule lines of
    the two G1 profile sources, with line numbers;
  - the relevant confinement-relevant unit properties of both G1 units and the
    vendor mysql.service.

Fail-closed: an unreadable journal is UNKNOWN, never "no denial"; an empty
journal is reported explicitly. Output and runtime are bounded. No service is
started, stopped or reloaded, no profile is loaded or compiled, and nothing is
written. Local synthetic test passes do NOT make this tool approved; independent
Integration QA and AppSec PASS records are required before its output is used
in a release decision.

Usage: python3 -I -B g1-notify-evidence.py [--since TS] [--lines N] [--profile PATH]
"""

import re
import stat
import sys

TOOL = "g1-notify-evidence"
VERSION = "1.0.0"

TARGET = "/run/systemd/notify"
PROFILE_P = "/etc/apparmor.d/wpcaregrid-mysql-production"
PROFILE_R = "/etc/apparmor.d/wpcaregrid-mysql-production-restore"
G1_PROFILES = (PROFILE_P, PROFILE_R)
G1_PROFILE_PREFIXES = ("wpcaregrid-mysql-production", "wpcaregrid-mysql-production-restore")
UNITS = ("mysql@wpcaregrid-production.service", "mysql@wpcaregrid-production-restore.service")
VENDOR_UNIT = "mysql.service"
UNIT_PROPERTIES = ("Type", "NotifyAccess", "PrivateTmp", "PrivateDevices", "ProtectSystem",
                   "ProtectHome", "RestrictAddressFamilies")

DEFAULT_LINES = 20000
MAX_LINES = 20000
MAX_PRINT_ROWS = 200
MAX_PROFILE_BYTES = 4 * 1024 * 1024
COMMAND_TIMEOUT = 60
ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "HOME": "/nonexistent", "LANG": "C", "LC_ALL": "C"}

FIELD_RE = re.compile(r'([A-Za-z_]+)=("(?:[^"]*)"|\S+)')
COLUMNS = ("ts", "profile", "operation", "requested", "denied", "name", "addr", "peer",
           "peer_addr", "error", "info", "comm", "pid")


class HostError(Exception):
    def __init__(self, code, detail=""):
        super().__init__(code if not detail else code + ":" + str(detail))
        self.code, self.detail = code, str(detail)


class RealHost:
    def run(self, args, timeout=COMMAND_TIMEOUT):
        import subprocess

        try:
            done = subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                  stderr=subprocess.DEVNULL, timeout=timeout, check=False,
                                  close_fds=True, env=ENV)
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise HostError("command_failed", args[0]) from exc
        return done.returncode, done.stdout

    def read_regular(self, path, max_bytes):
        import os

        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0))
        try:
            if not stat.S_ISREG(os.fstat(fd).st_mode):
                raise HostError("not_regular", path)
            data = b""
            while len(data) < max_bytes:
                chunk = os.read(fd, min(65536, max_bytes - len(data)))
                if not chunk:
                    break
                data += chunk
            if len(data) >= max_bytes and os.read(fd, 1):
                raise HostError("too_large", path)
            return data
        finally:
            os.close(fd)

    def read_text(self, path, max_bytes):
        return self.read_regular(path, max_bytes).decode("utf-8", errors="replace")


def guard(fn):
    try:
        return fn()
    except HostError as exc:
        return ("UNKNOWN", exc.code + ((":" + exc.detail) if exc.detail else ""))
    except OSError as exc:
        import errno

        return ("UNKNOWN", "filesystem_error:" + errno.errorcode.get(exc.errno, str(exc.errno)))
    except Exception as exc:  # noqa: BLE001 - a diagnostic must never crash into a false verdict
        return ("UNKNOWN", "internal_error:" + exc.__class__.__name__)


def parse_record(line):
    fields = {}
    for key, value in FIELD_RE.findall(line):
        value = value[1:-1] if value.startswith('"') else value
        fields.setdefault(key, value)
    return fields


def timestamp_of(line):
    marker = line.find(" kernel: ")
    if marker > 0:
        return line[:marker].strip()
    return line[:32].strip()


def unit_property(host, unit, prop):
    rc, out = host.run(["/usr/bin/systemctl", "show", unit, "--property=" + prop, "--value"])
    if rc != 0:
        raise HostError("systemctl_show_failed", unit + ":" + prop)
    return out.decode("utf-8", errors="replace").strip()


def collect_journal(host, lines_cap, since):
    args = ["/usr/bin/journalctl", "-k", "--no-pager", "--no-hostname", "-n", str(lines_cap)]
    if since:
        args += ["-S", since]
    rc, raw = host.run(args, timeout=COMMAND_TIMEOUT * 2)
    return {"rc": rc, "raw": raw}


def journal_rows(text):
    """Split journal text into parsed AppArmor records. Returns (denied, notify_targets)."""
    denied, targets = [], []
    for line in text.splitlines():
        if 'apparmor="DENIED"' not in line:
            continue
        fields = parse_record(line)
        fields["ts"] = timestamp_of(line)
        denied.append(fields)
        values = " ".join(str(value) for value in fields.values())
        if "systemd/notify" in values:
            targets.append(fields)
    return denied, targets


def emit_row(emit, fields):
    values = []
    for column in COLUMNS:
        value = fields.get(column, "-")
        if column in ("requested",):
            value = fields.get("requested_mask", fields.get("mask", "-"))
        if column in ("denied",):
            value = fields.get("denied_mask", "-")
        values.append(str(value))
    emit("ROW " + " | ".join(values))


def profile_statics(host, paths, emit):
    for path in paths:
        outcome = guard(lambda: host.read_text(path, MAX_PROFILE_BYTES))  # noqa: B023
        if isinstance(outcome, tuple):
            emit("PROFILE_SOURCE %s unreadable:%s" % (path, outcome[1]))
            continue
        notify_rule = False
        for number, line in enumerate(outcome.splitlines(), start=1):
            stripped = line.strip()
            if stripped.startswith("#include") or stripped.startswith("abi "):
                emit("PROFILE %s:%d %s" % (path, number, stripped))
            if TARGET in line:
                notify_rule = True
                emit("PROFILE %s:%d %s" % (path, number, stripped))
        emit("PROFILE_SOURCE %s notify_rule_present=%s" % (path, "yes" if notify_rule else "no"))


def emit_unit_properties(host, units, emit):
    for unit in units:
        for prop in UNIT_PROPERTIES:
            outcome = guard(lambda: unit_property(host, unit, prop))  # noqa: B023
            if isinstance(outcome, tuple):
                emit("UNITPROP %s %s UNKNOWN %s" % (unit, prop, outcome[1]))
            else:
                emit("UNITPROP %s %s=%s" % (unit, prop, outcome))


def main(argv=None, host=None, stream=None):
    stream = stream if stream is not None else sys.stdout

    def emit(line):
        print(line, file=stream)

    argv = list(sys.argv[1:] if argv is None else argv)
    since, lines_cap = None, DEFAULT_LINES
    extra_profiles = []
    index = 0
    while index < len(argv):
        argument = argv[index]
        if argument == "--since":
            index += 1
            if index >= len(argv):
                emit("G1_NOTIFY_EVIDENCE=UNKNOWN;COUNT=0;STATUS=UNKNOWN;CODE=since_argument_missing")
                return 4
            since = argv[index]
        elif argument == "--lines":
            index += 1
            if index >= len(argv) or not argv[index].isdigit() or not 1 <= int(argv[index]) <= MAX_LINES:
                emit("G1_NOTIFY_EVIDENCE=UNKNOWN;COUNT=0;STATUS=UNKNOWN;CODE=lines_argument_invalid")
                return 4
            lines_cap = int(argv[index])
        elif argument == "--profile":
            index += 1
            if index >= len(argv) or len(extra_profiles) >= 8:
                emit("G1_NOTIFY_EVIDENCE=UNKNOWN;COUNT=0;STATUS=UNKNOWN;CODE=profile_argument_invalid")
                return 4
            extra_profiles.append(argv[index])
        else:
            emit("G1_NOTIFY_EVIDENCE=UNKNOWN;COUNT=0;STATUS=UNKNOWN;CODE=argument_invalid:" + argument)
            return 4
        index += 1

    host = host if host is not None else RealHost()
    emit("TOOL %s %s mode=read_only target=%s" % (TOOL, VERSION, TARGET))
    emit("JOURNAL lines_cap=%d since=%s" % (lines_cap, since or "none"))

    outcome = guard(lambda: collect_journal(host, lines_cap, since))
    if isinstance(outcome, tuple):
        emit("CHECK journal UNKNOWN " + outcome[1])
        emit("G1_NOTIFY_EVIDENCE=UNKNOWN;COUNT=0;STATUS=UNKNOWN;CODE=" + outcome[1])
        return 4
    rc, raw = outcome["rc"], outcome["raw"]
    if rc != 0:
        emit("CHECK journal UNKNOWN journal_unavailable_rc=%d" % rc)
        emit("G1_NOTIFY_EVIDENCE=UNKNOWN;COUNT=0;STATUS=UNKNOWN;CODE=journal_unavailable")
        return 4
    text = raw.decode("utf-8", errors="replace").strip()
    if not text or text == "-- No entries --":
        emit("CHECK journal %s journal_empty_or_no_entries" % ("ABSENT" if text else "EMPTY"))
        emit("COUNTS denied_total=0 g1_denied_total=0 notify_target=0")
        verdict, status, code, count = "ABSENT", "HOLD", "journal_empty", 0
    else:
        denied, targets = journal_rows(text)
        g1_denied = [fields for fields in denied
                     if str(fields.get("profile", "")).startswith(G1_PROFILE_PREFIXES)]
        emit("CHECK journal PASS lines_read=%d" % len(text.splitlines()))
        emit("COUNTS denied_total=%d g1_denied_total=%d notify_target=%d"
             % (len(denied), len(g1_denied), len(targets)))
        if targets:
            emit("ROW_HEADER " + " | ".join(COLUMNS))
        for fields in targets[:MAX_PRINT_ROWS]:
            emit_row(emit, fields)
        if len(targets) > MAX_PRINT_ROWS:
            emit("NOTE rows_truncated printed=%d total=%d" % (MAX_PRINT_ROWS, len(targets)))
        if targets:
            verdict, status, code, count = "PRESENT", "PASS", "none", len(targets)
        else:
            verdict, status, code, count = ("ABSENT", "HOLD",
                                            "no_matching_records_in_bounded_window", 0)

    profile_statics(host, G1_PROFILES + tuple(extra_profiles), emit)
    emit_unit_properties(host, UNITS + (VENDOR_UNIT,), emit)
    emit("G1_NOTIFY_EVIDENCE=%s;COUNT=%d;STATUS=%s;CODE=%s" % (verdict, count, status, code))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
