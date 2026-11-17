#!/usr/bin/env python3
"""Synthetic-only tests for authenticated recovery points and migration receipts.

No database client, host, staging or production resource is used: MySQL access
is replaced by in-process fakes, every file is created in a private temporary
directory, and the HMAC key is generated per test.
"""

import contextlib
import copy
import datetime
import gzip
import hashlib
import importlib.util
import io
import json
import os
import secrets
import re
import sys
import subprocess
import tempfile
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]


def _load(name, relative):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


V3 = _load("authenticated_restore_v3_under_test", "scripts/authenticated-restore-v3.py")
V2 = V3.V2
V2T = _load("restore_v2_test_fixtures", "scripts/tests/isolated-staging-restore-v2-test.py")
OWNER = os.geteuid()
UTC = datetime.timezone.utc
SOURCE_UUID = "source-server-uuid"
DEST_UUID = "123e4567-e89b-42d3-a456-426614174000"
BACKUP_ACCOUNT = "wpcg_backup@localhost"
APP_ACCOUNT = "wpcg_app@localhost"
DEFINER = "restore_owner@localhost"
DBA_EXECUTOR = "wpcg_dba_executor@localhost"
CLONE_SCHEMA = "wpcg_recovery_restore_20261115_b2c3d4e5"
TABLES = ["migrations", "synthetic"]
BASE_LEDGER = ["2026_11_02_000001_create_deployment_transition_fences_table"]
PENDING = ["2026_11_16_000001_add_synthetic_a", "2026_11_16_000002_add_synthetic_b"]
OBJECTS = b"events\t0\nroutines\t0\nviews\t0\n"
MYSQL84_DUMP_BODY_FIXTURE = b"""-- MySQL dump 10.13  Distrib 8.4.11, for Linux (x86_64)
-- Host: 127.0.0.1    Database: wpcaregrid
-- Server version 8.4.11
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
CREATE TABLE `migrations` (`id` int NOT NULL);
CREATE TABLE `synthetic` (`id` int NOT NULL);
INSERT INTO `synthetic` VALUES (1);
/*!50003 SET @saved_sql_mode = @@sql_mode */ ;
/*!50003 SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`restore_owner`@`localhost`*/ /*!50003 TRIGGER `synthetic_bi` BEFORE INSERT ON `synthetic` FOR EACH ROW SET NEW.`id`=COALESCE(NEW.`id`,1) */ ;;
DELIMITER ;
/*!50003 SET sql_mode = @saved_sql_mode */ ;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
"""


def expect(code, function, *args, **kwargs):
    try:
        function(*args, **kwargs)
    except V3.RestoreRefused as error:
        assert str(error) == code, (str(error), code)
        return
    raise AssertionError(f"Expected refusal {code}")


def sha(text):
    return hashlib.sha256(text.encode()).hexdigest()


def write_json(path, value):
    path = Path(path)
    path.write_bytes(json.dumps(value, sort_keys=True, separators=(",", ":")).encode() + b"\n")
    os.chmod(path, 0o600)
    return hashlib.sha256(path.read_bytes()).hexdigest()


def read_json(path):
    return json.loads(Path(path).read_text())


def observe_ok(_policy=None):
    return {"current_user": BACKUP_ACCOUNT, "database": "wpcaregrid", "server_uuid_sha256": sha(SOURCE_UUID),
            "mysql_version": "8.4.11", "log_bin": "1", "binlog_format": "ROW", "trust_function_creators": "0",
            "database_charset": "utf8mb4", "database_collation": "utf8mb4_unicode_ci"}


class World:
    """One synthetic capture + release-bound manifest/destination fixture."""

    def __init__(self, directory):
        self.dir = Path(directory)
        self.manifest_path, self.policy_path, self.config_path, self.manifest = V2T._release_fixture(directory)
        self.policy = read_json(self.policy_path)
        self.t0 = datetime.datetime.now(UTC)
        self.key_path = self.dir / "signing.key"
        self.key_path.write_text(secrets.token_hex(32) + "\n", encoding="ascii")
        os.chmod(self.key_path, 0o600)
        self.key = V3.load_signing_key(self.key_path, owner_uid=OWNER)
        config_sha, key_sha = V2._config_identity(self.config_path, owner_uid=OWNER)
        self.reference = {"reference_id": "prod-env-recovery-1", "custody": "protected_escrow",
                          "locator_id": "escrow:wpcg-env-2026-11", "app_key_fingerprint_sha256": key_sha,
                          "config_identity_sha256": config_sha, "verified_at": V3._iso(self.t0)}
        self.reference_path = self.dir / "config-reference.json"
        write_json(self.reference_path, self.reference)
        self.source_policy = {
            "schema_version": 1, "policy_id": "synthetic-source-policy", "environment_id": "source-env",
            "host_id": "source-host", "schema": "wpcaregrid", "backup_account": BACKUP_ACCOUNT,
            "approved_definer": self.manifest["approved_definer"],
            "server_uuid_sha256": sha(SOURCE_UUID),
            "mysql": {"host": "127.0.0.1", "port": 3308, "defaults_file": self.policy["mysql"]["defaults_file"]},
            "recovery_point_kind": V3.RECOVERY_POST_BOOTSTRAP,
            "application": {"state": "deployed", "sha": "d" * 40},
            "deployment_receipt": str(self.dir / "deployment.json"), "active_release": str(self.dir / "current")}
        release = self.dir / ("d" * 40)
        release.mkdir()
        (self.dir / "current").symlink_to(release)
        write_json(self.dir / "deployment.json", {"schema_version": 1, "result": "PASS",
                   "application_sha": "d" * 40, "environment_id": "source-env", "host_id": "source-host",
                   "release_path": str(release)})
        write_json(release / ".wpcg-release.json", {"application_sha": "d" * 40,
                   "deployment_receipt_sha256": hashlib.sha256((self.dir / "deployment.json").read_bytes()).hexdigest()})
        Path(self.source_policy["mysql"]["defaults_file"]).write_text(
            "[client]\nuser=" + BACKUP_ACCOUNT.split("@", 1)[0] + "\n")
        os.chmod(self.source_policy["mysql"]["defaults_file"], 0o600)
        self.source_policy_path = self.dir / "source-policy.json"
        write_json(self.source_policy_path, self.source_policy)
        self.begin_path = self.dir / "capture-begin.json"
        self.record_path = self.dir / "capture-record.json"
        self.archive_path = Path(self.manifest["backup"]["path"])
        self.begin_at = self.t0 - datetime.timedelta(minutes=30)
        self.finish_at = self.t0 - datetime.timedelta(minutes=5)
        stamp = (self.t0 - datetime.timedelta(minutes=10)).timestamp()
        os.utime(self.archive_path, (stamp, stamp))

    def capture(self, observe=observe_ok, dump_sql=None):
        # A no-selector MySQL 8.4 mysqldump body with the normal header,
        # session wrappers, table definitions/data and a versioned trigger.
        sql = dump_sql if dump_sql is not None else MYSQL84_DUMP_BODY_FIXTURE
        self.archive_path.unlink()
        observations = iter((observe_ok(), observe(self.source_policy)))
        def dump(argv, consume, **kw):
            assert argv[0] == "/usr/bin/mysqldump"
            assert argv[1] == "--defaults-file=" + self.source_policy["mysql"]["defaults_file"]
            assert "--single-transaction" in argv and "--set-gtid-purged=OFF" in argv
            assert "--skip-set-charset" in argv and "--databases" not in argv
            assert argv[-2:] == ["--", "wpcaregrid"]
            consume(sql)
        with (mock.patch.object(V3, "_bounded_process", side_effect=dump),
              mock.patch.object(V3, "_now", side_effect=lambda n: n or self.finish_at)):
            V3.capture(self.source_policy_path, self.archive_path, self.manifest["backup"]["id"],
                       self.reference_path, self.record_path, self.key_path, owner_uid=OWNER,
                       now=self.begin_at, observe=lambda p: next(observations))
        self.manifest["backup"].update(sha256=hashlib.sha256(self.archive_path.read_bytes()).hexdigest(),
                                      size_bytes=self.archive_path.stat().st_size)
        write_json(self.manifest_path, self.manifest)
        return self.record_path

    def restore(self, receipt_name="restore-receipt.json", manifest_path=None, now=None):
        return V3.restore_authenticated(
            manifest_path or self.manifest_path, self.policy_path, self.config_path, self.dir / receipt_name,
            self.record_path, self.key_path, owner_uid=OWNER, now=now)


def observed_for(manifest):
    return {"schema_sha256": "f" * 64, "schema_components": {}, "ledger_sha256": V3._ledger_sha(manifest["expected"]["migrations"]), "data_sha256": "2" * 64,
            "data_bytes": 123, "table_row_counts": {"migrations": 0, "synthetic": 1},
            "migrations": manifest["expected"]["migrations"], "triggers": manifest["expected"]["triggers"]}


DEFINER_LIFECYCLE = {"account_exists": True, "account_locked": True, "global_privileges": ["USAGE"],
                     "schema_privileges": ["TRIGGER"], "select_tables": ["migrations"], "grants_sha256": "3" * 64}


@contextlib.contextmanager
def synthetic_restore_mysql(manifest):
    identity = {"version": "8.4.11", "server_uuid": DEST_UUID, "log_bin": "1", "binlog_format": "ROW",
                "trust_function_creators": "0"}
    with (mock.patch.object(V2, "_mysql_identity", return_value=identity),
          mock.patch.object(V2, "mysql_query", return_value=b"0\n"),
          mock.patch.object(V2, "_create_database"),
          mock.patch.object(V2, "verify_definer_lifecycle", return_value=dict(DEFINER_LIFECYCLE)),
          mock.patch.object(V2, "_run_mysql_import"),
          mock.patch.object(V2, "_inventory", return_value=observed_for(manifest)),
          mock.patch.object(V2, "_drop_database") as drop):
        yield drop


def clone_manifest(world, directory):
    manifest = copy.deepcopy(world.manifest)
    manifest["target_schema"] = CLONE_SCHEMA
    manifest["manifest_id"] = "synthetic-manifest-clone"
    path = Path(directory) / "clone-manifest.json"
    write_json(path, manifest)
    return path, manifest


# ---------------------------------------------------------------- authentication

def test_signing_key_must_be_protected_strong_and_bounded():
    with tempfile.TemporaryDirectory() as directory:
        key = Path(directory) / "k"
        key.write_text(secrets.token_hex(32))
        os.chmod(key, 0o640)
        expect("SIGNING_KEY_METADATA_INVALID", V3.load_signing_key, key, owner_uid=OWNER)
        os.chmod(key, 0o600)
        assert len(V3.load_signing_key(key, owner_uid=OWNER)) == 32
        key.write_text("ab" * 16 + "\n")
        expect("SIGNING_KEY_WEAK", V3.load_signing_key, key, owner_uid=OWNER)
        key.write_text("not hex")
        expect("SIGNING_KEY_INVALID", V3.load_signing_key, key, owner_uid=OWNER)
        key.write_text("00" * 8192)
        expect("SIGNING_KEY_SIZE_LIMIT", V3.load_signing_key, key, owner_uid=OWNER)
        link = Path(directory) / "link"
        link.symlink_to(key)
        expect("SIGNING_KEY_UNAVAILABLE", V3.load_signing_key, link, owner_uid=OWNER)
        expect("SIGNING_KEY_PATH_INVALID", V3.load_signing_key, "relative", owner_uid=OWNER)


