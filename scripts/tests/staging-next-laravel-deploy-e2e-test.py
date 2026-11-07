#!/usr/bin/env python3
"""Deploy the actual Laravel source through the real backend in disposable services."""

from __future__ import annotations

import base64
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

from scripts import staging_next_deploy_backend_1_4_8 as BACKEND
from scripts import staging_next_deploy_controller as CONTROLLER
from scripts.staging_next_db_transaction_1_4_8 import (
    DatabaseReleaseTransaction, TransactionIdentity, _mysql_defaults, _mysql_query,
    _mysql_query_failure_code, database_fingerprint, restore_receipt_sha256,
)


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


def run_as_php_fpm(argv, *, cwd=None, timeout=15, extra_env=None, maximum=2_000_000):
    """Run Laravel setup commands as the same unprivileged FPM identity used by deployment."""
    return run(["/usr/sbin/runuser", "--user", "www-data", "--group", "www-data", "--", *argv],
               cwd=cwd, timeout=timeout, extra_env=extra_env, maximum=maximum)


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


def verify_mysqldump_option_file():
    """Confirm the real mysqldump client consumes the inherited private options."""
    credentials = {
        "username": os.environ["WPCG_MYSQL_USER"],
        "password": os.environ["WPCG_MYSQL_PASSWORD"],
    }
    option_fd, defaults_path = _mysql_defaults(credentials, os.environ["WPCG_MYSQL_HOST"], 3306)
    try:
        result = subprocess.run(
            ["/usr/bin/mysqldump", f"--defaults-file={defaults_path}", "--print-defaults"],
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=5, check=False, pass_fds=(option_fd,),
        )
    finally:
        os.close(option_fd)
    # The output contains the synthetic password. Inspect only in memory and
    # report a boolean; never include the output or credential in diagnostics.
    output = result.stdout
    flags = {
        "HOST": os.environ["WPCG_MYSQL_HOST"].encode("ascii") in output,
        "PROTOCOL": b"protocol" in output.lower() and b"tcp" in output.lower(),
        "USER": os.environ["WPCG_MYSQL_USER"].encode("ascii") in output,
        "PASSWORD": b"password" in output.lower(),
    }
    valid = result.returncode == 0 and len(output) <= 4096 and len(result.stderr) <= 4096 and all(flags.values())
    report = " ".join(f"{key}={'PASS' if value else 'FAIL'}" for key, value in flags.items())
    error = result.stderr.lower()
    if b"defaults" in error and (b"open" in error or b"read" in error):
        error_class = "DEFAULTS_FILE_REJECTED"
    elif b"could not open" in error or b"cannot open" in error or b"couldn't open" in error:
        error_class = "OPEN_FAILED"
    elif b"permission denied" in error:
        error_class = "PERMISSION_DENIED"
    elif b"unknown option" in error:
        error_class = "OPTION_REJECTED"
    elif b"plugin" in error:
        error_class = "AUTH_PLUGIN"
    elif b"defaults" in error:
        error_class = "DEFAULTS_DIAGNOSTIC"
    elif error:
        error_class = "OTHER"
    else:
        error_class = "NONE"
    allowed_words = {
        b"error", b"warning", b"option", b"file", b"group", b"section", b"config",
        b"defaults", b"proc", b"fd", b"open", b"read", b"cannot", b"could", b"couldn't",
        b"found", b"without", b"preceding", b"required", b"ignored", b"unknown", b"invalid",
        b"not", b"specified", b"argument", b"line", b"mysql", b"mysqldump",
    }
    safe_tokens = sorted(set(re.findall(rb"[a-z']+", error)) & allowed_words)
    safe_tokens_text = ",".join(token.decode("ascii") for token in safe_tokens) or "NONE"
    print(f"MYSQLDUMP_OPTION_FILE={'PASS' if valid else 'FAIL'};RC={result.returncode};OUT={len(output)};ERR={len(result.stderr)};STDERR_CLASS={error_class};STDERR_TOKENS={safe_tokens_text};{report}")
    if not valid:
        raise RuntimeError("mysqldump_option_file_probe_failed")


def verify_mysql_client_query():
    """Check the mysql CLI's inherited credentials using a read-only identity query."""
    credentials = {
        "username": os.environ["WPCG_MYSQL_USER"],
        "password": os.environ["WPCG_MYSQL_PASSWORD"],
    }
    option_fd, defaults_path = _mysql_defaults(credentials, os.environ["WPCG_MYSQL_HOST"], 3306)
    try:
        result = subprocess.run(
            ["/usr/bin/mysql", f"--defaults-file={defaults_path}", "--batch", "--skip-column-names",
             "--raw", "--database", "wpcg_staging_next", "-e", "SELECT VERSION(),DATABASE()"],
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=5, check=False, pass_fds=(option_fd,),
        )
    finally:
        os.close(option_fd)
    output = result.stdout.decode("ascii", "replace").strip().split("\t")
    valid = result.returncode == 0 and len(result.stdout) <= 4096 and len(result.stderr) <= 4096 \
        and len(output) == 2 and output[0].startswith("8.4.") and output[1] == "wpcg_staging_next"
    diagnostic = _mysql_query_failure_code(result.stderr + b"\n" + result.stdout[:16_384])
    allowed_words = {b"mysql", b"error", b"warning", b"plugin", b"load", b"loading", b"shared",
                     b"library", b"libraries", b"file", b"not", b"found", b"cannot", b"could",
                     b"open", b"failed", b"permission", b"denied", b"option", b"unknown",
                     b"auth", b"authentication", b"client", b"missing", b"required"}
    tokens = sorted(set(re.findall(rb"[a-z']+", (result.stderr + b"\n" + result.stdout[:16_384]).lower()))
                    & allowed_words)
    safe_tokens = ",".join(token.decode("ascii") for token in tokens) or "NONE"
    print(f"MYSQL_CLIENT_READ_ONLY_PROBE={'PASS' if valid else 'FAIL'};RC={result.returncode};"
          f"OUT={len(result.stdout)};ERR={len(result.stderr)};"
          f"RESULT={'PASS' if valid else 'MISMATCH'};DIAGNOSTIC={diagnostic};TOKENS={safe_tokens}")
    if not valid:
        raise RuntimeError("mysql_client_read_only_probe_failed")


def verify_php_vendor_readability(vendor: Path, application_cwd: Path):
    result = subprocess.run(
        ["/usr/sbin/runuser", "--user", "www-data", "--group", "www-data", "--",
         "/usr/bin/test", "-r", "vendor/autoload.php"], cwd=application_cwd,
        stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        timeout=5, check=False,
    )
    modes = []
    for path in (Path("/opt"), vendor.parent, vendor, vendor / "autoload.php"):
        try:
            info = path.stat()
            modes.append(f"{info.st_uid}:{info.st_gid}:{stat.S_IMODE(info.st_mode):04o}")
        except OSError:
            modes.append("MISSING")
    valid = result.returncode == 0
    print(f"PHP_VENDOR_READABILITY={'PASS' if valid else 'FAIL'};RC={result.returncode};"
          f"METADATA={'/'.join(modes)}")
    if not valid:
        raise RuntimeError("php_vendor_readability_failed")
    load = subprocess.run(
        ["/usr/sbin/runuser", "--user", "www-data", "--group", "www-data", "--",
         "/usr/local/bin/php", "-r", 'require "vendor/autoload.php";'],
        cwd=application_cwd, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL, timeout=5, check=False,
    )
    print(f"PHP_VENDOR_LOAD={'PASS' if load.returncode == 0 else 'FAIL'};RC={load.returncode}")
    if load.returncode != 0:
        raise RuntimeError("php_vendor_load_failed")


