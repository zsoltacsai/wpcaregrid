#!/usr/bin/env python3
"""Tests for scripts/staging-next-helper-1-4-12-stage-sources.sh (Owner GO #1: stage the pinned 1.4.12 files on the host).

The host-side python program is executed in-process against a temporary home; the whole driver is also run
end to end against a fixture git repository with a fake `ssh`. Nothing touches a real host.
"""

from __future__ import annotations

import contextlib
import hashlib
import io
import os
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
DRIVER = (ROOT / "scripts" / "staging-next-helper-1-4-12-stage-sources.sh").read_text(encoding="utf-8")
USERNAME = pwd.getpwuid(os.getuid()).pw_name
IS_ROOT = os.geteuid() == 0

START = DRIVER.index("<<'WPCG_STAGE_PY' || true\n") + len("<<'WPCG_STAGE_PY' || true\n")
END = DRIVER.index("\nWPCG_STAGE_PY\n")
TEMPLATE = DRIVER[START:END]

NAMES = ["wpcaregrid-staging-next-deploy-priv-1_4_12.py", "staging_next_deploy_backend_1_4_12.py",
         "staging_next_db_transaction_1_4_12.py", "deployment_target_binding_1_4_12.py",
         "deployment_environment_contract_1_4_12.py", "install_staging_next_helper_1_4_12.py"]
CONTENT = {name: (f"# fixture {name}\n" + "x = 1\n" * 50).encode() for name in NAMES}
PINS = {name: hashlib.sha256(data).hexdigest() for name, data in CONTENT.items()}


def pin_lines(pins=PINS):
    return "\n".join(f'    "{name}": "{digest}",' for name, digest in pins.items())


def frame(files=None, *, end=True, extra=b""):
    files = CONTENT if files is None else files
    out = b""
    for name, data in files.items():
        out += f"FILE {name} {len(data)}\n".encode() + data
    return out + (b"END\n" if end else b"") + extra


class Remote(unittest.TestCase):
    def setUp(self):
        self.base = Path(os.path.realpath(tempfile.mkdtemp(prefix="stage1410-")))
        self.addCleanup(shutil.rmtree, self.base, ignore_errors=True)
        self.home = self.base / "home" / "deploy"
        self.home.mkdir(parents=True)
        self.app_dir = self.base / "staging-app"
        self.profile_dir = self.base / "profiles"
        for directory in (self.app_dir, self.profile_dir):
            directory.mkdir(parents=True, exist_ok=True)
        self.home.chmod(0o755)
        (self.base / "home").chmod(0o755)

    def code(self, mode="stage", pins=None):
        text = TEMPLATE.replace("@@MODE@@", mode).replace("@@PINS@@", pin_lines(PINS if pins is None else pins))
        text = text.replace('USER = "deploy"', f'USER = "{USERNAME}"').replace('HOME = "/home/deploy"', f'HOME = "{self.home}"')
        text = text.replace('APP_DIR = "/var/www/wpcaregrid-staging-new"', f'APP_DIR = "{self.app_dir}"')
        text = text.replace('PROFILE_DIR = "/etc/wpcaregrid-deploy/profiles"', f'PROFILE_DIR = "{self.profile_dir}"')
        text = text.replace('ROOT_UID = 0', f'ROOT_UID = {os.getuid()}')
        return text

    def run_remote(self, payload, mode="stage", pins=None, euid=None, patches=()):
        out = io.StringIO()
        stdin = types.SimpleNamespace(buffer=io.BytesIO(payload))
        code = compile(self.code(mode, pins), "<remote>", "exec")
        status = 0
        with contextlib.ExitStack() as stack:
            stack.enter_context(contextlib.redirect_stdout(out))
            stack.enter_context(mock.patch.object(sys, "stdin", stdin))
            if euid is not None:
                stack.enter_context(mock.patch.object(os, "geteuid", return_value=euid))
            for patcher in patches:
                stack.enter_context(patcher)
            # `/home` is checked as a real path; the fixture home only replaces /home/deploy
            namespace = {"__name__": "remote"}
            try:
                exec(code, namespace)
            except SystemExit as error:
                status = error.code or 0
        lines = out.getvalue().splitlines()
        return lines, status

    def snapshot(self):
        state = {}
        for dirpath, dirnames, filenames in os.walk(self.base):
            for name in dirnames + filenames:
                full = os.path.join(dirpath, name)
                info = os.lstat(full)
                digest = hashlib.sha256(Path(full).read_bytes()).hexdigest() if stat.S_ISREG(info.st_mode) else ""
                state[full] = (info.st_mode, info.st_uid, info.st_size, digest)
        return state

    def final(self, lines):
        finals = [l for l in lines if l.startswith("STAGING_1412_STAGE=")]
        self.assertEqual(len(finals), 1, lines)
        self.assertEqual(lines[-1], finals[0])
        return finals[0]

    def assert_stop(self, payload, code, **kwargs):
        before = self.snapshot()
        lines, status = self.run_remote(payload, **kwargs)
        self.assertEqual(self.final(lines), f"STAGING_1412_STAGE=STOP;CODE={code}" if not code.endswith("*") else lines[-1])
        if code.endswith("*"):
            self.assertTrue(lines[-1].startswith(f"STAGING_1412_STAGE=STOP;CODE={code[:-1]}"), lines[-1])
        self.assertEqual(status, 2)
        self.assertEqual(self.snapshot(), before, "a STOP must leave the host tree untouched")


