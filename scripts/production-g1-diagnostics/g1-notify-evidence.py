#!/usr/bin/env python3
"""Read-only AppArmor audit evidence collector for the /run/systemd/notify denial.

The GO-5.4a hotfix now adds the reviewed, evidence-backed systemd-notify
permission pair to the G1 MySQL profiles (GO-20.29/20.30/20.31; the complete
necessity evidence lives in docs/PRODUCTION_G1_MYSQL_GO54A_APPARMOR_HOTFIX.md).
Until the separately approved transaction runs, the OLD profiles still omit
it, so the Owner's read-only preflight confirmation still collects that
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
journal is reported explicitly; a DENIED line that does not carry the complete
expected audit shape is counted as unrecognized and never becomes target
evidence. The tool is designed for the approved root console and requires
root: a non-root run reports UNKNOWN, never a verdict. Exit code 0 means the
bounded evidence COLLECTION completed — the classification is carried by the
final G1_NOTIFY_EVIDENCE line (PRESENT with STATUS=PASS, or ABSENT with
STATUS=HOLD); UNKNOWN and collection failures exit 4. Gate on the final line
together with the exit code, never on the exit code alone. Output and runtime
are bounded. No service is started, stopped or reloaded, no profile is loaded
or compiled, and nothing is written. Local synthetic test passes do NOT make
this tool approved; independent Integration QA and AppSec PASS records are
required before its output is used in a release decision.

Usage: python3 -I -B g1-notify-evidence.py [--since TS] [--lines N] [--profile NAME]

--profile NAME accepts a profile BASENAME under /etc/apparmor.d/ only (no
paths, no traversal); arbitrary caller-supplied paths are rejected.
"""

import re
import stat
import sys

TOOL = "g1-notify-evidence"
VERSION = "1.4.0"

TARGET = "/run/systemd/notify"
PROFILE_DIR = "/etc/apparmor.d/"
PROFILE_NAME_RE = r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}"
PROFILE_P = "/etc/apparmor.d/wpcaregrid-mysql-production"
PROFILE_R = "/etc/apparmor.d/wpcaregrid-mysql-production-restore"
G1_PROFILES = (PROFILE_P, PROFILE_R)
# Exact installed profile names (scripts/production_g1_mysql_contract.py): a
# prefix match would let lookalike profiles such as
# "wpcaregrid-mysql-production-evil" fabricate G1 evidence (AppSec R2 F4).
G1_PROFILE_NAMES = ("wpcaregrid-mysql-production", "wpcaregrid-mysql-production-restore")
TARGET_FIELDS = ("name", "addr", "peer", "peer_addr")
UNITS = ("mysql@wpcaregrid-production.service", "mysql@wpcaregrid-production-restore.service")
VENDOR_UNIT = "mysql.service"
UNIT_PROPERTIES = ("Type", "NotifyAccess", "PrivateTmp", "PrivateDevices", "ProtectSystem",
                   "ProtectHome", "RestrictAddressFamilies")

