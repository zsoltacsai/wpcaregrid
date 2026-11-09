#!/usr/bin/env python3
"""Tests for scripts/staging-149-stage-on-host.py (on-host Owner GO #1, run as root on the staging host).

The program is exec'd in-process against a temporary home with a fake downloader; root is simulated by
patching os.geteuid (chown to the current user is permitted). Nothing touches the network or a real host.
"""

from __future__ import annotations

import contextlib
import hashlib
import io
import json
import os
import pwd
import re
import shutil
import signal
import stat
import sys
import tempfile
import types
import unittest
import urllib.error
import urllib.request
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
SOURCE = (ROOT / "scripts" / "staging-149-stage-on-host.py").read_text(encoding="utf-8")
CODE = SOURCE.split('"""', 2)[2]  # without the module docstring
ENTRY = pwd.getpwuid(os.getuid())
IS_ROOT = os.geteuid() == 0
INSTALLER = "install_staging_next_helper_1_4_9.py"
NAMES = ["wpcaregrid-staging-next-deploy-priv-1_4_9.py", "staging_next_deploy_backend_1_4_9.py",
         "staging_next_db_transaction_1_4_9.py", "deployment_target_binding.py",
         "deployment_environment_contract.py", INSTALLER]
CONTENT = {name: (f"# fixture {name}\n" + "x = 1\n" * 40).encode() for name in NAMES}
PINS = {name: hashlib.sha256(data).hexdigest() for name, data in CONTENT.items()}


def good_fetch(name):
    return CONTENT[name]


@unittest.skipIf(IS_ROOT, "the tests simulate root by patching geteuid")
class Host(unittest.TestCase):
    def setUp(self):
        self.base = Path(os.path.realpath(tempfile.mkdtemp(prefix="hs149-")))
        self.addCleanup(shutil.rmtree, self.base, ignore_errors=True)
        self.home = self.base / "deploy"
        self.home.mkdir(mode=0o755)
        self.home.chmod(0o755)
        self.drops = []
        self.priv = self.home / ".private"
        self.src = self.priv / "wpcg-staging-next-helper-1.4.9"
        self.inst = self.priv / "wpcaregrid-staging-next-helper-installer-1.4.9.py"
        names = ("SIGHUP", "SIGTERM", "SIGINT", "SIGALRM")
        self.saved = {name: signal.getsignal(getattr(signal, name)) for name in names}
        self.addCleanup(lambda: [signal.signal(getattr(signal, n), h) for n, h in self.saved.items()])
        self.addCleanup(lambda: signal.pthread_sigmask(signal.SIG_UNBLOCK, {getattr(signal, n) for n in names}))
        self.addCleanup(signal.alarm, 0)

    def load(self, user=ENTRY.pw_name, pw_dir=ENTRY.pw_dir, home=None):
        text = (SOURCE.replace('USER = "deploy"', f'USER = "{user}"')
                .replace('HOME = "/home/deploy"', f'HOME = "{home or self.home}"')
                .replace('EXPECTED_PW_DIR = "/home/deploy"', f'EXPECTED_PW_DIR = "{pw_dir}"'))
        namespace = {"__name__": "hostmod"}
        exec(compile(text, "<host>", "exec"), namespace)
        namespace["PINS"].clear()
        namespace["PINS"].update(PINS)
        return namespace

    def run_host(self, mode="stage", fetcher=good_fetch, root=True, namespace=None, patches=()):
        namespace = namespace or self.load()
        out = io.StringIO()
        status = 0
        with contextlib.ExitStack() as stack:
            stack.enter_context(contextlib.redirect_stdout(out))
            if root:
                stack.enter_context(mock.patch.object(os, "geteuid", return_value=0))
                for fn in ("setgroups", "setegid", "seteuid"):
                    stack.enter_context(mock.patch.object(
                        os, fn, lambda value, fn=fn: self.drops.append((fn, value))))
            for patcher in patches:
                stack.enter_context(patcher)
            try:
                namespace["main"](mode, fetcher)
            except SystemExit as error:
                status = error.code or 0
        return out.getvalue().splitlines(), status

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
        finals = [l for l in lines if l.startswith("STAGING_149_STAGE=")]
        self.assertEqual(len(finals), 1, lines)
        self.assertEqual(lines[-1], finals[0])
        return finals[0]

    def stop(self, code, **kwargs):
        before = self.snapshot()
        lines, status = self.run_host(**kwargs)
        self.assertEqual(self.final(lines), "STAGING_149_STAGE=STOP;CODE=" + code, lines)
        self.assertEqual(status, 2)
        self.assertEqual(self.snapshot(), before, "a STOP must leave the host tree untouched")


