#!/usr/bin/env python3
"""One-shot, owner-recipient-only Package B guard-predicate diagnostic."""

from __future__ import annotations

import argparse
import hashlib
import hmac
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
TEST_ID = (
    r"Tests\Feature\Deployment\Phase2B\TransitionLeasePersistenceTest::"
    "test_create_claim_exact_replay_and_receipt_readback_are_durable_and_bound"
)
TEST_METHOD = TEST_ID.rsplit("::", 1)[1]
TEST_FILE = "tests/Feature/Deployment/Phase2B/TransitionLeasePersistenceTest.php"
COMMON_ASSERTION_CHECKPOINTS = (
    "TEST_STARTED", "CREATE_RPC", "CREATE_STATUS", "CREATE_STATE", "CREATE_STATE_ROW",
    "CREATE_RECEIPT", "CREATE_READBACK", "CREATE_MICROSECONDS", "EXACT_REPLAY", "CLAIM_RPC",
    "CLAIM_STATUS", "CLAIM_STATE", "CLAIM_VERSION", "CLAIM_READBACK", "CLAIM_MICROSECONDS",
    "RECEIPT_READBACK", "INVALID_DIGEST_PREPARE", "INVALID_DIGEST_RPC",
)
ASSERTION_CHECKPOINT_PATHS = (
    COMMON_ASSERTION_CHECKPOINTS + ("INVALID_DIGEST_CREATE_RETURNED", "INVALID_DIGEST_ACCEPTED"),
    COMMON_ASSERTION_CHECKPOINTS + (
        "INVALID_DIGEST_EXCEPTION_CAUGHT", "INVALID_DIGEST_GUARD_ASSERTION", "INVALID_DIGEST_REJECTION",
        "INVALID_DIGEST_NONPERSISTENCE_CHECK", "INVALID_DIGEST_NOT_PERSISTED",
    ),
)
ASSERTION_CHECKPOINTS = tuple(dict.fromkeys(code for path in ASSERTION_CHECKPOINT_PATHS for code in path))
ASSERTION_CHECKPOINT_PREFIX = b"WPCG_PACKAGE_B_ASSERTION_CHECKPOINT="
AGE_RECIPIENT = "age1n400h5l00wuk249x2nlwxednsmrdk2yk3pn7sakx83us4lquyerq2aza65"
AGE_RECIPIENT_FINGERPRINT = "sha256:b7d9bf57feee062bc52998eeb4c0e9478400e98db8df8fa78e915b9496272aca"
AGE_ARCHIVE_SHA256 = "cbe24006683f8eb669266162894b9a522a1af52f2665fbc63a4bb032ed26ac10"
AGE_VERSION = "age v1.3.2"
AGE_TIMEOUT = 30
SELECTION_TIMEOUT = 60
TEST_TIMEOUT = 300
MAX_SELECTION_BYTES = 1_000_000
MAX_CAPTURE_BYTES = 2_000_000
STAGES = {"CREATE_STATE_SAVE", "CLAIM_STATE_SAVE", "REJECT_STATE_SAVE", "DIRECT_STATE_SAVE"}
PREDICATES = {
    "MODEL_ALREADY_EXISTS", "LEASE_ID_NOT_UUID_V7", "OPERATION_ID_NOT_UUID_V7",
    "GENERATION_NOT_INTEGER", "GENERATION_NOT_NONNEGATIVE", "EXECUTION_ID_NOT_UUID_V7",
    "FENCE_TUPLE_DIGEST_INVALID", "FENCE_STATE_UNSUPPORTED", "FENCE_BOOT_ID_INVALID",
    "FENCE_JOURNAL_SEQ_NOT_INTEGER", "FENCE_JOURNAL_SEQ_NOT_NONNEGATIVE",
    "FENCE_JOURNAL_HEAD_HASH_INVALID", "FENCE_EVIDENCE_DIGEST_INVALID", "FENCE_MANIFEST_MISMATCH",
    "STATE_VERSION_NOT_INTEGER", "STATE_VERSION_UNSUPPORTED", "STATE_UNSUPPORTED",
    "EVENT_TYPE_UNSUPPORTED", "HOLDER_DIGEST_INVALID", "STATE_DIGEST_INVALID",
    "REQUEST_DIGEST_INVALID", "SOURCE_STATE_DIGEST_INVALID", "REQUEST_ID_NOT_UUID_V7",
    "EXPIRY_MISSING", "OCCURRED_AT_MISSING", "VERSION_STATE_PAIR_INVALID",
    "FENCE_BINDING_INVALID", "STATE_DIGEST_MISMATCH",
}
GUARD_RE = re.compile(
    r"Invalid append-only lease state\. \[(CREATE_STATE_SAVE|CLAIM_STATE_SAVE|"
    r"REJECT_STATE_SAVE|DIRECT_STATE_SAVE):([A-Z0-9_]+)\]"
)
LISTED_TEST_RE = re.compile(r"^\s*-\s+(.+?)\s*$")
ASSERTION_CHECKPOINT_RE = re.compile(rb"(?m)^WPCG_PACKAGE_B_ASSERTION_CHECKPOINT=([A-Z0-9_]+)\r?$")