def test_signature_binds_body_purpose_and_key():
    key, other = secrets.token_bytes(32), secrets.token_bytes(32)
    body = {"a": 1, "b": ["x"]}
    signed = V3.sign_document(body, key, "capture-record")
    assert V3.verify_document(signed, key, "capture-record") == body
    expect("AUTHENTICATION_FAILED", V3.verify_document, signed, other, "capture-record")
    expect("AUTHENTICATION_INVALID", V3.verify_document, signed, key, "restore-receipt")
    tampered = copy.deepcopy(signed)
    tampered["a"] = 2
    expect("AUTHENTICATION_FAILED", V3.verify_document, tampered, key, "capture-record")
    stripped = {k: v for k, v in signed.items() if k != "authentication"}
    expect("AUTHENTICATION_MISSING", V3.verify_document, stripped, key, "capture-record")
    expect("SECRET_MATERIAL_IN_DOCUMENT", V3.sign_document, {"v": "APP_KEY=base64:abc"}, key, "capture-record")
    expect("SECRET_MATERIAL_IN_DOCUMENT", V3.sign_document, {"v": ["x", {"w": "base64:abc"}]}, key, "capture-record")
    expect("DOCUMENT_ALREADY_AUTHENTICATED", V3.sign_document, signed, key, "capture-record")


# ---------------------------------------------------------------- capture records

def test_capture_record_binds_identity_without_raw_uuid_or_secrets():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        world.capture()
        text = world.record_path.read_text()
        record, record_sha = V3.verify_capture_record(world.record_path, world.key, owner_uid=OWNER)
        assert record_sha == hashlib.sha256(world.record_path.read_bytes()).hexdigest()
        assert record["application"] == {"state": "deployed", "sha": "d" * 40}
        assert record["source"]["server_uuid_sha256"] == sha(SOURCE_UUID)
        assert record["source"]["backup_account"] == BACKUP_ACCOUNT
        assert record["archive"]["sha256"] == world.manifest["backup"]["sha256"]
        assert record["archive"]["size_bytes"] == world.manifest["backup"]["size_bytes"]
        assert record["capture"]["started_at"] < record["capture"]["finished_at"]
        assert SOURCE_UUID not in text and "APP_KEY" not in text and "base64:" not in text
        assert V3.verify_document(read_json(world.record_path), world.key, "capture-record")
        expect("EXTERNAL_CAPTURE_DISABLED", V3.capture_begin, world.source_policy_path, world.begin_path,
               world.key_path, owner_uid=OWNER, observe=observe_ok)


def test_capture_refuses_identity_drift_and_wrong_identity():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        expect("SOURCE_IDENTITY_CHANGED_DURING_CAPTURE", world.capture,
               observe=lambda p: observe_ok() | {"mysql_version": "8.4.12"})
        assert not world.record_path.exists()


def test_source_identity_uses_information_schema_default_collation_column():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        raw = (f"{BACKUP_ACCOUNT}\twpcaregrid\t{SOURCE_UUID}\t8.4.11\t1\tROW\t0\t"
               "utf8mb4\tutf8mb4_unicode_ci\n").encode()
        with mock.patch.object(V2, "mysql_query", return_value=raw) as query:
            observation = V3.observe_source_identity(world.source_policy)
        assert observation["database_charset"] == "utf8mb4"
        assert observation["database_collation"] == "utf8mb4_unicode_ci"
        assert "DEFAULT_COLLATION_NAME" in query.call_args.args[1]
        assert ",COLLATION_NAME FROM information_schema.SCHEMATA" not in query.call_args.args[1]


def test_capture_adds_audited_mysql84_selector_and_audits_before_signing():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        world.capture()
        captured = V2.decode_verified_archive(world.archive_path.read_bytes(),
                                              hashlib.sha256(world.archive_path.read_bytes()).hexdigest())
        assert captured.startswith(
            b"CREATE DATABASE /*!32312 IF NOT EXISTS*/ `wpcaregrid` "
            b"/*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;\n"
            b"USE `wpcaregrid`;\n"
        )
        assert b"STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION" in captured
        assert b"DEFINER=`restore_owner`@`localhost`" in captured
        V2.audit_sql(captured, V3.CAPTURE_AUDIT_TARGET, world.manifest["approved_definer"])
    unsafe_cases = (
        (b"SET NAMES gbk;\nCREATE TABLE `migrations` (`id` int NOT NULL);\n",
         "DUMP_UNSAFE_STATEMENT_MYSQL_CHARSET_CHANGE_UNSUPPORTED"),
        (MYSQL84_DUMP_BODY_FIXTURE.replace(
            b"STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION", b"ANSI_QUOTES"), "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (MYSQL84_DUMP_BODY_FIXTURE.replace(
            b"STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION", b"' 'ANSI_QUOTES"),
         "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (MYSQL84_DUMP_BODY_FIXTURE.replace(
            b"SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
            b"SET sql_mode = 'STRICT_TRANS_TABLES,' 'NO_BACKSLASH_ESCAPES'"),
         "SQL_LEXICAL_MODE_UNSUPPORTED"),
        (MYSQL84_DUMP_BODY_FIXTURE.replace(
            b"SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
            b"SET sql_mode = 'STRICT_TRANS_TABLES' | 4"), "SQL_LEXICAL_MODE_UNSUPPORTED"),
    )
    for unsafe_dump, code in unsafe_cases:
        with tempfile.TemporaryDirectory() as directory:
            world = World(directory)
            expect(code, world.capture, dump_sql=unsafe_dump)
            assert not world.record_path.exists()


def test_capture_refuses_stale_oversized_or_untrusted_archives_and_begin_records():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        for age in (0, -86400):
            os.utime(world.archive_path, (world.t0.timestamp()+age,) * 2)
            expect("EXTERNAL_CAPTURE_DISABLED", V3.capture_finish, world.source_policy_path,
                   world.begin_path, world.archive_path, "synthetic-backup-v1", world.reference_path,
                   world.record_path, world.key_path, owner_uid=OWNER)
            expect("RECEIPT_ALREADY_EXISTS", V3.capture, world.source_policy_path, world.archive_path,
                   "synthetic-backup-v1", world.reference_path, world.record_path, world.key_path,
                   owner_uid=OWNER, observe=observe_ok)
        assert not world.record_path.exists()


def test_verify_capture_rejects_stale_expired_incomplete_and_oversized_records():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        world.capture()
        now = datetime.datetime.now(UTC)
        expect("CAPTURE_RECORD_STALE", V3.verify_capture_record, world.record_path, world.key, owner_uid=OWNER,
               now=now + V3.MAX_CAPTURE_AGE + datetime.timedelta(minutes=10))
        expect("CAPTURE_RECORD_STALE", V3.verify_capture_record, world.record_path, world.key, owner_uid=OWNER,
               now=now + V3.MAX_RECORD_LIFETIME + datetime.timedelta(days=1))
        expect("AUTHENTICATION_FAILED", V3.verify_capture_record, world.record_path, secrets.token_bytes(32),
               owner_uid=OWNER)
        body = {k: v for k, v in read_json(world.record_path).items() if k != "authentication"}

        def reissue(mutate):
            changed = copy.deepcopy(body)
            mutate(changed)
            write_json(world.record_path, V3.sign_document(changed, world.key, "capture-record"))
            return V3.verify_capture_record, world.record_path, world.key

        for mutate, code in (
                (lambda b: b.pop("archive"), "CAPTURE_RECORD_INVALID"),
                (lambda b: b.update(unexpected=1), "CAPTURE_RECORD_INVALID"),
                (lambda b: b["archive"].update(size_bytes=V3.MAX_ARCHIVE_BYTES + 1), "CAPTURE_RECORD_INVALID"),
                (lambda b: b["source"].update(server_uuid_sha256="abc"), "CAPTURE_RECORD_INVALID"),
                (lambda b: b["application"].update(state="pre_bootstrap", sha=None), "CAPTURE_RECORD_INVALID"),
                (lambda b: b["capture"].update(finished_at=b["capture"]["started_at"].replace("T", "T0")),
                 "CAPTURE_RECORD_INVALID"),
                (lambda b: b["capture"].update(started_at="2099-01-01T00:00:00Z"), "CAPTURE_INTERVAL_INVALID"),
                (lambda b: b.update(expires_at=b["issued_at"]), "CAPTURE_RECORD_TIME_INVALID"),
                (lambda b: b["config_reference"].update(locator_id="escrow:" + "a1b2c3d4" * 5),
                 "CONFIG_RECOVERY_REFERENCE_SECRET_LIKE")):
            function, *args = reissue(mutate)
            expect(code, function, *args, owner_uid=OWNER)
        # Duplicate keys and oversized files are refused before JSON is trusted.
        world.record_path.write_text('{"a":1,"a":2}')
        os.chmod(world.record_path, 0o600)
        expect("MANIFEST_JSON_DUPLICATE_KEY", V3.verify_capture_record, world.record_path, world.key, owner_uid=OWNER)
        world.record_path.write_text(" " * (V3.MAX_RECORD_BYTES + 1))
        expect("PROTECTED_FILE_METADATA_INVALID", V3.verify_capture_record, world.record_path, world.key, owner_uid=OWNER)


def test_pre_bootstrap_state_is_explicit_and_cannot_be_restored_as_a_post_bootstrap_backup():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        world.capture()
        record, _ = V3.verify_capture_record(world.record_path, world.key, owner_uid=OWNER)
        record.update(recovery_point_kind=V3.RECOVERY_PRE_BOOTSTRAP,
                      application={"state": "pre_bootstrap", "sha": None})
        assert record["recovery_point_kind"] == V3.RECOVERY_PRE_BOOTSTRAP and record["application"]["sha"] is None
        expect("PRE_BOOTSTRAP_RESTORE_UNSUPPORTED", V3.bind_capture_to_manifest, record, world.manifest, world.policy)
        # A pre-bootstrap point may never carry an invented SHA, nor a deployed one lack it.
        for bad in ({"recovery_point_kind": V3.RECOVERY_PRE_BOOTSTRAP, "application": {"state": "deployed", "sha": "a" * 40}},
                    {"recovery_point_kind": V3.RECOVERY_POST_BOOTSTRAP, "application": {"state": "deployed", "sha": None}},
                    {"recovery_point_kind": V3.RECOVERY_POST_BOOTSTRAP, "application": {"state": "pre_bootstrap", "sha": None}}):
            expect("SOURCE_POLICY_INVALID", V3.source_policy_object, world.source_policy | bad)
        expect("SOURCE_POLICY_INVALID", V3.source_policy_object, world.source_policy | {"backup_account": "root@localhost"})
        expect("SOURCE_POLICY_INVALID", V3.source_policy_object, world.source_policy | {
            "mysql": {"host": "db.example.test", "port": 3306, "defaults_file": "/x"}})