@unittest.skipIf(IS_ROOT, "the host program refuses root by design")
class StageTests(Remote):
    PRIV = property(lambda self: self.home / ".private")
    SRC = property(lambda self: self.home / ".private" / "wpcg-staging-next-helper-1.4.12")
    INST = property(lambda self: self.home / ".private" / "wpcaregrid-staging-next-helper-installer-1.4.12.py")

    def test_stage_creates_exactly_the_pinned_layout_with_private_modes(self):
        lines, status = self.run_remote(frame())
        self.assertEqual(self.final(lines), "STAGING_1412_STAGE=PASS")
        self.assertEqual(status, 0)
        self.assertEqual(stat.S_IMODE(os.lstat(self.PRIV).st_mode), 0o700)
        self.assertEqual(stat.S_IMODE(os.lstat(self.SRC).st_mode), 0o700)
        self.assertEqual(sorted(os.listdir(self.SRC)), sorted(n for n in NAMES if n.startswith(("wpcaregrid", "staging", "deployment"))))
        for name in NAMES:
            path = self.INST if name.startswith("install_") else self.SRC / name
            info = os.lstat(path)
            self.assertTrue(stat.S_ISREG(info.st_mode))
            self.assertEqual((stat.S_IMODE(info.st_mode), info.st_uid, info.st_nlink), (0o600, os.getuid(), 1))
            self.assertEqual(hashlib.sha256(Path(path).read_bytes()).hexdigest(), PINS[name])
        self.assertEqual(sorted(os.listdir(self.PRIV)), sorted([self.SRC.name, self.INST.name]))

    def test_check_mode_verifies_everything_and_writes_nothing(self):
        before = self.snapshot()
        lines, status = self.run_remote(frame(), mode="check")
        self.assertEqual(self.final(lines), "STAGING_1412_STAGE=CHECK_PASS")
        self.assertEqual((status, self.snapshot()), (0, before))
        self.assertFalse(self.PRIV.exists())

    def test_a_second_run_never_overwrites(self):
        self.run_remote(frame())
        before = self.snapshot()
        lines, status = self.run_remote(frame())
        self.assertEqual(self.final(lines), "STAGING_1412_STAGE=STOP;CODE=destination_exists")
        self.assertEqual(self.snapshot(), before)

    def test_each_existing_destination_kind_is_a_stop_and_stays_untouched(self):
        self.PRIV.mkdir(mode=0o700)
        for create in (lambda: self.SRC.mkdir(mode=0o700),
                       lambda: self.INST.write_text("keep"),
                       lambda: os.symlink("/nonexistent", self.INST),
                       lambda: os.symlink(str(self.base), self.SRC)):
            create()
            self.assert_stop(frame(), "destination_exists")
            if self.INST.is_symlink() or self.INST.exists():
                self.INST.unlink()
            if self.SRC.is_symlink():
                self.SRC.unlink()
            elif self.SRC.exists():
                self.SRC.rmdir()

    def test_payload_defects_are_stops_and_leave_no_trace(self):
        data = dict(CONTENT)
        name = NAMES[0]
        bad_hash = dict(data, **{name: b"tampered"})
        self.assert_stop(frame(bad_hash), "payload_hash_mismatch:" + name)
        missing = {k: v for k, v in data.items() if k != NAMES[1]}
        self.assert_stop(frame(missing), "payload_incomplete")
        self.assert_stop(frame(data, end=False), "payload_header_invalid")
        self.assert_stop(frame(data, extra=b"x"), "payload_trailing_data")
        self.assert_stop(frame(dict(data, **{"extra.py": b"x"})), "payload_name_invalid")
        self.assert_stop(frame(dict(data, **{"../evil": b"x"})), "payload_name_invalid")
        self.assert_stop(frame(dict(data, **{"sub/dir.py": b"x"})), "payload_name_invalid")
        self.assert_stop(frame(data)[:-300], "payload_*")
        duplicate = frame(data).replace(b"END\n", f"FILE {name} {len(data[name])}\n".encode() + data[name] + b"END\n")
        self.assert_stop(duplicate, "payload_name_invalid")
        self.assert_stop(f"FILE {name} 99999999\n".encode(), "payload_size_invalid")
        self.assert_stop(f"FILE {name} 0\n".encode(), "payload_size_invalid")
        self.assert_stop(f"FILE {name} -5\n".encode(), "payload_size_invalid")
        self.assert_stop(b"GARBAGE\n", "payload_header_invalid")
        self.assert_stop(b"", "payload_header_invalid")
        self.assert_stop(f"FILE {name} 10\nshort".encode(), "payload_truncated")

    def test_wrong_pins_on_the_host_side_stop(self):
        self.assert_stop(frame(), "payload_hash_mismatch:" + NAMES[0], pins=dict(PINS, **{NAMES[0]: "0" * 64}))

    def test_identity_and_directory_preconditions(self):
        self.assert_stop(frame(), "must_not_run_as_root", euid=0)
        with mock.patch.object(Path, "exists", Path.exists):
            pass
        self.home.chmod(0o775)
        self.assert_stop(frame(), "directory_writable_by_group_or_other:" + str(self.home))
        self.home.chmod(0o755)
        self.PRIV.mkdir(mode=0o750)
        os.chmod(self.PRIV, 0o750)
        self.assert_stop(frame(), "directory_mode:" + str(self.PRIV))
        os.chmod(self.PRIV, 0o770)
        self.assert_stop(frame(), "directory_writable_by_group_or_other:" + str(self.PRIV))
        self.PRIV.rmdir()
        link_target = self.base / "elsewhere"
        link_target.mkdir(mode=0o700)
        os.symlink(link_target, self.PRIV)
        self.assert_stop(frame(), "directory_invalid:" + str(self.PRIV))
        os.unlink(self.PRIV)

    def test_wrong_user_name_stops(self):
        code = self.code().replace(f'USER = "{USERNAME}"', 'USER = "someone-else"')
        out = io.StringIO()
        with contextlib.redirect_stdout(out), mock.patch.object(sys, "stdin", types.SimpleNamespace(buffer=io.BytesIO(frame()))):
            with self.assertRaises(SystemExit) as raised:
                exec(compile(code, "<remote>", "exec"), {"__name__": "remote"})
        self.assertEqual(raised.exception.code, 2)
        self.assertEqual(out.getvalue().splitlines()[-1], "STAGING_1412_STAGE=STOP;CODE=wrong_user")

    def test_symlinked_home_stops(self):
        real = self.base / "real-home"
        real.mkdir(mode=0o755)
        shutil.rmtree(self.home)
        os.symlink(real, self.home)
        self.assert_stop(frame(), "directory_invalid:" + str(self.home))

    def test_failure_during_installer_publication_fails_closed_without_deleting(self):
        original_open = os.open

        def fail_installer(path, *args, **kwargs):
            if str(path).endswith("wpcaregrid-staging-next-helper-installer-1.4.12.py"):
                raise OSError("boom")
            return original_open(path, *args, **kwargs)

        with mock.patch.object(os, "open", side_effect=fail_installer):
            lines, status = self.run_remote(frame())
        self.assertEqual(lines[-1], "STAGING_1412_STAGE=STOP;CODE=stage_failed:OSError")
        self.assertEqual(status, 2)
        self.assertTrue(any(line.startswith("ROLLBACK=HOLD;NO_AUTOMATIC_DELETE;") for line in lines))
        self.assertTrue(self.SRC.is_dir())
        self.assertFalse(self.INST.exists())
        self.assertEqual(sorted(path.name for path in self.PRIV.iterdir()), [self.SRC.name])

    def test_staging_preserves_preexisting_private_directory_contents(self):
        self.PRIV.mkdir(mode=0o700)
        (self.PRIV / "keep.txt").write_text("keep")
        lines, status = self.run_remote(frame())
        self.assertEqual((self.final(lines), status), ("STAGING_1412_STAGE=PASS", 0))
        self.assertEqual(self.final(lines), "STAGING_1412_STAGE=PASS")
        self.assertEqual((self.PRIV / "keep.txt").read_text(), "keep")

    def test_failure_while_writing_reports_hold_and_keeps_partial_objects(self):
        original = os.fsync
        calls = {"n": 0}

        def failing(fd):
            calls["n"] += 1
            if calls["n"] == 3:
                raise OSError("disk")
            return original(fd)

        with mock.patch.object(os, "fsync", failing):
            lines, status = self.run_remote(frame())
        self.assertEqual(lines[-1], "STAGING_1412_STAGE=STOP;CODE=stage_failed:OSError")
        self.assertEqual(status, 2)
        self.assertTrue(any(line.startswith("ROLLBACK=HOLD;NO_AUTOMATIC_DELETE;") for line in lines))
        self.assertTrue(self.PRIV.exists())

    def test_output_never_contains_file_content(self):
        lines, _ = self.run_remote(frame())
        self.assertNotIn("fixture", "\n".join(lines))
        self.assertTrue(all(l.startswith(("PRECONDITIONS=", "FILE|", "STAGING_1412_STAGE=", "ROLLBACK=")) for l in lines), lines)

    def test_only_the_private_directory_is_ever_modified(self):
        before = self.snapshot()
        self.run_remote(frame())
        after = self.snapshot()
        changed = {p for p in set(before) | set(after) if before.get(p) != after.get(p)}
        allowed = {str(self.PRIV), str(self.home)}
        self.assertTrue(all(p == str(self.home) or p.startswith(str(self.PRIV) + os.sep) or p == str(self.PRIV)
                            for p in changed), changed)