def child_environment(include_database: bool = True, private_dir: Path | None = None) -> dict[str, str]:
    allowed = {"PATH", "HOME", "LANG", "LC_ALL", "CI", "SYSTEMROOT", "WINDIR"}
    if include_database:
        allowed.update({"DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD", "DB_URL"})
    result = {key: os.environ[key] for key in allowed if key in os.environ}
    result.update({
        "APP_ENV": "testing",
        "CAREGRID_DEPLOYMENT_ENV": "testing",
        "MARKETING_RELEASE_ENFORCEMENT_MODE": "testing",
        "CACHE_STORE": "array",
    })
    if private_dir is not None:
        result.update({"TMPDIR": str(private_dir), "TMP": str(private_dir), "TEMP": str(private_dir)})
    return result


def parse_selection(data: bytes) -> tuple[int, bool]:
    if len(data) > MAX_SELECTION_BYTES:
        return 0, False
    try:
        lines = data.decode("utf-8", errors="strict").splitlines()
    except UnicodeDecodeError:
        return 0, False
    listed = [match.group(1) for line in lines if (match := LISTED_TEST_RE.match(line))]
    return sum(name == TEST_ID for name in listed), len(listed) == 1 and listed[0] == TEST_ID


def parse_assertion_checkpoint(raw: bytes) -> str:
    occurrences = raw.count(ASSERTION_CHECKPOINT_PREFIX)
    matches = ASSERTION_CHECKPOINT_RE.findall(raw)
    if occurrences != len(matches) or not matches or len(matches) > len(ASSERTION_CHECKPOINTS):
        return "UNKNOWN"
    try:
        observed = tuple(item.decode("ascii", errors="strict") for item in matches)
    except UnicodeDecodeError:
        return "UNKNOWN"
    if not any(observed == path[:len(observed)] for path in ASSERTION_CHECKPOINT_PATHS):
        return "UNKNOWN"
    return observed[-1]


def project(raw: bytes, exit_code: int | None, selection_valid: bool, truncated: bool) -> dict[str, str]:
    result = "INCONCLUSIVE"
    stage = "UNKNOWN"
    predicate = "UNKNOWN"
    assertion_checkpoint = "UNKNOWN"
    if selection_valid and not truncated and len(raw) <= MAX_CAPTURE_BYTES:
        assertion_checkpoint = parse_assertion_checkpoint(raw)
        text = raw.decode("utf-8", errors="replace")
        matches = list(GUARD_RE.finditer(text))
        for match in matches:
            if match.group(1) in STAGES and match.group(2) in PREDICATES:
                stage, predicate = match.groups()
                break
        if exit_code == 0 and re.search(r"(?m)^OK\s*\(\s*1 test(?:,|\))", text):
            result = "PASS"
        elif exit_code not in (None, 0) and stage != "UNKNOWN":
            result = "GUARD_REJECTED"
        elif exit_code not in (None, 0) and re.search(r"(?m)^ERRORS!\s*$", text):
            result = "TEST_ERROR"
        elif exit_code not in (None, 0) and re.search(r"(?m)^FAILURES!\s*$", text):
            result = "TEST_FAILURE"
    return {
        "result": result,
        "stage": stage,
        "predicate": predicate,
        "assertion_checkpoint": assertion_checkpoint,
        "phpunit_exit": "UNKNOWN" if exit_code is None else str(exit_code),
    }


