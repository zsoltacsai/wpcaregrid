#!/usr/bin/python3 -I
"""Versioned production-only entrypoint for the shared deployment backend."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import os
import pwd
import re
import stat
import sys
import types
from pathlib import Path

sys.dont_write_bytecode = True

VERSION = "2.0.0"
CONTRACT = "wpcaregrid-deployment-profile-v2"
ENTRYPOINT = Path("/usr/local/libexec/wpcaregrid-deploy/production/2.0.0/wpcaregrid-deploy-production-priv")
BACKEND = ENTRYPOINT.parent / "deployment_backend_v2.py"
TRANSACTION = ENTRYPOINT.parent / "deployment_transaction_v2.py"
ENVIRONMENT_CONTRACT = ENTRYPOINT.parent / "deployment_environment_contract.py"
POLICY = Path("/etc/wpcaregrid-deploy/profiles/production-v2.0.0.json")
OPERATIONS = ["authorize", "deploy", "preflight", "stage", "verify-install"]
SHA256_RE = re.compile(r"[0-9a-f]{64}\Z")
SHA_RE = re.compile(r"[0-9a-f]{40}\Z")


class Refused(Exception):
    """Fixed, sanitized refusal at the production privilege boundary."""


def _read_trusted(path: Path, *, mode: int, maximum: int) -> bytes:
    try:
        before = os.lstat(path)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0
                or stat.S_IMODE(before.st_mode) != mode or before.st_size > maximum):
            raise Refused("artifact_metadata_invalid")
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0))
        try:
            opened = os.fstat(fd)
            if (opened.st_ino != before.st_ino or opened.st_dev != before.st_dev
                    or opened.st_uid != 0 or stat.S_IMODE(opened.st_mode) != mode
                    or opened.st_size != before.st_size or opened.st_size > maximum):
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


def _load_environment_module(raw: bytes):
    module_name = "_wpcg_deployment_environment_contract_v2"
    module = types.ModuleType(module_name)
    module.__file__ = str(ENVIRONMENT_CONTRACT)
    sys.modules[module_name] = module
    try:
        exec(compile(raw, str(ENVIRONMENT_CONTRACT), "exec", dont_inherit=True), module.__dict__)
    except BaseException:
        sys.modules.pop(module_name, None)
        raise Refused("environment_contract_load_failed") from None
    return module


def _load_transaction(raw: bytes):
    """Load the already hash-verified transaction bytes for backend imports."""
    module_name = "_wpcg_deployment_transaction_v2"
    module = types.ModuleType(module_name)
    module.__file__ = str(TRANSACTION)
    sys.modules[module_name] = module
    try:
        exec(compile(raw, str(TRANSACTION), "exec", dont_inherit=True), module.__dict__)
    except BaseException:
        sys.modules.pop(module_name, None)
        raise Refused("deployment_transaction_load_failed") from None
    if not callable(getattr(module, "bind_environment_contract", None)):
        sys.modules.pop(module_name, None)
        raise Refused("deployment_transaction_contract_invalid")
    return module


def _load_backend(raw: bytes):
    module_name = "_wpcg_deployment_backend_v2"
    module = types.ModuleType(module_name)
    module.__file__ = str(BACKEND)
    sys.modules[module_name] = module
    try:
        exec(compile(raw, str(BACKEND), "exec", dont_inherit=True), module.__dict__)
    except BaseException:
        sys.modules.pop(module_name, None)
        raise Refused("deployment_backend_load_failed") from None
    if module.HELPER_VERSION != VERSION or module.HELPER_CONTRACT != CONTRACT:
        raise Refused("deployment_backend_contract_mismatch")
    return module


def _environment_config(policy: object, contract_fields: frozenset[str]) -> dict[str, object]:
    if not isinstance(policy, dict) or not contract_fields.issubset(policy):
        raise Refused("production_policy_schema_invalid")
    return {key: policy[key] for key in contract_fields}


def _parse_operation(argv: list[str]) -> tuple[str, str | None, str | None]:
    operation = argv[0] if argv else ""
    if operation in {"preflight", "verify-install"} and len(argv) == 1:
        return operation, None, None
    if operation == "authorize" and len(argv) == 2 and SHA_RE.fullmatch(argv[1]):
        return operation, argv[1], None
    if operation in {"stage", "deploy"} and len(argv) == 3 \
            and SHA_RE.fullmatch(argv[1]) and SHA256_RE.fullmatch(argv[2]):
        return operation, argv[1], argv[2]
    raise Refused("operation_not_allowed")


def _load_profile(argv: list[str], sudo_uid: str | None):
    if sys.argv[0] != str(ENTRYPOINT):
        raise Refused("unknown_entrypoint")
    _trusted_parent(ENTRYPOINT)
    _trusted_parent(POLICY)
    entry_raw = _read_trusted(ENTRYPOINT, mode=0o755, maximum=128_000)
    backend_raw = _read_trusted(BACKEND, mode=0o644, maximum=1_000_000)
    transaction_raw = _read_trusted(TRANSACTION, mode=0o644, maximum=1_000_000)
    environment_raw = _read_trusted(ENVIRONMENT_CONTRACT, mode=0o644, maximum=128_000)

    try:
        policy = json.loads(_read_trusted(POLICY, mode=0o644, maximum=32_768).decode("utf-8"))
    except (UnicodeError, ValueError):
        raise Refused("production_policy_invalid") from None
    environment_module = _load_environment_module(environment_raw)
    contract_fields = environment_module.production_required_fields()
    policy_keys = set(contract_fields) | {
        "entrypoint_sha256", "backend_sha256", "transaction_module_sha256",
        "environment_contract_sha256", "helper_artifact_sha256", "phase_b_policy_sha256", "operations",
    }
    if not isinstance(policy, dict) or set(policy) != policy_keys:
        raise Refused("production_policy_schema_invalid")
    if (policy.get("enabled") is not True or policy.get("profile") != "production"
            or policy.get("schema") != 1 or policy.get("operations") != OPERATIONS):
        raise Refused("production_profile_disabled_or_invalid")
    source_hashes = {
        "entrypoint_sha256": hashlib.sha256(entry_raw).hexdigest(),
        "backend_sha256": hashlib.sha256(backend_raw).hexdigest(),
        "transaction_module_sha256": hashlib.sha256(transaction_raw).hexdigest(),
        "environment_contract_sha256": hashlib.sha256(environment_raw).hexdigest(),
    }
    if any(policy.get(key) != value for key, value in source_hashes.items()):
        raise Refused("production_artifact_identity_mismatch")
    phase_policy_sha256 = policy.get("phase_b_policy_sha256")
    if not isinstance(phase_policy_sha256, str) or SHA256_RE.fullmatch(phase_policy_sha256) is None:
        raise Refused("production_phase_b_policy_unpinned")
    material = json.dumps({
        "schema": 1, "helper_version": VERSION, "compatibility_contract": CONTRACT,
        **source_hashes,
    }, sort_keys=True, separators=(",", ":")).encode("utf-8")
    artifact_digest = hashlib.sha256(material).hexdigest()
    if policy.get("helper_artifact_sha256") != artifact_digest:
        raise Refused("production_helper_manifest_mismatch")
    uid = policy.get("deploy_uid")
    deploy_user = policy.get("deploy_user")
    if (deploy_user != "deploy-production" or type(uid) is not int or uid <= 0
            or sudo_uid != str(uid)):
        raise Refused("production_caller_identity_invalid")
    if os.geteuid() != 0:
        raise Refused("privileged_identity_required")
    try:
        if pwd.getpwuid(uid).pw_name != deploy_user:
            raise Refused("production_caller_identity_invalid")
    except KeyError:
        raise Refused("production_caller_identity_invalid") from None

    try:
        environment_config = _environment_config(policy, contract_fields)
        environment = environment_module.resolve_environment("production", production_config=environment_config)
    except environment_module.EnvironmentRefused as error:
        raise Refused(str(error)) from None
    # deployment_backend_v2 imports this fixed module name. Register the exact
    # bytes verified above before loading the backend so it cannot reopen a
    # different on-disk transaction module after provenance verification.
    _load_transaction(transaction_raw)
    backend = _load_backend(backend_raw)
    profile = backend.profile_for_environment(
        environment, helper_artifact_sha256=artifact_digest,
        phase_b_policy_sha256=phase_policy_sha256, expected_owner_uid=0,
    )
    operation, app_sha, artifact_sha = _parse_operation(argv)
    if operation not in OPERATIONS:
        raise Refused("operation_not_allowed")
    return backend, profile, uid, operation, app_sha, artifact_sha


def main(argv=None) -> int:
    args = list(sys.argv[1:] if argv is None else argv)
    sudo_uid = os.environ.get("SUDO_UID")
    os.environ.clear()
    try:
        backend, profile, uid, operation, app_sha, artifact_sha = _load_profile(args, sudo_uid)
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
            payload = {"result": result.status, "reason": result.reason,
                       "application_sha": result.application_sha, "previous_sha": result.previous_sha,
                       "artifact_sha256": result.artifact_sha256, "receipt_path": result.receipt_path,
                       "receipt_sha256": result.receipt_sha256, "profile": "production",
                       "helper_version": VERSION, "helper_contract": CONTRACT,
                       "helper_artifact_sha256": profile.helper_artifact_sha256}
            status = result.status
        else:
            payload = dict(result)
            status = payload.get("result")
        sys.stdout.write(json.dumps(payload, sort_keys=True, separators=(",", ":")) + "\n")
        return 0 if status in {"PASS", "NO_OP"} else 3 if status == "HOLD" else 4
    except Refused as error:
        sys.stderr.write(f"DEPLOYMENT=HOLD;CODE={error}\n")
        return 3
    except BaseException:
        sys.stderr.write("DEPLOYMENT=HOLD;CODE=production_helper_unavailable\n")
        return 3


if __name__ == "__main__":
    raise SystemExit(main())