class StageTests(Host):
    def test_check_downloads_verifies_and_writes_nothing(self):
        before = self.snapshot()
        lines, status = self.run_host(mode="check")
        self.assertEqual(self.final(lines), "STAGING_149_STAGE=CHECK_PASS")
        self.assertEqual((status, self.snapshot()), (0, before))
        self.assertFalse(self.priv.exists())
        self.assertTrue(any(l.startswith("PRECONDITIONS=PASS;DOWNLOAD=VERIFIED;FILES=6") for l in lines))

    def test_stage_creates_the_exact_layout_owned_by_deploy_with_private_modes(self):
        lines, status = self.run_host()
        self.assertEqual((self.final(lines), status), ("STAGING_149_STAGE=PASS", 0))
        self.assertEqual(stat.S_IMODE(os.lstat(self.priv).st_mode), 0o700)
        self.assertEqual(stat.S_IMODE(os.lstat(self.src).st_mode), 0o700)
        self.assertEqual(sorted(os.listdir(self.src)), sorted(n for n in NAMES if n != INSTALLER))
        for name in NAMES:
            path = self.inst if name == INSTALLER else self.src / name
            info = os.lstat(path)
            self.assertTrue(stat.S_ISREG(info.st_mode))
            self.assertEqual((stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid, info.st_nlink),
                             (0o600, ENTRY.pw_uid, ENTRY.pw_gid, 1))
            self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), PINS[name])
        self.assertEqual(sorted(os.listdir(self.priv)), sorted([self.src.name, self.inst.name]))
        self.assertEqual(len([l for l in lines if l.startswith("FILE|")]), 6)

    def test_never_overwrites_a_second_run_or_any_existing_destination(self):
        self.run_host()
        self.stop("destination_exists")
        shutil.rmtree(self.src)
        self.stop("destination_exists")  # installer still there
        self.inst.unlink()
        self.priv.chmod(0o700)
        os.symlink("/nonexistent", self.inst)
        self.stop("destination_exists")
        self.inst.unlink()
        os.symlink(str(self.base), self.src)
        self.stop("destination_exists")

    def test_preexisting_private_directory_and_its_contents_are_preserved(self):
        self.priv.mkdir(mode=0o700)
        (self.priv / "keep.txt").write_text("keep")
        lines, _ = self.run_host()
        self.assertEqual(self.final(lines), "STAGING_149_STAGE=PASS")
        self.assertEqual((self.priv / "keep.txt").read_text(), "keep")

    def test_output_never_contains_file_content(self):
        lines, _ = self.run_host()
        self.assertNotIn("fixture", "\n".join(lines))
        self.assertTrue(all(l.startswith(("PRECONDITIONS=", "FILE|", "STAGING_149_STAGE=")) for l in lines), lines)