def recipient_is_approved() -> bool:
    calculated = "sha256:" + hashlib.sha256(AGE_RECIPIENT.encode("ascii")).hexdigest()
    return hmac.compare_digest(calculated, AGE_RECIPIENT_FINGERPRINT)


def private_child(candidate: Path, parent: Path, prefix: str) -> bool:
    if candidate.is_symlink() or not candidate.is_dir() or not candidate.is_absolute():
        return False
    if not candidate.name.startswith(prefix):
        return False
    try:
        return candidate.resolve(strict=True).parent == parent.resolve(strict=True)
    except (OSError, RuntimeError):
        return False


def cleanup_age_resources(
    root: Path | None,
    runner_temp: Path,
    php_version: str,
    artifact_owned: str,
    credential_owned: str,
    unlinker: Callable[[Path], None] = Path.unlink,
) -> tuple[bool, str, str, str]:
    age_status = "INVALID_OR_MISSING_ROOT"
    age_ok = False
    valid_root = root is not None and (
        re.fullmatch(r"wpcg-age-v1\.3\.2\.[A-Za-z0-9]{6}", root.name) is not None
        and private_child(root, runner_temp, "wpcg-age-v1.3.2.")
    )
    if valid_root and root is not None:
        archive = root / "age-v1.3.2-linux-amd64.tar.gz"
        extract = root / "extract"
        age_dir = extract / "age"
        age_bin = age_dir / "age"
        file_status = unlink_owned((("archive", archive), ("age_binary", age_bin)), unlinker)
        directory_status: list[str] = []
        for path in (age_dir, extract, root):
            try:
                path.rmdir()
                directory_status.append("REMOVED")
            except FileNotFoundError:
                directory_status.append("ALREADY_ABSENT")
            except OSError:
                directory_status.append("FAILED")
        age_absent = not root.exists() and not archive.exists() and not age_bin.exists()
        age_ok = "FAILED" not in file_status.values() and "FAILED" not in directory_status and age_absent
        age_status = "PASS" if age_ok else "FAILED"

    artifact_path = runner_temp / f"wpcg-package-b-guard-{php_version}.age"
    if artifact_owned == "true":
        artifact_status = unlink_owned((("encrypted_artifact", artifact_path),), unlinker)["encrypted_artifact"]
    elif artifact_owned == "false":
        artifact_status = "NOT_OWNED"
    else:
        # Ownership uncertainty must not prevent cleanup of the independently validated age directory.
        artifact_status = "UNKNOWN"

    credential = runner_temp / "wpcg-package-b-guard-db-credential"
    credential_status = "ALREADY_ABSENT"
    try:
        if not credential.exists() and not credential.is_symlink():
            credential_status = "ALREADY_ABSENT"
        elif credential_owned not in {"true", "false"}:
            credential_status = "UNKNOWN"
        elif credential_owned != "true":
            credential_status = "UNKNOWN"
        elif credential.is_symlink() or not credential.is_file():
            credential_status = "FAILED"
        else:
            unlinker(credential)
            credential_status = "REMOVED" if not credential.exists() else "FAILED"
    except FileNotFoundError:
        credential_status = "ALREADY_ABSENT"
    except OSError:
        credential_status = "FAILED"

    overall_ok = age_ok and artifact_status not in {"UNKNOWN", "FAILED"} and credential_status in {"REMOVED", "ALREADY_ABSENT"}
    return overall_ok, age_status, artifact_status, credential_status


def unlink_owned(
    paths: tuple[tuple[str, Path], ...],
    unlinker: Callable[[Path], None] = Path.unlink,
) -> dict[str, str]:
    outcomes: dict[str, str] = {}
    for name, path in paths:
        try:
            unlinker(path)
            outcomes[name] = "REMOVED"
        except FileNotFoundError:
            outcomes[name] = "ALREADY_ABSENT"
        except OSError:
            outcomes[name] = "FAILED"
    return outcomes