DEFAULT_LINES = 20000
MAX_LINES = 20000
MAX_PRINT_ROWS = 200
MAX_JOURNAL_BYTES = 16 * 1024 * 1024
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
    def euid(self):
        import os

        return os.geteuid()

    def run(self, args, timeout=COMMAND_TIMEOUT):
        import subprocess

        try:
            done = subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                  stderr=subprocess.DEVNULL, timeout=timeout, check=False,
                                  close_fds=True, env=ENV)
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise HostError("command_failed", args[0]) from exc
        return done.returncode, done.stdout

    def run_stdout_bounded(self, args, max_bytes, timeout):
        """AppSec R2-F8/R3-F1 fix: never buffer unbounded subprocess output and
        never block past the deadline while stdout is idle. Reads only when
        the pipe is readable (selectors), enforces the deadline between
        reads, and kills/reaps the producer on overflow or timeout."""
        import selectors
        import subprocess
        import time

        try:
            process = subprocess.Popen(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                        stderr=subprocess.DEVNULL, close_fds=True, env=ENV)
        except OSError as exc:
            raise HostError("command_failed", args[0]) from exc
        data = b""
        overflow = False
        deadline = time.monotonic() + timeout
        selector = selectors.DefaultSelector()
        selector.register(process.stdout, selectors.EVENT_READ)
        try:
            while True:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    process.kill()
                    raise HostError("command_failed", args[0])
                events = selector.select(timeout=remaining)
                if not events:
                    continue  # the deadline fires on the next loop iteration
                if time.monotonic() >= deadline:
                    # Strict deadline (AppSec R4 note): a late EOF/readiness
                    # event at or past the deadline is a timeout, never a
                    # successful completion.
                    process.kill()
                    raise HostError("command_failed", args[0])
                chunk = process.stdout.read1(65536)
                if not chunk:
                    break
                if len(data) + len(chunk) > max_bytes:
                    overflow = True
                    break
                data += chunk
        finally:
            selector.close()
            if process.poll() is None:
                process.kill()
            process.wait()
            process.stdout.close()
        if overflow:
            raise HostError("journal_exceeds_bounds", str(max_bytes))
        return process.returncode, data

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
    rc, raw = host.run_stdout_bounded(args, MAX_JOURNAL_BYTES, COMMAND_TIMEOUT * 2)
    return {"rc": rc, "raw": raw}


def recognized_denied_record(fields):
    """AppSec F4 + R2-F4 fix: only a complete audit shape counts as evidence.

    A DENIED line must carry an explicit DENIED verdict, a non-empty operation,
    a syntactically sane profile token, BOTH the requested and denied masks,
    and the pid/comm identity fields. Anything else is counted as unrecognized
    and can never become target evidence."""
    if fields.get("apparmor") != "DENIED":
        return False
    operation = fields.get("operation", "")
    profile = fields.get("profile", "")
    if not operation or not profile:
        return False
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,127}", profile):
        return False
    if not (fields.get("requested_mask") or fields.get("mask")):
        return False
    if not fields.get("denied_mask"):
        return False
    if not fields.get("pid") or not fields.get("comm"):
        return False
    return True


def journal_rows(text):
    """Split journal text into (recognized_denied, notify_targets, unrecognized).

    Targets require a recognized record, one of the two EXACT G1 profile
    names, and a path/address field value that is EXACTLY the notify path —
    prefix lookalike profiles and near-miss paths never count (AppSec R2 F4)."""
    denied, targets, unrecognized = [], [], []
    for line in text.splitlines():
        if 'apparmor="DENIED"' not in line:
            continue
        fields = parse_record(line)
        fields["ts"] = timestamp_of(line)
        if not recognized_denied_record(fields):
            unrecognized.append(line)
            continue
        denied.append(fields)
        if fields.get("profile") not in G1_PROFILE_NAMES:
            continue
        if any(str(fields.get(field, "")) == TARGET for field in TARGET_FIELDS):
            targets.append(fields)
    return denied, targets, unrecognized


def sanitize(text):
    """Untrusted evidence and arguments never reach the archived output
    verbatim: ASCII control characters, DEL, C1 controls and Unicode
    line/paragraph separators are all masked (AppSec R2/R4 hardening)."""
    separators = {" ", " "}
    return "".join("?" if (ch < " " or ch == "\x7f" or "\x80" <= ch <= "\x9f"
                           or ch in separators) else ch
                   for ch in str(text))


def emit_row(emit, fields):
    values = []
    for column in COLUMNS:
        value = fields.get(column, "-")
        if column in ("requested",):
            value = fields.get("requested_mask", fields.get("mask", "-"))
        if column in ("denied",):
            value = fields.get("denied_mask", "-")
        values.append(sanitize(value))
    emit("ROW " + " | ".join(values))


