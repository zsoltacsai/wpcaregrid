#!/usr/bin/env python3
"""Shared, fail-closed privilege profile contract for future deployment helpers.

The legacy staging helper is deliberately outside this registry. Profile
selection is derived from the installed absolute entrypoint, never a command
line/environment value. Production remains a compiled-in inert profile until
an independently approved production baseline exists.
"""

from __future__ import annotations

import json
import hashlib
import os
import posixpath
import re
import stat
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from types import MappingProxyType
from typing import Mapping, Sequence


class ProfileRefused(ValueError):
    """A fixed, sanitized refusal from the privileged profile boundary."""


@dataclass(frozen=True)
class DeploymentProfile:
    name: str
    entrypoint: PurePosixPath
    enabled: bool
    app_dir: str | None
    database: str | None
    host: str | None
    policy_file: PurePosixPath | None
    operations: frozenset[str]


STAGING_NEXT_ENTRYPOINT = PurePosixPath(
    "/usr/local/libexec/wpcaregrid-deploy/staging-next/1.0.0/wpcaregrid-deploy-staging-next-priv"
)
PRODUCTION_ENTRYPOINT = PurePosixPath(
    "/usr/local/libexec/wpcaregrid-deploy/production/inactive/wpcaregrid-deploy-production-priv"
)
STAGING_NEXT_POLICY = PurePosixPath("/etc/wpcaregrid-deploy/profiles/staging-next.json")
STAGING_NEXT_HELPER_VERSION = "1.0.0"
STAGING_NEXT_COMPATIBILITY_CONTRACT = "wpcaregrid-deploy-profile-v1"

STAGING_NEXT = DeploymentProfile(
    name="staging-next",
    entrypoint=STAGING_NEXT_ENTRYPOINT,
    enabled=True,
    app_dir="/var/www/wpcaregrid-staging-new",
    database="wpcg_staging_next",
    host="staging-next.wpcaregrid.com",
    policy_file=STAGING_NEXT_POLICY,
    # This first release only supports read-only preflight. Deployment operations
    # remain unavailable until their backup/restore and migration gates are wired.
    operations=frozenset({"preflight"}),
)

PRODUCTION = DeploymentProfile(
    name="production",
    entrypoint=PRODUCTION_ENTRYPOINT,
    enabled=False,
    app_dir=None,
    database=None,
    host=None,
    policy_file=None,
    operations=frozenset(),
)

PROFILES: Mapping[str, DeploymentProfile] = MappingProxyType({
    "staging-next": STAGING_NEXT,
    "production": PRODUCTION,
})
_ENTRYPOINTS: Mapping[Path, DeploymentProfile] = MappingProxyType({
    profile.entrypoint: profile for profile in PROFILES.values()
})
_POLICY_KEYS = frozenset({
    "schema", "profile", "enabled", "allowed_uid", "app_dir", "database", "host", "operations",
    "helper_version", "compatibility_contract", "entrypoint_sha256", "profiles_module_sha256",
})


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate policy key")
        result[key] = value
    return result


def resolve_profile(executable: str | os.PathLike[str]) -> DeploymentProfile:
    """Resolve only one of the fixed installed entrypoint paths."""
    try:
        path = PurePosixPath(os.fspath(executable))
    except (TypeError, ValueError):
        raise ProfileRefused("unknown_entrypoint") from None

    if not path.is_absolute() or posixpath.normpath(str(path)) != str(path):
        raise ProfileRefused("unknown_entrypoint")

    profile = _ENTRYPOINTS.get(path)
    if profile is None:
        raise ProfileRefused("unknown_entrypoint")
    return profile


def _validate_policy(profile: DeploymentProfile, data: object) -> int:
    if not isinstance(data, dict) or set(data) != _POLICY_KEYS:
        raise ProfileRefused("policy_schema_invalid")

    if (type(data["schema"]) is not int or data["schema"] != 1 or data["profile"] != profile.name
            or data["enabled"] is not True or not profile.enabled):
        raise ProfileRefused("profile_not_enabled")

    uid = data["allowed_uid"]
    if isinstance(uid, bool) or not isinstance(uid, int) or uid <= 0:
        raise ProfileRefused("caller_identity_invalid")

    if (data["app_dir"] != profile.app_dir or data["database"] != profile.database
            or data["host"] != profile.host):
        raise ProfileRefused("target_identity_mismatch")

    if (data["helper_version"] != STAGING_NEXT_HELPER_VERSION
            or data["compatibility_contract"] != STAGING_NEXT_COMPATIBILITY_CONTRACT
            or not isinstance(data["entrypoint_sha256"], str)
            or re.fullmatch(r"[0-9a-f]{64}", data["entrypoint_sha256"]) is None
            or not isinstance(data["profiles_module_sha256"], str)
            or re.fullmatch(r"[0-9a-f]{64}", data["profiles_module_sha256"]) is None):
        raise ProfileRefused("helper_provenance_invalid")

    operations = data["operations"]
    if (not isinstance(operations, list) or any(not isinstance(item, str) for item in operations)
            or set(operations) != profile.operations or len(operations) != len(profile.operations)):
        raise ProfileRefused("operation_allowlist_mismatch")

    return uid


