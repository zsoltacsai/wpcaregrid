"""Deterministic tests for the GO-6.2 standalone production inventory helper.

The suite is hermetic: every filesystem fixture lives under a temporary root, every
subprocess result is injected, and no MySQL connection, credential or privileged
command is ever attempted.  It covers the happy-path report, symlink/traversal
rejection, output sanitisation, unavailable evidence, backup integrity, restore
evidence, lock probing, argument/root/audit guards and package pin consistency.

Run with:  python3 -B -m unittest scripts/tests/test_go62_production_inventory.py
"""

from __future__ import annotations

import gzip
import hashlib
import importlib.util
import json
import os
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest import mock

sys.dont_write_bytecode = True

SCRIPTS = Path(__file__).resolve().parents[1]
PACKAGE = SCRIPTS / "production-g1-inventory"
HELPER = PACKAGE / "go62-inventory.py"
MANIFEST = PACKAGE / "go62-inventory-manifest.json"
SUMS = PACKAGE / "SHA256SUMS"

SPEC = importlib.util.spec_from_file_location("go62_inventory", HELPER)
go = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = go
SPEC.loader.exec_module(go)

TX_SPEC = importlib.util.spec_from_file_location("deployment_transaction_v2", SCRIPTS / "deployment_transaction_v2.py")
tx = importlib.util.module_from_spec(TX_SPEC)
sys.modules[TX_SPEC.name] = tx
TX_SPEC.loader.exec_module(tx)
tx.PROFILE = "production"
tx.DATABASE = "wpcg_prod_app"

RELEASE_SHA = "1" * 40
BASE_SHA = "2" * 40
BACKUP_NAME = "production-" + RELEASE_SHA + "-20261112201500-" + "5" * 8 + ".sql.gz"
TEST_TIME_NS = 1763000000000000000
CHECKPOINT = {
    "backup_sha256": "6" * 64,
    "target_schema_sha256": "7" * 64,
    "target_ledger_sha256": "8" * 64,
    "target_data_sha256": "9" * 64,
    "restored_schema_sha256": "a" * 64,
    "restored_ledger_sha256": "b" * 64,
    "restored_data_sha256": "c" * 64,
    "restore_mysql_uuid_sha256": "d" * 64,
    "restore_receipt_sha256": "e" * 64,
}


def receipt_for(checkpoint):
    payload = {key: checkpoint[key] for key in go.RESTORE_CHECKPOINT_KEYS - {"restore_receipt_sha256"}}
    return tx.restore_receipt_sha256(payload)


def producer_transaction_id(journal):
    identity = tx.TransactionIdentity(
        application_sha=journal["application_sha"], previous_sha=journal["previous_sha"],
        artifact_sha256=journal["artifact_sha256"], backup_sha256=journal["backup_sha256"],
        backup_filename=journal["backup_filename"], mysql_uuid_sha256=journal["mysql_uuid_sha256"],
        migrations=tuple(journal["migrations"]),
    )
    return tx.DatabaseReleaseTransaction._identity_id(identity)


def journal_for(app_sha=RELEASE_SHA, *, backup_sha="6" * 64, checkpoint=None,
                state="COMPLETE", previous_sha=BASE_SHA):
    journal = {
        "schema": 4, "profile": "production", "database": "wpcg_prod_app",
        "transaction_id": "0" * 32, "application_sha": app_sha, "previous_sha": previous_sha,
        "artifact_sha256": "a" * 64, "backup_sha256": backup_sha,
        "backup_filename": BACKUP_NAME, "mysql_uuid_sha256": "b" * 64,
        "migrations": ["2026_01_01_000001_fixture_migration"], "state": state,
        "evidence": ({"RESTORE_REHEARSAL_VERIFIED": checkpoint} if checkpoint is not None else {}),
        "migration_cursor": 0, "migration_before": None, "migration_started_at": None,
        "updated_at": TEST_TIME_NS,
    }
    journal["transaction_id"] = producer_transaction_id(journal)
    return journal
UNIT_OUTPUT = {
    go.MYSQL_UNIT: ("active", "running", 4242),
    go.FPM_UNIT: ("active", "running", 4243),
    go.NGINX_UNIT: ("active", "running", 4244),
    go.G1_UNITS["wpcaregrid-production"]: ("inactive", "dead", 0),
    go.G1_UNITS["wpcaregrid-production-restore"]: ("inactive", "dead", 0),
}
MYSQL_DEFAULTS = "\n".join((
    "--binlog-format=ROW",
    "--binlog-row-image=FULL",
    "--sync-binlog=1",
    "--binlog-expire-logs-seconds=604800",
    "--expire-logs-days=7",
    "--log-bin=/var/lib/mysql-wpcaregrid-production/mysql-bin",
    "--log-bin-trust-function-creators=OFF",
    "--read-only=ON",
    "--super-read-only=ON",
    "--local-infile=OFF",
    "--skip-grant-tables=OFF",
    "--sql-mode=STRICT_TRANS_TABLES,NO_ZERO_DATE",
))


class FakeRun:
    """Fixed subprocess double: records argv and returns bounded fixture output."""

    def __init__(self, *, fail=False, mysql_defaults=MYSQL_DEFAULTS, mysql_defaults_by_suffix=None,
                 journal="", unit_output=None):
        self.calls = []
        self.fail = fail
        self.mysql_defaults = mysql_defaults
        self.mysql_defaults_by_suffix = mysql_defaults_by_suffix or {}
        self.journal = journal
        self.unit_output = unit_output or UNIT_OUTPUT

    def __call__(self, argv):
        self.calls.append(tuple(argv))
        if self.fail:
            raise go.Unavailable("command_unavailable")
        if argv[0] == "/usr/bin/systemctl":
            unit = argv[2]
            active, sub, pid = self.unit_output.get(unit, ("inactive", "dead", 0))
            body = "\n".join((
                "LoadState=loaded",
                f"ActiveState={active}",
                f"SubState={sub}",
                "UnitFileState=enabled",
                f"MainPID={pid}",
                "FragmentPath=/usr/lib/systemd/system/mysql@.service",
                "DropInPaths=" + ("/etc/systemd/system/" + unit + ".d/90-wpcaregrid-g1.conf"
                                   if unit in go.G1_UNITS.values() else ""),
                "Result=success",
                "ExecMainStartTimestamp=Thu 2026-11-12 20:00:00 UTC",
            )) + "\n"
            return 0, body.encode(), b""
        if argv[0] == "/usr/bin/php":
            return 0, b"PHP 8.4.10 (cli) (built: Oct 1 2026)\n", b""
        if argv[0] == "/usr/sbin/php-fpm8.4":
            return 0, b"PHP 8.4.10 (fpm-fcgi)\n", b""
        if argv[0] == "/usr/sbin/nginx":
            return 0, b"", b"nginx version: nginx/1.26.2\n"
        if argv[0] == "/usr/sbin/mysqld":
            return 0, b"/usr/sbin/mysqld  Ver 8.4.3 for Linux on x86_64\n", b""
        if argv[0] == "/usr/bin/journalctl":
            return 0, self.journal.encode(), b""
        if argv[0] == "/usr/bin/my_print_defaults":
            suffix = next((arg.split("=", 1)[1] for arg in argv
                           if arg.startswith("--defaults-group-suffix=")), "")
            data = self.mysql_defaults_by_suffix.get(suffix, self.mysql_defaults)
            return 0, data.encode(), b""
        raise AssertionError("unexpected argv: %r" % (argv,))