# ---------------------------------------------------------------- config recovery reference

def test_config_reference_is_secret_safe():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        good = world.reference
        assert V3.validate_config_recovery_reference(dict(good)) == good
        key_value = "base64:" + secrets.token_urlsafe(32)
        for mutate in (
                lambda r: r.update(locator_id="escrow:" + key_value),
                lambda r: r.update(locator_id="escrow:" + secrets.token_hex(20)),
                lambda r: r.update(reference_id="password-reset-1"),
                lambda r: r.update(locator_id="escrow:vault-token-2026"),
                lambda r: r.update(locator_id="https://user:pw@example.test/x"),
                lambda r: r.update(locator_id="/etc/wpcaregrid/.env"),
                lambda r: r.update(app_key=key_value),
                lambda r: r.pop("verified_at"),
                lambda r: r.update(custody="operator_notebook"),
                lambda r: r.update(app_key_fingerprint_sha256=r["config_identity_sha256"]),
                lambda r: r.update(verified_at="yesterday")):
            bad = dict(good)
            mutate(bad)
            try:
                V3.validate_config_recovery_reference(bad)
            except V3.RestoreRefused as error:
                assert str(error).startswith("CONFIG_RECOVERY_REFERENCE_")
                assert key_value not in str(error)
            else:
                raise AssertionError(f"Secret-like reference accepted: {bad}")
        V3.verify_config_reference_against_config(good, world.config_path, owner_uid=OWNER)
        world.config_path.write_bytes(b"APP_KEY=base64:rotated-key\n")
        os.chmod(world.config_path, 0o600)
        expect("CONFIG_RECOVERY_REFERENCE_MISMATCH", V3.verify_config_reference_against_config, good,
               world.config_path, owner_uid=OWNER)


# ---------------------------------------------------------------- binding

def test_capture_to_manifest_binding_rejects_every_mismatch():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        world.capture()
        record, _ = V3.verify_capture_record(world.record_path, world.key, owner_uid=OWNER)
        bound = V3.bind_capture_to_manifest(record, world.manifest, world.policy)
        assert bound["source_uuid_sha256"] != bound["destination_uuid_sha256"]
        cases = (
            ("CAPTURE_ARCHIVE_BINDING_MISMATCH", lambda m, p: m["backup"].update(sha256="9" * 64)),
            ("CAPTURE_ARCHIVE_BINDING_MISMATCH", lambda m, p: m["backup"].update(size_bytes=m["backup"]["size_bytes"] + 1)),
            ("CAPTURE_ARCHIVE_BINDING_MISMATCH", lambda m, p: m["backup"].update(id="other-backup")),
            ("CAPTURE_APPLICATION_SHA_MISMATCH", lambda m, p: m["backup"].update(source_application_sha="c" * 40)),
            ("CAPTURE_APPLICATION_SHA_MISMATCH", lambda m, p: m["source_identity"].update(application_sha="c" * 40)),
            ("CAPTURE_SCHEMA_BINDING_MISMATCH", lambda m, p: m["source_identity"].update(database="other")),
            ("CAPTURE_SCHEMA_BINDING_MISMATCH", lambda m, p: m.update(target_schema="wpcaregrid")),
            ("CAPTURE_SOURCE_IDENTITY_MISMATCH", lambda m, p: m["source_identity"].update(server_uuid="other-uuid")),
            ("CAPTURE_SOURCE_IDENTITY_MISMATCH", lambda m, p: m["source_identity"].update(environment_id="other-env")),
            ("DESTINATION_NOT_SEPARATE_FROM_SOURCE", lambda m, p: p["mysql"].update(server_uuid=SOURCE_UUID)),
            ("DESTINATION_NOT_SEPARATE_FROM_SOURCE", lambda m, p: p["destination_identity"].update(host_id="source-host")),
            ("DESTINATION_NOT_SEPARATE_FROM_SOURCE", lambda m, p: p["destination_identity"].update(environment_id="source-env")),
            ("CAPTURE_CONFIG_BINDING_MISMATCH", lambda m, p: m["config"].update(identity_sha256="8" * 64)),
            ("CAPTURE_CONFIG_BINDING_MISMATCH", lambda m, p: m["config"].update(app_key_fingerprint_sha256="8" * 64)),
            ("MANIFEST_PREDATES_CAPTURE", lambda m, p: m.update(issued_at="2000-01-01T00:00:00+00:00")),
        )
        for code, mutate in cases:
            manifest, policy = copy.deepcopy(world.manifest), copy.deepcopy(world.policy)
            mutate(manifest, policy)
            expect(code, V3.bind_capture_to_manifest, record, manifest, policy)


# ---------------------------------------------------------------- restore pipeline

def test_authenticated_restore_chains_receipts_and_cleanup():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        world.capture()
        with synthetic_restore_mysql(world.manifest) as drop:
            receipt = world.restore()
            path = V3._auth_path(world.dir / "restore-receipt.json")
            body, receipt_sha = V3.verify_restore_receipt(path, world.key, owner_uid=OWNER)
            assert body == receipt and receipt["result"] == "PASS"
            record_sha = hashlib.sha256(world.record_path.read_bytes()).hexdigest()
            assert body["capture_record_sha256"] == record_sha
            assert body["v2_receipt_sha256"] == hashlib.sha256((world.dir / "restore-receipt.json").read_bytes()).hexdigest()
            assert body["source"]["server_uuid_sha256"] == sha(SOURCE_UUID)
            assert body["destination"]["server_uuid_sha256"] == sha(DEST_UUID)
            assert body["backup"]["source_application_sha"] == "d" * 40
            assert body["release"]["application_sha"] == world.manifest["release"]["application_sha"]
            assert all(body["separation"].values())
            assert body["observed"]["migrations"] == BASE_LEDGER
            assert body["observed"]["table_names"] == world.manifest["expected"]["table_names"]
            text = path.read_text()
            assert SOURCE_UUID not in text and DEST_UUID not in text
            assert "APP_KEY" not in text and "base64:" not in text and "synthetic-test-key" not in text
            expect("RESTORE_RECEIPT_CAPTURE_MISMATCH", V3.verify_restore_receipt, path, world.key,
                   owner_uid=OWNER, capture_sha256="0" * 64)
            expect("RESTORE_RECEIPT_STALE", V3.verify_restore_receipt, path, world.key, owner_uid=OWNER,
                   now=datetime.datetime.now(UTC) + datetime.timedelta(days=2))
            tampered = read_json(path)
            tampered["destination"]["target_schema"] = CLONE_SCHEMA
            write_json(path, tampered)
            expect("AUTHENTICATION_FAILED", V3.verify_restore_receipt, path, world.key, owner_uid=OWNER)
            write_json(path, receipt | {"authentication": read_json(world.record_path)["authentication"]})
            expect("AUTHENTICATION_INVALID", V3.verify_restore_receipt, path, world.key, owner_uid=OWNER)
            write_json(path, V3.sign_document(receipt, world.key, "restore-receipt"))
            cleanup = V3.cleanup_authenticated(world.manifest_path, world.policy_path, world.dir / "restore-receipt.json",
                                               world.key_path, owner_uid=OWNER)
            assert cleanup["absence_verified"] is True and cleanup["restore_receipt_sha256"] == hashlib.sha256(
                path.read_bytes()).hexdigest()
            assert V3.verify_document(read_json(V3._auth_path(world.dir / "restore-receipt.json",
                                                                 ".authenticated-cleanup.json")), world.key,
                                      "restore-cleanup")
            drop.assert_called_once_with(mock.ANY, world.manifest["target_schema"])


def test_untrusted_capture_stops_before_any_database_access():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        world.capture()
        with (mock.patch.object(V2, "_mysql_identity", side_effect=AssertionError("DB reached")),
              mock.patch.object(V2, "mysql_query", side_effect=AssertionError("DB reached"))):
            wrong_key = world.dir / "other.key"
            wrong_key.write_text(secrets.token_hex(32))
            os.chmod(wrong_key, 0o600)
            expect("AUTHENTICATION_FAILED", V3.restore_authenticated, world.manifest_path, world.policy_path,
                   world.config_path, world.dir / "r.json", world.record_path, wrong_key, owner_uid=OWNER)
            expect("CAPTURE_RECORD_STALE", world.restore, "r.json",
                   now=datetime.datetime.now(UTC) + datetime.timedelta(days=30))
            manifest = copy.deepcopy(world.manifest)
            manifest["backup"]["sha256"] = "9" * 64
            bad_path = world.dir / "bad-manifest.json"
            write_json(bad_path, manifest)
            expect("CAPTURE_ARCHIVE_BINDING_MISMATCH", world.restore, "r.json", manifest_path=bad_path)
            (world.dir / "r.authenticated.json").write_text("preserve")
            expect("RECEIPT_ALREADY_EXISTS", world.restore, "r.json")
            world.config_path.write_bytes(b"APP_KEY=base64:different\n")
            os.chmod(world.config_path, 0o600)
            expect("CONFIG_RECOVERY_REFERENCE_MISMATCH", world.restore, "r2.json")
        assert (world.dir / "r.authenticated.json").read_text() == "preserve"
        assert not (world.dir / "r.json").exists()


def test_failure_after_restore_removes_the_schema_or_leaves_an_explicit_hold():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        world.capture()
        with synthetic_restore_mysql(world.manifest) as drop:
            with mock.patch.object(V3, "_write_signed", side_effect=V3.RestoreRefused("INJECTED_WRITE_FAILURE")):
                expect("INJECTED_WRITE_FAILURE", world.restore)
            drop.assert_called_once()
            assert not V3._auth_path(world.dir / "restore-receipt.json").exists()
            assert not (world.dir / "restore-receipt.pending.json").exists()
            assert (world.dir / "restore-receipt.cleanup.json").exists()  # evidence is retained
            # A retry may never reuse the evidence path: the operator must review first.
            expect("RECEIPT_ALREADY_EXISTS", world.restore)
        with tempfile.TemporaryDirectory() as second:
            world = World(second)
            world.capture()
            with synthetic_restore_mysql(world.manifest) as drop:
                drop.side_effect = [V3.RestoreRefused("CLEANUP_FAILED")]
                with mock.patch.object(V3, "_write_signed", side_effect=V3.RestoreRefused("INJECTED_WRITE_FAILURE")):
                    expect("RESTORE_FAILED_CLEANUP_HOLD", world.restore)
            assert (world.dir / "restore-receipt.json").exists()
            assert (world.dir / "restore-receipt.pending.json").exists()  # journal preserved for review
            assert not V3._auth_path(world.dir / "restore-receipt.json").exists()


