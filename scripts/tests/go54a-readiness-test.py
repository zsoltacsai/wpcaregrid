"""Hermetic, non-root synthetic fixture tests for go54a-readiness.py.

The production tool is read-only and fail-closed; these tests inject a fake
host (filesystem tree, systemctl answers, lock probe, identity) and assert the
classification for the healthy OLD baseline and for unsafe or unexpected host
states. They are development evidence only: passing them does NOT approve the
tool for host use (independent Integration QA and AppSec are required).
"""

import importlib.util
import io
import posixpath
import stat
import unittest
from pathlib import Path
from types import SimpleNamespace

OPS = Path(__file__).parents[1] / "production-g1-ops"
SPEC = importlib.util.spec_from_file_location("go54a_readiness", OPS / "go54a-readiness.py")
rd = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(rd)


def fake_stat(kind, mode, uid, gid, size=0, mtime_ns=1000):
    if kind == "dir":
        full = stat.S_IFDIR | mode
    elif kind == "file":
        full = stat.S_IFREG | mode
    else:
        full = stat.S_IFLNK | 0o777
    return SimpleNamespace(st_mode=full, st_uid=uid, st_gid=gid, st_size=size,
                           st_mtime_ns=mtime_ns, st_nlink=1, st_dev=1, st_ino=1)


class FakeHost:
    """Implements the RealHost interface over an in-memory tree. No filesystem access."""

    def __init__(self, files=None, dirs=None, symlinks=None, euid=0,
                 ids=None, commands=None, lock=None, script_dir="/opt/toolkit-empty"):
        self.files = files or {}
        self.dirs = dirs or {}
        self.symlinks = symlinks or {}
        self.euid_value = euid
        self.ids_value = ids or {"mysql": (105, 105)}
        self.commands = commands or {}
        self.lock = lock or {}
        self.script_dir_value = script_dir

    def _all_paths(self):
        return list(self.files) + list(self.dirs) + list(self.symlinks)

    def euid(self):
        return self.euid_value

    def ids(self, name):
        if name not in self.ids_value:
            raise rd.HostError("identity_unavailable", name)
        return self.ids_value[name]

    def script_dir(self):
        return self.script_dir_value

    def lexists(self, path):
        return path in self.files or path in self.dirs or path in self.symlinks

    def lstat(self, path):
        if path in self.symlinks:
            return fake_stat("link", 0o777, 0, 0)
        if path in self.files:
            spec = self.files[path]
            return fake_stat("file", spec.get("mode", 0o644), spec.get("uid", 0),
                             spec.get("gid", 0), len(spec.get("content", b"")),
                             spec.get("mtime_ns", 1000))
        if path in self.dirs:
            spec = self.dirs[path]
            return fake_stat("dir", spec.get("mode", 0o755), spec.get("uid", 0),
                             spec.get("gid", 0), 0, spec.get("mtime_ns", 1000))
        raise FileNotFoundError(path)

    def _children(self, path):
        prefix = path.rstrip("/") + "/"
        return sorted(name for entry in self._all_paths()
                      if entry.startswith(prefix) and "/" not in entry[len(prefix):]
                      for name in [entry[len(prefix):]])

    def listdir(self, path):
        if path not in self.dirs:
            raise FileNotFoundError(path)
        return self._children(path)

    def read_regular(self, path, max_bytes):
        if path in self.symlinks or path not in self.files:
            raise FileNotFoundError(path)
        content = self.files[path].get("content", b"")
        if len(content) > max_bytes:
            raise rd.HostError("too_large", path)
        return content

    def read_text(self, path, max_bytes):
        return self.read_regular(path, max_bytes).decode("utf-8", errors="replace")

    def walk_tree(self, path):
        def recurse(directory, rel_base):
            for name in self._children(directory):
                full = directory.rstrip("/") + "/" + name
                rel = (rel_base + "/" + name) if rel_base else name
                yield rel, self.lstat(full)
                if full in self.dirs:
                    yield from recurse(full, rel)
        yield from recurse(path, "")

    def run(self, args, timeout=60):
        if args[:2] == ["/usr/bin/systemctl", "show"]:
            unit, prop = args[2], args[3].split("=", 1)[1]
            value = self.commands.get((unit, prop))
            if isinstance(value, list):
                if not value:
                    raise rd.HostError("systemctl_show_failed", unit + ":" + prop)
                value = value.pop(0)
            if value is None:
                raise rd.HostError("systemctl_show_failed", unit + ":" + prop)
            return 0, (str(value) + "\n").encode()
        raise rd.HostError("command_failed", args[0])

    def flock_shared_probe(self, path):
        if self.lock.get(path) == "held":
            return "held"
        return "free"


