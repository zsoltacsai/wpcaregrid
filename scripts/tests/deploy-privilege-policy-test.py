#!/usr/bin/env python3
"""Unprivileged policy tests for the fixed staging deploy helper interface."""

import importlib.util
import hashlib
import subprocess
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
HELPER = ROOT / "scripts/wpcaregrid-deploy-priv.py"
CONTROLLER = ROOT / "scripts/deploy-staging.sh"
DENIAL_FIXTURE = ROOT / "scripts/tests/fixtures/deploy-sudo-denial-fixture.sh"
spec = importlib.util.spec_from_file_location("deploy_priv", HELPER)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def expect_rejected(args):
    try:
        module.dispatch(args)
    except (ValueError, OSError, subprocess.SubprocessError):
        return
    raise AssertionError(f"unsafe helper request accepted: {args!r}")


def main():
    invocations = []

    def fake_run(argv, **kwargs):
        invocations.append(argv)
        return subprocess.CompletedProcess(argv, 0, stdout="wpcaregrid-default RUNNING pid 42\n")

    module.run = fake_run
    module.worker("status", "wpcaregrid-default")
    assert invocations[-1] == ["/usr/bin/supervisorctl", "status", "wpcaregrid-default"]
    module.dispatch(["db-dump"])
    assert invocations[-1] == ["/usr/bin/mysqldump", "--single-transaction", "--routines", "--triggers", "--databases", "wpcaregrid"]

    for args in (
        ["worker", "status", "unrelated-service"],
        ["worker", "shell", "wpcaregrid-default"],
        ["db-dump", "other_database"],
        ["file-check", "www-data", "read", "../../etc/shadow"],
        ["file-check", "root", "read", "artisan"],
        ["cache-file-secure", "storage/framework/views/../../.env"],
        ["gate-on", "/etc/passwd"],
        ["supervisorctl", "status"],
        ["run", "/bin/sh", "-c", "id"],
    ):
        expect_rejected(args)

    with tempfile.TemporaryDirectory() as temporary:
        app = Path(temporary) / "app"
        app.mkdir()
        module.APP = app
        (app / "artisan").write_text("safe\n", encoding="utf-8")
        (app / "link").symlink_to("/etc/passwd")
        for relative in ("../etc/passwd", "link", "missing"):
            try:
                module.app_file(relative)
            except (ValueError, OSError):
                continue
            raise AssertionError(f"unsafe application path accepted: {relative}")

    controller = CONTROLLER.read_text(encoding="utf-8")
    fixture = DENIAL_FIXTURE.read_text(encoding="utf-8")
    wrapper_hash = hashlib.sha256((ROOT / "scripts/run-scheduler.sh").read_bytes()).hexdigest()
    assert wrapper_hash == module.SCHEDULER_SOURCE_SHA256, "root helper wrapper hash must match the reviewed scheduler source"
    direct_privilege = [
        line.strip()
        for line in controller.splitlines()
        if "sudo -n" in line and "DEPLOY_PRIV_HELPER" not in line and "sudo -n -l" not in line
    ]
    assert not direct_privilege, f"generic sudo operation remains: {direct_privilege}"
    assert "sudo -n \"$DEPLOY_PRIV_HELPER\" \"$@\"" in controller
    assert 'sudo_listing="$(sudo -n -l 2>&1)"' in controller
    assert '[[ "$command_lines" == "(root) NOPASSWD: ${DEPLOY_PRIV_HELPER} *" ]]' in controller
    assert "sudo -n /usr/bin/id" in fixture
    assert "sudo -n /bin/sh" not in fixture and "rm -" not in fixture and "chmod " not in fixture
    assert "install.*DEPLOY_PRIV_HELPER" not in controller

    print("deploy privilege policy passed: fixed operation allowlist, path validation, no generic sudo calls")


if __name__ == "__main__":
    main()
