#!/usr/bin/env python3
"""Synthetic-only tests for the pinned staging dump restore preflight."""

import gzip
import hashlib
import importlib.util
import os
import socket
import stat
import sys
import threading
from types import SimpleNamespace
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("isolated_staging_restore", ROOT / "scripts/isolated-staging-restore.py")
RESTORE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = RESTORE
SPEC.loader.exec_module(RESTORE)

SCHEMA = "wpcg_recovery_restore_20261104_a1b2c3d4"
SAFE_DUMP = b"""-- Current Database: `wpcaregrid`
CREATE DATABASE /*!32312 IF NOT EXISTS*/ `wpcaregrid` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;
USE `wpcaregrid`;
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
CREATE TABLE `migrations` (`id` int NOT NULL);
CREATE TABLE `synthetic` (`id` int NOT NULL);
INSERT INTO `synthetic` VALUES (1),('SET GLOBAL x=1; wpcaregrid.other');
DELIMITER ;;
CREATE TRIGGER `synthetic_bi` BEFORE INSERT ON `synthetic` FOR EACH ROW SET NEW.`id`=COALESCE(NEW.`id`,1);;
DELIMITER ;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
"""


def expect_refusal(sql, code):
    try:
        RESTORE.audit_sql(sql, SCHEMA)
    except RESTORE.RestoreRefused as error:
        assert str(error) == code, (str(error), code)
    else:
        raise AssertionError(f"Expected {code}")


def test_safe_dump_is_transformed_narrowly():
    plan = RESTORE.audit_sql(SAFE_DUMP, SCHEMA)
    explicit_session_mode = SAFE_DUMP.replace(b"@OLD_SQL_MODE=@@SQL_MODE", b"@OLD_SQL_MODE=@@SESSION.SQL_MODE")
    assert RESTORE.audit_sql(explicit_session_mode, SCHEMA)["schema_references"] == 0
    transformed = plan["transformed_sql"]
    assert plan["source_selector_lines"] == [2, 3]
    assert plan["schema_references"] == 0
    assert plan["create_sql"] == f"CREATE DATABASE `{SCHEMA}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    assert b"-- Current Database: `wpcaregrid`" in transformed  # inert comment preserved
    assert b"CREATE DATABASE" not in transformed
    assert f"USE `{SCHEMA}`;".encode() in transformed
    assert b"USE `wpcaregrid`;" not in transformed
    assert b"SET SQL_MODE=@OLD_SQL_MODE" not in transformed
    assert b"SET GLOBAL" in transformed  # occurs only inside a SQL string literal


def test_only_ordered_standard_mysqldump_mode_footer_is_removed():
    save = b"/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"
    footer = b"/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n"
    reordered = SAFE_DUMP.replace(save, b"").replace(footer, b"") + footer + save
    expect_refusal(reordered, "SQL_LEXICAL_MODE_UNSUPPORTED")
    duplicate_footer = SAFE_DUMP + footer
    expect_refusal(duplicate_footer, "SQL_LEXICAL_MODE_UNSUPPORTED")
    duplicate_save = SAFE_DUMP.replace(save, save + save)
    expect_refusal(duplicate_save, "SQL_LEXICAL_MODE_UNSUPPORTED")
    nonstandard_footer = SAFE_DUMP.replace(footer, b"/*!40101 SET SQL_MODE=CONCAT(@OLD_SQL_MODE,',ANSI_QUOTES') */;\n")
    expect_refusal(nonstandard_footer, "SQL_LEXICAL_MODE_UNSUPPORTED")


def test_selector_and_schema_rejections():
    expect_refusal(SAFE_DUMP.replace(b"USE `wpcaregrid`;", b"USE `other_schema`;"), "DATABASE_SELECTOR_SOURCE_MISMATCH")
    expect_refusal(SAFE_DUMP.replace(b"USE `wpcaregrid`;", b"USE `wpcaregrid`;\nUSE `other_schema`;"), "DATABASE_SELECTOR_COUNT_MISMATCH")
    expect_refusal(SAFE_DUMP.replace(b"CREATE DATABASE", b"CREATE DATABASE /*!32312 IF NOT EXISTS*/ `extra` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;\nCREATE DATABASE", 1), "DATABASE_SELECTOR_COUNT_MISMATCH")
    expect_refusal(SAFE_DUMP.replace(b"INSERT INTO `synthetic`", b"INSERT INTO `other_schema`.`synthetic`"), "CROSS_SCHEMA_REFERENCE")
    expect_refusal(SAFE_DUMP.replace(b"CREATE DATABASE", b"DROP DATABASE `wpcaregrid`;\nCREATE DATABASE", 1), "DUMP_UNSAFE_STATEMENT_DATABASE_DROP")
    lines = SAFE_DUMP.splitlines(keepends=True)
    lines[1] = b"CREATE DATABASE `other` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;\n"
    expect_refusal(b"".join(lines), "DATABASE_CREATE_FORMAT_UNEXPECTED")


