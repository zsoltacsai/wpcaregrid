"""Hermetic, non-root synthetic fixture tests for go54a-readiness.py.

The production tool is read-only and fail-closed; these tests inject a fake
host (filesystem tree, systemctl answers, lock probe, identity) and assert the
classification for the healthy OLD baseline and for unsafe or unexpected host
states. They are development evidence only: passing them does NOT approve the
tool for host use (independent Integration QA and AppSec are required).
"""

import errno
import importlib.util
import io
import posixpath
import stat
import unittest
from pathlib import Path
from types import SimpleNamespace

OPS = Path(__file__).parents[1] / "production-g1-diagnostics"
SPEC = importlib.util.spec_from_file_location("go54a_readiness", OPS / "go54a-readiness.py")
rd = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(rd)

TOOLKIT_SIBLING = "production-g1-ops"


def fake_stat(kind, mode, uid, gid, size=0, mtime_ns=1000, ino=1, ctime_ns=1000):
    if kind == "dir":
        full = stat.S_IFDIR | mode
    elif kind == "file":
        full = stat.S_IFREG | mode
    else:
        full = stat.S_IFLNK | 0o777
    return SimpleNamespace(st_mode=full, st_uid=uid, st_gid=gid, st_size=size,
                           st_mtime_ns=mtime_ns, st_nlink=1, st_dev=1, st_ino=ino,
                           st_ctime_ns=ctime_ns)