class Fixture:
    """Builds a temporary root that looks like the production host."""

    def __init__(self, root: Path):
        self.root = root
        self.uid = os.geteuid()
        self.gid = os.getegid()
        self.audit = []
        self.run = FakeRun()

    def path(self, absolute):
        return self.root / absolute.lstrip("/")

    def write(self, absolute, data, mode=0o644):
        target = self.path(absolute)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        os.chmod(target, mode)
        return target

    def directory(self, absolute, mode=0o700):
        target = self.path(absolute)
        target.mkdir(parents=True, exist_ok=True)
        os.chmod(target, mode)
        return target

    def build(self, *, with_evidence=True):
        self.write("/usr/lib/os-release",
                   b'ID=arch\nVERSION_ID=20261112\nPRETTY_NAME="Arch Linux"\nVERSION_CODENAME=rolling\n')
        for _name, absolute, mode in go.G1_ARTIFACTS:
            self.write(absolute, b"# pinned fixture artifact\n", mode)
        self.write("/sys/kernel/security/apparmor/profiles",
                   b"wpcaregrid-mysql-production (enforce)\n"
                   b"wpcaregrid-mysql-production-restore (enforce)\n"
                   b"/usr/sbin/mysqld (enforce)\n")
        self.write("/proc/locks", b"", 0o644)

        releases = self.directory(go.PRODUCTION_RELEASES, 0o755)
        release = releases / RELEASE_SHA
        release.mkdir(mode=0o755)
        os.chmod(release, 0o755)
        manifest = {
            "schema": 1,
            "profile": "production",
            "application_sha": RELEASE_SHA,
            "base_application_sha": BASE_SHA,
            "artifact_sha256": "f" * 64,
            "files_sha256": "0" * 64,
            "database_scope": "unchanged",
            "database_migrations": [],
            "php_minimum": "8.4",
            "helper_version": "1.0.0",
            "helper_contract": "1",
        }
        (release / "release-manifest.json").write_text(json.dumps(manifest), encoding="utf-8")
        current = self.path(go.PRODUCTION_CURRENT)
        current.parent.mkdir(parents=True, exist_ok=True)
        os.symlink(os.path.join("releases", RELEASE_SHA), current)

        self.directory(go.PRODUCTION_STATE, 0o700)
        self.directory(go.PRODUCTION_BACKUPS, 0o700)
        self.directory("/var/lib/mysql-wpcaregrid-production-restore", 0o750)
        (self.path("/var/lib/mysql-wpcaregrid-production-restore") / "mysql").write_bytes(b"")
        if with_evidence:
            backup = gzip.compress(b"CREATE TABLE fixture (id int);\n")
            self.write(go.PRODUCTION_BACKUPS + "/" + BACKUP_NAME, backup, 0o600)
            digest = hashlib.sha256(backup).hexdigest()
            checkpoint = dict(CHECKPOINT)
            checkpoint["backup_sha256"] = digest
            checkpoint["restore_receipt_sha256"] = receipt_for(checkpoint)
            journal = journal_for(backup_sha=digest, checkpoint=checkpoint)
            self.txid = journal["transaction_id"]
            self.write(go.PRODUCTION_STATE + "/db-release-" + self.txid + ".json",
                       json.dumps(journal).encode(), 0o600)
        return self

    def execute(self, *, argv=None, euid=0, run=None, audit=None):
        captured = self.audit
        return go.execute(
            [] if argv is None else argv,
            run=self.run if run is None else run,
            euid=euid,
            root=str(self.root),
            root_uid=self.uid,
            root_gid=self.gid,
            audit=captured.append if audit is None else audit,
        )


class InventoryTestCase(unittest.TestCase):
    def setUp(self):
        self._temp = tempfile.TemporaryDirectory(prefix="wpcg-go62-test-")
        self.fixture = Fixture(Path(self._temp.name))
        self.addCleanup(self._temp.cleanup)

    def report(self, **kwargs):
        report, code = self.fixture.execute(**kwargs)
        self.assertEqual(code, report["exit_code"])
        json.dumps(report)  # the report must always be serialisable
        return report