def test_inputs_changed_during_restore_never_leave_pass_evidence():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        world.capture()
        original = V2._restore_impl

        def swapped(manifest_path, policy_path, *args, **kwargs):
            receipt = original(manifest_path, policy_path, *args, **kwargs)
            receipt = dict(receipt, manifest_sha256="0" * 64)
            return receipt

        with synthetic_restore_mysql(world.manifest) as drop, mock.patch.object(V2, "_restore_impl", swapped):
            expect("INPUTS_CHANGED_DURING_RESTORE", world.restore)
            drop.assert_called_once()
        assert not V3._auth_path(world.dir / "restore-receipt.json").exists()


# ---------------------------------------------------------------- migration rehearsal

def grants(schema, *, extra=(), privileges="SELECT, INSERT, UPDATE, DELETE"):
    escaped = schema.replace("_", "\\_")
    return ["GRANT USAGE ON *.* TO `{a}`", f"GRANT {privileges} ON `{escaped}`.* TO `{{a}}`", *extra]


class Rehearsal:
    def __init__(self, directory, *, contract=None, with_pair=False):
        self.dir = Path(directory)
        self.world = World(directory)
        self.world.capture()
        self.contract = contract or {"pins": {}, "triggers": ("synthetic_bi",), "select_tables": ("migrations",)}
        self.migrations = self.dir / "candidate-migrations"
        self.migrations.mkdir()
        self.holds = self.dir / "holds"
        self.holds.mkdir()
        V3.REHEARSAL_HOLD_ROOT = self.holds
        names = BASE_LEDGER + PENDING
        for name in names:
            (self.migrations / (name + ".php")).write_text(f"<?php // {name}\n")
        self.clone_path, self.clone_manifest = clone_manifest(self.world, directory)
        with synthetic_restore_mysql(self.world.manifest):
            self.world.restore("baseline.json")
            self.world.restore("clone.json", manifest_path=self.clone_path)
        self.baseline_receipt = self.dir / "baseline.json"
        self.clone_receipt = self.dir / "clone.json"
        self.after = sorted(BASE_LEDGER + PENDING)
        self.final_schema = "a" * 64
        applied = set(BASE_LEDGER)
        checkpoints = []
        for index, name in enumerate(PENDING):
            applied.add(name)
            checkpoints.append({"migration": name, "ledger_sha256": V3._canonical_ledger_sha(applied),
                                "schema_sha256": self.final_schema if index == len(PENDING) - 1 else "b" * 64})
        base_time = datetime.datetime.now(UTC)
        self.now = base_time + datetime.timedelta(minutes=1)
        self.inputs = {
            "schema_version": 1, "rehearsal_id": "synthetic-rehearsal-1",
            "candidate": self.world.manifest["release"],
            "accounts": {"app": APP_ACCOUNT, "dba_executor": DBA_EXECUTOR, "definer": DEFINER},
            "dba_window": {"granted_at": V3._iso(base_time + datetime.timedelta(seconds=10)),
                           "revoked_at": V3._iso(base_time + datetime.timedelta(seconds=20)),
                           "sessions_terminated_at": V3._iso(base_time + datetime.timedelta(seconds=30))},
            "guarded_writes": {"executed_as": "app", "allowed_write_passed": True, "rejected_write_passed": True},
            "definer_select_tables": ["migrations"], "checkpoints": checkpoints}
        self.inputs_path = self.dir / "rehearsal-inputs.json"
        self.out = self.dir / "migration-receipt.json"
        self.trigger = dict(self.world.manifest["expected"]["triggers"][0])
        self.state = {"after": self.after, "tables": ["migrations", "synthetic", "new_table"],
                      "triggers": [self.trigger], "baseline_schema_sha": "f" * 64, "sessions": b"0\n",
            "app": grants(CLONE_SCHEMA), "definer": grants(CLONE_SCHEMA, privileges="TRIGGER, SELECT"),
                      "dba": ["GRANT USAGE ON *.* TO `{a}`"],
                      "baseline_data": "2" * 64, "identity_uuid": DEST_UUID}
        write_json(self.inputs_path, self.inputs)

    def schema_state(self, schema):
        if schema == self.world.manifest["target_schema"]:
            return {"schema_sha256": self.state["baseline_schema_sha"], "schema_components": {},
                    "triggers": self.world.manifest["expected"]["triggers"], "object_raw": OBJECTS}
        return {"schema_sha256": self.final_schema, "schema_components": {}, "triggers": self.state["triggers"],
                "object_raw": OBJECTS}

    def ledger(self, schema):
        if schema == self.world.manifest["target_schema"]:
            return list(BASE_LEDGER), V3._ledger_sha(BASE_LEDGER)
        return list(self.state["after"]), V3._ledger_sha(self.state["after"])

    def query(self, _policy, sql, *, database=None, **_kw):
        if sql.startswith("SHOW GRANTS FOR"):
            user_part, host_part = sql.split("FOR ", 1)[1].split("@")
            account = "`" + user_part.strip("'") + "`@`" + host_part.strip("'") + "`"
            kind = "app" if "wpcg_app" in sql else "dba" if "wpcg_dba_executor" in sql else "definer"
            return "\n".join(line.replace("{a}", account) for line in self.state[kind]).encode()
        if "performance_schema.threads" in sql:
            return self.state["sessions"]
        if "information_schema.TABLES" in sql and "TABLE_TYPE='BASE TABLE'" in sql:
            return ("\n".join(self.state["tables"]) + "\n").encode()
        raise AssertionError(f"unexpected query {sql}")

    @contextlib.contextmanager
    def mysql(self):
        identity = {"version": "8.4.11", "server_uuid": self.state["identity_uuid"], "log_bin": "1",
                    "binlog_format": "ROW", "trust_function_creators": "0"}
        with (mock.patch.object(V2, "_mysql_identity", return_value=identity),
              mock.patch.object(V2, "observe_schema", side_effect=lambda _p, s, **_k: self.schema_state(s)),
              mock.patch.object(V2, "observe_ledger", side_effect=lambda _p, s: self.ledger(s)),
              mock.patch.object(V2, "observe_row_counts", side_effect=lambda _p, s, t: {n: 1 for n in t}),
              mock.patch.object(V2, "_safe_dump_hash", side_effect=lambda _p, s, t, **_k: (
                  self.state["baseline_data"] if s == self.world.manifest["target_schema"] else "5" * 64, 10)),
              mock.patch.object(V2, "verify_definer_lifecycle", side_effect=lambda _p, s, d, tables: {
                  **DEFINER_LIFECYCLE, "select_tables": sorted(tables)}),
              mock.patch.object(V2, "mysql_query", side_effect=self.query)):
            yield

    def create(self, **override):
        args = dict(now=self.now, contract=self.contract)
        args.update(override)
        inventory = [{"name": f.stem, "sha256": hashlib.sha256(f.read_bytes()).hexdigest()}
                     for f in sorted(self.migrations.iterdir())]
        definitions = {t["name"]: {k: v for k, v in t.items() if k not in ("name", "definer")}
                       for t in [self.trigger, dict(self.trigger, name="synthetic_bu", event="UPDATE")]}
        args["contract"] = {**args["contract"], "definitions": definitions}
        self.contract.update(definitions=definitions)
        with self.mysql(), mock.patch.object(V3, "candidate_migration_inventory", return_value=inventory):
            return V3.create_migration_receipt(
                self.baseline_receipt, self.clone_receipt, self.clone_path, self.world.policy_path,
                self.inputs_path, self.out, self.world.key_path, owner_uid=OWNER, **args)


def test_migration_receipt_is_machine_checkable_and_binds_the_whole_chain():
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        body = rehearsal.create()
        verified, file_sha = V3.verify_migration_receipt(rehearsal.out, rehearsal.world.key, owner_uid=OWNER,
                                                         now=rehearsal.now, contract=rehearsal.contract)
        assert verified == body and file_sha == hashlib.sha256(rehearsal.out.read_bytes()).hexdigest()
        assert body["pending"] == PENDING and body["before"]["migrations"] == BASE_LEDGER
        assert body["after"]["migrations"] == rehearsal.after
        assert [c["migration"] for c in body["checkpoints"]] == PENDING
        assert body["candidate"]["migration_count"] == 3
        assert body["restore_point"]["baseline_schema"] != body["restore_point"]["clone_schema"]
        assert body["restore_point"]["source_server_uuid_sha256"] != body["restore_point"]["destination_server_uuid_sha256"]
        assert body["observed"]["baseline_unchanged"] is True and body["observed"]["elevated_sessions_open"] == 0
        assert body["observed"]["app_account"]["schema_privileges"] == ["delete", "insert", "select", "update"]
        assert body["attested"]["guarded_writes"]["executed_as"] == "app"  # attested, not observed
        assert body["production_admission"] == {
            "state": "HOLD",
            "required_runtime_attestations": [
                "mysqldump_endpoint_instance_identity",
                "application_connection_identity",
                "candidate_migration_bytes_execution",
            ],
        }
        assert "isolated_rehearsal_evidence_only" in body["limitations"]
        text = rehearsal.out.read_text()
        assert DEST_UUID not in text and SOURCE_UUID not in text
        record_sha = hashlib.sha256(rehearsal.world.record_path.read_bytes()).hexdigest()
        assert body["capture_record_sha256"] == record_sha
        assert body["clone_receipt_sha256"] == hashlib.sha256(
            V3._auth_path(rehearsal.clone_receipt).read_bytes()).hexdigest()
        expect("RECEIPT_ALREADY_EXISTS", rehearsal.create)
        expect("MIGRATION_RECEIPT_STALE", V3.verify_migration_receipt, rehearsal.out, rehearsal.world.key,
               owner_uid=OWNER, now=rehearsal.now + datetime.timedelta(days=3), contract=rehearsal.contract)
        expect("MIGRATION_RECEIPT_CLONE_MISMATCH", V3.verify_migration_receipt, rehearsal.out, rehearsal.world.key,
               owner_uid=OWNER, now=rehearsal.now, contract=rehearsal.contract, expect_clone_receipt_sha256="0" * 64)
        hold = V3._migration_failure_path(read_json(V3._auth_path(rehearsal.clone_receipt)))
        V3._write_signed(hold, {"schema_version": 1, "kind": "wpcg_migration_rehearsal_failure",
                                "result": "HOLD", "code": "LATER_REHEARSAL_FAILED",
                                "failed_at": V3._iso(rehearsal.now), "clone_receipt_sha256": "a" * 64,
                                "destination_policy_sha256": "b" * 64, "clone_schema": CLONE_SCHEMA},
                         rehearsal.world.key, V3.PURPOSE_MIGRATION_FAILURE, owner_uid=OWNER)
        expect("FAILED_REHEARSAL_HOLD_PRESENT", V3.verify_migration_receipt, rehearsal.out,
               rehearsal.world.key, owner_uid=OWNER, now=rehearsal.now, contract=rehearsal.contract)
        try:
            V3.verify_migration_receipt(rehearsal.out, rehearsal.world.key, owner_uid=OWNER,
                                        now=rehearsal.now, contract=rehearsal.contract,
                                        allow_matching_hold=True)
        except TypeError:
            pass
        else:
            raise AssertionError("public verifier exposed a hold-bypass argument")


