#!/usr/bin/env python3
"""Synthetic tests for the 2.1.4 root-console parent-directory provisioner.

Nothing here touches a real host path, sudoers, a service, a database or the
network. Every filesystem assertion runs inside a fresh temporary tree whose
owner is the current user, so the suite is meaningful as a non-root CI job.
Root-console gating itself is exercised through the pure ``enforce_root_console``
predicate, never by running the production layout.
"""

from __future__ import annotations

import ast
import importlib.util
import json
import os
import shutil
import stat
import sys
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))


def _load(name: str, relative: str):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


PROVISION = _load(
    "parent_dirs_provisioner_2_1_4_under_test",
    "scripts/provision_wpcaregrid_deploy_parent_dirs_2_1_4.py",
)
INSTALLER = _load(
    "parent_dirs_installer_2_1_4_under_test",
    "scripts/install_production_helper_2_1_4.py",
)

SCRIPT = ROOT / "scripts/provision_wpcaregrid_deploy_parent_dirs_2_1_4.py"
HASH = "a" * 64
EXPECTED_TARGETS = (
    "/var/lib/wpcaregrid-deploy",
    "/usr/local/libexec/wpcaregrid-deploy",
    "/etc/wpcaregrid-deploy",
    "/etc/wpcaregrid-deploy/profiles",
)


class SimulatedKill(BaseException):
    """Stands in for SIGKILL: no handler, no rollback, journal left unfinished."""


class Sandbox:
    """A throwaway root tree shaped like the host's relevant ancestors."""

    def __init__(self) -> None:
        self.tmp = Path(tempfile.mkdtemp(prefix="wpcg-parents-2.1.4-"))
        self.root = self.tmp / "root"
        self.root.mkdir()
        os.chmod(self.root, 0o755)
        for relative in (
            "var", "var/lib", "usr", "usr/local", "usr/local/libexec", "etc",
        ):
            path = self.root / relative
            path.mkdir(exist_ok=True)
            os.chmod(path, 0o755)
        self.state = self.tmp / "state"
        self.state.mkdir()
        os.chmod(self.state, 0o700)
        self.uid = os.getuid()
        self.gid = os.getgid()
        self.layout = PROVISION.Layout(
            root=self.root, owner_uid=self.uid, owner_gid=self.gid)

    def cleanup(self) -> None:
        shutil.rmtree(self.tmp, ignore_errors=True)

    def physical(self, target: str) -> Path:
        return self.root / target.lstrip("/")

    def target(self, index: int) -> str:
        return PROVISION.TARGETS[index]

    def provision(self, *, mode: str = "apply", run_id: str | None = None,
                  checkpoint=None, state_dir: Path | None = None):
        return PROVISION.provision(
            layout=self.layout,
            state_dir=state_dir or self.state,
            mode=mode,
            script_sha256=HASH,
            run_id=run_id,
            checkpoint=checkpoint,
        )

    def recover(self, *, run_id: str | None = None, checkpoint=None):
        return PROVISION.recover(
            layout=self.layout,
            state_dir=self.state,
            script_sha256=HASH,
            run_id=run_id,
            checkpoint=checkpoint,
        )

    def names(self) -> list[str]:
        return sorted(path.name for path in self.state.iterdir())

    def receipts(self, prefix: str) -> list[Path]:
        return sorted(self.state.glob(f"{prefix}-*.json"))

    def read_json(self, name: str) -> dict:
        return json.loads((self.state / name).read_text())


def _snapshot(root: Path) -> dict[str, tuple]:
    """Physical identity of everything below ``root`` (contents included)."""
    snapshot: dict[str, tuple] = {}
    for dirpath, dirnames, filenames in os.walk(root):
        for name in dirnames + filenames:
            path = Path(dirpath) / name
            info = os.lstat(path)
            snapshot[str(path.relative_to(root))] = (
                info.st_ino,
                info.st_dev,
                stat.S_IMODE(info.st_mode),
                info.st_uid,
                info.st_gid,
                info.st_size,
                info.st_mtime_ns,
            )
    return snapshot


