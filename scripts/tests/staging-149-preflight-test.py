#!/usr/bin/env python3
"""Fixture tests for the read-only staging-next 1.4.9 host preflight (scripts/staging-149-preflight.py)."""

from __future__ import annotations

import ast
import contextlib
import hashlib
import importlib.util
import io
import os
import re
import shutil
import socket
import stat
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("staging_149_preflight", ROOT / "scripts" / "staging-149-preflight.py")
M = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = M
SPEC.loader.exec_module(M)
SOURCE = (ROOT / "scripts" / "staging-149-preflight.py").read_text(encoding="utf-8")

UID, GID = os.getuid(), os.getgid()
ROUTE_OK = """
http {
    include /etc/nginx/conf.d/*.conf;
}
"""
VHOST = """
server {
    listen 443 ssl;
    server_name staging-next.wpcaregrid.com;
    location ~ \\.php$ {
        include snippets/selector.conf;
    }
}
server { listen 443 ssl; server_name stg1.wpcaregrid.com; location / { fastcgi_pass unix:/run/php/php8.4-fpm.sock; } }
"""
SYSTEMCTL = {
    "php8.5-fpm.service": "LoadState=loaded\nActiveState=active\nUnitFileState=enabled\n",
    "nginx.service": "LoadState=loaded\nActiveState=active\nUnitFileState=enabled\n",
    "wpcaregrid-staging-next-scheduler.timer": "LoadState=not-found\nActiveState=inactive\nUnitFileState=\n",
    "mysql.service": "LoadState=loaded\nActiveState=active\nUnitFileState=enabled\n",
}