def resign(rehearsal, mutate):
    body = {k: v for k, v in read_json(rehearsal.out).items() if k != "authentication"}
    mutate(body)
    write_json(rehearsal.out, V3.sign_document(body, rehearsal.world.key, "migration-receipt"))


def test_offline_verifier_rejects_internally_inconsistent_but_correctly_signed_receipts():
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        rehearsal.create()
        pristine = rehearsal.out.read_text()
        cases = (
            ("MIGRATION_RECEIPT_PENDING_INVALID", lambda b: b.update(pending=PENDING[:1])),
            ("MIGRATION_CHECKPOINT_MISMATCH", lambda b: b["checkpoints"].reverse()),
            ("MIGRATION_CHECKPOINTS_INCOMPLETE", lambda b: b["checkpoints"].pop()),
            ("MIGRATION_RECEIPT_LEDGER_INVALID", lambda b: b["after"].update(ledger_sha256="7" * 64)),
            ("MIGRATION_REHEARSAL_INCOMPLETE", lambda b: (b["after"].update(migrations=BASE_LEDGER),
                                                           b["after"].update(ledger_sha256=V3._ledger_sha(BASE_LEDGER)))),
            ("MIGRATION_RECEIPT_INVENTORY_INVALID", lambda b: b["candidate"].update(migration_inventory_sha256="6" * 64)),
            ("ACCOUNT_IDENTITY_SEPARATION_INVALID", lambda b: b["attested"]["accounts"].update(app=DEFINER)),
            ("TRIGGER_DEFINER_UNEXPECTED", lambda b: b["triggers"][0].update(definer="other@localhost")),
            ("MIGRATION_RECEIPT_PHASE2B_INVALID", lambda b: b["phase2b"].update(trigger_inventory_complete=False)),
            ("MIGRATION_RECEIPT_SEPARATION_INVALID", lambda b: b["restore_point"].update(
                source_server_uuid_sha256=b["restore_point"]["destination_server_uuid_sha256"])),
            ("DBA_WINDOW_ORDER_INVALID", lambda b: b["attested"]["dba_window"].update(
                revoked_at="2000-01-01T00:00:00Z")),
            ("MIGRATION_RECEIPT_INVALID", lambda b: b["observed"].update(baseline_unchanged=False)),
            ("MIGRATION_RECEIPT_INVALID", lambda b: b["observed"].update(elevated_sessions_open=1)),
            ("PRODUCTION_ADMISSION_MUST_REMAIN_HOLD", lambda b: b["production_admission"].update(state="PASS")),
            ("MIGRATION_RECEIPT_INVALID", lambda b: b.update(result="HOLD")),
        )
        for code, mutate in cases:
            rehearsal.out.write_text(pristine)
            os.chmod(rehearsal.out, 0o600)
            resign(rehearsal, mutate)
            expect(code, V3.verify_migration_receipt, rehearsal.out, rehearsal.world.key, owner_uid=OWNER,
                   now=rehearsal.now, contract=rehearsal.contract)
        rehearsal.out.write_text(pristine)
        tampered = read_json(rehearsal.out)
        tampered["pending"] = []
        write_json(rehearsal.out, tampered)
        expect("AUTHENTICATION_FAILED", V3.verify_migration_receipt, rehearsal.out, rehearsal.world.key,
               owner_uid=OWNER, now=rehearsal.now, contract=rehearsal.contract)


def test_rehearsal_refuses_incomplete_unknown_or_regressed_ledgers():
    for mutate, code in (
            (lambda r: r.state.update(after=BASE_LEDGER + PENDING[:1]), "MIGRATION_REHEARSAL_INCOMPLETE"),
            (lambda r: r.state.update(after=sorted(BASE_LEDGER + PENDING + ["2026_12_01_000001_unknown"])),
             "LEDGER_NOT_IN_CANDIDATE"),
            (lambda r: r.state.update(after=PENDING), "MIGRATION_LEDGER_REGRESSED")):
        with tempfile.TemporaryDirectory() as directory:
            rehearsal = Rehearsal(directory)
            mutate(rehearsal)
            expect(code, rehearsal.create)
            assert not rehearsal.out.exists()


def test_baseline_must_remain_unchanged_and_clone_must_share_the_recovery_point():
    for mutate, code in (
            (lambda r: r.state.update(baseline_schema_sha="9" * 64), "BASELINE_RESTORE_CHANGED"),
            (lambda r: r.state.update(baseline_data="9" * 64), "BASELINE_RESTORE_CHANGED")):
        with tempfile.TemporaryDirectory() as directory:
            rehearsal = Rehearsal(directory)
            mutate(rehearsal)
            expect(code, rehearsal.create)
            hold = V3._migration_failure_path(read_json(V3._auth_path(rehearsal.clone_receipt)))
            marker = V3.verify_document(read_json(hold), rehearsal.world.key, "migration-failure")
            assert marker["result"] == "IN_PROGRESS"
            # A failed rehearsal blocks blind retries until reviewed.
            mutate_back = rehearsal.state
            mutate_back.update(baseline_schema_sha="f" * 64, baseline_data="2" * 64)
            expect("FAILED_REHEARSAL_HOLD_PRESENT", rehearsal.create)
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        # Using the baseline as its own clone is refused (same schema).
        rehearsal.clone_receipt = rehearsal.baseline_receipt
        rehearsal.clone_path = rehearsal.world.manifest_path
        expect("CLONE_NOT_FROM_SAME_RECOVERY_POINT", rehearsal.create)
        rehearsal.clone_receipt = rehearsal.dir / "clone.json"
        rehearsal.clone_path = rehearsal.dir / "clone-manifest.json"
        envelope = read_json(V3._auth_path(rehearsal.clone_receipt))
        body = {k: v for k, v in envelope.items() if k != "authentication"}
        body["backup"]["sha256"] = "9" * 64
        write_json(V3._auth_path(rehearsal.clone_receipt), V3.sign_document(body, rehearsal.world.key, "restore-receipt"))
        expect("CLONE_NOT_FROM_SAME_RECOVERY_POINT", rehearsal.create)
        body["backup"]["sha256"] = rehearsal.world.manifest["backup"]["sha256"]
        body["capture_record_sha256"] = "9" * 64
        write_json(V3._auth_path(rehearsal.clone_receipt), V3.sign_document(body, rehearsal.world.key, "restore-receipt"))
        expect("RESTORE_RECEIPT_CAPTURE_MISMATCH", rehearsal.create)


def test_rehearsal_refuses_untrusted_stale_or_mismatched_inputs():
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        expect("RESTORE_RECEIPT_STALE", rehearsal.create, now=rehearsal.now + datetime.timedelta(days=2))
        inputs = copy.deepcopy(rehearsal.inputs)
        for mutate, code in (
                (lambda i: i["candidate"].update(application_sha="1" * 40), "CANDIDATE_BINDING_MISMATCH"),
                (lambda i: i["accounts"].update(definer="other_definer@localhost"), "ACCOUNT_IDENTITY_SEPARATION_INVALID"),
                (lambda i: i["accounts"].update(app=DEFINER), "ACCOUNT_IDENTITY_SEPARATION_INVALID"),
                (lambda i: i["accounts"].update(app=DEFINER), "ACCOUNT_IDENTITY_SEPARATION_INVALID"),
                (lambda i: i["accounts"].update(dba_executor=DEFINER), "ACCOUNT_IDENTITY_SEPARATION_INVALID"),
                (lambda i: i["dba_window"].update(granted_at="2000-01-01T00:00:00Z"), "DBA_WINDOW_ORDER_INVALID"),
                (lambda i: i["dba_window"].update(sessions_terminated_at="2099-01-01T00:00:00Z"), "DBA_WINDOW_ORDER_INVALID"),
                (lambda i: i["guarded_writes"].update(rejected_write_passed=False), "REHEARSAL_INPUTS_INVALID"),
                (lambda i: i.update(extra=1), "REHEARSAL_INPUTS_INVALID"),
                (lambda i: i.pop("checkpoints"), "REHEARSAL_INPUTS_INVALID"),
                (lambda i: i.update(definer_select_tables=["synthetic", "migrations"]), "DEFINER_SELECT_TABLES_INVALID")):
            changed = copy.deepcopy(inputs)
            mutate(changed)
            write_json(rehearsal.inputs_path, changed)
            expect(code, rehearsal.create)
            # Independent malformed-input scenario; explicit synthetic hold reset.
            V3._migration_failure_path(read_json(V3._auth_path(rehearsal.clone_receipt))).unlink(missing_ok=True)
        write_json(rehearsal.inputs_path, inputs)
        rehearsal.inputs_path.write_text('{"a":1,"a":2}')
        os.chmod(rehearsal.inputs_path, 0o600)
        expect("MANIFEST_JSON_DUPLICATE_KEY", rehearsal.create)
        assert not V3._migration_failure_path(read_json(V3._auth_path(rehearsal.clone_receipt))).exists()
        write_json(rehearsal.inputs_path, inputs)
        wrong_policy = copy.deepcopy(rehearsal.world.policy)
        wrong_policy["policy_id"] = "another-policy"
        write_json(rehearsal.world.policy_path, wrong_policy)
        expect("REHEARSAL_BINDING_MISMATCH", rehearsal.create)


def test_checkpoints_must_match_the_exact_pending_sequence():
    for mutate in (
            lambda i: i["checkpoints"].reverse(),
            lambda i: i["checkpoints"].pop(),
            lambda i: i["checkpoints"][0].update(ledger_sha256="4" * 64),
            lambda i: i["checkpoints"][-1].update(schema_sha256="4" * 64)):
        with tempfile.TemporaryDirectory() as directory:
            rehearsal = Rehearsal(directory)
            changed = copy.deepcopy(rehearsal.inputs)
            mutate(changed)
            write_json(rehearsal.inputs_path, changed)
            try:
                rehearsal.create()
            except V3.RestoreRefused as error:
                assert str(error) in {"MIGRATION_CHECKPOINT_MISMATCH", "MIGRATION_CHECKPOINTS_INCOMPLETE"}
            else:
                raise AssertionError("Bad checkpoints accepted")
            assert not rehearsal.out.exists()