def capture_bounded(command: list[str], output: Path, timeout: int, limit: int, env: dict[str, str]) -> tuple[int | None, bool, bool]:
    truncated = False
    exit_code: int | None = None
    timed_out = False
    started = time.monotonic()
    with output.open("xb") as stream:
        os.chmod(output, 0o600)
        process = subprocess.Popen(
            command, cwd=ROOT, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT, env=env, start_new_session=True,
        )
        assert process.stdout is not None
        selector = selectors.DefaultSelector()
        selector.register(process.stdout, selectors.EVENT_READ)
        try:
            while selector.get_map():
                remaining = timeout - (time.monotonic() - started)
                if remaining <= 0:
                    timed_out = True
                    break
                events = selector.select(min(remaining, 1.0))
                if not events and process.poll() is not None:
                    break
                for key, _ in events:
                    chunk = os.read(key.fd, 65536)
                    if not chunk:
                        selector.unregister(key.fileobj)
                        continue
                    room = max(0, limit + 1 - stream.tell())
                    if room:
                        stream.write(chunk[:room])
                    if len(chunk) > room:
                        truncated = True
            if timed_out:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except OSError:
                    pass
            exit_code = process.wait(timeout=10)
        except (OSError, subprocess.SubprocessError):
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except OSError:
                pass
            process.wait()
            raise
        finally:
            selector.close()
            process.stdout.close()
            stream.flush()
    return exit_code, truncated, timed_out


def emit_github_output(values: dict[str, str]) -> bool:
    output = os.environ.get("GITHUB_OUTPUT")
    if not output:
        return False
    try:
        with Path(output).open("a", encoding="ascii") as stream:
            stream.write(f"artifact_created={values['artifact_created']}\n")
            stream.write(f"artifact_owned={values['artifact_owned']}\n")
        return True
    except OSError:
        return False


