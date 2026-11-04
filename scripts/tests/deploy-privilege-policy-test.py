#!/usr/bin/env python3
"""Unprivileged policy tests for the fixed staging deploy helper interface."""

import importlib.util
import hashlib
import fnmatch
import io
import os
import shutil
import socket
import stat
import subprocess
import tempfile
from contextlib import redirect_stdout, redirect_stderr
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
HELPER = ROOT / "scripts/wpcaregrid-deploy-priv.py"
CONTROLLER = ROOT / "scripts/deploy-staging.sh"
DENIAL_FIXTURE = ROOT / "scripts/tests/fixtures/deploy-sudo-denial-fixture.sh"
spec = importlib.util.spec_from_file_location("deploy_priv", HELPER)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
NGINX_VALIDATOR = module.validate_nginx_runtime_dump


def expect_rejected(args):
    try:
        module.dispatch(args)
    except (ValueError, OSError, subprocess.SubprocessError):
        return
    raise AssertionError(f"unsafe helper request accepted: {args!r}")


def expect_preflight_code(expected, callback):
    try:
        callback()
    except module.RuntimePreflightFailure as error:
        assert error.code == expected, f"preflight code mismatch: expected {expected}, got {error.code}"
        assert str(error) == expected, "preflight diagnostic must contain only its fixed code"
        return
    raise AssertionError(f"preflight failure was not reported as {expected}")


def nginx_dump(files):
    items = list(files.items())
    return "".join(
        f"# configuration file {path}:\n{content}" + ("\n" if index < len(items) - 1 else "")
        for index, (path, content) in enumerate(items)
    )


def nginx_fixture(profile="8.4", *, staging_name="staging.wpcaregrid.com", extra_staging_include=None, handler_override=None, selector_path=None):
    selector_path = selector_path or module.PHP_RUNTIME_LINK.as_posix()
    files = {
        "/etc/nginx/nginx.conf": "events {}\nhttp {\n    include /etc/nginx/sites-enabled/*;\n}\n",
        "/etc/nginx/sites-enabled/staging": (
            "server {\n"
            f"    server_name {staging_name};\n"
            "    include /etc/nginx/snippets/wpcaregrid-staging-routes.conf;\n"
            + (f"    include {extra_staging_include};\n" if extra_staging_include else "")
            + "}\n"
        ),
        "/etc/nginx/sites-enabled/sibling": (
            "server {\n"
            "    server_name sibling.example;\n"
            "    location ~ \\.php$ {\n"
            "        include /etc/nginx/snippets/sibling-fastcgi.conf;\n"
            "    }\n"
            "}\n"
        ),
        "/etc/nginx/snippets/wpcaregrid-staging-routes.conf": (
            "location ~ \\.php$ {\n"
            "    include /etc/nginx/snippets/wpcaregrid-staging-handler.conf;\n"
            "    include fastcgi_params;\n"
            "}\n"
        ),
        "/etc/nginx/snippets/wpcaregrid-staging-handler.conf": handler_override or f"include {selector_path};\n",
        selector_path: module.PHP_RUNTIME_DIRECTIVES[profile],
        "/etc/nginx/fastcgi_params": "fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n",
        "/etc/nginx/snippets/sibling-fastcgi.conf": "fastcgi_pass unix:/run/php/php8.4-fpm.sock;\n",
    }
    if extra_staging_include and extra_staging_include not in files:
        files[extra_staging_include] = "location ~ \\.alternate-php$ {\n    include /etc/nginx/snippets/staging-extra-fastcgi.conf;\n}\n"
        files["/etc/nginx/snippets/staging-extra-fastcgi.conf"] = "fastcgi_pass unix:/run/php/php8.4-fpm.sock;\n"
    dump = nginx_dump(files)
    return dump, files


def validate_nginx_fixture(dump, files, resolver_override=None):
    def source_reader(path):
        if path not in files:
            raise ValueError("fixture source missing")
        return files[path]

    def include_resolver(pattern, source_paths):
        if resolver_override is not None:
            return resolver_override(pattern, source_paths)
        return module._resolve_nginx_include(
            pattern,
            source_paths,
            filesystem_glob=lambda resolved: sorted(
                path for path in files if fnmatch.fnmatchcase(path, resolved)
            ),
        )

    NGINX_VALIDATOR(dump, source_reader=source_reader, include_resolver=include_resolver)


