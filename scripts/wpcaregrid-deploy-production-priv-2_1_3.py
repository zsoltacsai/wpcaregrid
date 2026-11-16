#!/usr/bin/python3 -I
"""Versioned production-only entrypoint (helper 2.1.3) for the 2.1.3 backend.

Successor of helper 2.1.2 (unchanged, never installed in place). Production
privileged operations are invoked **directly by the Owner-controlled root
console**. There is no production sudoers rule and this entrypoint refuses any
sudo-, pkexec- or doas-originated invocation as well as any non-root real or
effective identity. The existing ``deploy`` account is only the untrusted
transfer identity named in the production policy.

The release-review V3 admission, exact latest protected-primary SHA, artifact
receipt and Phase B gates are those of backend 2.1.3 (unchanged from 2.1.2).
"""

from __future__ import annotations

import hashlib
import json
import os
import pwd
import re
import stat
import sys
import types
from pathlib import Path

sys.dont_write_bytecode = True

VERSION = "2.1.3"
CONTRACT = "wpcaregrid-deployment-profile-v2.2"
ENTRYPOINT = Path("/usr/local/libexec/wpcaregrid-deploy/production/2.1.3/wpcaregrid-deploy-production-priv")
BACKEND = ENTRYPOINT.parent / "deployment_backend_v2_1_3.py"
TRANSACTION = ENTRYPOINT.parent / "deployment_transaction_v2.py"
ENVIRONMENT_CONTRACT = ENTRYPOINT.parent / "deployment_environment_contract_2_1_3.py"
REVIEW_POLICY = ENTRYPOINT.parent / "release_review_policy_v3.py"
POLICY = Path("/etc/wpcaregrid-deploy/profiles/production-v2.1.3.json")
OPERATIONS = ["authorize", "deploy", "preflight", "stage", "verify-install"]
TRANSFER_USER = "deploy"
ENVIRONMENT_MODULE_NAME = "_wpcg_deployment_environment_contract_2_1_3"
# Variables set by privilege-delegation tools. Production has no delegated path.
DELEGATION_VARIABLES = frozenset({
    "SUDO_UID", "SUDO_GID", "SUDO_USER", "SUDO_COMMAND", "SUDO_HOME", "PKEXEC_UID", "DOAS_USER",
})
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


def _require_root_console(environ: dict[str, str], *, uid: int, euid: int, gid: int, egid: int,
                          isolated: int) -> None:
    """Only an Owner root console may run production operations; never sudo."""
    if DELEGATION_VARIABLES & set(environ):
        raise Refused("delegated_invocation_refused")
    if uid != 0 or euid != 0 or gid != 0 or egid != 0:
        raise Refused("root_console_required")
    if isolated != 1:
        raise Refused("isolated_interpreter_required")


def _exec_module(module_name: str, path: Path, raw: bytes, failure: str):
    module = types.ModuleType(module_name)
    module.__file__ = str(path)
    sys.modules[module_name] = module
    try:
        exec(compile(raw, str(path), "exec", dont_inherit=True), module.__dict__)
    except BaseException:
        sys.modules.pop(module_name, None)
        raise Refused(failure) from None
    return module


def _load_environment_module(raw: bytes):
    module = _exec_module(ENVIRONMENT_MODULE_NAME, ENVIRONMENT_CONTRACT, raw, "environment_contract_load_failed")
    if (getattr(module, "CONTRACT_VERSION", None) != VERSION
            or getattr(module, "PRIVILEGED_INVOCATION", None) != "root-console-direct"
            or getattr(module, "TRANSFER_USER", None) != TRANSFER_USER
            or not callable(getattr(module, "verify_confinement_observation", None))
            or not callable(getattr(module, "resolve_environment", None))):
        sys.modules.pop(ENVIRONMENT_MODULE_NAME, None)
        raise Refused("environment_contract_invalid")
    return module


def _load_review_policy(raw: bytes):
    """Register the already hash-verified V3 review-policy bytes for backend imports."""
    module_name = "_wpcg_release_review_policy_v3"
    module = _exec_module(module_name, REVIEW_POLICY, raw, "release_review_policy_load_failed")
    if (not callable(getattr(module, "verify_agent_release_reviews", None))
            or not isinstance(getattr(module, "ReviewRefused", None), type)
            or module.RECORD_PREFIX != "WPCG_RELEASE_REVIEW_V3"
            or tuple(module.ROLES) != ("integration_qa", "appsec")):
        sys.modules.pop(module_name, None)
        raise Refused("release_review_policy_contract_invalid")
    return module


def _load_transaction(raw: bytes):
    """Load the already hash-verified transaction bytes for backend imports."""
    module_name = "_wpcg_deployment_transaction_v2"
    module = _exec_module(module_name, TRANSACTION, raw, "deployment_transaction_load_failed")
    if not callable(getattr(module, "bind_environment_contract", None)):
        sys.modules.pop(module_name, None)
        raise Refused("deployment_transaction_contract_invalid")
    return module