def self_test() -> int:
    checks = 0

    def check(value: bool) -> None:
        nonlocal checks
        checks += 1
        if not value:
            raise RuntimeError("SYNTHETIC_CHECK_FAILED")

    check(parse_selection(("- " + TEST_ID + "\n").encode()) == (1, True))
    check(parse_selection(b"") == (0, False))
    check(parse_selection(("- " + TEST_ID + "\n- " + TEST_ID + "\n").encode()) == (2, False))
    check(parse_selection(("- " + TEST_ID + "\n- Other::test\n").encode()) == (1, False))
    check(parse_selection(b"\xff") == (0, False))
    check(parse_selection(b"x" * (MAX_SELECTION_BYTES + 1)) == (0, False))
    common_checkpoints = b"".join(
        ASSERTION_CHECKPOINT_PREFIX + code.encode("ascii") + b"\n" for code in COMMON_ASSERTION_CHECKPOINTS
    )
    rejection_checkpoints = b"".join(
        ASSERTION_CHECKPOINT_PREFIX + code.encode("ascii") + b"\n" for code in ASSERTION_CHECKPOINT_PATHS[1]
    )
    caught_checkpoints = b"".join(
        ASSERTION_CHECKPOINT_PREFIX + code.encode("ascii") + b"\n"
        for code in ASSERTION_CHECKPOINT_PATHS[1][:-3]
    )
    accepted_checkpoints = b"".join(
        ASSERTION_CHECKPOINT_PREFIX + code.encode("ascii") + b"\n" for code in ASSERTION_CHECKPOINT_PATHS[0]
    )
    check(parse_assertion_checkpoint(rejection_checkpoints) == "INVALID_DIGEST_NOT_PERSISTED")
    check(parse_assertion_checkpoint(caught_checkpoints) == "INVALID_DIGEST_GUARD_ASSERTION")
    check(parse_assertion_checkpoint(accepted_checkpoints) == "INVALID_DIGEST_ACCEPTED")
    partial_checkpoints = b"".join(
        ASSERTION_CHECKPOINT_PREFIX + code.encode("ascii") + b"\n" for code in COMMON_ASSERTION_CHECKPOINTS[:8]
    )
    check(parse_assertion_checkpoint(partial_checkpoints) == "CREATE_MICROSECONDS")
    check(parse_assertion_checkpoint(b"") == "UNKNOWN")
    check(parse_assertion_checkpoint(ASSERTION_CHECKPOINT_PREFIX + b"UNAPPROVED\n") == "UNKNOWN")
    check(parse_assertion_checkpoint(ASSERTION_CHECKPOINT_PREFIX + b"TEST_STARTED\n" + ASSERTION_CHECKPOINT_PREFIX + b"TEST_STARTED\n") == "UNKNOWN")
    check(parse_assertion_checkpoint(ASSERTION_CHECKPOINT_PREFIX + b"CREATE_RPC\n") == "UNKNOWN")
    check(parse_assertion_checkpoint(b"prefix " + ASSERTION_CHECKPOINT_PREFIX + b"TEST_STARTED\n") == "UNKNOWN")
    event = b"InvalidArgumentException: Invalid append-only lease state. [CREATE_STATE_SAVE:STATE_DIGEST_MISMATCH]"
    projected = project(event, 2, True, False)
    check(projected["stage"] == "CREATE_STATE_SAVE" and projected["predicate"] == "STATE_DIGEST_MISMATCH")
    check(project(b"Invalid append-only lease state. [CREATE_STATE_SAVE:SECRET_VALUE]", 2, True, False)["predicate"] == "UNKNOWN")
    check(project(event, 2, False, False)["stage"] == "UNKNOWN")
    check(project(event, 2, True, True)["predicate"] == "UNKNOWN")
    status_index = COMMON_ASSERTION_CHECKPOINTS.index("CREATE_STATUS") + 1
    status_prefix = b"".join(
        ASSERTION_CHECKPOINT_PREFIX + code.encode("ascii") + b"\n"
        for code in COMMON_ASSERTION_CHECKPOINTS[:status_index]
    )
    checkpoint_projection = project(status_prefix, 1, True, False)
    check(checkpoint_projection["assertion_checkpoint"] == "CREATE_STATUS")
    started_prefix = ASSERTION_CHECKPOINT_PREFIX + b"TEST_STARTED\n"
    safe_projection = project(
        b"private-value\n" + started_prefix, 1, True, False,
    )
    check(safe_projection["assertion_checkpoint"] == "TEST_STARTED")
    check("private-value" not in str(safe_projection))
    check(parse_assertion_checkpoint(common_checkpoints + ASSERTION_CHECKPOINT_PREFIX + b"INVALID_DIGEST_CREATE_RETURNED\n" + ASSERTION_CHECKPOINT_PREFIX + b"INVALID_DIGEST_REJECTION\n") == "UNKNOWN")
    check(parse_assertion_checkpoint(common_checkpoints + ASSERTION_CHECKPOINT_PREFIX + b"INVALID_DIGEST_REJECTION\n" + ASSERTION_CHECKPOINT_PREFIX + b"INVALID_DIGEST_ACCEPTED\n") == "UNKNOWN")
    check(project(b"OK (1 test, 3 assertions)\n", 0, True, False)["result"] == "PASS")
    check(project(event, 2, True, False)["result"] == "GUARD_REJECTED")
    check(recipient_is_approved())
    check(not hmac.compare_digest(AGE_RECIPIENT, AGE_RECIPIENT + "x"))
    for code in sorted(PREDICATES):
        projected = project(f"Invalid append-only lease state. [CLAIM_STATE_SAVE:{code}]".encode(), 2, True, False)
        check(projected["stage"] == "CLAIM_STATE_SAVE" and projected["predicate"] == code)
    for stage in sorted(STAGES):
        projected = project(f"Invalid append-only lease state. [{stage}:STATE_DIGEST_MISMATCH]".encode(), 2, True, False)
        check(projected["stage"] == stage)

    with tempfile.TemporaryDirectory(prefix="wpcg-guard-selftest-") as temporary:
        parent = Path(temporary)
        scenarios = (
            ("success", None, None, "REMOVED", "REMOVED"),
            ("first_failed", "left", None, "FAILED", "REMOVED"),
            ("second_failed", None, "right", "REMOVED", "FAILED"),
            ("both_failed", "left", "right", "FAILED", "FAILED"),
            ("first_absent", "absent-left", None, "ALREADY_ABSENT", "REMOVED"),
            ("second_absent", None, "absent-right", "REMOVED", "ALREADY_ABSENT"),
        )
        for name, fail_one, fail_two, expected_left, expected_right in scenarios:
            left, right = parent / f"{name}-left", parent / f"{name}-right"
            left.write_text("synthetic", encoding="ascii")
            right.write_text("synthetic", encoding="ascii")
            attempts: list[Path] = []

            def synthetic_unlink(path: Path) -> None:
                attempts.append(path)
                if (fail_one == "left" and path == left) or (fail_two == "right" and path == right):
                    raise PermissionError
                if (fail_one == "absent-left" and path == left) or (fail_two == "absent-right" and path == right):
                    raise FileNotFoundError
                path.unlink()

            outcomes = unlink_owned((("left", left), ("right", right)), synthetic_unlink)
            check(attempts == [left, right])
            check(outcomes == {"left": expected_left, "right": expected_right})

        age_root = parent / "wpcg-age-v1.3.2.ABC123"
        age_dir = age_root / "extract" / "age"
        age_dir.mkdir(parents=True)
        (age_root / "age-v1.3.2-linux-amd64.tar.gz").write_bytes(b"synthetic")
        (age_dir / "age").write_bytes(b"synthetic")
        credential = parent / "wpcg-package-b-guard-db-credential"
        credential.write_text("synthetic", encoding="ascii")
        credential = parent / "wpcg-package-b-guard-db-credential"
        credential.write_text("synthetic", encoding="ascii")
        overall, age_state, artifact_state, credential_state = cleanup_age_resources(age_root, parent, "8.4", "", "true")
        check(age_state == "PASS" and artifact_state == "UNKNOWN" and credential_state == "REMOVED" and not overall)
        check(not age_root.exists() and not credential.exists())

        foreign_root = parent / "foreign"
        foreign_root.mkdir()
        overall, age_state, artifact_state, credential_state = cleanup_age_resources(foreign_root, parent, "8.4", "false", "false")
        check(not overall and age_state == "INVALID_OR_MISSING_ROOT" and artifact_state == "NOT_OWNED")
        check(foreign_root.is_dir())
        foreign_root.rmdir()

        credential.write_text("synthetic", encoding="ascii")
        overall, age_state, artifact_state, credential_state = cleanup_age_resources(None, parent, "8.4", "false", "")
        check(not overall and age_state == "INVALID_OR_MISSING_ROOT" and credential_state == "UNKNOWN")
        check(credential.is_file())
        credential.unlink()

    # The externally emitted projection is a fixed-code tuple only; hostile source text is never returned.
    hostile = b"secret lease-id SQL SELECT credential raw-message"
    rendered = str(project(hostile, 2, True, False))
    check(all(value not in rendered for value in ("secret", "lease-id", "SELECT", "credential", "raw-message")))
    print(f"GUARD_DIAGNOSTIC_SELF_TEST=PASS checks={checks}")
    return 0