def test_candidate_migration_inventory_is_bounded_and_rejects_unsafe_files():
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        repo = root / "repo"
        subprocess.run(["git", "init", "-q", str(repo)], check=True)
        migrations = repo / "database/migrations"
        migrations.mkdir(parents=True)
        file = migrations / (PENDING[0] + ".php")
        file.write_text("<?php // exact candidate")
        def commit():
            subprocess.run(["git", "-C", str(repo), "add", "."], check=True)
            subprocess.run(["git", "-C", str(repo), "-c", "user.name=Test", "-c", "user.email=test@example.test",
                            "commit", "-qm", "fixture"], check=True)
            return subprocess.check_output(["git", "-C", str(repo), "rev-parse", "HEAD"]).decode().strip()
        good = commit()
        store = root / "store.git"
        subprocess.run(["git", "init", "--bare", "-q", str(store)], check=True)
        subprocess.run(["git", "-c", "fetch.unpackLimit=999999", "-c", "gc.auto=0",
                        "-c", "maintenance.auto=false", "--git-dir=" + str(store),
                        "fetch", "-q", str(repo), "HEAD"], check=True)
        with mock.patch.object(V3, "CANDIDATE_GIT_STORE", store):
            inventory = V3.candidate_migration_inventory(good, owner_uid=OWNER)
            assert inventory == [{"name": PENDING[0], "sha256": hashlib.sha256(file.read_bytes()).hexdigest()}]
            file.write_text("tampered worktree")
            assert V3.candidate_migration_inventory(good, owner_uid=OWNER) == inventory
            expect("CANDIDATE_SHA_INVALID", V3.candidate_migration_inventory, "relative/path", owner_uid=OWNER)
            expect("CAPTURE_PROCESS_FAILED", V3.candidate_migration_inventory, "0" * 40, owner_uid=OWNER)
            os.chmod(store / "config", 0o666)
            expect("CANDIDATE_STORE_UNSAFE", V3.candidate_migration_inventory, good, owner_uid=OWNER)
            os.chmod(store / "config", 0o600)
            file.unlink()
            file.symlink_to("/etc/passwd")
            bad = commit()
            subprocess.run(["git", "-c", "gc.auto=0", "-c", "maintenance.auto=false",
                            "--git-dir=" + str(store), "fetch", "-q", str(repo), "HEAD"], check=True)
            expect("CANDIDATE_TREE_INVALID", V3.candidate_migration_inventory, bad, owner_uid=OWNER)
            file.unlink()
            file.write_text("<?php executable")
            os.chmod(file, 0o755)
            executable = commit()
            subprocess.run(["git", "-c", "gc.auto=0", "-c", "maintenance.auto=false",
                            "--git-dir=" + str(store), "fetch", "-q", str(repo), "HEAD"], check=True)
            expect("CANDIDATE_TREE_INVALID", V3.candidate_migration_inventory, executable, owner_uid=OWNER)
            (migrations / (PENDING[1] + ".php")).write_text("<?php second")
            crowded = commit()
            subprocess.run(["git", "-c", "gc.auto=0", "-c", "maintenance.auto=false",
                            "--git-dir=" + str(store), "fetch", "-q", str(repo), "HEAD"], check=True)
            with mock.patch.object(V3, "MAX_MIGRATION_FILES", 1):
                expect("CANDIDATE_MIGRATIONS_COUNT_INVALID", V3.candidate_migration_inventory, crowded, owner_uid=OWNER)
            (store / "objects/info/alternates").write_text("/untrusted")
            expect("CANDIDATE_STORE_UNSAFE", V3.candidate_migration_inventory, good, owner_uid=OWNER)
            (store / "objects/info/alternates").unlink()
            with mock.patch.object(V3, "MAX_MIGRATION_FILE_BYTES", 1):
                expect("CAPTURE_PROCESS_SIZE_LIMIT", V3.candidate_migration_inventory, good, owner_uid=OWNER)


def test_candidate_store_rejects_lazy_fetch_config_hardlinks_and_corrupt_objects():
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        repo = root / "repo"
        subprocess.run(["git", "init", "-q", str(repo)], check=True)
        migrations = repo / "database/migrations"
        migrations.mkdir(parents=True)
        file = migrations / (PENDING[0] + ".php")
        file.write_text("<?php trusted candidate")
        subprocess.run(["git", "-C", str(repo), "add", "."], check=True)
        subprocess.run(["git", "-C", str(repo), "-c", "user.name=Test", "-c", "user.email=test@example.test",
                        "commit", "-qm", "fixture"], check=True)
        candidate = subprocess.check_output(["git", "-C", str(repo), "rev-parse", "HEAD"]).decode().strip()
        store = root / "candidate.git"
        subprocess.run(["git", "init", "--bare", "-q", str(store)], check=True)
        subprocess.run(["git", "-c", "fetch.unpackLimit=999999", "-c", "gc.auto=0",
                        "-c", "maintenance.auto=false", "--git-dir=" + str(store),
                        "fetch", "-q", str(repo), "HEAD"], check=True)
        with mock.patch.object(V3, "CANDIDATE_GIT_STORE", store):
            assert V3.candidate_migration_inventory(candidate, owner_uid=OWNER)
            database_tree = subprocess.check_output(["git", "-C", str(repo), "rev-parse",
                                                     candidate + ":database"]).decode().strip()
            loose_tree = store / "objects" / database_tree[:2] / database_tree[2:]
            saved_tree = loose_tree.read_bytes()
            import zlib
            os.chmod(loose_tree, 0o600)
            loose_tree.write_bytes(zlib.compress(b"tree 6\0broken"))
            expect("CANDIDATE_OBJECT_HASH_MISMATCH", V3.candidate_migration_inventory,
                   candidate, owner_uid=OWNER)
            loose_tree.write_bytes(saved_tree)
            os.chmod(loose_tree, 0o444)
            config = store / "config"
            original = config.read_text()
            config.write_text(original + '\n[remote "origin"]\n\tpromisor = true\n')
            expect("CANDIDATE_STORE_UNSAFE", V3.candidate_migration_inventory, candidate, owner_uid=OWNER)
            config.write_text(original)
            blob = subprocess.check_output(["git", "-C", str(repo), "rev-parse",
                                            candidate + ":database/migrations/" + file.name]).decode().strip()
            loose = store / "objects" / blob[:2] / blob[2:]
            assert loose.exists(), "fixture must use a loose blob object"
            original_object = loose.read_bytes()
            os.chmod(loose, 0o600)
            loose.write_bytes(zlib.compress(b"blob 6\0broken"))
            expect("CANDIDATE_OBJECT_HASH_MISMATCH", V3.candidate_migration_inventory, candidate, owner_uid=OWNER)
            loose.write_bytes(original_object)
            os.chmod(loose, 0o444)
            os.link(loose, root / "object-hardlink")
            expect("CANDIDATE_STORE_UNSAFE", V3.candidate_migration_inventory, candidate, owner_uid=OWNER)


def phase2b_contract(directory, *, names):
    migrations = Path(directory) / "candidate-migrations"
    return {"pins": {name: hashlib.sha256((migrations / (name + ".php")).read_bytes()).hexdigest() for name in names},
            "triggers": ("synthetic_bi", "synthetic_bu"), "select_tables": ("migrations",)}


def test_phase2b_pair_requires_pinned_hashes_and_the_complete_definer_trigger_inventory():
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        contract = phase2b_contract(directory, names=PENDING)
        second = dict(rehearsal.trigger, name="synthetic_bu", event="UPDATE")
        rehearsal.state["triggers"] = [rehearsal.trigger, second]
        body = rehearsal.create(contract=contract)
        assert body["phase2b"] == {"pair_in_candidate": True, "trigger_inventory_complete": True,
                                   "pair_hashes_pinned": True}
        assert body["observed"]["definer"]["select_tables"] == ["migrations"]
        # Hash drift in either pinned file refuses.
        (rehearsal.migrations / (PENDING[0] + ".php")).write_text("<?php // altered\n")
        rehearsal.out.unlink()
        V3._migration_failure_path(read_json(V3._auth_path(rehearsal.clone_receipt))).unlink(missing_ok=True)
        expect("PHASE2B_PAIR_HASH_MISMATCH", rehearsal.create, contract=contract)
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        contract = phase2b_contract(directory, names=PENDING)
        # Only one of the two triggers exists: incomplete inventory is a refusal, not a warning.
        expect("PHASE2B_TRIGGER_INVENTORY_INCOMPLETE", rehearsal.create, contract=contract)
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        contract = phase2b_contract(directory, names=PENDING)
        rehearsal.state["triggers"] = [dict(rehearsal.trigger, definer="rogue@localhost"),
                                       dict(rehearsal.trigger, name="synthetic_bu", event="UPDATE")]
        expect("PHASE2B_TRIGGER_INVENTORY_INCOMPLETE", rehearsal.create, contract=contract)
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        contract = phase2b_contract(directory, names=PENDING[:1])  # a half pair: the other pinned file is absent
        contract["pins"]["2026_11_16_000009_missing_half"] = "a" * 64
        expect("PHASE2B_PAIR_INCOMPLETE", rehearsal.create, contract=contract)
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        contract = phase2b_contract(directory, names=PENDING) | {"select_tables": ("synthetic",)}
        rehearsal.state["triggers"] = [rehearsal.trigger, dict(rehearsal.trigger, name="synthetic_bu", event="UPDATE")]
        expect("PHASE2B_DEFINER_SELECT_TABLES_UNEXPECTED", rehearsal.create, contract=contract)
    real_pins = V3.PHASE2B_MIGRATION_PINS
    for name, digest in real_pins.items():
        source = ROOT / "database/migrations" / (name + ".php")
        assert hashlib.sha256(source.read_bytes()).hexdigest() == digest, name
    assert len(V3.PHASE2B_TRIGGERS) == 6 and V3.PHASE2B_TRIGGERS == tuple(sorted(V3.PHASE2B_TRIGGERS))
    migration = (ROOT / "database/migrations/2026_11_15_000001_verify_phase2b_trigger_definers.php").read_text()
    for trigger in V3.PHASE2B_TRIGGERS:
        assert f"'{trigger}'" in migration