def _load_backend(raw: bytes, review_module, environment_module):
    module_name = "_wpcg_deployment_backend_v2_1_3"
    module = _exec_module(module_name, BACKEND, raw, "deployment_backend_load_failed")
    if module.HELPER_VERSION != VERSION or module.HELPER_CONTRACT != CONTRACT:
        raise Refused("deployment_backend_contract_mismatch")
    # The backend must be bound to the exact verified module bytes, never to a
    # module re-read from disk.
    if (getattr(module, "_REVIEW_POLICY_MODULE", None) is not review_module
            or module.verify_agent_release_reviews is not review_module.verify_agent_release_reviews):
        raise Refused("release_review_policy_binding_invalid")
    if getattr(module, "_ENVIRONMENT_MODULE", None) is not environment_module:
        raise Refused("environment_contract_binding_invalid")
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


def artifact_digest(source_hashes: dict[str, str]) -> str:
    material = json.dumps({
        "schema": 1, "helper_version": VERSION, "compatibility_contract": CONTRACT,
        **source_hashes,
    }, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(material).hexdigest()


def _load_profile(argv: list[str]):
    if sys.argv[0] != str(ENTRYPOINT):
        raise Refused("unknown_entrypoint")
    operation, app_sha, artifact_sha = _parse_operation(argv)
    _trusted_parent(ENTRYPOINT)
    _trusted_parent(POLICY)
    entry_raw = _read_trusted(ENTRYPOINT, mode=0o755, maximum=128_000)
    backend_raw = _read_trusted(BACKEND, mode=0o644, maximum=1_000_000)
    transaction_raw = _read_trusted(TRANSACTION, mode=0o644, maximum=1_000_000)
    environment_raw = _read_trusted(ENVIRONMENT_CONTRACT, mode=0o644, maximum=128_000)
    review_policy_raw = _read_trusted(REVIEW_POLICY, mode=0o644, maximum=128_000)

    try:
        policy = json.loads(_read_trusted(POLICY, mode=0o644, maximum=65_536).decode("utf-8"))
    except (UnicodeError, ValueError):
        raise Refused("production_policy_invalid") from None
    source_hashes = {
        "entrypoint_sha256": hashlib.sha256(entry_raw).hexdigest(),
        "backend_sha256": hashlib.sha256(backend_raw).hexdigest(),
        "transaction_module_sha256": hashlib.sha256(transaction_raw).hexdigest(),
        "environment_contract_sha256": hashlib.sha256(environment_raw).hexdigest(),
        "release_review_policy_sha256": hashlib.sha256(review_policy_raw).hexdigest(),
    }
    # Hash identity is checked before any verified module bytes are executed.
    if not isinstance(policy, dict) or any(policy.get(key) != value for key, value in source_hashes.items()):
        raise Refused("production_artifact_identity_mismatch")
    digest = artifact_digest(source_hashes)
    if policy.get("helper_artifact_sha256") != digest:
        raise Refused("production_helper_manifest_mismatch")
    environment_module = _load_environment_module(environment_raw)
    contract_fields = environment_module.production_required_fields()
    policy_keys = set(contract_fields) | {
        "entrypoint_sha256", "backend_sha256", "transaction_module_sha256",
        "environment_contract_sha256", "release_review_policy_sha256",
        "helper_artifact_sha256", "phase_b_policy_sha256", "operations",
    }
    if set(policy) != policy_keys:
        raise Refused("production_policy_schema_invalid")
    if (policy.get("enabled") is not True or policy.get("profile") != "production"
            or policy.get("schema") != 2 or policy.get("operations") != OPERATIONS):
        raise Refused("production_profile_disabled_or_invalid")
    phase_policy_sha256 = policy.get("phase_b_policy_sha256")
    if not isinstance(phase_policy_sha256, str) or SHA256_RE.fullmatch(phase_policy_sha256) is None:
        raise Refused("production_phase_b_policy_unpinned")
    transfer_uid = policy.get("deploy_uid")
    if policy.get("deploy_user") != TRANSFER_USER or type(transfer_uid) is not int or transfer_uid <= 0:
        raise Refused("production_transfer_identity_invalid")
    try:
        if pwd.getpwuid(transfer_uid).pw_name != TRANSFER_USER:
            raise Refused("production_transfer_identity_invalid")
    except KeyError:
        raise Refused("production_transfer_identity_invalid") from None

    try:
        environment = environment_module.resolve_environment(
            "production", production_config=_environment_config(policy, contract_fields))
    except environment_module.EnvironmentRefused as error:
        raise Refused(str(error)) from None
    # The backend imports these fixed module names. Register the exact bytes
    # verified above so it cannot reopen different on-disk modules.
    _load_transaction(transaction_raw)
    review_module = _load_review_policy(review_policy_raw)
    backend = _load_backend(backend_raw, review_module, environment_module)
    profile = backend.profile_for_environment(
        environment, helper_artifact_sha256=digest,
        phase_b_policy_sha256=phase_policy_sha256, expected_owner_uid=0,
    )
    return backend, profile, operation, app_sha, artifact_sha


def main(argv=None) -> int:
    args = list(sys.argv[1:] if argv is None else argv)
    inherited = dict(os.environ)
    os.environ.clear()
    try:
        _require_root_console(inherited, uid=os.getuid(), euid=os.geteuid(), gid=os.getgid(),
                              egid=os.getegid(), isolated=sys.flags.isolated)
        backend, profile, operation, app_sha, artifact_sha = _load_profile(args)
        uid = 0
        if operation in {"preflight", "verify-install"}:
            try:
                result = (backend.preflight(profile, invoking_uid=uid) if operation == "preflight"
                          else backend.verify_helper_installation(profile, invoking_uid=uid))
            except backend.DeployRefused as error:
                raise Refused(str(error)) from None
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
