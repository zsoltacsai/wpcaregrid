#!/usr/bin/env python3
"""Deploy the actual Laravel source through the real backend in disposable services."""

from __future__ import annotations

import hashlib
import gzip
import json
import os
import re
import shutil
import socket
import stat
import subprocess
import sys
import tarfile
import tempfile
import threading
import time
import urllib.error
import urllib.request
from dataclasses import replace
from pathlib import Path

ROOT = Path("/repo")
sys.path.insert(0, str(ROOT))

from scripts import staging_next_deploy_backend as BACKEND
from scripts import staging_next_deploy_controller as CONTROLLER


def run(argv, *, cwd=None, timeout=15, extra_env=None, maximum=2_000_000):
    env = {"PATH": "/usr/local/bin:/usr/bin:/bin", "LANG": "C"}
    if extra_env:
        env.update(extra_env)
    result = subprocess.run(argv, cwd=cwd, env=env,
                            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=timeout, check=False)
    if result.returncode != 0 or len(result.stdout) > maximum or len(result.stderr) > maximum:
        raise RuntimeError("isolated_laravel_command_failed")
    return result.stdout.decode("utf-8").strip()


def drain_bounded(stream, result, limit=16_384):
    """Drain diagnostics while retaining only a small bounded in-memory prefix."""
    while chunk := stream.read(4096):
        remaining = limit - len(result)
        if remaining > 0:
            result.extend(chunk[:remaining])


def mysql_error_codes(raw):
    """Return numeric MySQL/SQLSTATE codes only; never expose diagnostic text."""
    errors = re.findall(r"ERROR\s+(\d+)\s+\(([A-Z0-9]+)\)", raw.decode("utf-8", "replace"))
    return ",".join(f"{number}:{state}" for number, state in errors[:8]) or "UNCLASSIFIED"