class FakeHost:
    """Implements the RealHost interface over an in-memory tree. No filesystem access."""

    def __init__(self, files=None, dirs=None, symlinks=None, euid=0,
                 ids=None, commands=None, lock=None, script_dir="/opt/toolkit-empty",
                 mutate_on_read=None, replace_on_read=None, rewrite_on_read=None,
                 grow_dir_after_listing=None, replace_dir_after_listing=None):
        self.files = files or {}
        self.dirs = dirs or {}
        self.symlinks = symlinks or {}
        self.euid_value = euid
        self.ids_value = ids or {"mysql": (105, 105)}
        self.commands = commands or {}
        self.lock = lock or {}
        self.script_dir_value = script_dir
        self.mutate_on_read = mutate_on_read or set()
        # Simulated evasions from the AppSec round-3 review: a same-size inode
        # replacement with restored mtime, and an in-place rewrite with
        # restored mtime (ctime cannot be restored on a real host).
        self.replace_on_read = replace_on_read or set()
        self.rewrite_on_read = rewrite_on_read or set()
        # Simulated evasions from the AppSec round-4 review: an entry added
        # after a directory's listing (directory mtime changes), and a child
        # directory replaced during traversal (directory inode changes).
        self.grow_dir_after_listing = grow_dir_after_listing or set()
        self.replace_dir_after_listing = replace_dir_after_listing or set()
        # Round-6 simulations: a file swapped between the walk stat and the
        # descriptor open, and a concurrent transaction that replaces an
        # installed artifact after the initial baseline read (the closing
        # re-verification must catch the stale evidence).
        self.swap_before_open = set()
        self.swap_after_reads = {}
        self.read_counts = {}
        # Round-7 simulation: when the mid-run artifact swap fires, a
        # transaction lock file appears and is HELD (an updater running
        # while readiness still collects evidence).
        self.reveal_held_lock_on_swap = False

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
                             spec.get("mtime_ns", 1000), spec.get("ino", 1),
                             spec.get("ctime_ns", 1000))
        if path in self.dirs:
            spec = self.dirs[path]
            return fake_stat("dir", spec.get("mode", 0o755), spec.get("uid", 0),
                             spec.get("gid", 0), 0, spec.get("mtime_ns", 1000),
                             spec.get("ino", 1), spec.get("ctime_ns", 1000))
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
        if path in self.symlinks:
            raise OSError(errno.ELOOP, "symlink", path)
        if path in self.dirs:
            raise rd.HostError("not_regular", posixpath.basename(path))
        if path not in self.files:
            raise FileNotFoundError(path)
        self.read_counts[path] = self.read_counts.get(path, 0) + 1
        if self.read_counts[path] > self.swap_after_reads.get(path, 1 << 30):
            self.files[path]["content"] = self.files[path].get("content2",
                                                              self.files[path].get("content", b""))
            if self.reveal_held_lock_on_swap:
                self.files[rd.LOCK] = {"content": b"", "mode": 0o600, "uid": 0, "gid": 0}
                self.lock[rd.LOCK] = "held"
        content = self.files[path].get("content", b"")
        if len(content) > max_bytes:
            raise rd.HostError("too_large", posixpath.basename(path))
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

    def read_regular_stable(self, path, max_bytes):
        """Mirrors RealHost.read_regular_stable faithfully: the opened
        descriptor keeps identifying the ORIGINAL inode even after a path
        replacement, so the descriptor views are stable while a fresh path
        stat reflects the rebound name."""
        if path in self.symlinks:
            raise OSError(errno.ELOOP, "symlink", path)
        if path in self.dirs:
            raise rd.HostError("not_regular", posixpath.basename(path))
        if path not in self.files:
            raise FileNotFoundError(path)
        st_fd_before = self.lstat(path)
        data = self.files[path].get("content", b"")
        if len(data) > max_bytes:
            raise rd.HostError("too_large", posixpath.basename(path))
        if path in self.mutate_on_read:
            self.files[path]["mtime_ns"] = self.files[path].get("mtime_ns", 1000) + 1
        if path in self.rewrite_on_read:
            self.files[path]["ctime_ns"] = self.files[path].get("ctime_ns", 1000) + 1
        st_fd_after = self.lstat(path)
        if path in self.replace_on_read:
            self.files[path]["ino"] = self.files[path].get("ino", 1) + 1
        st_path_after = self.lstat(path)
        return data, st_fd_before, st_fd_after, st_path_after

    def datadir_fingerprint(self, path, mysql_ids, max_entries, max_total_bytes, max_file_bytes):
        """Mirrors RealHost.datadir_fingerprint faithfully over the in-memory
        tree: like os.fwalk, the level's names are collected FIRST and the
        level stat is captured afterwards, so the mutation hooks simulate the
        window between name collection and the code's fstat. Every child
        directory's level stat is compared against the stat its parent
        recorded before descent, and every level is re-verified after the
        whole walk."""
        root = self.lstat(path)
        rd.datadir_root_check(root, path, mysql_ids)
        records, entries, total = [], 0, 0
        levels = []
        parent_expected = {}

        def process(directory, rel_base):
            nonlocal entries, total
            children = self._children(directory)
            dir_names = sorted(name for name in children
                               if (directory.rstrip("/") + "/" + name) in self.dirs)
            file_names = sorted(name for name in children if name not in dir_names)
            # fwalk collected the names above; hooks mutate in the window
            # before the code fstats the level.
            if directory in self.grow_dir_after_listing:
                self.dirs[directory]["mtime_ns"] = self.dirs[directory].get("mtime_ns", 1000) + 1
            if directory in self.replace_dir_after_listing:
                self.dirs[directory]["ino"] = self.dirs[directory].get("ino", 1) + 1
            level_stat = self.lstat(directory)
            if rel_base == "":
                if any(getattr(level_stat, field) != getattr(root, field)
                       for field in rd.MUTATION_IDENTITY):
                    raise rd.HostError("datadir_changed_during_read", posixpath.basename(path))
            else:
                expected = parent_expected.get(rel_base)
                if expected is not None and any(getattr(level_stat, field) != getattr(expected, field)
                                                for field in rd.MUTATION_IDENTITY):
                    raise rd.HostError("datadir_changed_during_read", rel_base)
            levels.append((rel_base, level_stat))
            subdirs = []
            for name in dir_names + file_names:
                full = directory.rstrip("/") + "/" + name
                rel = (rel_base + "/" + name) if rel_base else name
                st = self.lstat(full)
                entries += 1
                if entries > max_entries:
                    raise rd.HostError("datadir_entry_count_exceeded", path)
                rd.validate_datadir_entry(rel, st, mysql_ids)
                if stat.S_ISDIR(st.st_mode):
                    parent_expected[rel] = st
                digest = "-"
                if stat.S_ISREG(st.st_mode):
                    if full in self.swap_before_open:
                        # Model the race, then run the PRODUCTION check so the
                        # regression exercises the real logic (AppSec R7-F2).
                        self.files[full]["ino"] = self.files[full].get("ino", 1) + 1
                        rd.datadir_open_identity_check(rel, st, self.lstat(full))
                    if st.st_size > max_file_bytes or total + st.st_size > max_total_bytes:
                        raise rd.HostError("datadir_exceeds_bounds", rel)
                    data = self.read_regular(full, max_file_bytes)
                    if full in self.mutate_on_read:
                        self.files[full]["mtime_ns"] = self.files[full].get("mtime_ns", 1000) + 1
                    if full in self.rewrite_on_read:
                        self.files[full]["ctime_ns"] = self.files[full].get("ctime_ns", 1000) + 1
                    st_after_fd = self.lstat(full)
                    if full in self.replace_on_read:
                        self.files[full]["ino"] = self.files[full].get("ino", 1) + 1
                    st_after_path = self.lstat(full)
                    total = rd.datadir_read_check(rel, st, st_after_fd, st_after_path,
                                                   len(data), total, max_total_bytes)
                    digest = rd.sha256(data)
                records.append(rd.datadir_record(rel, st, digest))
                if full in self.dirs:
                    subdirs.append((full, rel))
            for full, rel in subdirs:
                process(full, rel)

        process(path, "")
        for rel, st_recorded in levels:
            current = self.lstat(posixpath.join(path, rel) if rel else path)
            if any(getattr(current, field) != getattr(st_recorded, field)
                   for field in rd.MUTATION_IDENTITY):
                raise rd.HostError("datadir_changed_during_read",
                                   rel or posixpath.basename(path))
        material = repr((stat.S_IMODE(root.st_mode), root.st_uid, root.st_gid,
                         root.st_mtime_ns, records)).encode()
        return rd.sha256(material), entries, total

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
        dirs[rd.LIBEXEC] = {"mode": 0o755, "uid": 0, "gid": 0}
        dirs[rd.APPARMOR] = {"mode": 0o755, "uid": 0, "gid": 0}
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
        self.assertIn("HOLD staged_package_invalid:wpcg-go54a-abc1234:invalid:package_contents",
                      self.output())

    def test_explicit_package_hash_mismatch_holds(self):
        files, dirs = self.valid_package()
        files["/root/wpcg-go54a-abc1234/production-g1-mysql.py"] = {
            "content": b"tampered", "mode": 0o600, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files, dirs=dirs)
        self.assertEqual(self.run_main(["--package", "/root/wpcg-go54a-abc1234"]), 3)
        self.assertIn("HOLD staged_package_invalid:wpcg-go54a-abc1234:"
                      "invalid:package_hash_mismatch:production-g1-mysql.py",
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
        files = {}
        files[TOOLKIT_SIBLING + "/" + rd.UPGRADER_NAME] = {"content": b"upgrader", "mode": 0o755, "uid": 0, "gid": 0}
        files[TOOLKIT_SIBLING + "/" + rd.FETCH_NAME] = {"content": b"fetch", "mode": 0o755, "uid": 0, "gid": 0}
        files[TOOLKIT_SIBLING + "/" + rd.OPS_SUMS_NAME] = {"content": b"sums", "mode": 0o644, "uid": 0, "gid": 0}
        saved = (rd.EXPECTED_UPGRADER_SHA, rd.EXPECTED_FETCH_SHA, rd.EXPECTED_OPS_SUMS_SHA)
        rd.EXPECTED_UPGRADER_SHA = rd.sha256(b"upgrader")
        rd.EXPECTED_FETCH_SHA = rd.sha256(b"fetch")
        rd.EXPECTED_OPS_SUMS_SHA = rd.sha256(b"sums")
        self.addCleanup(lambda: (setattr(rd, "EXPECTED_UPGRADER_SHA", saved[0]),
                                 setattr(rd, "EXPECTED_FETCH_SHA", saved[1]),
                                 setattr(rd, "EXPECTED_OPS_SUMS_SHA", saved[2])))
        self.host = self.healthy_host(files={**self.host.files, **files}, script_dir="companions")
        self.assertEqual(self.run_main(), 0)
        self.assertIn("CHECK toolkit_companions PASS companions_match_reviewed_pins", self.output())
        self.assertIn("NOTE toolkit_companions_verified:" + TOOLKIT_SIBLING, self.output())

    def test_companion_hash_mismatch_holds(self):
        files = {}
        files[TOOLKIT_SIBLING + "/" + rd.UPGRADER_NAME] = {"content": b"upgrader", "mode": 0o755, "uid": 0, "gid": 0}
        files[TOOLKIT_SIBLING + "/" + rd.FETCH_NAME] = {"content": b"fetch", "mode": 0o755, "uid": 0, "gid": 0}
        files[TOOLKIT_SIBLING + "/" + rd.OPS_SUMS_NAME] = {"content": b"sums", "mode": 0o644, "uid": 0, "gid": 0}
        saved = (rd.EXPECTED_UPGRADER_SHA, rd.EXPECTED_FETCH_SHA, rd.EXPECTED_OPS_SUMS_SHA)
        rd.EXPECTED_UPGRADER_SHA = rd.sha256(b"different")
        rd.EXPECTED_FETCH_SHA = rd.sha256(b"fetch")
        rd.EXPECTED_OPS_SUMS_SHA = rd.sha256(b"sums")
        self.addCleanup(lambda: (setattr(rd, "EXPECTED_UPGRADER_SHA", saved[0]),
                                 setattr(rd, "EXPECTED_FETCH_SHA", saved[1]),
                                 setattr(rd, "EXPECTED_OPS_SUMS_SHA", saved[2])))
        self.host = self.healthy_host(files={**self.host.files, **files}, script_dir="companions")
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD toolkit_companion_mismatch:" + TOOLKIT_SIBLING + "/" + rd.UPGRADER_NAME,
                      self.output())

    def test_partial_companion_set_holds(self):
        files = {TOOLKIT_SIBLING + "/" + rd.UPGRADER_NAME:
                 {"content": b"upgrader", "mode": 0o755, "uid": 0, "gid": 0}}
        self.host = self.healthy_host(files={**self.host.files, **files}, script_dir="companions")
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD toolkit_companions_incomplete:" + TOOLKIT_SIBLING, self.output())

    def test_diagnostics_sums_file_pins_exactly_both_tools(self):
        sums = (OPS / "SHA256SUMS").read_text(encoding="ascii")
        listed = {}
        for line in sums.splitlines():
            digest, name = line.split("  ", 1)
            listed[name] = digest
        self.assertEqual(listed, {
            "go54a-readiness.py": rd.sha256((OPS / "go54a-readiness.py").read_bytes()),
            "g1-notify-evidence.py": rd.sha256((OPS / "g1-notify-evidence.py").read_bytes()),
        })
        self.assertEqual(sorted(p.name for p in OPS.iterdir() if p.is_file()),
                         ["SHA256SUMS", "g1-notify-evidence.py", "go54a-readiness.py"])

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

    # -- independent-reviewer findings (QA B1-B3, AppSec F5, hardening) ------

    def test_constant_parity_with_the_transaction_tool(self):
        # Compare against the REAL transaction pins, not the synthetic fixture
        # hashes that setUp installs for the other cases.
        rd.OLD.clear()
        rd.OLD.update(self.saved_old)
        rd.NEW.clear()
        rd.NEW.update(self.saved_new)
        ops = Path(__file__).parents[1] / "production-g1-ops"
        spec = importlib.util.spec_from_file_location("go54a_transaction_reference",
                                                     ops / "go54a-upgrade-apparmor.py")
        reference = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(reference)
        for name in ("HELPER", "CONTRACT", "MANIFEST", "PROFILE_P", "PROFILE_R", "ORDER",
                     "OLD", "NEW", "PACKAGE", "MODES", "LOCK", "JOURNAL", "UNITS",
                     "DATADIRS", "LOGS", "PROFILE_NAMES"):
            self.assertEqual(getattr(rd, name), getattr(reference, name), name)
        self.assertEqual(rd.MANIFEST, "/usr/local/libexec/production_g1_mysql_manifest.json")
        self.assertEqual(rd.EXPECTED_UPGRADER_SHA,
                         rd.sha256((ops / "go54a-upgrade-apparmor.py").read_bytes()))
        self.assertEqual(rd.EXPECTED_FETCH_SHA,
                         rd.sha256((ops / "go54a-fetch-package.sh").read_bytes()))
        self.assertEqual(rd.EXPECTED_OPS_SUMS_SHA, rd.sha256((ops / "SHA256SUMS").read_bytes()))

    def test_companions_verified_when_run_from_own_directory_with_own_sums(self):
        # QA B2 regression: the diagnostics directory legitimately contains its
        # own SHA256SUMS; running from it must verify the sibling ops toolkit,
        # never mistake the own sums for a partial companion set.
        base = "/repo/scripts/production-g1-diagnostics"
        sibling = "/repo/scripts/production-g1-ops"
        files = {base + "/SHA256SUMS": {"content": b"diagnostics sums\n", "mode": 0o644, "uid": 0, "gid": 0}}
        for name, content, mode in ((rd.UPGRADER_NAME, b"upgrader", 0o755),
                                     (rd.FETCH_NAME, b"fetch", 0o755),
                                     (rd.OPS_SUMS_NAME, b"sums", 0o644)):
            files[sibling + "/" + name] = {"content": content, "mode": mode, "uid": 0, "gid": 0}
        saved = (rd.EXPECTED_UPGRADER_SHA, rd.EXPECTED_FETCH_SHA, rd.EXPECTED_OPS_SUMS_SHA)
        rd.EXPECTED_UPGRADER_SHA = rd.sha256(b"upgrader")
        rd.EXPECTED_FETCH_SHA = rd.sha256(b"fetch")
        rd.EXPECTED_OPS_SUMS_SHA = rd.sha256(b"sums")
        self.addCleanup(lambda: (setattr(rd, "EXPECTED_UPGRADER_SHA", saved[0]),
                                 setattr(rd, "EXPECTED_FETCH_SHA", saved[1]),
                                 setattr(rd, "EXPECTED_OPS_SUMS_SHA", saved[2])))
        self.host = self.healthy_host(files={**self.host.files, **files}, script_dir=base)
        self.assertEqual(self.run_main(), 0)
        self.assertIn("NOTE toolkit_companions_verified:" + sibling, self.output())
        self.assertNotIn("toolkit_companions_incomplete", self.output())

    def test_flat_staging_with_diagnostics_sums_holds(self):
        # A flat layout that mixes the diagnostics' own SHA256SUMS with the ops
        # executables cannot satisfy the ops pins and must HOLD explicitly.
        base = "/stage/diagnostics"
        files = {
            base + "/SHA256SUMS": {"content": b"diagnostics sums\n", "mode": 0o644, "uid": 0, "gid": 0},
            base + "/" + rd.UPGRADER_NAME: {"content": b"upgrader", "mode": 0o755, "uid": 0, "gid": 0},
            base + "/" + rd.FETCH_NAME: {"content": b"fetch", "mode": 0o755, "uid": 0, "gid": 0},
        }
        saved = (rd.EXPECTED_UPGRADER_SHA, rd.EXPECTED_FETCH_SHA, rd.EXPECTED_OPS_SUMS_SHA)
        rd.EXPECTED_UPGRADER_SHA = rd.sha256(b"upgrader")
        rd.EXPECTED_FETCH_SHA = rd.sha256(b"fetch")
        rd.EXPECTED_OPS_SUMS_SHA = rd.sha256(b"ops sums")
        self.addCleanup(lambda: (setattr(rd, "EXPECTED_UPGRADER_SHA", saved[0]),
                                 setattr(rd, "EXPECTED_FETCH_SHA", saved[1]),
                                 setattr(rd, "EXPECTED_OPS_SUMS_SHA", saved[2])))
        self.host = self.healthy_host(files={**self.host.files, **files}, script_dir=base)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD toolkit_companion_mismatch:diagnostics/" + rd.OPS_SUMS_NAME,
                      self.output())

    def test_explicit_package_empty_argument_fails_closed(self):
        self.assertEqual(self.run_main(["--package", ""]), 3)
        self.assertEqual(self.last_line(), "G1_GO54A_READINESS=HOLD;CODE=package_argument_invalid:empty")

    def test_duplicate_package_argument_fails_closed(self):
        self.assertEqual(self.run_main(["--package", "/a", "--package", "/b"]), 3)
        self.assertEqual(self.last_line(), "G1_GO54A_READINESS=HOLD;CODE=duplicate_package_argument")

    def test_explicit_package_missing_directory_holds(self):
        self.assertEqual(self.run_main(["--package", "/nonexistent-package"]), 3)
        self.assertIn("HOLD package_invalid:invalid:package_directory_missing", self.output())

    def test_explicit_package_still_scans_root_for_incoming(self):
        # AppSec F5 regression: an explicit valid package must not hide an
        # unverified incoming staging directory.
        files_a, dirs_a = self.valid_package()
        files_b, dirs_b = self.valid_package(name="/root/wpcg-go54a-def5678.incoming-UNVERIFIED")
        self.host = self.healthy_host(files={**files_a, **files_b}, dirs={**dirs_a, **dirs_b})
        self.assertEqual(self.run_main(["--package", "/root/wpcg-go54a-abc1234"]), 3)
        self.assertIn("HOLD incoming_package_unverified_present:wpcg-go54a-def5678.incoming-UNVERIFIED",
                      self.output())

    def test_parent_directory_unsafe_holds(self):
        dirs = dict(self.host.dirs)
        dirs[rd.APPARMOR] = {"mode": 0o775, "uid": 0, "gid": 0}
        self.host = self.healthy_host(dirs=dirs)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD parent_directory_mode_unsafe:" + rd.APPARMOR, self.output())

    def test_parent_directory_missing_holds(self):
        dirs = dict(self.host.dirs)
        del dirs[rd.LIBEXEC]
        self.host = self.healthy_host(dirs=dirs)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD parent_directory_missing:" + rd.LIBEXEC, self.output())

    def test_failed_unit_holds(self):
        commands = dict(self.host.commands)
        commands[(rd.UNITS[0], "ActiveState")] = "failed"
        commands[(rd.UNITS[0], "SubState")] = "failed"
        self.host = self.healthy_host(commands=commands)
        self.assertEqual(self.run_main(), 3)
        self.assertIn("HOLD g1_unit_not_inactive:" + rd.UNITS[0], self.output())

    def test_datadir_entry_count_bound_yields_unknown(self):
        saved = rd.MAX_DATADIR_ENTRIES
        rd.MAX_DATADIR_ENTRIES = 1
        self.addCleanup(setattr, rd, "MAX_DATADIR_ENTRIES", saved)
        files = dict(self.host.files)
        files[rd.DATADIRS[0] + "/second"] = {"content": b"x", "mode": 0o640, "uid": 105, "gid": 105}
        self.host = self.healthy_host(files=files)
        self.assertEqual(self.run_main(), 4)
        self.assertIn("UNKNOWN datadir_entry_count_exceeded", self.output())

    def test_datadir_file_size_bound_yields_unknown(self):
        saved = rd.MAX_FILE_BYTES
        rd.MAX_FILE_BYTES = 2
        self.addCleanup(setattr, rd, "MAX_FILE_BYTES", saved)
        self.host = self.healthy_host()
        self.assertEqual(self.run_main(), 4)
        self.assertIn("UNKNOWN datadir_exceeds_bounds", self.output())

    # -- AppSec round-2 findings (F2 aggregate/mutation, R3 cycle) ----------

    def test_datadir_file_changed_during_read_yields_unknown(self):
        # A file that mutates between the walk's stat and the completed read
        # makes the fingerprint unreliable: fail closed as UNKNOWN.
        self.host = self.healthy_host(mutate_on_read={rd.DATADIRS[0] + "/ib_logfile0"})
        self.assertEqual(self.run_main(), 4)
        self.assertIn("UNKNOWN datadir_changed_during_read:ib_logfile0", self.output())

    def test_datadir_read_check_charges_actual_bytes_and_detects_mutation(self):
        before = fake_stat("file", 0o640, 105, 105, size=4, mtime_ns=1000)
        same = fake_stat("file", 0o640, 105, 105, size=4, mtime_ns=1000)
        grown = fake_stat("file", 0o640, 105, 105, size=9, mtime_ns=1001)
        rewritten_ctime = fake_stat("file", 0o640, 105, 105, size=4, mtime_ns=1000, ctime_ns=1001)
        replaced_ino = fake_stat("file", 0o640, 105, 105, size=4, mtime_ns=1000, ino=2)
        self.assertEqual(rd.datadir_read_check("f", before, same, same, 4, 0, 100), 4)
        with self.assertRaisesRegex(rd.HostError, "datadir_changed_during_read:f:fd$"):
            rd.datadir_read_check("f", before, grown, same, 9, 0, 100)
        with self.assertRaisesRegex(rd.HostError, "datadir_changed_during_read:f:fd$"):
            rd.datadir_read_check("f", before, rewritten_ctime, same, 4, 0, 100)
        with self.assertRaisesRegex(rd.HostError, "datadir_changed_during_read:f:path$"):
            rd.datadir_read_check("f", before, same, replaced_ino, 4, 0, 100)
        with self.assertRaisesRegex(rd.HostError, "datadir_exceeds_bounds"):
            rd.datadir_read_check("f", before, same, same, 4, 99, 100)
        with self.assertRaisesRegex(rd.HostError, "datadir_exceeds_bounds"):
            rd.datadir_read_check("f", before, same, same, 5, 99, 100)

    def test_datadir_same_size_replacement_with_restored_mtime_yields_unknown(self):
        # AppSec R3 finding 2: a same-size inode replacement with restored
        # mtime must not yield a stale-fingerprint PASS.
        self.host = self.healthy_host(replace_on_read={rd.DATADIRS[0] + "/ib_logfile0"})
        self.assertEqual(self.run_main(), 4)
        self.assertIn("UNKNOWN datadir_changed_during_read:ib_logfile0:path", self.output())

    def test_datadir_inplace_rewrite_with_restored_mtime_yields_unknown(self):
        # AppSec R3 finding 2: an in-place same-size rewrite with restored
        # mtime is caught through the descriptor's ctime (never restorable).
        self.host = self.healthy_host(rewrite_on_read={rd.DATADIRS[0] + "/ib_logfile0"})
        self.assertEqual(self.run_main(), 4)
        self.assertIn("UNKNOWN datadir_changed_during_read:ib_logfile0:fd", self.output())

    # -- AppSec round-4 findings (R4-F1 enumeration, R4-F2 logs) ------------

    def test_datadir_entry_added_during_listing_yields_unknown(self):
        # An entry added after a directory's names were collected must not be
        # silently omitted from the fingerprint: the directory's change
        # metadata is re-verified after the whole walk.
        self.host = self.healthy_host(grow_dir_after_listing={rd.DATADIRS[0]})
        self.assertEqual(self.run_main(), 4)
        self.assertIn("UNKNOWN datadir_changed_during_read:mysql-wpcaregrid-production",
                      self.output())

    def test_datadir_child_directory_replaced_during_walk_yields_unknown(self):
        self.host = self.healthy_host(replace_dir_after_listing={rd.DATADIRS[0]})
        self.assertEqual(self.run_main(), 4)
        self.assertIn("UNKNOWN datadir_changed_during_read:mysql-wpcaregrid-production",
                      self.output())

    def test_log_replaced_during_read_yields_unknown(self):
        # AppSec R4-F2: validation and hashing bind to the same descriptor;
        # a concurrent replacement of the log file fails closed.
        self.host = self.healthy_host(replace_on_read={rd.LOGS[0]})
        self.assertEqual(self.run_main(), 4)
        self.assertIn("UNKNOWN mysql_log_changed_during_read:" + posixpath.basename(rd.LOGS[0]),
                      self.output())

    def test_log_rewritten_during_read_yields_unknown(self):
        self.host = self.healthy_host(rewrite_on_read={rd.LOGS[0]})
        self.assertEqual(self.run_main(), 4)
        self.assertIn("UNKNOWN mysql_log_changed_during_read:" + posixpath.basename(rd.LOGS[0]),
                      self.output())

    def test_unit_line_values_are_sanitized(self):
        # Untrusted systemctl output never reaches the transcript verbatim.
        commands = dict(self.host.commands)
        commands[(rd.UNITS[0], "SubState")] = "dead\x1b[EVIL"
        self.host = self.healthy_host(commands=commands)
        self.assertEqual(self.run_main(), 0)
        self.assertIn("SubState=dead?[EVIL", self.output())
        self.assertNotIn("dead\x1b[EVIL", self.output())

    # -- AppSec round-5 findings (R5-F1 nested windows, R5-F3 emission) -----

    def _healthy_with_nested(self):
        files, dirs = dict(self.host.files), dict(self.host.dirs)
        nested = rd.DATADIRS[0] + "/nested"
        dirs[nested] = {"mode": 0o750, "uid": 105, "gid": 105}
        files[nested + "/ibdata1"] = {"content": b"nested", "mode": 0o640, "uid": 105, "gid": 105}
        return self.healthy_host(files=files, dirs=dirs)

    def test_datadir_nested_entry_added_during_listing_yields_unknown(self):
        # R5-F1: a mutation between the parent's stat of a child directory
        # and that child's own level capture is caught through the
        # parent-recorded baseline.
        self.host = self._healthy_with_nested()
        self.host.grow_dir_after_listing = {rd.DATADIRS[0] + "/nested"}
        self.assertEqual(self.run_main(), 4)
        self.assertIn("UNKNOWN datadir_changed_during_read:nested", self.output())

    def test_datadir_nested_directory_replaced_during_listing_yields_unknown(self):
        self.host = self._healthy_with_nested()
        self.host.replace_dir_after_listing = {rd.DATADIRS[0] + "/nested"}
        self.assertEqual(self.run_main(), 4)
        self.assertIn("UNKNOWN datadir_changed_during_read:nested", self.output())

    def test_log_mutated_during_read_yields_unknown(self):
        self.host = self.healthy_host(mutate_on_read={rd.LOGS[0]})
        self.assertEqual(self.run_main(), 4)
        self.assertIn("UNKNOWN mysql_log_changed_during_read:" + posixpath.basename(rd.LOGS[0]),
                      self.output())

    def test_accepted_package_path_is_sanitized_in_output(self):
        # R5-F3: a valid caller-supplied package path must never inject
        # newline/control characters into the CHECK result lines.
        files, dirs = self.valid_package(name="/root/wpcg-go54a-x\nEVIL")
        self.host = self.healthy_host(files=files, dirs=dirs)
        self.assertEqual(self.run_main(["--package", "/root/wpcg-go54a-x\nEVIL"]), 0)
        self.assertIn("package_verified:/root/wpcg-go54a-x?EVIL", self.output())
        self.assertNotIn("/root/wpcg-go54a-x\nEVIL", self.output())

    # -- AppSec round-6 findings (R6-F1 stale evidence, R6-F2 UNKNOWN) ------

    def test_state_revalidation_passes_in_healthy_run(self):
        self.assertEqual(self.run_main(), 0)
        self.assertIn("CHECK state_revalidation PASS critical_state_stable_during_run",
                      self.output())

    def test_concurrent_upgrade_during_run_yields_stale_evidence_unknown(self):
        # R6-F1: a GO-5.4a transaction completing after the initial baseline
        # read must invalidate the whole evidence set as stale.
        files = dict(self.host.files)
        files[rd.MANIFEST] = {"content": old_bytes(rd.MANIFEST),
                               "content2": new_bytes(rd.MANIFEST),
                               "mode": 0o644, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.host.swap_after_reads = {rd.MANIFEST: 1}
        self.assertEqual(self.run_main(), 4)
        self.assertIn("CHECK state_revalidation UNKNOWN evidence_stale:installed_baseline",
                      self.output())
        self.assertEqual(self.last_line(), "G1_GO54A_READINESS=UNKNOWN")

    def test_datadir_swap_before_open_yields_unknown(self):
        # R6-F2: a file replaced between the walk stat and the descriptor
        # open classifies as UNKNOWN (uniform mutation classification).
        self.host = self.healthy_host()
        self.host.swap_before_open = {rd.DATADIRS[0] + "/ib_logfile0"}
        self.assertEqual(self.run_main(), 4)
        self.assertIn("UNKNOWN datadir_changed_during_read:ib_logfile0", self.output())

    # -- AppSec round-7 findings (R7-F1 semantics, R7-F2 production logic) --

    def test_datadir_open_identity_check_is_the_production_function(self):
        # R7-F2: direct coverage of the shared production check.
        st = fake_stat("file", 0o640, 105, 105, size=4, ino=7)
        same = fake_stat("file", 0o640, 105, 105, size=4, ino=7)
        replaced = fake_stat("file", 0o640, 105, 105, size=4, ino=8)
        rd.datadir_open_identity_check("f", st, same)  # no raise
        with self.assertRaisesRegex(rd.HostError, "datadir_changed_during_read"):
            rd.datadir_open_identity_check("f", st, replaced)

    def test_lock_acquired_mid_run_yields_stale_evidence_unknown(self):
        # R7-F1: a transaction acquiring the lock while readiness still
        # collects evidence is caught by the closing re-observation.
        files = dict(self.host.files)
        files[rd.MANIFEST] = {"content": old_bytes(rd.MANIFEST),
                               "content2": new_bytes(rd.MANIFEST),
                               "mode": 0o644, "uid": 0, "gid": 0}
        self.host = self.healthy_host(files=files)
        self.host.swap_after_reads = {rd.MANIFEST: 1}
        self.host.reveal_held_lock_on_swap = True
        self.assertEqual(self.run_main(), 4)
        self.assertIn("CHECK state_revalidation UNKNOWN evidence_stale", self.output())

    def test_readiness_verdict_line_documents_advisory_semantics(self):
        # R7-F1: the tool's own docstring must state the honest guarantee.
        self.assertEqual(self.run_main(), 0)
        doc = (Path(rd.__file__)).read_text(encoding="utf-8")
        self.assertIn("ADVISORY", doc)
        self.assertIn("NON-ATOMIC", doc)


if __name__ == "__main__":
    unittest.main(verbosity=2)
