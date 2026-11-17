"""Hermetic, non-root synthetic fixture tests for g1-notify-evidence.py.

The production tool is a read-only, bounded evidence collector with a
fail-closed classification. These tests inject canned journal output, canned
systemctl answers and synthetic profile sources, and assert parsing, bounds,
explicit empty/unavailable flags and the verdict. They are development
evidence only and do NOT approve the tool for host use.
"""

import importlib.util
import io
import unittest
from pathlib import Path

OPS = Path(__file__).parents[1] / "production-g1-ops"
SPEC = importlib.util.spec_from_file_location("g1_notify_evidence", OPS / "g1-notify-evidence.py")
ne = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ne)

SOCK_LOCK_DENIAL = (
    'Nov 17 18:59:00 kernel: audit: 1740000000.100:3 apparmor="DENIED" '
    'operation="mknod" profile="wpcaregrid-mysql-production" pid=3512 comm="mysqld" '
    'name="/run/mysqld/wpcaregrid-production.sock.lock" requested_mask="c" denied_mask="c"'
)
NOTIFY_DENIAL_FULL = (
    'Nov 17 19:00:01 kernel: audit: 1740000001.123:4 apparmor="DENIED" '
    'operation="sendmsg" profile="wpcaregrid-mysql-production" pid=3512 comm="mysqld" '
    'requested_mask="send" denied_mask="send" addr=/run/systemd/notify peer="systemd" '
    'peer_addr=none info="Failed name lookup - disconnected path" error=-13'
)
NOTIFY_DENIAL_SPARSE = (
    'Nov 17 19:00:02 kernel: audit: 1740000002.123:5 apparmor="DENIED" '
    'operation="sendmsg" profile="wpcaregrid-mysql-production-restore" pid=3513 comm="mysqld" '
    'requested_mask="w" denied_mask="w" name="/run/systemd/notify"'
)
INNOCENT_KERNEL_LINE = "Nov 17 19:00:03 kernel: usb 1-1: new device"


class FakeHost:
    def __init__(self, journal="-- No entries --", journal_rc=0, commands=None,
                 files=None, fail_journal=False):
        self.journal, self.journal_rc = journal, journal_rc
        self.commands = commands or {}
        self.files = files or {}
        self.fail_journal = fail_journal
        self.run_args = []

    def run(self, args, timeout=60):
        self.run_args.append(list(args))
        if args[0] == "/usr/bin/journalctl":
            if self.fail_journal:
                raise ne.HostError("command_failed", "journalctl")
            data = self.journal.encode() if isinstance(self.journal, str) else self.journal
            return self.journal_rc, data
        if args[:2] == ["/usr/bin/systemctl", "show"]:
            unit, prop = args[2], args[3].split("=", 1)[1]
            value = self.commands.get((unit, prop))
            if value is None:
                raise ne.HostError("systemctl_show_failed", unit + ":" + prop)
            return 0, (str(value) + "\n").encode()
        raise ne.HostError("command_failed", args[0])

    def read_regular(self, path, max_bytes):
        if path not in self.files:
            raise FileNotFoundError(path)
        content = self.files[path]
        content = content.encode() if isinstance(content, str) else content
        if len(content) > max_bytes:
            raise ne.HostError("too_large", path)
        return content

    def read_text(self, path, max_bytes):
        return self.read_regular(path, max_bytes).decode("utf-8", errors="replace")