def test_least_privilege_account_observation_rejects_excess_and_open_sessions():
    cases = (
        ("ACCOUNT_PRIVILEGE_EXCESSIVE", "app", ["GRANT SUPER ON *.* TO `{a}`"]),
        ("ACCOUNT_PRIVILEGE_EXCESSIVE", "app", ["GRANT USAGE ON *.* TO `{a}` WITH GRANT OPTION"]),
        ("ACCOUNT_PRIVILEGE_EXCESSIVE", "app", ["GRANT USAGE ON *.* TO `{a}`", "GRANT FILE ON *.* TO `{a}`"]),
        ("ACCOUNT_PRIVILEGE_EXCESSIVE", "app", ["GRANT SELECT ON *.* TO `{a}`"]),
        ("ACCOUNT_PRIVILEGE_EXCESSIVE", "app", ["GRANT USAGE ON *.* TO `{a}`",
                                                 "GRANT ALL PRIVILEGES ON `wpcg\\_recovery\\_restore\\_20261115\\_b2c3d4e5`.* TO `{a}`"]),
        ("ACCOUNT_PRIVILEGE_EXCESSIVE", "app", ["GRANT USAGE ON *.* TO `{a}`",
                                                 "GRANT SELECT ON `wpcg\\_recovery\\_restore\\_20261115\\_b2c3d4e5`.* TO `{a}` WITH GRANT OPTION"]),
        ("ACCOUNT_SCOPE_EXCESSIVE", "app", ["GRANT USAGE ON *.* TO `{a}`", "GRANT SELECT ON `other_schema`.* TO `{a}`"]),
        ("ACCOUNT_SCOPE_EXCESSIVE", "app", ["GRANT USAGE ON *.* TO `{a}`", "GRANT SELECT ON `mysql`.`user` TO `{a}`"]),
        ("ACCOUNT_GRANT_UNPARSEABLE", "app", ["GRANT `app_role`@`%` TO `{a}`"]),
        ("ACCOUNT_PRIVILEGE_EXCESSIVE", "app", ["GRANT SELECT ON `wpcg\\_recovery\\_restore\\_20261115\\_b2c3d4e5`.* TO `{a}`"]),
        ("ACCOUNT_PRIVILEGE_EXCESSIVE", "definer", ["GRANT USAGE ON *.* TO `{a}`", "GRANT SUPER ON *.* TO `{a}`"]),
    )
    for code, kind, lines in cases:
        with tempfile.TemporaryDirectory() as directory:
            rehearsal = Rehearsal(directory)
            rehearsal.state[kind] = lines
            expect(code, rehearsal.create)
            assert not rehearsal.out.exists()
            assert V3._migration_failure_path(read_json(V3._auth_path(rehearsal.clone_receipt))).exists()
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        rehearsal.state["app"] = ["GRANT USAGE ON *.* TO `{a}`"]  # no schema grants at all
        expect("APP_ACCOUNT_GRANTS_MISSING", rehearsal.create)
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        rehearsal.state["sessions"] = b"2\n"
        expect("ELEVATED_SESSIONS_STILL_OPEN", rehearsal.create)
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        rehearsal.state["sessions"] = b"many"
        expect("ACCOUNT_SESSION_INVENTORY_INVALID", rehearsal.create)


def test_destination_identity_and_unexpected_objects_fail_closed():
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        rehearsal.state["identity_uuid"] = "another-instance-uuid"
        expect("DESTINATION_MYSQL_CONTRACT_MISMATCH", rehearsal.create)
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        original = rehearsal.schema_state

        def with_view(schema):
            state = original(schema)
            return state | {"object_raw": b"events\t0\nroutines\t0\nviews\t1\n"} if schema == CLONE_SCHEMA else state

        rehearsal.schema_state = with_view
        expect("UNEXPECTED_SCHEMA_OBJECT_PRESENT", rehearsal.create)
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        rehearsal.state["tables"] = ["synthetic"]  # reviewed SELECT table disappeared
        expect("DEFINER_SELECT_TABLE_MISSING", rehearsal.create)
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        rehearsal.state["triggers"] = [dict(rehearsal.trigger, definer="rogue@localhost")]
        expect("TRIGGER_DEFINER_UNEXPECTED", rehearsal.create)


# ---------------------------------------------------------------- CLI and static contract

def test_command_line_requires_root_and_reports_fixed_codes_only():
    stderr = io.StringIO()
    with contextlib.redirect_stderr(stderr):
        if os.geteuid() != 0:
            assert V3.main(["verify-capture", "--capture-record", "/x", "--signing-key", "/y"]) == 1
            assert "CODE=ROOT_EXECUTION_REQUIRED" in stderr.getvalue()
    stderr = io.StringIO()
    with mock.patch.object(os, "geteuid", return_value=0), contextlib.redirect_stderr(stderr):
        assert V3.main(["restore"]) == 1
        assert "CODE=REQUIRED_ARGUMENT_MISSING" in stderr.getvalue()
    stderr = io.StringIO()
    with mock.patch.object(os, "geteuid", return_value=0), contextlib.redirect_stderr(stderr):
        assert V3.main(["verify-capture", "--capture-record", "relative", "--signing-key", "relative"]) == 1
        assert "CODE=SIGNING_KEY_PATH_INVALID" in stderr.getvalue()
    with mock.patch.object(os, "geteuid", return_value=0), mock.patch.object(
            V3, "load_signing_key", side_effect=RuntimeError("secret detail"
                                                            )), contextlib.redirect_stderr(io.StringIO()) as hidden:
        assert V3.main(["verify-capture", "--capture-record", "/x", "--signing-key", "/y"]) == 1
        assert "secret detail" not in hidden.getvalue() and "UNEXPECTED_FAILURE" in hidden.getvalue()


def test_source_has_no_shell_execution_secret_argv_or_remote_code_surface():
    text = (ROOT / "scripts/authenticated-restore-v3.py").read_text()
    for forbidden in ("shell=True", "os.system", "eval(", "exec(", "--password", "MYSQL_PWD", "-p'", "popen("):
        assert forbidden not in text, forbidden
    # Every database action goes through the single v2 query/dump/import helpers.
    assert "V2.mysql_query" in text and "V2._safe_dump_hash" in text
    # v2 remains the one restore engine: the module holds no SQL import, DROP or CREATE DATABASE of its own.
    for sql in ("DROP DATABASE", "CREATE DATABASE", "SET GLOBAL", "GRANT ", "REVOKE "):
        assert sql not in text, sql


def test_phase2b_rejects_every_trigger_field_and_action_tamper():
    # Independently expand the pinned migration's PHP SQL templates; the
    # approved action fingerprints must remain tied to those reviewed bytes.
    for table in ("transition_v2_requests", "transition_history_events"):
        source = next((ROOT / "database/migrations").glob("*create_" + table + "_table.php")).read_text()
        values = {"table": table}
        for line in source.splitlines():
            match = re.fullmatch(r"\s*\$(\w+) = ([\"'])(.*?)\2;", line)
            if match:
                name, _, value = match.groups()
                values[name] = re.sub(r"\{\$(\w+)\}", lambda m: values.get(m[1], m[0]), value)
        condition = values["requestIdentityCondition" if table == "transition_v2_requests" else "historyConditionMysql"]
        for event in ("insert", "update", "delete"):
            action = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '" + values["message"] + "'"
            if event == "insert":
                action = "BEGIN IF NOT (" + condition + ") THEN " + action + "; END IF; END"
            assert "{$" not in action
            assert sha(action) == V3.PHASE2B_TRIGGER_DEFINITIONS[table + "_immutable_" + event]["action_sha256"]
    triggers = [{"name": name, "definer": DEFINER, **definition}
                for name, definition in V3.PHASE2B_TRIGGER_DEFINITIONS.items()]
    assert V3._check_phase2b_triggers(triggers, DEFINER, True, V3.PHASE2B_TRIGGERS)
    for index in range(6):
        for field, value in (("name", "other"), ("definer", "rogue@localhost"), ("event", "OTHER"),
                             ("table", "other"), ("timing", "AFTER"), ("orientation", "STATEMENT"),
                             ("action_sha256", sha("BEGIN END"))):
            changed = copy.deepcopy(triggers)
            changed[index][field] = value
            expect("PHASE2B_TRIGGER_INVENTORY_INCOMPLETE", V3._check_phase2b_triggers,
                   changed, DEFINER, True, V3.PHASE2B_TRIGGERS)


def test_application_privileges_are_allowlisted_online_and_offline():
    for privilege in ("DROP", "ALTER", "CREATE", "TRIGGER", "SUPER", "EXECUTE", "UNKNOWN"):
        with tempfile.TemporaryDirectory() as directory:
            rehearsal = Rehearsal(directory)
            rehearsal.state["app"] = grants(CLONE_SCHEMA, privileges="SELECT, " + privilege)
            expect("ACCOUNT_PRIVILEGE_EXCESSIVE", rehearsal.create)
        with tempfile.TemporaryDirectory() as directory:
            rehearsal = Rehearsal(directory)
            rehearsal.create()
            resign(rehearsal, lambda b: b["observed"]["app_account"].update(schema_privileges=[privilege.lower()]))
            expect("ACCOUNT_PRIVILEGE_EXCESSIVE", V3.verify_migration_receipt, rehearsal.out,
                   rehearsal.world.key, owner_uid=OWNER, now=rehearsal.now, contract=rehearsal.contract)


def test_application_grant_scope_requires_exact_schema_object():
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        rehearsal.state["app"] = ["GRANT USAGE ON *.* TO `{a}`",
                                   "GRANT SELECT ON `wpcg_recovery_restore_20261115_b2c3d4e5.other`.* TO `{a}`"]
        expect("ACCOUNT_SCOPE_EXCESSIVE", rehearsal.create)


def test_definer_grant_validators_accept_the_same_exact_phase2b_grants():
    schema = CLONE_SCHEMA
    escaped = V2._grant_pattern_identifier(schema)
    account = "restore_owner@localhost"
    raw_lines = [
        "GRANT USAGE ON *.* TO `restore_owner`@`localhost`",
        f"GRANT TRIGGER ON `{escaped}`.* TO `restore_owner`@`localhost`",
        f"GRANT SELECT ON `{escaped}`.`migrations` TO `restore_owner`@`localhost`",
    ]

    def query(_policy, sql, *, database=None, **_kwargs):
        if sql.startswith("SELECT account_locked"):
            return b"Y\n"
        if sql.startswith("SHOW GRANTS FOR"):
            return ("\n".join(raw_lines) + "\n").encode()
        raise AssertionError(f"unexpected query: {sql}")

    with mock.patch.object(V2, "mysql_query", side_effect=query):
        lifecycle = V2.verify_definer_lifecycle(object(), schema, account, ["migrations"])
        report = V3.account_privilege_report(
            object(), schema, account, allow_schema_privileges=True,
            allowed_privileges=V3.DEFINER_SCHEMA_PRIVILEGES,
            allowed_table_privileges={"migrations": {"select"}})

    assert report["schema_privileges"] == ["trigger"]
    assert lifecycle["schema_privileges"] == ["TRIGGER"]
    assert lifecycle["select_tables"] == ["migrations"]


def test_definer_table_grants_are_exactly_allowlisted():
    schema = CLONE_SCHEMA
    escaped = V2._grant_pattern_identifier(schema)
    account = "restore_owner@localhost"
    raw_lines = [
        "GRANT USAGE ON *.* TO `restore_owner`@`localhost`",
        f"GRANT TRIGGER ON `{escaped}`.* TO `restore_owner`@`localhost`",
        f"GRANT SELECT, INSERT ON `{escaped}`.`migrations` TO `restore_owner`@`localhost`",
    ]
    with mock.patch.object(V2, "mysql_query", return_value=("\n".join(raw_lines) + "\n").encode()):
        expect("ACCOUNT_SCOPE_EXCESSIVE", V3.account_privilege_report, object(), schema, account,
               allow_schema_privileges=True, allowed_privileges=V3.DEFINER_SCHEMA_PRIVILEGES,
               allowed_table_privileges={"migrations": {"select"}})