def run_once(php_version: str, age_bin: Path) -> int:
    runner_temp = Path(os.environ.get("RUNNER_TEMP", tempfile.gettempdir())).resolve()
    private_dir: Path | None = None
    selection_path: Path | None = None
    raw_path: Path | None = None
    probe_plain: Path | None = None
    probe_cipher: Path | None = None
    artifact_path = runner_temp / f"wpcg-package-b-guard-{php_version}.age"
    selection_state = "NOT_RUN"
    selection_count = "UNKNOWN"
    process_exit: int | None = None
    capture_state = "NOT_RUN"
    result = {
        "result": "INCONCLUSIVE", "stage": "UNKNOWN", "predicate": "UNKNOWN",
        "assertion_checkpoint": "UNKNOWN", "phpunit_exit": "NOT_RUN",
    }
    encrypted = False
    artifact_owned = False
    cleanup_ok = False
    setup_state = "PASS"

    try:
        if not recipient_is_approved():
            setup_state = "RECIPIENT_REJECTED"
            raise RuntimeError("RECIPIENT_REJECTED")
        if artifact_path.exists() or artifact_path.is_symlink():
            setup_state = "ARTIFACT_PATH_OCCUPIED"
            raise RuntimeError("ARTIFACT_PATH_OCCUPIED")
        private_dir = Path(tempfile.mkdtemp(prefix=f"wpcg-guard-{php_version}-", dir=runner_temp))
        private_dir.chmod(0o700)
        selection_path = private_dir / "selection.raw"
        raw_path = private_dir / "phpunit.raw"
        probe_plain = private_dir / "age-probe.txt"
        probe_cipher = private_dir / "age-probe.age"
        probe_plain.write_bytes(b"WPCAREGRID_SYNTHETIC_TEST")
        os.chmod(probe_plain, 0o600)
        probe = subprocess.run(
            [str(age_bin), "-r", AGE_RECIPIENT, "-o", str(probe_cipher), str(probe_plain)],
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            check=False, timeout=AGE_TIMEOUT, env=child_environment(False, private_dir),
        )
        encrypted = probe.returncode == 0 and probe_cipher.is_file() and probe_cipher.stat().st_size > 0
        if not encrypted:
            setup_state = "AGE_PROBE_FAILED"
            raise RuntimeError("AGE_PROBE_FAILED")
        probe_cleanup = unlink_owned((("probe_plain", probe_plain), ("probe_cipher", probe_cipher)))
        if "FAILED" in probe_cleanup.values():
            setup_state = "AGE_PROBE_CLEANUP_FAILED"
            raise RuntimeError("AGE_PROBE_CLEANUP_FAILED")
        probe_plain = probe_cipher = None

        phpunit = ROOT / "vendor/bin/phpunit"
        config = ROOT / "phpunit.xml"
        test_file = ROOT / TEST_FILE
        selection_exit, selection_truncated, selection_timeout = capture_bounded(
            [str(phpunit), "-c", str(config), "--list-tests", "--filter", TEST_METHOD, str(test_file)],
            selection_path, SELECTION_TIMEOUT, MAX_SELECTION_BYTES,
            child_environment(False, private_dir),
        )
        selected_count, exact = parse_selection(selection_path.read_bytes()) if selection_path.stat().st_size <= MAX_SELECTION_BYTES else (0, False)
        selection_count = str(selected_count) if exact else "UNKNOWN"
        selection_state = "EXACTLY_ONE" if exact else "NOT_EXACTLY_ONE"
        if selection_exit != 0 or selection_truncated or selection_timeout or not exact or selected_count != 1:
            result["result"] = "SELECTION_FAILED"
            raise RuntimeError("SELECTION_FAILED")

        process_exit, truncated, timed_out = capture_bounded(
            [str(phpunit), "-c", str(config), "--debug", "--fail-on-skipped", "--filter", TEST_METHOD, str(test_file)],
            raw_path, TEST_TIMEOUT, MAX_CAPTURE_BYTES,
            child_environment(True, private_dir),
        )
        capture_state = "TRUNCATED" if truncated else "TIMEOUT" if timed_out else "COMPLETE"
        result = project(raw_path.read_bytes(), process_exit, True, truncated or timed_out)
        result_path_ready = raw_path.is_file() and raw_path.stat().st_size > 0
        if result_path_ready:
            artifact_owned = True
            encrypt_result = subprocess.run(
                [str(age_bin), "-r", AGE_RECIPIENT, "-o", str(artifact_path), str(raw_path)],
                stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                check=False, timeout=AGE_TIMEOUT, env=child_environment(False, private_dir),
            )
            encrypted = encrypt_result.returncode == 0 and artifact_path.is_file() and artifact_path.stat().st_size > 0
        else:
            encrypted = False
    except (OSError, RuntimeError, subprocess.SubprocessError, ValueError):
        if process_exit is not None:
            result = {
                "result": "INCONCLUSIVE", "stage": "UNKNOWN", "predicate": "UNKNOWN",
                "assertion_checkpoint": "UNKNOWN", "phpunit_exit": str(process_exit),
            }
    finally:
        owned = tuple((name, path) for name, path in (
            ("selection", selection_path), ("phpunit_raw", raw_path),
            ("age_probe_plain", probe_plain), ("age_probe_cipher", probe_cipher),
        ) if path is not None)
        cleanup_files = unlink_owned(owned)
        cleanup_dir = "ALREADY_ABSENT"
        if private_dir is not None:
            try:
                private_dir.rmdir()
                cleanup_dir = "REMOVED"
            except FileNotFoundError:
                cleanup_dir = "ALREADY_ABSENT"
            except OSError:
                cleanup_dir = "FAILED"
        cleanup_ok = "FAILED" not in cleanup_files.values() and cleanup_dir != "FAILED" and private_dir is not None and not private_dir.exists()
        if not cleanup_ok and artifact_owned:
            unlink_owned((("encrypted_artifact", artifact_path),))
            encrypted = False
        if artifact_owned and encrypted and cleanup_ok:
            artifact_created = "true"
        else:
            artifact_created = "false"
        output_ok = emit_github_output({"artifact_created": artifact_created, "artifact_owned": "true" if artifact_owned else "false"})

    if not output_ok:
        artifact_created = "false"
    fixed = (
        f"PACKAGE_B_GUARD_DIAG php={php_version} selection={selection_state} selection_count={selection_count} "
        f"setup={setup_state} result={result['result']} phpunit_exit={result['phpunit_exit']} "
        f"stage={result['stage']} predicate={result['predicate']} checkpoint={result['assertion_checkpoint']} capture={capture_state} "
        f"encryption={'PASS' if encrypted else 'FAIL'} artifact_created={'YES' if artifact_created == 'true' else 'NO'} "
        f"cleanup={'PASS' if cleanup_ok else 'FAIL'}"
    )
    print(fixed)
    if not cleanup_ok or not output_ok or not encrypted:
        return process_exit if process_exit not in (None, 0) else 1
    return process_exit if process_exit is not None else 1


