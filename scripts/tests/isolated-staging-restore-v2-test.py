#!/usr/bin/env python3
"""Synthetic-only tests for the pinned staging dump restore preflight."""

import gzip
import hashlib
import importlib.util
import contextlib
import io
import inspect
import tempfile
import os
import stat
import sys
import datetime
import json
from unittest import mock
from types import SimpleNamespace
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("isolated_staging_restore", ROOT / "scripts/isolated-staging-restore-v2.py")
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


def test_standard_mysqldump_trigger_session_wrapper_is_audited_and_removed():
    trigger_block = b"""
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
/*!50003 CREATE*/ /*!50017 DEFINER=`wpcg_dba`@`localhost`*/ /*!50003 TRIGGER `synthetic_bi` BEFORE INSERT ON `synthetic` FOR EACH ROW SET NEW.`id`=COALESCE(NEW.`id`,1) */;;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DELIMITER ;
"""
    dump = SAFE_DUMP.replace(
        b"CREATE TRIGGER `synthetic_bi` BEFORE INSERT ON `synthetic` FOR EACH ROW SET NEW.`id`=COALESCE(NEW.`id`,1);;\nDELIMITER ;\n",
        trigger_block,
    )
    plan = RESTORE.audit_sql(dump, SCHEMA, approved_definer="wpcg_dba@localhost")
    transformed = plan["transformed_sql"]
    assert b"@saved_cs_client" not in transformed
    assert b"@saved_sql_mode" not in transformed
    assert b"SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO'" in transformed
    assert b"DEFINER=`wpcg_dba`@`localhost`" in transformed
    unpaired = dump.replace(b"/*!50003 SET collation_connection  = @saved_col_connection */ ;\n", b"")
    expect_refusal(unpaired, "MYSQLDUMP_TRIGGER_SESSION_WRAPPER_UNBALANCED")


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
        (b"SET NAMES gbk;", "DUMP_UNSAFE_STATEMENT_MYSQL_CHARSET_CHANGE_UNSUPPORTED"),
        (b"SET NAMES utf8mb4;", "DUMP_UNSAFE_STATEMENT_MYSQL_CHARSET_CHANGE_UNSUPPORTED"),
        (b"SET CHARACTER SET gbk;", "DUMP_UNSAFE_STATEMENT_MYSQL_CHARSET_CHANGE_UNSUPPORTED"),
        (b"SET CHARSET gbk;", "DUMP_UNSAFE_STATEMENT_MYSQL_CHARSET_CHANGE_UNSUPPORTED"),
        (b"/*!50000 SET CHARACTER SET gbk */;", "DUMP_UNSAFE_STATEMENT_MYSQL_CHARSET_CHANGE_UNSUPPORTED"),
        (b"SET character_set_client='gbk';", "DUMP_UNSAFE_STATEMENT_MYSQL_CHARSET_CHANGE_UNSUPPORTED"),
        (b"SET @@character_set_client='gbk';", "DUMP_UNSAFE_STATEMENT_MYSQL_CHARSET_CHANGE_UNSUPPORTED"),
        (b"SET @@SESSION.character_set_client='gbk';", "DUMP_UNSAFE_STATEMENT_MYSQL_CHARSET_CHANGE_UNSUPPORTED"),
        (b"SET @@character_set_client := gbk;", "DUMP_UNSAFE_STATEMENT_MYSQL_CHARSET_CHANGE_UNSUPPORTED"),
        (b"SET CHARACTER SET gbk; INSERT INTO `synthetic` VALUES ('\\xe4\\xb8\\xad\\\\'); GRANT ALL ON *.* TO x@y; -- (');",
         "DUMP_UNSAFE_STATEMENT_MYSQL_CHARSET_CHANGE_UNSUPPORTED"),
        (b"INSERT INTO `synthetic` VALUES ('x'); \\C gbk\n", "MYSQL_CLIENT_COMMAND_UNSUPPORTED"),
        (b"/*!50000 SET GLOBAL log_bin_trust_function_creators=1 */;", "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING"),
        (b"SET @@GLOBAL.gtid_purged='x';", "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING"),
        (b"SET PERSIST sql_mode='';", "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING"),
        (b"SET @local_value=1, @@GLOBAL.log_bin_trust_function_creators=1;", "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING"),
        (b"SET @local_value=1, @@PERSIST.sql_mode='';", "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING"),
        (b"SET @local_value=1, @@PERSIST_ONLY.sql_mode='';", "DUMP_UNSAFE_STATEMENT_GLOBAL_SETTING"),
        (b"SET SESSION sql_mode='ANSI_QUOTES';", "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (b"SET SESSION sql_mode='NO_BACKSLASH_ESCAPES';", "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (b"SET `sql_mode`='NO_BACKSLASH_ESCAPES';", "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (b"SET @@session.`sql_mode`='NO_BACKSLASH_ESCAPES,ANSI_QUOTES';", "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (b"SET `sql_mode`='NO_BACKSLASH_ESCAPES'; INSERT INTO `synthetic` VALUES ('x\\\\'); GRANT ALL ON *.* TO x@y; -- (');",
         "DUMP_UNSAFE_STATEMENT_PRIVILEGED_OR_SERVER_OPERATION"),
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
        (b"CREATE VIEW `v` AS SELECT 1;", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OBJECT"),
        (b"CREATE OR REPLACE VIEW `v` AS SELECT 1;", "DUMP_UNSAFE_STATEMENT_UNEXPECTED_SERVER_OBJECT"),
        (b"GRANT ALL ON *.* TO 'x'@'localhost';", "DUMP_UNSAFE_STATEMENT_PRIVILEGED_OR_SERVER_OPERATION"),
        (b"SELECT LOAD_FILE('/etc/passwd');", "DUMP_UNSAFE_STATEMENT_EXTERNAL_FILE_OPERATION"),
    )
    for statement, code in cases:
        expect_refusal(SAFE_DUMP + statement + b"\n", code)
    no_saved_mode = SAFE_DUMP.replace(b"/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n", b"")
    expect_refusal(no_saved_mode + b"SET SQL_MODE=@OLD_SQL_MODE;\n", "SQL_LEXICAL_MODE_UNSUPPORTED")


def test_definer_requires_exact_explicit_pin():
    statement = b"CREATE DEFINER=`restore_owner`@`localhost` TRIGGER `t` BEFORE INSERT ON `synthetic` FOR EACH ROW SET NEW.`id`=1;"
    expect_refusal(SAFE_DUMP + statement + b"\n", "DUMP_UNSAFE_STATEMENT_DEFINER_UNSUPPORTED")
    approved = RESTORE.audit_sql(SAFE_DUMP + statement + b"\n", SCHEMA, "restore_owner@localhost")
    assert "t" in approved["expected_triggers"]
    for identity in ("other@localhost", "restore_owner@%", "", None):
        try:
            RESTORE.audit_sql(SAFE_DUMP + statement + b"\n", SCHEMA, identity)
        except RESTORE.RestoreRefused as error:
            assert str(error) == "DUMP_UNSAFE_STATEMENT_DEFINER_UNSUPPORTED"
        else:
            raise AssertionError("Unpinned DEFINER accepted")


def test_successor_pins_and_receipt_contract_are_separate_from_legacy():
    assert RESTORE.BACKUP_ID == "wpcaregrid-pre-deploy-20261104T133855Z"
    assert RESTORE.BACKUP_SHA256 == "5a2cce05c8a0cc44cfa76be3619f86bcdb748793dd6fed098fd8ef349b02c431"
    assert RESTORE.APPLICATION_SHA == "ee3d29818ff1647d82087446dfaf017ca688cb77"
    import inspect
    params = inspect.signature(RESTORE.restore).parameters
    assert list(params) == ["manifest_path", "policy_path", "config_path", "receipt_path"]
    argv = RESTORE._mysql_argv({"mysql": {"host": "wpcg_restore_0123456789", "port": 3306,
        "defaults_file": "/tmp/mysql.cnf", "client": "/usr/bin/mysql",
        "dump_client": "/usr/bin/mysqldump"}}, database="wpcaregrid", dump=True)
    assert "--connect-timeout=5" not in argv  # mysqldump does not implement this mysql-client option


def test_admission_tuple_requires_every_exact_pin_and_matching_fingerprint():
    source = {"environment_id": "source-env", "host_id": "source-host", "database_id": "source-db",
              "release_sha": "a" * 40}
    destination = {"environment_id": "recovery-env", "host_id": "isolated-host",
                   "instance_uuid": "123e4567-e89b-42d3-a456-426614174000",
                   "approved_isolation_policy": "approved-isolated-recovery:policy-1"}
    assert RESTORE.validate_identity_bindings(source, destination)
    variants = []
    missing_source = dict(source); missing_source.pop("database_id")
    variants.append((missing_source, destination, "SOURCE_IDENTITY_EVIDENCE_INVALID"))
    bad_sha = dict(source); bad_sha["release_sha"] = "not-a-release"
    variants.append((bad_sha, destination, "SOURCE_RELEASE_SHA_INVALID"))
    same_host = dict(destination); same_host["host_id"] = source["host_id"]
    variants.append((source, same_host, "DESTINATION_NOT_ISOLATED"))
    same_environment = dict(destination); same_environment["environment_id"] = source["environment_id"]
    variants.append((source, same_environment, "DESTINATION_NOT_ISOLATED"))
    malformed_uuid = dict(destination); malformed_uuid["instance_uuid"] = "destination-uuid"
    variants.append((source, malformed_uuid, "DESTINATION_INSTANCE_UUID_INVALID"))
    unapproved = dict(destination); unapproved["approved_isolation_policy"] = "self-declared"
    variants.append((source, unapproved, "DESTINATION_POLICY_NOT_APPROVED"))
    missing_policy = dict(destination); missing_policy.pop("approved_isolation_policy")
    variants.append((source, missing_policy, "DESTINATION_IDENTITY_EVIDENCE_INVALID"))
    for candidate_source, candidate_destination, code in variants:
        try:
            RESTORE.validate_identity_bindings(candidate_source, candidate_destination)
        except RESTORE.RestoreRefused as error:
            assert str(error) == code
        else:
            raise AssertionError("Invalid identity evidence accepted")


def test_no_release_manifest_means_historical_only():
    receipt = RESTORE.historical_receipt(
        backup_sha=RESTORE.BACKUP_SHA256, application_sha=RESTORE.APPLICATION_SHA,
        recovery_tool_sha=RESTORE.RECOVERY_TOOL_SHA, started_at="2026-11-15T00:00:00+00:00",
        completed_at="2026-11-15T00:00:01+00:00", archive_integrity_verified=True)
    assert receipt["classification"] == "historical_non_release_bound"
    assert receipt["release_bound"] is False
    assert receipt["backup_date"] == "2026-11-04"
    for name in ("complete_trigger_definition_and_definer_verified", "exact_data_integrity_verified",
                 "protected_config_and_app_key_recovery_verified", "release_manifest_verified",
                 "source_identity_matches_protected_manifest",
                 "destination_mysql_instance_matches_protected_policy",
                 "destination_network_isolation_attested_by_root_policy"):
        assert receipt["assertions"][name] is False
    assert "classification" not in inspect.signature(RESTORE.historical_receipt).parameters
    assert "source_config_fingerprint" not in inspect.signature(RESTORE.main).parameters


def _write_json(path, value):
    path.write_bytes(json.dumps(value, sort_keys=True, separators=(",", ":")).encode() + b"\n")
    os.chmod(path, 0o600)
    return hashlib.sha256(path.read_bytes()).hexdigest()


def _release_fixture(directory):
    directory = Path(directory)
    schema = "wpcg_recovery_restore_20261115_a1b2c3d4"
    approved_definer = "restore_owner@localhost"
    archive = gzip.compress(SAFE_DUMP)
    backup_path = directory / "backup.sql.gz"
    backup_path.write_bytes(archive)
    os.chmod(backup_path, 0o600)
    config_path = directory / "recovered.env"
    config_path.write_bytes(b"APP_KEY=base64:synthetic-test-key\nAPP_ENV=production\n")
    os.chmod(config_path, 0o600)
    defaults_path = directory / "mysql.cnf"
    defaults_path.write_text("[client]\nuser=restore\n", encoding="ascii")
    os.chmod(defaults_path, 0o600)
    config_sha, key_sha = RESTORE._config_identity(config_path, owner_uid=os.geteuid())
    source_sha = "d" * 40
    server_uuid = "123e4567-e89b-42d3-a456-426614174000"
    policy = {
        "schema_version": 1, "policy_id": "synthetic-policy-v1",
        "destination_identity": {"environment_id": "recovery-env", "host_id": "isolated-host"},
        "mysql": {"host": "wpcg_restore_0123456789", "port": 3306,
                  "server_uuid": server_uuid, "defaults_file": str(defaults_path),
                  "client": "/usr/bin/mysql", "dump_client": "/usr/bin/mysqldump"},
        "isolation": {"approved": True, "kind": "disposable_mysql_container",
                      "network_id": "wpcg_restore_net_0123456789", "public_ports": False},
    }
    policy_path = directory / "destination-policy.json"
    policy_sha = _write_json(policy_path, policy)
    plan = RESTORE.audit_sql(SAFE_DUMP, schema, approved_definer)
    trigger = {"name": "synthetic_bi", "table": "synthetic", "event": "INSERT",
               "timing": "BEFORE", "orientation": "ROW", "definer": approved_definer,
               "action_sha256": "e" * 64}
    now = datetime.datetime.now(datetime.timezone.utc)
    manifest = {
        "schema_version": 1, "manifest_id": "synthetic-manifest-v1",
        "owner_authorization_id": "synthetic-owner-approval",
        "release": {"application_sha": source_sha, "base_sha": "b" * 40, "artifact_sha256": "a" * 64},
        "backup": {"id": "synthetic-backup-v1", "path": str(backup_path),
                   "sha256": hashlib.sha256(archive).hexdigest(), "size_bytes": len(archive),
                   "source_schema": "wpcaregrid"},
        "source_identity": {"environment_id": "source-env", "host_id": "source-host",
                             "database_id": "source-db", "server_uuid": "source-server-uuid",
                             "database": "wpcaregrid", "application_sha": source_sha},
        "destination_policy_sha256": policy_sha, "target_schema": schema,
        "database_charset": plan["charset"], "database_collation": plan["collation"],
        "mysql": {"version": "8.4.11", "server_uuid": server_uuid, "log_bin": True,
                  "binlog_format": "ROW", "trust_function_creators": False},
        "approved_definer": approved_definer,
        "expected": {"table_names": plan["expected_tables"],
                     "table_row_counts": {"migrations": 0, "synthetic": 1},
                     "schema_sha256": "f" * 64, "ledger_sha256": "1" * 64,
                     "data_sha256": "2" * 64, "triggers": [trigger],
                     "definer_select_tables": ["migrations"],
                     "migrations": ["2026_11_02_000001_create_deployment_transition_fences_table"]},
        "config": {"identity_sha256": config_sha, "app_key_fingerprint_sha256": key_sha},
        "issued_at": now.isoformat(),
        "expires_at": (now + datetime.timedelta(hours=1)).isoformat(),
    }
    # The empty migration list is a valid restored pre-migration snapshot.
    manifest_path = directory / "release-manifest.json"
    _write_json(manifest_path, manifest)
    return manifest_path, policy_path, config_path, manifest


def test_manifest_and_destination_policy_are_protected_and_bound():
    with tempfile.TemporaryDirectory() as directory:
        manifest_path, policy_path, _config_path, manifest = _release_fixture(directory)
        checked, manifest_sha, _policy, policy_sha = RESTORE.verify_manifest_and_policy(
            manifest_path, policy_path, owner_uid=os.geteuid())
        assert checked["manifest_id"] == manifest["manifest_id"]
        assert manifest_sha == hashlib.sha256(manifest_path.read_bytes()).hexdigest()
        assert policy_sha == manifest["destination_policy_sha256"]
        bad_policy = json.loads(policy_path.read_text())
        bad_policy["isolation"]["public_ports"] = True
        bad_policy_path = Path(directory) / "bad-policy.json"
        _write_json(bad_policy_path, bad_policy)
        try:
            RESTORE._policy_object(bad_policy)
        except RESTORE.RestoreRefused as error:
            assert str(error) == "DESTINATION_POLICY_NOT_ISOLATED"
        else:
            raise AssertionError("Publicly exposed destination accepted")
        for mutate, expected_code in (
                (lambda m: m["source_identity"].update(application_sha="c" * 40), "SOURCE_RELEASE_BINDING_MISMATCH"),
                (lambda m: m.update(approved_definer="root@localhost"), "DEFINER_ALLOWLIST_INVALID"),
                (lambda m: m.update(expires_at="2020-01-01T00:00:00+00:00"), "RELEASE_MANIFEST_EXPIRED_OR_INVALID")):
            invalid = json.loads(manifest_path.read_text())
            mutate(invalid)
            try:
                RESTORE._manifest_object(invalid)
            except RESTORE.RestoreRefused as error:
                assert str(error) == expected_code
            else:
                raise AssertionError(f"Invalid manifest accepted: {expected_code}")


def test_release_bound_restore_and_cleanup_pipeline_with_synthetic_mysql():
    with tempfile.TemporaryDirectory() as directory:
        manifest_path, policy_path, config_path, manifest = _release_fixture(directory)
        receipt_path = Path(directory) / "restore-receipt.json"
        observed = {"schema_sha256": "f" * 64, "schema_components": {},
                    "ledger_sha256": "1" * 64, "data_sha256": "2" * 64, "data_bytes": 123,
                    "table_row_counts": {"migrations": 0, "synthetic": 1},
        "migrations": manifest["expected"]["migrations"], "triggers": manifest["expected"]["triggers"]}
        with (mock.patch.object(RESTORE, "_mysql_identity", return_value={
                    "version": "8.4.11", "server_uuid": manifest["mysql"]["server_uuid"],
                    "log_bin": "1", "binlog_format": "ROW", "trust_function_creators": "0"}) as mysql_identity,
              mock.patch.object(RESTORE, "mysql_query", return_value=b"0\n"),
              mock.patch.object(RESTORE, "_create_database") as create_db,
              mock.patch.object(RESTORE, "verify_definer_lifecycle", return_value={
                  "account_exists": True, "account_locked": True, "global_privileges": ["USAGE"],
                  "schema_privileges": ["TRIGGER"], "select_tables": ["migrations"],
                  "grants_sha256": "3" * 64}) as verify_definer,
              mock.patch.object(RESTORE, "_run_mysql_import") as import_dump,
              mock.patch.object(RESTORE, "_inventory", return_value=observed),
              mock.patch.object(RESTORE, "_drop_database") as drop_db):
            receipt = RESTORE._restore_impl(manifest_path, policy_path, config_path, receipt_path,
                                            owner_uid=os.geteuid())
            assert receipt["restore"] == "PASS"
            assert receipt["release_bound"] is True
            assert receipt["assertions"]["definer_account_locked_with_exact_minimal_grants_verified"] is True
            assert receipt["backup"]["id"] == "synthetic-backup-v1"
            create_db.assert_called_once()
            import_dump.assert_called_once()
            assert verify_definer.call_count == 2
            assert mysql_identity.call_count == 2
            assert receipt["assertions"]["destination_network_isolation_attested_by_root_policy"] is True
            assert "destination_instance_bound_and_isolated" not in receipt["assertions"]
            pending_path = receipt_path.with_suffix(".pending.json")
            assert pending_path.exists()
            cleanup = RESTORE._cleanup_restore_impl(manifest_path, policy_path, receipt_path,
                                                    owner_uid=os.geteuid())
            assert cleanup["cleanup"] == "PASS"
            assert cleanup["restore_receipt_sha256"] == hashlib.sha256(receipt_path.read_bytes()).hexdigest()
            drop_db.assert_called_once_with(mock.ANY, manifest["target_schema"])
            assert not pending_path.exists()
            assert receipt_path.with_suffix(".cleanup.json").exists()


def test_existing_receipt_blocks_before_any_database_access():
    with tempfile.TemporaryDirectory() as directory:
        manifest_path, policy_path, config_path, _manifest = _release_fixture(directory)
        receipt_path = Path(directory) / "restore-receipt.json"
        receipt_path.write_text("preserve", encoding="ascii")
        with mock.patch.object(RESTORE, "_mysql_identity", side_effect=AssertionError("DB reached")):
            try:
                RESTORE._restore_impl(manifest_path, policy_path, config_path, receipt_path,
                                      owner_uid=os.geteuid())
            except RESTORE.RestoreRefused as error:
                assert str(error) == "RECEIPT_ALREADY_EXISTS"
            else:
                raise AssertionError("Existing receipt was overwritten")
        assert receipt_path.read_text(encoding="ascii") == "preserve"


def test_journal_commit_failure_cannot_leave_a_pass_receipt():
    with tempfile.TemporaryDirectory() as directory:
        manifest_path, policy_path, config_path, manifest = _release_fixture(directory)
        receipt_path = Path(directory) / "restore-receipt.json"
        journal_path = receipt_path.with_suffix(".pending.json")
        original_atomic_json = RESTORE._atomic_json

        def fail_final_journal(path, value, **kwargs):
            if Path(path) == journal_path and value.get("state") == "RESTORE_VERIFIED":
                raise RESTORE.RestoreRefused("INJECTED_JOURNAL_FAILURE")
            return original_atomic_json(path, value, **kwargs)

        observed = {"schema_sha256": "f" * 64, "schema_components": {},
                    "ledger_sha256": "1" * 64, "data_sha256": "2" * 64, "data_bytes": 123,
                    "table_row_counts": {"migrations": 0, "synthetic": 1},
                    "migrations": manifest["expected"]["migrations"],
                    "triggers": manifest["expected"]["triggers"]}
        with (mock.patch.object(RESTORE, "_mysql_identity", return_value={
                    "version": "8.4.11", "server_uuid": manifest["mysql"]["server_uuid"],
                    "log_bin": "1", "binlog_format": "ROW", "trust_function_creators": "0"}),
              mock.patch.object(RESTORE, "mysql_query", return_value=b"0\n"),
              mock.patch.object(RESTORE, "_create_database"),
              mock.patch.object(RESTORE, "verify_definer_lifecycle", return_value={"account_locked": True}),
              mock.patch.object(RESTORE, "_run_mysql_import"),
              mock.patch.object(RESTORE, "_inventory", return_value=observed),
              mock.patch.object(RESTORE, "_drop_database") as drop_database,
              mock.patch.object(RESTORE, "_atomic_json", side_effect=fail_final_journal)):
            try:
                RESTORE._restore_impl(manifest_path, policy_path, config_path, receipt_path,
                                      owner_uid=os.geteuid())
            except RESTORE.RestoreRefused as error:
                assert str(error) == "INJECTED_JOURNAL_FAILURE"
            else:
                raise AssertionError("Injected final journal failure was ignored")
        drop_database.assert_called_once()
        assert not receipt_path.exists()
        assert not journal_path.exists()


def test_existing_target_database_is_never_overwritten():
    with tempfile.TemporaryDirectory() as directory:
        manifest_path, policy_path, config_path, _manifest = _release_fixture(directory)
        receipt_path = Path(directory) / "restore-receipt.json"
        with (mock.patch.object(RESTORE, "_mysql_identity", return_value={
                    "version": "8.4.11", "server_uuid": "123e4567-e89b-42d3-a456-426614174000",
                    "log_bin": "1", "binlog_format": "ROW", "trust_function_creators": "0"}),
              mock.patch.object(RESTORE, "mysql_query", return_value=b"1\n"),
              mock.patch.object(RESTORE, "_create_database") as create_db):
            try:
                RESTORE._restore_impl(manifest_path, policy_path, config_path, receipt_path,
                                      owner_uid=os.geteuid())
            except RESTORE.RestoreRefused as error:
                assert str(error) == "TARGET_SCHEMA_ALREADY_EXISTS"
            else:
                raise AssertionError("Existing target schema was accepted")
            create_db.assert_not_called()
        assert not receipt_path.exists()


def test_definer_account_must_be_locked_and_have_exact_minimal_grants():
    policy = {"mysql": {"server_uuid": "server"}}
    definer = "restore_owner@localhost"
    expected_grants = (b"GRANT USAGE ON *.* TO `restore_owner`@`localhost`\n"
                       b"GRANT TRIGGER ON `wpcg\\_recovery\\_restore\\_20261104\\_a1b2c3d4`.* TO `restore_owner`@`localhost`\n"
                       b"GRANT SELECT ON `wpcg\\_recovery\\_restore\\_20261104\\_a1b2c3d4`.`migrations` TO `restore_owner`@`localhost`\n")
    with mock.patch.object(RESTORE, "mysql_query", side_effect=[b"Y\n", expected_grants]) as query:
        observed = RESTORE.verify_definer_lifecycle(policy, SCHEMA, definer, ["migrations"])
    assert observed["account_locked"] is True
    assert observed["select_tables"] == ["migrations"]
    assert query.call_count == 2
    for locked, grants, code in (
            (b"N\n", expected_grants, "DEFINER_ACCOUNT_NOT_LOCKED"),
            (b"Y\n", expected_grants + b"GRANT SUPER ON *.* TO restore_owner@localhost\n",
             "DEFINER_GRANTS_NOT_MINIMAL")):
        with mock.patch.object(RESTORE, "mysql_query", side_effect=[locked, grants]):
            try:
                RESTORE.verify_definer_lifecycle(policy, SCHEMA, definer, ["migrations"])
            except RESTORE.RestoreRefused as error:
                assert str(error) == code
            else:
                raise AssertionError(f"Expected {code}")


def test_definer_grants_preserve_literal_underscore_escaping():
    escaped = "GRANT TRIGGER ON `wpcg\\_recovery\\_restore\\_20261104\\_a1b2c3d4`.* TO `restore_owner`@`localhost`"
    wildcard = "GRANT TRIGGER ON `wpcg_recovery_restore_20261104_a1b2c3d4`.* TO `restore_owner`@`localhost`"
    expected = "GRANT TRIGGER ON " + RESTORE._grant_pattern_identifier(SCHEMA) + ".* TO restore_owner@localhost"
    assert RESTORE._normalized_grant(escaped) == RESTORE._normalized_grant(expected)
    assert RESTORE._normalized_grant(escaped) != RESTORE._normalized_grant(wildcard)


def test_production_entrypoint_requires_root():
    if os.geteuid() != 0:
        try:
            RESTORE.restore("manifest", "policy", "config", "receipt")
        except RESTORE.RestoreRefused as error:
            assert str(error) == "ROOT_EXECUTION_REQUIRED"
        else:
            raise AssertionError("Non-root restore entrypoint accepted")


def test_mysql_import_pins_utf8mb4_and_initial_sql_mode():
    policy = {"mysql": {"client": "/usr/bin/mysql", "dump_client": "/usr/bin/mysqldump",
                        "defaults_file": "/tmp/mysql.cnf", "host": "127.0.0.1", "port": 3306}}
    argv = RESTORE._mysql_argv(policy, database=SCHEMA)
    assert "--default-character-set=utf8mb4" in argv
    assert "--init-command=SET SESSION sql_mode=''" in argv


def test_ambiguous_create_is_left_as_explicit_hold_and_not_dropped():
    with tempfile.TemporaryDirectory() as directory:
        manifest_path, policy_path, config_path, _manifest = _release_fixture(directory)
        receipt_path = Path(directory) / "restore-receipt.json"
        with (mock.patch.object(RESTORE, "_mysql_identity", return_value={
                    "version": "8.4.11", "server_uuid": "123e4567-e89b-42d3-a456-426614174000",
                    "log_bin": "1", "binlog_format": "ROW", "trust_function_creators": "0"}),
              mock.patch.object(RESTORE, "mysql_query", side_effect=[b"0\n", b"1\n"]),
              mock.patch.object(RESTORE, "_create_database", side_effect=RuntimeError("lost acknowledgement")),
              mock.patch.object(RESTORE, "_drop_database") as drop_db):
            try:
                RESTORE._restore_impl(manifest_path, policy_path, config_path, receipt_path,
                                      owner_uid=os.geteuid())
            except RESTORE.RestoreRefused as error:
                assert str(error) == "RESTORE_CREATE_STATE_UNKNOWN_HOLD"
            else:
                raise AssertionError("Ambiguous create was not held")
            drop_db.assert_not_called()
        journal_path = receipt_path.with_suffix(".pending.json")
        journal, _digest = RESTORE.read_protected_json(journal_path, owner_uid=os.geteuid(), maximum=32 * 1024)
        assert journal["state"] == "CREATE_STATE_UNKNOWN"


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



def main():
    tests = (
        test_safe_dump_is_transformed_narrowly,
        test_only_ordered_standard_mysqldump_mode_footer_is_removed,
        test_standard_mysqldump_trigger_session_wrapper_is_audited_and_removed,
        test_selector_and_schema_rejections,
        test_global_replication_and_external_side_effects_rejected,
        test_comments_and_quoted_data_are_not_sql_directives,
        test_definer_requires_exact_explicit_pin,
        test_successor_pins_and_receipt_contract_are_separate_from_legacy,
        test_admission_tuple_requires_every_exact_pin_and_matching_fingerprint,
        test_no_release_manifest_means_historical_only,
        test_manifest_and_destination_policy_are_protected_and_bound,
        test_release_bound_restore_and_cleanup_pipeline_with_synthetic_mysql,
        test_existing_receipt_blocks_before_any_database_access,
        test_journal_commit_failure_cannot_leave_a_pass_receipt,
        test_definer_account_must_be_locked_and_have_exact_minimal_grants,
        test_existing_target_database_is_never_overwritten,
        test_production_entrypoint_requires_root,
        test_mysql_import_pins_utf8mb4_and_initial_sql_mode,
        test_definer_grants_preserve_literal_underscore_escaping,
        test_ambiguous_create_is_left_as_explicit_hold_and_not_dropped,
        test_target_name_and_dump_format_fail_closed,
        test_sql_resource_budgets_fail_closed,
        test_backup_hash_gzip_and_metadata_checks,
        test_archive_limit_and_trailing_members_fail_closed,
    )
    for test in tests:
        test()
    print(f"ISOLATED_STAGING_RESTORE_V2_TESTS={len(tests)}/PASS")


if __name__ == "__main__":
    main()