class Fixture(unittest.TestCase):
    def setUp(self):
        base = tempfile.mkdtemp(prefix="p149-", dir="/tmp" if os.path.isdir("/tmp") else None)
        self.root = os.path.realpath(base)
        self.addCleanup(shutil.rmtree, self.root, ignore_errors=True)
        for patcher in (mock.patch.object(M, "DEPLOY_UID", UID + 7), mock.patch.object(M, "FPM_UID", UID),
                        mock.patch.object(M, "FPM_GID", GID)):
            patcher.start()
            self.addCleanup(patcher.stop)
        self.commands = []
        self.systemctl = dict(SYSTEMCTL)
        self.extra_run = {}
        self.php_version = b"8.5.1"
        self.ps = b"/usr/sbin/nginx -g daemon off;\n"
        self.build()

    # ------------------------------------------------------------------ fixture tree
    def path(self, name):
        return self.root + name

    def put(self, name, data=b"", mode=0o644, directory=False):
        target = Path(self.path(name))
        if directory:
            target.mkdir(parents=True, exist_ok=True)
        else:
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data if isinstance(data, bytes) else data.encode())
        target.chmod(mode)
        return target

    def build(self):
        for directory in ("/usr/local", "/etc", "/var/lib", "/home/deploy"):
            self.put(directory, directory=True, mode=0o755)
        self.put("/etc/os-release", 'PRETTY_NAME="Ubuntu 26.04.1 LTS"\nVERSION_ID="26.04"\n')
        self.put("/proc/meminfo", "MemTotal: 4000000 kB\nMemAvailable: 3000000 kB\n")
        self.put("/proc/net/tcp", "  sl local_address rem_address st\n   0: 00000000:01BB 00000000:0000 0A\n"
                                  "   1: 0100007F:0CEA 00000000:0000 0A\n")
        self.put("/proc/net/tcp6", "  sl local_address rem_address st\n")
        self.put("/proc/locks", "")
        self.put("/proc/self/mountinfo", "")
        for binary in ("/usr/bin/php8.5", "/usr/bin/php8.4", "/usr/sbin/nginx", "/usr/sbin/visudo", "/usr/bin/python3"):
            self.put(binary, b"", 0o755)
        sock_path = self.path("/run/php/php8.5-fpm.sock")
        Path(sock_path).parent.mkdir(parents=True)
        self.socket = socket.socket(socket.AF_UNIX)
        self.socket.bind(sock_path)
        self.addCleanup(self.socket.close)
        self.put("/etc/php/8.5/fpm/pool.d/www.conf", "[www]\nlisten = /run/php/php8.5-fpm.sock\n")
        self.put("/etc/nginx/nginx.conf", ROUTE_OK)
        self.put("/etc/nginx/conf.d/sites.conf", VHOST)
        self.put("/etc/nginx/snippets/selector.conf", "fastcgi_pass unix:/run/php/php8.5-fpm.sock;\n")
        self.put("/var/www/wpcaregrid-staging-new", directory=True, mode=0o755)
        self.put("/usr/local/libexec/wpcaregrid-deploy/staging-next", directory=True, mode=0o755)
        self.put("/etc/wpcaregrid-deploy/profiles", directory=True, mode=0o755)
        self.put("/etc/sudoers.d", directory=True, mode=0o755)
        self.put("/etc/passwd", f"deploy:x:{UID}:{UID}::/home/deploy:/bin/bash\n"
                                f"deploy-staging-next:x:{UID + 7}:{UID + 7}::/nonexistent:/usr/sbin/nologin\n")
        self.put("/var/lib/mysql/auto.cnf", "[auto]\nserver-uuid=11111111-2222-3333-4444-555555555555\n")

    # ------------------------------------------------------------------ runner / execution
    def runner(self, argv, timeout):
        self.commands.append(list(argv))
        name = argv[0].rsplit("/", 1)[-1]
        if name == "systemctl":
            return 0, self.systemctl.get(argv[-1], "LoadState=not-found\nActiveState=inactive\n").encode()
        if name == "php8.5":
            return 0, self.php_version
        if name == "supervisorctl":
            return self.extra_run.get("supervisorctl", (0, b"legacy-default   RUNNING   pid 12, uptime 1:00:00\n"))
        if name == "visudo":
            return self.extra_run.get("visudo", (0, b"/etc/sudoers: parsed OK\n"))
        if name == "ps":
            return 0, self.ps
        if name == "mysqld":
            return 0, b"mysqld  Ver 8.4.3 for Linux on x86_64\n"
        return None

    def run_preflight(self, euid=None):
        host = M.Host(root=self.root, euid=UID if euid is None else euid, root_uid=UID, runner=self.runner,
                      hostname="stg1")
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            code = M.main(host)
        lines = out.getvalue().splitlines()
        return code, lines

    def result(self, **kwargs):
        code, lines = self.run_preflight(**kwargs)
        finals = [line for line in lines if line.startswith("STAGING_149_PREFLIGHT=")]
        self.assertEqual(len(finals), 1)
        self.assertEqual(lines[-1], finals[0])
        return finals[0], code, lines

    def snapshot(self):
        state = {}
        for dirpath, dirnames, filenames in os.walk(self.root):
            for name in dirnames + filenames:
                full = os.path.join(dirpath, name)
                info = os.lstat(full)
                digest = hashlib.sha256(Path(full).read_bytes()).hexdigest() if stat.S_ISREG(info.st_mode) else ""
                state[full] = (info.st_mode, info.st_uid, info.st_mtime_ns, info.st_size, digest)
        return state

    def stage_sources(self, tamper=None):
        source = self.put(M.SOURCE_DIR, directory=True, mode=0o700)
        pins = {}
        for name in M.SOURCE_PINS:
            data = f"fixture-{name}".encode()
            self.put(f"{M.SOURCE_DIR}/{name}", data, 0o600)
            pins[name] = hashlib.sha256(data).hexdigest()
        self.put(M.INSTALLER, b"installer-fixture", 0o600)
        for patcher in (mock.patch.dict(M.SOURCE_PINS, pins),
                        mock.patch.object(M, "INSTALLER_SHA256", hashlib.sha256(b"installer-fixture").hexdigest())):
            patcher.start()
            self.addCleanup(patcher.stop)
        if tamper:
            self.put(f"{M.SOURCE_DIR}/{tamper}", b"tampered", 0o600)
        return source


