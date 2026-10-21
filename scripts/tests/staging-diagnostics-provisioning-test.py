#!/usr/bin/env python3
"""Synthetic tests for staging diagnostics provisioning and recovery."""

from __future__ import annotations

import base64
import importlib.util
import hashlib
import os
import shutil
import stat
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
        if command == ["/usr/bin/sudo", "-l", "-U", provision.DIAGNOSTICS_USER]:
            return (
                f"User {provision.DIAGNOSTICS_USER} may run the following commands on stg1:\n"
                '(root) NOPASSWD: /usr/local/sbin/wpcaregrid-staging-m12-readback ""\n'
            )
        if command[:3] == ["/usr/sbin/visudo", "-cf", "INVALID"]:
            raise provision.ProvisionError("SUDOERS_INVALID")
        return ""

    def run_install(self, runner=None, checkpoint=None) -> None:
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
                checkpoint=checkpoint,
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
        self.assertEqual(key_path.stat().st_mode & 0o777, 0o444)
        self.assertEqual(key_path.parent.stat().st_mode & 0o777, 0o711)
        self.assertFalse(self.layout.path(provision.PENDING_PATH).exists())
        self.assertTrue(provision._activation_is_valid(self.layout, self.source_sha))
        self.assertEqual(
            self.layout.path(provision.ACTIVE_PATH).read_bytes(),
            f"active={self.source_sha}\n".encode(),
        )
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
        self.assertTrue(provision._activation_is_valid(self.layout, self.source_sha))

    def test_every_interrupted_install_phase_remains_inactive(self) -> None:
        phases = (
            "after_sudoers_installation",
            "after_authorized_keys_installation",
            "after_final_verification",
            "before_activation_preparation",
            "after_activation_preparation",
        )
        original_layout = self.layout
        for phase in phases:
            with self.subTest(phase=phase):
                phase_root = self.temp_root / phase
                phase_root.mkdir()
                self.layout = provision.Layout(
                    root=phase_root, owner_uid=os.geteuid(), owner_gid=os.getegid()
                )
                for directory in (
                    "/usr/local/libexec", "/usr/local/sbin", "/etc/sudoers.d",
                    "/var/lib", "/run", "/run/lock",
                ):
                    target = self.layout.path(directory)
                    target.mkdir(parents=True, exist_ok=True)
                    target.chmod(0o755)
                self.runner_calls = []

                def fail_at(current: str) -> None:
                    if current == phase:
                        raise provision.ProvisionError("INJECTED_FAILURE")

                with self.assertRaisesRegex(provision.ProvisionError, "INJECTED_FAILURE"):
                    self.run_install(checkpoint=fail_at)
                self.assertTrue(self.layout.path(provision.PENDING_PATH).is_file())
                self.assertFalse(provision._activation_is_valid(self.layout, self.source_sha))
                if phase == "after_activation_preparation":
                    self.assertTrue(self.layout.path(provision.ACTIVE_PATH).is_file())
        self.layout = original_layout

    def test_activation_check_accepts_only_intact_active_state_without_pending_marker(self) -> None:
        if not Path("/usr/bin/stat").is_file() or shutil.which("sh") is None:
            self.skipTest("POSIX shell and GNU stat are unavailable")
        state = self.layout.path(provision.STATE_PATH)
        state.mkdir(mode=0o700)
        state.chmod(0o711)
        checker_text = (
            (PROVISIONING / "activation-check.sh")
            .read_text(encoding="utf-8")
            .replace("/var/lib/wpcaregrid-staging-diagnostics", str(state))
            .replace("'0:711:directory'", f"'{os.geteuid()}:711:directory'")
            .replace("'0:444:regular file'", f"'{os.geteuid()}:444:regular file'")
        )
        checker = self.temp_root / "activation-check.sh"
        checker.write_text(checker_text, encoding="utf-8")
        checker.chmod(0o700)
        active = self.layout.path(provision.ACTIVE_PATH)
        active.write_bytes(f"active={self.source_sha}\n".encode())
        active.chmod(0o444)

        def run_check() -> subprocess.CompletedProcess[str]:
            return subprocess.run(["sh", str(checker)], text=True, capture_output=True, check=False)

        self.assertEqual(run_check().returncode, 0)
        state.chmod(0o700)
        self.layout.path(provision.PENDING_PATH).write_text("pending\n", encoding="ascii")
        self.layout.path(provision.PENDING_PATH).chmod(0o600)
        state.chmod(0o711)
        self.assertNotEqual(run_check().returncode, 0)
        state.chmod(0o700)
        self.layout.path(provision.PENDING_PATH).unlink()
        active.chmod(0o600)
        active.write_text("active=invalid\n", encoding="ascii")
        active.chmod(0o444)
        state.chmod(0o711)
        self.assertNotEqual(run_check().returncode, 0)
        state.chmod(0o700)
        active.unlink()
        active.symlink_to(self.temp_root / "elsewhere")
        state.chmod(0o711)
        self.assertNotEqual(run_check().returncode, 0)

    def test_rollback_deactivates_before_revoking_key_and_sudoers(self) -> None:
        self.run_install()
        self.assertTrue(provision._activation_is_valid(self.layout, self.source_sha))
        with patch.object(rollback, "verify_source_provenance", return_value=provision.source_hashes(ROOT)):
            rollback.rollback_filesystem(self.layout, self.source_sha, self.public_key)
        self.assertFalse(self.layout.path(provision.ACTIVE_PATH).exists())
        self.assertFalse(self.layout.path(provision.SUDOERS_PATH).exists())
        self.assertFalse(
            self.layout.path(f"{provision.DIAGNOSTICS_HOME}/.ssh/authorized_keys").exists()
        )

    def test_idempotent_install_rejects_effective_sudo_privilege_expansion(self) -> None:
        self.run_install()

        def expanded_runner(command: list[str]) -> str:
            self.runner_calls.append(command)
            if command == ["/usr/bin/sudo", "-l", "-U", provision.DIAGNOSTICS_USER]:
                return (
                    f"User {provision.DIAGNOSTICS_USER} may run the following commands on stg1:\n"
                    '(root) NOPASSWD: /usr/local/sbin/wpcaregrid-staging-m12-readback ""\n'
                    "(root) NOPASSWD: ALL\n"
                )
            return ""

        with self.assertRaisesRegex(provision.ProvisionError, "SUDOERS_EFFECTIVE_POLICY_UNSAFE"):
            self.run_install(expanded_runner)

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

    def test_sshd_policy_rejects_match_user_group_address_and_included_rules(self) -> None:
        config_root = self.temp_root / "etc-ssh"
        config_root.mkdir()
        main = config_root / "sshd_config"
        include_dir = config_root / "sshd_config.d"
        include_dir.mkdir()
        for match in (
            "Match User caregrid-diagnostics\n",
            "Match Group caregrid-diagnostics\n",
            "Match Address 203.0.113.0/24\n",
        ):
            main.write_text(match, encoding="ascii")
            self.assertTrue(
                provision._sshd_config_has_unsafe_match(
                    main, owner_uid=os.geteuid(), owner_gid=os.getegid()
                )
            )
        main.write_text("Include sshd_config.d/*.conf\n", encoding="ascii")
        (include_dir / "diagnostics.conf").write_text(
            "Match Address 198.51.100.0/24\n", encoding="ascii"
        )
        self.assertTrue(
            provision._sshd_config_has_unsafe_match(
                main, owner_uid=os.geteuid(), owner_gid=os.getegid()
            )
        )
        (include_dir / "diagnostics.conf").unlink()
        (include_dir / "link.conf").symlink_to(main)
        with self.assertRaisesRegex(provision.ProvisionError, "SSHD_INCLUDE_PATH_UNSAFE"):
            provision._sshd_config_has_unsafe_match(main, owner_uid=os.geteuid(), owner_gid=os.getegid())

    def test_sshd_policy_accepts_global_only_config_and_recurses_includes(self) -> None:
        config_root = self.temp_root / "ssh-global"
        config_root.mkdir()
        (config_root / "included.conf").write_text("PasswordAuthentication no\n", encoding="ascii")
        main = config_root / "sshd_config"
        main.write_text("Include included.conf\nPubkeyAuthentication yes\n", encoding="ascii")
        self.assertFalse(provision._sshd_config_has_unsafe_match(main, owner_uid=os.geteuid(), owner_gid=os.getegid()))

    def test_effective_sshd_config_requires_locked_account_compatible_pam_policy(self) -> None:
        valid = (
            "usepam yes", "pubkeyauthentication yes", "passwordauthentication no",
            "kbdinteractiveauthentication no", "gssapiauthentication no",
            "hostbasedauthentication no", "authorizedkeyscommand none",
            "trustedusercakeys none", "forcecommand none", "allowtcpforwarding no",
            "allowagentforwarding no", "x11forwarding no", "permittunnel no",
            "permittty no", "permituserrc no", "permituserenvironment no",
            "authorizedkeysfile .ssh/authorized_keys",
            "subsystem sftp /usr/lib/openssh/sftp-server",
        )
        output = "\n".join(valid)
        provision.validate_sshd_effective_config(output)
        with self.assertRaisesRegex(provision.ProvisionError, "SSHD_AUTH_POLICY_UNSAFE"):
            provision.validate_sshd_effective_config(output.replace("usepam yes", "usepam no"))

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

    def test_trusted_package_manifest_rejects_mutations_and_bad_sha(self) -> None:
        sha = "c" * 40
        source_parent = self.temp_root / "trusted-source"
        package = source_parent / sha
        for relative in provision.TRUSTED_PACKAGE_FILES:
            destination = package / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / relative, destination)
            destination.chmod(0o444)
        lines = [f"commit={sha}"]
        for relative in provision.TRUSTED_PACKAGE_FILES:
            digest = hashlib.sha256((package / relative).read_bytes()).hexdigest()
            lines.append(f"{digest}  {relative}")
        manifest = package / provision.PROVENANCE_PATH
        manifest.write_text("\n".join(lines) + "\n", encoding="ascii")
        manifest.chmod(0o444)
        source_parent.chmod(0o700)
        package.chmod(0o700)

        real_lstat = provision._lstat

        def trusted_test_lstat(path: Path):
            info = real_lstat(path)
            if info is None:
                return None
            mode = info.st_mode
            if stat.S_ISDIR(mode):
                mode &= ~0o022
            return type("SyntheticStat", (), {
                "st_mode": mode,
                "st_uid": os.geteuid(),
                "st_gid": os.getegid(),
                "st_size": info.st_size,
            })()

        with patch.object(provision, "TRUSTED_SOURCE_PARENT", source_parent), patch.object(
            provision, "_lstat", side_effect=trusted_test_lstat
        ):
            provision._verify_trusted_source_package(
                package,
                sha,
                owner_uid=os.geteuid(),
                owner_gid=os.getegid(),
                enforce_system_path=False,
            )
            manifest.chmod(0o644)
            manifest.write_text("commit=" + "d" * 40 + "\n" + "\n".join(lines[1:]) + "\n", encoding="ascii")
            manifest.chmod(0o444)
            with self.assertRaisesRegex(provision.ProvisionError, "SOURCE_SHA_MISMATCH"):
                provision._verify_trusted_source_package(
                    package, sha, owner_uid=os.geteuid(), owner_gid=os.getegid(),
                    enforce_system_path=False,
                )
            manifest.chmod(0o644)
            manifest.write_text("\n".join(lines) + "\n", encoding="ascii")
            manifest.chmod(0o444)
            target = package / provision.TRUSTED_PACKAGE_FILES[0]
            target.chmod(0o644)
            target.write_text("tampered\n", encoding="ascii")
            with self.assertRaisesRegex(provision.ProvisionError, "SOURCE_FILE_MISMATCH"):
                provision._verify_trusted_source_package(
                    package, sha, owner_uid=os.geteuid(), owner_gid=os.getegid(),
                    enforce_system_path=False,
                )

    def test_root_trusted_bootstrap_refuses_checkout_execution(self) -> None:
        with self.assertRaisesRegex(provision.ProvisionError, "TRUSTED_SOURCE_PATH_UNSAFE"):
            provision._verify_trusted_source_package(ROOT, "e" * 40)

    def test_isolated_python_ignores_attacker_controlled_import_path(self) -> None:
        interpreter = shutil.which("python3") or sys.executable
        if not interpreter:
            self.skipTest("Python interpreter unavailable")
        attacker_dir = self.temp_root / "attacker-import"
        attacker_dir.mkdir()
        (attacker_dir / "provision.py").write_text(
            "raise RuntimeError('ATTACKER_IMPORT_LOADED')\n", encoding="ascii"
        )
        env = {**os.environ, "PYTHONPATH": str(attacker_dir)}
        result = subprocess.run(
            [interpreter, "-I", "-S", str(PROVISIONING / "verify.py"), "--help"],
            cwd=attacker_dir,
            env=env,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("ATTACKER_IMPORT_LOADED", result.stderr)

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