def test_grant_schema_identifier_case_is_not_folded():
    schema = CLONE_SCHEMA
    escaped = V2._grant_pattern_identifier(schema).upper()
    account = "restore_owner@localhost"
    raw_lines = [
        "GRANT USAGE ON *.* TO `restore_owner`@`localhost`",
        f"GRANT TRIGGER ON `{escaped}`.* TO `restore_owner`@`localhost`",
    ]

    def query(_policy, sql, *, database=None, **_kwargs):
        if sql.startswith("SELECT account_locked"):
            return b"Y\n"
        if sql.startswith("SHOW GRANTS FOR"):
            return ("\n".join(raw_lines) + "\n").encode()
        raise AssertionError(f"unexpected query: {sql}")

    # The old v2 normalization folds identifier case; v3 must still reject a
    # grant to the distinct uppercase schema on case-sensitive MySQL.
    with mock.patch.object(V2, "mysql_query", side_effect=query):
        V2.verify_definer_lifecycle(object(), schema, account, [])
        expect("ACCOUNT_SCOPE_EXCESSIVE", V3.account_privilege_report, object(), schema, account,
               allow_schema_privileges=True, allowed_privileges=V3.DEFINER_SCHEMA_PRIVILEGES)


def test_holds_survive_interruptions_publication_errors_and_output_changes():
    for error in (KeyboardInterrupt(), RuntimeError("failure"), OSError("publication")):
        with tempfile.TemporaryDirectory() as directory:
            rehearsal = Rehearsal(directory)
            target = "_write_signed" if isinstance(error, OSError) else "schema_state"
            original = V3._write_signed
            def publish(path, *args, **kwargs):
                if Path(path) == rehearsal.out:
                    raise error
                return original(path, *args, **kwargs)
            with mock.patch.object(V3 if target == "_write_signed" else rehearsal, target,
                                   side_effect=publish if target == "_write_signed" else error):
                try:
                    rehearsal.create()
                except BaseException as caught:
                    assert caught is error
                else:
                    raise AssertionError("failure ignored")
            hold = V3._migration_failure_path(read_json(V3._auth_path(rehearsal.clone_receipt)))
            assert hold.exists()
            rehearsal.out = rehearsal.dir / "alternate-output.json"
            expect("FAILED_REHEARSAL_HOLD_PRESENT", rehearsal.create)
    with tempfile.TemporaryDirectory() as directory:
        rehearsal = Rehearsal(directory)
        V3.REHEARSAL_HOLD_ROOT = rehearsal.dir / "missing-hold-directory"
        with mock.patch.object(V2, "_mysql_identity") as query:
            expect("RECEIPT_PATH_INVALID", rehearsal.create)
            query.assert_not_called()


def test_defaults_metadata_and_deployment_identity_are_observed():
    with tempfile.TemporaryDirectory() as directory:
        world = World(directory)
        defaults = Path(world.source_policy["mysql"]["defaults_file"])
        os.chmod(defaults, 0o644)
        expect("MYSQL_DEFAULTS_METADATA_INVALID", V3._validate_defaults, world.source_policy, OWNER)
        os.chmod(defaults, 0o600)
        link = world.dir / "defaults-link"
        link.symlink_to(defaults)
        policy = copy.deepcopy(world.source_policy)
        policy["mysql"]["defaults_file"] = str(link)
        expect("MYSQL_DEFAULTS_UNAVAILABLE", V3._validate_defaults, policy, OWNER)
        defaults.write_text("!include /untrusted\n")
        expect("MYSQL_DEFAULTS_INCLUDE_FORBIDDEN", V3._validate_defaults, world.source_policy, OWNER)
        for group, expected_code in (("[mysqldump]", "MYSQL_TOOL_DEFAULTS_GROUP_FORBIDDEN"),
                                     ("[mysqldump ]arbitrary-tail", "MYSQL_DEFAULTS_GROUP_INVALID"),
                                     ("[mysql ] trailing", "MYSQL_DEFAULTS_GROUP_INVALID")):
            defaults.write_text(f"[client]\nuser=synthetic\n{group}\nuser=other\n")
            expect(expected_code, V3._validate_defaults, world.source_policy, OWNER)
        for option in ("plugin-dir=/tmp/plugins", "default-auth=cleartext", "host=attacker", "protocol=TCP",
                       "skip-triggers", "no-data"):
            defaults.write_text("[client]\nuser=synthetic\n" + option + "\n")
            expect("MYSQL_DEFAULTS_OPTION_FORBIDDEN", V3._validate_defaults, world.source_policy, OWNER)
        defaults.write_text("[client]\nuser=other\n")
        expect("MYSQL_DEFAULTS_ACCOUNT_MISMATCH", V3._validate_defaults, world.source_policy, OWNER)
        defaults.write_text("[broken\nuser=synthetic\n")
        expect("MYSQL_DEFAULTS_GROUP_INVALID", V3._validate_defaults, world.source_policy, OWNER)
        defaults.write_text("[client]\nuser=" + BACKUP_ACCOUNT.split("@", 1)[0] + "\n")
        V3.observe_deployment(world.source_policy, owner_uid=OWNER)
        active = world.dir / "current"
        release = world.dir / ("d" * 40)
        intermediate = world.dir / "release-pointer"
        intermediate.symlink_to(release)
        active.unlink()
        active.symlink_to(intermediate)
        expect("ACTIVE_RELEASE_MISMATCH", V3.observe_deployment, world.source_policy, owner_uid=OWNER)
        active.unlink()
        active.symlink_to(release)
        receipt = read_json(world.dir / "deployment.json")
        write_json(world.dir / "deployment.json", receipt | {"application_sha": "e" * 40})
        expect("ACTIVE_RELEASE_MISMATCH", V3.observe_deployment, world.source_policy, owner_uid=OWNER)
        write_json(world.dir / "deployment.json", receipt | {"result": "FAIL"})
        expect("DEPLOYMENT_RECEIPT_INVALID", V3.observe_deployment, world.source_policy, owner_uid=OWNER)
        write_json(world.dir / "deployment.json", receipt)
        (world.dir / "current").unlink()
        other = world.dir / ("e" * 40)
        other.mkdir()
        (world.dir / "current").symlink_to(other)
        expect("ACTIVE_RELEASE_MISMATCH", V3.observe_deployment, world.source_policy, owner_uid=OWNER)


def test_controlled_capture_failure_never_signs_partial_output():
    for failure in (V3.RestoreRefused("CAPTURE_PROCESS_FAILED"), KeyboardInterrupt()):
        with tempfile.TemporaryDirectory() as directory:
            world = World(directory)
            world.archive_path.unlink()
            def fail(argv, consume, **kwargs):
                consume(b"partial SQL")
                raise failure
            with mock.patch.object(V3, "_bounded_process", side_effect=fail):
                try:
                    V3.capture(world.source_policy_path, world.archive_path, "synthetic-backup-v1",
                               world.reference_path, world.record_path, world.key_path,
                               owner_uid=OWNER, observe=observe_ok)
                except BaseException as caught:
                    assert caught is failure
                else:
                    raise AssertionError("failed capture signed")
            assert not world.record_path.exists()
            assert world.archive_path.exists()
            assert world.archive_path.stat().st_mode & 0o777 == 0o600
            expect("RECEIPT_ALREADY_EXISTS", V3.capture, world.source_policy_path, world.archive_path,
                   "synthetic-backup-v1", world.reference_path, world.record_path, world.key_path,
                   owner_uid=OWNER, observe=observe_ok)


def test_bounded_capture_process_status_size_and_diagnostics():
    output = []
    V3._bounded_process([sys.executable, "-c", "print('dump')"], output.append, maximum=100)
    assert b"".join(output) == b"dump\n"
    expect("CAPTURE_PROCESS_FAILED", V3._bounded_process,
           [sys.executable, "-c", "print('partial'); raise SystemExit(3)"], output.append, maximum=100)
    expect("CAPTURE_PROCESS_SIZE_LIMIT", V3._bounded_process,
           [sys.executable, "-c", "print('x'*1000)"], output.append, maximum=100)
    expect("CAPTURE_PROCESS_DIAGNOSTIC_LIMIT", V3._bounded_process,
           [sys.executable, "-c", "import sys; sys.stderr.write('x'*20000)"], output.append, maximum=100)
    expect("CAPTURE_PROCESS_TIMEOUT", V3._bounded_process,
           [sys.executable, "-c", "import time; time.sleep(1)"], output.append, maximum=100, timeout=.01)


def main():
    tests = (
        test_phase2b_rejects_every_trigger_field_and_action_tamper,
        test_application_privileges_are_allowlisted_online_and_offline,
        test_holds_survive_interruptions_publication_errors_and_output_changes,
        test_defaults_metadata_and_deployment_identity_are_observed,
        test_bounded_capture_process_status_size_and_diagnostics,
        test_controlled_capture_failure_never_signs_partial_output,
        test_signing_key_must_be_protected_strong_and_bounded,
        test_signature_binds_body_purpose_and_key,
        test_capture_record_binds_identity_without_raw_uuid_or_secrets,
        test_capture_refuses_identity_drift_and_wrong_identity,
        test_source_identity_uses_information_schema_default_collation_column,
        test_capture_adds_audited_mysql84_selector_and_audits_before_signing,
        test_capture_refuses_stale_oversized_or_untrusted_archives_and_begin_records,
        test_verify_capture_rejects_stale_expired_incomplete_and_oversized_records,
        test_pre_bootstrap_state_is_explicit_and_cannot_be_restored_as_a_post_bootstrap_backup,
        test_config_reference_is_secret_safe,
        test_capture_to_manifest_binding_rejects_every_mismatch,
        test_authenticated_restore_chains_receipts_and_cleanup,
        test_untrusted_capture_stops_before_any_database_access,
        test_failure_after_restore_removes_the_schema_or_leaves_an_explicit_hold,
        test_inputs_changed_during_restore_never_leave_pass_evidence,
        test_migration_receipt_is_machine_checkable_and_binds_the_whole_chain,
        test_offline_verifier_rejects_internally_inconsistent_but_correctly_signed_receipts,
        test_rehearsal_refuses_incomplete_unknown_or_regressed_ledgers,
        test_baseline_must_remain_unchanged_and_clone_must_share_the_recovery_point,
        test_rehearsal_refuses_untrusted_stale_or_mismatched_inputs,
        test_checkpoints_must_match_the_exact_pending_sequence,
        test_candidate_migration_inventory_is_bounded_and_rejects_unsafe_files,
        test_phase2b_pair_requires_pinned_hashes_and_the_complete_definer_trigger_inventory,
        test_least_privilege_account_observation_rejects_excess_and_open_sessions,
        test_destination_identity_and_unexpected_objects_fail_closed,
        test_command_line_requires_root_and_reports_fixed_codes_only,
        test_source_has_no_shell_execution_secret_argv_or_remote_code_surface,
        test_candidate_store_rejects_lazy_fetch_config_hardlinks_and_corrupt_objects,
        test_application_grant_scope_requires_exact_schema_object,
        test_definer_grant_validators_accept_the_same_exact_phase2b_grants,
        test_definer_table_grants_are_exactly_allowlisted,
        test_grant_schema_identifier_case_is_not_folded,
    )
    for test in tests:
        test()
    print(f"AUTHENTICATED_RESTORE_V3_TESTS={len(tests)}/PASS")


if __name__ == "__main__":
    main()
