"""Hermetic transaction and interrupted-journal tests for the GO-5.4a updater."""

import base64
import hashlib
import importlib.util
import json
import os
import stat
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest import mock

OPS = Path(__file__).parents[1] / "production-g1-ops"
SOURCE = OPS / "go54a-upgrade-apparmor.py"
SPEC = importlib.util.spec_from_file_location("go54a_transaction", SOURCE)
go = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(go)


@unittest.skipUnless(os.geteuid() == 0, "run with sudo: transaction fixtures require root-owned files")
class Go54aTransactionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="wpcg-go54a-test-")
        self.root = Path(self.temp.name)
        self.libexec = self.root / "libexec"
        self.apparmor = self.root / "apparmor"
        self.package = self.root / "package"
        for path in (self.libexec, self.apparmor, self.package):
            path.mkdir(mode=0o700)
        self.vendor = self.root / "vendor-profile"
        self.vendor.write_text("unchanged vendor policy\n")
        self.loaded = self.root / "loaded-profiles"
        self.loaded.write_text("wpcaregrid-mysql-production (enforce)\n"
                               "wpcaregrid-mysql-production-restore (enforce)\n"
                               "/usr/sbin/mysqld (enforce)\n")
        self.data = (self.root / "data-p", self.root / "data-r")
        self.logs = (self.root / "log-p", self.root / "log-r")
        for path in (*self.data, *self.logs):
            path.mkdir() if path in self.data else path.write_bytes(b"stable log\n")

        self.paths = {
            "helper": str(self.libexec / "wpcaregrid-g1-mysql.py"),
            "contract": str(self.libexec / "production_g1_mysql_contract.py"),
            "manifest": str(self.libexec / "production_g1_mysql_manifest.json"),
            "profile_p": str(self.apparmor / "wpcaregrid-mysql-production"),
            "profile_r": str(self.apparmor / "wpcaregrid-mysql-production-restore"),
        }
        self.globals = {name: getattr(go, name) for name in (
            "LIBEXEC", "APPARMOR", "HELPER", "CONTRACT", "MANIFEST", "PROFILE_P", "PROFILE_R",
            "VENDOR_PROFILE", "LOADED", "DATADIRS", "LOGS", "UNITS", "JOURNAL", "OLD", "NEW",
            "PACKAGE", "MODES", "ORDER", "PROFILE_NAMES",
        )}
        go.LIBEXEC, go.APPARMOR = str(self.libexec), str(self.apparmor)
        for name, key in (("HELPER", "helper"), ("CONTRACT", "contract"), ("MANIFEST", "manifest"),
                          ("PROFILE_P", "profile_p"), ("PROFILE_R", "profile_r")):
            setattr(go, name, self.paths[key])
        go.VENDOR_PROFILE, go.LOADED = str(self.vendor), str(self.loaded)
        go.DATADIRS, go.LOGS = tuple(map(str, self.data)), tuple(map(str, self.logs))
        go.JOURNAL = str(self.libexec / ".go54a-apparmor-upgrade.json")
        go.UNITS = ("mysql@wpcaregrid-production.service", "mysql@wpcaregrid-production-restore.service")
        go.PACKAGE = {go.HELPER: "production-g1-mysql.py", go.CONTRACT: "production_g1_mysql_contract.py",
                      go.MANIFEST: "production-g1-mysql-manifest.json"}
        go.ORDER = (go.CONTRACT, go.MANIFEST, go.HELPER, go.PROFILE_P, go.PROFILE_R)
        go.MODES = {go.HELPER: 0o755, go.CONTRACT: 0o644, go.MANIFEST: 0o644,
                    go.PROFILE_P: 0o644, go.PROFILE_R: 0o644}
        go.PROFILE_NAMES = {go.PROFILE_P: "wpcaregrid-mysql-production",
                            go.PROFILE_R: "wpcaregrid-mysql-production-restore"}

        repo_scripts = Path(__file__).parents[1]
        payload = {
            go.HELPER: (repo_scripts / "production-g1-mysql.py").read_bytes(),
            go.CONTRACT: (repo_scripts / "production_g1_mysql_contract.py").read_bytes(),
            go.MANIFEST: (repo_scripts / "production-g1-mysql-manifest.json").read_bytes(),
        }
        contract_spec = importlib.util.spec_from_file_location("go54a_test_contract", repo_scripts / "production_g1_mysql_contract.py")
        contract_module = importlib.util.module_from_spec(contract_spec)
        sys.modules[contract_spec.name] = contract_module
        contract_spec.loader.exec_module(contract_module)
        generated = contract_module.artifact_files()
        profiles = {go.PROFILE_P: generated[self.globals["PROFILE_P"]],
                    go.PROFILE_R: generated[self.globals["PROFILE_R"]]}
        self.new_bytes = {**payload, **profiles}
        go.NEW = {path: go.sha(data) for path, data in self.new_bytes.items()}
        go.OLD = {path: go.sha(("old:" + Path(path).name).encode()) for path in go.ORDER}
        for path in go.ORDER:
            target = Path(path)
            target.write_bytes(("old:" + target.name).encode())
            target.chmod(go.MODES[path])
        for path, data in payload.items():
            package_path = self.package / go.PACKAGE[path]
            package_path.write_bytes(data)
            package_path.chmod(0o600)
        sums = "".join(f"{go.sha(payload[path])}  {name}\n" for path, name in go.PACKAGE.items())
        sums_path = self.package / "SHA256SUMS"
        sums_path.write_text(sums)
        sums_path.chmod(0o600)

        self.calls = []
        self.patches = [
            mock.patch.object(go, "compile_profiles"),
            mock.patch.object(go, "generated_profiles", side_effect=lambda _:
                              {go.PROFILE_P: self.new_bytes[go.PROFILE_P], go.PROFILE_R: self.new_bytes[go.PROFILE_R]}),
            mock.patch.object(go, "enforce_state"),
            mock.patch.object(go, "vendor_policy_state", return_value=(0o644, 0, 0, "vendor-sha", "vendor (enforce)")),
            mock.patch.object(go, "fingerprint_tree", side_effect=lambda path: "tree:" + path),
            mock.patch.object(go, "fingerprint_logs", return_value=["log-p", "log-r"]),
        ]
        for patcher in self.patches:
            patcher.start()
        self.addCleanup(self.restore_globals)

    def tearDown(self):
        self.temp.cleanup()

    def restore_globals(self):
        for patcher in self.patches:
            patcher.stop()
        for name, value in self.globals.items():
            setattr(go, name, value)

    def runfn(self, args):
        self.calls.append(args)
        if args[:2] == ["/usr/bin/systemctl", "show"]:
            output = "inactive\n" if "ActiveState" in args[-2] else "1131\n"
            return types.SimpleNamespace(returncode=0, stdout=output.encode())
        if args[0] == "/usr/sbin/apparmor_parser":
            return types.SimpleNamespace(returncode=0, stdout=b"")
        if args[0] == "/usr/bin/python3":
            return types.SimpleNamespace(returncode=0, stdout=b"STATIC_VERIFY=PASS\n")
        return types.SimpleNamespace(returncode=0, stdout=b"")

    def old_state(self):
        return {path: go.sha(go.read_root_file(path, go.MODES[path])) for path in go.ORDER}

    def test_success_replaces_only_the_pinned_five_files_and_leaves_no_journal(self):
        self.assertEqual(go._transaction(str(self.package), self.runfn, euid=0), "G1_GO54A_APPARMOR=PASS")
        self.assertEqual(self.old_state(), go.NEW)
        self.assertFalse(os.path.lexists(go.JOURNAL))
        flat = " ".join(" ".join(call) for call in self.calls)
        self.assertNotIn("systemctl start", flat)
        self.assertNotIn("--initialize", flat)

    def test_already_upgraded_state_is_idempotent(self):
        self.assertEqual(go._transaction(str(self.package), self.runfn, euid=0), "G1_GO54A_APPARMOR=PASS")
        self.assertEqual(go._transaction(str(self.package), self.runfn, euid=0),
                         "G1_GO54A_APPARMOR=PASS;NOTE=already_upgraded")

    def test_failed_post_write_check_rolls_back_all_files_and_reports_verified_rollback(self):
        original = go.replace
        failed = False

        def fail_after_first_replace(path, data, mode):
            nonlocal failed
            original(path, data, mode)
            if not failed:
                failed = True
                raise OSError("injected after first atomic replacement")

        with mock.patch.object(go, "replace", side_effect=fail_after_first_replace):
            with self.assertRaisesRegex(go.Stop, r"ROLLED_BACK=yes"):
                go._transaction(str(self.package), self.runfn, euid=0)
        self.assertEqual(self.old_state(), go.OLD)
        self.assertFalse(os.path.lexists(go.JOURNAL))

    def test_failed_rollback_is_reported_and_journal_is_preserved(self):
        original = go.replace
        failed_new = False

        # Exercise an injected runtime failure, then make rollback of the first
        # replaced target fail so that the updater must retain its journal.
        first = go.ORDER[0]
        target_new = go.NEW[first]
        def injected(path, data, mode):
            nonlocal failed_new
            if path == first and go.sha(data) == target_new and not failed_new:
                original(path, data, mode)
                failed_new = True
                raise OSError("injected transaction failure")
            if path == first and go.sha(data) == go.OLD[first]:
                raise OSError("injected rollback failure")
            return original(path, data, mode)

        with mock.patch.object(go, "replace", side_effect=injected):
            with self.assertRaisesRegex(go.Stop, r"ROLLED_BACK=NO"):
                go._transaction(str(self.package), self.runfn, euid=0)
        self.assertTrue(os.path.lexists(go.JOURNAL))

    def write_journal(self, phase, current_paths=()):
        backup = {path: base64.b64encode(("old:" + Path(path).name).encode()).decode() for path in go.ORDER}
        if phase == "committed":
            for path in go.ORDER:
                go.replace(path, self.payload_for(path), go.MODES[path])
        else:
            for path in current_paths:
                go.replace(path, self.payload_for(path), go.MODES[path])
        go.journal_write({"schema": 1, "phase": phase, "old": go.OLD, "new": go.NEW, "backup": backup})

    def payload_for(self, path):
        return self.new_bytes[path]

    def test_interrupted_prepared_or_replacing_journal_recovers_then_stops(self):
        for phase, current in (("prepared", ()), ("replacing", (go.CONTRACT,))):
            with self.subTest(phase=phase):
                # Reinitialize the on-disk transition state for each subcase.
                for path in go.ORDER:
                    old = ("old:" + Path(path).name).encode()
                    if go.sha(go.read_root_file(path, go.MODES[path])) != go.OLD[path]:
                        go.replace(path, old, go.MODES[path])
                self.write_journal(phase, current)
                with self.assertRaisesRegex(go.Stop, "interrupted_transaction_recovered_manual_review_required"):
                    go._transaction(str(self.package), self.runfn, euid=0)
                self.assertEqual(self.old_state(), go.OLD)
                self.assertFalse(os.path.lexists(go.JOURNAL))

    def test_committed_journal_is_cleaned_and_stops_for_manual_review(self):
        self.write_journal("committed")
        with self.assertRaisesRegex(go.Stop, "interrupted_transaction_recovered_manual_review_required"):
            go._transaction(str(self.package), self.runfn, euid=0)
        self.assertEqual(self.old_state(), go.NEW)
        self.assertFalse(os.path.lexists(go.JOURNAL))


if __name__ == "__main__":
    unittest.main(verbosity=2)