@unittest.skipIf(IS_ROOT, "the host program refuses root by design")
class HardeningTests(Remote):
    PRIV = property(lambda self: self.home / ".private")
    SRC = property(lambda self: self.home / ".private" / "wpcg-staging-next-helper-1.4.12")

    def test_home_must_be_owned_by_the_login_user(self):
        real = os.lstat
        wrong = types.SimpleNamespace()

        def fake(path, *a, **k):
            info = real(path, *a, **k)
            if str(path) == str(self.home):
                fields = list(info)[:10]
                fields[4] = os.getuid() + 1
                return os.stat_result(tuple(fields))
            return info

        before = self.snapshot()
        with mock.patch.object(os, "lstat", fake):
            lines, status = self.run_remote(frame())
        self.assertEqual(lines[-1], "STAGING_1412_STAGE=STOP;CODE=directory_owner:" + str(self.home))
        self.assertEqual(self.snapshot(), before)

    def test_a_destination_that_appears_before_publication_is_never_replaced(self):
        def racing(source, destination):
            self.SRC.mkdir(mode=0o700)
            raise FileExistsError(17, "File exists", str(destination))

        code = self.code()
        self.assertTrue(code.rstrip().endswith("main()"))
        code = code.rstrip()[:-len("main()")]+"rename_directory_noreplace = RACE\nmain()\n"
        out = io.StringIO()
        stdin = types.SimpleNamespace(buffer=io.BytesIO(frame()))
        with contextlib.redirect_stdout(out), mock.patch.object(sys, "stdin", stdin):
            try:
                exec(compile(code, "<remote>", "exec"), {"__name__": "remote", "RACE": racing})
            except SystemExit as error:
                status = error.code or 0
            else:
                status = 0
        lines = out.getvalue().splitlines()
        self.assertEqual(lines[-1], "STAGING_1412_STAGE=STOP;CODE=stage_failed:FileExistsError", lines)
        self.assertTrue(any(line.startswith("ROLLBACK=HOLD;NO_AUTOMATIC_DELETE;") for line in lines))
        self.assertIn(self.SRC.name, [p.name for p in self.PRIV.iterdir()])
        self.assertEqual(list(self.SRC.iterdir()), [])

    def test_a_failing_readback_leaves_objects_for_manual_review(self):
        before = self.snapshot()
        code = self.code().replace("report = readback(uid)",
                                   'report = readback(uid)\n        stop("injected_readback_failure")')
        out = io.StringIO()
        stdin = types.SimpleNamespace(buffer=io.BytesIO(frame()))
        with contextlib.redirect_stdout(out), mock.patch.object(sys, "stdin", stdin):
            with self.assertRaises(SystemExit) as raised:
                exec(compile(code, "<remote>", "exec"), {"__name__": "remote"})
        self.assertEqual(raised.exception.code, 2)
        lines = out.getvalue().splitlines()
        self.assertEqual(lines[-2], "STAGING_1412_STAGE=STOP;CODE=injected_readback_failure")
        self.assertTrue(lines[-1].startswith("ROLLBACK=HOLD;NO_AUTOMATIC_DELETE;"))
        self.assertTrue(self.SRC.is_dir())

    def test_readback_rejects_opened_inode_mismatch(self):
        code = self.code().rstrip()
        self.assertTrue(code.endswith("main()"))
        code = code[:-len("main()")]
        namespace = {"__name__": "remote"}
        exec(compile(code, "<remote>", "exec"), namespace)
        path = self.base / "readback.txt"
        path.write_text("fixture", encoding="utf-8")
        path.chmod(0o600)
        original = os.fstat

        def wrong_inode(fd):
            fields = list(original(fd))
            fields[1] += 1
            return os.stat_result(fields)

        output = io.StringIO()
        with contextlib.redirect_stdout(output), mock.patch.object(os, "fstat", wrong_inode):
            with self.assertRaises(SystemExit) as raised:
                namespace["read_fd_bounded"](str(path), "fixture", os.getuid())
        self.assertEqual(raised.exception.code, 2)
        self.assertEqual(output.getvalue().splitlines()[-1],
                         "STAGING_1412_STAGE=STOP;CODE=readback_identity:fixture")

    def test_readback_failure_preserves_unknown_concurrent_file(self):
        code = self.code().replace("report = readback(uid)",
                                   'with open(SRC + "/outside", "wb") as injected: injected.write(b"keep")\n'
                                   '        report = readback(uid)')
        out = io.StringIO()
        stdin = types.SimpleNamespace(buffer=io.BytesIO(frame()))
        with contextlib.redirect_stdout(out), mock.patch.object(sys, "stdin", stdin):
            with self.assertRaises(SystemExit):
                exec(compile(code, "<remote>", "exec"), {"__name__": "remote"})
        lines = out.getvalue().splitlines()
        self.assertIn("STAGING_1412_STAGE=STOP;CODE=readback_contents", lines)
        self.assertTrue(lines[-1].startswith("ROLLBACK=HOLD;NO_AUTOMATIC_DELETE;"), lines)
        self.assertTrue((self.SRC / "outside").exists())
        self.assertEqual((self.SRC / "outside").read_bytes(), b"keep")
        installer = self.PRIV / "wpcaregrid-staging-next-helper-installer-1.4.12.py"
        self.assertTrue(installer.is_file())

    def test_termination_signals_leave_partial_objects_for_manual_review(self):
        import signal
        before = self.snapshot()
        original = os.fsync
        calls = {"n": 0}

        def killed(fd):
            calls["n"] += 1
            if calls["n"] == 4:
                os.kill(os.getpid(), signal.SIGTERM)
            return original(fd)

        old = signal.getsignal(signal.SIGTERM)
        try:
            with mock.patch.object(os, "fsync", killed):
                lines, status = self.run_remote(frame())
        finally:
            for name in ("SIGHUP", "SIGTERM", "SIGINT"):
                signal.signal(getattr(signal, name), signal.SIG_DFL if name != "SIGINT" else signal.default_int_handler)
        self.assertEqual(lines[-2], "STAGING_1412_STAGE=STOP;CODE=terminated_by_signal_" + str(int(signal.SIGTERM)))
        self.assertTrue(lines[-1].startswith("ROLLBACK=HOLD;NO_AUTOMATIC_DELETE;"))
        self.assertTrue(self.PRIV.exists())