class CompleteFixtureTest(InventoryTestCase):
    def test_collection_does_not_change_any_inspected_file_or_directory(self):
        self.fixture.build()

        def snapshot():
            result = []
            for path in sorted(self.fixture.root.rglob("*")):
                info = path.lstat()
                if path.is_symlink():
                    value = os.readlink(path)
                elif path.is_file():
                    value = hashlib.sha256(path.read_bytes()).hexdigest()
                else:
                    value = None
                result.append((str(path.relative_to(self.fixture.root)), info.st_mode, value))
            return result

        before = snapshot()
        self.report()
        self.assertEqual(snapshot(), before)

    def test_inventory_reads_do_not_update_inspected_file_atime(self):
        self.fixture.build()
        target = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        before = target.stat().st_atime_ns
        directories = [self.fixture.path(path) for path in (
            go.PRODUCTION_STATE, go.PRODUCTION_BACKUPS, go.PRODUCTION_RELEASES,
        )]
        before_dirs = [path.stat().st_atime_ns for path in directories]
        self.report()
        self.assertEqual(target.stat().st_atime_ns, before)
        self.assertEqual([path.stat().st_atime_ns for path in directories], before_dirs)

    def test_all_obtainable_evidence_passes_but_privileges_keep_overall_unknown(self):
        self.fixture.build()
        report = self.report()
        sections = report["sections"]
        self.assertEqual(report["schema"], 1)
        self.assertEqual(report["tool"], "go62-production-inventory")
        for name in ("runtime", "g1_systemd", "g1_artifacts", "apparmor",
                     "release", "journals", "backups", "restore", "locks"):
            self.assertEqual(sections[name]["status"], "PASS", name)
        self.assertEqual(report["audit"]["status"], "UNKNOWN")
        self.assertEqual(report["audit"]["persistent"], "ATTEMPTED_UNVERIFIED")
        self.assertEqual(sections["mysql"]["config_status"], "PASS")
        self.assertEqual(sections["mysql"]["privileges"]["status"], "UNKNOWN")
        self.assertEqual(sections["mysql"]["status"], "UNKNOWN")
        mysql_calls = [call for call in self.fixture.run.calls if call[0] == "/usr/bin/my_print_defaults"]
        self.assertEqual([next(arg for arg in call if arg.startswith("--defaults-group-suffix=")) for call in mysql_calls], [
            "--defaults-group-suffix=@wpcaregrid-production",
            "--defaults-group-suffix=@wpcaregrid-production-restore",
        ])
        # Privileged database facts are out of authorised scope, so the inventory can
        # never certify PASS; it reports UNKNOWN (exit 4) rather than inventing proof.
        self.assertEqual(report["overall"], "UNKNOWN")
        self.assertEqual(report["exit_code"], 4)

    def test_runtime_reports_versions_units_and_no_g1_instance_active(self):
        self.fixture.build()
        runtime = self.report()["sections"]["runtime"]
        self.assertEqual(runtime["php_cli"]["version"], "8.4.10")
        self.assertEqual(runtime["php_fpm"]["version"], "8.4.10")
        self.assertEqual(runtime["nginx"]["version"], "1.26.2")
        self.assertEqual(runtime["mysql"]["version"], "8.4.3")
        g1 = self.report()["sections"]["g1_systemd"]
        self.assertTrue(g1["both_g1_units_inactive"])
        self.assertEqual(g1["g1_units_active"], [])
        self.assertEqual(g1["mysql_service"]["main_pid"], 4242)
        self.assertEqual(
            g1["units"]["wpcaregrid-production"]["drop_in_paths"],
            ["/etc/systemd/system/mysql@wpcaregrid-production.service.d/90-wpcaregrid-g1.conf"],
        )
        apparmor = self.report()["sections"]["apparmor"]
        self.assertEqual(apparmor["profiles"][go.VENDOR_PROFILE]["status"], "PASS")

    def test_artifacts_report_owner_mode_size_and_hash(self):
        self.fixture.build()
        artifacts = self.report()["sections"]["g1_artifacts"]
        self.assertEqual(artifacts["expected_owner"],
                         f"{self.fixture.uid}:{self.fixture.gid}")
        for name, _path, mode in go.G1_ARTIFACTS:
            item = artifacts["artifacts"][name]
            self.assertEqual(item["status"], "PASS")
            self.assertTrue(item["owner_ok"])
            self.assertTrue(item["mode_ok"])
            self.assertEqual(item["mode"], "0o%04o" % mode)
            self.assertRegex(item["sha256"], r"^[0-9a-f]{64}$")

    def test_release_reports_active_sha_and_manifest_identity(self):
        self.fixture.build()
        release = self.report()["sections"]["release"]
        self.assertEqual(release["status"], "PASS")
        self.assertEqual(release["application_sha"], RELEASE_SHA)
        self.assertEqual(release["release_path"], "releases/" + RELEASE_SHA)
        self.assertEqual(release["database_scope"], "unchanged")
        self.assertEqual(release["release_count"], 1)

    def test_backup_integrity_never_claims_restore_proof(self):
        self.fixture.build()
        backups = self.report()["sections"]["backups"]
        self.assertEqual(backups["status"], "PASS")
        entry = backups["backups"][0]
        self.assertEqual(entry["integrity"], "PASS")
        self.assertEqual(entry["evidence_match"], "PASS")
        self.assertEqual(entry["restore_proof"], "NOT_PROVEN")
        self.assertTrue(backups["metadata_only_backup_does_not_prove_restore"])

    def test_restore_reports_recorded_rehearsal_evidence(self):
        self.fixture.build()
        restore = self.report()["sections"]["restore"]
        self.assertEqual(restore["status"], "PASS")
        self.assertEqual(restore["readiness"], "EVIDENCE_RECORDED")
        self.assertTrue(restore["restore_checkpoint_present"])
        self.assertEqual(restore["live_restore_validation"], "NOT_PERFORMED")
        self.assertEqual(restore["restore_proof"], "NOT_PROVEN")
        self.assertEqual(restore["latest_journal"]["updated_at"], TEST_TIME_NS)

    def test_inventory_transaction_id_matches_production_writer(self):
        self.fixture.build()
        journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        journal = json.loads(journal_path.read_text(encoding="utf-8"))
        self.assertEqual(journal["transaction_id"], producer_transaction_id(journal))
        self.assertEqual(journal["transaction_id"], go._transaction_id(journal))

    def test_locks_absent_is_reported_free(self):
        self.fixture.build()
        locks = self.report()["sections"]["locks"]
        self.assertEqual(locks["status"], "PASS")
        self.assertEqual(locks["locks"]["deploy_lock"]["state"], "ABSENT")
        self.assertEqual(locks["locks"]["backup_lock"]["state"], "NOT_SEPARATELY_DEFINED")

    def test_no_mysql_client_connection_or_shell_call_is_made(self):
        self.fixture.build()
        self.report()
        for argv in self.fixture.run.calls:
            self.assertIn(argv[0], go.ALLOWED_COMMANDS)
            self.assertNotIn(argv[0].rsplit("/", 1)[-1],
                             {"mysql", "mysqladmin", "mysqlsh", "mariadb"})
            self.assertNotIn("bash", argv)
            self.assertNotIn("sh", argv)
        source = HELPER.read_text(encoding="utf-8")
        self.assertNotIn("shell=True", source)
        self.assertNotIn("os.system", source)
        self.assertNotIn(".env", source)
        self.assertNotIn("deploy-priv", source)