class DownloadTests(Host):
    def test_every_hash_mismatch_empty_or_oversized_download_is_a_stop_before_any_write(self):
        for name in NAMES:
            for bad in (b"tampered", b"", b"x" * 2_000_001, None, "text"):
                def fetcher(requested, name=name, bad=bad):
                    return bad if requested == name else CONTENT[requested]
                code = "download_hash_mismatch:" + name if bad == b"tampered" else "download_size_invalid:" + name
                self.stop(code, fetcher=fetcher, mode="stage")

    def test_download_failures_are_stops(self):
        def broken(name):
            raise urllib.error.URLError("no route")
        lines, status = self.run_host(fetcher=broken)
        self.assertEqual((lines[-1], status), ("STAGING_149_STAGE=STOP;CODE=download_failed:" + NAMES[0] + ":URLError", 2))
        self.assertFalse(self.priv.exists())

    def test_unexpected_exceptions_still_end_with_one_stop_line(self):
        namespace = self.load()
        out = io.StringIO()

        def boom(*args, **kwargs):
            raise ZeroDivisionError("secret-ish")

        namespace["main"] = boom
        with contextlib.redirect_stdout(out):
            with self.assertRaises(SystemExit) as raised:
                namespace["run"]()
        self.assertEqual(raised.exception.code, 2)
        self.assertEqual(out.getvalue().splitlines(), ["STAGING_149_STAGE=STOP;CODE=unexpected_error:ZeroDivisionError"])

    def test_real_fetch_enforces_https_no_proxy_no_redirect_and_size_cap(self):
        namespace = self.load()
        self.assertIn("ProxyHandler({})", SOURCE)
        handler = namespace["_NoRedirect"]()
        self.assertIsNone(handler.redirect_request(None, None, 302, "Found", {}, "https://evil.example/x"))
        self.assertTrue(namespace["BASE_URL"].startswith("https://raw.githubusercontent.com/zsoltacsai/wpcaregrid/"))

        class Response:
            def __init__(self, status, url, body):
                self.status, self._url, self._body = status, url, body

            def geturl(self):
                return self._url

            def read(self, limit):
                return self._body[:limit]

            def __enter__(self):
                return self

            def __exit__(self, *a):
                return False

        def with_response(response=None, error=None):
            opener = mock.Mock()
            if error:
                opener.open.side_effect = error
            else:
                opener.open.return_value = response
            return mock.patch.object(urllib.request, "build_opener", return_value=opener), opener

        name = NAMES[0]
        url = namespace["BASE_URL"] + name
        patcher, opener = with_response(Response(200, url, b"abc"))
        with patcher:
            self.assertEqual(namespace["fetch"](name), b"abc")
        request = opener.open.call_args[0][0]
        self.assertEqual(request.full_url, url)
        self.assertEqual(opener.open.call_args[1]["timeout"], 30)
        for response, error, code in (
                (Response(404, url, b""), None, "download_unexpected_response:"),
                (Response(200, "https://elsewhere.example/x", b"abc"), None, "download_unexpected_response:"),
                (None, urllib.error.HTTPError(url, 302, "Found", {}, None), "download_failed:"),
                (None, OSError("boom"), "download_failed:")):
            patcher, _ = with_response(response, error)
            out = io.StringIO()
            with patcher, contextlib.redirect_stdout(out):
                with self.assertRaises(SystemExit):
                    namespace["fetch"](name)
            self.assertIn("STAGING_149_STAGE=STOP;CODE=" + code + name, out.getvalue())
        # an over-long body is read only up to the cap + 1 byte, then rejected as oversized by the caller
        patcher, _ = with_response(Response(200, url, b"x" * 5_000_000))
        with patcher:
            self.assertEqual(len(namespace["fetch"](name)), 2_000_001)