def verify_upload(outcome: str, artifact_id: str, digest: str, php_version: str) -> int:
    path = Path(os.environ.get("RUNNER_TEMP", tempfile.gettempdir())) / f"wpcg-package-b-guard-{php_version}.age"
    valid = (
        outcome == "success"
        and re.fullmatch(r"[0-9]{1,20}", artifact_id or "") is not None
        and re.fullmatch(r"[0-9a-fA-F]{64}", digest or "") is not None
        and path.is_file() and not path.is_symlink() and path.stat().st_size > 0
    )
    print(f"ENCRYPTED_ARTIFACT_UPLOAD={'PASS' if valid else 'FAIL'}")
    return 0 if valid else 1


def cleanup_age(age_root_arg: str, php_version: str, artifact_owned: str) -> int:
    runner_temp = Path(os.environ.get("RUNNER_TEMP", "")).resolve()
    root = Path(age_root_arg) if age_root_arg else None
    credential_owned = os.environ.get("WPCG_DB_CREDENTIAL_OWNED", "")
    passed, age_status, artifact_status, credential_status = cleanup_age_resources(
        root, runner_temp, php_version, artifact_owned, credential_owned,
    )
    print(f"AGE_TEMP_CLEANUP={age_status} ARTIFACT_CLEANUP={artifact_status} DB_CREDENTIAL_CLEANUP={credential_status}")
    return 0 if passed else 1