class RedactionAndInjectionTest(InventoryTestCase):
    def test_artifact_symlink_is_rejected(self):
        self.fixture.build()
        target = self.fixture.path(go.G1_ARTIFACTS[0][1])
        target.unlink()
        os.symlink("/etc/shadow", target)
        artifacts = self.report()["sections"]["g1_artifacts"]
        item = artifacts["artifacts"]["helper"]
        self.assertEqual(item["status"], "FAIL")
        self.assertEqual(item["reason"], "symlink_rejected")

    def test_release_symlink_traversal_is_rejected(self):
        self.fixture.build()
        current = self.fixture.path(go.PRODUCTION_CURRENT)
        current.unlink()
        os.symlink("releases/../../etc", current)
        release = self.report()["sections"]["release"]
        self.assertEqual(release["status"], "FAIL")
        self.assertEqual(release["reason"], "active_release_target_invalid")

    def test_release_manifest_symlink_is_rejected(self):
        self.fixture.build()
        manifest = self.fixture.path(go.PRODUCTION_RELEASES) / RELEASE_SHA / "release-manifest.json"
        manifest.unlink()
        os.symlink("/etc/passwd", manifest)
        release = self.report()["sections"]["release"]
        self.assertEqual(release["status"], "FAIL")
        self.assertEqual(release["reason"], "file_symlink_rejected")

    def test_journal_filename_and_backup_path_are_redacted(self):
        self.fixture.build()
        journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        journal = json.loads(journal_path.read_text(encoding="utf-8"))
        journal["backup_filename"] = "../../etc/shadow"
        journal.pop("evidence")
        journal_path.write_text(json.dumps(journal), encoding="utf-8")
        backups = self.report()["sections"]["backups"]
        self.assertEqual(backups["backups"][0]["evidence_match"], "UNKNOWN")

    def test_mysql_option_paths_and_unsafe_values_are_redacted(self):
        self.fixture.build()
        self.fixture.run.mysql_defaults = "\n".join((
            "--binlog-format=STATEMENT",
            "--log-bin=/root/private/mysql-bin",
            "--log-bin-trust-function-creators=ON",
            "--sql-mode=STRICT,EVIL;DROP",
        ))
        mysql = self.report()["sections"]["mysql"]
        settings = mysql["instances"]["production"]["settings"]
        self.assertEqual(settings["log_bin"]["value"], "ON")
        self.assertNotIn("/root", json.dumps(mysql))
        self.assertEqual(settings["sql_mode"]["value"], "REDACTED")
        self.assertEqual(settings["binlog_format"]["value"], "STATEMENT")
        self.assertIn("binlog_format_statement", mysql["instances"]["production"]["unsafe"])
        self.assertIn("log_bin_trust_function_creators_on", mysql["instances"]["production"]["unsafe"])
        self.assertEqual(mysql["config_status"], "FAIL")

    def test_mysql_prefixed_unsafe_options_are_normalized(self):
        self.fixture.build()
        self.fixture.run.mysql_defaults = "\n".join((
            "--loose-local-infile=1", "--loose-skip-grant-tables",
            "--binlog-format=ROW", "--log-bin=/var/lib/mysql/mysql-bin",
            "--log-bin-trust-function-creators=OFF",
        ))
        instance = self.report()["sections"]["mysql"]["instances"]["production"]
        self.assertEqual(instance["settings"]["local_infile"]["value"], "ON")
        self.assertEqual(instance["settings"]["skip_grant_tables"]["value"], "ON")
        self.assertIn("local_infile_on", instance["unsafe"])
        self.assertIn("skip_grant_tables_on", instance["unsafe"])
        self.assertEqual(instance["config_status"], "FAIL")

    def test_mysql_all_inspected_boolean_aliases_are_conservative(self):
        self.fixture.build()
        self.fixture.run.mysql_defaults = "\n".join((
            "--binlog-format=ROW", "--log-bin=/var/lib/mysql/mysql-bin",
            "--log-bin-trust-function-creators=OFF", "--local-infile=OFF",
            "--skip-grant-tables=OFF", "--enable-skip-grant-tables",
        ))
        instance = self.report()["sections"]["mysql"]["instances"]["production"]
        self.assertEqual(instance["settings"]["skip_grant_tables"]["value"], "ON")
        self.assertEqual(instance["config_status"], "FAIL")

    def test_unparseable_watched_boolean_makes_mysql_unknown(self):
        self.fixture.build()
        self.fixture.run.mysql_defaults = "\n".join((
            "--binlog-format=ROW", "--log-bin=/var/lib/mysql/mysql-bin",
            "--log-bin-trust-function-creators=OFF", "--local-infile=maybe",
            "--skip-grant-tables=OFF",
        ))
        instance = self.report()["sections"]["mysql"]["instances"]["production"]
        self.assertEqual(instance["settings"]["local_infile"]["value"], "UNKNOWN")
        self.assertEqual(instance["config_status"], "UNKNOWN")

    def test_maximum_prefix_does_not_masquerade_as_watched_setting(self):
        self.fixture.build()
        self.fixture.run.mysql_defaults = "\n".join((
            "--binlog-format=ROW", "--maximum-log-bin-trust-function-creators=ON",
        ))
        instance = self.report()["sections"]["mysql"]["instances"]["production"]
        self.assertEqual(instance["settings"]["log_bin_trust_function_creators"]["value"], "UNKNOWN")
        self.assertEqual(instance["config_status"], "UNKNOWN")

    def test_mysql_configs_are_classified_per_instance_not_shared_defaults(self):
        self.fixture.build()
        self.fixture.run.mysql_defaults_by_suffix = {
            "@wpcaregrid-production": "\n".join(("--log-bin=/var/lib/mysql/prod-bin", "--log-bin-trust-function-creators=OFF", "--binlog-format=ROW", "--local-infile=OFF", "--skip-grant-tables=OFF")),
            "@wpcaregrid-production-restore": "\n".join(("--skip-log-bin", "--log-bin-trust-function-creators=OFF", "--binlog-format=ROW", "--local-infile=OFF", "--skip-grant-tables=OFF")),
        }
        mysql = self.report()["sections"]["mysql"]
        self.assertEqual(mysql["instances"]["production"]["config_status"], "PASS")
        self.assertEqual(mysql["instances"]["restore"]["config_status"], "FAIL")
        self.assertEqual(mysql["instances"]["restore"]["settings"]["log_bin"]["value"], "OFF")

    def test_skip_log_bin_is_reported_as_disabled(self):
        self.fixture.build()
        self.fixture.run.mysql_defaults = "--skip-log-bin\n--log-bin-trust-function-creators=OFF\n--binlog-format=ROW\n"
        settings = self.report()["sections"]["mysql"]["instances"]["production"]["settings"]
        self.assertEqual(settings["log_bin"], {"configured": True, "value": "OFF"})

    def test_log_bin_basename_named_off_still_means_binary_logging_on(self):
        self.fixture.build()
        self.fixture.run.mysql_defaults = "--log-bin=OFF\n--log-bin-trust-function-creators=OFF\n--binlog-format=row\n"
        mysql = self.report()["sections"]["mysql"]
        self.assertEqual(mysql["instances"]["production"]["settings"]["log_bin"], {"configured": True, "value": "ON"})
        self.assertEqual(mysql["instances"]["production"]["settings"]["binlog_format"]["value"], "ROW")
        self.assertNotIn("binary_logging_off", mysql["instances"]["production"]["unsafe"])

    def test_missing_binary_log_options_are_unknown_not_pass(self):
        self.fixture.build()
        self.fixture.run.mysql_defaults = "--sql-mode=STRICT_TRANS_TABLES\n"
        mysql = self.report()["sections"]["mysql"]
        self.assertEqual(mysql["config_status"], "UNKNOWN")
        self.assertEqual(mysql["instances"]["production"]["settings"]["log_bin"]["value"], "UNKNOWN")

    def test_apparmor_denials_are_sanitised_and_counted(self):
        self.fixture.build()
        denials = (
            'apparmor="DENIED" profile="wpcaregrid-mysql-production" operation="open" '
            'denied_mask="r" requested_mask="r" name="/root/.ssh/id_rsa" comm="mysqld"\n'
            'apparmor="DENIED" profile="wpcaregrid-mysql-production" operation="open" '
            'denied_mask="w" requested_mask="w" name="/var/tmp/wpcaregrid-production" comm="mysqld"\n'
            'apparmor="DENIED" profile="wpcaregrid-mysql-production//child" operation="open" '
            'denied_mask="r" requested_mask="r" name="/etc/mysql/my.cnf" comm="mysqld"\n'
            'apparmor="DENIED" profile="unrelated" operation="open" denied_mask="r" '
            'requested_mask="r" name="/etc/shadow" comm="other"\n'
        )
        self.fixture.run.journal = denials
        apparmor = self.report()["sections"]["apparmor"]
        self.assertEqual(apparmor["status"], "FAIL")
        paths = {entry["path"] for entry in apparmor["denials"]["entries"]}
        self.assertIn("REDACTED", paths)
        self.assertIn("g1_production_tmpdir", paths)
        self.assertIn("mysql_base_config_link", paths)
        self.assertNotIn("/root/.ssh/id_rsa", json.dumps(apparmor))
        self.assertNotIn("unrelated", json.dumps(apparmor))
        self.assertTrue(apparmor["denials"]["sanitized_paths"])
        self.assertEqual(apparmor["denials"]["ignored_other_profile_denials"], 1)

    def test_unrelated_apparmor_denials_do_not_fail_g1_status(self):
        self.fixture.build()
        self.fixture.run.journal = (
            'apparmor="DENIED" profile="snap.foo" operation="open" denied_mask="r" '
            'requested_mask="r" name="/etc/shadow" comm="foo"\\n'
        )
        apparmor = self.report()["sections"]["apparmor"]
        self.assertEqual(apparmor["status"], "PASS")
        self.assertEqual(apparmor["denials"]["entries"], [])
        self.assertEqual(apparmor["denials"]["ignored_other_profile_denials"], 1)

    def test_safe_helpers_reject_untrusted_text(self):
        self.assertEqual(go._safe_token("plain-token_1"), "plain-token_1")
        self.assertEqual(go._safe_token("two words"), "REDACTED")
        self.assertEqual(go._safe_token("line\nbreak"), "REDACTED")
        self.assertEqual(go._safe_text("<script>"), "REDACTED")
        self.assertEqual(go._safe_system_path("/etc/passwd;rm -rf /"), "REDACTED")
        self.assertEqual(go._safe_system_path("/etc/../root"), "REDACTED")
        self.assertEqual(go._safe_system_path("/etc/systemd/system/mysql@x.service.d/override.conf"),
                         "/etc/systemd/system/mysql@x.service.d/override.conf")
        self.assertEqual(go._safe_mode("0777;rm"), "REDACTED")

    def test_command_allowlist_rejects_shell_and_arguments_injection(self):
        with self.assertRaises(go.Stop) as caught:
            go._default_run(("/bin/sh", "-c", "id"))
        self.assertEqual(caught.exception.code, "command_not_allowed")
        with self.assertRaises(go.Stop) as caught:
            go._default_run(("/usr/bin/systemctl", "status\x00;id"))
        self.assertEqual(caught.exception.code, "command_argument_invalid")
        with self.assertRaises(go.Stop) as caught:
            go._default_run(("/usr/bin/systemctl", "status\nid"))
        self.assertEqual(caught.exception.code, "command_argument_invalid")
        self.assertNotIn("/usr/bin/mysql", go.ALLOWED_COMMANDS)
        self.assertNotIn("/usr/bin/bash", go.ALLOWED_COMMANDS)