def prove_mysql_backup_restore(profile, root: Path):
    """Restore a private backup twice and compare schema, ledger, and row-count proofs."""
    run([
        "/usr/bin/mysql", "--no-defaults", "-h", os.environ["WPCG_MYSQL_HOST"],
        "-u", os.environ["WPCG_MYSQL_USER"], "wpcg_staging_next", "-e",
        "CREATE TABLE wpcg_e2e_restore_probe (id BIGINT PRIMARY KEY, marker VARCHAR(32) NOT NULL) ENGINE=InnoDB; "
        "INSERT INTO wpcg_e2e_restore_probe VALUES (1,'synthetic-row');",
    ], extra_env={"MYSQL_PWD": os.environ["WPCG_MYSQL_PASSWORD"]}, timeout=15)
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

    import_targets = (
        (os.environ["WPCG_RESTORE_MYSQL_HOST"], os.environ["WPCG_RESTORE_MYSQL_USER"],
         os.environ["WPCG_RESTORE_MYSQL_PASSWORD"]),
        (os.environ["WPCG_MIGRATION_MYSQL_HOST"], os.environ["WPCG_MIGRATION_MYSQL_USER"],
         os.environ["WPCG_MIGRATION_MYSQL_PASSWORD"]),
    )
    for host, username, password in import_targets:
        importer = subprocess.Popen([
            "/usr/bin/mysql", "--no-defaults", "-h", host, "-u", username, "wpcg_staging_next",
        ], env={"PATH": "/usr/bin:/bin", "LANG": "C", "MYSQL_PWD": password},
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
        "$r=$p->query($sql)->fetch(PDO::FETCH_ASSOC);"
        "$counts=[];$tables=$p->query(\"SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME\")->fetchAll(PDO::FETCH_NUM);"
        "foreach($tables as [$n,$e]){if($e!=='InnoDB'||!preg_match('/^[A-Za-z0-9_$]{1,64}$/D',$n))exit(32);$counts[]=[$n,(int)$p->query(\"SELECT COUNT(*) FROM `\".$n.\"`\")->fetchColumn()];}"
        "$r['data_sha256']=hash('sha256',json_encode($counts,JSON_UNESCAPED_SLASHES));echo json_encode($r);"
    )
    observed = []
    for host, username, password in (
        (os.environ["WPCG_MYSQL_HOST"], os.environ["WPCG_MYSQL_USER"], os.environ["WPCG_MYSQL_PASSWORD"]),
        (os.environ["WPCG_RESTORE_MYSQL_HOST"], os.environ["WPCG_RESTORE_MYSQL_USER"], os.environ["WPCG_RESTORE_MYSQL_PASSWORD"]),
        (os.environ["WPCG_MIGRATION_MYSQL_HOST"], os.environ["WPCG_MIGRATION_MYSQL_USER"], os.environ["WPCG_MIGRATION_MYSQL_PASSWORD"]),
    ):
        raw = run(["/usr/local/bin/php", "-r", probe], cwd=profile.application_link, timeout=20,
                  extra_env={"H": host, "U": username, "P": password})
        observed.append(json.loads(raw))
    first, second, third = observed
    if (any(not str(value.get("version", "")).startswith("8.4.11") for value in observed)
            or len({value.get("uuid") for value in observed}) != len(observed)
            or len({value.get("tables") for value in observed}) != 1
            or len({value.get("migrations") for value in observed}) != 1
            or int(first.get("tables", 0)) <= 0 or int(first.get("migrations", 0)) <= 0):
        raise RuntimeError("isolated_database_restore_verification_failed")
    if len({value.get("data_sha256") for value in observed}) != 1:
        raise RuntimeError("isolated_database_restore_data_mismatch")
    restore_env = {"MYSQL_PWD": os.environ["WPCG_RESTORE_MYSQL_PASSWORD"]}
    run([
        "/usr/bin/mysql", "--no-defaults", "-h", os.environ["WPCG_RESTORE_MYSQL_HOST"],
        "-u", os.environ["WPCG_RESTORE_MYSQL_USER"], "wpcg_staging_next", "-e",
        "DELETE FROM wpcg_e2e_restore_probe WHERE id=1",
    ], extra_env=restore_env, timeout=15)
    omitted = json.loads(run(
        ["/usr/local/bin/php", "-r", probe], cwd=profile.application_link, timeout=20,
        extra_env={"H": os.environ["WPCG_RESTORE_MYSQL_HOST"],
                   "U": os.environ["WPCG_RESTORE_MYSQL_USER"],
                   "P": os.environ["WPCG_RESTORE_MYSQL_PASSWORD"]}))
    if omitted.get("data_sha256") == first.get("data_sha256"):
        raise RuntimeError("isolated_restore_row_omission_not_detected")
    run([
        "/usr/bin/mysql", "--no-defaults", "-h", os.environ["WPCG_RESTORE_MYSQL_HOST"],
        "-u", os.environ["WPCG_RESTORE_MYSQL_USER"], "wpcg_staging_next", "-e",
        "INSERT INTO wpcg_e2e_restore_probe VALUES (1,'synthetic-row')",
    ], extra_env=restore_env, timeout=15)
    return backup_sha, int(first["tables"]), int(first["migrations"])