class PreconditionTests(Host):
    def test_not_root_and_usage(self):
        self.stop("must_run_as_root", root=False)
        for mode in ("", "install", "STAGE", None):
            namespace = self.load()
            out = io.StringIO()
            with contextlib.redirect_stdout(out), mock.patch.object(sys, "argv", ["-"]):
                with self.assertRaises(SystemExit):
                    namespace["main"](mode, good_fetch)
            self.assertIn("usage_check_or_stage", out.getvalue())

    def test_deploy_user_identity(self):
        self.stop("deploy_user_missing", namespace=self.load(user="no-such-user-xyz"))
        self.stop("deploy_user_identity_invalid", namespace=self.load(pw_dir="/somewhere/else"))
        fake = types.SimpleNamespace(pw_uid=0, pw_gid=0, pw_dir=ENTRY.pw_dir)
        self.stop("deploy_user_identity_invalid", patches=[mock.patch.object(pwd, "getpwnam", return_value=fake)])

    def test_home_must_be_a_real_directory_owned_by_deploy_and_not_writable_by_others(self):
        self.home.chmod(0o775)
        self.stop("directory_writable_by_group_or_other:" + str(self.home))
        self.home.chmod(0o755)
        real = os.fstat

        def foreign(fd):
            info = real(fd)
            if stat.S_ISDIR(info.st_mode) and info.st_ino == os.lstat(self.home).st_ino:
                fields = list(info)[:10]
                fields[4] = ENTRY.pw_uid + 1
                return os.stat_result(tuple(fields))
            return info

        self.stop("directory_owner:" + str(self.home), patches=[mock.patch.object(os, "fstat", foreign)])
        link = self.base / "linked-home"
        os.symlink(self.home, link)
        self.stop("directory_open_failed:" + str(link), namespace=self.load(home=link))

    def test_private_directory_must_be_a_real_0700_directory_owned_by_deploy(self):
        self.priv.mkdir(mode=0o750)
        os.chmod(self.priv, 0o750)
        self.stop("directory_mode:" + str(self.home) + "/.private")
        os.chmod(self.priv, 0o770)
        self.stop("directory_writable_by_group_or_other:" + str(self.home) + "/.private")
        self.priv.rmdir()
        elsewhere = self.base / "elsewhere"
        elsewhere.mkdir(mode=0o700)
        os.symlink(elsewhere, self.priv)
        self.stop("directory_open_failed:.private")
        self.assertEqual(list(elsewhere.iterdir()), [])

    def test_untrusted_root_parents_and_production_paths_stop(self):
        real_lstat = os.stat

        def fake_stat(path, *a, **k):
            if path == "/home" and not a and not k.get("dir_fd"):
                info = real_lstat(path, *a, **k)
                fields = list(info)[:10]
                fields[4] = 1234
                return os.stat_result(tuple(fields))
            return real_lstat(path, *a, **k)

        self.stop("untrusted_parent:/home", patches=[mock.patch.object(os, "stat", fake_stat)])
        namespace = self.load()
        original = namespace["lstat_at"]
        namespace["lstat_at"] = lambda path, dir_fd=None: (os.stat("/") if path == "/var/www/wpcaregrid-production"
                                                           else original(path, dir_fd))
        self.stop("production_paths_present", namespace=namespace)

    def test_low_disk_space_stops(self):
        fake = types.SimpleNamespace(f_bavail=1, f_frsize=4096, f_flag=0)
        self.stop("disk_space_or_read_only", patches=[mock.patch.object(os, "statvfs", return_value=fake)])


