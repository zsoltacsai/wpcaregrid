#!/usr/bin/env python3
"""Regression checks for immutable production confinement 1.0.16 symlink probes."""
import hashlib
import importlib.util
import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "deploy/php-fpm-confinement/1.0.16"
PREVIOUS = ROOT / "deploy/php-fpm-confinement/1.0.15"
RUNTIME_BASE = ROOT / "deploy/php-fpm-confinement/1.0.12"
EXPECTED_SHA256SUMS = "5a141648ee771e6ceaa4ff296720857bb52ecd9f1c995d6e58b7e6016a50e622"
EXPECTED_BINDING = "41d10ad9d8d0c3d03e852bed449b3783aef387a5e55be1e9b9077cd37632633d"
EXPECTED_PACKAGE_TREE = "442fa9ab4e82652a70f997238ad5d98e11ade5b9"
ESCAPE_PATH = "/var/www/wpcaregrid-production/releases/wpcg-fixture/public/wpcg-probe-escape"
ESCAPE_TARGET = "/etc/wpcaregrid-deploy/wpcg-fixture-canary"


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


class ProbeEofCleanup(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.probe = load("production_confinement_1015_probe", PKG / "acceptance/probe.py")

    def run_calibration(self, failing_call=None):
        probe = self.probe
        real_fork = os.fork
        real_waitpid = os.waitpid
        children = []
        waited = []
        calls = []

        def tracked_fork():
            child = real_fork()
            if child > 0:
                children.append(child)
            return child

        def tracked_waitpid(pid, options):
            result = real_waitpid(pid, options)
            waited.append(result)
            return result

        def fake_timed(results, ident, kind, target, call, *, pid=None, extra=None):
            calls.append(ident)
            if failing_call == ident:
                raise RuntimeError("simulated probe failure")
            results.append({"id": ident, "kind": kind, "target": target,
                            "pid": pid or os.getpid(), "errno": 0})

        with patch.object(probe, "timed", fake_timed), \
             patch.object(probe.os, "fork", tracked_fork), \
             patch.object(probe.os, "waitpid", tracked_waitpid):
            if failing_call is None:
                results = []
                probe.descendant_calibration(results)
            else:
                results = []
                with self.assertRaisesRegex(RuntimeError, "simulated probe failure"):
                    probe.descendant_calibration(results)

        self.assertEqual(len(children), 1)
        self.assertEqual(len(waited), 1)
        pid, status = waited[0]
        self.assertEqual(pid, children[0])
        self.assertTrue(os.WIFEXITED(status))
        self.assertEqual(os.WEXITSTATUS(status), 0)
        return calls, results, children[0]

    def test_sibling_canary_is_not_allowed_and_listener_pins_parent_directory(self):
        from types import SimpleNamespace
        import stat
        listener = load("production_confinement_1015_sibling_listener", PKG / "acceptance/sibling-listener.py")
        matrix = json.loads((PKG / "acceptance/matrix.json").read_text(encoding="utf-8"))
        expected = "/run/mysqld/wpcaregrid-acceptance/canary.sock"
        self.assertEqual(matrix["sibling_canary"]["path"], expected)
        build_profile = (PKG / "apparmor/wpcaregrid-php-cli-production-build").read_text(encoding="utf-8")
        deploy_profile = (PKG / "apparmor/wpcaregrid-php-cli-production-deploy").read_text(encoding="utf-8")
        self.assertNotIn("network (create, connect, shutdown, send, receive, getattr, getopt, setopt) unix stream,", build_profile)
        self.assertIn("/run/mysqld/wpcaregrid-production.sock rw,", deploy_profile)
        self.assertNotIn(expected, build_profile)
        self.assertNotIn(expected, deploy_profile)
        build_socket_paths = [line.strip() for line in build_profile.splitlines()
                              if line.strip().startswith("/run/mysqld") and not line.strip().startswith("audit deny")]
        deploy_socket_paths = [line.strip() for line in deploy_profile.splitlines()
                               if line.strip().startswith("/run/mysqld") and not line.strip().startswith("audit deny")]
        self.assertEqual(build_socket_paths, [])
        self.assertEqual(deploy_socket_paths, ["/run/mysqld/wpcaregrid-production.sock rw,"])
        for profile_name in ("wpcaregrid-php-cli-production-build", "wpcaregrid-php-cli-production-deploy"):
            sockets = [s for s in matrix["profiles"][profile_name]["sockets"] if s["id"] == "mysql-sibling-canary"]
            self.assertEqual(len(sockets), 1)
            self.assertEqual(sockets[0]["path"], expected)
        import tempfile
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            run_dir = base / "run"
            mysqld_dir = run_dir / "mysqld"
            parent = mysqld_dir / "wpcaregrid-acceptance"
            run_dir.mkdir(mode=0o755)
            mysqld_dir.mkdir(mode=0o755)
            parent.mkdir(mode=0o755)
            outside = base / "outside"
            outside.mkdir(mode=0o755)
            moved = base / "moved"
            expected_parent = str(parent)
            expected_path = expected_parent + "/canary.sock"
            matrix["sibling_canary"]["parent"] = expected_parent
            matrix["sibling_canary"]["path"] = expected_path
            root_info = SimpleNamespace(st_mode=stat.S_IFDIR | 0o755, st_uid=0, st_gid=0)
            mysql_info = SimpleNamespace(st_mode=stat.S_IFDIR | 0o755, st_uid=105, st_gid=105)
            sequence = [root_info, mysql_info, root_info]
            with patch.object(listener, "RUN_DIR", str(run_dir)), patch.object(listener, "MYSQLD_DIR", str(mysqld_dir)), \
                 patch.object(listener, "EXPECTED_PARENT", expected_parent), patch.object(listener, "EXPECTED_PATH", expected_path), \
                 patch.object(listener.os, "fstat", side_effect=sequence):
                parent_fd = listener.prepare(matrix)
                parent.rename(moved)
                parent.symlink_to(outside, target_is_directory=True)
                # The server binds and cleans up through the pinned fd; replacement of
                # the checked path cannot redirect root filesystem operations to a symlink.
                self.assertEqual(listener.serve(expected_path, 0, parent_fd), 0)
                self.assertEqual(list(outside.iterdir()), [])
                self.assertEqual(list(moved.iterdir()), [])
                self.assertTrue(parent.is_symlink())

            for bad in (
                {"uid": 33, "gid": 0, "mode": 0o755},
                {"uid": 0, "gid": 0, "mode": 0o700},
                {"uid": 0, "gid": 0, "mode": 0o775},
            ):
                bad_parent = mysqld_dir / "wpcaregrid-acceptance"
                bad_parent.unlink() if bad_parent.is_symlink() else None
                bad_parent.mkdir(exist_ok=True, mode=bad["mode"])
                fake = SimpleNamespace(st_mode=stat.S_IFDIR | bad["mode"], st_uid=bad["uid"], st_gid=bad["gid"])
                bad_matrix = json.loads((PKG / "acceptance/matrix.json").read_text(encoding="utf-8"))
                bad_matrix["sibling_canary"]["parent"] = str(bad_parent)
                bad_matrix["sibling_canary"]["path"] = str(bad_parent / "canary.sock")
                seq = [root_info, mysql_info, fake]
                with patch.object(listener, "RUN_DIR", str(run_dir)), patch.object(listener, "MYSQLD_DIR", str(mysqld_dir)), \
                     patch.object(listener, "EXPECTED_PARENT", str(bad_parent)), patch.object(listener, "EXPECTED_PATH", str(bad_parent / "canary.sock")), \
                     patch.object(listener.os, "fstat", side_effect=seq):
                    with self.assertRaises(listener.Refused):
                        listener.prepare(bad_matrix)

    def test_sibling_listener_serves_fixed_banner_and_cleans_its_socket(self):
        from types import SimpleNamespace
        import signal
        import socket
        import stat
        import tempfile
        import time
        listener = load("production_confinement_1015_live_sibling_listener", PKG / "acceptance/sibling-listener.py")
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            run_dir = base / "run"
            mysqld_dir = run_dir / "mysqld"
            parent = mysqld_dir / "wpcaregrid-acceptance"
            run_dir.mkdir(mode=0o755)
            mysqld_dir.mkdir(mode=0o755)
            parent.mkdir(mode=0o755)
            expected_parent = str(parent)
            expected_path = expected_parent + "/canary.sock"
            matrix = json.loads((PKG / "acceptance/matrix.json").read_text(encoding="utf-8"))
            matrix["sibling_canary"]["parent"] = expected_parent
            matrix["sibling_canary"]["path"] = expected_path
            root_info = SimpleNamespace(st_mode=stat.S_IFDIR | 0o755, st_uid=0, st_gid=0)
            mysql_info = SimpleNamespace(st_mode=stat.S_IFDIR | 0o755, st_uid=105, st_gid=105)
            with patch.object(listener, "RUN_DIR", str(run_dir)), patch.object(listener, "MYSQLD_DIR", str(mysqld_dir)), \
                 patch.object(listener, "EXPECTED_PARENT", expected_parent), patch.object(listener, "EXPECTED_PATH", expected_path), \
                 patch.object(listener.os, "fstat", side_effect=[root_info, mysql_info, root_info]):
                parent_fd = listener.prepare(matrix)
            pid = os.fork()
            if pid == 0:
                try:
                    listener.serve(expected_path, 10, parent_fd)
                    os._exit(0)
                except BaseException:
                    os._exit(1)
            os.close(parent_fd)
            child_reaped = False
            try:
                deadline = time.monotonic() + 3
                while not os.path.exists(expected_path) and time.monotonic() < deadline:
                    time.sleep(0.02)
                self.assertTrue(os.path.exists(expected_path), "listener did not publish the canary socket")
                client = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
                try:
                    client.settimeout(2)
                    client.connect(expected_path)
                    self.assertEqual(client.recv(64), b"WPCG-SIBLING-CANARY-v1\n")
                finally:
                    client.close()
                os.kill(pid, signal.SIGTERM)
                _waited, status = os.waitpid(pid, 0)
                child_reaped = True
                self.assertTrue(os.WIFEXITED(status))
                self.assertEqual(os.WEXITSTATUS(status), 0)
                self.assertFalse(os.path.exists(expected_path))
            finally:
                if not child_reaped:
                    try:
                        os.kill(pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
                    try:
                        os.waitpid(pid, os.WNOHANG)
                    except ChildProcessError:
                        pass

    def test_normal_path_runs_every_calibration_and_reaps_eof_child(self):
        calls, results, child = self.run_calibration()
        expected = [
            "control:self-maps",
            "calibration:child-signal",
            "calibration:child-maps",
            "calibration:child-environ",
            "calibration:child-process_vm_readv",
            "calibration:child-ptrace-seize",
        ]
        self.assertEqual(calls, expected)
        self.assertEqual([item["id"] for item in results], expected)
        self.assertTrue(all(item["child"] == child for item in results))

    def test_probe_exception_still_closes_pipe_and_reaps_child(self):
        calls, results, _child = self.run_calibration("calibration:child-maps")
        self.assertEqual(calls, [
            "control:self-maps", "calibration:child-signal", "calibration:child-maps"
        ])
        self.assertEqual(len(results), 2)

    def test_probe_uses_eof_cleanup_without_signalling_the_child(self):
        source = (PKG / "acceptance/probe.py").read_text(encoding="utf-8")
        self.assertIn("os.pipe2(os.O_CLOEXEC)", source)
        self.assertIn("_close_calibration_pipe_and_reap(write_end, child)", source)
        self.assertNotIn("signal.SIGKILL", source)

    def test_package_pins_and_runtime_files_match_immutable_predecessors(self):
        sums = (PKG / "SHA256SUMS").read_bytes()
        binding_bytes = (PKG / "binding.json").read_bytes()
        binding = json.loads(binding_bytes)
        matrix = json.loads((PKG / "acceptance/matrix.json").read_text(encoding="utf-8"))
        self.assertEqual(hashlib.sha256(sums).hexdigest(), EXPECTED_SHA256SUMS)
        self.assertEqual(hashlib.sha256(binding_bytes).hexdigest(), EXPECTED_BINDING)
        root_tree = subprocess.run(["git", "write-tree"], cwd=ROOT, text=True,
                                   capture_output=True, check=True).stdout.strip()
        entry = subprocess.run(["git", "ls-tree", root_tree, "deploy/php-fpm-confinement/1.0.16"],
                               cwd=ROOT, text=True, capture_output=True, check=True).stdout.strip()
        self.assertEqual(entry.split()[2], EXPECTED_PACKAGE_TREE)
        self.assertEqual(binding["package_version"], "1.0.16")
        self.assertEqual(matrix["version"], "1.0.16")
        self.assertEqual(binding["installed_files"], json.loads(
            (RUNTIME_BASE / "binding.json").read_text(encoding="utf-8"))["installed_files"])

        entries = {}
        for line in sums.decode("utf-8").splitlines():
            digest, name = line.split("  ", 1)
            entries[name] = digest
        actual = {p.relative_to(PKG).as_posix() for p in PKG.rglob("*")
                  if p.is_file() and p.name != "SHA256SUMS"}
        self.assertEqual(set(entries), actual)
        for name, digest in entries.items():
            self.assertEqual(hashlib.sha256((PKG / name).read_bytes()).hexdigest(), digest, name)
        for relative in binding["installed_files"].values():
            self.assertEqual((PKG / relative).read_bytes(), (RUNTIME_BASE / relative).read_bytes(), relative)

        previous = {p.relative_to(PREVIOUS).as_posix() for p in PREVIOUS.rglob("*") if p.is_file()}
        current = {p.relative_to(PKG).as_posix() for p in PKG.rglob("*") if p.is_file()}
        self.assertEqual(previous, current)
        for relative in previous - {"SHA256SUMS", "acceptance/matrix.json", "binding.json", "acceptance/probe.py"}:
            self.assertEqual((PKG / relative).read_bytes(), (PREVIOUS / relative).read_bytes(), relative)

    def test_probe_uses_exact_precreated_symlink_fixture_without_temp_writes(self):
        probe = load("production_confinement_1016_probe", PKG / "acceptance/probe.py")
        matrix = json.loads((PKG / "acceptance/matrix.json").read_text(encoding="utf-8"))
        declared = {(entry["path"], entry["target"]) for entry in matrix["fixtures"]["symlinks"]}
        self.assertIn((ESCAPE_PATH, ESCAPE_TARGET), declared)
        self.assertNotIn("import tempfile", (PKG / "acceptance/probe.py").read_text(encoding="utf-8"))
        source = (PKG / "acceptance/probe.py").read_text(encoding="utf-8")
        self.assertNotIn("tempfile.mkdtemp", source)
        self.assertNotIn("os.symlink(", source)
        for profile, spec in matrix["profiles"].items():
            if "live_symlink_escape_targets" not in spec:
                continue
            self.assertEqual(spec["live_symlink_escape_targets"], [ESCAPE_TARGET], profile)
            self.assertEqual(spec["live_symlink_escape_paths"], [ESCAPE_PATH], profile)

        with patch.object(probe.os, "lstat", return_value=type("LinkStat", (), {"st_mode": stat.S_IFLNK})()), \
             patch.object(probe.os, "readlink", return_value=ESCAPE_TARGET):
            self.assertEqual(probe.symlink_escape_fixtures(matrix, matrix["profiles"]["wpcaregrid-nginx"]),
                             [(ESCAPE_PATH, ESCAPE_TARGET)])

        invalid = json.loads(json.dumps(matrix))
        invalid["profiles"]["wpcaregrid-nginx"]["live_symlink_escape_paths"] = ["/tmp/not-a-fixture"]
        with self.assertRaisesRegex(ValueError, "not an exact declared fixture"):
            probe.symlink_escape_fixtures(invalid, invalid["profiles"]["wpcaregrid-nginx"])
        with patch.object(probe.os, "lstat", side_effect=FileNotFoundError), \
             patch.object(probe.os, "readlink", return_value=ESCAPE_TARGET):
            with self.assertRaisesRegex(ValueError, "missing or unreadable"):
                probe.symlink_escape_fixtures(matrix, matrix["profiles"]["wpcaregrid-nginx"])

    def test_declared_symlink_fixture_lifecycle_cleans_the_escape_link(self):
        fixtures = load("production_confinement_1016_fixtures", PKG / "acceptance/fixtures.py")
        matrix = json.loads((PKG / "acceptance/matrix.json").read_text(encoding="utf-8"))
        with tempfile.TemporaryDirectory(prefix="wpcg-1016-fixtures-") as temporary:
            root = Path(temporary) / "root"
            root.mkdir()
            for parent in ("var/www", "var/lib", "var/log", "etc", "home", "run"):
                (root / parent).mkdir(parents=True, exist_ok=True)
            receipt = Path(temporary) / "receipt.json"
            fixtures.create(matrix["fixtures"], str(root), str(receipt), chown=False)
            link = root / ESCAPE_PATH.lstrip("/")
            self.assertTrue(link.is_symlink())
            self.assertEqual(os.readlink(link), str(root / ESCAPE_TARGET.lstrip("/")))
            self.assertIn(ESCAPE_PATH, fixtures.status(matrix["fixtures"], str(root)))
            result = fixtures.remove(matrix["fixtures"], str(root), str(receipt))
            self.assertEqual(result["left"], [])
            self.assertFalse(os.path.lexists(link))
            self.assertEqual(fixtures.status(matrix["fixtures"], str(root)), [])

    def test_verifier_contract_keeps_denial_bound_to_protected_target(self):
        verifier = load("production_confinement_1016_verifier", PKG / "acceptance/verify-acceptance.py")
        old_verifier = PREVIOUS / "acceptance/verify-acceptance.py"
        self.assertEqual((PKG / "acceptance/verify-acceptance.py").read_bytes(), old_verifier.read_bytes())
        matrix = json.loads((PKG / "acceptance/matrix.json").read_text(encoding="utf-8"))
        expected, _targets = verifier.expected_checks(matrix, "wpcaregrid-nginx", {}, "post")
        self.assertEqual(expected["symlink-escape:0"],
                         ("symlink", {"expect": "deny", "path": ESCAPE_TARGET, "access": "r"}))


if __name__ == "__main__":
    unittest.main(verbosity=2)