class NotifyEvidenceTestCase(unittest.TestCase):
    def run_main(self, argv=(), host=None):
        self.buffer = io.StringIO()
        return ne.main(list(argv), host=host if host is not None else self.host,
                       stream=self.buffer)

    def output(self):
        return self.buffer.getvalue()

    def last_line(self):
        return self.output().strip().splitlines()[-1]

    def test_present_notify_records_parse_with_full_columns(self):
        journal = "\n".join([SOCK_LOCK_DENIAL, NOTIFY_DENIAL_FULL, NOTIFY_DENIAL_SPARSE,
                             INNOCENT_KERNEL_LINE]) + "\n"
        self.host = FakeHost(journal=journal, commands=self.unit_commands())
        self.assertEqual(self.run_main(), 0)
        self.assertIn("COUNTS denied_total=3 g1_denied_total=3 notify_target=2", self.output())
        self.assertIn("ROW_HEADER ts | profile | operation | requested | denied | name | addr"
                      " | peer | peer_addr | error | info | comm | pid", self.output())
        # The full record keeps error and info distinguishable in their own columns.
        self.assertIn("| none | -13 | Failed name lookup - disconnected path | mysqld | 3512",
                      self.output())
        self.assertIn("/run/systemd/notify | systemd", self.output())
        # The sparse record must show its gaps explicitly instead of hiding them.
        self.assertIn("ROW Nov 17 19:00:02 | wpcaregrid-mysql-production-restore | sendmsg",
                      self.output())
        self.assertIn("| w | w | /run/systemd/notify | - | - | - | - | - | mysqld | 3513",
                      self.output())
        self.assertEqual(self.last_line(), "G1_NOTIFY_EVIDENCE=PRESENT;COUNT=2;STATUS=PASS;CODE=none")

    def test_no_entries_is_reported_explicitly_and_holds(self):
        self.host = FakeHost(journal="-- No entries --")
        self.assertEqual(self.run_main(), 0)
        self.assertIn("CHECK journal ABSENT journal_empty_or_no_entries", self.output())
        self.assertIn("COUNTS denied_total=0 g1_denied_total=0 notify_target=0", self.output())
        self.assertEqual(self.last_line(),
                         "G1_NOTIFY_EVIDENCE=ABSENT;COUNT=0;STATUS=HOLD;CODE=journal_empty")

    def test_empty_journal_output_is_flagged_not_silent(self):
        self.host = FakeHost(journal="")
        self.assertEqual(self.run_main(), 0)
        self.assertIn("CHECK journal EMPTY journal_empty_or_no_entries", self.output())
        self.assertIn("CODE=journal_empty", self.output())

    def test_journal_command_failure_is_unknown_not_absent(self):
        self.host = FakeHost(fail_journal=True)
        self.assertEqual(self.run_main(), 4)
        self.assertEqual(self.last_line().startswith("G1_NOTIFY_EVIDENCE=UNKNOWN"), True)
        self.assertIn("CODE=command_failed", self.output())

    def test_journal_nonzero_rc_is_unknown_not_absent(self):
        self.host = FakeHost(journal_rc=1)
        self.assertEqual(self.run_main(), 4)
        self.assertIn("CODE=journal_unavailable", self.output())

    def test_non_notify_denials_do_not_count_as_targets(self):
        self.host = FakeHost(journal=SOCK_LOCK_DENIAL + "\n")
        self.assertEqual(self.run_main(), 0)
        self.assertIn("COUNTS denied_total=1 g1_denied_total=1 notify_target=0", self.output())
        self.assertEqual(self.last_line(),
                         "G1_NOTIFY_EVIDENCE=ABSENT;COUNT=0;STATUS=HOLD;"
                         "CODE=no_matching_records_in_bounded_window")

    def test_target_rows_are_print_bounded(self):
        rows = "\n".join(NOTIFY_DENIAL_FULL.replace("1740000001.123:4", "1740000001.%03d:%d" % (i, i))
                         for i in range(205)) + "\n"
        self.host = FakeHost(journal=rows)
        self.assertEqual(self.run_main(), 0)
        printed = [line for line in self.output().splitlines() if line.startswith("ROW ")]
        self.assertEqual(len(printed), ne.MAX_PRINT_ROWS)
        self.assertIn("NOTE rows_truncated printed=%d total=205" % ne.MAX_PRINT_ROWS, self.output())
        self.assertTrue(self.last_line().startswith("G1_NOTIFY_EVIDENCE=PRESENT;COUNT=205"))

    def unit_commands(self):
        commands = {}
        for unit in ne.UNITS + (ne.VENDOR_UNIT,):
            for prop in ne.UNIT_PROPERTIES:
                commands[(unit, prop)] = "n/a"
        commands[(ne.VENDOR_UNIT, "Type")] = "notify"
        return commands

    def test_profile_statics_include_and_abi_and_notify_rules(self):
        files = {
            ne.PROFILE_P: "#include <tunables/global>\nabi 4.0,\n/usr/sbin/mysqld x,\n",
            ne.PROFILE_R: "  /run/systemd/notify w,\n",
        }
        self.host = FakeHost(journal="-- No entries --", files=files,
                             commands=self.unit_commands())
        self.assertEqual(self.run_main(), 0)
        self.assertIn("PROFILE %s:1 #include <tunables/global>" % ne.PROFILE_P, self.output())
        self.assertIn("PROFILE %s:2 abi 4.0," % ne.PROFILE_P, self.output())
        self.assertIn("PROFILE_SOURCE %s notify_rule_present=no" % ne.PROFILE_P, self.output())
        self.assertIn("PROFILE %s:1 /run/systemd/notify w," % ne.PROFILE_R, self.output())
        self.assertIn("PROFILE_SOURCE %s notify_rule_present=yes" % ne.PROFILE_R, self.output())

    def test_unreadable_profile_source_is_flagged_only(self):
        self.host = FakeHost(journal="-- No entries --", commands=self.unit_commands())
        self.assertEqual(self.run_main(), 0)
        self.assertIn("PROFILE_SOURCE %s unreadable:" % ne.PROFILE_P, self.output())

    def test_unit_properties_are_printed_and_failures_flagged(self):
        commands = self.unit_commands()
        commands.pop((ne.UNITS[0], "PrivateTmp"))
        self.host = FakeHost(journal="-- No entries --", commands=commands)
        self.assertEqual(self.run_main(), 0)
        self.assertIn("UNITPROP %s Type=n/a" % ne.UNITS[0], self.output())
        self.assertIn("UNITPROP %s PrivateTmp UNKNOWN systemctl_show_failed"
                      % ne.UNITS[0], self.output())
        self.assertIn("UNITPROP %s Type=notify" % ne.VENDOR_UNIT, self.output())

    def test_since_and_lines_arguments_reach_journalctl(self):
        self.host = FakeHost(journal="-- No entries --")
        self.assertEqual(self.run_main(["--since", "2026-11-17 00:00:00", "--lines", "500"]), 0)
        args = self.host.run_args[0]
        self.assertIn("-S", args)
        self.assertIn("2026-11-17 00:00:00", args)
        self.assertIn("500", args)
        self.assertIn("JOURNAL lines_cap=500 since=2026-11-17 00:00:00", self.output())

    def test_lines_above_bound_is_rejected(self):
        self.host = FakeHost(journal="-- No entries --")
        self.assertEqual(self.run_main(["--lines", str(ne.MAX_LINES + 1)]), 4)
        self.assertIn("CODE=lines_argument_invalid", self.output())

    def test_unknown_argument_fails_closed(self):
        self.host = FakeHost(journal="-- No entries --")
        self.assertEqual(self.run_main(["--bogus"]), 4)
        self.assertIn("CODE=argument_invalid:--bogus", self.output())


if __name__ == "__main__":
    unittest.main(verbosity=2)