class UnavailableEvidenceTest(InventoryTestCase):
    def test_empty_root_reports_unavailable_and_exit_five(self):
        report = self.report(run=FakeRun(fail=True))
        self.assertEqual(report["overall"], "UNKNOWN")
        self.assertEqual(report["exit_code"], 4)
        for name in ("runtime", "g1_systemd", "g1_artifacts", "apparmor",
                     "mysql", "release", "journals", "backups", "restore"):
            self.assertEqual(report["sections"][name]["status"], "UNAVAILABLE", name)

    def test_missing_state_and_backup_directories_do_not_invent_evidence(self):
        self.fixture.build()
        import shutil
        shutil.rmtree(self.fixture.path(go.PRODUCTION_STATE))
        shutil.rmtree(self.fixture.path(go.PRODUCTION_BACKUPS))
        sections = self.report()["sections"]
        self.assertEqual(sections["backups"]["status"], "UNAVAILABLE")
        self.assertEqual(sections["backups"]["reason"], "backup_directory_missing")
        self.assertEqual(sections["restore"]["status"], "UNAVAILABLE")
        self.assertFalse(sections["restore"]["restore_checkpoint_present"])

    def test_metadata_only_backup_without_journal_cannot_prove_restore(self):
        self.fixture.build(with_evidence=False)
        backup = gzip.compress(b"CREATE TABLE fixture (id int);\n")
        self.fixture.write(go.PRODUCTION_BACKUPS + "/" + BACKUP_NAME, backup, 0o600)
        sections = self.report()["sections"]
        self.assertEqual(sections["backups"]["status"], "UNKNOWN")
        self.assertEqual(sections["backups"]["backups"][0]["integrity"], "PASS")
        self.assertEqual(sections["backups"]["backups"][0]["evidence_match"], "UNKNOWN")
        self.assertEqual(sections["restore"]["status"], "UNKNOWN")
        self.assertEqual(sections["restore"]["reason"], "restore_evidence_absent")
        self.assertEqual(sections["restore"]["restore_proof"], "NOT_PROVEN")

    def test_completed_journal_without_restore_checkpoint_is_invalid(self):
        self.fixture.build()
        journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        journal = json.loads(journal_path.read_text(encoding="utf-8"))
        journal["evidence"] = {}
        journal_path.write_text(json.dumps(journal), encoding="utf-8")
        restore = self.report()["sections"]["restore"]
        self.assertEqual(restore["status"], "FAIL")
        self.assertEqual(restore["reason"], "restore_evidence_invalid")
        self.assertFalse(restore["restore_checkpoint_present"])

    def test_invalid_journal_fails_closed(self):
        self.fixture.build()
        journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        journal_path.write_text("{not json", encoding="utf-8")
        sections = self.report()["sections"]
        self.assertEqual(sections["restore"]["status"], "FAIL")
        self.assertEqual(sections["restore"]["reason"], "restore_evidence_invalid")

    def test_invalid_state_directory_is_exposed_and_fails_restore(self):
        self.fixture.build()
        import shutil
        state = self.fixture.path(go.PRODUCTION_STATE)
        shutil.rmtree(state)
        state.symlink_to(self.fixture.path(go.PRODUCTION_BACKUPS))
        sections = self.report()["sections"]
        self.assertEqual(sections["journals"]["status"], "FAIL")
        self.assertEqual(sections["journals"]["reason"], "state_directory_invalid")
        self.assertEqual(sections["restore"]["status"], "FAIL")

    def test_journal_truncation_is_reported_and_restore_stays_unknown(self):
        self.fixture.build(with_evidence=False)
        state = self.fixture.path(go.PRODUCTION_STATE)
        for index in range(go.MAX_JOURNALS + 1):
            txid = f"{index:032x}"
            journal = journal_for(checkpoint=None, state="PREPARED", previous_sha=f"{index + 1:040x}")
            journal["updated_at"] = TEST_TIME_NS + index
            txid = journal["transaction_id"]
            (state / f"db-release-{txid}.json").write_text(json.dumps(journal), encoding="utf-8")
            os.chmod(state / f"db-release-{txid}.json", 0o600)
        sections = self.report()["sections"]
        self.assertEqual(sections["journals"]["journal_count"], go.MAX_JOURNALS + 1)
        self.assertTrue(sections["journals"]["truncated"])
        self.assertEqual(sections["journals"]["status"], "UNKNOWN")
        self.assertEqual(sections["restore"]["status"], "UNKNOWN")
        self.assertEqual(sections["restore"]["reason"], "restore_journal_set_truncated")

    def test_restore_checkpoint_must_match_active_release_and_backup(self):
        self.fixture.build()
        journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        journal = json.loads(journal_path.read_text(encoding="utf-8"))
        stale_backup_name = "production-" + "a" * 40 + "-20261112201500-" + "5" * 8 + ".sql.gz"
        original_backup = self.fixture.path(go.PRODUCTION_BACKUPS) / BACKUP_NAME
        original_backup.rename(self.fixture.path(go.PRODUCTION_BACKUPS) / stale_backup_name)
        journal["application_sha"] = "a" * 40
        journal["backup_filename"] = stale_backup_name
        new_id = producer_transaction_id(journal)
        journal["transaction_id"] = new_id
        new_path = journal_path.with_name("db-release-" + new_id + ".json")
        new_path.write_text(json.dumps(journal), encoding="utf-8")
        os.chmod(new_path, 0o600)
        journal_path.unlink()
        restore = self.report()["sections"]["restore"]
        self.assertEqual(restore["status"], "UNKNOWN")
        self.assertFalse(restore["restore_checkpoint_present"])
        self.assertEqual(restore["reason"], "restore_evidence_stale_or_unmatched")

    def test_restore_checkpoint_backup_sha_must_match_journal(self):
        self.fixture.build()
        journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        journal = json.loads(journal_path.read_text(encoding="utf-8"))
        journal["evidence"]["RESTORE_REHEARSAL_VERIFIED"]["backup_sha256"] = "f" * 64
        journal["evidence"]["RESTORE_REHEARSAL_VERIFIED"]["restore_receipt_sha256"] = receipt_for(
            journal["evidence"]["RESTORE_REHEARSAL_VERIFIED"])
        journal_path.write_text(json.dumps(journal), encoding="utf-8")
        os.chmod(journal_path, 0o600)
        sections = self.report()["sections"]
        self.assertEqual(sections["journals"]["status"], "FAIL")
        self.assertEqual(sections["restore"]["status"], "FAIL")
        self.assertEqual(sections["journals"]["journals"][0]["reason"], "journal_restore_checkpoint_invalid")

    def test_restore_checkpoint_does_not_qualify_if_disk_backup_changes(self):
        self.fixture.build()
        backup_path = self.fixture.path(go.PRODUCTION_BACKUPS) / BACKUP_NAME
        backup_path.write_bytes(gzip.compress(b"different valid backup payload\n"))
        os.chmod(backup_path, 0o600)
        sections = self.report()["sections"]
        self.assertEqual(sections["backups"]["backups"][0]["integrity"], "PASS")
        self.assertEqual(sections["backups"]["backups"][0]["evidence_match"], "FAIL")
        self.assertEqual(sections["restore"]["status"], "UNKNOWN")
        self.assertFalse(sections["restore"]["restore_checkpoint_present"])

    def test_restore_checkpoint_receipt_must_match_its_fields(self):
        self.fixture.build()
        journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        journal = json.loads(journal_path.read_text(encoding="utf-8"))
        journal["evidence"]["RESTORE_REHEARSAL_VERIFIED"]["target_data_sha256"] = "f" * 64
        journal_path.write_text(json.dumps(journal), encoding="utf-8")
        os.chmod(journal_path, 0o600)
        sections = self.report()["sections"]
        self.assertEqual(sections["journals"]["status"], "FAIL")
        self.assertEqual(sections["restore"]["status"], "FAIL")

    def test_recovery_required_journal_is_invalid_evidence(self):
        self.fixture.build()
        journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        journal = json.loads(journal_path.read_text(encoding="utf-8"))
        journal["state"] = "RECOVERY_REQUIRED"
        journal_path.write_text(json.dumps(journal), encoding="utf-8")
        os.chmod(journal_path, 0o600)
        self.assertEqual(self.report()["sections"]["journals"]["status"], "FAIL")

    def test_journal_transaction_id_must_match_filename(self):
        self.fixture.build()
        journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        journal = json.loads(journal_path.read_text(encoding="utf-8"))
        journal["transaction_id"] = "9" * 32
        journal_path.write_text(json.dumps(journal), encoding="utf-8")
        os.chmod(journal_path, 0o600)
        self.assertEqual(self.report()["sections"]["journals"]["status"], "FAIL")

    def test_journal_transaction_id_must_bind_all_identity_fields(self):
        self.fixture.build()
        journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        journal = json.loads(journal_path.read_text(encoding="utf-8"))
        journal["previous_sha"] = "c" * 40
        journal_path.write_text(json.dumps(journal), encoding="utf-8")
        os.chmod(journal_path, 0o600)
        self.assertEqual(self.report()["sections"]["journals"]["journals"][0]["reason"],
                         "transaction_identity_mismatch")

    def test_duplicate_journal_json_keys_fail_closed(self):
        self.fixture.build()
        journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        journal_text = journal_path.read_text(encoding="utf-8")
        journal_path.write_text('{"state":"RECOVERY_REQUIRED",' + journal_text[1:], encoding="utf-8")
        os.chmod(journal_path, 0o600)
        self.assertEqual(self.report()["sections"]["journals"]["journals"][0]["reason"], "journal_invalid")

    def test_malformed_nonnull_checkpoint_in_early_state_fails(self):
        self.fixture.build()
        journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        journal = json.loads(journal_path.read_text(encoding="utf-8"))
        journal["state"] = "PREPARED"
        journal["evidence"]["RESTORE_REHEARSAL_VERIFIED"] = {"unexpected": "value"}
        journal_path.write_text(json.dumps(journal), encoding="utf-8")
        os.chmod(journal_path, 0o600)
        self.assertEqual(self.report()["sections"]["journals"]["journals"][0]["reason"],
                         "journal_state_evidence_mismatch")

    def test_journal_profile_database_and_timestamp_are_validated(self):
        for field, bad_value in (("profile", "staging-next"), ("database", "wpcg_staging_next")):
            with self.subTest(field=field):
                self.fixture = Fixture(Path(self._temp.name) / field)
                self.fixture.build()
                journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
                journal = json.loads(journal_path.read_text(encoding="utf-8"))
                journal[field] = bad_value
                journal_path.write_text(json.dumps(journal), encoding="utf-8")
                os.chmod(journal_path, 0o600)
                self.assertEqual(self.report()["sections"]["journals"]["status"], "FAIL")

    def test_journal_updated_at_must_be_positive_nanoseconds(self):
        for index, bad_value in enumerate((None, -1, 1.5, True, "abc", 1763000000)):
            with self.subTest(updated_at=bad_value):
                self.fixture = Fixture(Path(self._temp.name) / f"timestamp-{index}")
                self.fixture.build()
                journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
                journal = json.loads(journal_path.read_text(encoding="utf-8"))
                journal["updated_at"] = bad_value
                journal_path.write_text(json.dumps(journal), encoding="utf-8")
                os.chmod(journal_path, 0o600)
                self.assertEqual(self.report()["sections"]["journals"]["status"], "FAIL")

    def test_duplicate_journal_backup_references_fail_deterministically(self):
        self.fixture.build()
        journal = journal_for(checkpoint=None, state="PREPARED", previous_sha="f" * 40)
        other_id = journal["transaction_id"]
        path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + other_id + ".json")
        path.write_text(json.dumps(journal), encoding="utf-8")
        os.chmod(path, 0o600)
        sections = self.report()["sections"]
        backup = sections["backups"]["backups"][0]
        self.assertEqual(backup["evidence_match"], "FAIL")
        self.assertEqual(backup["evidence_match_reason"], "duplicate_backup_journal_reference")

    def test_restore_checkpoint_does_not_qualify_with_bad_backup_metadata(self):
        self.fixture.build()
        os.chmod(self.fixture.path(go.PRODUCTION_BACKUPS) / BACKUP_NAME, 0o644)
        sections = self.report()["sections"]
        self.assertEqual(sections["backups"]["status"], "FAIL")
        self.assertEqual(sections["restore"]["status"], "UNKNOWN")
        self.assertFalse(sections["restore"]["restore_checkpoint_present"])


