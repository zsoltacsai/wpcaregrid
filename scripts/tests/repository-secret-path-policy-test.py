#!/usr/bin/env python3
"""Attacker-controlled filenames are detected but never printed."""
import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("secret_paths", ROOT / "scripts/tests/repository-secret-path-policy.py")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
CHECKER = ROOT / "scripts/tests/repository-secret-path-policy.py"


def git(root, *args, env=None):
    return subprocess.run(
        ["git", "-C", str(root), *args],
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=env,
        timeout=10,
    )


def create_file(root, relative_path):
    if os.name == "nt":
        path = root.joinpath(*relative_path.decode("utf-8").split("/"))
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(b"synthetic marker only\n")
        return

    full_path = os.fsencode(root) + b"/" + relative_path
    os.makedirs(full_path.rsplit(b"/", 1)[0], exist_ok=True)
    descriptor = os.open(full_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, "wb") as output:
        output.write(b"synthetic marker only\n")


def run_checker(root, env):
    return subprocess.run(
        [sys.executable, str(CHECKER)],
        cwd=root,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=env,
        timeout=10,
    )


def main():
    newline = bytes([10])
    sensitive = [
        b".env", b"storage/id_rsa", b"database/test.sqlite", b"folder" + newline + b"::error::sentinel/.env",
        b"folder" + newline + b"::add-mask::SECRET/auth.json",
    ]
    found = MODULE.find_sensitive(sensitive)
    assert len(found) == 5
    assert MODULE.find_sensitive([b".env.example", b"reports/a.md", b"public/index.php"]) == []

    hostile_names = [
        b"dir\n::error::M147B_ANNOTATION_SENTINEL/.env",
        b"dir\r::warning::M147B_WARNING_SENTINEL/auth.json",
        b"dir\t::notice::M147B_NOTICE_SENTINEL/id_rsa",
        b"dir\x1f::add-mask::M147B_MASK_SENTINEL/credential.pem",
        b"dir\n::set-output name=check::M147B_OUTPUT_SENTINEL/database.sqlite",
        b"dir\rGITHUB_ENV<<M147B_ENV_SENTINEL/.env.production",
        b"-::error::M147B_DASH_SENTINEL/id_ed25519",
        b" /::notice::M147B_SPACE_SENTINEL/auth.json",
        b"unicode-\xe2\x80\xa8-marker/.env.backup",
        b"long-" + b"x" * 190 + b"/private.p12",
    ]
    if os.name == "nt":
        # Windows forbids control characters in filenames. Linux CI executes
        # the complete corpus; keep local tests portable on this filesystem.
        hostile_names = [
            b"dir-annotation-M147B_ANNOTATION_SENTINEL/.env",
            b"dir-mask-M147B_MASK_SENTINEL/auth.json",
            b"-warning-M147B_DASH_SENTINEL/id_ed25519",
            b"unicode-\xe2\x80\xa8-marker/.env.backup",
            b"long-" + b"x" * 80 + b"/private.p12",
        ]
    assert len(MODULE.find_sensitive(hostile_names)) == len(hostile_names)

    with tempfile.TemporaryDirectory(prefix="m147b-secret-path-") as directory:
        repository = Path(directory) / "fixture"
        repository.mkdir()
        git(repository, "init", "--quiet")
        for name in hostile_names:
            create_file(repository, name)

        benign_name = (
            b"README-M147B_BENIGN_SENTINEL.txt"
            if os.name == "nt"
            else b"README\n::warning::M147B_BENIGN_SENTINEL.txt"
        )
        create_file(repository, benign_name)
        git(repository, "add", "--all", "--")

        command_files = {
            "GITHUB_ENV": Path(directory) / "runner-env",
            "GITHUB_OUTPUT": Path(directory) / "runner-output",
            "GITHUB_PATH": Path(directory) / "runner-path",
            "GITHUB_STEP_SUMMARY": Path(directory) / "runner-summary",
        }
        checker_env = {
            key: os.environ[key]
            for key in ("PATH", "SYSTEMROOT", "WINDIR", "TEMP", "TMP", "HOME", "USERPROFILE", "COMSPEC", "PATHEXT")
            if key in os.environ
        }
        checker_env["GIT_CONFIG_NOSYSTEM"] = "1"
        for key, value in command_files.items():
            checker_env[key] = str(value)

        rejected = run_checker(repository, checker_env)
        assert rejected.returncode == 1, (rejected.returncode, rejected.stdout, rejected.stderr)
        assert rejected.stdout == b""
        expected_stderr = (
            f"Local or secret-like tracked paths found: {len(hostile_names)} (paths redacted).\n".encode()
        )
        rejected_output = (rejected.stdout + rejected.stderr).replace(b"\r\n", b"\n")
        assert rejected_output == expected_stderr, repr(rejected_output)
        combined = rejected_output
        for marker in (
            b"M147B_ANNOTATION_SENTINEL", b"M147B_WARNING_SENTINEL", b"M147B_NOTICE_SENTINEL",
            b"M147B_MASK_SENTINEL", b"M147B_OUTPUT_SENTINEL", b"M147B_ENV_SENTINEL",
            b"M147B_DASH_SENTINEL", b"M147B_SPACE_SENTINEL", b"M147B_BENIGN_SENTINEL",
        ):
            assert marker not in combined
        assert b"::" not in combined
        assert b"\r" not in combined
        for path in command_files.values():
            assert not path.exists()

        git(repository, "rm", "--cached", "-r", "--quiet", "--", ".")
        git(repository, "add", "--all", "--", os.fsdecode(benign_name))
        clean = run_checker(repository, checker_env)
        assert clean.returncode == 0
        clean_output = clean.stdout.replace(b"\r\n", b"\n")
        assert clean_output == b"No local or secret-like tracked paths found.\n"
        assert clean.stderr == b""
        assert b"M147B_BENIGN_SENTINEL" not in clean_output + clean.stderr
        for path in command_files.values():
            assert not path.exists()

    print(
        "Tracked secret-path hostile-filename integration: "
        f"PASS ({len(hostile_names)} synthetic paths; names redacted)"
    )


if __name__ == "__main__":
    main()