class SandboxCase(unittest.TestCase):
    def setUp(self) -> None:
        self.sandbox = Sandbox()
        self.addCleanup(self.sandbox.cleanup)

    def assertOutcome(self, outcome, status: str, code: str) -> None:
        self.assertEqual((outcome.status, outcome.code), (status, code))

    def assertTargetStatus(self, target: str, expected: str) -> None:
        path = self.sandbox.physical(target)
        self.assertEqual(path.exists() or path.is_symlink(), expected == "present",
                         msg=f"{target} presence mismatch")

    def assertExactDirectory(self, target: str, mode: int = 0o755) -> None:
        path = self.sandbox.physical(target)
        info = os.lstat(path)
        self.assertTrue(stat.S_ISDIR(info.st_mode), msg=f"{target} is not a directory")
        self.assertEqual(stat.S_IMODE(info.st_mode), mode, msg=f"{target} mode")
        self.assertEqual((info.st_uid, info.st_gid),
                         (self.sandbox.uid, self.sandbox.gid), msg=f"{target} owner")

    def assertProvisionError(self, code: str, callable_) -> None:
        with self.assertRaises(PROVISION.ProvisionError) as caught:
            callable_()
        self.assertEqual(caught.exception.code, code)


class ContractTests(unittest.TestCase):
    """The provisioner must track the confirmed installer, not a guess."""

    def test_targets_match_installer_required_dirs(self) -> None:
        installer_dirs = {str(path) for path in INSTALLER.PRODUCTION.required_dirs}
        self.assertEqual(set(PROVISION.TARGETS), installer_dirs)
        self.assertEqual(len(PROVISION.TARGETS), 4)

    def test_targets_are_ordered_parentally(self) -> None:
        ordered = list(PROVISION.TARGETS)
        positions = {path: index for index, path in enumerate(ordered)}
        for target in ordered:
            parent = str(Path(target).parent)
            if parent in positions:
                self.assertLess(positions[parent], positions[target])

    def test_target_set_is_exactly_the_four_paths(self) -> None:
        self.assertEqual(PROVISION.TARGETS, EXPECTED_TARGETS)

    def test_staging_and_unrelated_paths_are_out_of_scope(self) -> None:
        source = SCRIPT.read_text()
        joined = "\n".join(PROVISION.TARGETS)
        for forbidden in (
            "/var/lib/wpcaregrid-deploy/staging-next",
            "/etc/sudoers",
            "/etc/nginx",
            "/usr/local/sbin",
        ):
            self.assertNotIn(forbidden, joined)
        self.assertNotIn("staging-next", source)
        self.assertNotIn("systemctl", source)
        self.assertNotIn("mysql", source)

    def test_no_privileged_mutation_primitives(self) -> None:
        tree = ast.parse(SCRIPT.read_text())
        forbidden_modules = {
            "subprocess", "shutil", "socket", "urllib", "http", "ctypes",
            "pwd", "grp", "pty", "sqlite3", "ssl",
        }
        imported: set[str] = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                imported.update(alias.name.split(".")[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imported.add(node.module.split(".")[0])
        self.assertEqual(imported & forbidden_modules, set())
        forbidden_calls = {
            "chmod", "lchmod", "fchmod", "chown", "lchown", "fchown",
            "system", "popen", "spawnv", "execv", "setuid", "seteuid",
        }
        for node in ast.walk(tree):
            if (isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute)
                    and node.func.attr in forbidden_calls):
                self.fail(f"unexpected privileged call: {ast.dump(node.func)}")
        text = SCRIPT.read_text()
        self.assertIn("O_NOFOLLOW", text)
        self.assertIn("dir_fd=", text)


class RootConsoleTests(unittest.TestCase):
    def test_real_root_console_accepted(self) -> None:
        PROVISION.enforce_root_console(0, 0, 0, 0, {})

    def test_non_root_refused(self) -> None:
        with self.assertRaises(PROVISION.ProvisionError) as caught:
            PROVISION.enforce_root_console(1000, 0, 1000, 0, {})
        self.assertEqual(caught.exception.code, "ROOT_REQUIRED")

    def test_effective_root_only_refused(self) -> None:
        with self.assertRaises(PROVISION.ProvisionError) as caught:
            PROVISION.enforce_root_console(1000, 0, 1000, 0, {})
        self.assertEqual(caught.exception.code, "ROOT_REQUIRED")

    def test_delegated_invocation_refused(self) -> None:
        for variable in ("SUDO_UID", "SUDO_USER", "PKEXEC_UID", "DOAS_USER"):
            with self.assertRaises(PROVISION.ProvisionError) as caught:
                PROVISION.enforce_root_console(0, 0, 0, 0, {variable: "1000"})
            self.assertEqual(caught.exception.code, "DELEGATED_INVOCATION_REFUSED")

    def test_injected_interpreter_refused(self) -> None:
        for variable in ("PYTHONPATH", "PYTHONHOME", "LD_PRELOAD", "LD_AUDIT"):
            with self.assertRaises(PROVISION.ProvisionError) as caught:
                PROVISION.enforce_root_console(0, 0, 0, 0, {variable: "/tmp"})
            self.assertEqual(caught.exception.code, "UNSAFE_ENVIRONMENT_REFUSED")


class PlanTests(SandboxCase):
    def test_plan_reports_four_absent_targets_and_mutates_nothing(self) -> None:
        before = _snapshot(self.sandbox.root)
        outcome = self.sandbox.provision(mode="plan", run_id="a" * 32)
        self.assertOutcome(outcome, "PLAN", "preflight_ok")
        self.assertEqual(_snapshot(self.sandbox.root), before)
        self.assertEqual(outcome.receipt, f"plan-{'a' * 32}.json")
        self.assertEqual(self.sandbox.receipts("plan"), [self.sandbox.state / outcome.receipt])
        receipt = self.sandbox.read_json(outcome.receipt)
        self.assertEqual(receipt["outcome"], "PLAN")
        self.assertEqual(receipt["version"], "2.1.4")
        self.assertEqual(receipt["script_sha256"], HASH)
        self.assertEqual(
            [item["path"] for item in receipt["targets"]], list(EXPECTED_TARGETS))
        self.assertEqual({item["state"] for item in receipt["targets"]}, {"absent"})
        self.assertEqual(self.sandbox.receipts("receipt"), [])

    def test_plan_accepts_already_complete_layout(self) -> None:
        self.assertOutcome(self.sandbox.provision(), "PASS", "provisioned")
        outcome = self.sandbox.provision(mode="plan")
        self.assertOutcome(outcome, "PLAN", "preflight_ok")
        self.assertEqual({item["state"] for item in
                          self.sandbox.read_json(outcome.receipt)["targets"]},
                         {"existing"})


class ApplyTests(SandboxCase):
    def test_apply_creates_only_the_four_directories(self) -> None:
        outcome = self.sandbox.provision(run_id="b" * 32)
        self.assertOutcome(outcome, "PASS", "provisioned")
        self.assertEqual(outcome.created, EXPECTED_TARGETS)
        self.assertEqual(outcome.existing, ())
        for target in EXPECTED_TARGETS:
            self.assertExactDirectory(target)
        self.assertEqual(outcome.journal, f"journal-{'b' * 32}.jsonl")
        self.assertEqual(outcome.receipt, f"receipt-{'b' * 32}.json")
        journal = (self.sandbox.state / outcome.journal).read_text().splitlines()
        self.assertEqual([json.loads(line)["record"] for line in journal],
                         ["prepared", "created", "created", "created", "created", "complete"])
        self.assertEqual([json.loads(line)["seq"] for line in journal],
                         [1, 2, 3, 4, 5, 6])
        receipt = self.sandbox.read_json(outcome.receipt)
        self.assertEqual(receipt["outcome"], "PASS")
        self.assertEqual(receipt["code"], "provisioned")
        self.assertEqual(receipt["journal"], outcome.journal)

    def test_apply_is_idempotent(self) -> None:
        self.assertOutcome(self.sandbox.provision(run_id="c" * 32), "PASS", "provisioned")
        identities = {target: os.lstat(self.sandbox.physical(target)).st_ino
                      for target in EXPECTED_TARGETS}
        outcome = self.sandbox.provision(run_id="d" * 32)
        self.assertOutcome(outcome, "NO_OP", "already_complete")
        self.assertIsNone(outcome.journal)
        self.assertEqual(outcome.created, ())
        for target in EXPECTED_TARGETS:
            self.assertEqual(os.lstat(self.sandbox.physical(target)).st_ino,
                             identities[target])
        receipt = self.sandbox.read_json(outcome.receipt)
        self.assertEqual(receipt["outcome"], "NO_OP")

    def test_apply_forces_exact_mode_under_permissive_umask(self) -> None:
        previous = os.umask(0o000)
        try:
            self.assertOutcome(self.sandbox.provision(), "PASS", "provisioned")
        finally:
            os.umask(previous)
        for target in EXPECTED_TARGETS:
            self.assertExactDirectory(target)

    def test_apply_skips_existing_targets_and_keeps_content_opaque(self) -> None:
        existing = self.sandbox.physical(PROVISION.TARGETS[0])
        existing.mkdir()
        os.chmod(existing, 0o755)
        payload = existing / "staging-next"
        payload.mkdir()
        marker = payload / "release.tar"
        marker.write_bytes(b"protected production-adjacent content")
        before = os.lstat(payload)

        outcome = self.sandbox.provision(run_id="e" * 32)
        self.assertOutcome(outcome, "PASS", "provisioned")
        self.assertEqual(outcome.created, EXPECTED_TARGETS[1:])
        self.assertEqual(outcome.existing, (PROVISION.TARGETS[0],))
        self.assertEqual(marker.read_bytes(),
                         b"protected production-adjacent content")
        after = os.lstat(payload)
        self.assertEqual((before.st_ino, before.st_mode, before.st_mtime_ns),
                         (after.st_ino, after.st_mode, after.st_mtime_ns))

    def test_apply_can_create_profiles_under_existing_parent(self) -> None:
        parent = self.sandbox.physical("/etc/wpcaregrid-deploy")
        parent.mkdir()
        os.chmod(parent, 0o755)
        outcome = self.sandbox.provision()
        self.assertOutcome(outcome, "PASS", "provisioned")
        self.assertEqual(outcome.created,
                         ("/var/lib/wpcaregrid-deploy",
                          "/usr/local/libexec/wpcaregrid-deploy",
                          "/etc/wpcaregrid-deploy/profiles"))
        self.assertExactDirectory("/etc/wpcaregrid-deploy/profiles")


class RefusalTests(SandboxCase):
    def test_wrong_existing_mode_refused_without_mutation(self) -> None:
        target = self.sandbox.physical("/etc/wpcaregrid-deploy")
        target.mkdir()
        os.chmod(target, 0o700)
        before = _snapshot(self.sandbox.root)
        outcome = self.sandbox.provision()
        self.assertOutcome(outcome, "HOLD", "DIRECTORY_MODE_INVALID")
        self.assertEqual(_snapshot(self.sandbox.root), before)

    def test_symlinked_target_refused(self) -> None:
        elsewhere = self.sandbox.root / "elsewhere"
        elsewhere.mkdir()
        os.chmod(elsewhere, 0o755)
        link = self.sandbox.physical("/etc/wpcaregrid-deploy")
        link.symlink_to(elsewhere, target_is_directory=True)
        outcome = self.sandbox.provision()
        self.assertOutcome(outcome, "HOLD", "SYMLINK_REFUSED")
        self.assertTrue(link.is_symlink())

    def test_non_directory_target_refused(self) -> None:
        target = self.sandbox.physical("/etc/wpcaregrid-deploy")
        target.write_text("not a directory\n")
        os.chmod(target, 0o644)
        outcome = self.sandbox.provision()
        self.assertOutcome(outcome, "HOLD", "DIRECTORY_UNSAFE")

    def test_missing_base_parent_refused(self) -> None:
        (self.sandbox.root / "usr/local/libexec").rmdir()
        before = _snapshot(self.sandbox.root)
        outcome = self.sandbox.provision()
        self.assertOutcome(outcome, "HOLD", "PARENT_MISSING")
        self.assertEqual(_snapshot(self.sandbox.root), before)

    def test_symlinked_parent_refused(self) -> None:
        (self.sandbox.root / "usr/local/libexec").rmdir()
        target = self.sandbox.root / "outside"
        target.mkdir()
        os.chmod(target, 0o755)
        (self.sandbox.root / "usr/local/libexec").symlink_to(target)
        outcome = self.sandbox.provision()
        self.assertOutcome(outcome, "HOLD", "SYMLINK_REFUSED")

    def test_wrong_parent_mode_refused(self) -> None:
        os.chmod(self.sandbox.root / "usr/local/libexec", 0o775)
        outcome = self.sandbox.provision()
        self.assertOutcome(outcome, "HOLD", "DIRECTORY_MODE_INVALID")

    def test_wrong_owner_refused(self) -> None:
        layout = replace(self.sandbox.layout, owner_uid=self.sandbox.uid + 1)
        fd = os.open(str(self.sandbox.root / "etc"), os.O_RDONLY | os.O_DIRECTORY)
        try:
            with self.assertRaises(PROVISION.ProvisionError) as caught:
                PROVISION._verify_fd(fd, "/etc", layout, exact_mode=0o755)
            self.assertEqual(caught.exception.code, "DIRECTORY_OWNER_INVALID")
        finally:
            os.close(fd)

    def test_state_dir_must_preexist(self) -> None:
        missing = self.sandbox.tmp / "absent-state"
        self.assertProvisionError(
            "STATE_DIRECTORY_UNAVAILABLE",
            lambda: self.sandbox.provision(state_dir=missing))

    def test_state_dir_mode_must_be_0700(self) -> None:
        os.chmod(self.sandbox.state, 0o755)
        self.assertProvisionError(
            "DIRECTORY_MODE_INVALID", lambda: self.sandbox.provision())

    def test_state_dir_symlink_refused(self) -> None:
        link = self.sandbox.tmp / "state-link"
        link.symlink_to(self.sandbox.state, target_is_directory=True)
        self.assertProvisionError(
            "STATE_DIRECTORY_UNSAFE",
            lambda: self.sandbox.provision(state_dir=link))

    def test_state_dir_inside_target_refused(self) -> None:
        target = self.sandbox.physical("/etc/wpcaregrid-deploy")
        target.mkdir()
        os.chmod(target, 0o755)
        nested = target / "state"
        nested.mkdir()
        os.chmod(nested, 0o700)
        self.assertProvisionError(
            "STATE_DIRECTORY_OVERLAPS_TARGET",
            lambda: self.sandbox.provision(state_dir=nested))


class AtomicPublicationTests(SandboxCase):
    def _state_fd(self) -> int:
        fd, _ = PROVISION._open_state_dir(self.sandbox.layout, self.sandbox.state)
        return fd

    def test_receipt_publication_never_overwrites(self) -> None:
        fd = self._state_fd()
        try:
            name = f"receipt-{'f' * 32}.json"
            PROVISION._publish_atomic(fd, name, b"first\n", self.sandbox.layout)
            original = (self.sandbox.state / name).read_bytes()
            with self.assertRaises(PROVISION.ProvisionError) as caught:
                PROVISION._publish_atomic(fd, name, b"second\n", self.sandbox.layout)
            self.assertEqual(caught.exception.code, "RECEIPT_EXISTS")
            self.assertEqual((self.sandbox.state / name).read_bytes(), original)
            self.assertEqual([path.name for path in self.sandbox.state.iterdir()],
                             [name])
        finally:
            os.close(fd)

    def test_journal_creation_never_overwrites(self) -> None:
        fd = self._state_fd()
        try:
            name = f"journal-{'1' * 32}.jsonl"
            first = PROVISION.Journal(fd, name, self.sandbox.layout)
            first.append({"record": "prepared", "run_id": "1" * 32,
                          "created_ns": 1, "script_sha256": HASH, "targets": []})
            first.close()
            original = (self.sandbox.state / name).read_bytes()
            with self.assertRaises(PROVISION.ProvisionError) as caught:
                PROVISION.Journal(fd, name, self.sandbox.layout)
            self.assertEqual(caught.exception.code, "JOURNAL_EXISTS")
            self.assertEqual((self.sandbox.state / name).read_bytes(), original)
        finally:
            os.close(fd)

    def test_receipt_reuse_of_run_id_refused(self) -> None:
        self.assertOutcome(self.sandbox.provision(run_id="9" * 32), "PASS", "provisioned")
        original = (self.sandbox.state / f"receipt-{'9' * 32}.json").read_bytes()
        outcome = self.sandbox.provision(run_id="9" * 32)
        self.assertOutcome(outcome, "HOLD", "RECEIPT_EXISTS")
        self.assertEqual(
            (self.sandbox.state / f"receipt-{'9' * 32}.json").read_bytes(), original)

    def test_tampered_journal_chain_refused(self) -> None:
        outcome = self.sandbox.provision(run_id="2" * 32)
        self.assertOutcome(outcome, "PASS", "provisioned")
        journal = self.sandbox.state / outcome.journal
        os.chmod(journal, 0o600)
        forged = {"schema": 1, "version": "2.1.4", "seq": 2,
                  "prev_sha256": "0" * 64, "record": "created",
                  "path": PROVISION.TARGETS[0]}
        with journal.open("ab") as handle:
            handle.write((json.dumps(forged) + "\n").encode())
        refused = self.sandbox.provision()
        self.assertOutcome(refused, "HOLD", "JOURNAL_CHAIN_INVALID")
        for target in EXPECTED_TARGETS:
            self.assertTargetStatus(target, "present")


class RaceTests(SandboxCase):
    def test_parent_inode_replacement_detected_and_rolled_back(self) -> None:
        def checkpoint(label: str) -> None:
            if label == "before_mkdir:/etc/wpcaregrid-deploy":
                etc = self.sandbox.root / "etc"
                os.rename(etc, self.sandbox.root / "etc.saved")
                etc.mkdir()
                os.chmod(etc, 0o755)

        outcome = self.sandbox.provision(checkpoint=checkpoint)
        self.assertOutcome(outcome, "ROLLBACK", "PARENT_IDENTITY_CHANGED")
        self.assertEqual(outcome.removed,
                         ("/usr/local/libexec/wpcaregrid-deploy",
                          "/var/lib/wpcaregrid-deploy"))
        self.assertTargetStatus("/var/lib/wpcaregrid-deploy", "absent")
        self.assertTargetStatus("/usr/local/libexec/wpcaregrid-deploy", "absent")
        self.assertTargetStatus("/etc/wpcaregrid-deploy", "absent")

    def test_created_target_inode_replacement_holds(self) -> None:
        def checkpoint(label: str) -> None:
            if label == f"created_pending:{PROVISION.TARGETS[0]}":
                path = self.sandbox.physical(PROVISION.TARGETS[0])
                os.rename(path, path.with_name(path.name + ".original"))
                path.mkdir()
                os.chmod(path, 0o755)

        outcome = self.sandbox.provision(checkpoint=checkpoint)
        self.assertOutcome(outcome, "HOLD", "ROLLBACK_IDENTITY_MISMATCH")
        path = self.sandbox.physical(PROVISION.TARGETS[0])
        self.assertTrue(path.is_dir())
        self.assertTrue(path.with_name(path.name + ".original").is_dir())


    def test_unexpected_target_created_mid_run_holds(self) -> None:
        planted = self.sandbox.physical("/etc/wpcaregrid-deploy")

        def checkpoint(label: str) -> None:
            if label == "before_mkdir:/etc/wpcaregrid-deploy":
                planted.mkdir()
                os.chmod(planted, 0o755)

        outcome = self.sandbox.provision(checkpoint=checkpoint)
        self.assertOutcome(outcome, "HOLD", "UNEXPLAINED_STATE")
        self.assertTrue(planted.is_dir())
        self.assertTargetStatus("/var/lib/wpcaregrid-deploy", "absent")
        self.assertTargetStatus("/usr/local/libexec/wpcaregrid-deploy", "absent")
        journal = (self.sandbox.state / outcome.journal).read_text().splitlines()
        self.assertEqual(json.loads(journal[-1])["record"], "hold")


class InterruptionTests(SandboxCase):
    def test_keyboard_interrupt_rolls_back_this_run_only(self) -> None:
        def checkpoint(label: str) -> None:
            if label == f"before_mkdir:{PROVISION.TARGETS[2]}":
                raise KeyboardInterrupt

        outcome = self.sandbox.provision(run_id="3" * 32, checkpoint=checkpoint)
        self.assertOutcome(outcome, "ROLLBACK", "interrupted")
        self.assertEqual(outcome.removed,
                         ("/usr/local/libexec/wpcaregrid-deploy",
                          "/var/lib/wpcaregrid-deploy"))
        for target in EXPECTED_TARGETS:
            self.assertTargetStatus(target, "absent")
        journal = (self.sandbox.state / outcome.journal).read_text().splitlines()
        self.assertEqual(json.loads(journal[-1])["record"], "rolled_back")
        receipt = self.sandbox.read_json(outcome.receipt)
        self.assertEqual(receipt["outcome"], "ROLLBACK")
        self.assertEqual(receipt["code"], "interrupted")

    def test_interrupt_before_first_mkdir_rolls_back_empty_run(self) -> None:
        def checkpoint(label: str) -> None:
            if label == f"before_mkdir:{PROVISION.TARGETS[0]}":
                raise KeyboardInterrupt

        outcome = self.sandbox.provision(run_id="7" * 32, checkpoint=checkpoint)
        self.assertOutcome(outcome, "ROLLBACK", "interrupted")
        self.assertEqual(outcome.created, ())
        self.assertEqual(outcome.removed, ())
        for target in EXPECTED_TARGETS:
            self.assertTargetStatus(target, "absent")
        journal = (self.sandbox.state / outcome.journal).read_text().splitlines()
        self.assertEqual([json.loads(line)["record"] for line in journal],
                         ["prepared", "rolled_back"])

    def test_pre_journal_interrupt_records_hold_without_journal(self) -> None:
        previous = PROVISION._INTERRUPTED
        PROVISION._INTERRUPTED = True
        try:
            outcome = self.sandbox.provision()
        finally:
            PROVISION._INTERRUPTED = previous
        self.assertOutcome(outcome, "HOLD", "interrupted")
        self.assertIsNone(outcome.journal)
        self.assertEqual(list(self.sandbox.state.glob("journal-*")), [])
        for target in EXPECTED_TARGETS:
            self.assertTargetStatus(target, "absent")

    def test_simulated_sigkill_requires_identity_bound_recovery(self) -> None:
        def checkpoint(label: str) -> None:
            if label == f"after_create:{PROVISION.TARGETS[0]}":
                raise SimulatedKill

        with self.assertRaises(SimulatedKill):
            self.sandbox.provision(run_id="4" * 32, checkpoint=checkpoint)
        self.assertTargetStatus("/var/lib/wpcaregrid-deploy", "present")
        journal = self.sandbox.state / f"journal-{'4' * 32}.jsonl"
        self.assertTrue(journal.is_file())
        self.assertNotIn("complete", journal.read_text())

        refused = self.sandbox.provision()
        self.assertOutcome(refused, "HOLD", "RECOVERY_REQUIRED")
        self.assertTargetStatus("/var/lib/wpcaregrid-deploy", "present")

        recovered = self.sandbox.recover(run_id="5" * 32)
        self.assertOutcome(recovered, "ROLLBACK", "recovery_complete")
        self.assertEqual(recovered.removed, ("/var/lib/wpcaregrid-deploy",))
        self.assertTargetStatus("/var/lib/wpcaregrid-deploy", "absent")
        self.assertIn("rolled_back", journal.read_text())
        receipt = self.sandbox.read_json(recovered.receipt)
        self.assertEqual(receipt["outcome"], "ROLLBACK")
        self.assertEqual(receipt["code"], "recovery_complete")

        plan = self.sandbox.provision(mode="plan")
        self.assertOutcome(plan, "PLAN", "preflight_ok")

    def test_recovery_refuses_unexplained_replacement(self) -> None:
        def checkpoint(label: str) -> None:
            if label == f"after_create:{PROVISION.TARGETS[0]}":
                raise SimulatedKill

        with self.assertRaises(SimulatedKill):
            self.sandbox.provision(checkpoint=checkpoint)
        path = self.sandbox.physical(PROVISION.TARGETS[0])
        os.rename(path, path.with_name(path.name + ".original"))
        path.mkdir()
        os.chmod(path, 0o755)

        outcome = self.sandbox.recover()
        self.assertOutcome(outcome, "HOLD", "UNEXPLAINED_STATE")
        self.assertTrue(path.is_dir())
        self.assertTrue(path.with_name(path.name + ".original").is_dir())
        self.assertTargetStatus("/usr/local/libexec/wpcaregrid-deploy", "absent")

    def test_recovery_without_unfinished_journal_is_noop(self) -> None:
        outcome = self.sandbox.recover(run_id="6" * 32)
        self.assertOutcome(outcome, "NO_OP", "no_unfinished_journal")
        self.assertEqual(outcome.receipt, f"recovery-{'6' * 32}.json")

    def test_lock_prevents_concurrent_provision(self) -> None:
        state_fd, _ = PROVISION._open_state_dir(self.sandbox.layout, self.sandbox.state)
        lock_fd = -1
        try:
            lock_fd = PROVISION._lock_state(state_fd, self.sandbox.layout)
            outcome = self.sandbox.provision()
            self.assertOutcome(outcome, "HOLD", "PROVISION_BUSY")
        finally:
            if lock_fd >= 0:
                os.close(lock_fd)
            os.close(state_fd)


class ScriptVerificationTests(SandboxCase):
    def _write_script(self, mode: int = 0o700) -> tuple[Path, str]:
        path = self.sandbox.state / "provision-copy.py"
        path.write_bytes(b"#!/usr/bin/python3\nprint('verified')\n")
        os.chmod(path, mode)
        digest = PROVISION._sha(path.read_bytes())
        return path, digest

    def test_matching_hash_accepted(self) -> None:
        path, digest = self._write_script()
        self.assertEqual(
            PROVISION.verify_script(path, digest, layout=self.sandbox.layout), digest)

    def test_wrong_hash_refused(self) -> None:
        path, _ = self._write_script()
        self.assertProvisionError(
            "SCRIPT_HASH_MISMATCH",
            lambda: PROVISION.verify_script(path, "b" * 64, layout=self.sandbox.layout))

    def test_wrong_file_mode_refused(self) -> None:
        path, digest = self._write_script(mode=0o644)
        self.assertProvisionError(
            "SCRIPT_LOCATION_UNTRUSTED",
            lambda: PROVISION.verify_script(path, digest, layout=self.sandbox.layout))

    def test_symlinked_script_refused(self) -> None:
        path, digest = self._write_script()
        alias = self.sandbox.state / "alias.py"
        alias.symlink_to(path)
        self.assertProvisionError(
            "SCRIPT_LOCATION_UNTRUSTED",
            lambda: PROVISION.verify_script(alias, digest, layout=self.sandbox.layout))

    def test_world_readable_parent_refused(self) -> None:
        path = self.sandbox.root / "copy.py"
        path.write_bytes(b"#!/usr/bin/python3\n")
        os.chmod(path, 0o700)
        digest = PROVISION._sha(path.read_bytes())
        self.assertProvisionError(
            "SCRIPT_LOCATION_UNTRUSTED",
            lambda: PROVISION.verify_script(path, digest, layout=self.sandbox.layout))


class CommandLineTests(unittest.TestCase):
    def test_version_exits_zero(self) -> None:
        self.assertEqual(PROVISION.main(["--version"]), 0)

    def test_missing_expected_hash_is_refused(self) -> None:
        self.assertEqual(PROVISION.main([]), 1)


if __name__ == "__main__":
    unittest.main(verbosity=2)