class BackupIntegrityTest(InventoryTestCase):
    def test_corrupt_gzip_is_a_failure(self):
        self.fixture.build()
        self.fixture.write(go.PRODUCTION_BACKUPS + "/" + BACKUP_NAME, b"\x1f\x8bnot a stream", 0o600)
        entry = self.report()["sections"]["backups"]["backups"][0]
        self.assertEqual(entry["integrity"], "FAIL")
        self.assertEqual(entry["integrity_reason"], "gzip_integrity_failed")
        self.assertEqual(entry["status"], "FAIL")

    def test_backup_hash_mismatch_is_a_failure(self):
        self.fixture.build()
        journal_path = self.fixture.path(go.PRODUCTION_STATE) / ("db-release-" + self.fixture.txid + ".json")
        journal = json.loads(journal_path.read_text(encoding="utf-8"))
        journal["backup_sha256"] = "9" * 64
        new_id = producer_transaction_id(journal)
        journal["transaction_id"] = new_id
        new_path = journal_path.with_name("db-release-" + new_id + ".json")
        new_path.write_text(json.dumps(journal), encoding="utf-8")
        os.chmod(new_path, 0o600)
        journal_path.unlink()
        sections = self.report()["sections"]
        self.assertEqual(sections["journals"]["status"], "FAIL")
        self.assertEqual(sections["restore"]["status"], "FAIL")

    def test_wrong_backup_mode_fails_metadata(self):
        self.fixture.build()
        os.chmod(self.fixture.path(go.PRODUCTION_BACKUPS) / BACKUP_NAME, 0o644)
        entry = self.report()["sections"]["backups"]["backups"][0]
        self.assertFalse(entry["metadata_ok"])
        self.assertEqual(entry["status"], "FAIL")

    def test_insecure_backup_directory_is_not_traversed(self):
        self.fixture.build()
        os.chmod(self.fixture.path(go.PRODUCTION_BACKUPS), 0o777)
        backups = self.report()["sections"]["backups"]
        self.assertEqual(backups["status"], "FAIL")
        self.assertEqual(backups["reason"], "backup_directory_metadata_invalid")
        self.assertEqual(backups["backups"], [])

    def test_backup_symlink_is_a_failure(self):
        self.fixture.build()
        backup = self.fixture.path(go.PRODUCTION_BACKUPS) / BACKUP_NAME
        backup.unlink()
        os.symlink("/etc/passwd", backup)
        entry = self.report()["sections"]["backups"]["backups"][0]
        self.assertEqual(entry["status"], "FAIL")
        self.assertEqual(entry["reason"], "backup_type_invalid")

    def test_empty_backup_directory_fails(self):
        self.fixture.build(with_evidence=False)
        backups = self.report()["sections"]["backups"]
        self.assertEqual(backups["status"], "FAIL")
        self.assertEqual(backups["reason"], "no_backups_present")


