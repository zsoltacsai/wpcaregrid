#!/usr/bin/env python3
"""Unprivileged policy tests for the fixed staging deploy helper interface."""

import importlib.util
import hashlib
import io
import os
import subprocess
import tempfile
from contextlib import redirect_stdout
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
    output = io.StringIO()
    with redirect_stdout(output):
        module.worker("status", "wpcaregrid-default")
    assert output.getvalue() == "wpcaregrid-default RUNNING pid 42\n"
    assert invocations[-1] == ["/usr/bin/supervisorctl", "status", "wpcaregrid-default"]

    status_cases = (
        (0, "wpcaregrid-default RUNNING pid 42, uptime 0:01:00\n", "wpcaregrid-default RUNNING pid 42\n"),
        (3, "wpcaregrid-default STOPPED Oct 17 01:59 PM\n", "wpcaregrid-default STOPPED\n"),
        (3, "wpcaregrid-default FATAL Exited too quickly\n", "wpcaregrid-default FATAL\n"),
    )
    for returncode, response, expected in status_cases:
        module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, returncode, stdout=response)
        output = io.StringIO()
        with redirect_stdout(output):
            module.worker("status", "wpcaregrid-default")
        assert output.getvalue() == expected
    module.run = lambda argv, **kwargs: subprocess.CompletedProcess(
        argv, 3, stdout="unrelated-service STOPPED\n"
    )
    try:
        module.worker("status", "wpcaregrid-default")
    except ValueError:
        pass
    else:
        raise AssertionError("unexpected Supervisor status output was accepted")
    module.run = fake_run
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
        ["rollback-allowed", "../etc/passwd"],
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

    with tempfile.TemporaryDirectory() as temporary:
        approval = Path(temporary) / "approved-rollback-sha"
        rollback_sha = "a" * 40
        approval.write_bytes((rollback_sha + "\n").encode("ascii"))
        approval.chmod(0o600)
        owner = approval.stat().st_uid
        test_mode = None if os.name == "nt" else 0o600
        assert module.read_approved_rollback(approval, expected_owner=owner, expected_mode=test_mode) == rollback_sha
        approval.write_bytes(b"not-a-full-sha\n")
        try:
            module.read_approved_rollback(approval, expected_owner=owner, expected_mode=test_mode)
        except ValueError:
            pass
        else:
            raise AssertionError("malformed rollback approval was accepted")
        link = Path(temporary) / "approval-link"
        link.symlink_to(approval)
        try:
            module.read_approved_rollback(link, expected_owner=owner, expected_mode=test_mode)
        except (OSError, ValueError):
            pass
        else:
            raise AssertionError("symlink rollback approval was accepted")

    module.APPROVED_ROLLBACK = Path("/root-owned-approval-fixture")
    module.read_approved_rollback = lambda: "a" * 40
    module.dispatch(["rollback-allowed", "a" * 40])
    expect_rejected(["rollback-allowed", "b" * 40])

    assert module.php_profile("8.4") == "8.4"
    assert module.php_profile("8.5") == "8.5"
    for profile in ("8.3", "8.6", "/usr/bin/php8.5", "", "8.5;id"):
        try:
            module.php_profile(profile)
        except ValueError:
            pass
        else:
            raise AssertionError(f"unsupported PHP runtime profile accepted: {profile!r}")

    stopped = {name: "STOPPED" for name in module.WORKERS}
    running = {name: "RUNNING" for name in module.WORKERS}
    assert module.runtime_switch_preconditions(True, True, stopped)
    assert not module.runtime_switch_preconditions(False, True, stopped)
    assert not module.runtime_switch_preconditions(True, False, stopped)
    assert not module.runtime_switch_preconditions(True, True, running)
    assert not module.runtime_switch_preconditions(True, True, {"unexpected": "STOPPED"})

    with tempfile.TemporaryDirectory() as temporary:
        marker = Path(temporary) / "workers-disabled"
        marker.write_text("disabled\n", encoding="ascii")
        prior_marker = module.WORKERS_DISABLED
        prior_require = module.require_regular
        prior_run = module.run
        module.WORKERS_DISABLED = marker
        module.require_regular = lambda *args, **kwargs: None
        module.run = lambda *args, **kwargs: (_ for _ in ()).throw(AssertionError("disabled worker reached Supervisor"))
        try:
            module.worker("start", "wpcaregrid-default")
        except ValueError:
            pass
        else:
            raise AssertionError("worker start bypassed the persistent disable marker")
        finally:
            module.WORKERS_DISABLED = prior_marker
            module.require_regular = prior_require
            module.run = prior_run

    for profile in ("8.4", "8.5"):
        valid_runtime = {
            "binary_ok": True,
            "cli_version": profile,
            "fpm_active": True,
            "fpm_binary_ok": True,
            "fpm_executable": module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(),
            "fpm_version": profile + ".27",
            "socket_type": True,
            "socket_uid": 33,
            "socket_gid": 33,
            "socket_mode": 0o660,
            "expected_uid": 33,
            "expected_gid": 33,
        }
        module.validate_runtime_evidence(profile, **valid_runtime)
        for key, bad_value in (("binary_ok", False), ("cli_version", "8.4" if profile == "8.5" else "8.5"), ("fpm_active", False), ("fpm_binary_ok", False), ("fpm_executable", "/usr/sbin/php-fpm8.4" if profile == "8.5" else "/usr/sbin/php-fpm8.5"), ("fpm_version", "8.4.27" if profile == "8.5" else "8.5.12"), ("socket_type", False), ("socket_mode", 0o666)):
            invalid_runtime = dict(valid_runtime)
            invalid_runtime[key] = bad_value
            try:
                module.validate_runtime_evidence(profile, **invalid_runtime)
            except ValueError:
                pass
            else:
                raise AssertionError(f"runtime mismatch accepted: {profile} {key}")
    fpm_info = """[PHP Modules]\nctype\ncurl\ndom\nfileinfo\nfilter\nhash\niconv\nintl\njson\nlibxml\nmbstring\nopenssl\npcre\nPDO\npdo_mysql\nsession\nSimpleXML\nsodium\ntokenizer\nxml\nzip\n[Zend Modules]\nZend OPcache\nopcache.validate_timestamps => On => On\nopcache.revalidate_freq => 2 => 2\n"""
    module.validate_fpm_runtime_info(fpm_info)
    for invalid_info in (
        fpm_info.replace("pdo_mysql\n", ""),
        fpm_info.replace("opcache.validate_timestamps => On => On", "opcache.validate_timestamps => Off => Off"),
        fpm_info.replace("opcache.revalidate_freq => 2 => 2", "opcache.revalidate_freq => 5 => 5"),
        fpm_info.replace("opcache.revalidate_freq => 2 => 2", ""),
    ):
        try:
            module.validate_fpm_runtime_info(invalid_info)
        except ValueError:
            pass
        else:
            raise AssertionError("incompatible PHP-FPM configuration was accepted")
        for name, command in module.WORKER_COMMANDS.items():
            expected_executable = module.PHP_RUNTIME_BINARIES[profile].as_posix()
            expected_argv = [expected_executable, *command.split()[1:]]
            status = f"{name} RUNNING pid 42, uptime 0:01:00\n"
            module.validate_worker_runtime(profile, name, status, expected_executable, expected_argv)
            for bad_executable, bad_argv in (
                (module.PHP_RUNTIME_BINARIES["8.5" if profile == "8.4" else "8.4"].as_posix(), expected_argv),
                (expected_executable, [expected_executable, "/tmp/unrelated.php"]),
            ):
                try:
                    module.validate_worker_runtime(profile, name, status, bad_executable, bad_argv)
                except ValueError:
                    pass
                else:
                    raise AssertionError("mismatched live worker runtime was accepted")

    if os.name != "nt":
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            profile_files = {"8.4": root / "php84.conf", "8.5": root / "php85.conf"}
            for profile, path in profile_files.items():
                path.write_text(profile, encoding="ascii")
            active_link = root / "active.conf"
            active_link.symlink_to(profile_files["8.4"])
            gate = root / "deploy-maintenance"
            pause = root / "scheduler-paused"
            disabled = root / "workers-disabled"
            for marker in (gate, pause, disabled):
                marker.write_text("active\n", encoding="ascii")
            saved = {
                name: getattr(module, name)
                for name in ("PHP_RUNTIME_LINK", "PHP_RUNTIME_FILES", "GATE", "SCHEDULER_PAUSE", "WORKERS_DISABLED", "open_root_trusted_directory", "active_php_profile", "php_runtime_preflight", "require_regular", "run")
            }
            try:
                module.PHP_RUNTIME_LINK = active_link
                module.PHP_RUNTIME_FILES = profile_files
                module.GATE = gate
                module.SCHEDULER_PAUSE = pause
                module.WORKERS_DISABLED = disabled
                module.open_root_trusted_directory = lambda path: os.open(path, os.O_RDONLY | os.O_DIRECTORY)
                module.active_php_profile = lambda: next(profile for profile, path in profile_files.items() if os.readlink(active_link) == str(path))
                module.php_runtime_preflight = lambda profile: None
                module.require_regular = lambda path, **kwargs: (_ for _ in ()).throw(ValueError("unsafe marker")) if path.is_symlink() or not path.is_file() else path.stat()
                failure_state = {"fail_nginx_once": False}

                def fake_runtime_run(argv, **kwargs):
                    if argv[:2] == ["/usr/bin/supervisorctl", "status"]:
                        name = argv[-1]
                        return subprocess.CompletedProcess(argv, 0, stdout=f"{name} STOPPED\n")
                    if argv == ["/usr/sbin/nginx", "-t"] and failure_state["fail_nginx_once"]:
                        failure_state["fail_nginx_once"] = False
                        raise subprocess.CalledProcessError(1, argv)
                    return subprocess.CompletedProcess(argv, 0, stdout="")

                module.run = fake_runtime_run
                module.switch_php_runtime("8.5")
                assert module.active_php_profile() == "8.5"
                active_link.unlink()
                active_link.symlink_to(profile_files["8.4"])
                failure_state["fail_nginx_once"] = True
                try:
                    module.switch_php_runtime("8.5")
                except ValueError:
                    pass
                else:
                    raise AssertionError("failed Nginx validation did not abort runtime switch")
                assert module.active_php_profile() == "8.4", "partial runtime transition did not restore previous selector"
                assert gate.is_file() and pause.is_file() and disabled.is_file(), "failed runtime transition removed a fail-closed marker"
            finally:
                for name, value in saved.items():
                    setattr(module, name, value)

    original_trusted_read = module.trusted_file_bytes
    original_run = module.run
    try:
        for profile in ("8.4", "8.5"):
            module.trusted_file_bytes = lambda path, **kwargs: module.PHP_RUNTIME_DIRECTIVES[profile].encode("ascii")
            module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout="")
            module.runtime_snippet(profile)
            module.trusted_file_bytes = lambda path, **kwargs: b"fastcgi_pass unix:/run/php/php7.4-fpm.sock;\n"
            try:
                module.runtime_snippet(profile)
            except ValueError:
                pass
            else:
                raise AssertionError("unexpected FPM socket directive was accepted")

        module.SUPERVISOR_CONFIG = Path("/synthetic/wpcaregrid.conf")
        supervisor_fixture = """[program:wpcaregrid-default]\ncommand=php /var/www/wpcaregrid/artisan queue:work database --queue=default --sleep=3 --tries=3 --backoff=60 --timeout=120 --max-time=3600 --memory=256\n\n[program:wpcaregrid-backups]\ncommand=php /var/www/wpcaregrid/artisan queue:work backups --queue=backups --sleep=3 --timeout=3660 --max-time=14400 --memory=512\n"""
        module.trusted_file_bytes = lambda path, **kwargs: supervisor_fixture.encode("utf-8")
        module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout="user::rw-\ngroup::r--\nother::r--\n")
        module.supervisor_runtime_contract(allow_legacy=True)
        rewritten_supervisor = module.rewrite_supervisor_runtime_commands(supervisor_fixture)
        assert "command=" + module.WORKER_COMMANDS["wpcaregrid-default"] in rewritten_supervisor
        assert "command=" + module.WORKER_COMMANDS["wpcaregrid-backups"] in rewritten_supervisor
        assert module.rewrite_supervisor_runtime_commands(rewritten_supervisor) == rewritten_supervisor
        try:
            module.supervisor_runtime_contract()
        except ValueError:
            pass
        else:
            raise AssertionError("legacy implicit Supervisor PHP command passed strict runtime verification")
        supervisor_fixture = supervisor_fixture.replace("command=php ", "command=/usr/local/bin/wpcaregrid-php ")
        module.supervisor_runtime_contract()
        supervisor_fixture += "[program:unexpected]\ncommand=/bin/sh -c id\n"
        module.supervisor_runtime_contract()
        supervisor_fixture = supervisor_fixture.replace("/usr/local/bin/wpcaregrid-php /var/www/wpcaregrid/artisan queue:work backups", "php /var/www/wpcaregrid/artisan queue:work backups")
        try:
            module.supervisor_runtime_contract()
            module.rewrite_supervisor_runtime_commands(supervisor_fixture)
        except ValueError:
            pass
        else:
            raise AssertionError("mixed Supervisor PHP runtimes were accepted")
    finally:
        module.trusted_file_bytes = original_trusted_read
        module.run = original_run

    original_snippet = module.runtime_snippet
    original_active_profile = module.active_php_profile
    original_run = module.run
    try:
        module.runtime_snippet = lambda profile: None
        module.active_php_profile = lambda: "8.4"
        good_nginx = """# configuration file /etc/nginx/sites-enabled/staging.wpcaregrid.com:\nserver {\n    listen 80;\n    server_name staging.wpcaregrid.com;\n    return 301 https://staging.wpcaregrid.com$request_uri;\n}\nserver {\n    listen 443 ssl;\n    server_name staging.wpcaregrid.com;\n    include /etc/nginx/snippets/wpcaregrid-staging-runtime.conf;\n}\n# configuration file /etc/nginx/snippets/wpcaregrid-staging-php84.conf:\nfastcgi_pass unix:/run/php/php8.4-fpm.sock;\n"""
        module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout=good_nginx if argv[-1] == "-T" else "")
        module.nginx_runtime_contract("8.4")
        for bad_nginx in (
            good_nginx.replace("staging.wpcaregrid.com;", "other.example;").replace("staging.wpcaregrid.com:\nserver", "other.example:\nserver"),
            good_nginx + "# configuration file /etc/nginx/sites-enabled/other.example:\ninclude /etc/nginx/snippets/wpcaregrid-staging-runtime.conf;\n",
            good_nginx.replace("include /etc/nginx/snippets/wpcaregrid-staging-runtime.conf;", "fastcgi_pass unix:/run/php/php7.4-fpm.sock;"),
            good_nginx.replace("include /etc/nginx/snippets/wpcaregrid-staging-runtime.conf;", "include /etc/nginx/snippets/wpcaregrid-staging-runtime.conf;\n    fastcgi_pass unix:/run/php/php8.4-fpm.sock;"),
        ):
            module.run = lambda argv, bad_nginx=bad_nginx, **kwargs: subprocess.CompletedProcess(argv, 0, stdout=bad_nginx if argv[-1] == "-T" else "")
            try:
                module.nginx_runtime_contract("8.4")
            except ValueError:
                pass
            else:
                raise AssertionError("unsafe or non-staging Nginx PHP routing was accepted")
    finally:
        module.runtime_snippet = original_snippet
        module.active_php_profile = original_active_profile
        module.run = original_run

    controller = CONTROLLER.read_text(encoding="utf-8")
    controller_lines = controller.splitlines()
    gate_line = next(i for i, line in enumerate(controller_lines) if "privileged gate-on" in line and i > 700)
    switch_line = next(i for i, line in enumerate(controller_lines) if "privileged php-runtime-switch \"$PHP_TARGET_PROFILE\"" in line)
    workers_stop_line = next(i for i, line in enumerate(controller_lines) if "privileged worker stop-all" in line)
    disabled_line = next(i for i, line in enumerate(controller_lines) if "privileged worker-runtime-disable" in line and i > gate_line)
    assert gate_line < disabled_line < workers_stop_line < switch_line, "runtime switch must follow the active gate, persistent worker disable, and drained workers"
    recovery_switch_line = next(i for i, line in enumerate(controller_lines) if "privileged php-runtime-switch \"$PHP_BASELINE_PROFILE\"" in line)
    baseline_checkout_line = next(i for i, line in enumerate(controller_lines) if "rollback_code_to_baseline || return 1" in line)
    assert recovery_switch_line < baseline_checkout_line, "runtime must return to baseline before code rollback"
    assert "composer check-platform-reqs --no-dev --lock" in controller
    assert "update-alternatives" not in controller
    assert 'if ! post_deploy_smoke || ! runtime_cache_access_ok || ! workers_running; then' in controller
    assert "privileged gate-on || log 'Could not confirm the Nginx gate marker" in controller
    launcher = (ROOT / "scripts/run-php-runtime.sh").read_text(encoding="utf-8")
    assert "queue:work) blocked /var/lib/wpcaregrid/workers-disabled" in launcher
    assert "schedule:run) blocked /var/lib/wpcaregrid/scheduler-paused" in launcher
    assert launcher.index("queue:work) blocked") < launcher.index('exec "$PHP_BIN"')

    controller = CONTROLLER.read_text(encoding="utf-8")
    fixture = DENIAL_FIXTURE.read_text(encoding="utf-8")
    wrapper_hash = hashlib.sha256((ROOT / "scripts/run-scheduler.sh").read_bytes()).hexdigest()
    launcher_hash = hashlib.sha256((ROOT / "scripts/run-php-runtime.sh").read_bytes()).hexdigest()
    assert wrapper_hash == module.SCHEDULER_RUNTIME_SOURCE_SHA256, "root helper scheduler hash must match the reviewed runtime wrapper"
    assert launcher_hash == module.PHP_RUNTIME_LAUNCHER_SHA256, "root helper launcher hash must match the reviewed runtime dispatcher"
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