def load_root_policy(profile: DeploymentProfile, *, stat_fn=os.stat, read_bytes_fn=Path.read_bytes) -> int:
    """Load the exact policy, refusing symlinks, wrong ownership or writable modes."""
    if not profile.enabled or profile.policy_file is None:
        raise ProfileRefused("profile_not_enabled")

    path = profile.policy_file
    try:
        parent = PurePosixPath("/")
        for component in path.parts[1:-1]:
            parent /= component
            directory = stat_fn(str(parent), follow_symlinks=False)
            if (not stat.S_ISDIR(directory.st_mode) or directory.st_uid != 0
                    or stat.S_IMODE(directory.st_mode) & 0o022):
                raise ProfileRefused("policy_parent_metadata_invalid")
        info = stat_fn(path, follow_symlinks=False)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o644:
            raise ProfileRefused("policy_metadata_invalid")
        if info.st_size > 4096:
            raise ProfileRefused("policy_size_invalid")
        raw = read_bytes_fn(Path(str(path)))
        if len(raw) > 4096:
            raise ProfileRefused("policy_size_invalid")
        data = json.loads(raw.decode("utf-8"), object_pairs_hook=_unique_object)
    except ProfileRefused:
        raise
    except (OSError, UnicodeError, ValueError, TypeError):
        raise ProfileRefused("policy_unavailable") from None

    allowed_uid = _validate_policy(profile, data)
    helper_path = Path(str(profile.entrypoint))
    module_path = helper_path.parent / "wpcaregrid_deploy_profiles.py"
    helper_digest = _root_file_sha256(helper_path, expected_mode=0o755, stat_fn=stat_fn, read_bytes_fn=read_bytes_fn)
    module_digest = _root_file_sha256(module_path, expected_mode=0o644, stat_fn=stat_fn, read_bytes_fn=read_bytes_fn)
    if helper_digest != data["entrypoint_sha256"] or module_digest != data["profiles_module_sha256"]:
        raise ProfileRefused("helper_identity_mismatch")
    return allowed_uid


def verify_installed_entrypoint(executable: str | os.PathLike[str], *, lstat_fn=os.lstat) -> None:
    profile = resolve_profile(executable)
    try:
        parent = PurePosixPath("/")
        for component in profile.entrypoint.parts[1:-1]:
            parent /= component
            directory = lstat_fn(str(parent))
            if (not stat.S_ISDIR(directory.st_mode) or directory.st_uid != 0
                    or stat.S_IMODE(directory.st_mode) & 0o022):
                raise ProfileRefused("helper_parent_metadata_invalid")
        info = lstat_fn(str(profile.entrypoint))
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0
                or stat.S_IMODE(info.st_mode) != 0o755):
            raise ProfileRefused("helper_metadata_invalid")
    except ProfileRefused:
        raise
    except OSError:
        raise ProfileRefused("helper_unavailable") from None


def _root_file_sha256(path: Path, *, expected_mode: int, stat_fn=os.stat, read_bytes_fn=Path.read_bytes) -> str:
    info = stat_fn(path, follow_symlinks=False)
    if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0
            or stat.S_IMODE(info.st_mode) != expected_mode or info.st_size > 1_000_000):
        raise ProfileRefused("helper_metadata_invalid")
    digest = hashlib.sha256(read_bytes_fn(path)).hexdigest()
    return digest


def authorize_invocation(
    executable: str | os.PathLike[str],
    arguments: Sequence[str],
    *,
    effective_uid: int,
    invoking_uid: int | None,
    policy_uid: int,
) -> DeploymentProfile:
    """Authorize a fixed profile and operation without runtime target selection."""
    profile = resolve_profile(executable)
    if not profile.enabled:
        raise ProfileRefused("profile_not_enabled")
    if effective_uid != 0 or invoking_uid is None or invoking_uid != policy_uid:
        raise ProfileRefused("caller_identity_invalid")
    if len(arguments) != 1 or arguments[0] not in profile.operations:
        raise ProfileRefused("operation_not_allowed")
    return profile


def invoking_uid_from_sudo(value: str | None) -> int:
    """Parse sudo's caller UID without accepting signs, whitespace or booleans."""
    if not isinstance(value, str) or re.fullmatch(r"[1-9][0-9]{0,9}", value) is None:
        raise ProfileRefused("caller_identity_invalid")
    return int(value)


def verify_target_directory(profile: DeploymentProfile, *, lstat_fn=os.lstat) -> None:
    """Check the fixed application path exists without traversing symlinks."""
    if not profile.enabled or profile.app_dir is None:
        raise ProfileRefused("profile_not_enabled")

    current = PurePosixPath("/")
    try:
        for component in PurePosixPath(profile.app_dir).parts[1:]:
            current /= component
            info = lstat_fn(str(current))
            if stat.S_ISLNK(info.st_mode):
                raise ProfileRefused("target_path_symlink")
        if not stat.S_ISDIR(info.st_mode):
            raise ProfileRefused("target_path_invalid")
    except ProfileRefused:
        raise
    except OSError:
        raise ProfileRefused("target_path_unavailable") from None