def prove_mysql_backup_restore(profile, root: Path):
    """Create a private compressed DB backup, restore it to a second MySQL instance, compare metadata."""
    backup = root / "wpcg-staging-next-e2e.sql.gz"
    backup_env = {"MYSQL_PWD": os.environ["WPCG_BACKUP_MYSQL_PASSWORD"]}
    with backup.open("xb") as stream:
        os.chmod(backup, 0o600)
        dump = subprocess.Popen([
            "/usr/bin/mysqldump", "--no-defaults", "--single-transaction", "--routines",
            "--triggers", "--events", "--no-tablespaces", "-h", os.environ["WPCG_MYSQL_HOST"],
            "-u", os.environ["WPCG_MYSQL_USER"], "wpcg_staging_next",
        ], env={"PATH": "/usr/bin:/bin", "LANG": "C", **backup_env},
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        dump_diagnostic = bytearray()
        dump_reader = threading.Thread(target=drain_bounded, args=(dump.stderr, dump_diagnostic), daemon=True)
        dump_reader.start()
        compressor = subprocess.Popen(["/usr/bin/gzip", "-c", "-n"], stdin=dump.stdout,
                                      stdout=stream, stderr=subprocess.DEVNULL)
        dump.stdout.close()
        try:
            compress_rc = compressor.wait(timeout=300)
            dump_rc = dump.wait(timeout=300)
            dump_reader.join(timeout=5)
        except subprocess.TimeoutExpired:
            compressor.kill()
            dump.kill()
            compressor.wait(timeout=5)
            dump.wait(timeout=5)
            raise RuntimeError("isolated_database_backup_timeout") from None
    if (dump_rc != 0 or compress_rc != 0 or not backup.is_file()
            or backup.stat().st_size > 512 * 1024 * 1024):
        raise RuntimeError(f"isolated_database_backup_failed:{mysql_error_codes(dump_diagnostic)}")
    backup_sha = hashlib.sha256(backup.read_bytes()).hexdigest()
    try:
        with gzip.open(backup, "rb") as stream:
            while stream.read(1024 * 1024):
                pass
    except (OSError, EOFError):
        raise RuntimeError("isolated_database_backup_integrity_failed") from None

    restore_env = {"MYSQL_PWD": os.environ["WPCG_RESTORE_MYSQL_PASSWORD"]}
    importer = subprocess.Popen([
        "/usr/bin/mysql", "--no-defaults", "-h", os.environ["WPCG_RESTORE_MYSQL_HOST"],
        "-u", os.environ["WPCG_RESTORE_MYSQL_USER"], "wpcg_staging_next",
    ], env={"PATH": "/usr/bin:/bin", "LANG": "C", **restore_env},
       stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    import_diagnostic = bytearray()
    import_reader = threading.Thread(target=drain_bounded, args=(importer.stderr, import_diagnostic), daemon=True)
    import_reader.start()
    unpacked = 0
    try:
        with gzip.open(backup, "rb") as source:
            while chunk := source.read(1024 * 1024):
                unpacked += len(chunk)
                if unpacked > 2 * 1024 * 1024 * 1024:
                    raise RuntimeError("isolated_database_restore_size_limit")
                importer.stdin.write(chunk)
        importer.stdin.close()
        restore_rc = importer.wait(timeout=600)
        import_reader.join(timeout=5)
    except BaseException:
        importer.kill()
        importer.wait(timeout=5)
        raise
    if restore_rc != 0:
        raise RuntimeError(f"isolated_database_restore_failed:{mysql_error_codes(import_diagnostic)}")

    probe = (
        "$p=new PDO(sprintf('mysql:host=%s;port=3306;dbname=wpcg_staging_next;charset=utf8mb4',getenv('H')),"
        "getenv('U'),getenv('P'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);"
        "$r=$p->query('SELECT VERSION() v,@@server_uuid u,(SELECT COUNT(*) FROM information_schema.tables "
        "WHERE table_schema=DATABASE() AND table_type=\"BASE TABLE\") t,(SELECT COUNT(*) FROM migrations) m,'"
        ").fetch(PDO::FETCH_ASSOC);"
    )
    # Query both instances with the same fixed code; emit metadata only.
    probe = (
        "$p=new PDO(sprintf('mysql:host=%s;port=3306;dbname=wpcg_staging_next;charset=utf8mb4',getenv('H')),"
        "getenv('U'),getenv('P'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);"
        "$sql=\"SELECT VERSION() version,@@server_uuid uuid,(SELECT COUNT(*) FROM information_schema.tables "
        "WHERE table_schema=DATABASE() AND table_type='BASE TABLE') tables,(SELECT COUNT(*) FROM migrations) migrations\";"
        "$r=$p->query($sql); echo json_encode($r->fetch(PDO::FETCH_ASSOC));"
    )
    observed = []
    for host, username, password in (
        (os.environ["WPCG_MYSQL_HOST"], os.environ["WPCG_MYSQL_USER"], os.environ["WPCG_MYSQL_PASSWORD"]),
        (os.environ["WPCG_RESTORE_MYSQL_HOST"], os.environ["WPCG_RESTORE_MYSQL_USER"], os.environ["WPCG_RESTORE_MYSQL_PASSWORD"]),
    ):
        raw = run(["/usr/local/bin/php", "-r", probe], cwd=profile.application_link, timeout=20,
                  extra_env={"H": host, "U": username, "P": password})
        observed.append(json.loads(raw))
    first, second = observed
    if (not str(first.get("version", "")).startswith("8.4.11")
            or not str(second.get("version", "")).startswith("8.4.11")
            or first.get("uuid") == second.get("uuid")
            or first.get("tables") != second.get("tables")
            or first.get("migrations") != second.get("migrations")
            or int(first.get("tables", 0)) <= 0 or int(first.get("migrations", 0)) <= 0):
        raise RuntimeError("isolated_database_restore_verification_failed")
    return backup_sha, int(first["tables"]), int(first["migrations"])


def git(repo, *args):
    return subprocess.run(["git", "-C", str(repo), *args], check=True, timeout=15,
                          stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, text=True).stdout.strip()


def excluded(_directory, names):
    return {name for name in names if name in {".git", ".env", "vendor", "node_modules", "storage"}}


def prepare_tree(repo: Path):
    shutil.copytree(ROOT, repo, ignore=excluded, symlinks=True)
    for path in repo.rglob("*"):
        if path.is_symlink():
            raise RuntimeError("repository_symlink_unexpected")
    git(repo, "init", "--quiet", "-b", BACKEND.PRIMARY_BRANCH)
    git(repo, "config", "user.name", "WP CareGrid isolated E2E")
    git(repo, "config", "user.email", "wpcg-isolated-e2e@example.invalid")
    git(repo, "add", "-A")
    git(repo, "commit", "--quiet", "-m", "isolated Laravel deployment base")
    base = git(repo, "rev-parse", "HEAD")
    home = repo / "resources/views/pages/home.blade.php"
    home.write_text(home.read_text(encoding="utf-8") + "\n<!-- WPCG_LARAVEL_DEPLOY_E2E -->\n", encoding="utf-8")
    git(repo, "add", "resources/views/pages/home.blade.php")
    git(repo, "commit", "--quiet", "-m", "isolated marketing-only release")
    candidate = git(repo, "rev-parse", "HEAD")
    return base, candidate


def create_active_release(profile, repo, base_sha):
    CONTROLLER.SOURCE_CHECKOUT = repo
    CONTROLLER.INCOMING_DIRECTORY = profile.incoming_dir
    archive_path, artifact_sha = CONTROLLER._package_release(base_sha, base_sha)
    raw = archive_path.read_bytes()
    release = profile.releases_dir / base_sha
    release.mkdir(mode=0o700)
    files, manifest = BACKEND._extract_archive(raw, release, app_sha=base_sha, base_sha=base_sha,
        artifact_sha=artifact_sha, helper_artifact_sha256=CONTROLLER.HELPER_ARTIFACT_SHA256)
    manifest = dict(manifest)
    manifest["artifact_sha256"] = artifact_sha
    (release / "release-manifest.json").write_text(
        json.dumps(manifest, sort_keys=True, separators=(",", ":")) + "\n", encoding="utf-8")
    os.chmod(release / "release-manifest.json", 0o644)
    os.symlink(str(profile.shared_env_file), release / ".env")
    os.symlink(str(profile.shared_storage_dir), release / "storage")
    os.symlink(str(profile.shared_vendor_dir), release / "vendor")
    for path in sorted(release.rglob("*"), reverse=True):
        if path.is_dir() and not path.is_symlink():
            os.chmod(path, 0o755)
        elif not path.is_symlink():
            os.chmod(path, 0o644)
    os.chmod(release, 0o755)
    original_run = subprocess.run
    captured = []

    def capture_php_runtime_result(*args, **kwargs):
        result = original_run(*args, **kwargs)
        command = args[0] if args else kwargs.get("args", [])
        if isinstance(command, (list, tuple)) and command[:1] == ["/usr/sbin/runuser"]:
            captured.append((result.returncode, result.stdout, result.stderr))
        return result

    subprocess.run = capture_php_runtime_result
    try:
        BACKEND._prepare_application_bootstrap(profile, release)
    except BACKEND.DeployRefused as error:
        error_code = str(error)
        if error_code != "runtime_probe_failed" and not error_code.startswith("runtime_probe_failed_"):
            raise
        safe_code = "unclassified"
        if captured:
            diagnostic = (captured[-1][1] + b"\n" + captured[-1][2]).lower()
            for marker, code in (
                (b"permission denied", "permission_denied"),
                (b"must be present and writable", "laravel_cache_directory_not_writable"),
                (b"failed to open stream", "file_open_failed"),
                (b"could not find driver", "pdo_driver_missing"),
                (b"no application encryption key", "application_key_missing"),
                (b"no such file or directory", "required_path_missing"),
                (b"group does not exist", "php_fpm_group_unavailable"),
                (b"failed to write", "write_failed"),
                (b"unable to write", "write_failed"),
                (b"is not writable", "path_not_writable"),
                (b"failed to load", "runtime_load_failed"),
                (b"cannot open", "path_open_failed"),
                (b"class not found", "class_not_found"),
                (b"failed opening required", "required_file_missing"),
                (b"parse error", "php_parse_error"),
                (b"fatal error", "php_fatal_error"),
            ):
                if marker in diagnostic:
                    safe_code = code
                    break
        raise RuntimeError(f"laravel_bootstrap_test_failed:{error_code}:{safe_code}") from None
    finally:
        subprocess.run = original_run
    profile.application_link.symlink_to(release)
    return artifact_sha


def main():
    if os.geteuid() != 0 or os.environ.get("WPCG_PHP_MYSQL_E2E") != "1":
        raise RuntimeError("isolated_root_mysql_environment_required")
    private_parent = Path("/var/lib")
    parent_info = private_parent.lstat()
    if not stat.S_ISDIR(parent_info.st_mode) or parent_info.st_uid != 0 \
            or stat.S_IMODE(parent_info.st_mode) != 0o755:
        raise RuntimeError("isolated_test_parent_metadata_invalid")
    temp = tempfile.TemporaryDirectory(prefix="wpcg-real-laravel-e2e-", dir=private_parent)
    root = Path(temp.name)
    os.chmod(root, 0o711)
    php_process = None
    php_stderr = None
    try:
        repo = root / "source"
        base_sha, candidate_sha = prepare_tree(repo)
        deploy_root = root / "deploy"
        deploy_root.mkdir(mode=0o755)
        os.chmod(deploy_root, 0o755)
        release_dir = deploy_root / "releases"
        incoming = deploy_root / "incoming"
        inbox = deploy_root / "inbox"
        state = deploy_root / "state"
        receipts = deploy_root / "receipts"
        shared = deploy_root / "shared"
        app_parent = deploy_root / "app"
        for path, mode in ((release_dir, 0o755), (incoming, 0o700), (inbox, 0o755),
                           (state, 0o700), (receipts, 0o700), (shared, 0o755), (app_parent, 0o755)):
            path.mkdir(mode=mode)
            os.chmod(path, mode)
        os.chown(incoming, 1000, 1000)
        env_file = shared / ".env"
        env = "\n".join((
            "APP_NAME=WP-CareGrid-Isolated-E2E", "APP_ENV=production",
            "APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=", "APP_DEBUG=false",
            "APP_URL=http://localhost", "CAREGRID_DEPLOYMENT_ENV=staging",
            "CAREGRID_DEPLOYMENT_HOSTNAME=staging-next.wpcaregrid.com",
            "MARKETING_RELEASE_PROFILE=marketing_only", "DB_CONNECTION=mysql",
            "LOG_CHANNEL=stderr", "LOG_LEVEL=debug",
            "DB_HOST=" + os.environ["WPCG_MYSQL_HOST"], "DB_PORT=3306",
            "DB_DATABASE=wpcg_staging_next", "DB_USERNAME=" + os.environ["WPCG_MYSQL_USER"],
            "DB_PASSWORD=" + os.environ["WPCG_MYSQL_PASSWORD"], "SESSION_DRIVER=file",
            "CACHE_STORE=file", "QUEUE_CONNECTION=sync", "MAIL_MAILER=log",
            "STRIPE_KEY=", "STRIPE_SECRET=", "STRIPE_WEBHOOK_SECRET=",
        )) + "\n"
        env_file.write_text(env, encoding="utf-8")
        os.chown(env_file, 0, 33)
        os.chmod(env_file, 0o640)
        storage = shared / "storage"
        storage.mkdir(mode=0o2770)
        os.chmod(storage, 0o2770)
        for child in ("app/public", "framework/cache/data", "framework/sessions",
                      "framework/views", "logs"):
            (storage / child).mkdir(parents=True, exist_ok=True, mode=0o2770)
            os.chmod(storage / child, 0o2770)
        for path in (storage, *storage.rglob("*")):
            os.chown(path, 33, 33, follow_symlinks=False)
        vendor = Path("/opt/wpcaregrid-source/vendor")
        if not (vendor / "autoload.php").is_file():
            raise RuntimeError("composer_vendor_missing")
        with socket.socket() as probe:
            probe.bind(("127.0.0.1", 0))
            port = probe.getsockname()[1]
        profile = BACKEND.StagingProfile(
            name="staging-next", application_link=app_parent / "current", releases_dir=release_dir,
            incoming_dir=incoming, inbox_dir=inbox, state_dir=state, receipts_dir=receipts,
            shared_env_file=env_file, shared_storage_dir=storage, shared_vendor_dir=vendor,
            basic_auth_file=None, mysql_server_uuid_sha256=os.environ["WPCG_MYSQL_UUID_SHA256"],
            integration_qa_reviewer=None, m17_appsec_reviewer=None,
            helper_artifact_sha256=CONTROLLER.HELPER_ARTIFACT_SHA256, php_fpm_uid=33, php_fpm_gid=33,
            test_git_repo=repo, allowed_uid=1000, expected_owner_uid=0,
            acceptance_urls=tuple((f"http://localhost:{port}{path}", status)
                                  for path, status in (("/up", 200), ("/hu", 200), ("/en", 200),
                                                       ("/register", 404), ("/checkout", 404), ("/api", 404))),
            build_application_bootstrap=True, test_only=True, verify_test_database=True,
        )
        create_active_release(profile, repo, base_sha)
        run(["/usr/local/bin/php", "artisan", "migrate", "--force", "--isolated", "--no-interaction"],
            cwd=profile.application_link, timeout=900)
        migration_status = run(["/usr/local/bin/php", "artisan", "migrate:status", "--no-interaction"],
                               cwd=profile.application_link, timeout=60)
        if "Pending" in migration_status:
            raise RuntimeError("isolated_migration_pending_after_run")
        backup_sha, restored_tables, restored_migrations = prove_mysql_backup_restore(profile, root)
        print(f"MIGRATIONS=PASS;COUNT={restored_migrations};ISOLATED_RESTORE=PASS;TABLES={restored_tables};BACKUP_SHA256={backup_sha}")
        php_env = {"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": str(storage),
                   "WPCG_CURRENT_APP": str(profile.application_link)}
        stderr_path = root / "php-server.stderr"
        php_stderr = stderr_path.open("xb")
        os.chown(stderr_path, 33, 33)
        os.chmod(stderr_path, 0o600)
        php_process = subprocess.Popen(
            ["/usr/sbin/runuser", "--user", "www-data", "--group", "www-data", "--",
             "/usr/local/bin/php", "-S", f"127.0.0.1:{port}", "-t",
             str(profile.application_link / "public"), str(ROOT / "scripts/tests/staging-next-laravel-router.php")],
            cwd=profile.application_link, env=php_env, stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL, stderr=php_stderr)
        profile = replace(profile, acceptance_urls=tuple(
            (f"http://localhost:{port}{path}", status)
            for path, status in (("/up", 200), ("/hu", 200), ("/en", 200),
                                 ("/register", 404), ("/checkout", 404), ("/api", 404))))
        last_probe = "NO_RESPONSE"
        for _ in range(40):
            if php_process.poll() is not None:
                raise RuntimeError("laravel_http_process_exited")
            try:
                with urllib.request.urlopen(f"http://localhost:{port}/up", timeout=1) as response:
                    last_probe = f"HTTP_{response.status}"
                    if response.status == 200:
                        break
            except urllib.error.HTTPError as error:
                last_probe = f"HTTP_{error.code}"
                if error.code == 200:
                    break
            except (urllib.error.URLError, TimeoutError):
                last_probe = "CONNECTION_UNAVAILABLE"
                time.sleep(0.25)
        else:
            raise RuntimeError(f"laravel_http_readiness_timeout:{last_probe}")

        artifact_path, artifact_sha = CONTROLLER._package_release(candidate_sha, base_sha)
        tree_names = set(BACKEND._local_git_tree(profile, candidate_sha))
        with tarfile.open(artifact_path, mode="r:") as packaged:
            artifact_names = {member.name for member in packaged.getmembers()
                              if member.isfile() and member.name != "release-manifest.json"}
        source_artifact_names = {name for name in artifact_names if not name.startswith("public/build/")}
        if tree_names != source_artifact_names or "public/build/manifest.json" not in artifact_names:
            missing = sorted(tree_names - source_artifact_names)[:10]
            extra = sorted(source_artifact_names - tree_names)[:10]
            raise RuntimeError(f"artifact_source_file_set_mismatch:missing={missing}:extra={extra}")
        incoming_path = incoming / f"{candidate_sha}.tar"
        if artifact_path != incoming_path:
            shutil.copyfile(artifact_path, incoming_path)
        # The E2E process is root only to model root-owned release storage;
        # the controller-produced inbox artifact itself must remain deploy-owned.
        os.chown(incoming_path, 1000, 1000)
        os.chmod(incoming_path, 0o600)
        staged = BACKEND.stage_artifact(profile, invoking_uid=1000, application_sha=candidate_sha,
                                        artifact_sha256=artifact_sha)
        if staged.status != "PASS":
            raise RuntimeError(f"artifact_stage_{staged.reason}")
        result = BACKEND.deploy_release(profile, invoking_uid=1000, application_sha=candidate_sha,
                                        artifact_sha256=artifact_sha)
        if result.status != "PASS":
            observed_codes = []
            laravel_error_class = "UNAVAILABLE"
            log_path = storage / "logs" / "laravel.log"
            if log_path.is_file() and not log_path.is_symlink():
                # Keep exception messages, SQL, paths, and credentials private.
                import re
                for match in re.finditer(r"production\.ERROR: ([A-Za-z0-9_\\]+)",
                                         log_path.read_text(encoding="utf-8", errors="replace")):
                    laravel_error_class = match.group(1)
            if result.receipt_path:
                failure_receipt = json.loads(Path(result.receipt_path).read_text(encoding="utf-8"))
                observed_codes = [check.get("observed_status") for check in failure_receipt.get("acceptance", [])]
            raise RuntimeError(f"deployment_{result.status.lower()}_{result.reason}:http={observed_codes}:laravel_error_class={laravel_error_class}")
        receipt = json.loads(Path(result.receipt_path).read_text(encoding="utf-8"))
        if receipt.get("active_sha_after_deploy") != candidate_sha or receipt.get("result") != "PASS":
            raise RuntimeError("observed_receipt_mismatch")
        # The real backend reloads PHP-FPM after atomically switching current.
        # Restart the disposable PHP server to model that worker generation change.
        php_process.terminate()
        php_process.wait(timeout=5)
        php_process = subprocess.Popen(
            ["/usr/sbin/runuser", "--user", "www-data", "--group", "www-data", "--",
             "/usr/local/bin/php", "-S", f"127.0.0.1:{port}", "-t",
             str(profile.application_link / "public"), str(ROOT / "scripts/tests/staging-next-laravel-router.php")],
            cwd=profile.application_link, env=php_env, stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL, stderr=php_stderr)
        for _ in range(40):
            if php_process.poll() is not None:
                raise RuntimeError("reloaded_laravel_http_process_exited")
            try:
                with urllib.request.urlopen(f"http://localhost:{port}/up", timeout=1) as response:
                    if response.status == 200:
                        break
            except (urllib.error.URLError, TimeoutError):
                time.sleep(0.25)
        else:
            raise RuntimeError("reloaded_laravel_http_readiness_timeout")
        try:
            with urllib.request.urlopen(f"http://localhost:{port}/hu", timeout=3) as response:
                body = response.read(2_000_000).decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            import re
            log_path = storage / "logs" / "laravel.log"
            exception_class = "UNAVAILABLE"
            if php_stderr is not None:
                php_stderr.flush()
            diagnostic_text = ""
            if php_stderr is not None and not php_stderr.closed:
                diagnostic_text += stderr_path.read_text(encoding="utf-8", errors="replace")
            log_size = -1
            log_text = ""
            if log_path.is_file() and not log_path.is_symlink():
                log_size = log_path.stat().st_size
                log_text = log_path.read_text(encoding="utf-8", errors="replace")
            response_body = error.read(1_000_000).decode("utf-8", "replace")
            combined = diagnostic_text + "\n" + log_text + "\n" + response_body
            matches = re.findall(
                r"(?:ERROR:\s+(?:Uncaught\s+)?|PHP Fatal error:\s+Uncaught\s+)"
                r"([A-Z][A-Za-z0-9_\\]*)", combined)
            view_matches = re.findall(r"View\s+\[([A-Za-z0-9_.-]{1,120})\]\s+not found", combined, re.IGNORECASE)
            if not matches:
                matches = re.findall(r"\b([A-Za-z_\\][A-Za-z0-9_\\]*(?:Exception|Error))\b", combined)
            if not matches:
                matches = re.findall(r"Uncaught\s+([A-Za-z_\\][A-Za-z0-9_\\]*)", combined)
            if matches:
                exception_class = matches[-1]
            diagnostic_lower = combined.lower()
            diagnostic_code = next((code for marker, code in (
                ("could not find driver", "pdo_driver_missing"),
                ("failed to open stream", "file_open_failed"),
                ("permission denied", "permission_denied"),
                ("no application encryption key", "application_key_missing"),
                ("sqlstate", "database_query_failed"),
                ("viteexception", "vite_manifest_failed"),
                ("call to undefined function", "php_function_missing"),
                ("class not found", "php_class_missing"),
                ("failed opening required", "php_required_file_missing"),
                ("allowed memory size", "php_memory_limit"),
                ("maximum execution time", "php_execution_timeout"),
                ("undefined array key", "php_undefined_array_key"),
                ("undefined variable", "php_undefined_variable"),
                ("call to a member function", "php_invalid_object_state"),
                ("undefined method", "php_method_missing"),
                ("view [", "view_resolution_failed"),
                ("internal server error", "http_internal_server_error"),
                ("whoops", "http_exception_page"),
                ("not found", "http_not_found"),
                ("unauthorized", "http_unauthorized"),
            ) if marker in diagnostic_lower), "server_error_unclassified")
            php_error_types = sorted(set(re.findall(
                r"(?:PHP\s+)?(Fatal error|Parse error|Warning|Notice):", combined, re.IGNORECASE)))
            stderr_size = stderr_path.stat().st_size if stderr_path.exists() else -1
            raise RuntimeError(
                f"candidate_http_error:{error.code}:exception_class={exception_class}:"
                f"diagnostic_code={diagnostic_code}:error_types={','.join(php_error_types) or 'NONE'}:"
                f"view_id={view_matches[-1] if view_matches else 'NONE'}:"
                f"stderr_bytes={stderr_size}:log_bytes={log_size}:response_bytes={len(response_body)}"
            ) from None
        if "WPCG_LARAVEL_DEPLOY_E2E" not in body:
            raise RuntimeError("candidate_marketing_release_not_served")
        noop = BACKEND.deploy_release(profile, invoking_uid=1000, application_sha=candidate_sha,
                                      artifact_sha256=artifact_sha)
        if (noop.status, noop.reason) != ("NO_OP", "exact_release_already_active"):
            raise RuntimeError("repeat_deployment_not_noop")
        print(f"LARAVEL_DEPLOY=PASS\nLARAVEL_RELEASE={candidate_sha}\nMYSQL=8.4\nHTTP=6/6_PASS\nNO_OP=PASS\nRECEIPT_SHA256={result.receipt_sha256}")
    finally:
        if php_process is not None:
            php_process.terminate()
            try:
                php_process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                php_process.kill()
                php_process.wait(timeout=5)
        if php_stderr is not None:
            php_stderr.close()
        temp.cleanup()


if __name__ == "__main__":
    main()