def test_global_replication_and_external_side_effects_rejected():
    cases = (
        (b"/*!50000 SET GLOBAL log_bin_trust_function_creators=1 */;", "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING"),
        (b"SET @@GLOBAL.gtid_purged='x';", "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING"),
        (b"SET PERSIST sql_mode='';", "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING"),
        (b"SET @local_value=1, @@GLOBAL.log_bin_trust_function_creators=1;", "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING"),
        (b"SET @local_value=1, @@PERSIST.sql_mode='';", "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING"),
        (b"SET @local_value=1, @@PERSIST_ONLY.sql_mode='';", "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING"),
        (b"SET SESSION sql_mode='ANSI_QUOTES';", "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (b"SET SESSION sql_mode='NO_BACKSLASH_ESCAPES';", "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (b"SET SESSION sql_mode:='ANSI_QUOTES';", "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (b"SET SESSION SQL_MODE /* comment */ = 'ANSI_QUOTES';", "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (b"SET SESSION sql_mode=CONCAT(@@SESSION.sql_mode,',ANSI_QUOTES');", "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (b"SET @OLD_SQL_MODE='ANSI_QUOTES'; SET SQL_MODE=@OLD_SQL_MODE;", "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (b"SET @OLD_SQL_MODE:='ANSI_QUOTES'; SET SQL_MODE=@OLD_SQL_MODE;", "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (b"SET @@SESSION.GTID_NEXT='AUTOMATIC';", "DUMP_UNSAFE_STATEMENT_GTID_STATE"),
        (b"SET PASSWORD FOR 'x'@'localhost'='x';", "DUMP_UNSAFE_STATEMENT_PRIVILEGED_OR_SERVER_OPERATION"),
        (b"CREATE RESOURCE GROUP `rg` TYPE=USER VCPU=0-1;", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OBJECT"),
        (b"CREATE UNDO TABLESPACE `undo_ts` ADD DATAFILE 'undo.ibu';", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OBJECT"),
        (b"ALTER UNDO TABLESPACE `undo_ts` SET INACTIVE;", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OBJECT"),
        (b"DROP UNDO TABLESPACE `undo_ts` ENGINE=INNODB;", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OBJECT"),
        (b"RENAME USER 'old'@'localhost' TO 'new'@'localhost';", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OBJECT"),
        (b"CREATE AGGREGATE FUNCTION `udf` RETURNS INTEGER SONAME 'udf.so';", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OBJECT"),
        (b"DROP SPATIAL REFERENCE SYSTEM 4326;", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OBJECT"),
        (b"ALTER SPATIAL REFERENCE SYSTEM 4326 NAME 'x';", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OBJECT"),
        (b"CREATE TEMPORARY TABLE `tmp` (`id` int);", "DUMP_UNSAFE_STATEMENT_TEMPORARY_TABLE_UNSUPPORTED"),
        (b"CHANGE REPLICATION SOURCE TO SOURCE_HOST='x';", "DUMP_UNSAFE_STATEMENT_REPLICATION_CONFIGURATION"),
        (b"START REPLICA;", "DUMP_UNSAFE_STATEMENT_REPLICATION_CONFIGURATION"),
        (b"CALL mysql.rds_set_configuration('x','y');", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OPERATION"),
        (b"CLONE INSTANCE FROM 'x'@'localhost':3306 IDENTIFIED BY 'x';", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OPERATION"),
        (b"DO SLEEP(1);", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OPERATION"),
        (b"FLUSH PRIVILEGES;", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OPERATION"),
        (b"RESTART;", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OPERATION"),
        (b"LOCK INSTANCE FOR BACKUP;", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OPERATION"),
        (b"ALTER TABLE `synthetic` IMPORT TABLESPACE;", "DUMP_UNSAFE_STATEMENT_EXTERNAL_FILE_OPERATION"),
        (b"RESET MASTER;", "DUMP_UNSAFE_STATEMENT_REPLICATION_OR_GLOBAL_STATE"),
        (b"RESET /* comment */ BINARY LOGS AND GTIDS;", "DUMP_UNSAFE_STATEMENT_REPLICATION_OR_GLOBAL_STATE"),
        (b"SET @@GLOBAL.GTID_PURGED='x';", "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING"),
        (b"ALTER DATABASE `wpcaregrid` READ ONLY = 1;", "DUMP_UNSAFE_STATEMENT_DATABASE_SELECTOR_UNEXPECTED"),
        (b"SET SESSION sql_log_bin=0;", "DUMP_UNSAFE_STATEMENT_REPLICATION_SESSION_SETTING"),
        (b"LOAD DATA LOCAL INFILE '/tmp/input' INTO TABLE `synthetic`;", "DUMP_UNSAFE_STATEMENT_EXTERNAL_FILE_OPERATION"),
        (b"SELECT 1 INTO OUTFILE '/tmp/output';", "DUMP_UNSAFE_STATEMENT_EXTERNAL_FILE_OPERATION"),
        (b"CREATE EVENT `e` ON SCHEDULE EVERY 1 DAY DO SELECT 1;", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OBJECT"),
        (b"CREATE DEFINER=`root`@`localhost` TRIGGER `t` BEFORE INSERT ON `synthetic` FOR EACH ROW SET NEW.`id`=1;", "DUMP_UNSAFE_STATEMENT_DEFINER_UNSUPPORTED"),
        (b"GRANT ALL ON *.* TO 'x'@'localhost';", "DUMP_UNSAFE_STATEMENT_PRIVILEGED_OR_SERVER_OPERATION"),
        (b"SELECT LOAD_FILE('/etc/passwd');", "DUMP_UNSAFE_STATEMENT_EXTERNAL_FILE_OPERATION"),
    )
    for statement, code in cases:
        expect_refusal(SAFE_DUMP + statement + b"\n", code)
    no_saved_mode = SAFE_DUMP.replace(b"/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n", b"")
    expect_refusal(no_saved_mode + b"SET SQL_MODE=@OLD_SQL_MODE;\n", "SQL_LEXICAL_MODE_UNSUPPORTED")


def test_comments_and_quoted_data_are_not_sql_directives():
    noisy = SAFE_DUMP + b"-- USE `other_schema`; SET GLOBAL x=1;\nINSERT INTO `synthetic` VALUES ('USE `other`;');\n"
    assert RESTORE.audit_sql(noisy, SCHEMA)["schema_references"] == 0
    expect_refusal(SAFE_DUMP + b"\\! id\n", "MYSQL_CLIENT_COMMAND_UNSUPPORTED")
    expect_refusal(SAFE_DUMP + b"SOURCE /tmp/extra.sql\n", "MYSQL_CLIENT_COMMAND_UNSUPPORTED")
    expect_refusal(SAFE_DUMP + b"SYSTEM id\n", "MYSQL_CLIENT_COMMAND_UNSUPPORTED")
    expect_refusal(SAFE_DUMP + b"/*! \\d // */\n", "MYSQL_CLIENT_COMMAND_UNSUPPORTED")
    expect_refusal(SAFE_DUMP + b"/*! \\C latin1 */\n", "MYSQL_CLIENT_COMMAND_UNSUPPORTED")
    expect_refusal(SAFE_DUMP + b"/*+ \\d // */\n", "MYSQL_CLIENT_COMMAND_UNSUPPORTED")
    expect_refusal(SAFE_DUMP + b"/*+ \\C latin1 */\n", "MYSQL_CLIENT_COMMAND_UNSUPPORTED")
    expect_refusal(SAFE_DUMP + b"DELIMITER //\n", "MYSQL_DELIMITER_UNBALANCED")
    expect_refusal(SAFE_DUMP + b"DELIMITER @@\n", "MYSQL_DELIMITER_UNSUPPORTED")
    for command in (b"CONNECT localhost", b"TEE /root/out", b"PAGER /bin/sh", b"CHARSET utf8mb4", b"QUIT"):
        expect_refusal(SAFE_DUMP + command + b"\n", "MYSQL_CLIENT_COMMAND_UNSUPPORTED")


def test_target_name_and_dump_format_fail_closed():
    for target in ("wpcaregrid", "wpcg_recovery_restore_20261104_bad!", "wpcg_recovery_restore_20261104_ABCDEF12"):
        try:
            RESTORE.audit_sql(SAFE_DUMP, target)
        except RESTORE.RestoreRefused as error:
            assert str(error) == "TARGET_SCHEMA_INVALID"
        else:
            raise AssertionError("Invalid target schema accepted")
    expect_refusal(SAFE_DUMP.replace(b"COLLATE utf8mb4_unicode_ci", b"COLLATE utf8mb4_unicode_ci; SET GLOBAL x=1"), "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING")
    expect_refusal(SAFE_DUMP.replace(b"DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", b"DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; SELECT 1"), "DATABASE_CREATE_FORMAT_UNEXPECTED")
    expect_refusal(SAFE_DUMP.replace(b"USE `wpcaregrid`;", b"USE `wpcaregrid`"), "DATABASE_USE_FORMAT_UNEXPECTED")
    expect_refusal(SAFE_DUMP + b"/* unterminated", "DUMP_COMMENT_UNTERMINATED")
    expect_refusal(SAFE_DUMP.replace(b"CREATE TABLE `migrations`", b"CREATE TABLE `other`"), "EXPECTED_TABLE_SET_INVALID")


def test_sql_resource_budgets_fail_closed():
    expect_refusal(SAFE_DUMP + b"," * (RESTORE.MAX_SQL_TOKENS + 1), "DUMP_TOKEN_LIMIT")
    expect_refusal(SAFE_DUMP + b"\n" * (RESTORE.MAX_SQL_LINES + 1), "DUMP_LINE_LIMIT")
    expect_refusal(SAFE_DUMP + b"\r" * (RESTORE.MAX_SQL_LINES + 1), "DUMP_LINE_LIMIT")


def test_backup_hash_gzip_and_metadata_checks():
    with tempfile.TemporaryDirectory() as directory:
        backup = Path(directory) / "synthetic.sql.gz"
        payload = gzip.compress(SAFE_DUMP)
        backup.write_bytes(payload)
        os.chmod(backup, 0o600)
        expected = hashlib.sha256(payload).hexdigest()
        assert RESTORE.decode_verified_archive(payload, expected) == SAFE_DUMP
        try:
            RESTORE.decode_verified_archive(payload, "0" * 64)
        except RESTORE.RestoreRefused as error:
            assert str(error) == "BACKUP_HASH_MISMATCH"
        else:
            raise AssertionError("Backup hash mismatch accepted")
        backup.write_bytes(b"not gzip")
        bad_hash = hashlib.sha256(b"not gzip").hexdigest()
        try:
            RESTORE.decode_verified_archive(b"not gzip", bad_hash)
        except RESTORE.RestoreRefused as error:
            assert str(error) == "BACKUP_GZIP_INVALID"
        else:
            raise AssertionError("Malformed gzip accepted")
    good = SimpleNamespace(st_mode=stat.S_IFREG | 0o600, st_uid=1001, st_gid=1001)
    assert RESTORE.backup_metadata_valid(good, owner_uid=1001, group_gid=1001)
    assert not RESTORE.backup_metadata_valid(SimpleNamespace(st_mode=stat.S_IFLNK | 0o600, st_uid=1001, st_gid=1001), owner_uid=1001, group_gid=1001)
    assert not RESTORE.backup_metadata_valid(SimpleNamespace(st_mode=stat.S_IFREG | 0o644, st_uid=1001, st_gid=1001), owner_uid=1001, group_gid=1001)


def test_archive_limit_and_trailing_members_fail_closed():
    payload = gzip.compress(SAFE_DUMP)
    expected = hashlib.sha256(payload).hexdigest()
    assert RESTORE.decode_verified_archive(payload, expected) == SAFE_DUMP
    concatenated = payload + gzip.compress(b"extra")
    try:
        RESTORE.decode_verified_archive(concatenated, hashlib.sha256(concatenated).hexdigest())
    except RESTORE.RestoreRefused as error:
        assert str(error) == "DUMP_TRAILING_COMPRESSED_DATA"
    else:
        raise AssertionError("Concatenated gzip member accepted")


def test_mysql_connection_is_explicit_and_ignores_option_files():
    argv = RESTORE.mysql_argv(["--execute", "SELECT 1"], "/run/mysqld/mysqld.sock")
    assert argv[0] == RESTORE.MYSQL
    assert argv[1] == "--no-defaults"
    assert argv[2] == "--no-login-paths"
    assert "--binary-mode" in argv
    assert "--init-command=SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'" in argv
    assert "--protocol=socket" in argv
    assert "--socket=/run/mysqld/mysqld.sock" in argv
    try:
        RESTORE.mysql_argv(["--execute", "SELECT 1"], None)
    except RESTORE.RestoreRefused as error:
        assert str(error) == "MYSQL_SOCKET_REQUIRED"
    else:
        raise AssertionError("Unpinned MySQL socket accepted")


def test_mysql_peer_must_be_the_systemd_main_process():
    assert RESTORE.mysql_peer_identity_valid(42, 42, "/usr/sbin/mysqld", "0::/system.slice/mysql.service\n")
    assert not RESTORE.mysql_peer_identity_valid(43, 42, "/usr/sbin/mysqld", "0::/system.slice/mysql.service\n")
    assert not RESTORE.mysql_peer_identity_valid(42, 42, "/tmp/fake-mysqld", "0::/system.slice/mysql.service\n")
    assert not RESTORE.mysql_peer_identity_valid(42, 42, "/usr/sbin/mysqld", "0::/user.slice/user-1000.slice\n")


def test_mysql_proxy_round_trip_isolation():
    if not hasattr(socket, "AF_UNIX"):
        assert sys.platform == "win32"
        print("MYSQL_PROXY_ROUNDTRIP=NOT_SUPPORTED_WINDOWS")
        return
    with tempfile.TemporaryDirectory() as directory:
        os.chmod(directory, 0o700)
        peer_path = str(Path(directory) / "peer.sock")
        peer = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        peer.bind(peer_path)
        peer.listen(1)
        received = []

        def serve_peer():
            connection, _ = peer.accept()
            with connection:
                connection.settimeout(5)
                received.append(connection.recv(32))
                connection.sendall(b"reply")

        server_thread = threading.Thread(target=serve_peer)
        server_thread.start()
        previous_argv = RESTORE.mysql_argv
        previous_verifier = RESTORE.verify_mysql_peer
        previous_path_validator = RESTORE.validate_socket_path
        RESTORE.mysql_argv = lambda _args, proxy_path: [
            sys.executable, "-c",
            "import socket,sys; s=socket.socket(socket.AF_UNIX); s.connect(sys.argv[1]); "
            "s.sendall(b'probe'); print(s.recv(32).decode('ascii')); s.close()",
            proxy_path,
        ]
        RESTORE.verify_mysql_peer = lambda _connection: None
        # This test exercises proxy relay only; its /tmp socket is intentionally
        # not a valid production MySQL endpoint path.
        RESTORE.validate_socket_path = lambda _path: None
        try:
            result = RESTORE._mysql([], peer_path, proxy_root=directory)
        finally:
            RESTORE.mysql_argv = previous_argv
            RESTORE.verify_mysql_peer = previous_verifier
            RESTORE.validate_socket_path = previous_path_validator
            peer.close()
        server_thread.join(timeout=5)
        assert not server_thread.is_alive()
        assert received == [b"probe"]
        assert result.returncode == 0
        assert result.stdout.strip() == b"reply"


def main():
    tests = (
        test_safe_dump_is_transformed_narrowly,
        test_only_ordered_standard_mysqldump_mode_footer_is_removed,
        test_selector_and_schema_rejections,
        test_global_replication_and_external_side_effects_rejected,
        test_comments_and_quoted_data_are_not_sql_directives,
        test_target_name_and_dump_format_fail_closed,
        test_sql_resource_budgets_fail_closed,
        test_backup_hash_gzip_and_metadata_checks,
        test_archive_limit_and_trailing_members_fail_closed,
        test_mysql_connection_is_explicit_and_ignores_option_files,
        test_mysql_peer_must_be_the_systemd_main_process,
        test_mysql_proxy_round_trip_isolation,
    )
    for test in tests:
        test()
    print(f"ISOLATED_STAGING_RESTORE_TESTS={len(tests)}/PASS")


if __name__ == "__main__":
    main()