class LockTest(InventoryTestCase):
    def test_held_lock_is_reported_fail_and_free_lock_pass(self):
        self.fixture.build()
        deploy = self.fixture.write(go.PRODUCTION_STATE + "/deploy.lock", b"", 0o600)
        self.fixture.write(go.PRODUCTION_STATE + "/database-release.lock", b"", 0o600)
        info = os.stat(deploy)
        identity = f"{os.major(info.st_dev):02x}:{os.minor(info.st_dev):02x}:{info.st_ino}"
        self.fixture.write("/proc/locks", f"1: FLOCK ADVISORY WRITE 1234 {identity} 0 EOF\n".encode(), 0o644)
        locks = self.report()["sections"]["locks"]
        self.assertEqual(locks["status"], "FAIL")
        self.assertEqual(locks["locks"]["deploy_lock"]["state"], "HELD")
        self.assertEqual(locks["locks"]["migration_lock"]["state"], "FREE")

    def test_lock_inspection_does_not_acquire_or_release_locks(self):
        self.fixture.build()
        path = self.fixture.write(go.PRODUCTION_STATE + "/deploy.lock", b"", 0o600)
        info = os.stat(path)
        identity = f"{os.major(info.st_dev):02x}:{os.minor(info.st_dev):02x}:{info.st_ino}"
        self.fixture.write("/proc/locks", f"1: FLOCK ADVISORY WRITE 1234 {identity} 0 EOF\n".encode(), 0o644)
        with mock.patch.object(go, "fcntl", create=True) as fcntl_mock:
            result = go._probe_lock(str(path), self.fixture.uid, str(self.fixture.path("/proc/locks")))
        self.assertEqual(result["state"], "HELD")
        fcntl_mock.assert_not_called()

    def test_lock_with_wrong_mode_is_invalid(self):
        self.fixture.build()
        self.fixture.write(go.PRODUCTION_STATE + "/deploy.lock", b"", 0o644)
        lock = self.report()["sections"]["locks"]["locks"]["deploy_lock"]
        self.assertEqual(lock["status"], "FAIL")
        self.assertEqual(lock["state"], "INVALID")


