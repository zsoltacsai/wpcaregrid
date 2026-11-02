#!/usr/bin/env python3
"""One-shot Package B reproduction with owner-recipient-only raw capture."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import importlib.util
import os
import re
import selectors
import signal
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from typing import Callable


ROOT = Path(__file__).resolve().parents[2]
SOURCE_HELPER = ROOT / "scripts/ci/package-b-targeted-lease-repro.py"
AGE_RECIPIENT = "age1n400h5l00wuk249x2nlwxednsmrdk2yk3pn7sakx83us4lquyerq2aza65"
# Owner-provided on 2026-11-02. Fingerprint algorithm: SHA-256 of the exact ASCII bech32 recipient text.
AGE_RECIPIENT_FINGERPRINT = "sha256:b7d9bf57feee062bc52998eeb4c0e9478400e98db8df8fa78e915b9496272aca"
MAX_SELECTION_BYTES = 1_000_000
MAX_PHPUNIT_BYTES = 2_000_000
SELECTION_TIMEOUT_SECONDS = 60
PHPUNIT_TIMEOUT_SECONDS = 300
AGE_TIMEOUT_SECONDS = 30
OUTPUT_PREFIX = "PACKAGE_B_CONFIDENTIAL_DIAG"


def load_contract():
    spec = importlib.util.spec_from_file_location("package_b_diag_contract", SOURCE_HELPER)
    if spec is None or spec.loader is None:
        raise RuntimeError("DIAGNOSTIC_CONTRACT_UNAVAILABLE")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


CONTRACT = load_contract()
TEST_ID = CONTRACT.TEST_ID
TEST_METHOD = CONTRACT.TEST_METHOD
TEST_FILE = CONTRACT.TEST_FILE


def child_environment(include_database: bool = True) -> dict[str, str]:
    """Pass only the execution environment and synthetic test database settings."""
    allowed = {"PATH", "HOME", "LANG", "LC_ALL", "CI", "SYSTEMROOT", "WINDIR"}
    if include_database:
        allowed.update({"DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD", "DB_URL"})
    result = {key: os.environ[key] for key in allowed if key in os.environ}
    result["APP_ENV"] = "testing"
    result["CAREGRID_DEPLOYMENT_ENV"] = "testing"
    result["MARKETING_RELEASE_ENFORCEMENT_MODE"] = "testing"
    result["CACHE_STORE"] = "array"
    return result


def selection_result(data: bytes) -> tuple[str, int, bool]:
    if len(data) > MAX_SELECTION_BYTES:
        return "OVERSIZED", 0, False
    count, valid = CONTRACT.parse_selection(data)
    if valid and count == 1:
        return "EXACTLY_ONE", count, True
    if count > 1:
        return "AMBIGUOUS", count, False
    return "NO_EXACT_MATCH", count, False


def recipient_matches_approval(recipient: str, fingerprint: str) -> bool:
    if not isinstance(recipient, str) or not isinstance(fingerprint, str):
        return False
    if re.fullmatch(r"sha256:[0-9a-f]{64}", fingerprint) is None:
        return False
    try:
        calculated = "sha256:" + hashlib.sha256(recipient.encode("ascii", errors="strict")).hexdigest()
    except (UnicodeEncodeError, AttributeError):
        return False
    return (
        hmac.compare_digest(recipient, AGE_RECIPIENT)
        and hmac.compare_digest(calculated, fingerprint)
        and hmac.compare_digest(AGE_RECIPIENT_FINGERPRINT, fingerprint)
    )


def age_temp_dir_is_valid(candidate: str | None, runner_temp: str | None) -> bool:
    """Accept only the existing unique direct child created by this workflow."""
    if not candidate or not runner_temp:
        return False
    path = Path(candidate)
    parent = Path(runner_temp)
    if not path.is_absolute() or path.is_symlink() or not path.is_dir():
        return False
    if re.fullmatch(r"wpcg-age-v1\.3\.2\.[A-Za-z0-9]{6}", path.name) is None:
        return False
    try:
        resolved_parent = parent.resolve(strict=True)
        resolved_path = path.resolve(strict=True)
    except (OSError, RuntimeError):
        return False
    return resolved_path.parent == resolved_parent and resolved_path.name == path.name


def artifact_receipt_is_valid(upload_outcome: str, artifact_id: str, digest: str) -> bool:
    return (
        upload_outcome == "success"
        and re.fullmatch(r"[0-9]{1,20}", artifact_id or "") is not None
        and re.fullmatch(r"[0-9a-fA-F]{64}", digest or "") is not None
    )


def run_bounded_capture(
    command: list[str],
    destination: Path,
    timeout_seconds: int,
    capture_limit: int,
    environment: dict[str, str],
) -> tuple[int | None, bool, bool]:
    """Capture bounded merged output privately; drain excess without storing it."""
    process: subprocess.Popen[bytes] | None = None
    truncated = False
    timed_out = False
    captured = 0
    with destination.open("xb") as stream:
        os.chmod(destination, 0o600)
        process = subprocess.Popen(
            command,
            cwd=ROOT,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            env=environment,
            start_new_session=True,
            close_fds=True,
        )
        assert process.stdout is not None
        selector = selectors.DefaultSelector()
        deadline = time.monotonic() + timeout_seconds
        try:
            selector.register(process.stdout, selectors.EVENT_READ)
            while selector.get_map():
                remaining = deadline - time.monotonic()
                if remaining <= 0 and process.poll() is None:
                    timed_out = True
                    try:
                        os.killpg(process.pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
                    deadline = time.monotonic() + 5
                    remaining = deadline - time.monotonic()
                for key, _ in selector.select(timeout=max(0.0, min(0.25, remaining))):
                    chunk = os.read(key.fileobj.fileno(), 65536)
                    if not chunk:
                        selector.unregister(key.fileobj)
                        continue
                    remaining_capacity = max(0, capture_limit - captured)
                    if remaining_capacity:
                        portion = chunk[:remaining_capacity]
                        stream.write(portion)
                        captured += len(portion)
                    if len(chunk) > remaining_capacity:
                        # The exact prefix was retained; all excess bytes are drained and discarded.
                        truncated = True
                if timed_out and time.monotonic() > deadline:
                    break
            stream.flush()
        except BaseException:
            terminate_process_group(process)
            raise
        finally:
            selector.close()
            process.stdout.close()
    if process.poll() is None:
        terminate_process_group(process)
    try:
        return_code = process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        return None, truncated, True
    return (None if timed_out else return_code), truncated, timed_out


def try_age_encryption(age_bin: Path, private_dir: Path) -> tuple[bool, Path, Path]:
    plain = private_dir / "synthetic-age-check.txt"
    cipher = private_dir / "synthetic-age-check.txt.age"
    with plain.open("xb") as stream:
        os.chmod(plain, 0o600)
        stream.write(b"WPCAREGRID_SYNTHETIC_TEST")
    try:
        if not recipient_matches_approval(AGE_RECIPIENT, AGE_RECIPIENT_FINGERPRINT):
            return False, plain, cipher
        completed = subprocess.run(
            [str(age_bin), "-r", AGE_RECIPIENT, "-o", str(cipher), str(plain)],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
            timeout=AGE_TIMEOUT_SECONDS,
            env=child_environment(include_database=False),
        )
        if completed.returncode != 0 or not cipher.is_file():
            return False, plain, cipher
        os.chmod(cipher, 0o600)
        with cipher.open("rb") as stream:
            return stream.read(22).startswith(b"age-encryption.org/v1"), plain, cipher
    except (OSError, subprocess.SubprocessError):
        return False, plain, cipher


def unlink_owned(
    paths: tuple[tuple[str, Path], ...],
    unlinker: Callable[[Path], None] = Path.unlink,
) -> dict[str, str]:
    outcome: dict[str, str] = {}
    for name, path in paths:
        try:
            unlinker(path)
            outcome[name] = "REMOVED"
        except FileNotFoundError:
            outcome[name] = "ALREADY_ABSENT"
        except OSError:
            outcome[name] = "FAILED"
    return outcome


def remove_private_directory(path: Path, rmdir: Callable[[Path], None] = Path.rmdir) -> str:
    try:
        rmdir(path)
        return "REMOVED"
    except FileNotFoundError:
        return "ALREADY_ABSENT"
    except OSError:
        return "FAILED"


def diagnostic_outcome(phpunit_exit: int | None, encrypted: bool, cleanup_ok: bool, output_ok: bool) -> tuple[int, bool]:
    """Preserve PHPUnit failures and fail closed on diagnostic handling errors."""
    publish = encrypted and cleanup_ok and output_ok
    if phpunit_exit not in (None, 0):
        return phpunit_exit, publish
    if phpunit_exit == 0 and publish:
        return 0, True
    return 1, publish


def terminate_process_group(process: subprocess.Popen[bytes]) -> None:
    """Boundedly terminate and reap a child after an exceptional capture path."""
    if process.poll() is None:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except (ProcessLookupError, OSError):
            pass
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except (ProcessLookupError, OSError):
            pass
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            pass


def write_github_output(artifact_created: bool, artifact_owned: bool) -> bool:
    destination = os.environ.get("GITHUB_OUTPUT")
    if not destination:
        return False
    try:
        with Path(destination).open("a", encoding="utf-8", newline="\n") as stream:
            stream.write(f"artifact_created={'true' if artifact_created else 'false'}\n")
            stream.write(f"artifact_owned={'true' if artifact_owned else 'false'}\n")
        return True
    except OSError:
        return False


def emit(fields: dict[str, str]) -> None:
    print(OUTPUT_PREFIX + "|" + "|".join(f"{key}={value}" for key, value in fields.items()), flush=True)


def self_test() -> tuple[bool, int]:
    checks = 0
    passed = True

    def check(condition: bool) -> None:
        nonlocal checks, passed
        checks += 1
        passed = passed and condition

    check(selection_result(("- " + TEST_ID + "\n").encode()) == ("EXACTLY_ONE", 1, True))
    check(selection_result(b"") == ("NO_EXACT_MATCH", 0, False))
    check(selection_result(("- " + TEST_ID + "\n- " + TEST_ID + "\n").encode())[0] == "AMBIGUOUS")
    check(selection_result(b"\xff")[0] == "NO_EXACT_MATCH")
    sample = (
        "There was 1 error:\n1) " + TEST_ID
        + "\nIlluminate\\Database\\QueryException: SQLSTATE[23000]: Integrity constraint violation: 1062 synthetic-secret\n"
    ).encode()
    projected = CONTRACT.project(sample, 2, True)
    rendered = "|".join(projected.values())
    check(projected["result"] == "ERROR")
    check(projected["exception"] == "QueryException")
    check(projected["sqlstate"] == "23000" and projected["vendor_errno"] == "1062")
    check("synthetic-secret" not in rendered and "Integrity constraint" not in rendered)
    check(CONTRACT.project(b"unrecognized synthetic output", 2, True)["result"] == "INCONCLUSIVE")
    check(recipient_matches_approval(AGE_RECIPIENT, AGE_RECIPIENT_FINGERPRINT))
    check(not recipient_matches_approval(AGE_RECIPIENT + "x", AGE_RECIPIENT_FINGERPRINT))
    check(not recipient_matches_approval(AGE_RECIPIENT, "sha256:" + ("0" * 64)))
    check(artifact_receipt_is_valid("success", "123456789", "a" * 64))
    check(not artifact_receipt_is_valid("skipped", "123456789", "a" * 64))
    check(not artifact_receipt_is_valid("success", "", "a" * 64))
    check(not artifact_receipt_is_valid("success", "123456789", "invalid"))
    check(diagnostic_outcome(0, True, True, True) == (0, True))
    check(diagnostic_outcome(0, False, True, True) == (1, False))
    check(diagnostic_outcome(0, True, False, True) == (1, False))
    check(diagnostic_outcome(0, True, True, False) == (1, False))
    check(diagnostic_outcome(2, True, True, True) == (2, True))
    check(diagnostic_outcome(2, True, False, True) == (2, False))
    check(diagnostic_outcome(None, True, True, True) == (1, True))
    check(diagnostic_outcome(0, True, True, False) == (1, False))

    cleanup_cases = (
        (set(), set(), {"first": "REMOVED", "second": "REMOVED"}),
        ({"first"}, set(), {"first": "FAILED", "second": "REMOVED"}),
        ({"second"}, set(), {"first": "REMOVED", "second": "FAILED"}),
        ({"first", "second"}, set(), {"first": "FAILED", "second": "FAILED"}),
        (set(), {"first"}, {"first": "ALREADY_ABSENT", "second": "REMOVED"}),
        (set(), {"second"}, {"first": "REMOVED", "second": "ALREADY_ABSENT"}),
    )
    with tempfile.TemporaryDirectory(prefix="wpcg-confidential-selftest-") as temporary:
        root = Path(temporary)
        runner_temp = root / "runner-temp"
        runner_temp.mkdir()
        expected_root = runner_temp / "wpcg-age-v1.3.2.ABC123"
        expected_root.mkdir()
        outside_root = root / "wpcg-age-v1.3.2.DEF456"
        outside_root.mkdir()
        unexpected_root = runner_temp / "wpcg-age-v1.3.2-unexpected"
        unexpected_root.mkdir()
        check(age_temp_dir_is_valid(str(expected_root), str(runner_temp)))
        check(not age_temp_dir_is_valid(None, str(runner_temp)))
        check(not age_temp_dir_is_valid("", str(runner_temp)))
        check(not age_temp_dir_is_valid(str(outside_root), str(runner_temp)))
        check(not age_temp_dir_is_valid(str(unexpected_root), str(runner_temp)))
        check(not age_temp_dir_is_valid(str(expected_root), None))
        check(remove_private_directory(expected_root) == "REMOVED" and not expected_root.exists())
        failed_root = runner_temp / "wpcg-age-v1.3.2.GHI789"
        failed_root.mkdir()

        def synthetic_rmdir(_path: Path) -> None:
            raise PermissionError

        check(remove_private_directory(failed_root, synthetic_rmdir) == "FAILED" and failed_root.is_dir())
        for failures, absent, expected in cleanup_cases:
            first = root / "first"
            second = root / "second"
            first.write_text("synthetic", encoding="ascii")
            second.write_text("synthetic", encoding="ascii")
            if "first" in absent:
                first.unlink()
            if "second" in absent:
                second.unlink()
            attempts: list[str] = []

            def synthetic_unlink(path: Path) -> None:
                label = path.name
                attempts.append(label)
                if label in failures:
                    raise PermissionError
                path.unlink()

            observed = unlink_owned((("first", first), ("second", second)), synthetic_unlink)
            check(observed == expected and attempts == ["first", "second"])
    return passed, checks


def run_once(php_version: str, age_bin: Path) -> int:
    runner_temp = Path(os.environ.get("RUNNER_TEMP", tempfile.gettempdir()))
    private_dir: Path | None = None
    selection_path: Path | None = None
    raw_path: Path | None = None
    probe_plain: Path | None = None
    probe_cipher: Path | None = None
    artifact_path = runner_temp / f"wpcg-package-b-confidential-{php_version}.age"
    selected_count = 0
    selected_state = "UNKNOWN"
    recipient_fingerprint_state = "NOT_CHECKED"
    process_exit: int | None = None
    capture_state = "NOT_RUN"
    encrypted = False
    artifact_owned = False
    artifact_publishable = False
    artifact_cleanup = "NOT_CREATED"
    cleanup_files: dict[str, str] = {}
    cleanup_directory = "ALREADY_ABSENT"
    cleanup_ok = False
    output_ok = False
    report = {
        "phpunit_exit": "NOT_RUN", "result": "INCONCLUSIVE", "exception": "NONE",
        "sqlstate": "NONE", "vendor_errno": "NONE", "assertion": "UNKNOWN",
    }
    status = 1

    try:
        if not recipient_matches_approval(AGE_RECIPIENT, AGE_RECIPIENT_FINGERPRINT):
            raise RuntimeError("AGE_RECIPIENT_FINGERPRINT_MISMATCH")
        recipient_fingerprint_state = "VERIFIED"
        if artifact_path.exists():
            raise RuntimeError("ARTIFACT_PATH_ALREADY_EXISTS")
        private_dir = Path(tempfile.mkdtemp(prefix="wpcg-package-b-confidential-", dir=runner_temp))
        private_dir.chmod(0o700)
        selection_path = private_dir / "selection.txt"
        raw_path = private_dir / "phpunit.raw"

        version = subprocess.run([str(age_bin), "--version"], capture_output=True, check=False, timeout=AGE_TIMEOUT_SECONDS,
                                 env=child_environment(include_database=False))
        version_text = (version.stdout + version.stderr).decode("utf-8", errors="strict").strip()
        if version.returncode != 0 or version_text not in {"age v1.3.2", "v1.3.2"}:
            raise RuntimeError("AGE_BINARY_VERSION_MISMATCH")

        recipient_ok, probe_plain, probe_cipher = try_age_encryption(age_bin, private_dir)
        if not recipient_ok:
            raise RuntimeError("AGE_RECIPIENT_VALIDATION_FAILED")
        probe_cleanup = unlink_owned((("probe_plain", probe_plain), ("probe_cipher", probe_cipher)))
        if "FAILED" in probe_cleanup.values():
            raise RuntimeError("AGE_PROBE_CLEANUP_FAILED")
        probe_plain = probe_cipher = None

        selection_command = ["vendor/bin/phpunit", "-c", "phpunit.xml", "--list-tests", "--filter", TEST_METHOD, TEST_FILE]
        selection_exit, selection_truncated, selection_timeout = run_bounded_capture(
            selection_command, selection_path, SELECTION_TIMEOUT_SECONDS, MAX_SELECTION_BYTES,
            child_environment(include_database=False),
        )
        if selection_timeout:
            selected_state = "TIMEOUT"
            raise RuntimeError("TEST_SELECTION_FAILED")
        if selection_truncated:
            selected_state = "OVERSIZED"
            raise RuntimeError("TEST_SELECTION_FAILED")
        selection_bytes = selection_path.read_bytes()
        selected_state, selected_count, exact = selection_result(selection_bytes)
        if selection_exit != 0 or not exact:
            raise RuntimeError("TEST_SELECTION_FAILED")

        test_command = [
            "vendor/bin/phpunit", "-c", "phpunit.xml", "--debug", "--fail-on-skipped",
            "--filter", TEST_METHOD, TEST_FILE,
        ]
        process_exit, was_truncated, test_timeout = run_bounded_capture(
            test_command, raw_path, PHPUNIT_TIMEOUT_SECONDS, MAX_PHPUNIT_BYTES,
            child_environment(include_database=True),
        )
        capture_state = "TRUNCATED" if was_truncated else ("TIMEOUT" if test_timeout else "COMPLETE")
        report = CONTRACT.project(raw_path.read_bytes() if not was_truncated else b"", process_exit, not was_truncated and not test_timeout)

        try:
            artifact_owned = True
            encrypted_result = subprocess.run(
                [str(age_bin), "-r", AGE_RECIPIENT, "-o", str(artifact_path), str(raw_path)],
                stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                check=False, timeout=AGE_TIMEOUT_SECONDS, env=child_environment(include_database=False),
            )
            encrypted = encrypted_result.returncode == 0 and artifact_path.is_file() and artifact_path.stat().st_size > 0
            if encrypted:
                os.chmod(artifact_path, 0o600)
        except (OSError, subprocess.SubprocessError):
            encrypted = False

        if not encrypted:
            capture_state = "ENCRYPTION_FAILED"
    except (OSError, RuntimeError, subprocess.SubprocessError, UnicodeError, ValueError):
        if process_exit is None:
            report = {"phpunit_exit": "NOT_RUN", "result": "INCONCLUSIVE", "exception": "NONE",
                      "sqlstate": "NONE", "vendor_errno": "NONE", "assertion": "UNKNOWN"}
        else:
            report = {"phpunit_exit": str(process_exit), "result": "INCONCLUSIVE", "exception": "NONE",
                      "sqlstate": "NONE", "vendor_errno": "NONE", "assertion": "UNKNOWN"}
    finally:
        paths = tuple((name, path) for name, path in (
            ("selection", selection_path), ("capture", raw_path), ("probe_plain", probe_plain), ("probe_cipher", probe_cipher),
        ) if path is not None)
        cleanup_files = unlink_owned(paths)
        if private_dir is not None:
            cleanup_directory = remove_private_directory(private_dir)
        cleanup_ok = "FAILED" not in cleanup_files.values() and cleanup_directory != "FAILED"
        output_ok = write_github_output(encrypted and cleanup_ok, artifact_owned)
        status, artifact_publishable = diagnostic_outcome(process_exit, encrypted, cleanup_ok, output_ok)
        if artifact_publishable:
            artifact_cleanup = "RETAINED_FOR_UPLOAD"
        elif artifact_owned:
            artifact_cleanup = unlink_owned((("encrypted_artifact", artifact_path),))["encrypted_artifact"]
            if artifact_cleanup == "FAILED" and status == 0:
                status = 1

    fields = {
        "php": php_version,
        "test": TEST_ID,
        "selection": selected_state,
        "selection_count": str(selected_count) if selected_state == "EXACTLY_ONE" else "UNKNOWN",
        "recipient_fingerprint": recipient_fingerprint_state,
        "phpunit_exit": report["phpunit_exit"],
        "result": report["result"],
        "exception": report["exception"],
        "sqlstate": report["sqlstate"],
        "vendor_errno": report["vendor_errno"],
        "assertion": report["assertion"],
        "capture": capture_state,
        "encryption": "PASS" if encrypted else "FAIL",
        "artifact_created": "YES" if artifact_publishable else "NO",
        "publication": "PASS" if artifact_publishable else "FAILED",
        "cleanup_artifact": artifact_cleanup,
        "cleanup": "PASS" if "FAILED" not in cleanup_files.values() and cleanup_directory != "FAILED" and artifact_cleanup != "FAILED" else "FAIL",
    }
    emit(fields)
    if encrypted and process_exit not in (None, 0):
        return process_exit
    if process_exit not in (None, 0):
        return process_exit
    return status


def main() -> int:
    parser = argparse.ArgumentParser()
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--self-test", action="store_true")
    modes.add_argument("--run", action="store_true")
    modes.add_argument("--verify-upload", action="store_true")
    modes.add_argument("--validate-age-temp-dir", action="store_true")
    parser.add_argument("--php-version", choices=("8.4", "8.5"))
    parser.add_argument("--age-bin")
    parser.add_argument("--upload-outcome")
    parser.add_argument("--artifact-id")
    parser.add_argument("--artifact-digest")
    parser.add_argument("--age-temp-dir")
    parser.add_argument("--runner-temp")
    args = parser.parse_args()
    if args.self_test:
        passed, count = self_test()
        if not passed:
            print(f"{OUTPUT_PREFIX}|status=SYNTHETIC_VALIDATION_FAILED", flush=True)
            return 1
        print(f"{OUTPUT_PREFIX}|status=SYNTHETIC_VALIDATION_PASS|checks={count}", flush=True)
        return 0
    if args.verify_upload:
        if artifact_receipt_is_valid(args.upload_outcome or "", args.artifact_id or "", args.artifact_digest or ""):
            print(f"{OUTPUT_PREFIX}|artifact_receipt=VERIFIED", flush=True)
            return 0
        print(f"{OUTPUT_PREFIX}|artifact_receipt=FAILED", flush=True)
        return 1
    if args.validate_age_temp_dir:
        if age_temp_dir_is_valid(args.age_temp_dir, args.runner_temp):
            print(f"{OUTPUT_PREFIX}|age_temp_dir=VALID", flush=True)
            return 0
        print(f"{OUTPUT_PREFIX}|age_temp_dir=INVALID", flush=True)
        return 1
    if args.php_version is None or args.age_bin is None or not Path("vendor/bin/phpunit").is_file():
        print(f"{OUTPUT_PREFIX}|status=PRECONDITION_FAILED", flush=True)
        return 1
    return run_once(args.php_version, Path(args.age_bin))


if __name__ == "__main__":
    raise SystemExit(main())
