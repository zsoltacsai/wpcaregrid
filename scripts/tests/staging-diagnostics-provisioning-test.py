#!/usr/bin/env python3
"""Synthetic tests for staging diagnostics provisioning and recovery."""

from __future__ import annotations

import base64
import importlib.util
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


ROOT = Path(__file__).parents[2]
PROVISIONING = ROOT / "scripts" / "staging-diagnostics" / "provisioning"
sys.path.insert(0, str(PROVISIONING))

import provision  # noqa: E402
import rollback  # noqa: E402
import verify  # noqa: E402


def synthetic_ed25519_public_key() -> str:
    key_name = b"ssh-ed25519"
    key_bytes = bytes(range(32))
    blob = (
        len(key_name).to_bytes(4, "big")
        + key_name
        + len(key_bytes).to_bytes(4, "big")
        + key_bytes
    )
    return f"ssh-ed25519 {base64.b64encode(blob).decode()} synthetic-test-key"


class DiagnosticsProvisioningTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="caregrid-provision-test-")
        self.temp_root = Path(self.temp.name)
        self.layout = provision.Layout(
            root=self.temp_root,
            owner_uid=os.geteuid(),
            owner_gid=os.getegid(),
        )
        for directory in (
            "/usr/local/libexec",
            "/usr/local/sbin",
            "/etc/sudoers.d",
            "/var/lib",
            "/run",
            "/run/lock",
        ):
            path = self.layout.path(directory)
            path.mkdir(parents=True, exist_ok=True)
            path.chmod(0o755)
        self.source_sha = "a" * 40
        self.public_key = synthetic_ed25519_public_key()
        self.runner_calls: list[list[str]] = []

    def tearDown(self) -> None:
        self.temp.cleanup()

    def fake_runner(self, command: list[str]) -> str:
        self.runner_calls.append(command)
        if command[:3] == ["/usr/sbin/visudo", "-cf", "INVALID"]:
            raise provision.ProvisionError("SUDOERS_INVALID")
        return ""

    def run_install(self, runner=None) -> None:
        with patch.object(
            provision,
            "verify_source_provenance",
            return_value=provision.source_hashes(ROOT),
        ):
            provision.install(
                layout=self.layout,
                source_root=ROOT,
                approved_sha=self.source_sha,
                public_key=self.public_key,
                runner=runner or self.fake_runner,
            )

    def test_first_install_creates_only_fixed_managed_files(self) -> None:
        self.run_install()
        key_path = self.layout.path(
            f"{provision.DIAGNOSTICS_HOME}/.ssh/authorized_keys"
        )
        self.assertEqual(
            key_path.read_text(encoding="ascii"),
            provision.authorized_key_line(self.public_key) + "\n",
        )
        self.assertFalse(self.layout.path(provision.PENDING_PATH).exists())
        self.assertEqual(
            self.layout.path(provision.SUDOERS_PATH).read_text(encoding="ascii"),
            provision.SUDOERS_CONTENT,
        )

    def test_repeated_install_is_idempotent(self) -> None:
        self.run_install()
        before = {
            path: (self.layout.path(path).read_bytes(), self.layout.path(path).stat().st_mode)
            for path in provision.SOURCE_FILES
        }
        self.run_install()
        after = {
            path: (self.layout.path(path).read_bytes(), self.layout.path(path).stat().st_mode)
            for path in provision.SOURCE_FILES
        }
        self.assertEqual(before, after)
        self.assertFalse(self.layout.path(provision.PENDING_PATH).exists())

    def test_conflicting_account_fields_are_rejected(self) -> None:
        with self.assertRaisesRegex(provision.ProvisionError, "ACCOUNT_CONFLICT"):
            provision.validate_account_fields(
                group_gid=510,
                account_gid=510,
                home="/home/deploy",
                shell="/bin/bash",
                supplementary_groups={510},
                password_locked=True,
            )
        with self.assertRaisesRegex(provision.ProvisionError, "ACCOUNT_GROUPS_UNSAFE"):
            provision.validate_account_fields(
                group_gid=510,
                account_gid=510,
                home=provision.DIAGNOSTICS_HOME,
                shell=provision.SHELL_PATH,
                supplementary_groups={510, 27},
                password_locked=True,
            )

    def test_unsafe_target_directory_permissions_are_rejected(self) -> None:
        directory = self.layout.path("/etc/sudoers.d")
        directory.chmod(0o775)
        with self.assertRaisesRegex(
            provision.ProvisionError, "DIRECTORY_WRITABLE_BY_UNTRUSTED_GROUP"
        ):
            provision.validate_directory(directory, owner=os.geteuid())

    def test_invalid_public_key_types_and_payloads_are_rejected(self) -> None:
        with self.assertRaisesRegex(provision.ProvisionError, "PUBLIC_KEY_INVALID"):
            provision.parse_public_key("ssh-rsa AAAAB3NzaC1yc2E= test")
        with self.assertRaisesRegex(provision.ProvisionError, "PUBLIC_KEY_INVALID"):
            provision.parse_public_key("ssh-ed25519 not-base64 test")
        with self.assertRaisesRegex(provision.ProvisionError, "PUBLIC_KEY_INVALID"):
            provision.parse_public_key(self.public_key + "\nssh-ed25519 extra")

    def test_public_key_file_is_opened_without_following_symlinks(self) -> None:
        key_file = self.temp_root / "diagnostics.pub"
        key_file.write_text(self.public_key, encoding="ascii")
        key_file.chmod(0o600)
        self.assertEqual(
            provision._validate_key_file(key_file),
            " ".join(self.public_key.split()[:2]),
        )

        link = self.temp_root / "key-link.pub"
        link.symlink_to(key_file)
        with self.assertRaises(provision.ProvisionError):
            provision._validate_key_file(link)

        key_file.chmod(0o622)
        with self.assertRaises(provision.ProvisionError):
            provision._validate_key_file(key_file)

    def test_modified_source_tree_fails_provenance_validation(self) -> None:
        with tempfile.TemporaryDirectory(prefix="caregrid-git-fixture-") as folder:
            repository = Path(folder)
            for relative in provision.SOURCE_FILES.values():
                target = repository / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(ROOT / relative, target)
            subprocess.run(["git", "init", "-q", str(repository)], check=True)
            subprocess.run(
                ["git", "-C", str(repository), "config", "user.name", "Synthetic Test"],
                check=True,
            )
            subprocess.run(
                ["git", "-C", str(repository), "config", "user.email", "test@example.invalid"],
                check=True,
            )
            subprocess.run(["git", "-C", str(repository), "add", "."], check=True)
            subprocess.run(
                ["git", "-C", str(repository), "commit", "-qm", "synthetic source"],
                check=True,
            )
            sha = subprocess.run(
                ["git", "-C", str(repository), "rev-parse", "HEAD"],
                check=True,
                text=True,
                stdout=subprocess.PIPE,
            ).stdout.strip()
            provision.verify_source_provenance(repository, sha)
            (repository / provision.SOURCE_FILES[provision.DISPATCH_PATH]).write_text(
                "modified\n", encoding="ascii"
            )
            with self.assertRaisesRegex(provision.ProvisionError, "SOURCE_TREE_DIRTY"):
                provision.verify_source_provenance(repository, sha)

    def test_invalid_sudoers_leaves_journal_and_next_install_fails_closed(self) -> None:
        def invalid_sudoers_runner(command: list[str]) -> str:
            self.runner_calls.append(command)
            if command[:2] == ["/usr/sbin/visudo", "-cf"]:
                raise provision.ProvisionError("SUDOERS_INVALID")
            return ""

        with self.assertRaisesRegex(provision.ProvisionError, "SUDOERS_INVALID"):
            self.run_install(invalid_sudoers_runner)
        self.assertTrue(self.layout.path(provision.PENDING_PATH).is_file())
        with self.assertRaisesRegex(provision.ProvisionError, "RECOVERY_REQUIRED"):
            self.run_install()

    def test_recovery_removes_only_exact_key_sudoers_and_pending_marker(self) -> None:
        self.run_install()
        marker = self.layout.path(provision.PENDING_PATH)
        marker.parent.mkdir(parents=True, exist_ok=True)
        provision.atomic_write(
            marker,
            f"source={self.source_sha}\n".encode(),
            mode=0o600,
            uid=os.geteuid(),
            gid=os.getegid(),
        )
        with patch.object(
            rollback,
            "verify_source_provenance",
            return_value=provision.source_hashes(ROOT),
        ):
            rollback.rollback_filesystem(
                self.layout, self.source_sha, self.public_key
            )
        self.assertFalse(
            self.layout.path(
                f"{provision.DIAGNOSTICS_HOME}/.ssh/authorized_keys"
            ).exists()
        )
        self.assertFalse(self.layout.path(provision.SUDOERS_PATH).exists())
        self.assertFalse(marker.exists())
        # Root-owned helpers are deliberately retained for an operator's later
        # integrity review; access has been disabled by removing the key/rule.
        self.assertTrue(self.layout.path(provision.DISPATCH_PATH).is_file())

    def test_sudoers_pins_no_arguments_and_rejects_setenv(self) -> None:
        self.assertIn('wpcaregrid-staging-m12-readback ""', provision.SUDOERS_CONTENT)
        self.assertIn("!setenv", provision.SUDOERS_CONTENT)
        self.assertNotIn("*", provision.SUDOERS_CONTENT)

    def test_effective_sudoers_must_contain_only_fixed_no_argument_command(self) -> None:
        valid = (
            "User caregrid-diagnostics may run the following commands on stg1:\n"
            '    (root) NOPASSWD: /usr/local/sbin/wpcaregrid-staging-m12-readback ""\n'
        )
        provision.validate_effective_sudoers_output(valid)
        unsafe_outputs = (
            valid + "    (root) NOPASSWD: ALL\n",
            valid.replace(' ""', ""),
            valid.replace("(root)", "(ALL)"),
            '(root) NOPASSWD: /usr/local/sbin/wpcaregrid-staging-m12-readback ""\n',
        )
        for unsafe in unsafe_outputs:
            with self.subTest(unsafe=unsafe):
                with self.assertRaises(verify.ProvisionError):
                    provision.validate_effective_sudoers_output(unsafe)

    def test_dispatcher_and_shell_guard_reject_unauthorized_commands(self) -> None:
        dispatch = ROOT / "scripts" / "staging-diagnostics" / "dispatch.sh"
        shell_guard = PROVISIONING / "diagnostics-shell.sh"
        env = {**os.environ, "SSH_ORIGINAL_COMMAND": "id; cat /etc/passwd"}
        result = subprocess.run(["sh", str(dispatch)], env=env, check=False)
        self.assertEqual(result.returncode, 64)
        shell = subprocess.run(
            ["sh", str(shell_guard), "-c", "id"], env=env, check=False
        )
        self.assertEqual(shell.returncode, 64)


if __name__ == "__main__":
    unittest.main(verbosity=2)