def main():
    if os.name != "nt":
        bash = shutil.which("bash")
        if not bash:
            raise AssertionError("Linux deployment policy tests require Bash for syntax validation")
        subprocess.run(
            [
                bash,
                "-n",
                str(CONTROLLER),
                str(ROOT / "scripts/run-scheduler.sh"),
                str(ROOT / "scripts/run-php-runtime.sh"),
            ],
            check=True,
        )

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
        for key, bad_value in (("binary_ok", False), ("cli_version", "8.4" if profile == "8.5" else "8.5"), ("fpm_active", False), ("fpm_binary_ok", False), ("fpm_executable", "/usr/sbin/php-fpm8.4" if profile == "8.5" else "/usr/sbin/php-fpm8.5"), ("fpm_version", "8.4.27" if profile == "8.5" else "8.5.12"), ("socket_type", False), ("socket_uid", 0), ("socket_gid", 0), ("socket_mode", 0o666)):
            invalid_runtime = dict(valid_runtime)
            invalid_runtime[key] = bad_value
            try:
                module.validate_runtime_evidence(profile, **invalid_runtime)
            except ValueError:
                pass
            else:
                raise AssertionError(f"runtime mismatch accepted: {profile} {key}")
    fpm_modules = """[PHP Modules]\nctype\ncurl\ndom\nfileinfo\nfilter\nhash\niconv\nintl\njson\nlibxml\nmbstring\nopenssl\npcre\nPDO\npdo_mysql\nsession\nSimpleXML\nsodium\ntokenizer\nxml\nzip\n[Zend Modules]\nZend OPcache\n"""
    fpm_info = """PHP Version => 8.4\nopcache.validate_timestamps => On => On\nopcache.revalidate_freq => 2 => 2\n"""
    module.validate_fpm_runtime_info(fpm_info, fpm_modules)
    for invalid_info in (
        fpm_info.replace("opcache.validate_timestamps => On => On", "opcache.validate_timestamps => Off => Off"),
        fpm_info.replace("opcache.revalidate_freq => 2 => 2", "opcache.revalidate_freq => 5 => 5"),
        fpm_info.replace("opcache.revalidate_freq => 2 => 2", ""),
    ):
        try:
            module.validate_fpm_runtime_info(invalid_info, fpm_modules)
        except ValueError:
            pass
        else:
            raise AssertionError("incompatible PHP-FPM OPcache configuration was accepted")
    for invalid_modules in (
        fpm_modules.replace("pdo_mysql\n", ""),
        fpm_modules.replace("[PHP Modules]\n", ""),
    ):
        try:
            module.validate_fpm_runtime_info(fpm_info, invalid_modules)
        except ValueError:
            pass
        else:
            raise AssertionError("incomplete PHP-FPM module list was accepted")

    assert all(module.re.fullmatch(r"PHP_RUNTIME_[A-Z0-9_]+", code) for code in module.PREFLIGHT_FAILURE_CODES)
    assert "PHP_RUNTIME_PREFLIGHT_UNCLASSIFIED" in module.PREFLIGHT_FAILURE_CODES
    expect_preflight_code(
        "PHP_RUNTIME_FPM_CONFIG_REJECTED",
        lambda: module.preflight_step(
            "PHP_RUNTIME_FPM_CONFIG_REJECTED",
            lambda: (_ for _ in ()).throw(OSError("synthetic-secret-path")),
        ),
    )

    for code, callback in (
        ("PHP_RUNTIME_FPM_MAIN_PID_UNAVAILABLE", lambda: module.validate_fpm_main_process("8.4", pid="0", executable="", cgroup_paths=set(), command_line_uses_config=False)),
        ("PHP_RUNTIME_FPM_EXECUTABLE_MISMATCH", lambda: module.validate_fpm_main_process("8.4", pid="123", executable="/wrong", cgroup_paths={module.PHP_RUNTIME_FPM_CGROUPS["8.4"]}, command_line_uses_config=True)),
        ("PHP_RUNTIME_FPM_CGROUP_MISMATCH", lambda: module.validate_fpm_main_process("8.4", pid="123", executable=module.PHP_RUNTIME_FPM_BINARIES["8.4"].as_posix(), cgroup_paths=set(), command_line_uses_config=True)),
        ("PHP_RUNTIME_FPM_MAIN_PROCESS_INVALID", lambda: module.validate_fpm_main_process("8.4", pid="123", executable=module.PHP_RUNTIME_FPM_BINARIES["8.4"].as_posix(), cgroup_paths={module.PHP_RUNTIME_FPM_CGROUPS["8.4"]}, command_line_uses_config=False)),
    ):
        expect_preflight_code(code, callback)

    runtime_code_fields = (
        ("binary_ok", False, "PHP_RUNTIME_CLI_BINARY_INVALID"),
        ("cli_version", "8.5", "PHP_RUNTIME_CLI_VERSION_MISMATCH"),
        ("fpm_binary_ok", False, "PHP_RUNTIME_FPM_METADATA_INVALID"),
        ("fpm_active", False, "PHP_RUNTIME_FPM_PROCESS_INACTIVE"),
        ("fpm_executable", "/wrong", "PHP_RUNTIME_FPM_EXECUTABLE_MISMATCH"),
        ("fpm_version", "7.4.0", "PHP_RUNTIME_FPM_VERSION_MISMATCH"),
        ("socket_type", False, "PHP_RUNTIME_FPM_SOCKET_TYPE_INVALID"),
        ("socket_uid", 0, "PHP_RUNTIME_FPM_SOCKET_OWNER_MISMATCH"),
        ("socket_gid", 0, "PHP_RUNTIME_FPM_SOCKET_GROUP_MISMATCH"),
        ("socket_mode", 0o666, "PHP_RUNTIME_FPM_SOCKET_MODE_MISMATCH"),
    )
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
        for field, bad_value, code in runtime_code_fields:
            invalid = dict(valid_runtime)
            invalid[field] = ("8.4" if profile == "8.5" else "8.5") if field == "cli_version" else bad_value
            expect_preflight_code(code, lambda invalid=invalid, profile=profile: module.validate_runtime_evidence(profile, **invalid))

    for code, info, modules in (
        ("PHP_RUNTIME_FPM_MODULE_LIST_UNAVAILABLE", fpm_info, fpm_modules.replace("[PHP Modules]\n", "")),
        ("PHP_RUNTIME_FPM_REQUIRED_MODULES_MISSING", fpm_info, fpm_modules.replace("pdo_mysql\n", "")),
        ("PHP_RUNTIME_FPM_OPCACHE_TIMESTAMPS_DISABLED", fpm_info.replace("On => On", "Off => Off"), fpm_modules),
        ("PHP_RUNTIME_FPM_OPCACHE_INTERVAL_UNAVAILABLE", fpm_info.replace("opcache.revalidate_freq => 2 => 2\n", ""), fpm_modules),
        ("PHP_RUNTIME_FPM_OPCACHE_INTERVAL_UNAVAILABLE", fpm_info.replace("opcache.revalidate_freq => 2 => 2", "invalid"), fpm_modules),
        ("PHP_RUNTIME_FPM_OPCACHE_INTERVAL_TOO_HIGH", fpm_info.replace("2 => 2", "5 => 5"), fpm_modules),
    ):
        expect_preflight_code(code, lambda info=info, modules=modules: module.validate_fpm_runtime_info(info, modules))

    for code, dump in (
        ("PHP_RUNTIME_FPM_POOL_USER_MISMATCH", "[www]\nuser = nobody\ngroup = www-data\nlisten = /run/php/php8.4-fpm.sock\n"),
        ("PHP_RUNTIME_FPM_POOL_GROUP_MISMATCH", "[www]\nuser = www-data\ngroup = nobody\nlisten = /run/php/php8.4-fpm.sock\n"),
        ("PHP_RUNTIME_FPM_POOL_SOCKET_MISMATCH", "[www]\nuser = www-data\ngroup = www-data\nlisten = /run/php/other.sock\n"),
        ("PHP_RUNTIME_FPM_POOL_DUPLICATE_SOCKET", "[www]\nuser = www-data\ngroup = www-data\nlisten = /run/php/php8.4-fpm.sock\n[other]\nlisten = /run/php/php8.4-fpm.sock\n"),
    ):
        expect_preflight_code(code, lambda dump=dump: module.validate_fpm_pool_listen("8.4", dump))

    expect_preflight_code(
        "PHP_RUNTIME_LISTENER_OWNER_MISSING",
        lambda: module.validate_fpm_listener_processes("8.4", 1, 1, [], lambda pid, fd: {}),
    )
    expect_preflight_code(
        "PHP_RUNTIME_LISTENER_PROCESS_UNAVAILABLE",
        lambda: module.validate_fpm_listener_processes("8.4", 1, 1, [(1, 1)], lambda pid, fd: (_ for _ in ()).throw(OSError("synthetic-secret"))),
    )
    expect_preflight_code(
        "PHP_RUNTIME_LISTENER_EXECUTABLE_MISMATCH",
        lambda: module.validate_fpm_listener_processes("8.4", 1, 1, [(1, 1)], lambda pid, fd: {"executable": "/wrong", "cgroup_paths": set(), "fd_is_socket": False}),
    )
    expect_preflight_code(
        "PHP_RUNTIME_LISTENER_CGROUP_MISMATCH",
        lambda: module.validate_fpm_listener_processes("8.4", 1, 1, [(1, 1)], lambda pid, fd: {"executable": module.PHP_RUNTIME_FPM_BINARIES["8.4"].as_posix(), "cgroup_paths": set(), "fd_is_socket": True}),
    )
    expect_preflight_code(
        "PHP_RUNTIME_LISTENER_FD_TYPE_MISMATCH",
        lambda: module.validate_fpm_listener_processes("8.4", 1, 1, [(1, 1)], lambda pid, fd: {"executable": module.PHP_RUNTIME_FPM_BINARIES["8.4"].as_posix(), "cgroup_paths": {module.PHP_RUNTIME_FPM_CGROUPS["8.4"]}, "fd_is_socket": False}),
    )

    original_impl = module._php_runtime_preflight
    module._php_runtime_preflight = lambda *args, **kwargs: (_ for _ in ()).throw(OSError("synthetic-secret"))
    try:
        expect_preflight_code("PHP_RUNTIME_PREFLIGHT_UNCLASSIFIED", lambda: module.php_runtime_preflight("8.4"))
    finally:
        module._php_runtime_preflight = original_impl

    had_geteuid = hasattr(module.os, "geteuid")
    original_geteuid = getattr(module.os, "geteuid", None)
    original_dispatch, original_argv = module.dispatch, module.sys.argv
    error_output = io.StringIO()
    module.os.geteuid = lambda: 0
    module.dispatch = lambda args: (_ for _ in ()).throw(module.RuntimePreflightFailure("PHP_RUNTIME_FPM_CONFIG_REJECTED"))
    module.sys.argv = [str(HELPER), "php-runtime-preflight", "8.4"]
    try:
        with redirect_stderr(error_output):
            assert module.main() == 1
    finally:
        if had_geteuid:
            module.os.geteuid = original_geteuid
        else:
            del module.os.geteuid
        module.dispatch, module.sys.argv = original_dispatch, original_argv
    assert error_output.getvalue().strip() == "PREFLIGHT_FAILURE=PHP_RUNTIME_FPM_CONFIG_REJECTED"
    assert "synthetic-secret" not in error_output.getvalue()

    for profile in ("8.4", "8.5"):
        socket_path = module.PHP_RUNTIME_SOCKETS[profile].as_posix()
        good_fpm_dump = f"""[26-Oct-2026 12:00:00] NOTICE: [global]
[26-Oct-2026 12:00:00] NOTICE:  daemonize = no
[26-Oct-2026 12:00:00] NOTICE: [www]
[26-Oct-2026 12:00:00] NOTICE:  user = www-data
[26-Oct-2026 12:00:00] NOTICE:  group = www-data
[26-Oct-2026 12:00:00] NOTICE:  listen = {socket_path}
"""
        module.validate_fpm_pool_listen(profile, good_fpm_dump)
        wrong_dumps = (
            good_fpm_dump.replace(socket_path, "/run/php/php7.4-fpm.sock"),
            good_fpm_dump.replace("[www]", "[other]"),
            good_fpm_dump.replace(f"listen = {socket_path}\n", f"listen = {socket_path}\n[26-Oct-2026 12:00:00] NOTICE:  listen = /run/php/other.sock\n"),
            good_fpm_dump + f"[26-Oct-2026 12:00:00] NOTICE: [other]\n[26-Oct-2026 12:00:00] NOTICE:  listen = {socket_path}\n",
            good_fpm_dump.replace("user = www-data", "user = deploy"),
            good_fpm_dump.replace("group = www-data", "group = deploy"),
            good_fpm_dump.replace("user = www-data", "user = root"),
            good_fpm_dump.replace("group = www-data", "group = root"),
            good_fpm_dump.replace("user = www-data\n", ""),
            good_fpm_dump.replace("group = www-data\n", ""),
            good_fpm_dump.replace("user = www-data\n", "user = www-data\n[26-Oct-2026 12:00:00] NOTICE:  user = deploy\n"),
            good_fpm_dump.replace("group = www-data\n", "group = www-data\n[26-Oct-2026 12:00:00] NOTICE:  group = root\n"),
        )
        for bad_dump in wrong_dumps:
            try:
                module.validate_fpm_pool_listen(profile, bad_dump)
            except ValueError:
                pass
            else:
                raise AssertionError(f"wrong effective FPM pool listener passed for PHP {profile}")

        main_identity = {
            "pid": 1234,
            "executable": module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(),
            "cgroup_paths": {module.PHP_RUNTIME_FPM_CGROUPS[profile]},
            "command_line_uses_config": True,
        }
        module.validate_fpm_main_process(profile, **main_identity)
        assert module.command_line_uses_fpm_config(profile, [module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(), "--nodaemonize", "--fpm-config", module.PHP_RUNTIME_FPM_CONFIGS[profile].as_posix()])
        assert module.command_line_uses_fpm_config(profile, [f"php-fpm: master process ({module.PHP_RUNTIME_FPM_CONFIGS[profile].as_posix()})"])
        assert not module.command_line_uses_fpm_config(profile, [module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(), "--fpm-config", "/tmp/untrusted-fpm.conf"])
        for key, value in (
            ("executable", module.PHP_RUNTIME_FPM_BINARIES["8.5" if profile == "8.4" else "8.4"].as_posix()),
            ("cgroup_paths", {"/system.slice/unrelated.service"}),
            ("command_line_uses_config", False),
        ):
            invalid_identity = dict(main_identity)
            invalid_identity[key] = value
            try:
                module.validate_fpm_main_process(profile, **invalid_identity)
            except ValueError:
                pass
            else:
                raise AssertionError(f"incorrect PHP {profile} FPM service identity passed")

        listener_line = f"u_str LISTEN 0 511 {socket_path} * 0 users:((\"php-fpm8.{profile[-1]}\",pid=1234,fd=8)) ino:98765 sk:1"
        inode, owners = module.parse_unix_listener_records(listener_line, socket_path)
        assert inode == 98765 and owners == [(1234, 8)]
        valid_evidence = {
            "executable": module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(),
            "cgroup_paths": {module.PHP_RUNTIME_FPM_CGROUPS[profile]},
            "fd_is_socket": True,
        }
        module.validate_fpm_listener_processes(profile, inode, inode, owners, lambda pid, fd: valid_evidence)
        try:
            module.validate_fpm_listener_processes(profile, inode, inode + 1, owners, lambda pid, fd: valid_evidence)
        except ValueError:
            pass
        else:
            raise AssertionError("listener inode that differs from the configured socket path was accepted")
        for invalid_output in (
            "",
            f"u_str ESTAB 0 511 {socket_path} * 0 users:((\"php-fpm\",pid=1234,fd=8)) ino:98765",
            f"u_str LISTEN 0 511 {socket_path} * 0 ino:98765",
        ):
            try:
                module.parse_unix_listener_records(invalid_output, socket_path)
            except ValueError:
                pass
            else:
                raise AssertionError("stale, inactive, or ownerless FPM socket passed listener validation")
        for invalid_evidence in (
            {"executable": "/usr/bin/python3", "cgroup_paths": {module.PHP_RUNTIME_FPM_CGROUPS[profile]}, "fd_is_socket": True},
            {"executable": module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(), "cgroup_paths": {"/system.slice/other.service"}, "fd_is_socket": True},
            {"executable": module.PHP_RUNTIME_FPM_BINARIES[profile].as_posix(), "cgroup_paths": {module.PHP_RUNTIME_FPM_CGROUPS[profile]}, "fd_is_socket": False},
        ):
            try:
                module.validate_fpm_listener_processes(profile, inode, inode, owners, lambda pid, fd, item=invalid_evidence: item)
            except ValueError:
                pass
            else:
                raise AssertionError("foreign process, service, or socket inode passed FPM listener validation")

    if os.name != "nt":
        ss_binary = shutil.which("ss")
        if not ss_binary:
            raise AssertionError("Linux listener integration requires the iproute2 ss utility")
        with tempfile.TemporaryDirectory() as temporary:
            live_path = str(Path(temporary) / "live-listener.sock")
            listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            listener.bind(live_path)
            listener.listen(1)
            try:
                ss = subprocess.run([ss_binary, "-xlepH"], check=True, capture_output=True, text=True, timeout=5)
                inode, owners = module.parse_unix_listener_records(ss.stdout, live_path)
                assert os.getpid() in {pid for pid, _ in owners}, "real Unix listener is not attributed to its owning process"
                targets = [(pid, fd, os.readlink(f"/proc/{pid}/fd/{fd}")) for pid, fd in owners]
                assert os.lstat(live_path).st_ino == inode, "ss listener inode did not match the bound socket path inode"
                assert all(stat.S_ISSOCK(os.stat(f"/proc/{pid}/fd/{fd}").st_mode) for pid, fd in owners), f"ss listener owners no longer hold socket descriptors: {targets!r}"
            finally:
                listener.close()

    for profile in ("8.4", "8.5"):
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
                for name in ("PHP_RUNTIME_LINK", "PHP_RUNTIME_FILES", "GATE", "SCHEDULER_PAUSE", "WORKERS_DISABLED", "open_root_trusted_directory", "active_php_profile", "runtime_snippet", "php_runtime_preflight", "require_regular", "validate_nginx_runtime_dump", "run")
            }
            try:
                module.PHP_RUNTIME_LINK = active_link
                module.PHP_RUNTIME_FILES = profile_files
                module.GATE = gate
                module.SCHEDULER_PAUSE = pause
                module.WORKERS_DISABLED = disabled
                module.open_root_trusted_directory = lambda path: os.open(path, os.O_RDONLY | os.O_DIRECTORY)
                module.active_php_profile = lambda: next(profile for profile, path in profile_files.items() if os.readlink(active_link) == str(path))
                module.runtime_snippet = lambda profile: None
                module.php_runtime_preflight = lambda profile: None
                module.require_regular = lambda path, **kwargs: (_ for _ in ()).throw(ValueError("unsafe marker")) if path.is_symlink() or not path.is_file() else path.stat()
                failure_state = {"fail_nginx_once": False, "fail_reload_once": False}
                nginx_state = {}
                original_nginx_validator = module.validate_nginx_runtime_dump
                module.validate_nginx_runtime_dump = lambda output: validate_nginx_fixture(output, nginx_state["files"])

                def fake_runtime_run(argv, **kwargs):
                    if argv[:2] == ["/usr/bin/supervisorctl", "status"]:
                        name = argv[-1]
                        return subprocess.CompletedProcess(argv, 0, stdout=f"{name} STOPPED\n")
                    if argv == ["/usr/sbin/nginx", "-t"] and failure_state["fail_nginx_once"]:
                        failure_state["fail_nginx_once"] = False
                        raise subprocess.CalledProcessError(1, argv)
                    if argv == ["/usr/bin/systemctl", "reload", "nginx"] and failure_state["fail_reload_once"]:
                        failure_state["fail_reload_once"] = False
                        raise subprocess.CalledProcessError(1, argv)
                    if argv == ["/usr/sbin/nginx", "-T"]:
                        dump, files = nginx_fixture(profile=module.active_php_profile())
                        nginx_state["files"] = files
                        return subprocess.CompletedProcess(argv, 0, stdout=dump)
                    return subprocess.CompletedProcess(argv, 0, stdout="")

                module.run = fake_runtime_run
                module.php_runtime_preflight = lambda profile: (_ for _ in ()).throw(ValueError("synthetic preflight refusal"))
                try:
                    module.switch_php_runtime("8.5")
                except ValueError:
                    pass
                else:
                    raise AssertionError("failed preflight permitted a runtime selector change")
                assert module.active_php_profile() == "8.4", "preflight failure changed the active selector"
                assert gate.is_file() and pause.is_file() and disabled.is_file()
                module.php_runtime_preflight = lambda profile: None
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

                failure_state["fail_reload_once"] = True
                try:
                    module.switch_php_runtime("8.5")
                except ValueError:
                    pass
                else:
                    raise AssertionError("failed Nginx reload did not abort runtime switch")
                assert module.active_php_profile() == "8.4", "reload failure did not restore the previous selector"
                assert gate.is_file() and pause.is_file() and disabled.is_file(), "reload failure removed a fail-closed marker"

                preflight_calls = []

                def fail_post_switch_preflight(profile):
                    preflight_calls.append((profile, module.active_php_profile()))
                    if len(preflight_calls) == 2:
                        raise ValueError("synthetic post-switch listener failure")

                module.php_runtime_preflight = fail_post_switch_preflight
                try:
                    module.switch_php_runtime("8.5")
                except ValueError:
                    pass
                else:
                    raise AssertionError("post-switch listener failure did not abort runtime switch")
                assert preflight_calls[0] == ("8.5", "8.4") and preflight_calls[1] == ("8.5", "8.5")
                assert module.active_php_profile() == "8.4", "post-switch preflight failure did not restore the previous selector"
                assert gate.is_file() and pause.is_file() and disabled.is_file(), "post-switch preflight failure removed a fail-closed marker"
            finally:
                module.validate_nginx_runtime_dump = original_nginx_validator
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
        module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout="user::rw-\ngroup::r--\nother::r--\n\n")
        module.supervisor_runtime_contract(allow_legacy=True)
        module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout="user::rw-\ngroup::r--\nother::r--\n\n\n")
        try:
            module.supervisor_runtime_contract(allow_legacy=True)
        except ValueError:
            pass
        else:
            raise AssertionError("Supervisor ACL with multiple extra blank lines was accepted")
        for invalid_acl in (
            "user::rw-\ngroup::r--\nother::r-x\n",
            "user::rw-\ngroup::r--\nother::r--\nuser:deploy:r--\n",
            "user::rw-\ngroup::r--\nother::r--\ndefault:user::rwx\n",
        ):
            module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout=invalid_acl)
            try:
                module.supervisor_runtime_contract(allow_legacy=True)
            except ValueError:
                pass
            else:
                raise AssertionError("Supervisor ACL with non-baseline entries was accepted")
        module.run = lambda argv, **kwargs: subprocess.CompletedProcess(argv, 0, stdout="user::rw-\ngroup::r--\nother::r--\n")
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
    original_nginx_validator = module.validate_nginx_runtime_dump
    original_run = module.run
    try:
        module.runtime_snippet = lambda profile: None
        module.active_php_profile = lambda: "8.4"
        good_dump, good_sources = nginx_fixture("8.4")
        validate_nginx_fixture(good_dump, good_sources)
        parsed_sections = module._nginx_dump_sections(good_dump)
        assert parsed_sections == good_sources, "single nginx -T section-separator LF was not removed exactly"
        last_source_path = next(reversed(good_sources))
        assert parsed_sections[last_source_path] == good_sources[last_source_path], (
            "last source section did not preserve its own trailing newline"
        )
        validate_nginx_fixture(good_dump + "\n", good_sources)
        try:
            validate_nginx_fixture(good_dump + "\n\n", good_sources)
        except ValueError:
            pass
        else:
            raise AssertionError("multiple final-section framing LFs were accepted")

        no_final_newline_sources = dict(good_sources)
        no_final_newline_sources[last_source_path] = no_final_newline_sources[last_source_path].rstrip("\n")
        no_final_newline_dump = nginx_dump(no_final_newline_sources)
        validate_nginx_fixture(no_final_newline_dump, no_final_newline_sources)
        validate_nginx_fixture(no_final_newline_dump + "\n", no_final_newline_sources)
        try:
            validate_nginx_fixture(no_final_newline_dump + "\n\n", no_final_newline_sources)
        except ValueError:
            pass
        else:
            raise AssertionError("final section with two extra LFs was accepted")

        empty_wildcard_sources = dict(good_sources)
        root_source = empty_wildcard_sources["/etc/nginx/nginx.conf"]
        empty_wildcard_sources["/etc/nginx/nginx.conf"] = root_source.replace(
            "    include /etc/nginx/sites-enabled/*;\n",
            "    include /etc/nginx/modules-enabled/*.conf;\n"
            "    include /etc/nginx/conf.d/*.conf;\n"
            "    include /etc/nginx/sites-enabled/*;\n",
        )
        def empty_wildcard_resolver(pattern, source_paths):
            return module._resolve_nginx_include(
                pattern,
                source_paths,
                filesystem_glob=lambda resolved: sorted(
                    path for path in empty_wildcard_sources if fnmatch.fnmatchcase(path, resolved)
                ),
                filesystem_listdir=lambda directory: [],
            )
        validate_nginx_fixture(
            nginx_dump(empty_wildcard_sources),
            empty_wildcard_sources,
            empty_wildcard_resolver,
        )
        assert module._resolve_nginx_include(
            "/etc/nginx/modules-enabled/*.conf",
            good_sources,
            filesystem_glob=lambda resolved: [],
            filesystem_listdir=lambda directory: [],
        ) == [], "proven-empty wildcard include was rejected"
        for empty_mismatch_sources, empty_mismatch_matches in (
            (list(good_sources) + ["/etc/nginx/modules-enabled/50-module.conf"], []),
            (list(good_sources), ["/etc/nginx/modules-enabled/50-module.conf"]),
        ):
            try:
                module._resolve_nginx_include(
                    "/etc/nginx/modules-enabled/*.conf",
                    empty_mismatch_sources,
                    filesystem_glob=lambda resolved, matches=empty_mismatch_matches: matches,
                    filesystem_listdir=lambda directory: [],
                )
            except ValueError:
                pass
            else:
                raise AssertionError("empty wildcard include with mismatched sources was accepted")
        try:
            module._resolve_nginx_include(
                "/etc/nginx/modules-enabled/missing.conf",
                good_sources,
                filesystem_glob=lambda resolved: [],
            )
        except ValueError:
            pass
        else:
            raise AssertionError("missing non-wildcard include was accepted as empty")
        try:
            module._resolve_nginx_include(
                "/etc/nginx/modules-enabled/*.conf",
                good_sources,
                filesystem_glob=lambda resolved: [],
                filesystem_listdir=lambda directory: ["50-module.conf"],
            )
        except ValueError:
            pass
        else:
            raise AssertionError("filesystem match omitted by glob was accepted")

        blank_line_sources = dict(good_sources)
        first_source_path = next(iter(blank_line_sources))
        blank_line_sources[first_source_path] += "\n"
        blank_line_sections = module._nginx_dump_sections(nginx_dump(blank_line_sources))
        assert blank_line_sections[first_source_path] == blank_line_sources[first_source_path], (
            "source-owned trailing blank line was not preserved"
        )

        first_source_content = good_sources[first_source_path]
        first_marker = f"# configuration file {first_source_path}:\n"
        next_source_path = list(good_sources)[1]
        one_separator = first_marker + first_source_content + f"\n# configuration file {next_source_path}:\n"
        assert one_separator in good_dump, "Nginx fixture lacks its single framing LF"
        extra_separator_dump = good_dump.replace(
            one_separator,
            first_marker + first_source_content + f"\n\n# configuration file {next_source_path}:\n",
            1,
        )
        try:
            validate_nginx_fixture(extra_separator_dump, good_sources)
        except ValueError:
            pass
        else:
            raise AssertionError("multiple section-separator LFs were accepted as source content")

        changed_dump = good_dump.replace("events {}\n", "events { }\n", 1)
        try:
            validate_nginx_fixture(changed_dump, good_sources)
        except ValueError:
            pass
        else:
            raise AssertionError("real Nginx source-content difference was accepted")

        assert module._resolve_nginx_include(
            "fastcgi_params",
            good_sources,
            filesystem_glob=lambda resolved: sorted(
                path for path in good_sources if fnmatch.fnmatchcase(path, resolved)
            ),
        ) == ["/etc/nginx/fastcgi_params"], "relative include did not resolve against the Nginx configuration prefix"
        assert module._resolve_nginx_include(
            "fastcgi_*",
            good_sources,
            filesystem_glob=lambda resolved: sorted(
                path for path in good_sources if fnmatch.fnmatchcase(path, resolved)
            ),
        ) == ["/etc/nginx/fastcgi_params"], "static relative include mask did not resolve against the Nginx configuration prefix"
        for rejected_include in ("../fastcgi_params", "./fastcgi_params", "missing_params", "$fastcgi_params"):
            try:
                module._resolve_nginx_include(
                    rejected_include,
                    good_sources,
                    filesystem_glob=lambda resolved: sorted(
                        path for path in good_sources if fnmatch.fnmatchcase(path, resolved)
                    ),
                )
            except (ValueError, OSError):
                pass
            else:
                raise AssertionError("unsafe or unresolved relative Nginx include was accepted")
        try:
            module._resolve_nginx_include(
                "fastcgi_*",
                good_sources,
                filesystem_glob=lambda resolved: [
                    "/etc/nginx/fastcgi_params",
                    "/etc/nginx/fastcgi-secret.conf",
                ],
            )
        except ValueError:
            pass
        else:
            raise AssertionError("relative include mask with an unproven filesystem match was accepted")
        for profile in ("8.4", "8.5"):
            module.active_php_profile = lambda selected=profile: selected
            dump, sources = nginx_fixture(profile)
            validate_nginx_fixture(dump, sources)

        sibling_route = good_sources["/etc/nginx/snippets/sibling-fastcgi.conf"]
        assert sibling_route == "fastcgi_pass unix:/run/php/php8.4-fpm.sock;\n", "sibling PHP 8.4 route fixture changed"
        module.active_php_profile = lambda: "8.4"

        bad_fixtures = []
        bad_fixtures.append(nginx_fixture("8.4", staging_name="staging.wpcaregrid.com sibling.example"))
        bad_fixtures.append(nginx_fixture("8.4", staging_name="other.example"))
        bad_fixtures.append(nginx_fixture("8.4", staging_name="staging.wpcaregrid.com *.example"))
        bad_fixtures.append(nginx_fixture("8.4", staging_name=r"staging.wpcaregrid.com ~^sibling\.example$"))

        sibling_selector_dump, sibling_selector_sources = nginx_fixture("8.4")
        sibling_selector_sources["/etc/nginx/sites-enabled/sibling"] = sibling_selector_sources["/etc/nginx/sites-enabled/sibling"].replace(
            "include /etc/nginx/snippets/sibling-fastcgi.conf;", f"include {module.PHP_RUNTIME_LINK.as_posix()};"
        )
        sibling_selector_dump = nginx_dump(sibling_selector_sources)
        bad_fixtures.append((sibling_selector_dump, sibling_selector_sources))

        outside_dump, outside_sources = nginx_fixture("8.4")
        outside_sources["/etc/nginx/nginx.conf"] = outside_sources["/etc/nginx/nginx.conf"].replace(
            "include /etc/nginx/sites-enabled/*;", f"include {module.PHP_RUNTIME_LINK.as_posix()};\n    include /etc/nginx/sites-enabled/*;"
        )
        outside_dump = nginx_dump(outside_sources)
        bad_fixtures.append((outside_dump, outside_sources))

        duplicate_dump, duplicate_sources = nginx_fixture("8.4")
        duplicate_sources["/etc/nginx/snippets/wpcaregrid-staging-handler.conf"] += f"include {module.PHP_RUNTIME_LINK.as_posix()};\n"
        duplicate_dump = nginx_dump(duplicate_sources)
        bad_fixtures.append((duplicate_dump, duplicate_sources))

        competing_path = "/etc/nginx/snippets/staging-extra-location.conf"
        bad_fixtures.append(nginx_fixture("8.4", extra_staging_include=competing_path))

        cycle_dump, cycle_sources = nginx_fixture("8.4", handler_override="include /etc/nginx/snippets/cycle-a.conf;\n")
        cycle_sources["/etc/nginx/snippets/cycle-a.conf"] = "include /etc/nginx/snippets/cycle-b.conf;\n"
        cycle_sources["/etc/nginx/snippets/cycle-b.conf"] = "include /etc/nginx/snippets/cycle-a.conf;\n"
        cycle_dump = nginx_dump(cycle_sources)
        bad_fixtures.append((cycle_dump, cycle_sources))

        missing_dump, missing_sources = nginx_fixture("8.4", handler_override="include /etc/nginx/snippets/missing.conf;\n")
        missing_dump = nginx_dump(missing_sources)
        bad_fixtures.append((missing_dump, missing_sources))

        variable_dump, variable_sources = nginx_fixture("8.4", handler_override="include $unsafe_path;\n")
        variable_dump = nginx_dump(variable_sources)
        bad_fixtures.append((variable_dump, variable_sources))

        for bad_dump, bad_sources in bad_fixtures:
            try:
                validate_nginx_fixture(bad_dump, bad_sources)
            except (ValueError, KeyError, OSError):
                pass
            else:
                raise AssertionError("unsafe Nginx include context or competing PHP route was accepted")

        unproven_wildcard_dump, unproven_wildcard_sources = nginx_fixture("8.4")
        def incomplete_wildcard(pattern, source_paths):
            if pattern == "/etc/nginx/sites-enabled/*":
                return ["/etc/nginx/sites-enabled/staging", "/etc/nginx/sites-enabled/unlisted"]
            return sorted(path for path in source_paths if fnmatch.fnmatchcase(path, pattern))
        try:
            validate_nginx_fixture(unproven_wildcard_dump, unproven_wildcard_sources, incomplete_wildcard)
        except (ValueError, KeyError, OSError):
            pass
        else:
            raise AssertionError("wildcard include with an unlisted filesystem match was accepted")

        mismatched_source_dump, mismatched_sources = nginx_fixture("8.4")
        try:
            module.validate_nginx_runtime_dump(
                mismatched_source_dump,
                source_reader=lambda path: mismatched_sources[path] + "# changed after nginx -T\n",
                include_resolver=lambda pattern, paths: sorted(path for path in paths if fnmatch.fnmatchcase(path, pattern)),
            )
        except ValueError:
            pass
        else:
            raise AssertionError("Nginx dump whose source differs from trusted file was accepted")

        current_sources = {"files": good_sources}
        module.active_php_profile = lambda: "8.4"
        module.validate_nginx_runtime_dump = lambda output: validate_nginx_fixture(output, current_sources["files"])

        def fake_nginx_run(argv, **kwargs):
            return subprocess.CompletedProcess(argv, 0, stdout=good_dump if argv[-1] == "-T" else "")

        module.run = fake_nginx_run
        module.nginx_runtime_contract("8.4")
    finally:
        module.runtime_snippet = original_snippet
        module.active_php_profile = original_active_profile
        module.validate_nginx_runtime_dump = original_nginx_validator
        module.run = original_run

    controller = CONTROLLER.read_text(encoding="utf-8")
    controller_lines = controller.splitlines()
    assert "readonly COMPOSER_BIN='/usr/local/bin/composer'" in controller
    assert "readonly COMPOSER_SHA256='7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6'" in controller
    assert "readonly COMPOSER_VERSION='2.10.3'" in controller
    assert '"$PHP_BIN" "$COMPOSER_BIN" "$@"' in controller
    assert 'composer_binary_preflight "$COMPOSER_BIN" "$COMPOSER_SHA256" "$COMPOSER_VERSION" 0 0 "$PHP_BIN"' in controller
    assert '/usr/bin/composer' not in controller, "Composer must not fall back to the obsolete path"

    bash = shutil.which("bash")
    if bash and os.name != "nt":
        with tempfile.TemporaryDirectory(prefix="composer-preflight-") as temporary:
            fixture_dir = Path(temporary)
            composer_fixture = fixture_dir / "composer"
            php_fixture = fixture_dir / "php"
            composer_fixture.write_text("#!/bin/sh\nexit 0\n", encoding="ascii")
            composer_fixture.chmod(0o755)
            php_fixture.write_text(
                "#!/bin/sh\nprintf '%s\\n' \"$FAKE_COMPOSER_VERSION\"\n",
                encoding="ascii",
            )
            php_fixture.chmod(0o755)
            composer_sha = hashlib.sha256(composer_fixture.read_bytes()).hexdigest()
            php_failure_fixture = fixture_dir / "php-failure"
            php_failure_fixture.write_text("#!/bin/sh\nexit 1\n", encoding="ascii")
            php_failure_fixture.chmod(0o755)

            def run_preflight(
                php_binary,
                *,
                expected_version="Composer version 2.10.3 2026-01-01",
                digest=composer_sha,
                uid=os.getuid(),
                gid=os.getgid(),
                path=composer_fixture,
            ):
                return subprocess.run(
                    [
                        bash,
                        "-c",
                        'source "$1"; composer_binary_preflight "$2" "$3" 2.10.3 "$4" "$5" "$6"',
                        "composer-preflight-negative-test",
                        str(CONTROLLER),
                        str(path),
                        digest,
                        str(uid),
                        str(gid),
                        str(php_binary),
                    ],
                    check=False,
                    env={**os.environ, "FAKE_COMPOSER_VERSION": expected_version},
                )

            preflight_harness = r'''source "$1"
path="$2"
digest="$3"
uid="$4"
gid="$5"
php_bin="$6"
composer_binary_preflight "$path" "$digest" 2.10.3 "$uid" "$gid" "$php_bin" || exit 31
if composer_binary_preflight "$path" "$(printf '%064d' 0)" 2.10.3 "$uid" "$gid" "$php_bin"; then exit 32; fi
if composer_binary_preflight "$path" "$digest" 2.10.3 "$((uid + 1))" "$gid" "$php_bin"; then exit 33; fi
if composer_binary_preflight "$path" "$digest" 2.10.3 "$uid" "$((gid + 1))" "$php_bin"; then exit 34; fi
'''
            subprocess.run(
                [
                    bash,
                    "-c",
                    preflight_harness,
                    "composer-preflight-test",
                    str(CONTROLLER),
                    str(composer_fixture),
                    composer_sha,
                    str(os.getuid()),
                    str(os.getgid()),
                    str(php_fixture),
                ],
                check=True,
                env={**os.environ, "FAKE_COMPOSER_VERSION": "Composer version 2.10.3 2026-01-01"},
            )
            wrong_version = run_preflight(php_fixture, expected_version="Composer version 2.10.30 2026-01-01")
            assert wrong_version.returncode != 0, "a similarly prefixed Composer version must be rejected"
            php_failed = run_preflight(php_failure_fixture)
            assert php_failed.returncode != 0, "a failed PHP invocation must fail the Composer preflight"
            bad_mode = subprocess.run(["/usr/bin/chmod", "0700", str(composer_fixture)], check=False)
            assert bad_mode.returncode == 0
            wrong_mode = run_preflight(php_fixture)
            assert wrong_mode.returncode != 0, "non-0755 Composer files must be rejected"
            composer_fixture.chmod(0o755)
            symlink_fixture = fixture_dir / "composer-link"
            symlink_fixture.symlink_to(composer_fixture)
            wrong_type = run_preflight(php_fixture, path=symlink_fixture)
            assert wrong_type.returncode != 0, "Composer symlinks must be rejected"
    gate_line = next(i for i, line in enumerate(controller_lines) if "privileged gate-on" in line and i > 700)
    switch_line = next(i for i, line in enumerate(controller_lines) if "privileged php-runtime-switch \"$PHP_TARGET_PROFILE\"" in line)
    disabled_line = next(i for i, line in enumerate(controller_lines) if "privileged worker-runtime-disable" in line and i > gate_line)
    workers_stop_line = next(i for i, line in enumerate(controller_lines) if "privileged worker stop-all" in line and i > disabled_line)
    assert gate_line < disabled_line < workers_stop_line < switch_line, "runtime switch must follow the active gate, persistent worker disable, and drained workers"
    assert 'workers_running || return 1\n    privileged php-runtime-preflight "$PHP_TARGET_PROFILE" || return 1\n    release_gate || return 1' in controller, "recovery must revalidate the active runtime listener before releasing traffic"
    assert 'workers_stopped || fail \'One or more SaaS workers are not stopped before traffic is reopened.\'\n    privileged php-runtime-preflight "$PHP_TARGET_PROFILE" || fail \'The selected PHP-FPM pool or live listener changed before traffic was reopened.\'\n    release_gate' in controller, "marketing-only deployment must verify workers remain stopped and PHP-FPM remains healthy before traffic is reopened"
    assert "readonly GATE_DIR='/var/lib/wpcaregrid'" in controller
    assert 'readonly WORKERS_DISABLED_FILE="${GATE_DIR}/workers-disabled"' in controller
    assert "readonly SCHEDULER_PAUSE_FILE=\"${GATE_DIR}/scheduler-paused\"" in controller
    assert 'root_marker_present "$WORKERS_DISABLED_FILE"' in controller
    assert 'root_marker_present "$SCHEDULER_PAUSE_FILE"' in controller
    assert 'target_profile="$(read_marketing_release_profile)"' in controller
    assert '[[ "$target_profile" == \'marketing_only\' ]]' in controller
    assert 'check_system_health allow-background-fenced' in controller
    assert 'if (( TARGET_MARKETING_RELEASE )); then' in controller
    assert "TARGET_MARKETING_RELEASE=1" in controller
    marketing_success_start = controller.index('target_profile="$(read_marketing_release_profile)"')
    marketing_success_end = controller.index('mark_gate_released', marketing_success_start)
    marketing_success = controller[marketing_success_start:marketing_success_end]
    forbidden_marketing_operations = (
        'php artisan queue:restart',
        'worker-runtime-enable',
        'php-runtime-supervisor-update',
        'worker start-all',
        'resume_scheduler',
        'wait_for_scheduler',
    )
    assert not any(operation in marketing_success for operation in forbidden_marketing_operations), "marketing-only success must keep background execution fenced"
    assert 'fence_marketing_background "$target_profile"' in marketing_success
    assert 'workers_stopped' in marketing_success
    assert 'workers_running' not in marketing_success
    assert 'pending jobs are retained' in controller
    assert 'queue:clear' not in controller and 'DELETE FROM jobs' not in controller
    target_failure_start = controller.index('if (( TARGET_MARKETING_RELEASE )); then')
    target_failure_end = controller.index('if (( BASELINE_BACKGROUND_RESTRICTED )); then', target_failure_start)
    target_failure = controller[target_failure_start:target_failure_end]
    assert 'worker-runtime-disable' in target_failure and 'stop_workers_for_rollback' in target_failure
    assert 'scheduler-pause' in target_failure and 'workers_stopped' in target_failure
    assert 'release_scheduler_lock_preserving_pause' in target_failure
    assert 'return 1' in target_failure
    forbidden_failure_recovery = ('queue:restart', 'worker-runtime-enable', 'worker start-all', 'resume_scheduler', 'release_gate')
    assert not any(operation in target_failure for operation in forbidden_failure_recovery), "failed marketing-only transition must stay gated and must not resume SaaS work"
    baseline_fenced_start = controller.index('if (( BASELINE_BACKGROUND_RESTRICTED )); then', target_failure_end)
    baseline_fenced_end = controller.index("log 'Guarded recovery restored the marketing-only baseline", baseline_fenced_start)
    baseline_fenced_recovery = controller[baseline_fenced_start:baseline_fenced_end]
    assert 'worker-runtime-disable' in baseline_fenced_recovery and 'scheduler-pause' in baseline_fenced_recovery
    assert 'workers_stopped' in baseline_fenced_recovery and 'allow-background-fenced' in baseline_fenced_recovery
    assert not any(operation in baseline_fenced_recovery for operation in forbidden_failure_recovery[:-1]), "marketing-only baseline recovery must preserve the background fence"
    migration_recheck = 'migration_status="$(php artisan migrate:status --no-interaction)" || return 1\n        grep -q \'Pending\' <<<"$migration_status" && return 1'
    assert migration_recheck in baseline_fenced_recovery
    assert baseline_fenced_recovery.index(migration_recheck) < baseline_fenced_recovery.index('php artisan up') < baseline_fenced_recovery.index('release_gate || return 1'), "pending or unreadable migration status must keep the marketing-only baseline gated"
    migration_status_lines = [line.strip() for line in controller.splitlines() if 'php artisan migrate:status --no-interaction' in line]
    assert len(migration_status_lines) == 4 and all(line.startswith('migration_status="$(php artisan migrate:status --no-interaction)" || ') for line in migration_status_lines), "every migration-status read must fail closed when Artisan exits unsuccessfully"
    assert "php artisan migrate:status --no-interaction | grep" not in controller, "migration-status command errors must not be conflated with no pending migrations"
    target_switch = controller.index('git checkout --detach "$TARGET_SHA"')
    target_flag = controller.index('TARGET_MARKETING_RELEASE=1')
    assert target_flag < target_switch, "failure recovery must know the target is marketing-only before any source switch"

    bash = shutil.which("bash")
    if bash and os.name != "nt":
        script_path = str(CONTROLLER)
        harness = r'''source "$1"
parse_marketing_release_profile 'profile: marketing_only' >/dev/null
if parse_marketing_release_profile 'profile: invitation_beta' >/dev/null; then exit 21; fi
if parse_marketing_release_profile '' >/dev/null; then exit 22; fi
calls=''
privileged() {
    local rendered
    printf -v rendered '%s ' "$@"
    calls+="privileged:${rendered% }|"
}
workers_stopped() { calls+='workers-stopped|'; }
release_scheduler_lock_preserving_pause() { calls+='release-lock-preserve-pause|'; }
root_marker_present() { calls+="marker:$1|"; }
fence_marketing_background marketing_only || exit 23
case "$calls" in
  *'privileged:worker-runtime-disable|'*'privileged:worker stop-all|'*'privileged:scheduler-pause|'*'marker:/var/lib/wpcaregrid/workers-disabled|'*'marker:/var/lib/wpcaregrid/scheduler-paused|'*'workers-stopped|'*'release-lock-preserve-pause|'*) ;;
  *) exit 24 ;;
esac
case "$calls" in *'queue:restart'*|*'worker-runtime-enable'*|*'worker start-all'*|*'scheduler-resume'*) exit 25 ;; esac
prior_calls="$calls"
if fence_marketing_background unknown >/dev/null 2>&1; then exit 26; fi
[[ "$calls" == "$prior_calls" ]] || exit 27
'''
        subprocess.run([bash, "-c", harness, "marketing-fence-policy-test", script_path], check=True)
    recovery_switch_line = next(i for i, line in enumerate(controller_lines) if "privileged php-runtime-switch \"$PHP_BASELINE_PROFILE\"" in line)
    baseline_checkout_line = next(i for i, line in enumerate(controller_lines) if "rollback_code_to_baseline || return 1" in line)
    assert recovery_switch_line < baseline_checkout_line, "runtime must return to baseline before code rollback"
    assert "composer check-platform-reqs --no-dev --lock" in controller
    assert "update-alternatives" not in controller
    assert 'if ! post_deploy_smoke || ! runtime_cache_access_ok || ! workers_stopped; then' in controller
    assert "privileged gate-on || log 'Could not confirm the Nginx gate marker" in controller
    launcher = (ROOT / "scripts/run-php-runtime.sh").read_text(encoding="utf-8")
    assert "queue:work) blocked /var/lib/wpcaregrid/workers-disabled" in launcher
    assert "schedule:run) blocked /var/lib/wpcaregrid/scheduler-paused" in launcher
    assert "/var/lib/wpcaregrid" in launcher.split("for directory in ", 1)[1].split("; do", 1)[0]
    assert 'stat --no-dereference -c \'%u\' "$PROFILE_LINK"' in launcher
    assert '[[ -f "$PHP_BIN" && -x "$PHP_BIN" && ! -L "$PHP_BIN" ]]' in launcher
    launcher_hash = hashlib.sha256((ROOT / "scripts/run-php-runtime.sh").read_bytes()).hexdigest()
    assert launcher_hash == module.PHP_RUNTIME_LAUNCHER_SHA256
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