class InvocationGuardTest(InventoryTestCase):
    def test_arguments_are_rejected_without_running_any_command(self):
        self.fixture.build()
        report = self.report(argv=["--help"])
        self.assertEqual(report["overall"], "FAIL")
        self.assertEqual(report["reason"], "arguments_not_allowed")
        self.assertEqual(report["audit"]["arguments"], "REJECTED")
        self.assertEqual(self.fixture.run.calls, [])
        self.assertEqual([item["event"] for item in self.fixture.audit], ["start", "finish"])
        self.assertNotIn("--help", json.dumps(self.fixture.audit))

    def test_non_root_is_rejected_and_audited_without_collecting(self):
        self.fixture.build()
        report = self.report(euid=1000)
        self.assertEqual(report["overall"], "FAIL")
        self.assertEqual(report["reason"], "not_root")
        self.assertEqual(report["sections"], {})
        self.assertEqual(self.fixture.run.calls, [])
        self.assertEqual([item["event"] for item in self.fixture.audit], ["start", "finish"])

    def test_audit_failure_at_start_fails_closed(self):
        self.fixture.build()
        def broken(_record):
            raise OSError("audit unavailable")
        report = self.report(audit=broken)
        self.assertEqual(report["overall"], "UNKNOWN")
        self.assertEqual(report["reason"], "audit_unavailable")
        self.assertEqual(report["audit"]["persistent"], "UNAVAILABLE")
        self.assertEqual(self.fixture.run.calls, [])

    def test_audit_failure_at_finish_downgrades_to_unknown(self):
        self.fixture.build()
        calls = []
        def flaky(record):
            calls.append(record)
            if len(calls) == 2:
                raise OSError("journal vanished")
        report = self.report(audit=flaky)
        self.assertEqual(report["overall"], "UNKNOWN")
        self.assertEqual(report["audit"]["status"], "UNKNOWN")
        self.assertIn("release", report["sections"])

    def test_successful_run_writes_start_and_finish_audit_records(self):
        self.fixture.build()
        self.report()
        self.assertEqual([record["event"] for record in self.fixture.audit], ["start", "finish"])
        self.assertEqual(self.fixture.audit[0]["result"], "START")
        self.assertRegex(self.fixture.audit[0]["helper_sha256"], r"^[0-9a-f]{64}$")

    def test_syslog_audit_uses_auth_facility_and_one_line(self):
        fake = types.SimpleNamespace(
            LOG_PID=1, LOG_AUTH=2, LOG_NOTICE=3,
            openlog=mock.Mock(), syslog=mock.Mock(),
        )
        with mock.patch.dict(sys.modules, {"syslog": fake}):
            go.syslog_audit({
                "event": "start", "invocation_id": "f" * 32, "uid": 0, "euid": 0,
                "helper_sha256": "a" * 64,
            })
        fake.openlog.assert_called_once_with("wpcaregrid-g1-inventory", 1, 2)
        fake.syslog.assert_called_once()
        priority, message = fake.syslog.call_args.args
        self.assertEqual(priority, 3)
        self.assertIn("event=start", message)
        self.assertNotIn("\n", message)


class PackageTest(unittest.TestCase):
    def test_sha256sums_is_exact_and_complete(self):
        listed = {}
        raw = SUMS.read_bytes()
        self.assertTrue(raw.endswith(b"\n"))
        for line in raw.decode("ascii").splitlines():
            digest, name = line.split("  ", 1)
            listed[name] = digest
        actual = {p.name: hashlib.sha256(p.read_bytes()).hexdigest()
                  for p in PACKAGE.iterdir() if p.is_file() and p.name != "SHA256SUMS"}
        self.assertEqual(listed, actual)

    def test_manifest_pins_the_installed_helper(self):
        manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
        self.assertEqual(manifest["schema"], 1)
        self.assertEqual(manifest["package"], "wpcaregrid-g1-inventory")
        self.assertEqual(manifest["tool"], go.TOOL)
        self.assertEqual(manifest["version"], go.VERSION)
        files = {entry["name"]: entry for entry in manifest["files"]}
        helper = files["go62-inventory.py"]
        self.assertEqual(helper["sha256"], hashlib.sha256(HELPER.read_bytes()).hexdigest())
        self.assertEqual(helper["install_path"],
                         "/usr/local/libexec/wpcaregrid-g1-inventory/go62-inventory.py")
        self.assertEqual(helper["mode"], "0644")
        self.assertEqual(manifest["exit_codes"],
                         {"0": "PASS", "3": "FAIL", "4": "UNKNOWN", "5": "UNAVAILABLE"})
        self.assertIn("no database credential", manifest["scope"].lower())

    def test_package_does_not_extend_the_go0_or_go54a_pins(self):
        ops_sums = (SCRIPTS / "production-g1-ops" / "SHA256SUMS").read_text(encoding="ascii")
        self.assertEqual(len(ops_sums.splitlines()), 23)
        self.assertNotIn("go62-inventory", ops_sums)
        self.assertNotIn("production-g1-inventory", ops_sums)


if __name__ == "__main__":
    unittest.main()
