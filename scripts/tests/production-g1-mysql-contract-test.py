#!/usr/bin/env python3
"""Synthetic fail-closed tests for the Production G1 MySQL config contract."""

from __future__ import annotations

import importlib.util
import hashlib
import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("production_g1_mysql_contract", ROOT / "production_g1_mysql_contract.py")
CONTRACT = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
sys.modules[SPEC.name] = CONTRACT
SPEC.loader.exec_module(CONTRACT)


def defaults(instance):
    return " ".join(f"--{key}={value}" for key, value in instance.expected_options().items())


def group(instance):
    return f"[{instance.section}]\n" + "".join(
        f"{key}={value}\n" for key, value in instance.expected_options().items())


class ProductionG1MySQLContractTests(unittest.TestCase):
    def test_two_fixed_profiles_have_disjoint_resources(self):
        prod = CONTRACT.get_instance("wpcaregrid-production")
        restore = CONTRACT.get_instance("wpcaregrid-production-restore")
        for field in ("port", "datadir", "socket", "pid_file", "log_file", "tmpdir", "schema", "apparmor_profile"):
            self.assertNotEqual(getattr(prod, field), getattr(restore, field), field)
        self.assertEqual((prod.port, restore.port), (3308, 3309))

    def test_unknown_instance_is_rejected(self):
        with self.assertRaises(CONTRACT.Refused) as caught:
            CONTRACT.get_instance("staging-next")
        self.assertEqual(str(caught.exception), "instance_name_invalid")

    def test_exact_resolved_configuration_passes_for_both_instances(self):
        for instance in CONTRACT.INSTANCES.values():
            CONTRACT.validate_effective_options(instance, defaults(instance))

    def test_mysqld_help_boolean_and_path_rendering_is_normalized(self):
        self.assertEqual(CONTRACT.normalize_resolved_value("mysqlx", "OFF"), "OFF")
        self.assertEqual(CONTRACT.normalize_resolved_value("mysqlx", "0"), "OFF")
        self.assertEqual(CONTRACT.normalize_resolved_value("binlog_encryption", "FALSE"), "OFF")
        self.assertEqual(CONTRACT.normalize_resolved_value("datadir", "/var/lib/mysql-x/"), "/var/lib/mysql-x")
        self.assertEqual(CONTRACT.normalize_resolved_value("secure_file_priv", "NULL"), "NULL")

    def test_datadir_fallback_to_shared_mysql_is_rejected(self):
        instance = CONTRACT.get_instance("wpcaregrid-production")
        output = defaults(instance).replace(instance.datadir, "/var/lib/mysql")
        with self.assertRaises(CONTRACT.Refused) as caught:
            CONTRACT.validate_effective_options(instance, output)
        self.assertEqual(str(caught.exception), "effective_option_mismatch")

    def test_missing_datadir_is_rejected(self):
        instance = CONTRACT.get_instance("wpcaregrid-production")
        output = " ".join(x for x in defaults(instance).split() if not x.startswith("--datadir="))
        with self.assertRaises(CONTRACT.Refused) as caught:
            CONTRACT.validate_effective_options(instance, output)
        self.assertEqual(str(caught.exception), "required_option_missing")

    def test_shared_socket_is_rejected(self):
        instance = CONTRACT.get_instance("wpcaregrid-production-restore")
        output = defaults(instance).replace(instance.socket, "/run/mysqld/mysqld.sock")
        with self.assertRaises(CONTRACT.Refused) as caught:
            CONTRACT.validate_effective_options(instance, output)
        self.assertEqual(str(caught.exception), "effective_option_mismatch")

    def test_port_collision_is_rejected(self):
        instance = CONTRACT.get_instance("wpcaregrid-production-restore")
        output = defaults(instance).replace("--port=3309", "--port=3308")
        with self.assertRaises(CONTRACT.Refused) as caught:
            CONTRACT.validate_effective_options(instance, output)
        self.assertEqual(str(caught.exception), "effective_option_mismatch")
        with self.assertRaises(CONTRACT.Refused) as caught:
            CONTRACT.validate_port_probe(False)
        self.assertEqual(str(caught.exception), "instance_port_unavailable")
        CONTRACT.validate_port_probe(True)

    def test_secure_file_priv_must_be_null_not_empty(self):
        instance = CONTRACT.get_instance("wpcaregrid-production")
        output = defaults(instance).replace("--secure-file-priv=NULL", "--secure-file-priv=")
        with self.assertRaises(CONTRACT.Refused) as caught:
            CONTRACT.validate_effective_options(instance, output)
        self.assertEqual(str(caught.exception), "effective_option_mismatch")

    def test_keyring_config_is_rejected(self):
        instance = CONTRACT.get_instance("wpcaregrid-production")
        output = defaults(instance) + " --early-plugin-load=keyring_file.so"
        with self.assertRaises(CONTRACT.Refused) as caught:
            CONTRACT.validate_effective_options(instance, output)
        self.assertEqual(str(caught.exception), "keyring_configuration_present")

    def test_plugin_loader_is_rejected_even_when_value_is_not_named_keyring(self):
        instance = CONTRACT.get_instance("wpcaregrid-production")
        output = defaults(instance) + " --plugin-load=innocent.so"
        with self.assertRaises(CONTRACT.Refused) as caught:
            CONTRACT.validate_effective_options(instance, output)
        self.assertEqual(str(caught.exception), "keyring_configuration_present")

    def test_keyring_value_is_rejected_even_under_unexpected_option(self):
        instance = CONTRACT.get_instance("wpcaregrid-production")
        output = defaults(instance) + " --loose-custom-store=keyring_file.so"
        with self.assertRaises(CONTRACT.Refused) as caught:
            CONTRACT.validate_effective_options(instance, output)
        self.assertEqual(str(caught.exception), "keyring_configuration_present")

    def test_replication_configuration_is_rejected(self):
        instance = CONTRACT.get_instance("wpcaregrid-production-restore")
        for option in ("--relay-log=/var/log/mysql/relay", "--replicate-do-db=wpcg_prod_main",
                       "--source-host=stage-source.example", "--skip-replica-start=ON"):
            with self.subTest(option=option):
                with self.assertRaises(CONTRACT.Refused) as caught:
                    CONTRACT.validate_effective_options(instance, defaults(instance) + " " + option)
                self.assertEqual(str(caught.exception), "replication_configuration_present")

    def test_unsafe_inherited_startup_and_listener_options_are_rejected(self):
        for instance in CONTRACT.INSTANCES.values():
            for option in ("--skip-grant-tables", "--skip-grant-tables=ON",
                           "--init-file=/tmp/wpcg-init.sql", '--init-connect=\"SELECT 1\"',
                           "--loose-init-file=/tmp/wpcg-init.sql", "--daemonize=ON",
                           "--admin-port=33060", "--mysqlx-port=33060"):
                with self.subTest(instance=instance.name, option=option):
                    with self.assertRaises(CONTRACT.Refused) as caught:
                        CONTRACT.validate_effective_options(instance, defaults(instance) + " " + option)
                    self.assertEqual(str(caught.exception), "unsafe_effective_option_present")

    def test_persisted_keyring_registry_must_be_exact_zero(self):
        CONTRACT.validate_keyring_registry_count("0\n")
        for invalid in ("1\n", "0\n1", "NULL", "", "credential=hidden"):
            with self.subTest(invalid=invalid):
                with self.assertRaises(CONTRACT.Refused):
                    CONTRACT.validate_keyring_registry_count(invalid)

    def test_instance_group_must_be_complete_and_exclusive(self):
        instance = CONTRACT.get_instance("wpcaregrid-production")
        CONTRACT.validate_instance_group(instance, group(instance))
        with self.assertRaises(CONTRACT.Refused):
            CONTRACT.validate_instance_group(instance, group(instance).replace(f"datadir={instance.datadir}\n", ""))
        with self.assertRaises(CONTRACT.Refused):
            CONTRACT.validate_instance_group(instance, group(instance) + "\n[mysqld]\nport=3306\n")

    def test_dropin_resets_vendor_initializer_and_binds_profile(self):
        instance = CONTRACT.get_instance("wpcaregrid-production")
        contents = next(value.decode() for path, value in CONTRACT.artifact_files().items()
                        if path.endswith("mysql@wpcaregrid-production.service.d/90-wpcaregrid-g1.conf"))
        CONTRACT.validate_unit_dropin(instance, contents)
        self.assertIn("ExecStartPre=", contents)
        self.assertIn("AppArmorProfile=wpcaregrid-mysql-production", contents)
        self.assertIn("Environment=HOME=/nonexistent", contents)
        self.assertIn("Environment=PYTHONDONTWRITEBYTECODE=1", contents)

    def test_dropin_rejects_vendor_preflight_fallback_or_extra_override(self):
        instance = CONTRACT.get_instance("wpcaregrid-production")
        contents = next(value.decode() for path, value in CONTRACT.artifact_files().items()
                        if path.endswith("mysql@wpcaregrid-production.service.d/90-wpcaregrid-g1.conf"))
        with self.assertRaises(CONTRACT.Refused):
            CONTRACT.validate_unit_dropin(instance, contents.replace("ExecStartPre=\n", ""))
        with self.assertRaises(CONTRACT.Refused):
            CONTRACT.validate_unit_dropin(instance, contents + "User=root\n")

    def test_generated_profiles_do_not_grant_foreign_instance_paths(self):
        artifacts = CONTRACT.artifact_files()
        prod = artifacts["/etc/apparmor.d/wpcaregrid-mysql-production"].decode()
        restore = artifacts["/etc/apparmor.d/wpcaregrid-mysql-production-restore"].decode()
        self.assertIn("/var/lib/mysql-wpcaregrid-production/**", prod)
        self.assertNotIn("  /var/lib/mysql-wpcaregrid-production-restore/** rwk,", prod)
        self.assertIn("deny /var/lib/mysql-wpcaregrid-production-restore/**", prod)
        self.assertIn("/var/lib/mysql-wpcaregrid-production-restore/**", restore)
        self.assertNotIn("  /var/lib/mysql-wpcaregrid-production/** rwk,", restore)
        self.assertIn("deny /var/lib/mysql-wpcaregrid-production/**", restore)
        self.assertIn("deny /var/lib/mysql/**", prod + restore)
        self.assertNotIn("/var/lib/mysql-keyring", prod + restore)
        self.assertIn("/var/lib/mysql-wpcaregrid-production/** rwk,", prod)
        self.assertIn("/var/lib/mysql-wpcaregrid-production-restore/** rwk,", restore)
        self.assertIn("deny /run/mysqld/mysqld.sock", prod + restore)

    def test_resource_configuration_is_exact_and_has_no_fallback_group(self):
        artifacts = CONTRACT.artifact_files()
        for instance in CONTRACT.INSTANCES.values():
            path = f"/etc/mysql/mysql.conf.d/90-{instance.name}.cnf"
            text = artifacts[path].decode()
            CONTRACT.validate_instance_group(instance, text)
            self.assertIn("secure-file-priv=NULL\n", text)
            self.assertIn("pid-file=" + instance.pid_file + "\n", text)
            self.assertIn("log-error=" + instance.log_file + "\n", text)
            self.assertIn("tmpdir=" + instance.tmpdir + "\n", text)

    def test_systemd_service_identity_must_remain_mysql(self):
        CONTRACT.validate_systemd_identity("mysql\n", "mysql\n")
        for user, group in (("root", "mysql"), ("mysql", "root"), ("", ""), ("www-data", "www-data")):
            with self.subTest(user=user, group=group):
                with self.assertRaises(CONTRACT.Refused) as caught:
                    CONTRACT.validate_systemd_identity(user, group)
                self.assertEqual(str(caught.exception), "systemd_service_identity_mismatch")

    def test_instance_must_be_exactly_inactive_before_path_provisioning(self):
        CONTRACT.validate_unit_inactive(3, "inactive\n")
        for returncode, stdout in ((0, "active\n"), (3, "failed\n"), (4, "inactive\n"),
                                  (7, "0\n"), (3, "")):
            with self.subTest(returncode=returncode, stdout=stdout):
                with self.assertRaises(CONTRACT.Refused) as caught:
                    CONTRACT.validate_unit_inactive(returncode, stdout)
                self.assertEqual(str(caught.exception), "instance_must_be_inactive")

    def test_provisioning_resource_specs_cover_both_instances_and_reject_existing_data(self):
        specs = CONTRACT.provisioning_path_specs()
        self.assertEqual(len(specs), 6)
        expected = {
            path: (kind, mode)
            for instance in CONTRACT.INSTANCES.values()
            for path, kind, mode in ((instance.datadir, "directory", 0o750),
                                     (instance.tmpdir, "directory", 0o700),
                                     (instance.log_file, "file", 0o600))
        }
        self.assertEqual({item[0] for item in specs}, set(expected))
        for path, owner, group, mode, kind, must_be_empty in specs:
            with self.subTest(path=path):
                self.assertEqual((kind, mode), expected[path])
                self.assertEqual((owner, group, must_be_empty), ("mysql", "mysql", True))
                CONTRACT.validate_provisioning_resource_empty(True)
                with self.assertRaises(CONTRACT.Refused) as caught:
                    CONTRACT.validate_provisioning_resource_empty(False)
                self.assertEqual(str(caught.exception), "resource_path_not_empty")

    def test_final_artifact_manifest_matches_actual_source_and_generated_bytes(self):
        manifest_path = ROOT / "production-g1-mysql-manifest.json"
        manifest = json.loads(manifest_path.read_text(encoding="ascii"))
        script = (ROOT / "production-g1-mysql.py").read_bytes()
        contract = (ROOT / "production_g1_mysql_contract.py").read_bytes()
        expected_sources = {
            "production-g1-mysql.py": hashlib.sha256(script).hexdigest(),
            "production_g1_mysql_contract.py": hashlib.sha256(contract).hexdigest(),
        }
        expected_installed = {
            "/usr/local/libexec/wpcaregrid-g1-mysql.py": hashlib.sha256(script).hexdigest(),
            "/usr/local/libexec/production_g1_mysql_contract.py": hashlib.sha256(contract).hexdigest(),
            **{path: hashlib.sha256(content).hexdigest()
               for path, content in CONTRACT.artifact_files().items()},
        }
        self.assertEqual(manifest["sources"], expected_sources)
        self.assertEqual(manifest["installed_artifacts"], expected_installed)
        self.assertEqual(manifest["schema"], 1)
        self.assertEqual(manifest["tool"], "wpcaregrid-production-mysql-g1-v1")
        sums = {}
        for line in (ROOT / "SHA256SUMS").read_text(encoding="ascii").splitlines():
            digest, filename = line.split("  ", 1)
            sums[filename] = digest
        self.assertEqual(sums, {
            "production-g1-mysql.py": expected_sources["production-g1-mysql.py"],
            "production_g1_mysql_contract.py": expected_sources["production_g1_mysql_contract.py"],
            "production-g1-mysql-manifest.json": hashlib.sha256(manifest_path.read_bytes()).hexdigest(),
        })
        docs = (ROOT.parent / "docs/PRODUCTION_G1_MYSQL_PROVISIONING.md").read_text(encoding="utf-8")
        for name, digest in sums.items():
            self.assertIn(f"| `{name}` | `{digest}` |", docs)

    def test_downloaded_artifacts_have_exact_bytes_and_valid_provenance(self):
        files = {
            "production-g1-mysql.py": b"#!",
            "production_g1_mysql_contract.py": b"#!",
            "production-g1-mysql-manifest.json": b"{",
            "SHA256SUMS": b"5",
        }
        for name, prefix in files.items():
            with self.subTest(name=name):
                payload = (ROOT / name).read_bytes()
                self.assertTrue(payload.startswith(prefix))
                self.assertFalse(payload.startswith(b"_"))

        expected = {
            "production-g1-mysql.py": "577097aad15f53a6c144c6c4107e16646c338810b3b1575b5708ec7a17ab0b77",
            "production_g1_mysql_contract.py": "7ad317e3f09d1271531386263289cc821d99d8fa0a82959742dca666c78ed01a",
            "production-g1-mysql-manifest.json": "60ca7544edddd58937ac73daa2249367773319b5a563992ce579677f9cc441fc",
        }
        sums_path = ROOT / "SHA256SUMS"
        sums_bytes = sums_path.read_bytes()
        self.assertEqual(hashlib.sha256(sums_bytes).hexdigest(),
                         "d285049fb81b627f068a0a22bc64dd275b5f694b5b81560a46c874cfef77b2a2")
        self.assertTrue(sums_bytes.endswith(bytes([10])))
        sums = {}
        for line in sums_bytes.splitlines(keepends=True):
            self.assertTrue(line.endswith(bytes([10])))
            parts = line[:-1].decode("ascii").split("  ")
            self.assertEqual(len(parts), 2)
            digest, filename = parts
            self.assertEqual(len(digest), 64)
            self.assertTrue(all(char in "0123456789abcdef" for char in digest))
            self.assertNotIn(filename, sums)
            sums[filename] = digest
        self.assertEqual(sums, expected)
        for filename, digest in expected.items():
            self.assertEqual(hashlib.sha256((ROOT / filename).read_bytes()).hexdigest(), digest)

        manifest = json.loads((ROOT / "production-g1-mysql-manifest.json").read_text(encoding="ascii"))
        self.assertEqual(manifest["schema"], 1)
        self.assertEqual(manifest["tool"], "wpcaregrid-production-mysql-g1-v1")
        self.assertEqual(manifest["sources"], {
            "production-g1-mysql.py": expected["production-g1-mysql.py"],
            "production_g1_mysql_contract.py": expected["production_g1_mysql_contract.py"],
        })

        checkout = ROOT.parent
        for relative, prefix in (
            ("scripts/tests/production-g1-mysql-contract-test.py", b"#!"),
            ("docs/PRODUCTION_G1_MYSQL_PROVISIONING.md", b"#"),
        ):
            with self.subTest(path=relative):
                payload = (checkout / relative).read_bytes()
                self.assertTrue(payload.startswith(prefix))
                self.assertFalse(payload.startswith(b"_"))



if __name__ == "__main__":
    unittest.main(verbosity=2)