def profile_statics(host, paths, emit):
    for path in paths:
        outcome = guard(lambda: host.read_text(path, MAX_PROFILE_BYTES))  # noqa: B023
        if isinstance(outcome, tuple):
            emit("PROFILE_SOURCE %s unreadable:%s" % (path, outcome[1]))
            continue
        notify_rule = False
        for number, line in enumerate(outcome.splitlines(), start=1):
            stripped = sanitize(line.strip())
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
                emit("UNITPROP %s %s UNKNOWN %s" % (unit, prop, sanitize(outcome[1])))
            else:
                emit("UNITPROP %s %s=%s" % (unit, prop, sanitize(outcome)))


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
            name = argv[index]
            if not re.fullmatch(PROFILE_NAME_RE, name):
                emit("G1_NOTIFY_EVIDENCE=UNKNOWN;COUNT=0;STATUS=UNKNOWN;CODE=profile_argument_invalid")
                return 4
            extra_profiles.append(PROFILE_DIR + name)
        else:
            emit("G1_NOTIFY_EVIDENCE=UNKNOWN;COUNT=0;STATUS=UNKNOWN;CODE=argument_invalid:" + sanitize(argument))
            return 4
        index += 1

    host = host if host is not None else RealHost()
    emit("TOOL %s %s mode=read_only target=%s" % (TOOL, VERSION, TARGET))
    emit("JOURNAL lines_cap=%d since=%s" % (lines_cap, sanitize(since) if since else "none"))

    if host.euid() != 0:
        emit("CHECK console_root UNKNOWN not_root_console")
        emit("G1_NOTIFY_EVIDENCE=UNKNOWN;COUNT=0;STATUS=UNKNOWN;CODE=not_root_console")
        return 4
    emit("CHECK console_root PASS root_console")

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
        emit("COUNTS denied_total=0 g1_denied_total=0 notify_target=0 unrecognized=0")
        verdict, status, code, count = "ABSENT", "HOLD", "journal_empty", 0
    else:
        denied, targets, unrecognized = journal_rows(text)
        g1_denied = [fields for fields in denied
                     if fields.get("profile") in G1_PROFILE_NAMES]
        emit("CHECK journal PASS lines_read=%d" % len(text.splitlines()))
        emit("COUNTS denied_total=%d g1_denied_total=%d notify_target=%d unrecognized=%d"
             % (len(denied), len(g1_denied), len(targets), len(unrecognized)))
        if unrecognized:
            emit("NOTE unrecognized_denied_records=%d not_counted_as_evidence" % len(unrecognized))
        if targets:
            emit("ROW_HEADER " + " | ".join(COLUMNS))
        for fields in targets[:MAX_PRINT_ROWS]:
            emit_row(emit, fields)
        if len(targets) > MAX_PRINT_ROWS:
            emit("NOTE rows_truncated printed=%d total=%d" % (MAX_PRINT_ROWS, len(targets)))
        # AppSec R2 F5 fix: ANY unrecognized DENIED record makes the overall
        # evidence status UNKNOWN — valid rows and counts remain visible as
        # context, but incomplete evidence never yields a PASS verdict.
        if unrecognized:
            verdict, status, code, count = "UNKNOWN", "UNKNOWN", "unrecognized_denied_records", len(targets)
        elif targets:
            verdict, status, code, count = "PRESENT", "PASS", "none", len(targets)
        else:
            verdict, status, code, count = ("ABSENT", "HOLD",
                                            "no_matching_records_in_bounded_window", 0)

    profile_statics(host, G1_PROFILES + tuple(extra_profiles), emit)
    emit_unit_properties(host, UNITS + (VENDOR_UNIT,), emit)
    emit("G1_NOTIFY_EVIDENCE=%s;COUNT=%d;STATUS=%s;CODE=%s" % (verdict, count, status, code))
    return 0 if verdict != "UNKNOWN" else 4


if __name__ == "__main__":
    raise SystemExit(main())
