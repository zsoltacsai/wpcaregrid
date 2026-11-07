#!/usr/bin/python3 -I
"""Versioned, staging-next-only privileged deployment entrypoint (1.4.8)."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import os
import re
import stat
import sys
import types
from pathlib import Path

sys.dont_write_bytecode = True

VERSION = "1.4.8"
CONTRACT = "wpcaregrid-staging-next-deploy-v13"
ENTRYPOINT = Path("/usr/local/libexec/wpcaregrid-deploy/staging-next/1.4.8/wpcaregrid-deploy-staging-next-priv")
BACKEND = ENTRYPOINT.parent / "staging_next_deploy_backend_1_4_8.py"
TRANSACTION = ENTRYPOINT.parent / "staging_next_db_transaction_1_4_8.py"
POLICY = Path("/etc/wpcaregrid-deploy/profiles/staging-next-1.4.8.json")
POLICY_KEYS = {
    "schema", "profile", "enabled", "allowed_uid", "app_dir", "database", "host",
    "mysql_server_uuid_sha256",
    "helper_version", "compatibility_contract", "php_fpm_uid", "php_fpm_gid",
    "entrypoint_sha256", "backend_sha256", "transaction_module_sha256",
    "phase_b_policy_file", "phase_b_policy_sha256", "helper_artifact_sha256", "operations",
}
EXPECTED_TARGET = {
    "profile": "staging-next",
    "app_dir": "/var/www/wpcaregrid-staging-new",
    "database": "wpcg_staging_next",
    "host": "staging-next.wpcaregrid.com",
}
EXPECTED_OPERATIONS = ["authorize", "deploy", "preflight", "stage"]


class Refused(Exception):
    """Fixed sanitized refusal from the privileged boundary."""


def _read_trusted(path: Path, *, mode: int, maximum: int) -> bytes:
    try:
        info = os.lstat(path)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0
                or stat.S_IMODE(info.st_mode) != mode or info.st_size > maximum):
            raise Refused("artifact_metadata_invalid")
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0))
        try:
            opened = os.fstat(fd)
            if (opened.st_ino != info.st_ino or opened.st_dev != info.st_dev
                    or opened.st_uid != 0 or stat.S_IMODE(opened.st_mode) != mode
                    or opened.st_size != info.st_size or opened.st_size > maximum):
                raise Refused("artifact_changed_during_open")
            with os.fdopen(fd, "rb", closefd=False) as stream:
                raw = stream.read(maximum + 1)
            if len(raw) != opened.st_size:
                raise Refused("artifact_read_incomplete")
            return raw
        finally:
            os.close(fd)
    except Refused:
        raise
    except OSError:
        raise Refused("artifact_unavailable") from None


def _trusted_parent(path: Path) -> None:
    cursor = Path("/")
    for component in path.parts[1:-1]:
        cursor /= component
        try:
            info = os.lstat(cursor)
        except OSError:
            raise Refused("helper_parent_unavailable") from None
        if (not stat.S_ISDIR(info.st_mode) or info.st_uid != 0
                or stat.S_IMODE(info.st_mode) & 0o022):
            raise Refused("helper_parent_metadata_invalid")


def _validated_operation(argv: list[str]):
    """Reject malformed operation arguments before loading or invoking backend actions."""
    operation = argv[0] if argv else ""
    if operation in {"preflight", "verify-install"} and len(argv) == 1:
        return operation, None, None
    if operation == "authorize" and len(argv) == 2 and re.fullmatch(r"[0-9a-f]{40}", argv[1]):
        return operation, argv[1], None
    if operation in {"stage", "deploy"} and len(argv) == 3:
        if (re.fullmatch(r"[0-9a-f]{40}", argv[1])
                and re.fullmatch(r"[0-9a-f]{64}", argv[2])):
            return operation, argv[1], argv[2]
    raise Refused("operation_not_allowed")


def _load_backend(entrypoint: str, argv: list[str], sudo_uid: str | None):
    if entrypoint != str(ENTRYPOINT):
        raise Refused("unknown_entrypoint")
    _trusted_parent(ENTRYPOINT)
    _trusted_parent(POLICY)
    policy_raw = _read_trusted(POLICY, mode=0o644, maximum=4096)
    try:
        policy = json.loads(policy_raw.decode("utf-8"))
    except (UnicodeError, json.JSONDecodeError):
        raise Refused("policy_invalid") from None
    if not isinstance(policy, dict) or set(policy) != POLICY_KEYS:
        raise Refused("policy_schema_invalid")
    if (type(policy["schema"]) is not int or policy["schema"] != 1
            or policy["profile"] != EXPECTED_TARGET["profile"] or policy["enabled"] is not True
            or any(policy.get(key) != value for key, value in EXPECTED_TARGET.items())
            or policy["helper_version"] != VERSION or policy["compatibility_contract"] != CONTRACT
            or policy["operations"] != EXPECTED_OPERATIONS
            or not isinstance(policy["mysql_server_uuid_sha256"], str)
            or re.fullmatch(r"[0-9a-f]{64}", policy["mysql_server_uuid_sha256"]) is None
            or isinstance(policy["php_fpm_gid"], bool) or not isinstance(policy["php_fpm_gid"], int)
            or policy["php_fpm_gid"] < 0
            or isinstance(policy["php_fpm_uid"], bool) or not isinstance(policy["php_fpm_uid"], int)
            or policy["php_fpm_uid"] < 0
            or re.fullmatch(r"[0-9a-f]{64}", policy["helper_artifact_sha256"]) is None):
        raise Refused("policy_target_or_contract_invalid")
    uid = policy["allowed_uid"]
    if isinstance(uid, bool) or not isinstance(uid, int) or uid <= 0 or sudo_uid != str(uid):
        raise Refused("caller_identity_invalid")
    entry_raw = _read_trusted(ENTRYPOINT, mode=0o755, maximum=1_000_000)
    backend_raw = _read_trusted(BACKEND, mode=0o644, maximum=1_000_000)
    transaction_raw = _read_trusted(TRANSACTION, mode=0o644, maximum=1_000_000)
    if hashlib.sha256(entry_raw).hexdigest() != policy["entrypoint_sha256"]:
        raise Refused("entrypoint_identity_mismatch")
    if hashlib.sha256(backend_raw).hexdigest() != policy["backend_sha256"]:
        raise Refused("backend_identity_mismatch")
    if hashlib.sha256(transaction_raw).hexdigest() != policy["transaction_module_sha256"]:
        raise Refused("transaction_module_identity_mismatch")
    expected_phase_b_policy = Path("/etc/wpcaregrid-deploy/profiles/staging-next-phase-b-1.4.8.json")
    if (policy["phase_b_policy_file"] != str(expected_phase_b_policy)
            or (policy["phase_b_policy_sha256"] is not None
                and (not isinstance(policy["phase_b_policy_sha256"], str)
                     or re.fullmatch(r"[0-9a-f]{64}", policy["phase_b_policy_sha256"]) is None))):
        raise Refused("phase_b_policy_pin_invalid")
    digest_payload = json.dumps({
        "schema": 1, "helper_version": VERSION, "compatibility_contract": CONTRACT,
        "entrypoint_sha256": policy["entrypoint_sha256"], "backend_sha256": policy["backend_sha256"],
        "transaction_module_sha256": policy["transaction_module_sha256"],
    }, sort_keys=True, separators=(",", ":")).encode("utf-8")
    artifact_sha256 = hashlib.sha256(digest_payload).hexdigest()
    if artifact_sha256 != policy["helper_artifact_sha256"]:
        raise Refused("helper_artifact_identity_mismatch")
    if os.geteuid() != 0:
        raise Refused("privileged_identity_required")

    module = types.ModuleType("_wpcaregrid_staging_next_backend_v1_4_8")
    module.__file__ = str(BACKEND)
    sys.modules[module.__name__] = module
    try:
        exec(compile(backend_raw, str(BACKEND), "exec", dont_inherit=True), module.__dict__)
    except BaseException:
        sys.modules.pop(module.__name__, None)
        raise Refused("backend_load_failed") from None
    if module.HELPER_VERSION != VERSION or module.HELPER_CONTRACT != CONTRACT:
        raise Refused("backend_contract_mismatch")
    from dataclasses import replace
    profile = replace(module.PRODUCTION_PROFILE, allowed_uid=uid,
                      mysql_server_uuid_sha256=policy["mysql_server_uuid_sha256"],
                      helper_artifact_sha256=artifact_sha256, php_fpm_uid=policy["php_fpm_uid"],
                      php_fpm_gid=policy["php_fpm_gid"],
                      phase_b_policy_file=Path(policy["phase_b_policy_file"]),
                      phase_b_policy_sha256=policy["phase_b_policy_sha256"])
    operation, app_sha, artifact_sha = _validated_operation(argv)
    return module, profile, uid, operation, app_sha, artifact_sha


def main(argv=None) -> int:
    args = list(sys.argv[1:] if argv is None else argv)
    sudo_uid = os.environ.get("SUDO_UID")
    os.environ.clear()
    try:
        backend, profile, uid, operation, app_sha, artifact_sha = _load_backend(sys.argv[0], args, sudo_uid)
        if operation == "preflight":
            result = backend.preflight(profile, invoking_uid=uid)
        elif operation == "verify-install":
            result = backend.verify_helper_installation(profile, invoking_uid=uid)
        elif operation == "authorize":
            result = backend.authorize_release(profile, invoking_uid=uid, application_sha=app_sha)
        elif operation == "stage":
            result = backend.stage_artifact(profile, invoking_uid=uid,
                                            application_sha=app_sha, artifact_sha256=artifact_sha)
        else:
            result = backend.deploy_release(profile, invoking_uid=uid,
                                            application_sha=app_sha, artifact_sha256=artifact_sha)
        if hasattr(result, "status"):
            payload = {
                "result": result.status, "reason": result.reason,
                "application_sha": result.application_sha,
                "previous_sha": result.previous_sha,
                "artifact_sha256": result.artifact_sha256,
                "receipt_path": result.receipt_path,
                "receipt_sha256": result.receipt_sha256,
                "helper_version": VERSION, "helper_contract": CONTRACT,
                "helper_artifact_sha256": profile.helper_artifact_sha256,
            }
            status = result.status
        else:
            payload = dict(result)
            status = payload.get("result")
        sys.stdout.write(json.dumps(payload, sort_keys=True, separators=(",", ":")) + "\n")
        return 0 if status in {"PASS", "NO_OP"} else 3 if status == "HOLD" else 4
    except Refused as error:
        sys.stderr.write(f"DEPLOYMENT=HOLD;CODE={error}\n")
        return 3
    except Exception:
        sys.stderr.write("DEPLOYMENT=FAIL;CODE=internal_error\n")
        return 4


if __name__ == "__main__":
    raise SystemExit(main())