class SafetyTests(Host):
    def test_privileges_are_dropped_to_deploy_before_anything_is_created_and_no_chown_exists(self):
        self.assertNotIn("chown(", CODE)
        self.assertNotIn("fchown", CODE)
        order = []
        real_mkdir = os.mkdir
        with mock.patch.object(os, "mkdir", lambda *a, **k: (order.append("mkdir"), real_mkdir(*a, **k))[1]):
            self.run_host()
        self.assertEqual(self.drops[:3], [("setgroups", []), ("setegid", ENTRY.pw_gid), ("seteuid", ENTRY.pw_uid)])
        self.assertEqual(order[0], "mkdir")
        # check mode never drops privileges
        shutil.rmtree(self.priv)
        self.drops.clear()
        self.run_host(mode="check")
        self.assertEqual(self.drops, [])

    def test_a_failed_privilege_drop_is_a_stop_before_any_write(self):
        patch = mock.patch.object(os, "setegid", side_effect=PermissionError("no"))
        self.stop("privilege_drop_failed", patches=[patch])

    def test_a_swapped_in_root_owned_non_empty_directory_with_link_count_two_is_rejected(self):
        self.priv.mkdir(mode=0o700)
        victim = self.base / "victim"
        victim.mkdir(mode=0o700)
        (victim / "file").write_text("x")  # no subdirectory: link count 2, but not empty
        real = os.mkdir

        def swapping(path, mode=0o777, *, dir_fd=None):
            real(path, mode, dir_fd=dir_fd)
            if path == self.src.name:
                os.rename(self.src, self.base / "moved-src")
                os.rename(victim, self.src)

        with mock.patch.object(os, "mkdir", swapping):
            lines, _ = self.run_host()
        self.assertEqual(lines[-1], "STAGING_149_STAGE=STOP;CODE=stage_failed:RuntimeError")
        self.assertEqual((self.src / "file").read_text(), "x")

    def test_a_swapped_private_directory_cannot_redirect_root(self):
        self.priv.mkdir(mode=0o700)
        attack = self.base / "attack"
        attack.mkdir(mode=0o700)
        moved = self.base / "moved-private"

        def swapping(name):
            if name == NAMES[0]:  # runs after .private was opened (fd pinned to its inode)
                os.rename(self.priv, moved)
                os.symlink(attack, self.priv)
            return CONTENT[name]

        lines, status = self.run_host(fetcher=swapping)
        self.assertEqual(list(attack.iterdir()), [], "root must write only through the validated directory fd")
        self.assertTrue(self.final(lines).startswith("STAGING_149_STAGE="))

    def test_failure_during_publication_rolls_back_everything_it_created(self):
        before = self.snapshot()
        with mock.patch.object(os, "fsync", side_effect=OSError("boom")):
            lines, status = self.run_host()
        self.assertEqual((lines[-1], status), ("STAGING_149_STAGE=STOP;CODE=stage_failed:OSError", 2))
        self.assertEqual(self.snapshot(), before)

    def test_rollback_keeps_a_preexisting_private_directory(self):
        self.priv.mkdir(mode=0o700)
        (self.priv / "keep.txt").write_text("keep")
        before = self.snapshot()
        with mock.patch.object(os, "fsync", side_effect=OSError("boom")):
            self.run_host()
        self.assertEqual(self.snapshot(), before)

    def test_failures_while_writing_or_chowning_leave_nothing(self):
        before = self.snapshot()
        original = os.fsync
        count = {"n": 0}

        def failing(fd):
            count["n"] += 1
            if count["n"] == 3:
                raise OSError("disk")
            return original(fd)

        with mock.patch.object(os, "fsync", failing):
            lines, _ = self.run_host()
        self.assertEqual(lines[-1], "STAGING_149_STAGE=STOP;CODE=stage_failed:OSError")
        self.assertEqual(self.snapshot(), before)
        with mock.patch.object(os, "fchmod", side_effect=PermissionError("no")):
            lines, _ = self.run_host()
        self.assertEqual(lines[-1], "STAGING_149_STAGE=STOP;CODE=stage_failed:PermissionError")
        self.assertEqual(self.snapshot(), before)

    def test_a_destination_that_appears_before_creation_is_never_replaced(self):
        self.priv.mkdir(mode=0o700)

        def racing(name):
            if name == NAMES[0]:
                self.src.mkdir(mode=0o700)
                (self.src / "intruder.txt").write_text("mine")
            return CONTENT[name]

        lines, _ = self.run_host(fetcher=racing)
        self.assertEqual(lines[-1], "STAGING_149_STAGE=STOP;CODE=stage_failed:FileExistsError")
        self.assertEqual([p.name for p in self.src.iterdir()], ["intruder.txt"])
        self.assertEqual(sorted(p.name for p in self.priv.iterdir()), [self.src.name])

    def test_a_directory_swapped_in_after_mkdir_is_never_chowned_or_chmoded(self):
        self.priv.mkdir(mode=0o700)
        victim = self.base / "victim"
        victim.mkdir()
        victim.chmod(0o777)
        moved = self.base / "moved-src"
        real = os.mkdir

        def swapping(path, mode=0o777, *, dir_fd=None):
            real(path, mode, dir_fd=dir_fd)
            if path == self.src.name:
                os.rename(self.src, moved)
                os.rename(victim, self.src)

        chowns = []
        with mock.patch.object(os, "mkdir", swapping), mock.patch.object(os, "fchown", lambda *a: chowns.append(a)):
            lines, status = self.run_host()
        self.assertEqual((lines[-1], status), ("STAGING_149_STAGE=STOP;CODE=stage_failed:RuntimeError", 2))
        self.assertEqual(chowns, [])

    def test_a_swapped_in_directory_with_content_or_a_foreign_owner_is_rejected_before_chown(self):
        self.priv.mkdir(mode=0o700)
        victim = self.base / "victim"
        victim.mkdir(mode=0o700)
        (victim / "sub").mkdir()  # link count 3: not the empty directory that was just made
        real = os.mkdir

        def swapping(path, mode=0o777, *, dir_fd=None):
            real(path, mode, dir_fd=dir_fd)
            if path == self.src.name:
                os.rename(self.src, self.base / "moved-src")
                os.rename(victim, self.src)

        chowns = []
        with mock.patch.object(os, "mkdir", swapping), mock.patch.object(os, "fchown", lambda *a: chowns.append(a)):
            lines, _ = self.run_host()
        self.assertEqual(lines[-1], "STAGING_149_STAGE=STOP;CODE=stage_failed:RuntimeError")
        self.assertEqual(chowns, [])
        # a directory owned by somebody else (as seen by fstat) is rejected too
        shutil.rmtree(self.src)
        shutil.rmtree(self.base / "moved-src")
        real_fstat = os.fstat

        def foreign(fd):
            info = real_fstat(fd)
            if stat.S_ISDIR(info.st_mode) and info.st_nlink == 2 and info.st_ino != os.lstat(self.home).st_ino:
                fields = list(info)[:10]
                fields[4] = info.st_uid + 1
                return os.stat_result(tuple(fields))
            return info

        with mock.patch.object(os, "fstat", foreign), mock.patch.object(os, "fchown", lambda *a: chowns.append(a)):
            lines, _ = self.run_host()
        self.assertTrue(lines[-1].startswith("STAGING_149_STAGE=STOP;CODE="), lines)
        self.assertEqual(chowns, [])

    def test_a_file_or_symlink_planted_at_the_file_name_is_never_followed_or_truncated(self):
        self.priv.mkdir(mode=0o700)
        target = self.base / "target.txt"
        target.write_text("precious")
        real = os.open

        def planting(path, flags, *args, **kwargs):
            if path == NAMES[0] and flags & os.O_EXCL:
                os.symlink(target, self.src / NAMES[0])
            return real(path, flags, *args, **kwargs)

        with mock.patch.object(os, "open", planting):
            lines, _ = self.run_host()
        self.assertEqual(lines[-1], "STAGING_149_STAGE=STOP;CODE=stage_failed:FileExistsError")
        self.assertEqual(target.read_text(), "precious")

    def test_rollback_removes_only_objects_whose_identity_it_can_prove(self):
        namespace = self.load()
        fd = os.open(self.home, os.O_RDONLY | os.O_DIRECTORY)
        self.addCleanup(os.close, fd)
        os.mkdir("mine", 0o700, dir_fd=fd)
        (self.home / "file.txt").write_text("f")
        d = os.lstat(self.home / "mine")
        f = os.lstat(self.home / "file.txt")
        created = [("dir", fd, "mine", d.st_dev, d.st_ino), ("file", fd, "file.txt", f.st_dev, f.st_ino)]
        (self.home / "mine").rename(self.home / "moved")
        (self.home / "mine").mkdir()
        (self.home / "mine" / "keep.txt").write_text("keep")
        (self.home / "file.txt").unlink()
        (self.home / "file.txt").write_text("other")
        # the file is recreated while the old inode may be reused: make sure the identity differs
        if os.lstat(self.home / "file.txt").st_ino == f.st_ino:
            created[1] = ("file", fd, "file.txt", f.st_dev, f.st_ino + 1)
        namespace["rollback"](created)
        self.assertEqual((self.home / "mine" / "keep.txt").read_text(), "keep")
        self.assertEqual((self.home / "file.txt").read_text(), "other")

    def test_rollback_never_removes_a_non_empty_directory(self):
        namespace = self.load()
        fd = os.open(self.home, os.O_RDONLY | os.O_DIRECTORY)
        self.addCleanup(os.close, fd)
        os.mkdir("d", 0o700, dir_fd=fd)
        (self.home / "d" / "x").write_text("x")
        info = os.lstat(self.home / "d")
        namespace["rollback"]([("dir", fd, "d", info.st_dev, info.st_ino)])
        self.assertTrue((self.home / "d" / "x").exists())

    def test_reading_a_fifo_never_blocks_and_is_rejected(self):
        namespace = self.load()
        os.mkfifo(self.home / "pipe")
        fd = os.open(self.home, os.O_RDONLY | os.O_DIRECTORY)
        self.addCleanup(os.close, fd)
        signal.alarm(5)
        _, data = namespace["read_regular"](fd, "pipe")
        signal.alarm(0)
        self.assertIsNone(data)

    def test_watchdog_is_armed_and_cancelled(self):
        calls = []
        real = signal.alarm
        with mock.patch.object(signal, "alarm", lambda n: (calls.append(n), real(n))[1]):
            self.run_host(mode="check")
            self.assertEqual(calls, [300, 0])
            self.run_host()
        self.assertEqual(calls[2], 300)
        self.assertEqual(calls[-1], 0)
        self.assertLessEqual(max(calls), 300)

    def test_sigalrm_is_handled_as_a_clean_stop(self):
        before = self.snapshot()
        original = os.fsync
        count = {"n": 0}

        def alarmed(fd):
            count["n"] += 1
            if count["n"] == 3:
                os.kill(os.getpid(), signal.SIGALRM)
            return original(fd)

        with mock.patch.object(os, "fsync", alarmed):
            lines, _ = self.run_host()
        self.assertEqual(lines[-1], "STAGING_149_STAGE=STOP;CODE=terminated_by_signal_" + str(int(signal.SIGALRM)))
        self.assertEqual(self.snapshot(), before)

    def test_signals_are_blocked_while_rolling_back(self):
        namespace = self.load()
        seen = []
        real = namespace["rollback"]

        def watching(created):
            seen.append(signal.pthread_sigmask(signal.SIG_BLOCK, set()))
            return real(created)

        namespace["rollback"] = watching
        with mock.patch.object(os, "fsync", side_effect=OSError("boom")):
            self.run_host(namespace=namespace)
        self.assertEqual(len(seen), 1)
        self.assertTrue({signal.SIGTERM, signal.SIGINT, signal.SIGHUP, signal.SIGALRM} <= seen[0])

    def test_gid_zero_is_rejected(self):
        fake = types.SimpleNamespace(pw_uid=ENTRY.pw_uid, pw_gid=0, pw_dir=ENTRY.pw_dir)
        self.stop("deploy_user_identity_invalid", patches=[mock.patch.object(pwd, "getpwnam", return_value=fake)])

    def test_the_environment_is_scrubbed_before_anything_runs(self):
        namespace = self.load()
        seen = {}
        namespace["main"] = lambda: seen.update(os.environ)
        with mock.patch.dict(os.environ, {"LD_PRELOAD": "/x", "PYTHONPATH": "/y", "HTTPS_PROXY": "http://p"}):
            namespace["run"]()
            self.assertEqual(seen, {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C"})

    def test_a_closed_output_pipe_does_not_crash_the_final_line(self):
        namespace = self.load()
        with mock.patch("builtins.print", side_effect=BrokenPipeError):
            namespace["emit"]("x")
            with self.assertRaises(SystemExit):
                namespace["stop"]("code")

    def test_a_failing_readback_rolls_everything_back(self):
        before = self.snapshot()
        namespace = self.load()
        original = namespace["hashlib"].sha256

        class Fake:
            def __init__(self, data=b""):
                self._real = original(data)

            def hexdigest(self):
                return "0" * 64 if os.environ.get("HS_READBACK") else self._real.hexdigest()

        # only the read-back hashes after the files were published; downloads were verified before
        calls = {"n": 0}
        real_readback = namespace["readback"]

        def broken(priv_fd, uid, gid):
            namespace["stop"]("readback_hash:" + NAMES[0])

        namespace["readback"] = broken
        lines, status = self.run_host(namespace=namespace)
        self.assertEqual((lines[-1], status), ("STAGING_149_STAGE=STOP;CODE=readback_hash:" + NAMES[0], 2))
        self.assertEqual(self.snapshot(), before)

    def test_termination_signals_roll_back(self):
        before = self.snapshot()
        original = os.fsync
        count = {"n": 0}

        def killed(fd):
            count["n"] += 1
            if count["n"] == 4:
                os.kill(os.getpid(), signal.SIGTERM)
            return original(fd)

        with mock.patch.object(os, "fsync", killed):
            lines, _ = self.run_host()
        self.assertEqual(lines[-1], "STAGING_149_STAGE=STOP;CODE=terminated_by_signal_" + str(int(signal.SIGTERM)))
        self.assertEqual(self.snapshot(), before)

    def test_only_the_private_directory_is_ever_modified(self):
        before = self.snapshot()
        self.run_host()
        after = self.snapshot()
        changed = {p for p in set(before) | set(after) if before.get(p) != after.get(p)}
        self.assertTrue(all(str(self.priv) in p for p in changed), changed)


class StaticTests(unittest.TestCase):
    def test_no_process_shell_deletion_or_eval_surface(self):
        for forbidden in ("subprocess", "os.system", "os.popen", "shutil", "rmtree", "eval(", "exec(", "compile(",
                          "importlib", "pickle", "sudo", "os.chmod(", "os.chown(", "setuid", "__import__"):
            self.assertNotIn(forbidden, CODE, forbidden)
        self.assertIsNone(re.search(r"(?<![.\w])open\(", CODE))

    def test_downloaded_bytes_are_never_executed_only_hashed_and_written(self):
        self.assertNotIn("exec", CODE)
        self.assertNotIn("import ", CODE.split("def stop", 1)[1])  # no dynamic imports after the header

    def test_single_https_origin_and_pinned_commit(self):
        urls = set(re.findall(r"https?://[^\"' ]+", SOURCE.split('"""', 2)[2]))
        self.assertEqual(urls, {"https://raw.githubusercontent.com/zsoltacsai/wpcaregrid/"})
        self.assertIn('COMMIT = "49234694f062168a27b471fc43774409e6c4219a"', SOURCE)

    def test_pins_commit_and_paths_match_the_manifest_the_installer_and_the_workstation_driver(self):
        manifest = json.loads((ROOT / "deploy" / "release-manifests" / "staging-next-helper-1.4.9.json").read_text())
        expected = {
            "wpcaregrid-staging-next-deploy-priv-1_4_9.py": manifest["entrypoint_sha256"],
            "staging_next_deploy_backend_1_4_9.py": manifest["backend_sha256"],
            "staging_next_db_transaction_1_4_9.py": manifest["transaction_module_sha256"],
            "deployment_target_binding.py": manifest["target_binding_sha256"],
            "deployment_environment_contract.py": manifest["environment_contract_sha256"],
            INSTALLER: hashlib.sha256((ROOT / "scripts" / INSTALLER).read_bytes()).hexdigest(),
        }
        namespace = {"__name__": "hostmod"}
        exec(compile(SOURCE, "<host>", "exec"), namespace)
        self.assertEqual(namespace["PINS"], expected)
        driver = (ROOT / "scripts" / "staging-149-stage-sources.sh").read_text(encoding="utf-8")
        self.assertIn('COMMIT="' + namespace["COMMIT"] + '"', driver)
        for name, digest in expected.items():
            self.assertIn(f'"{name} {digest}"', driver)
        installer = (ROOT / "scripts" / INSTALLER).read_text(encoding="utf-8")
        self.assertIn('SOURCE_DIR = Path("/home/deploy/.private/' + namespace["SRC_NAME"] + '")', installer)
        self.assertEqual(namespace["INST_NAME"], "wpcaregrid-staging-next-helper-installer-1.4.9.py")
        preflight = (ROOT / "scripts" / "staging-149-preflight.py").read_text(encoding="utf-8")
        self.assertIn(namespace["INST_NAME"], preflight)
        self.assertIn(namespace["SRC_NAME"], preflight)

    def test_the_program_text_starts_with_the_expected_shebang_and_is_ascii(self):
        self.assertTrue(SOURCE.startswith("#!/usr/bin/python3\n"))
        SOURCE.encode("ascii")


if __name__ == "__main__":
    unittest.main()