class OutcomeTests(Fixture):
    def test_clean_host_passes_and_is_a_pure_read(self):
        before = self.snapshot()
        final, code, lines = self.result()
        self.assertEqual(final, "STAGING_149_PREFLIGHT=PASS", "\n".join(lines))
        self.assertEqual(code, 0)
        self.assertEqual(self.snapshot(), before)  # nothing created, changed or removed
        self.assertIn("# blockers: none", lines)
        # idempotent / repeatable
        self.assertEqual(self.result()[0], final)

    def test_all_status_words_appear(self):
        _, _, lines = self.result()
        statuses = {line.split("|")[2] for line in lines if line.startswith("CHECK|")}
        self.assertTrue({"REQUIRED_PRESENT", "EXPECTED_ABSENT", "UNKNOWN", "INFO"} <= statuses, statuses)

    def test_php85_missing_is_blocked_not_an_install_suggestion(self):
        os.unlink(self.path("/usr/bin/php8.5"))
        final, code, lines = self.result()
        self.assertEqual(final, "STAGING_149_PREFLIGHT=BLOCKED;CODE=php85_unavailable")
        self.assertEqual(code, 1)
        self.assertTrue(any("only PHP 8.4 is available" in line for line in lines))
        self.assertFalse(any(re.search(r"apt|install php", line, re.I) for line in lines))

    def test_nginx_pointing_at_the_wrong_fpm_socket_is_blocked(self):
        self.put("/etc/nginx/snippets/selector.conf", "fastcgi_pass unix:/run/php/php8.4-fpm.sock;\n")
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=nginx_route_mismatch")

    def test_missing_or_duplicate_vhost_is_blocked(self):
        self.put("/etc/nginx/conf.d/sites.conf", "server { listen 443 ssl; server_name other.example; }")
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=nginx_route_mismatch")
        self.put("/etc/nginx/conf.d/sites.conf", VHOST + VHOST)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=nginx_route_mismatch")

    def test_unreadable_nginx_config_is_unknown_blocked(self):
        os.unlink(self.path("/etc/nginx/nginx.conf"))
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=nginx_route_unknown")

    def test_socket_identity_fpm_unit_and_not_root(self):
        with mock.patch.object(M, "FPM_UID", UID + 5):
            self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=php85_socket_identity")
        self.systemctl["php8.5-fpm.service"] = "LoadState=loaded\nActiveState=inactive\n"
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=php85_fpm_inactive")
        self.systemctl.update(SYSTEMCTL)
        self.assertEqual(self.result(euid=UID + 999)[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=not_root")

    def test_application_directory_must_be_a_real_directory(self):
        shutil.rmtree(self.path("/var/www/wpcaregrid-staging-new"))
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=app_dir_missing")
        os.symlink(self.path("/tmp"), self.path("/var/www/wpcaregrid-staging-new"))
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=app_dir_invalid")

    def test_installer_directories_identity_and_sudoers_syntax(self):
        shutil.rmtree(self.path("/etc/wpcaregrid-deploy"))
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=installer_directory_missing")
        self.put("/etc/wpcaregrid-deploy/profiles", directory=True, mode=0o755)
        self.put("/etc/passwd", "deploy:x:1000:1000::/h:/bin/sh\n")
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=deploy_identity_mismatch")
        self.build_passwd()
        self.extra_run["visudo"] = (1, b"error")
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=sudoers_syntax")

    def build_passwd(self):
        self.put("/etc/passwd", f"deploy:x:{UID}:{UID}::/h:/bin/sh\n"
                                f"deploy-staging-next:x:{UID + 7}:{UID + 7}::/n:/sbin/nologin\n")


class StopConditionTests(Fixture):
    def stop(self, code):
        final, exit_code, _ = self.result()
        self.assertEqual(final, f"STAGING_149_PREFLIGHT=STOP;CODE={code}")
        self.assertEqual(exit_code, 2)

    def test_existing_1_4_9_destinations_are_a_conflict(self):
        for name, path in (("helper_dir", f"/usr/local/libexec/wpcaregrid-deploy/staging-next/{M.VERSION}"),
                           ("policy", f"/etc/wpcaregrid-deploy/profiles/staging-next-{M.VERSION}.json"),
                           ("sudoers", f"/etc/sudoers.d/wpcaregrid-staging-next-{M.VERSION}")):
            target = self.put(path, b"x" if "." in path.rsplit("/", 1)[-1] or name == "sudoers" else b"", 0o640)
            if name == "helper_dir":
                target.unlink()
                self.put(path, directory=True, mode=0o755)
            self.stop(f"destination_exists_{name}")
            if target.is_dir():
                shutil.rmtree(target)
            else:
                target.unlink()

    def test_phase_b_policy_must_stay_absent(self):
        self.put("/etc/wpcaregrid-deploy/profiles/staging-next-phase-b-1.4.9.json", b"{}", 0o644)
        self.stop("phase_b_policy_present")

    def test_active_fence_marker_gate_or_lock_stops(self):
        self.put(f"{M.BASE}/state", directory=True, mode=0o700)
        self.put(f"{M.BASE}/state/workers-disabled", b"x", 0o600)
        self.stop("fence_marker_present")
        os.unlink(self.path(f"{M.BASE}/state/workers-disabled"))
        self.put(f"{M.BASE}/shared/storage/framework/down", b"{}", 0o644)
        self.stop("maintenance_gate_present")
        os.unlink(self.path(f"{M.BASE}/shared/storage/framework/down"))
        lock = self.put(f"{M.BASE}/state/deploy.lock", b"", 0o600)
        info = os.lstat(lock)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=PASS")  # present but not locked
        self.put("/proc/locks", f"1: FLOCK  ADVISORY  WRITE 4242 {os.major(info.st_dev):02x}:{os.minor(info.st_dev):02x}:{info.st_ino} 0 EOF\n")
        self.stop("deploy_lock_held")

    def test_wrong_layout_metadata_and_symlinks_stop(self):
        self.put(f"{M.BASE}/state", directory=True, mode=0o755)  # must be 0700
        self.stop("staging_next_state_metadata")
        shutil.rmtree(self.path(M.BASE))
        os.makedirs(self.path("/elsewhere"))
        os.makedirs(os.path.dirname(self.path(M.BASE)), exist_ok=True)
        os.symlink(self.path("/elsewhere"), self.path(M.BASE))
        self.stop("symlink_in_staging_next_state_root")

    def test_production_paths_stop(self):
        self.put("/var/www/wpcaregrid-production", directory=True)
        self.stop("production_paths_present")

    def test_running_workers_scheduler_and_consumers_stop(self):
        self.systemctl["wpcaregrid-staging-next-scheduler.timer"] = "LoadState=loaded\nActiveState=active\n"
        self.stop("scheduler_active")
        self.systemctl.update(SYSTEMCTL)
        self.ps = b"php /var/www/wpcaregrid-staging-new/artisan queue:work --queue=default\n"
        self.stop("background_consumer_active")

    def test_unexpected_sudoers_rules_and_loose_credentials_stop(self):
        self.put("/etc/sudoers.d/wpcaregrid-staging-next-1.4.8",
                 "deploy-staging-next ALL=(root) NOPASSWD: NOSETENV: /usr/local/libexec/wpcaregrid-deploy/"
                 "staging-next/1.4.8/wpcaregrid-deploy-staging-next-priv preflight\n", 0o440)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=PASS")
        self.put("/etc/sudoers.d/wpcaregrid-extra", "deploy-staging-next ALL=(ALL) NOPASSWD: ALL\n", 0o440)
        self.stop("unexpected_sudoers_state")
        os.unlink(self.path("/etc/sudoers.d/wpcaregrid-extra"))
        self.put("/etc/wpcaregrid-deploy/credentials", directory=True, mode=0o755)
        self.put("/etc/wpcaregrid-deploy/credentials/db.json", b"canary-secret", 0o644)
        self.stop("credential_file_permissions")

    def test_legacy_helper_sudoers_rules_do_not_trigger_a_stop(self):
        self.put("/etc/sudoers.d/wpcaregrid-deploy",
                 "deploy ALL=(root) NOPASSWD: /usr/local/libexec/wpcaregrid-deploy-priv preflight\n", 0o440)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=PASS")

    def test_unexpected_rule_is_located_by_file_and_line_without_printing_its_text(self):
        self.put("/etc/sudoers.d/wpcaregrid-extra", "# c\ndeploy-staging-next ALL=(ALL) NOPASSWD: ALL\n", 0o440)
        _, _, lines = self.result()
        text = "\n".join(lines)
        self.assertIn("wpcaregrid-extra:rule@2", text)
        self.assertNotIn("NOPASSWD", text)

    def test_long_procfs_style_reads_are_complete(self):
        big = "x" * 200000
        self.put("/etc/nginx/snippets/pad.conf", "# " + big + "\n")
        self.assertEqual(len(M.Host(root=self.root).read("/etc/nginx/snippets/pad.conf", 1_000_000)), 200003)

    def test_unexpected_backup_names_stop(self):
        self.put(f"{M.BASE}/database-backups", directory=True, mode=0o700)
        self.put(f"{M.BASE}/database-backups/dump.sql", b"x", 0o600)
        self.stop("unexpected_backup_names")

    def test_stop_has_priority_over_blocked(self):
        os.unlink(self.path("/usr/bin/php8.5"))
        self.put("/etc/wpcaregrid-deploy/profiles/staging-next-phase-b-1.4.9.json", b"{}")
        self.stop("phase_b_policy_present")


class HardeningRoundTests(Fixture):
    def test_symlinked_nginx_includes_inside_the_nginx_tree_are_followed(self):
        os.unlink(self.path("/etc/nginx/conf.d/sites.conf"))
        self.put("/etc/nginx/sites-available/staging", VHOST)
        os.makedirs(self.path("/etc/nginx/sites-enabled"))
        os.symlink(self.path("/etc/nginx/sites-available/staging"), self.path("/etc/nginx/sites-enabled/staging"))
        self.put("/etc/nginx/nginx.conf", "http { include /etc/nginx/sites-enabled/*; }")
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=PASS")

    def test_symlinked_nginx_include_leaving_the_nginx_tree_is_unknown_blocked(self):
        os.unlink(self.path("/etc/nginx/conf.d/sites.conf"))
        self.put("/elsewhere/vhost.conf", VHOST)
        os.symlink(self.path("/elsewhere/vhost.conf"), self.path("/etc/nginx/conf.d/sites.conf"))
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=nginx_route_unknown")

    def test_server_level_fastcgi_without_a_php_location_is_not_a_route(self):
        self.put("/etc/nginx/conf.d/sites.conf",
                 "server { listen 443 ssl; server_name staging-next.wpcaregrid.com; "
                 "fastcgi_pass unix:/run/php/php8.5-fpm.sock; }")
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=nginx_route_mismatch")

    def test_server_level_fastcgi_must_also_match(self):
        self.put("/etc/nginx/conf.d/sites.conf",
                 "server { listen 443 ssl; server_name staging-next.wpcaregrid.com; "
                 "fastcgi_pass unix:/run/php/php8.4-fpm.sock; location ~ php$ { fastcgi_pass unix:/run/php/php8.5-fpm.sock; } }")
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=nginx_route_mismatch")

    def test_supervisor_connection_errors_are_unknown_not_clear(self):
        self.extra_run["supervisorctl"] = (3, b"unix:///var/run/supervisor.sock no such file\n")
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=worker_state_unknown")
        self.extra_run["supervisorctl"] = (1, b"error: <class 'ConnectionRefusedError'>\n")
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=worker_state_unknown")
        del self.extra_run["supervisorctl"]
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=PASS")

    def test_running_or_grouped_staging_next_workers_stop(self):
        for row in (b"wpcaregrid-staging-next-default   RUNNING  pid 1, uptime 0:01\n",
                    b"wpcaregrid-staging-next-backups:wpcaregrid-staging-next-backups_00   STARTING\n"):
            self.extra_run["supervisorctl"] = (0, row)
            final, _, _ = self.result()
            self.assertEqual(final, "STAGING_149_PREFLIGHT=STOP;CODE=worker_running")
        self.extra_run["supervisorctl"] = (0, b"wpcaregrid-staging-next-default   STOPPED  Nov 09 01:00 AM\n")
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=PASS")

    def test_sudoers_symlinks_includes_and_main_file_rules_stop(self):
        self.put("/elsewhere/rules", "deploy-staging-next ALL=(ALL) NOPASSWD: ALL\n")
        os.symlink(self.path("/elsewhere/rules"), self.path("/etc/sudoers.d/90-misc"))
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=STOP;CODE=unexpected_sudoers_state")
        os.unlink(self.path("/etc/sudoers.d/90-misc"))
        self.put("/etc/sudoers.d/91-inc", "#include /etc/other\n", 0o440)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=STOP;CODE=unexpected_sudoers_state")
        os.unlink(self.path("/etc/sudoers.d/91-inc"))
        self.put("/etc/sudoers", "root ALL=(ALL:ALL) ALL\n@includedir /etc/sudoers.d\n", 0o640)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=PASS")
        self.put("/etc/sudoers", "deploy-staging-next ALL=(ALL) NOPASSWD: ALL\n", 0o640)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=STOP;CODE=unexpected_sudoers_state")

    def test_unexpected_exceptions_still_produce_the_single_final_line(self):
        self.put("/etc/nginx/conf.d/sites.conf", "{" * 100000)
        final, _, _ = self.result()
        self.assertTrue(final.startswith("STAGING_149_PREFLIGHT=BLOCKED;CODE="))
        self.put("/etc/nginx/conf.d/sites.conf", VHOST)
        def check_environment(host, report):
            raise RuntimeError("boom")

        with mock.patch.object(M, "check_environment", check_environment):
            final, _, lines = self.result()
        self.assertEqual(final, "STAGING_149_PREFLIGHT=BLOCKED;CODE=preflight_internal_error")
        self.assertNotIn("boom", "\n".join(lines))

    def test_wrong_deploy_staging_next_uid_is_blocked(self):
        with mock.patch.object(M, "DEPLOY_UID", UID + 99):
            self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=deploy_identity_mismatch")

    def test_installer_directory_chains_need_root_owned_non_writable_components(self):
        os.chmod(self.path("/etc/wpcaregrid-deploy"), 0o775)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=installer_directory_metadata")
        os.chmod(self.path("/etc/wpcaregrid-deploy"), 0o755)
        os.chmod(self.path("/usr/local/libexec/wpcaregrid-deploy/staging-next"), 0o700)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=installer_directory_metadata")
        os.chmod(self.path("/usr/local/libexec/wpcaregrid-deploy/staging-next"), 0o755)
        os.chmod(self.path("/usr/local/libexec"), 0o777)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=installer_directory_metadata")

    def test_staged_source_needs_deploy_ownership_and_a_non_writable_parent_chain(self):
        self.stage_sources()
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=PASS")
        os.chmod(self.path("/home/deploy"), 0o777)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=staged_source_mismatch")
        os.chmod(self.path("/home/deploy"), 0o755)
        self.put("/etc/passwd", f"deploy:x:{UID + 1}:{UID + 1}::/h:/bin/sh\n"
                                f"deploy-staging-next:x:{UID + 7}:{UID + 7}::/n:/sbin/nologin\n")
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=staged_source_mismatch")

    def test_installer_hash_mismatch_is_blocked(self):
        self.stage_sources()
        self.put(M.INSTALLER, b"a different installer", 0o600)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=staged_source_mismatch")

    def test_symlinked_os_release_is_read_but_other_symlink_targets_are_not(self):
        os.unlink(self.path("/etc/os-release"))
        self.put("/usr/lib/os-release", 'PRETTY_NAME="Ubuntu 26.04.1 LTS"\nVERSION_ID="26.04"\n')
        os.symlink(self.path("/usr/lib/os-release"), self.path("/etc/os-release"))
        _, _, lines = self.result()
        self.assertTrue(any(line.startswith("CHECK|os.release|INFO|Ubuntu 26.04.1 LTS") for line in lines))
        os.unlink(self.path("/etc/os-release"))
        self.put("/tmp-outside/os-release", "PRETTY_NAME=x\n")
        os.symlink(self.path("/tmp-outside/os-release"), self.path("/etc/os-release"))
        _, _, lines = self.result()
        self.assertTrue(any(line.startswith("CHECK|os.release|UNKNOWN") for line in lines))

    def test_shebang_has_no_extra_arguments(self):
        self.assertEqual(SOURCE.splitlines()[0], "#!/usr/bin/python3")


class StagedSourceTests(Fixture):
    def test_correct_staged_files_pass_and_are_reported(self):
        self.stage_sources()
        final, _, lines = self.result()
        self.assertEqual(final, "STAGING_149_PREFLIGHT=PASS", "\n".join(lines))
        self.assertTrue(any(line.startswith("CHECK|source.staged|REQUIRED_PRESENT") for line in lines))

    def test_tampered_extra_or_wrongly_moded_sources_are_blocked(self):
        self.stage_sources(tamper="deployment_target_binding.py")
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=staged_source_mismatch")
        self.put(f"{M.SOURCE_DIR}/deployment_target_binding.py",
                 f"fixture-deployment_target_binding.py".encode(), 0o600)
        self.put(f"{M.SOURCE_DIR}/extra.txt", b"x", 0o600)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=staged_source_mismatch")
        os.unlink(self.path(f"{M.SOURCE_DIR}/extra.txt"))
        os.chmod(self.path(f"{M.SOURCE_DIR}/deployment_target_binding.py"), 0o644)
        self.assertEqual(self.result()[0], "STAGING_149_PREFLIGHT=BLOCKED;CODE=staged_source_mismatch")


class NoSecretsAndNoWritesTests(Fixture):
    def test_secret_files_are_never_read_or_printed(self):
        self.put(f"{M.BASE}/shared/.env", b"APP_KEY=canary-env-secret", 0o600)
        self.put("/etc/wpcaregrid-deploy/credentials", directory=True, mode=0o700)
        self.put("/etc/wpcaregrid-deploy/credentials/db.json", b"canary-credential", 0o600)
        self.put("/home/deploy/private-backups/b.sql.gz", b"canary-backup", 0o600)
        reads = []
        original = M.Host.read

        def spy(host, path, limit=262144):
            reads.append(path)
            return original(host, path, limit)

        with mock.patch.object(M.Host, "read", spy):
            _, _, lines = self.result()
        text = "\n".join(lines)
        for canary in ("canary-env-secret", "canary-credential", "canary-backup", "APP_KEY"):
            self.assertNotIn(canary, text)
        for path in reads:
            self.assertNotIn(".env", path)
            self.assertNotIn("credentials/", path)
            self.assertNotIn("private-backups", path)
            self.assertNotIn(".sql", path)
        self.assertTrue(any("metadata only" in line for line in lines))

    def test_only_whitelisted_read_only_commands_are_executed(self):
        self.stage_sources()
        self.result()
        allowed = {"systemctl", "supervisorctl", "visudo", "ps", "mysqld", "php8.5"}
        self.assertTrue({c[0].rsplit("/", 1)[-1] for c in self.commands} <= allowed)
        for command in self.commands:
            name = command[0].rsplit("/", 1)[-1]
            if name == "systemctl":
                self.assertEqual(command[1:3], ["show", "--no-pager"])
            if name == "supervisorctl":
                self.assertEqual(command[1:], ["status"])
            if name == "visudo":
                self.assertEqual(command[1:], ["-c"])
            if name == "php8.5":
                self.assertEqual(command[1:3], ["-n", "-r"])
            if name == "ps":
                self.assertEqual(command[1:], ["-eo", "args="])
            if name == "mysqld":
                self.assertEqual(command[1:], ["--no-defaults", "--version"])

    def test_static_source_has_no_write_network_or_mutating_operations(self):
        tree = ast.parse(SOURCE)
        forbidden_calls = {"mkdir", "makedirs", "remove", "unlink", "rmdir", "rename", "chmod", "chown",
                           "symlink", "link", "truncate", "write", "writelines", "system", "popen", "connect",
                           "bind", "listen", "sendall", "urlopen", "mkstemp", "mkdtemp", "copy", "copyfile", "move",
                           "rmtree", "utime", "setxattr", "kill", "fork", "execv", "execve", "flock", "lockf", "fcntl"}
        names = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Call):
                func = node.func
                name = func.attr if isinstance(func, ast.Attribute) else getattr(func, "id", "")
                names.add(name)
                self.assertNotIn(name, forbidden_calls, ast.dump(node)[:120])
                if isinstance(func, ast.Attribute) and isinstance(func.value, ast.Name) and func.value.id == "os":
                    self.assertNotEqual(name, "replace")
                if isinstance(func, ast.Name) and func.id == "open":
                    self.fail("builtin open() is not allowed; use Host.read")
        imports = {n.names[0].name for n in ast.walk(tree) if isinstance(n, ast.Import)}
        self.assertFalse(imports & {"shutil", "tempfile", "urllib", "http", "requests", "ftplib", "smtplib"})
        os_writes = [n for n in ast.walk(tree) if isinstance(n, ast.Attribute) and isinstance(n.value, ast.Name)
                     and n.value.id == "os" and n.attr in {"O_WRONLY", "O_RDWR", "O_CREAT", "O_APPEND", "O_TRUNC"}]
        self.assertEqual(os_writes, [])
        self.assertNotIn("subprocess.Popen", SOURCE)
        # every executable path literal is on the explicit allow-list
        literals = set(re.findall(r'"(/(?:usr/)?(?:s?bin)/[A-Za-z0-9._-]+)"', SOURCE))
        self.assertEqual(literals, {"/usr/bin/systemctl", "/usr/bin/php8.5", "/usr/bin/supervisorctl", "/bin/ps",
                                    "/usr/sbin/visudo", "/usr/sbin/mysqld", "/usr/sbin/nginx", "/usr/bin/python3",
                                    "/usr/bin/php8.4", "/usr/bin/php8.5"})

    def test_script_is_a_single_self_contained_file_without_placeholders(self):
        self.assertNotIn("@@", SOURCE)
        for value in list(M.SOURCE_PINS.values()) + [M.INSTALLER_SHA256]:
            self.assertRegex(value, r"\A[0-9a-f]{64}\Z")


class PinTests(unittest.TestCase):
    def test_pins_match_the_helper_manifest_and_installer(self):
        import json
        spec = importlib.util.spec_from_file_location("pinned_preflight", ROOT / "scripts" / "staging-149-preflight.py")
        fresh = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(fresh)
        manifest = json.loads((ROOT / "deploy" / "release-manifests" / "staging-next-helper-1.4.9.json").read_text())
        expected = {
            "wpcaregrid-staging-next-deploy-priv-1_4_9.py": manifest["entrypoint_sha256"],
            "staging_next_deploy_backend_1_4_9.py": manifest["backend_sha256"],
            "staging_next_db_transaction_1_4_9.py": manifest["transaction_module_sha256"],
            "deployment_target_binding.py": manifest["target_binding_sha256"],
            "deployment_environment_contract.py": manifest["environment_contract_sha256"],
        }
        self.assertEqual(fresh.SOURCE_PINS, expected)
        installer = (ROOT / "scripts" / "install_staging_next_helper_1_4_9.py").read_bytes()
        self.assertEqual(fresh.INSTALLER_SHA256, hashlib.sha256(installer).hexdigest())
        text = (ROOT / "scripts" / "install_staging_next_helper_1_4_9.py").read_text(encoding="utf-8")
        for constant, value in (("DEPLOY_UID", "EXPECTED_DEPLOY_UID"), ("MYSQL_UUID_SHA256", "MYSQL_UUID_SHA256")):
            self.assertIn(f'{value} = ', text)
        self.assertIn(f'APP_DIR = "{fresh.APP_DIR}"', text)
        self.assertIn(f'SOCKET = "{fresh.SOCKET}"', text)
        self.assertIn(f'MYSQL_UUID_SHA256 = "{fresh.MYSQL_UUID_SHA256}"', text)
        self.assertIn(f"EXPECTED_DEPLOY_UID = {fresh.DEPLOY_UID}", text)
        self.assertIn(f'SOURCE_DIR = Path("{fresh.SOURCE_DIR}")', text)

    def test_g1_artifact_hashes_match_the_g1_manifest(self):
        import json
        manifest = json.loads((ROOT / "scripts" / "production-g1-mysql-manifest.json").read_text())
        self.assertEqual(M.G1_ARTIFACTS, manifest["installed_artifacts"])


class NginxParserTests(unittest.TestCase):
    def test_tokenizer_handles_comments_quotes_and_nested_blocks(self):
        tree = M.nginx_parse(M.nginx_tokens('server { # c\n listen 443 ssl; server_name "a b"; location / { x y; } }'))
        flat = [args for args, _ in M.nginx_walk(tree)]
        self.assertIn(["listen", "443", "ssl"], flat)
        self.assertIn(["server_name", "a b"], flat)
        self.assertIn(["x", "y"], flat)


if __name__ == "__main__":
    unittest.main()