class StaticTests(unittest.TestCase):
    def test_driver_is_executable_as_documented(self):
        driver = ROOT / "scripts" / "staging-next-helper-1-4-12-stage-sources.sh"
        self.assertTrue(driver.stat().st_mode & stat.S_IXUSR)

    def test_host_program_has_no_network_process_or_deletion_surface(self):
        for forbidden in ("subprocess", "socket", "urllib", "shutil", "os.system", "os.popen", "rmtree", "chown",
                          "setuid", "sudo", "eval("):
            self.assertNotIn(forbidden, TEMPLATE, forbidden)
        self.assertIsNone(re.search(r"(?<![.\w])open\(", TEMPLATE))  # builtin open() is not used; only os.open
        paths = set(re.findall(r'"(/[a-z][^"]*)"', TEMPLATE))
        self.assertEqual(paths, {"/home/deploy", "/home",
                                 "/wpcg-staging-next-helper-1.4.12",
                                 "/wpcaregrid-staging-next-helper-installer-1.4.12.py",
                                 "/var/www/wpcaregrid-staging-new", "/etc/wpcaregrid-deploy/profiles", "/tmp"})

    def test_driver_uses_no_scp_rsync_curl_sudo_and_deletes_only_its_work_dir(self):
        shell = DRIVER[:START] + DRIVER[END:]
        for forbidden in ("scp ", "rsync", "curl ", "wget", "sudo ", "su ", "ssh-copy-id", "-o StrictHostKeyChecking=no"):
            self.assertNotIn(forbidden, shell, forbidden)
        self.assertEqual(shell.count("rm -rf"), 1)
        self.assertIn("rm -rf \"$WORK\"", shell)
        self.assertEqual(len(re.findall(r"^\s*ssh ", shell, re.M)), 1)
        self.assertIn("-o StrictHostKeyChecking=yes", shell)

    def test_pins_match_the_repository_manifest_and_commit_blobs(self):
        import json
        manifest = json.loads((ROOT / "deploy" / "release-manifests" / "staging-next-helper-1.4.12.json").read_text())
        expected = {
            "wpcaregrid-staging-next-deploy-priv-1_4_12.py": manifest["entrypoint_sha256"],
            "staging_next_deploy_backend_1_4_12.py": manifest["backend_sha256"],
            "staging_next_db_transaction_1_4_12.py": manifest["transaction_module_sha256"],
            "deployment_target_binding_1_4_12.py": manifest["target_binding_sha256"],
            "deployment_environment_contract_1_4_12.py": manifest["environment_contract_sha256"],
            "install_staging_next_helper_1_4_12.py": hashlib.sha256(
                (ROOT / "scripts" / "install_staging_next_helper_1_4_12.py").read_bytes()).hexdigest(),
        }
        block = DRIVER[DRIVER.index("PINS=(") : DRIVER.index("\n)\n", DRIVER.index("PINS=(")) ]
        found = dict(re.findall(r'"(\S+) ([0-9a-f]{64})"', block))
        self.assertEqual(found, expected)
        source_files = {
            "wpcaregrid-staging-next-deploy-priv-1_4_12.py": ROOT / "scripts/wpcaregrid-staging-next-deploy-priv-1_4_12.py",
            "staging_next_deploy_backend_1_4_12.py": ROOT / "scripts/staging_next_deploy_backend_1_4_12.py",
            "staging_next_db_transaction_1_4_12.py": ROOT / "scripts/staging_next_db_transaction_1_4_12.py",
            "deployment_target_binding_1_4_12.py": ROOT / "scripts/deployment_target_binding_1_4_12.py",
            "deployment_environment_contract_1_4_12.py": ROOT / "scripts/deployment_environment_contract_1_4_12.py",
            "install_staging_next_helper_1_4_12.py": ROOT / "scripts/install_staging_next_helper_1_4_12.py",
        }
        for name, path in source_files.items():
            self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), expected[name], name)


