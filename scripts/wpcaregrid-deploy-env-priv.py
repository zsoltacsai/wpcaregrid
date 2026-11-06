#!/usr/bin/python3 -I
"""Fixed staging-next entrypoint with a verified, bounded shared-code load."""

import hashlib
import json
import os
import stat
import sys
import types
from pathlib import PurePosixPath

sys.dont_write_bytecode = True

EXPECTED_ENTRYPOINT = "/usr/local/libexec/wpcaregrid-deploy/staging-next/1.0.1/wpcaregrid-deploy-staging-next-priv"
EXPECTED_PROFILES_MODULE_SHA256 = "27c122e11de038c2bf28f9bc462e4b78c695777a8d592a23428f17b5dc819bf7"
MAX_PROFILES_MODULE_BYTES = 1_000_000


class BootstrapRefused(Exception):
    """Sanitized refusal before shared privileged code is loaded."""


def _read_profiles_module(executable, *, lstat_fn=os.lstat, read_module_fn=None,
                          expected_module_sha256=EXPECTED_PROFILES_MODULE_SHA256):
    if executable != EXPECTED_ENTRYPOINT:
        raise BootstrapRefused("unknown_entrypoint")

    expected = PurePosixPath(EXPECTED_ENTRYPOINT)
    parent = PurePosixPath("/")
    for component in expected.parts[1:-1]:
        parent /= component
        try:
            info = lstat_fn(str(parent))
        except OSError:
            raise BootstrapRefused("helper_parent_metadata_invalid") from None
        if (not stat.S_ISDIR(info.st_mode) or info.st_uid != 0
                or stat.S_IMODE(info.st_mode) & 0o022):
            raise BootstrapRefused("helper_parent_metadata_invalid")

    try:
        entry = lstat_fn(EXPECTED_ENTRYPOINT)
    except OSError:
        raise BootstrapRefused("helper_unavailable") from None
    if (not stat.S_ISREG(entry.st_mode) or entry.st_uid != 0
            or stat.S_IMODE(entry.st_mode) != 0o755):
        raise BootstrapRefused("helper_metadata_invalid")

    module_path = expected.parent / "wpcaregrid_deploy_profiles.py"
    try:
        module_info = lstat_fn(str(module_path))
        if (not stat.S_ISREG(module_info.st_mode) or module_info.st_uid != 0
                or stat.S_IMODE(module_info.st_mode) != 0o644
                or module_info.st_size > MAX_PROFILES_MODULE_BYTES):
            raise BootstrapRefused("helper_metadata_invalid")
        if read_module_fn is None:
            flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0)
            fd = os.open(str(module_path), flags)
            try:
                opened = os.fstat(fd)
                if (not stat.S_ISREG(opened.st_mode) or opened.st_uid != 0
                        or stat.S_IMODE(opened.st_mode) != 0o644
                        or opened.st_size > MAX_PROFILES_MODULE_BYTES
                        or opened.st_size != module_info.st_size
                        or opened.st_ino != module_info.st_ino or opened.st_dev != module_info.st_dev):
                    raise BootstrapRefused("helper_metadata_invalid")
                with os.fdopen(fd, "rb", closefd=False) as stream:
                    source = stream.read(MAX_PROFILES_MODULE_BYTES + 1)
                if len(source) != opened.st_size:
                    raise BootstrapRefused("helper_identity_mismatch")
            finally:
                os.close(fd)
        else:
            source = read_module_fn(str(module_path))
    except BootstrapRefused:
        raise
    except OSError:
        raise BootstrapRefused("helper_unavailable") from None

    if len(source) > MAX_PROFILES_MODULE_BYTES or hashlib.sha256(source).hexdigest() != expected_module_sha256:
        raise BootstrapRefused("helper_identity_mismatch")
    return str(module_path), source


def _load_verified_profiles_module(executable, **kwargs):
    path, source = _read_profiles_module(executable, **kwargs)
    module = types.ModuleType("_wpcaregrid_deploy_profiles")
    module.__file__ = path
    exec(compile(source, path, "exec", dont_inherit=True), module.__dict__)
    return module


def main(argv=None):
    args = list(sys.argv[1:] if argv is None else argv)
    sudo_uid = os.environ.get("SUDO_UID")
    os.environ.clear()

    try:
        profiles = _load_verified_profiles_module(sys.argv[0])
        profile = profiles.resolve_profile(sys.argv[0])
        if profile.policy_file is None:
            raise profiles.ProfileRefused("profile_not_enabled")
        profiles.verify_installed_entrypoint(sys.argv[0])
        policy_uid = profiles.load_root_policy(profile)
        invoking_uid = profiles.invoking_uid_from_sudo(sudo_uid)
        profiles.authorize_invocation(
            sys.argv[0], args, effective_uid=os.geteuid(),
            invoking_uid=invoking_uid, policy_uid=policy_uid,
        )
        profiles.verify_target_directory(profile)
        sys.stdout.write(json.dumps({
            "OPERATION": "PREFLIGHT_ONLY",
            "PROFILE": profile.name,
            "RESULT": "PASS",
            "TARGETS": "PINNED",
        }, sort_keys=True, separators=(",", ":")) + "\n")
        return 0
    except BootstrapRefused as exc:
        sys.stderr.write(f"DEPLOY_PROFILE=HOLD;CODE={exc}\n")
        return 1
    except Exception as exc:
        # Never expose raw exception text from the privileged boundary.
        known_codes = {
            "unknown_entrypoint", "helper_parent_metadata_invalid", "helper_metadata_invalid",
            "helper_unavailable", "helper_identity_mismatch", "policy_schema_invalid",
            "profile_not_enabled", "caller_identity_invalid", "target_identity_mismatch",
            "helper_provenance_invalid", "operation_allowlist_mismatch", "policy_metadata_invalid",
            "policy_size_invalid", "policy_unavailable", "operation_not_allowed",
            "target_path_symlink", "target_path_invalid", "target_path_unavailable",
        }
        code = str(exc) if isinstance(exc, ValueError) and str(exc) in known_codes else "preflight_unavailable"
        sys.stderr.write(f"DEPLOY_PROFILE=HOLD;CODE={code}\n")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