def old_bytes(path):
    return b"old:" + posixpath.basename(path).encode()


def new_bytes(path):
    return b"new:" + posixpath.basename(path).encode()


class ReadinessTestCase(unittest.TestCase):
    def setUp(self):
        self.saved_old, self.saved_new = dict(rd.OLD), dict(rd.NEW)
        rd.OLD.clear()
        rd.OLD.update({path: rd.sha256(old_bytes(path)) for path in rd.ORDER})
        rd.NEW.clear()
        rd.NEW.update({path: rd.sha256(new_bytes(path)) for path in rd.ORDER})
        self.addCleanup(self._restore)
        self.host, self.buffer = self.healthy_host(), io.StringIO()

    def _restore(self):
        rd.OLD.clear()
        rd.OLD.update(self.saved_old)
        rd.NEW.clear()
        rd.NEW.update(self.saved_new)

    def healthy_host(self, **overrides):
        files, dirs, commands = {}, {}, {}
        dirs["/run"] = {"mode": 0o755, "uid": 0, "gid": 0}
        dirs[rd.ROOT_DIR] = {"mode": 0o700, "uid": 0, "gid": 0}
        for path in rd.ORDER:
            files[path] = {"content": old_bytes(path), "mode": rd.MODES[path], "uid": 0, "gid": 0}
        filler = "\n".join("some-profile-%03d (enforce)" % i for i in range(400))
        loaded = (filler + "\nwpcaregrid-mysql-production (enforce)\n"
                  "wpcaregrid-mysql-production-restore (enforce)\n/usr/sbin/mysqld (enforce)\n")
        files[rd.LOADED] = {"content": loaded.encode(), "mode": 0o444, "uid": 0, "gid": 0}
        files[rd.VENDOR_PROFILE] = {"content": b"vendor policy\n", "mode": 0o644, "uid": 0, "gid": 0}
        for datadir in rd.DATADIRS:
            dirs[datadir] = {"mode": 0o750, "uid": 105, "gid": 105}
            files[datadir + "/ib_logfile0"] = {"content": b"data", "mode": 0o640, "uid": 105, "gid": 105}
        for log in rd.LOGS:
            files[log] = {"content": b"stable log line\n", "mode": 0o600, "uid": 105, "gid": 105}
        files["/proc/1131/comm"] = {"content": b"mysqld\n", "mode": 0o400, "uid": 0, "gid": 0}
        for unit in rd.UNITS:
            commands[(unit, "LoadState")] = "loaded"
            commands[(unit, "ActiveState")] = "inactive"
            commands[(unit, "SubState")] = "dead"
        commands[(rd.VENDOR_UNIT, "ActiveState")] = "active"
        commands[(rd.VENDOR_UNIT, "MainPID")] = "1131"
        spec = dict(files=files, dirs=dirs, commands=commands)
        spec.update(overrides)
        return FakeHost(**spec)

    def run_main(self, argv=()):
        self.buffer = io.StringIO()
        return rd.main(list(argv), host=self.host, stream=self.buffer)

    def output(self):
        return self.buffer.getvalue()

    def last_line(self):
        return self.output().strip().splitlines()[-1]

    # -- healthy baseline ----------------------------------------------------

    def test_healthy_old_baseline_passes(self):
        self.assertEqual(self.run_main(), 0)
        self.assertEqual(self.last_line(), "G1_GO54A_READINESS=PASS")
        self.assertIn("CHECK installed_baseline PASS baseline=old", self.output())
        self.assertIn("BACKUP_EVIDENCE=UNKNOWN;CHECKLIST=RACKFOREST_BACKUP_VERIFICATION_CHECKLIST",
                      self.output())
        self.assertIn("CHECK g1_units_inactive PASS both_units_exactly_inactive", self.output())
        self.assertIn("CHECK transaction_leftovers PASS", self.output())
        self.assertIn("NOTE backup_evidence restore_evidence_is_not_verifiable_by_a_host_read",
                      self.output())

    def test_profiles_listed_at_end_of_loaded_file_is_not_falsely_missing(self):
        # Regression for the earlier false NOT LOADED: G1 lines sit at the very end
        # of a long loaded-profiles list and must still be found.
        files = dict(self.host.files)
        files[rd.LOADED] = {"content": self.host.files[rd.LOADED]["content"] + b"late-filler (enforce)\n",
                            "mode": 0o444, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 0)
        self.assertEqual(self.last_line(), "G1_GO54A_READINESS=PASS")

    # -- units ----------------------------------------------------------------

    def test_active_unit_holds(self):
        commands = dict(self.host.commands)
        commands[(rd.UNITS[0], "ActiveState")] = "active"
        self.host = self.healthy_host(commands=commands)
        self.assertEqual(self.run_main(), 3)
        self.assertEqual(self.last_line(), "G1_GO54A_READINESS=HOLD")
        self.assertIn("HOLD g1_unit_not_inactive:" + rd.UNITS[0], self.output())

    def test_masked_unit_holds(self):
        commands = dict(self.host.commands)
        commands[(rd.UNITS[1], "LoadState")] = "masked"
        self.host = self.healthy_host(commands=commands)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD g1_unit_masked:" + rd.UNITS[1], self.output())

    def test_missing_unit_holds(self):
        commands = dict(self.host.commands)
        commands[(rd.UNITS[0], "LoadState")] = "not-found"
        self.host = self.healthy_host(commands=commands)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD g1_unit_not_found:" + rd.UNITS[0], self.output())

    # -- installed baseline ---------------------------------------------------

    def test_new_baseline_reports_already_upgraded_hold(self):
        files = dict(self.host.files)
        for path in rd.ORDER:
            files[path] = {"content": new_bytes(path), "mode": rd.MODES[path], "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD already_upgraded_baseline=new", self.output())
        self.assertEqual(self.last_line(), "G1_GO54A_READINESS=HOLD")

    def test_mixed_baseline_holds(self):
        files = dict(self.host.files)
        files[rd.MANIFEST] = {"content": b"new:" + posixpath.basename(rd.MANIFEST).encode(),
                               "mode": 0o644, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD baseline_mixed_old_and_new", self.output())

    def test_unknown_hash_holds(self):
        files = dict(self.host.files)
        files[rd.HELPER] = {"content": b"tampered", "mode": 0o755, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD file_hash_mismatch:wpcaregrid-g1-mysql.py", self.output())

    def test_missing_artifact_holds(self):
        files = dict(self.host.files)
        del files[rd.PROFILE_R]
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD installed_file_missing:wpcaregrid-mysql-production-restore",
                      self.output())

    def test_wrong_mode_holds(self):
        files = dict(self.host.files)
        files[rd.HELPER] = {"content": old_bytes(rd.HELPER), "mode": 0o644, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD installed_file_mode_invalid:wpcaregrid-g1-mysql.py", self.output())

    def test_artifact_symlink_holds(self):
        symlinks = {rd.HELPER: "/elsewhere"}
        files = dict(self.host.files)
        del files[rd.HELPER]
        self.host = self.healthy_host(files=files, symlinks=symlinks)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD installed_file_symlink:wpcaregrid-g1-mysql.py", self.output())

    # -- apparmor -------------------------------------------------------------

    def test_complain_mode_holds(self):
        files = dict(self.host.files)
        content = self.host.files[rd.LOADED]["content"].decode()
        files[rd.LOADED] = {"content": content.replace(
            "wpcaregrid-mysql-production (enforce)", "wpcaregrid-mysql-production (complain)").encode(),
            "mode": 0o444, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD profile_not_enforced:wpcaregrid-mysql-production", self.output())

    def test_profile_not_loaded_holds(self):
        files = dict(self.host.files)
        content = self.host.files[rd.LOADED]["content"].decode()
        files[rd.LOADED] = {"content": content.replace("wpcaregrid-mysql-production-restore (enforce)\n",
                                                       "", 1).encode(), "mode": 0o444, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD profile_not_loaded:wpcaregrid-mysql-production-restore", self.output())

    def test_vendor_loaded_line_missing_holds(self):
        files = dict(self.host.files)
        content = self.host.files[rd.LOADED]["content"].decode()
        files[rd.LOADED] = {"content": content.replace("/usr/sbin/mysqld (enforce)\n", "", 1).encode(),
                            "mode": 0o444, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD vendor_profile_line_unexpected", self.output())

    # -- vendor mysql ---------------------------------------------------------

    def test_vendor_mysql_not_active_holds(self):
        commands = dict(self.host.commands)
        commands[(rd.VENDOR_UNIT, "ActiveState")] = "inactive"
        self.host = self.healthy_host(commands=commands)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD vendor_mysql_not_active", self.output())

    def test_vendor_pid_changed_during_run_holds(self):
        commands = dict(self.host.commands)
        commands[(rd.VENDOR_UNIT, "MainPID")] = ["1131", "2049"]
        self.host = self.healthy_host(commands=commands)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD vendor_mysql_pid_changed_during_run", self.output())

    def test_vendor_pid_not_mysqld_holds(self):
        files = dict(self.host.files)
        files["/proc/1131/comm"] = {"content": b"other\n", "mode": 0o400, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD vendor_mysql_pid_not_mysqld", self.output())

    # -- datadirs and logs ----------------------------------------------------

    def test_datadir_entry_symlink_holds(self):
        files, symlinks = dict(self.host.files), {rd.DATADIRS[0] + "/strange": "/etc"}
        self.host = self.healthy_host(files=files, symlinks=symlinks)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD datadir_entry_invalid:strange", self.output())

    def test_datadir_wrong_owner_holds(self):
        files = dict(self.host.files)
        files[rd.DATADIRS[0] + "/ib_logfile0"] = {"content": b"data", "mode": 0o640, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD datadir_entry_owner_or_mode_invalid:ib_logfile0", self.output())

    def test_datadir_missing_holds(self):
        dirs = dict(self.host.dirs)
        del dirs[rd.DATADIRS[1]]
        files = dict(self.host.files)
        del files[rd.DATADIRS[1] + "/ib_logfile0"]
        self.host = self.healthy_host(files=files, dirs=dirs)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD datadir_missing:" + rd.DATADIRS[1], self.output())

    def test_log_wrong_mode_holds(self):
        files = dict(self.host.files)
        files[rd.LOGS[0]] = {"content": b"log\n", "mode": 0o644, "uid": 105, "gid": 105}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD mysql_log_mode_invalid:" + posixpath.basename(rd.LOGS[0]),
                      self.output())

    # -- transaction leftovers and lock ---------------------------------------

    def test_upgrade_journal_present_holds(self):
        files = dict(self.host.files)
        files[rd.JOURNAL] = {"content": b"{}", "mode": 0o600, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD upgrade_journal_present_manual_review_required", self.output())

    def test_journal_temporary_present_holds(self):
        files = dict(self.host.files)
        files[rd.JOURNAL + ".tmp"] = {"content": b"{}", "mode": 0o600, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD journal_temporary_present_manual_review_required", self.output())

    def test_leftover_temporary_file_holds(self):
        files = dict(self.host.files)
        files[rd.temp_name(rd.CONTRACT)] = {"content": b"x", "mode": 0o600, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD leftover_temporary_file:", self.output())

    def test_lock_symlink_holds(self):
        files, symlinks = dict(self.host.files), {rd.LOCK: "/elsewhere"}
        self.host = self.healthy_host(files=files, symlinks=symlinks)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD lock_file_symlink", self.output())

    def test_lock_wrong_mode_holds(self):
        files = dict(self.host.files)
        files[rd.LOCK] = {"content": b"", "mode": 0o666, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD lock_file_mode_invalid", self.output())

    def test_lock_held_by_other_process_holds(self):
        files = dict(self.host.files)
        files[rd.LOCK] = {"content": b"", "mode": 0o600, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files, lock={rd.LOCK: "held"})
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD lock_held_by_other_process", self.output())

    def test_lock_directory_group_writable_holds(self):
        dirs = dict(self.host.dirs)
        dirs["/run"] = {"mode": 0o775, "uid": 0, "gid": 0}
        self.host = self.healthy_host(dirs=dirs)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD lock_directory_mode_unsafe", self.output())

    # -- package intake -------------------------------------------------------

    def valid_package(self, name="/root/wpcg-go54a-abc1234"):
        files, dirs = dict(self.host.files), dict(self.host.dirs)
        dirs[name] = {"mode": 0o700, "uid": 0, "gid": 0}
        sums = ""
        for path, payload in rd.PACKAGE.items():
            content = new_bytes(path)
            files[name + "/" + payload] = {"content": content, "mode": 0o600, "uid": 0, "gid": 0}
            sums += rd.sha256(content) + "  " + payload + "\n"
        files[name + "/" + rd.OPS_SUMS_NAME] = {"content": sums.encode(), "mode": 0o600, "uid": 0, "gid": 0}
        return files, dirs

    def test_explicit_package_valid_passes(self):
        files, dirs = self.valid_package()
        self.host = self.healthy_host(files=files, dirs=dirs)
        self.assertEqual(self.run_main(["--package", "/root/wpcg-go54a-abc1234"]), 0)
        self.assertEqual(self.last_line(), "G1_GO54A_READINESS=PASS")
        self.assertIn("CHECK package_intake PASS package_verified", self.output())

    def test_explicit_package_extra_entry_holds(self):
        files, dirs = self.valid_package()
        files["/root/wpcg-go54a-abc1234/extra"] = {"content": b"x", "mode": 0o600, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files, dirs=dirs)
        self.assertEqual(self.run_main(["--package", "/root/wpcg-go54a-abc1234"]), 3)
        self.assertIn("HOLD package_invalid:invalid:package_contents", self.output())

    def test_explicit_package_hash_mismatch_holds(self):
        files, dirs = self.valid_package()
        files["/root/wpcg-go54a-abc1234/production-g1-mysql.py"] = {
            "content": b"tampered", "mode": 0o600, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files, dirs=dirs)
        self.assertEqual(self.run_main(["--package", "/root/wpcg-go54a-abc1234"]), 3)
        self.assertIn("HOLD package_invalid:invalid:package_hash_mismatch:production-g1-mysql.py",
                      self.output())

    def test_incoming_unverified_directory_holds(self):
        files, dirs = self.valid_package(name="/root/wpcg-go54a-abc1234.incoming-UNVERIFIED")
        self.host = self.healthy_host(files=files, dirs=dirs)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD incoming_package_unverified_present:wpcg-go54a-abc1234.incoming-UNVERIFIED",
                      self.output())

    def test_staged_promoted_package_is_validated(self):
        files, dirs = self.valid_package()
        self.host = self.healthy_host(files=files, dirs=dirs)
        self.assertEqual(self.run_main(), 0)
        self.assertIn("CHECK package_intake PASS staged_packages_recognized_and_validated",
                      self.output())
        self.assertIn("NOTE staged_package_validated:wpcg-go54a-abc1234", self.output())

    def test_unrecognized_staged_directory_holds(self):
        files, dirs = self.valid_package()
        files["/root/wpcg-go54a-abc1234/production-g1-mysql.py"] = {
            "content": b"tampered", "mode": 0o600, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files, dirs=dirs)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD staged_package_invalid:wpcg-go54a-abc1234:", self.output())

    # -- toolkit companions ----------------------------------------------------

    def test_companions_matching_pins_pass(self):
        files, dirs = {}, {"companions": {"mode": 0o755, "uid": 0, "gid": 0}}
        files["companions/" + rd.UPGRADER_NAME] = {"content": b"upgrader", "mode": 0o755, "uid": 0, "gid": 0}
        files["companions/" + rd.FETCH_NAME] = {"content": b"fetch", "mode": 0o755, "uid": 0, "gid": 0}
        files["companions/" + rd.OPS_SUMS_NAME] = {"content": b"sums", "mode": 0o644, "uid": 0, "gid": 0}
        saved = (rd.EXPECTED_UPGRADER_SHA, rd.EXPECTED_FETCH_SHA, rd.EXPECTED_OPS_SUMS_SHA)
        rd.EXPECTED_UPGRADER_SHA = rd.sha256(b"upgrader")
        rd.EXPECTED_FETCH_SHA = rd.sha256(b"fetch")
        rd.EXPECTED_OPS_SUMS_SHA = rd.sha256(b"sums")
        self.addCleanup(lambda: (setattr(rd, "EXPECTED_UPGRADER_SHA", saved[0]),
                                 setattr(rd, "EXPECTED_FETCH_SHA", saved[1]),
                                 setattr(rd, "EXPECTED_OPS_SUMS_SHA", saved[2])))
        host = self.healthy_host(files={**self.host.files, **files},
                                 dirs={**self.host.dirs, **dirs}, script_dir="companions")
        self.host = host
        self.assertEqual(self.run_main(), 0)
        self.assertIn("CHECK toolkit_companions PASS companions_match_reviewed_pins", self.output())

    def test_companion_hash_mismatch_holds(self):
        files, dirs = {}, {"companions": {"mode": 0o755, "uid": 0, "gid": 0}}
        files["companions/" + rd.UPGRADER_NAME] = {"content": b"upgrader", "mode": 0o755, "uid": 0, "gid": 0}
        files["companions/" + rd.FETCH_NAME] = {"content": b"fetch", "mode": 0o755, "uid": 0, "gid": 0}
        files["companions/" + rd.OPS_SUMS_NAME] = {"content": b"sums", "mode": 0o644, "uid": 0, "gid": 0}
        saved = (rd.EXPECTED_UPGRADER_SHA, rd.EXPECTED_FETCH_SHA, rd.EXPECTED_OPS_SUMS_SHA)
        rd.EXPECTED_UPGRADER_SHA = rd.sha256(b"different")
        rd.EXPECTED_FETCH_SHA = rd.sha256(b"fetch")
        rd.EXPECTED_OPS_SUMS_SHA = rd.sha256(b"sums")
        self.addCleanup(lambda: (setattr(rd, "EXPECTED_UPGRADER_SHA", saved[0]),
                                 setattr(rd, "EXPECTED_FETCH_SHA", saved[1]),
                                 setattr(rd, "EXPECTED_OPS_SUMS_SHA", saved[2])))
        self.host = self.healthy_host(files={**self.host.files, **files},
                                      dirs={**self.host.dirs, **dirs}, script_dir="companions")
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD toolkit_companion_mismatch:" + rd.UPGRADER_NAME, self.output())

    # -- fail-closed identity -------------------------------------------------

    def test_not_root_yields_unknown_not_pass(self):
        self.host = self.healthy_host(euid=1000)
        self.assertEqual(self.run_main(), 4)
        self.assertEqual(self.last_line(), "G1_GO54A_READINESS=UNKNOWN")
        self.assertIn("CHECK console_root UNKNOWN not_root_console", self.output())

    def test_loaded_profiles_unavailable_yields_unknown(self):
        files = dict(self.host.files)
        del files[rd.LOADED]
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 4)
        self.assertIn("CHECK apparmor_enforced UNKNOWN filesystem_error", self.output())

    def test_invalid_argument_fails_closed(self):
        self.assertEqual(self.run_main(["--bogus"]), 3)
        self.assertEqual(self.last_line(), "G1_GO54A_READINESS=HOLD;CODE=argument_invalid:--bogus")


if __name__ == "__main__":
    unittest.main(verbosity=2)