def make_repo(base: Path, files=None):
    repo = base / "repo"
    (repo / "scripts").mkdir(parents=True)
    env = {**os.environ, "GIT_AUTHOR_NAME": "t", "GIT_AUTHOR_EMAIL": "t@example.invalid",
           "GIT_COMMITTER_NAME": "t", "GIT_COMMITTER_EMAIL": "t@example.invalid"}
    subprocess.run(["git", "init", "-q", str(repo)], check=True, env=env)
    for name, data in (CONTENT if files is None else files).items():
        (repo / "scripts" / name).write_bytes(data)
    subprocess.run(["git", "-C", str(repo), "add", "-A"], check=True, env=env)
    subprocess.run(["git", "-C", str(repo), "commit", "-q", "-m", "fixture"], check=True, env=env)
    commit = subprocess.run(["git", "-C", str(repo), "rev-parse", "HEAD"], check=True, capture_output=True,
                            text=True).stdout.strip()
    return repo, commit


@unittest.skipIf(IS_ROOT, "the host program refuses root by design")
@unittest.skipUnless(shutil.which("bash") and shutil.which("git") and shutil.which("base64"), "needs bash/git/base64")
class DriverEndToEnd(unittest.TestCase):
    def setUp(self):
        self.base = Path(os.path.realpath(tempfile.mkdtemp(prefix="stage1410-e2e-")))
        self.addCleanup(shutil.rmtree, self.base, ignore_errors=True)
        self.home = self.base / "home" / "deploy"
        self.home.mkdir(parents=True)
        self.home.chmod(0o755)
        (self.base / "home").chmod(0o755)
        self.app_dir = self.base / "staging-app"
        self.profile_dir = self.base / "profiles"
        for directory in (self.app_dir, self.profile_dir):
            directory.mkdir(parents=True, exist_ok=True)
        self.repo, self.commit = make_repo(self.base)
        self.bin = self.base / "bin"
        self.bin.mkdir()
        self.log = self.base / "ssh.log"
        fake = self.bin / "ssh"
        fake.write_text('#!/bin/sh\n'
                        'echo "$@" >> "$FAKE_SSH_LOG"\n'
                        '[ -n "$FAKE_SSH_FAIL" ] && exit "$FAKE_SSH_FAIL"\n'
                        'for last; do :; done\n'
                        'exec sh -c "$last"\n', encoding="utf-8")
        fake.chmod(0o755)
        self.driver = self.repo / "scripts" / "staging-next-helper-1-4-12-stage-sources.sh"
        self.write_driver()

    def write_driver(self, commit=None, pins=None):
        text = DRIVER.replace('USER = "deploy"', f'USER = "{USERNAME}"').replace('HOME = "/home/deploy"', f'HOME = "{self.home}"')
        text = text.replace('APP_DIR = "/var/www/wpcaregrid-staging-new"', f'APP_DIR = "{self.app_dir}"')
        text = text.replace('PROFILE_DIR = "/etc/wpcaregrid-deploy/profiles"', f'PROFILE_DIR = "{self.profile_dir}"')
        text = text.replace('ROOT_UID = 0', f'ROOT_UID = {os.getuid()}')
        block_start = text.index("PINS=(")
        block_end = text.index("\n)\n", block_start) + 3
        entries = "\n".join(f'  "{n} {d}"' for n, d in (PINS if pins is None else pins).items())
        text = text[:block_start] + "PINS=(\n" + entries + "\n)\n" + text[block_end:]
        self.driver.write_text(text, encoding="utf-8")
        fixture_git_env = {**os.environ, "GIT_AUTHOR_NAME": "Stage test fixture",
                           "GIT_AUTHOR_EMAIL": "stage-test@example.invalid",
                           "GIT_COMMITTER_NAME": "Stage test fixture",
                           "GIT_COMMITTER_EMAIL": "stage-test@example.invalid"}
        subprocess.run(["git", "-C", str(self.repo), "add", "scripts/staging-next-helper-1-4-12-stage-sources.sh"],
                       check=True, env=fixture_git_env)
        subprocess.run(["git", "-C", str(self.repo), "commit", "--amend", "-q", "--no-edit"],
                       check=True, env=fixture_git_env)

    def run_driver(self, mode="stage", target="caregrid-staging", fail=None, cwd=None, tool_commit=None):
        env = {**os.environ, "PATH": f"{self.bin}:{os.environ['PATH']}", "SSH_TARGET": target,
               "FAKE_SSH_LOG": str(self.log), "FAKE_SSH_FAIL": str(fail) if fail else "",
               "EXPECTED_TOOL_COMMIT": tool_commit or subprocess.check_output(
                   ["git", "-C", str(self.repo), "rev-parse", "HEAD"], text=True).strip()}
        if mode is None:
            env.pop("MODE", None)
        else:
            env["MODE"] = mode
        done = subprocess.run(["bash", str(self.driver)], cwd=cwd or self.repo, env=env, capture_output=True, text=True)
        return done.returncode, done.stdout.splitlines(), done.stderr

    def ssh_calls(self):
        return len(self.log.read_text().splitlines()) if self.log.exists() else 0

    def test_stage_end_to_end_then_never_overwrites(self):
        code, lines, err = self.run_driver()
        self.assertEqual(code, 0, (lines, err))
        self.assertEqual(lines[-1], "STAGING_1412_STAGE=PASS")
        self.assertTrue(any(l.startswith("LOCAL_VERIFY=PASS") for l in lines))
        self.assertEqual(len([l for l in lines if l.startswith("FILE|")]), 6)
        priv = self.home / ".private"
        self.assertEqual(sorted(p.name for p in priv.iterdir()),
                         ["wpcaregrid-staging-next-helper-installer-1.4.12.py", "wpcg-staging-next-helper-1.4.12"])
        code, lines, _ = self.run_driver()
        self.assertEqual((code, lines[-1]), (2, "STAGING_1412_STAGE=STOP;CODE=destination_exists"))

    def test_check_mode_writes_nothing(self):
        code, lines, err = self.run_driver(mode="check")
        self.assertEqual((code, lines[-1]), (0, "STAGING_1412_STAGE=CHECK_PASS"), err)
        self.assertEqual(list(self.home.iterdir()), [])

    def test_unset_mode_defaults_to_check_without_writing(self):
        code, lines, err = self.run_driver(mode=None)
        self.assertEqual((code, lines[-1]), (0, "STAGING_1412_STAGE=CHECK_PASS"), err)
        self.assertEqual(list(self.home.iterdir()), [])

    def test_modified_driver_refuses_before_ssh(self):
        self.driver.write_text(self.driver.read_text(encoding="utf-8") + "\n# modified\n", encoding="utf-8")
        code, lines, _ = self.run_driver(mode="check")
        self.assertEqual((code, lines[-1]), (2, "STAGING_1412_STAGE=STOP;CODE=local_script_modified"))
        self.assertEqual(self.ssh_calls(), 0)

    def test_local_pin_mismatch_stops_before_ssh_is_ever_called(self):
        self.write_driver(pins=dict(PINS, **{NAMES[2]: "0" * 64}))
        code, lines, _ = self.run_driver()
        self.assertEqual((code, lines[-1]), (2, f"STAGING_1412_STAGE=STOP;CODE=local_pin_mismatch:{NAMES[2]}"))
        self.assertEqual(self.ssh_calls(), 0)
        self.assertEqual(list(self.home.iterdir()), [])

    def test_missing_commit_invalid_mode_target_and_outside_a_clone(self):
        # The caller must bind the currently checked-out full commit, not an arbitrary SHA.
        self.write_driver()
        self.assertEqual(self.run_driver(mode="deploy")[1][-1], "STAGING_1412_STAGE=STOP;CODE=invalid_mode")
        for target in ("-oProxyCommand=x", "host name", "a;b", "$(x)", "other-host"):
            self.assertEqual(self.run_driver(target=target)[1][-1], "STAGING_1412_STAGE=STOP;CODE=invalid_ssh_target", target)
        self.assertEqual(self.run_driver(tool_commit="0" * 40)[1][-1],
                         "STAGING_1412_STAGE=STOP;CODE=tool_head_mismatch")
        outside = self.base / "not-a-clone"
        outside.mkdir()
        self.assertEqual(self.run_driver(cwd=outside)[1][-1], "STAGING_1412_STAGE=STOP;CODE=not_in_a_git_clone")
        self.assertEqual(self.ssh_calls(), 0)

    def test_ssh_failure_without_a_valid_result_is_a_stop(self):
        code, lines, _ = self.run_driver(fail=255)
        self.assertEqual(code, 2)
        self.assertTrue(lines[-1].startswith("STAGING_1412_STAGE=STOP;CODE=no_valid_result"), lines)
        self.assertEqual(list(self.home.iterdir()), [])

    def test_remote_stop_propagates_with_exit_2(self):
        (self.home / ".private").mkdir(mode=0o700)
        (self.home / ".private" / "wpcaregrid-staging-next-helper-installer-1.4.12.py").write_text("old")
        code, lines, _ = self.run_driver()
        self.assertEqual((code, lines[-1]), (2, "STAGING_1412_STAGE=STOP;CODE=destination_exists"))
        self.assertEqual((self.home / ".private" / "wpcaregrid-staging-next-helper-installer-1.4.12.py").read_text(), "old")

    def test_host_never_receives_a_tampered_repo_file(self):
        (self.repo / "scripts" / NAMES[0]).write_bytes(b"locally modified, uncommitted")
        code, lines, _ = self.run_driver()  # the driver exports committed blobs, not the working tree
        self.assertEqual((code, lines[-1]), (0, "STAGING_1412_STAGE=PASS"))
        staged = self.home / ".private" / "wpg-none"
        src = self.home / ".private" / "wpcg-staging-next-helper-1.4.12" / NAMES[0]
        self.assertEqual(hashlib.sha256(src.read_bytes()).hexdigest(), PINS[NAMES[0]])

    def test_a_bare_pass_from_the_host_is_not_trusted(self):
        fake = self.bin / "ssh"
        fake.write_text('#!/bin/sh\ncat > /dev/null\necho STAGING_1412_STAGE=PASS\n', encoding="utf-8")
        code, lines, _ = self.run_driver()
        self.assertEqual((code, lines[-1]), (2, "STAGING_1412_STAGE=STOP;CODE=remote_report_does_not_match_pins"))

    def test_reported_digests_must_match_the_local_pins(self):
        fake = self.bin / "ssh"
        reported = "".join(f"echo 'FILE|/home/deploy/.private/{'wpcaregrid-staging-next-helper-installer-1.4.12.py' if n.startswith('install_') else 'wpcg-staging-next-helper-1.4.12/' + n}|{'0' * 64 if i == 0 else PINS[n]}|0600|uid=1'\n"
                           for i, n in enumerate(NAMES))
        fake.write_text('#!/bin/sh\ncat > /dev/null\n' + reported + 'echo STAGING_1412_STAGE=PASS\n', encoding="utf-8")
        code, lines, _ = self.run_driver()
        self.assertEqual((code, lines[-1]), (2, "STAGING_1412_STAGE=STOP;CODE=remote_report_does_not_match_pins"))

    def test_a_forged_check_pass_in_stage_mode_and_vice_versa_are_stops(self):
        fake = self.bin / "ssh"
        fake.write_text('#!/bin/sh\ncat > /dev/null\necho STAGING_1412_STAGE=CHECK_PASS\n', encoding="utf-8")
        self.assertEqual(self.run_driver(mode="stage")[1][-1], "STAGING_1412_STAGE=STOP;CODE=unexpected_check_pass_in_stage_mode")
        fake.write_text('#!/bin/sh\ncat > /dev/null\necho STAGING_1412_STAGE=PASS\n', encoding="utf-8")
        self.assertEqual(self.run_driver(mode="check")[1][-1], "STAGING_1412_STAGE=STOP;CODE=unexpected_pass_in_check_mode")

    def test_driver_crosscheck_line_is_printed_on_success(self):
        code, lines, _ = self.run_driver()
        self.assertEqual(code, 0)
        self.assertIn("LOCAL_CROSSCHECK=PASS;REPORTED_FILES=6", lines)
        self.assertEqual(lines[-1], "STAGING_1412_STAGE=PASS")
        self.assertEqual(len([l for l in lines if l.startswith("STAGING_1412_STAGE=")]), 1)
        self.assertEqual(len([l for l in lines if l.startswith("REMOTE_RESULT=")]), 1)

    def test_driver_never_shows_a_remote_pass_followed_by_its_own_stop(self):
        fake = self.bin / "ssh"
        fake.write_text('#!/bin/sh\ncat > /dev/null\necho STAGING_1412_STAGE=PASS\n', encoding="utf-8")
        code, lines, _ = self.run_driver()
        self.assertEqual(len([l for l in lines if l.startswith("STAGING_1412_STAGE=")]), 1, lines)
        self.assertEqual(lines[-1], "STAGING_1412_STAGE=STOP;CODE=remote_report_does_not_match_pins")

    def test_unparseable_remote_stop_codes_are_sanitised(self):
        fake = self.bin / "ssh"
        fake.write_text('#!/bin/sh\ncat > /dev/null\nprintf "STAGING_1412_STAGE=STOP;CODE=bad code;rm -rf /\\n"\n', encoding="utf-8")
        code, lines, _ = self.run_driver()
        self.assertEqual((code, lines[-1]), (2, "STAGING_1412_STAGE=STOP;CODE=remote_stop_unparseable"))

    def test_untracked_script_copy_refuses_before_ssh(self):
        copy = self.base / "copied-driver.sh"
        copy.write_text(self.driver.read_text(encoding="utf-8"), encoding="utf-8")
        env = {**os.environ, "PATH": f"{self.bin}:{os.environ['PATH']}", "SSH_TARGET": "caregrid-staging", "MODE": "check",
               "EXPECTED_TOOL_COMMIT": subprocess.check_output(["git", "-C", str(self.repo), "rev-parse", "HEAD"], text=True).strip(),
               "FAKE_SSH_LOG": str(self.log), "FAKE_SSH_FAIL": ""}
        result = subprocess.run(["bash", str(copy)], cwd=self.repo, env=env, capture_output=True, text=True)
        self.assertEqual(result.stdout.splitlines()[-1], "STAGING_1412_STAGE=STOP;CODE=local_script_path_invalid")
        self.assertEqual(self.ssh_calls(), 0)


if __name__ == "__main__":
    unittest.main()
