#!/usr/bin/python3
"""Fixed-entrypoint staging-next preflight; no deployment operations yet."""

import json
import os
import sys

sys.dont_write_bytecode = True

from wpcaregrid_deploy_profiles import (
    ProfileRefused,
    authorize_invocation,
    invoking_uid_from_sudo,
    load_root_policy,
    resolve_profile,
    verify_installed_entrypoint,
    verify_target_directory,
)


def main(argv=None):
    argv = list(sys.argv[1:] if argv is None else argv)
    sudo_uid = os.environ.get("SUDO_UID")
    os.environ.clear()

    try:
        profile = resolve_profile(sys.argv[0])
        if profile.policy_file is None:
            raise ProfileRefused("profile_not_enabled")
        verify_installed_entrypoint(sys.argv[0])
        policy_uid = load_root_policy(profile)
        invoking_uid = invoking_uid_from_sudo(sudo_uid)
        authorize_invocation(
            sys.argv[0], argv, effective_uid=os.geteuid(),
            invoking_uid=invoking_uid, policy_uid=policy_uid,
        )
        verify_target_directory(profile)
        sys.stdout.write(json.dumps({
            "APP_DIR": "PASS",
            "DATABASE_IDENTITY": "PINNED",
            "HOST_IDENTITY": "PINNED",
            "OPERATION": "PREFLIGHT_ONLY",
            "PROFILE": profile.name,
            "RESULT": "PASS",
        }, sort_keys=True, separators=(",", ":")) + "\n")
        return 0
    except ProfileRefused as exc:
        sys.stderr.write(f"DEPLOY_PROFILE=HOLD;CODE={exc}\n")
        return 1
    except Exception:
        sys.stderr.write("DEPLOY_PROFILE=HOLD;CODE=preflight_unavailable\n")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