def migration_database_fingerprint(profile, *, host, username, password):
    """Hash real MySQL schema/ledger metadata through the deployed Laravel runtime."""
    script = r'''require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$config=$app["config"];
$config->set("database.default","mysql");
foreach (["host"=>"H","database"=>"D","username"=>"U","password"=>"P","port"=>"3306"] as $key=>$env) {
  $config->set("database.connections.mysql.".$key,getenv("WPCG_DB_".$env));
}
$app["db"]->purge("mysql");
$pdo=$app["db"]->connection("mysql")->getPdo();
$identity=$pdo->query("SELECT DATABASE() database_name,VERSION() version,@@server_uuid uuid")->fetch(PDO::FETCH_ASSOC);
if ($identity["database_name"]!=="wpcg_staging_next" || !str_starts_with($identity["version"],"8.4.")) exit(31);
$schema=[];
foreach ([
  "SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION",
  "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX",
  "SELECT TABLE_NAME,COLUMN_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION",
  "SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME",
] as $sql) $schema[]=$pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
$ledger=$pdo->query("SELECT migration,batch FROM migrations ORDER BY migration")->fetchAll(PDO::FETCH_NUM);
$tables=$pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_NUM);
$counts=[];
foreach ($tables as [$name,$engine]) {
  if ($engine!=="InnoDB" || !preg_match('/^[A-Za-z0-9_$]{1,64}$/D',$name)) exit(32);
  $counts[]=[$name,(int)$pdo->query("SELECT COUNT(*) FROM `".$name."`")->fetchColumn()];
}
echo json_encode(["database"=>$identity["database_name"],"version"=>$identity["version"],
  "mysql_uuid_sha256"=>hash("sha256",$identity["uuid"]),
  "schema_sha256"=>hash("sha256",json_encode($schema,JSON_UNESCAPED_SLASHES)),
  "ledger_sha256"=>hash("sha256",json_encode($ledger,JSON_UNESCAPED_SLASHES)),
  "data_sha256"=>hash("sha256",json_encode($counts,JSON_UNESCAPED_SLASHES)),
  "migrations"=>array_column($ledger,0)],JSON_UNESCAPED_SLASHES);'''
    result = subprocess.run(["/usr/local/bin/php", "-r", script], cwd=profile.application_link,
                            env={"PATH": "/usr/local/bin:/usr/bin:/bin",
                                 "WPCG_DB_H": host, "WPCG_DB_D": "wpcg_staging_next",
                                 "WPCG_DB_U": username, "WPCG_DB_P": password},
                            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            timeout=30, check=False)
    if result.returncode != 0 or len(result.stdout) > 65_536 or len(result.stderr) > 16_384:
        raise RuntimeError("migration_database_probe_failed")
    try:
        value = json.loads(result.stdout)
    except (UnicodeError, ValueError):
        raise RuntimeError("migration_database_probe_invalid") from None
    if (not isinstance(value, dict) or value.get("database") != "wpcg_staging_next"
            or not value.get("version", "").startswith("8.4.")
            or not isinstance(value.get("migrations"), list)):
        raise RuntimeError("migration_database_identity_mismatch")
    return value


def verify_database_content_fingerprint(profile):
    """Prove that same-count row-content changes alter the in-memory dump digest."""
    policy = BACKEND._load_phase_b_policy(profile)
    existing = _mysql_query(
        policy, isolated=False,
        sql="SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            "AND TABLE_NAME='wpcg_e2e_content_fingerprint_probe' AND TABLE_TYPE='BASE TABLE'",
        database="wpcg_staging_next", timeout=15,
    )
    if existing != b"0\n":
        raise RuntimeError("content_fingerprint_probe_table_not_absent")
    _mysql_query(
        policy, isolated=False,
        sql="CREATE TABLE `wpcg_e2e_content_fingerprint_probe` "
            "(`id` BIGINT PRIMARY KEY,`marker` VARCHAR(32) NOT NULL) ENGINE=InnoDB; "
            "INSERT INTO `wpcg_e2e_content_fingerprint_probe` VALUES (1,'original-row')",
        database="wpcg_staging_next", timeout=15,
    )
    before = database_fingerprint(policy, database="wpcg_staging_next", isolated=False)
    _mysql_query(
        policy, isolated=False,
        sql="UPDATE `wpcg_e2e_content_fingerprint_probe` SET `marker`='changed-row' WHERE `id`=1",
        database="wpcg_staging_next", timeout=15,
    )
    changed = database_fingerprint(policy, database="wpcg_staging_next", isolated=False)
    if (before["data_sha256"] == changed["data_sha256"]
            or before["schema_sha256"] != changed["schema_sha256"]
            or before["ledger_sha256"] != changed["ledger_sha256"]):
        raise RuntimeError("same_count_row_content_change_not_detected")
    _mysql_query(
        policy, isolated=False,
        sql="UPDATE `wpcg_e2e_content_fingerprint_probe` SET `marker`='original-row' WHERE `id`=1",
        database="wpcg_staging_next", timeout=15,
    )
    restored = database_fingerprint(policy, database="wpcg_staging_next", isolated=False)
    if restored["data_sha256"] != before["data_sha256"]:
        raise RuntimeError("same_count_row_content_fingerprint_not_reproducible")
    print("MYSQL_DATA_CONTENT_FINGERPRINT=PASS;SAME_COUNT_MUTATION_DETECTED=YES")


def execute_laravel_migration(profile, *, host, username, password, path):
    """Run one absolute, test-created Laravel migration through the real Artisan migrator."""
    script = r'''require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$config=$app["config"];
$config->set("database.default","mysql");
foreach (["host"=>"H","database"=>"D","username"=>"U","password"=>"P","port"=>"3306"] as $key=>$env) {
  $config->set("database.connections.mysql.".$key,getenv("WPCG_DB_".$env));
}
$app["db"]->purge("mysql");
$status=Illuminate\Support\Facades\Artisan::call("migrate",[
  "--path"=>getenv("WPCG_MIGRATION_PATH"),"--realpath"=>true,"--force"=>true,
  "--isolated"=>true,"--no-interaction"=>true,"--no-ansi"=>true,
]);
echo json_encode(["exit_code"=>$status]); exit($status);'''
    result = subprocess.run(["/usr/local/bin/php", "-r", script], cwd=profile.application_link,
                            env={"PATH": "/usr/local/bin:/usr/bin:/bin",
                                 "WPCG_DB_H": host, "WPCG_DB_D": "wpcg_staging_next",
                                 "WPCG_DB_U": username, "WPCG_DB_P": password,
                                 "WPCG_MIGRATION_PATH": str(path)},
                            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            timeout=300, check=False)
    if len(result.stdout) > 4096 or len(result.stderr) > 16_384:
        raise RuntimeError("migration_command_output_limit")
    try:
        payload = json.loads(result.stdout)
    except (UnicodeError, ValueError):
        payload = None
    if (result.returncode != 0 or not isinstance(payload, dict)
            or payload.get("exit_code") != 0):
        return False
    return True