def main() -> int:
    parser = argparse.ArgumentParser()
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--self-test", action="store_true")
    modes.add_argument("--run", action="store_true")
    modes.add_argument("--verify-upload", action="store_true")
    modes.add_argument("--cleanup-age", action="store_true")
    parser.add_argument("--php-version", choices=("8.4", "8.5"))
    parser.add_argument("--age-bin")
    parser.add_argument("--upload-outcome")
    parser.add_argument("--artifact-id")
    parser.add_argument("--artifact-digest")
    parser.add_argument("--age-root")
    parser.add_argument("--artifact-owned")
    args = parser.parse_args()
    try:
        if args.self_test:
            return self_test()
        if args.php_version is None:
            print("DIAGNOSTIC_ARGUMENTS=INVALID")
            return 1
        if args.run:
            if not args.age_bin:
                print("AGE_BINARY=UNAVAILABLE")
                return 1
            return run_once(args.php_version, Path(args.age_bin))
        if args.verify_upload:
            return verify_upload(args.upload_outcome or "", args.artifact_id or "", args.artifact_digest or "", args.php_version)
        return cleanup_age(args.age_root or "", args.php_version, args.artifact_owned or "")
    except Exception:
        print("DIAGNOSTIC_HELPER=FAILED")
        return 1


if __name__ == "__main__":
    sys.exit(main())