def prove_journaled_real_migration(profile, root: Path, *, application_sha: str,
                                   previous_sha: str, artifact_sha256: str, backup_sha256: str):
    """Rehearse then execute one exact migration across two restored disposable MySQL 8.4 instances."""
    migration_name = "2026_11_06_000001_create_wpcg_phase_b_probe"
    migration_dir = root / "phase-b-migration"
    migration_dir.mkdir(mode=0o700)
    migration_path = migration_dir / f"{migration_name}.php"
    migration_path.write_text(
        "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\n"
        "use Illuminate\\Database\\Schema\\Blueprint;\n"
        "use Illuminate\\Support\\Facades\\Schema;\n"
        "return new class extends Migration { public function up(): void { "
        "Schema::create('wpcg_phase_b_probe', function (Blueprint $table): void { "
        "$table->id(); $table->string('value'); $table->timestamps(); }); } "
        "public function down(): void { Schema::dropIfExists('wpcg_phase_b_probe'); } };\n",
        encoding="utf-8")
    os.chmod(migration_path, 0o600)
    rehearsal_host = os.environ["WPCG_MIGRATION_MYSQL_HOST"]
    rehearsal_user = os.environ["WPCG_MIGRATION_MYSQL_USER"]
    rehearsal_password = os.environ["WPCG_MIGRATION_MYSQL_PASSWORD"]
    execution_host = os.environ["WPCG_RESTORE_MYSQL_HOST"]
    execution_user = os.environ["WPCG_RESTORE_MYSQL_USER"]
    execution_password = os.environ["WPCG_RESTORE_MYSQL_PASSWORD"]
    rehearsal_before = migration_database_fingerprint(
        profile, host=rehearsal_host, username=rehearsal_user, password=rehearsal_password)
    if not execute_laravel_migration(profile, host=rehearsal_host, username=rehearsal_user,
                                     password=rehearsal_password, path=migration_path):
        raise RuntimeError("migration_rehearsal_failed")
    rehearsal_after = migration_database_fingerprint(
        profile, host=rehearsal_host, username=rehearsal_user, password=rehearsal_password)
    if (migration_name not in rehearsal_after["migrations"]
            or rehearsal_after["schema_sha256"] == rehearsal_before["schema_sha256"]):
        raise RuntimeError("migration_rehearsal_postcondition_failed")
    execution_before = migration_database_fingerprint(
        profile, host=execution_host, username=execution_user, password=execution_password)
    if (execution_before["schema_sha256"] != rehearsal_before["schema_sha256"]
            or execution_before["ledger_sha256"] != rehearsal_before["ledger_sha256"]):
        raise RuntimeError("migration_restore_baseline_mismatch")
    receipt = hashlib.sha256(json.dumps({
        "application_sha": application_sha,
        "backup_sha256": backup_sha256,
        "migration": migration_name,
        "migration_sha256": hashlib.sha256(migration_path.read_bytes()).hexdigest(),
        "before_schema_sha256": rehearsal_before["schema_sha256"],
        "before_ledger_sha256": rehearsal_before["ledger_sha256"],
        "after_schema_sha256": rehearsal_after["schema_sha256"],
        "after_ledger_sha256": rehearsal_after["ledger_sha256"],
    }, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    rehearsal_receipt = hashlib.sha256((backup_sha256 + receipt).encode()).hexdigest()
    restore_checkpoint = {
        "backup_sha256": backup_sha256,
        "target_schema_sha256": execution_before["schema_sha256"],
        "target_ledger_sha256": execution_before["ledger_sha256"],
        "target_data_sha256": execution_before["data_sha256"],
        "restored_schema_sha256": rehearsal_before["schema_sha256"],
        "restored_ledger_sha256": rehearsal_before["ledger_sha256"],
        "restored_data_sha256": rehearsal_before["data_sha256"],
        "restore_mysql_uuid_sha256": rehearsal_before["mysql_uuid_sha256"],
    }
    restore_receipt = restore_receipt_sha256(restore_checkpoint)
    restore_checkpoint["restore_receipt_sha256"] = restore_receipt
    fence_receipt = hashlib.sha256(b"isolated-e2e:no-worker:no-scheduler:no-external-network").hexdigest()
    identity = TransactionIdentity(
        application_sha=application_sha, previous_sha=previous_sha,
        artifact_sha256=artifact_sha256,
        backup_sha256=backup_sha256,
        backup_filename=f"staging-next-{application_sha}-1-00000000.sql.gz",
        mysql_uuid_sha256=execution_before["mysql_uuid_sha256"],
        migrations=(migration_name,),
    )
    transaction = DatabaseReleaseTransaction(profile.state_dir, identity)
    with transaction:
        transaction.advance("BACKUP_VERIFIED", {"backup_sha256": backup_sha256})
        transaction.advance("RESTORE_REHEARSAL_VERIFIED", restore_checkpoint)
        transaction.advance("MIGRATION_REHEARSAL_VERIFIED", {
            "migration_rehearsal_receipt_sha256": rehearsal_receipt})
        transaction.advance("FENCES_CLOSED", {"fence_receipt_sha256": fence_receipt})
        def observe_execution_database():
            value = migration_database_fingerprint(
                profile, host=execution_host, username=execution_user, password=execution_password)
            return {key: value[key] for key in (
                "schema_sha256", "ledger_sha256", "data_sha256", "migrations")}

        def apply_reviewed_migration(name):
            return (name == migration_name and execute_laravel_migration(
                profile, host=execution_host, username=execution_user,
                password=execution_password, path=migration_path))

        transaction.apply_rehearsed_migrations(
            expected_after={migration_name: {
                "schema_sha256": rehearsal_after["schema_sha256"],
                "ledger_sha256": rehearsal_after["ledger_sha256"],
                "data_sha256": rehearsal_after["data_sha256"],
            }},
            observe=observe_execution_database,
            apply_one=apply_reviewed_migration,
        )
        execution_after = migration_database_fingerprint(
            profile, host=execution_host, username=execution_user, password=execution_password)
        if transaction.state["state"] != "MIGRATIONS_VERIFIED":
            raise RuntimeError("journaled_migration_not_verified")
        if (execution_after["schema_sha256"] != rehearsal_after["schema_sha256"]
                or execution_after["ledger_sha256"] != rehearsal_after["ledger_sha256"]
                or execution_after["data_sha256"] != rehearsal_after["data_sha256"]
                or migration_name not in execution_after["migrations"]):
            raise RuntimeError("journaled_migration_postcondition_mismatch")
        print("MIGRATION_REHEARSAL=PASS;ISOLATED_EXECUTION=PASS;JOURNAL=PASS")


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
    migration = repo / "database/migrations/2026_11_06_000001_create_wpcg_phase_b_probe.php"
    migration.parent.mkdir(parents=True, exist_ok=True)
    migration.write_text(
        "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\n"
        "use Illuminate\\Support\\Facades\\DB;\n"
        "return new class extends Migration { public function up(): void { "
        "DB::unprepared('CREATE TRIGGER wpcg_phase_b_probe AFTER INSERT ON users "
        "FOR EACH ROW SET @wpcg_phase_b_probe = NEW.id'); } "
        "public function down(): void { DB::unprepared('DROP TRIGGER IF EXISTS wpcg_phase_b_probe'); } };\n",
        encoding="utf-8")
    git(repo, "add", "resources/views/pages/home.blade.php", "database/migrations/2026_11_06_000001_create_wpcg_phase_b_probe.php")
    git(repo, "commit", "--quiet", "-m", "isolated database migration release")
    candidate = git(repo, "rev-parse", "HEAD")
    return base, candidate


def create_active_release(profile, repo, base_sha):
    CONTROLLER.SOURCE_CHECKOUT = repo
    CONTROLLER.INCOMING_DIRECTORY = profile.incoming_dir
    archive_path, artifact_sha = CONTROLLER._package_release(base_sha, base_sha, test_only=True)
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
        basic_auth_file = root / "basic-auth.json"
        basic_auth_file.write_text(json.dumps({
            "username": "e2e-owner", "password": "synthetic-only-password"}), encoding="utf-8")
        os.chown(basic_auth_file, 0, 0)
        os.chmod(basic_auth_file, 0o600)
        storage = shared / "storage"
        storage.mkdir(mode=0o2770)
        os.chmod(storage, 0o2770)
        for child in ("app/public", "framework/cache/data", "framework/sessions",
                      "framework/views", "logs"):
            (storage / child).mkdir(parents=True, exist_ok=True, mode=0o2770)
            os.chmod(storage / child, 0o2770)
        for path in (storage, *storage.rglob("*")):
            os.chown(path, 33, 33, follow_symlinks=False)
        backup_dir = root / "database-backups"
        backup_dir.mkdir(mode=0o700)
        os.chmod(backup_dir, 0o700)
        worker_marker = state / "workers-disabled"
        scheduler_marker = state / "scheduler-paused"
        worker_marker.write_bytes(b"schema=1;profile=staging-next;workers=disabled\n")
        scheduler_marker.write_bytes(b"schema=1;profile=staging-next;scheduler=paused\n")
        os.chmod(worker_marker, 0o600)
        os.chmod(scheduler_marker, 0o600)
        policy_dir = root / "phase-b-policy"
        policy_dir.mkdir(mode=0o700)
        os.chmod(policy_dir, 0o700)
        credentials_file = policy_dir / "credentials.json"
        credentials_raw = json.dumps({
            "schema": 1,
            "target": {"username": os.environ["WPCG_MYSQL_USER"],
                       "password": os.environ["WPCG_MYSQL_PASSWORD"]},
            "isolated": {"username": "root", "password": "synthetic-only-password"},
        }, sort_keys=True, separators=(",", ":")).encode("utf-8")
        credentials_file.write_bytes(credentials_raw)
        os.chmod(credentials_file, 0o600)
        policy_file = policy_dir / "staging-next.json"
        policy_value = {
            "schema": 1, "profile": "staging-next", "enabled": True,
            "target_database": "wpcg_staging_next",
            "target_uuid_sha256": os.environ["WPCG_MYSQL_UUID_SHA256"],
            "isolated_uuid_sha256": os.environ["WPCG_RESTORE_MYSQL_UUID_SHA256"],
            "backup_directory": str(backup_dir), "credentials_file": str(credentials_file),
            "credentials_sha256": hashlib.sha256(credentials_raw).hexdigest(),
            "target_host": os.environ["WPCG_MYSQL_HOST"], "target_port": 3306,
            "restore_host": os.environ["WPCG_RESTORE_MYSQL_HOST"], "restore_port": 3306,
            "maintenance_file": str(storage / "framework" / "down"),
            "worker_programs": ["wpcaregrid-staging-next-default", "wpcaregrid-staging-next-backups"],
            "scheduler_unit": "wpcaregrid-staging-next-scheduler.timer",
            "worker_disabled_marker": str(worker_marker),
            "scheduler_paused_marker": str(scheduler_marker),
        }
        policy_raw = json.dumps(policy_value, sort_keys=True, separators=(",", ":")).encode("utf-8")
        policy_file.write_bytes(policy_raw)
        os.chmod(policy_file, 0o644)

        def phase_b_command_runner(argv, **kwargs):
            if argv[:2] == ["/usr/bin/supervisorctl", "status"]:
                program = argv[2]
                return subprocess.CompletedProcess(argv, 3, f"{program} STOPPED\n".encode(), b"")
            if argv[:3] == ["/bin/systemctl", "show", "--property=ActiveState"]:
                return subprocess.CompletedProcess(argv, 0, b"inactive\n", b"")
            if argv[:3] == ["/usr/bin/systemctl", "show", "--property=UnitFileState"]:
                return subprocess.CompletedProcess(argv, 0, b"disabled\n", b"")
            return subprocess.run(argv, **kwargs)

        phase_b_test_paths = {
            "policy": policy_file, "credentials": credentials_file, "backup": backup_dir,
            "target_host": policy_value["target_host"], "target_port": 3306,
            "restore_host": policy_value["restore_host"], "restore_port": 3306,
            "worker_disabled_marker": worker_marker,
            "scheduler_paused_marker": scheduler_marker,
            "command_runner": phase_b_command_runner,
        }
        verify_mysqldump_option_file()
        verify_mysql_client_query()
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
            basic_auth_file=basic_auth_file, mysql_server_uuid_sha256=os.environ["WPCG_MYSQL_UUID_SHA256"],
            helper_artifact_sha256=CONTROLLER.HELPER_ARTIFACT_SHA256, php_fpm_uid=33, php_fpm_gid=33,
            test_git_repo=repo, allowed_uid=1000, expected_owner_uid=0,
            acceptance_urls=tuple((f"http://localhost:{port}{path}", status)
                                  for path, status in (("/up", 200), ("/hu", 200), ("/en", 200),
                                                       ("/register", 404), ("/checkout", 404), ("/api", 404))),
            protected_acceptance_urls=(f"http://localhost:{port}/hu", f"http://localhost:{port}/en"),
            build_application_bootstrap=True, test_only=True, verify_test_database=True,
            phase_b_policy_file=policy_file,
            phase_b_policy_sha256=hashlib.sha256(policy_raw).hexdigest(),
            phase_b_test_only_paths=phase_b_test_paths,
        )
        create_active_release(profile, repo, base_sha)
        verify_php_vendor_readability(vendor, profile.application_link)
        run_as_php_fpm(["/usr/local/bin/php", "artisan", "migrate", "--force", "--isolated", "--no-interaction"],
                       cwd=profile.application_link, timeout=900)
        migration_status = run_as_php_fpm(
            ["/usr/local/bin/php", "artisan", "migrate:status", "--no-interaction"],
            cwd=profile.application_link, timeout=60)
        if "Pending" in migration_status:
            raise RuntimeError("isolated_migration_pending_after_run")
        print(f"BASELINE_MIGRATIONS=PASS;COUNT={len(migration_status.splitlines()) - 1}")
        verify_database_content_fingerprint(profile)
        run([
            "/usr/bin/mysql", "--no-defaults", "-h", os.environ["WPCG_MYSQL_HOST"],
            "-u", os.environ["WPCG_MYSQL_USER"], "wpcg_staging_next", "-e",
            "CREATE TABLE `wpcg_e2e_restore_probe` (`id` BIGINT PRIMARY KEY,"
            "`marker` VARCHAR(32) NOT NULL) ENGINE=InnoDB; "
            "INSERT INTO `wpcg_e2e_restore_probe` VALUES (1,'synthetic-row')",
        ], extra_env={"MYSQL_PWD": os.environ["WPCG_MYSQL_PASSWORD"]}, timeout=15)
        run([
            "/usr/bin/mysql", "--no-defaults", "-h", os.environ["WPCG_MYSQL_HOST"],
            "-u", "root", "--batch", "--skip-column-names",
            "-e", "SET GLOBAL log_bin_trust_function_creators=OFF",
        ], extra_env={"MYSQL_PWD": "synthetic-only-password"}, timeout=10)
        binlog_state = run([
            "/usr/bin/mysql", "--no-defaults", "-h", os.environ["WPCG_MYSQL_HOST"],
            "-u", "root", "--batch", "--skip-column-names",
            "-e", "SELECT @@global.log_bin,@@global.binlog_format,@@global.log_bin_trust_function_creators",
        ], extra_env={"MYSQL_PWD": "synthetic-only-password"}, timeout=10)
        if binlog_state != "1\tROW\t0":
            raise RuntimeError("synthetic_binlog_1419_precondition_invalid")
        print("MYSQL_BINLOG_TRIGGER_RESTRICTION=PASS;BINLOG=ON;FORMAT=ROW;TRUST=OFF")
        php_env = {"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": str(storage),
                   "WPCG_CURRENT_APP": str(profile.application_link),
                   "WPCG_TEST_HTTP_AUTH_USER": "e2e-owner",
                   "WPCG_TEST_HTTP_AUTH_PASSWORD": "synthetic-only-password"}
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
                readiness_request = urllib.request.Request(
                    f"http://localhost:{port}/up", headers={
                        "Authorization": "Basic " + base64.b64encode(
                            b"e2e-owner:synthetic-only-password").decode("ascii")})
                with urllib.request.urlopen(readiness_request, timeout=1) as response:
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

        artifact_path, artifact_sha = CONTROLLER._package_release(candidate_sha, base_sha, test_only=True)
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
        # Exercise a real MySQL identity-contract failure, then resume once with the
        # verified disposable instance identity. The first attempt must not gate,
        # switch releases, or modify the target migration ledger.
        wrong_identity_profile = replace(profile, mysql_server_uuid_sha256="0" * 64)
        rejected = BACKEND.deploy_release(
            wrong_identity_profile, invoking_uid=1000, application_sha=candidate_sha,
            artifact_sha256=artifact_sha)
        if (rejected.status, rejected.reason) != ("HOLD", "database_identity_mismatch"):
            raise RuntimeError(f"phase_b_failure_injection_not_held:{rejected.status}:{rejected.reason}")
        if (profile.application_link.resolve() != (release_dir / base_sha).resolve()
                or (storage / "framework" / "down").exists()):
            raise RuntimeError("phase_b_failure_injection_changed_active_state")
        unchanged_ledger = run([
            "/usr/bin/mysql", "--no-defaults", "-h", os.environ["WPCG_MYSQL_HOST"],
            "-u", os.environ["WPCG_MYSQL_USER"], "--batch", "--skip-column-names",
            "wpcg_staging_next", "-e", "SELECT COUNT(*) FROM migrations",
        ], extra_env={"MYSQL_PWD": os.environ["WPCG_MYSQL_PASSWORD"]}, timeout=15)
        if unchanged_ledger != "72":
            raise RuntimeError("phase_b_failure_injection_modified_database")
        execute_migration = BACKEND._execute_exact_migration

        def fail_rehearsal_once(*args, **kwargs):
            if kwargs.get("operation") == "rehearsal":
                raise BACKEND.DeployRefused("synthetic_rehearsal_failure")
            return execute_migration(*args, **kwargs)

        BACKEND._execute_exact_migration = fail_rehearsal_once
        rehearsal_failure = BACKEND.deploy_release(
            profile, invoking_uid=1000, application_sha=candidate_sha, artifact_sha256=artifact_sha)
        BACKEND._execute_exact_migration = execute_migration
        if (rehearsal_failure.status, rehearsal_failure.reason) != ("HOLD", "synthetic_rehearsal_failure"):
            raise RuntimeError("phase_b_rehearsal_failure_not_held")
        if ((release_dir / candidate_sha).exists()
                or profile.application_link.resolve() != (release_dir / base_sha).resolve()
                or (storage / "framework" / "down").exists()):
            raise RuntimeError("phase_b_rehearsal_failure_changed_release_state")
        rehearsal_journals = list(state.glob("db-release-*.json"))
        if len(rehearsal_journals) != 1:
            raise RuntimeError("phase_b_rehearsal_failure_journal_missing")
        rehearsal_state = json.loads(rehearsal_journals[0].read_text(encoding="utf-8"))
        if rehearsal_state.get("state") != "RESTORE_REHEARSAL_VERIFIED":
            raise RuntimeError("phase_b_rehearsal_failure_checkpoint_invalid")

        # Change a synthetic target row only after the isolated migration rehearsal
        # and persistent fences are complete. The production path must detect that
        # the tested baseline is stale before invoking any target migration.
        verify_fences = BACKEND._verify_phase_b_fences
        injected_target_change = [False]
        fence_verifications = [0]

        def mutate_after_fencing(active_profile, policy):
            observed_fences = verify_fences(active_profile, policy)
            fence_verifications[0] += 1
            # The first two fence checks occur while the rehearsal is being
            # prepared. Inject only after its target snapshot and durable fence
            # checkpoint have both been recorded, immediately before target
            # migration execution.
            if fence_verifications[0] == 3 and not injected_target_change[0]:
                _mysql_query(
                    policy, isolated=False,
                    sql="UPDATE `wpcg_e2e_restore_probe` SET `marker`='rehearsal-race' WHERE `id`=1",
                    database="wpcg_staging_next", timeout=15,
                )
                injected_target_change[0] = True
            return observed_fences

        BACKEND._verify_phase_b_fences = mutate_after_fencing
        stale_failure_classes = []

        def capture_stale_failure(frame, event, arg):
            if (event == "exception" and frame.f_code.co_filename.endswith((
                    "staging_next_deploy_backend_1_4_8.py",
                    "staging_next_db_transaction_1_4_8.py"))):
                exception_type, exception, _traceback = arg
                if isinstance(exception, (BACKEND.DeployRefused, BACKEND.TransactionRefused)):
                    safe_reason = str(exception)
                    if not re.fullmatch(r"[a-z][a-z0-9_]{2,95}", safe_reason):
                        safe_reason = "non_allowlisted_reason"
                    stale_failure_classes.append(f"{exception_type.__name__}:{safe_reason}")
                else:
                    stale_failure_classes.append(exception_type.__name__)
            return capture_stale_failure

        sys.settrace(capture_stale_failure)
        try:
            stale_rehearsal = BACKEND.deploy_release(
                profile, invoking_uid=1000, application_sha=candidate_sha, artifact_sha256=artifact_sha)
        finally:
            sys.settrace(None)
        BACKEND._verify_phase_b_fences = verify_fences
        if not injected_target_change[0] or (stale_rehearsal.status, stale_rehearsal.reason) != (
                "HOLD", "target_changed_since_migration_rehearsal"):
            observed_failure = stale_failure_classes[-1] if stale_failure_classes else "NO_EXCEPTION_CAPTURED"
            raise RuntimeError(
                f"phase_b_stale_rehearsal_not_held:{stale_rehearsal.status}:"
                f"{stale_rehearsal.reason}:{observed_failure}")
        stale_ledger = run([
            "/usr/bin/mysql", "--no-defaults", "-h", os.environ["WPCG_MYSQL_HOST"],
            "-u", os.environ["WPCG_MYSQL_USER"], "--batch", "--skip-column-names",
            "wpcg_staging_next", "-e", "SELECT COUNT(*) FROM migrations",
        ], extra_env={"MYSQL_PWD": os.environ["WPCG_MYSQL_PASSWORD"]}, timeout=15)
        if stale_ledger != "72":
            raise RuntimeError("phase_b_stale_rehearsal_ran_target_migration")
        _mysql_query(
            BACKEND._load_phase_b_policy(profile), isolated=False,
            sql="UPDATE `wpcg_e2e_restore_probe` SET `marker`='synthetic-row' WHERE `id`=1",
            database="wpcg_staging_next", timeout=15,
        )
        print("PHASE_B_STALE_REHEARSAL_HOLD=PASS;TARGET_MIGRATIONS=NOT_RUN")

        errno_1419 = BACKEND.deploy_release(
            profile, invoking_uid=1000, application_sha=candidate_sha, artifact_sha256=artifact_sha)
        if (errno_1419.status, errno_1419.reason) != (
                "HOLD", "mysql_errno_1419_sqlstate_hy000"):
            raise RuntimeError(f"mysql_errno_1419_not_safely_classified:{errno_1419.status}:{errno_1419.reason}")
        if (profile.application_link.resolve() != (release_dir / base_sha).resolve()
                or not (storage / "framework" / "down").is_file()):
            raise RuntimeError("mysql_errno_1419_release_fence_invalid")
        after_1419_ledger = run([
            "/usr/bin/mysql", "--no-defaults", "-h", os.environ["WPCG_MYSQL_HOST"],
            "-u", os.environ["WPCG_MYSQL_USER"], "--batch", "--skip-column-names",
            "wpcg_staging_next", "-e", "SELECT COUNT(*) FROM migrations",
        ], extra_env={"MYSQL_PWD": os.environ["WPCG_MYSQL_PASSWORD"]}, timeout=15)
        if after_1419_ledger != "72":
            raise RuntimeError("mysql_errno_1419_changed_target_ledger")
        retry_state = json.loads(rehearsal_journals[0].read_text(encoding="utf-8"))
        if (retry_state.get("state") != "FENCES_CLOSED"
                or retry_state.get("migration_cursor") != 0):
            raise RuntimeError("mysql_errno_1419_checkpoint_not_retryable")
        # This root-only setting change is confined to the disposable E2E MySQL.
        # The production helper never changes MySQL global variables.
        run([
            "/usr/bin/mysql", "--no-defaults", "-h", os.environ["WPCG_MYSQL_HOST"],
            "-u", "root", "--batch", "--skip-column-names",
            "-e", "SET GLOBAL log_bin_trust_function_creators=ON",
        ], extra_env={"MYSQL_PWD": "synthetic-only-password"}, timeout=10)
        print("MYSQL_ERRNO_1419=HOLD;UNCHANGED_DATABASE=PASS;SYNTHETIC_DBA_RETRY=AUTHORIZED")

        # Simulate a real process death after Laravel commits the target DDL and
        # migration ledger, but before the journal completion checkpoint.
        child = os.fork()
        if child == 0:
            def die_after_committed_ddl(_transaction, *_args, **_kwargs):
                os._exit(86)

            BACKEND.DatabaseReleaseTransaction.complete_migration = die_after_committed_ddl
            child_result = BACKEND.deploy_release(
                profile, invoking_uid=1000, application_sha=candidate_sha, artifact_sha256=artifact_sha)
            os.write(2, (f"PHASE_B_CRASH_CHILD_RETURN={child_result.status}:{child_result.reason}\n").encode("ascii"))
            os._exit(87)
        _child, child_status = os.waitpid(child, 0)
        if not os.WIFEXITED(child_status) or os.WEXITSTATUS(child_status) != 86:
            observed_exit = os.WEXITSTATUS(child_status) if os.WIFEXITED(child_status) else "SIGNAL"
            raise RuntimeError(f"phase_b_process_crash_injection_failed:{observed_exit}")
        crash_journals = list(state.glob("db-release-*.json"))
        if len(crash_journals) != 1:
            raise RuntimeError("phase_b_crash_journal_missing")
        crash_state = json.loads(crash_journals[0].read_text(encoding="utf-8"))
        if crash_state.get("state") != "MIGRATING":
            raise RuntimeError("phase_b_crash_checkpoint_not_durable")
        if (profile.application_link.resolve() != (release_dir / base_sha).resolve()
                or not (storage / "framework" / "down").is_file()):
            raise RuntimeError("phase_b_crash_fences_not_preserved")
        crash_ledger = run([
            "/usr/bin/mysql", "--no-defaults", "-h", os.environ["WPCG_MYSQL_HOST"],
            "-u", os.environ["WPCG_MYSQL_USER"], "--batch", "--skip-column-names",
            "wpcg_staging_next", "-e",
            "SELECT COUNT(*) FROM migrations; "
            "SELECT COUNT(*) FROM information_schema.TRIGGERS "
            "WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='wpcg_phase_b_probe'",
        ], extra_env={"MYSQL_PWD": os.environ["WPCG_MYSQL_PASSWORD"]}, timeout=15)
        if crash_ledger.splitlines() != ["73", "1"]:
            raise RuntimeError("phase_b_crash_committed_ddl_not_observed")

        # Re-enter the interrupted migration with a real PHP-FPM cache build,
        # then terminate the controller after config:cache has written files.
        # The inactive immutable release must be safely repairable on restart.
        cache_crash_child = os.fork()
        if cache_crash_child == 0:
            original_runner = BACKEND._run_as_php_fpm

            def run_php_fpm_then_die(*args, **kwargs):
                result = original_runner(*args, **kwargs)
                argv = args[1]
                if len(argv) > 2 and argv[2] == "config:cache":
                    os._exit(84)
                return result

            BACKEND._run_as_php_fpm = run_php_fpm_then_die
            child_result = BACKEND.deploy_release(
                profile, invoking_uid=1000, application_sha=candidate_sha, artifact_sha256=artifact_sha)
            os.write(2, (f"PHASE_B_CACHE_CHILD_RETURN={child_result.status}:{child_result.reason}\n").encode("ascii"))
            os._exit(87)
        _cache_child, cache_child_status = os.waitpid(cache_crash_child, 0)
        if not os.WIFEXITED(cache_child_status) or os.WEXITSTATUS(cache_child_status) != 84:
            observed_exit = os.WEXITSTATUS(cache_child_status) if os.WIFEXITED(cache_child_status) else "SIGNAL"
            raise RuntimeError(f"phase_b_cache_process_crash_injection_failed:{observed_exit}")
        candidate_cache = release_dir / candidate_sha / "bootstrap/cache"
        cache_metadata = []
        for name in (".gitignore", "config.php", "packages.php", "services.php"):
            try:
                item = os.lstat(candidate_cache / name)
                cache_metadata.append(
                    f"{name}:{item.st_uid}:{item.st_gid}:{stat.S_IMODE(item.st_mode):04o}:"
                    f"{item.st_nlink}:{item.st_size}")
            except FileNotFoundError:
                cache_metadata.append(f"{name}:ABSENT")
        print("PHASE_B_INTERRUPTED_CACHE_METADATA=" + ",".join(cache_metadata))
        cache_config = candidate_cache / "config.php"
        if ((candidate_cache.stat().st_uid, candidate_cache.stat().st_gid,
             stat.S_IMODE(candidate_cache.stat().st_mode)) != (33, 33, 0o2750)
                or cache_config.stat().st_uid != 33
                or profile.application_link.resolve() != (release_dir / base_sha).resolve()
                or not (storage / "framework" / "down").is_file()):
            raise RuntimeError("phase_b_cache_crash_state_not_preserved")
        print("PHASE_B_REAL_LARAVEL_CACHE_CRASH=PASS;FPM_OWNED_CACHE=OBSERVED")

        receipt_crash_child = os.fork()
        if receipt_crash_child == 0:
            original_advance = BACKEND.DatabaseReleaseTransaction.advance

            def die_after_receipt_commit(transaction, target, evidence):
                if target == "COMPLETE":
                    os._exit(85)
                return original_advance(transaction, target, evidence)

            BACKEND.DatabaseReleaseTransaction.advance = die_after_receipt_commit
            child_result = BACKEND.deploy_release(
                profile, invoking_uid=1000, application_sha=candidate_sha, artifact_sha256=artifact_sha)
            os.write(2, (f"PHASE_B_RECEIPT_CHILD_RETURN={child_result.status}:{child_result.reason}\n").encode("ascii"))
            os._exit(87)
        _receipt_child, receipt_child_status = os.waitpid(receipt_crash_child, 0)
        if not os.WIFEXITED(receipt_child_status) or os.WEXITSTATUS(receipt_child_status) != 85:
            observed_exit = os.WEXITSTATUS(receipt_child_status) if os.WIFEXITED(receipt_child_status) else "SIGNAL"
            raise RuntimeError(f"phase_b_receipt_crash_injection_failed:{observed_exit}")
        receipt_journal = state / f"db-release-{crash_state['transaction_id']}.json"
        accepted_state = json.loads(receipt_journal.read_text(encoding="utf-8"))
        if accepted_state.get("state") != "ACCEPTANCE_VERIFIED":
            raise RuntimeError("phase_b_receipt_crash_state_invalid")
        durable_receipts = []
        for receipt_file in profile.receipts_dir.glob("deploy-*.json"):
            item = json.loads(receipt_file.read_text(encoding="utf-8"))
            if (item.get("application_sha") == candidate_sha and item.get("artifact_sha256") == artifact_sha
                    and item.get("database_changed") is True and item.get("result") == "PASS"):
                durable_receipts.append((receipt_file, item, hashlib.sha256(receipt_file.read_bytes()).hexdigest()))
        if len(durable_receipts) != 1:
            raise RuntimeError("phase_b_durable_receipt_missing_or_ambiguous")
        durable_receipt_path, receipt, durable_receipt_sha = durable_receipts[0]
        result = BACKEND.deploy_release(profile, invoking_uid=1000, application_sha=candidate_sha,
                                        artifact_sha256=artifact_sha)
        if (result.status, result.reason) != ("NO_OP", "exact_release_already_active"):
            raise RuntimeError(f"phase_b_receipt_restart_not_reconciled:{result.status}:{result.reason}")
        if result.status != "PASS" and result.status != "NO_OP":
            observed_codes = []
            laravel_error_class = "UNAVAILABLE"
            log_path = storage / "logs" / "laravel.log"
            if log_path.is_file() and not log_path.is_symlink():
                # Keep exception messages, SQL, paths, and credentials private.
                for match in re.finditer(r"production\.ERROR: ([A-Za-z0-9_\\]+)",
                                         log_path.read_text(encoding="utf-8", errors="replace")):
                    laravel_error_class = match.group(1)
            if result.receipt_path:
                failure_receipt = json.loads(Path(result.receipt_path).read_text(encoding="utf-8"))
                observed_codes = [check.get("observed_status") for check in failure_receipt.get("acceptance", [])]
            raise RuntimeError(f"deployment_{result.status.lower()}_{result.reason}:http={observed_codes}:laravel_error_class={laravel_error_class}")
        if (receipt.get("active_sha_after_deploy") != candidate_sha or receipt.get("result") != "PASS"
                or receipt.get("database_changed") is not True
                or not re.fullmatch(r"[0-9a-f]{64}", receipt.get("database_backup_sha256", ""))
                or not re.fullmatch(r"[0-9a-f]{64}", receipt.get("restore_receipt_sha256", ""))
                or not re.fullmatch(r"[0-9a-f]{64}", receipt.get("migration_rehearsal_receipt_sha256", ""))
                or not re.fullmatch(r"[0-9a-f]{64}", receipt.get("migration_receipt_sha256", ""))):
            raise RuntimeError("observed_receipt_mismatch")
        journal = state / f"db-release-{receipt['database_transaction_id']}.json"
        journal_state = json.loads(journal.read_text(encoding="utf-8"))
        if (journal_state.get("state") != "COMPLETE"
                or journal_state.get("evidence", {}).get("COMPLETE", {}).get("release_receipt_sha256")
                != durable_receipt_sha):
            raise RuntimeError("database_transaction_not_complete")
        print(f"PHASE_B_RECEIPT_RESTART=PASS;DURABLE_PASS_RECEIPT_SHA256={durable_receipt_sha}")
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
                readiness_request = urllib.request.Request(
                    f"http://localhost:{port}/up", headers={
                        "Authorization": "Basic " + base64.b64encode(
                            b"e2e-owner:synthetic-only-password").decode("ascii")})
                with urllib.request.urlopen(readiness_request, timeout=1) as response:
                    if response.status == 200:
                        break
            except (urllib.error.URLError, TimeoutError):
                time.sleep(0.25)
        else:
            raise RuntimeError("reloaded_laravel_http_readiness_timeout")
        try:
            acceptance_request = urllib.request.Request(
                f"http://localhost:{port}/hu", headers={
                    "Authorization": "Basic " + base64.b64encode(
                        b"e2e-owner:synthetic-only-password").decode("ascii")})
            with urllib.request.urlopen(acceptance_request, timeout=3) as response:
                body = response.read(2_000_000).decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
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
        migration_status = run(["/usr/local/bin/php", "artisan", "migrate:status", "--no-interaction"],
                               cwd=profile.application_link, timeout=60)
        if "Pending" in migration_status or "2026_11_06_000001_create_wpcg_phase_b_probe" not in migration_status:
            raise RuntimeError("phase_b_target_migration_postcheck_failed")
        trigger_count = run([
            "/usr/bin/mysql", "--no-defaults", "-h", os.environ["WPCG_MYSQL_HOST"],
            "-u", os.environ["WPCG_MYSQL_USER"], "--batch", "--skip-column-names",
            "wpcg_staging_next", "-e", "SELECT COUNT(*) FROM information_schema.triggers "
            "WHERE trigger_schema='wpcg_staging_next' AND trigger_name='wpcg_phase_b_probe'",
        ], extra_env={"MYSQL_PWD": os.environ["WPCG_MYSQL_PASSWORD"]}, timeout=15)
        if trigger_count != "1":
            raise RuntimeError("phase_b_synthetic_trigger_postcheck_failed")
        print(f"PHASE_B_DEPLOY=PASS\nPHASE_B_BACKUP_RESTORE_REHEARSAL=PASS\n"
              f"PHASE_B_FAILURE_INJECTION_RECOVERY=PASS\nPHASE_B_PRETARGET_RESUME=PASS\n"
              f"PHASE_B_PROCESS_RESTART_RESUME=PASS\nMYSQL_TRIGGER_MIGRATION=PASS\n"
              f"PHASE_B_MIGRATIONS=PASS;COUNT={len(migration_status.splitlines()) - 1}\n"
              f"LARAVEL_RELEASE={candidate_sha}\nMYSQL=8.4.11\nHTTP=6/6_PASS\nNO_OP=PASS\n"
              f"RECEIPT_SHA256={durable_receipt_sha}\nBACKUP_SHA256={receipt['database_backup_sha256']}")
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
