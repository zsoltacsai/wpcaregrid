#!/usr/bin/env python3
"""Synthetic fail-closed tests for the Production G1 MySQL config contract."""

from __future__ import annotations

import importlib.util
import hashlib
import json
import sys
import types
import unittest
from unittest import mock
from pathlib import Path, PurePosixPath

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


VENDOR_TEMPLATE_EXECSTART = "ExecStart=/usr/sbin/mysqld --defaults-group-suffix=@%I"


def systemd_unescaped_instance(instance):
    """What systemd substitutes for %I: the unescaped instance name ('-' becomes '/')."""
    return instance.name.replace("-", "/")


def effective_execstart(argv, *, path="/usr/sbin/mysqld", ignore_errors="no"):
    """Shape of `systemctl show --property=ExecStart --value` for one command."""
    return (f"{{ path={path} ; argv[]={argv} ; ignore_errors={ignore_errors} ; "
            "start_time=[n/a] ; stop_time=[n/a] ; pid=0 ; code=(null) ; status=0/0 }")


def dropin_for(instance):
    return next(value.decode() for path, value in CONTRACT.artifact_files().items()
                if path.endswith(f"{instance.unit}.d/90-wpcaregrid-g1.conf"))


INHERITED_FROM_SHARED_GROUP = " --user=mysql --bind-address=127.0.0.1 --mysqlx-bind-address=127.0.0.1"


def real_defaults(instance):
    """my_print_defaults as captured on MySQL 8.4.13 with an Ubuntu-style shared [mysqld]
    group (user, bind-address, mysqlx-bind-address) next to the generated instance group."""
    return defaults(instance) + INHERITED_FROM_SHARED_GROUP


def real_help(instance, **overrides):
    """`mysqld --verbose --help` lines for the contract options, as rendered by 8.4.13:
    booleans as FALSE, mysqlx=0 as OFF, datadir with a trailing slash, log-error as stderr."""
    values = {
        "bind-address": "127.0.0.1", "binlog-encryption": "FALSE", "datadir": instance.datadir + "/",
        "default-table-encryption": "FALSE", "innodb-redo-log-encrypt": "FALSE",
        "innodb-undo-log-encrypt": "FALSE", "log-error": "stderr", "mysqlx": "OFF",
        "pid-file": instance.pid_file, "port": str(instance.port), "secure-file-priv": "NULL",
        "socket": instance.socket, "tmpdir": instance.tmpdir,
    }
    values.update(overrides)
    return "\n".join(f"{key:<61}{value}" for key, value in values.items()) + "\n"


class ProductionG1MySQLContractTests(unittest.TestCase):
    def _load_installer(self):
        spec = importlib.util.spec_from_file_location(
            "production_g1_mysql_installer_test", ROOT / "production-g1-mysql.py")
        installer = importlib.util.module_from_spec(spec)
        self.assertIsNotNone(spec)
        self.assertIsNotNone(spec.loader)
        sys.modules[spec.name] = installer
        # The production runner uses POSIX account modules; provide inert
        # modules so its pure manifest validation can be exercised on Windows.
        with mock.patch.dict(sys.modules, {
            "pwd": types.ModuleType("pwd"),
            "grp": types.ModuleType("grp"),
        }):
            spec.loader.exec_module(installer)
        return installer

    def test_source_manifest_bytes_match_authoritative_installer_serialization(self):
        installer = self._load_installer()
        script = (ROOT / "production-g1-mysql.py").read_bytes()
        contract = (ROOT / "production_g1_mysql_contract.py").read_bytes()
        generated = CONTRACT.artifact_files()
        expected_sources = {
            "production-g1-mysql.py": hashlib.sha256(script).hexdigest(),
            "production_g1_mysql_contract.py": hashlib.sha256(contract).hexdigest(),
        }
        expected_installed = {
            "/usr/local/libexec/wpcaregrid-g1-mysql.py": expected_sources["production-g1-mysql.py"],
            "/usr/local/libexec/production_g1_mysql_contract.py": expected_sources["production_g1_mysql_contract.py"],
            **{path: hashlib.sha256(content).hexdigest() for path, content in generated.items()},
        }
        expected_payload = {
            "schema": 1,
            "tool": "wpcaregrid-production-mysql-g1-v1",
            "sources": expected_sources,
            "installed_artifacts": expected_installed,
        }
        expected = (json.dumps(expected_payload, sort_keys=True, separators=(",", ":")) + "\n").encode("ascii")
        with (
            mock.patch.object(installer, "HELPER", PurePosixPath("/usr/local/libexec/wpcaregrid-g1-mysql.py")),
            mock.patch.object(installer, "CONTRACT", PurePosixPath("/usr/local/libexec/production_g1_mysql_contract.py")),
        ):
            self.assertEqual(expected, installer._manifest_bytes(script, contract, generated))
        self.assertEqual((ROOT / "production-g1-mysql-manifest.json").read_bytes(), expected)

    def test_install_rejects_manifest_mismatch_before_any_write(self):
        installer = self._load_installer()
        with (
            mock.patch.object(installer, "require_root"),
            mock.patch.object(installer, "_check_vendor_boundary"),
            mock.patch.object(installer.Path, "is_file", return_value=True),
            mock.patch.object(installer, "_systemd_inactive"),
            mock.patch.object(installer, "check_directory"),
            mock.patch.object(installer, "_source_bytes",
                              side_effect=[b"installer", b"contract", b"pretty manifest"]),
            mock.patch.object(installer, "_ensure_directory") as ensure_directory,
            mock.patch.object(installer, "_atomic_noreplace") as publish_file,
            mock.patch.object(installer, "run") as run_command,
        ):
            with self.assertRaises(installer.Refused) as caught:
                installer.install()
            self.assertEqual(str(caught.exception), "source_manifest_mismatch")
            ensure_directory.assert_not_called()
            publish_file.assert_not_called()
            run_command.assert_not_called()

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

    def test_generated_profiles_keep_deny_permissions_separate_and_restrictive(self):
        artifacts = CONTRACT.artifact_files()
        for instance in CONTRACT.INSTANCES.values():
            path = f"/etc/apparmor.d/{instance.apparmor_profile}"
            profile = artifacts[path].decode("utf-8")
            other = next(item for item in CONTRACT.INSTANCES.values() if item.name != instance.name)
            denied_paths = (
                "/var/lib/mysql/", "/var/lib/mysql/**",
                f"{other.datadir}/", f"{other.datadir}/**",
                f"{other.tmpdir}/", f"{other.tmpdir}/**",
            )
            for denied_path in denied_paths:
                with self.subTest(profile=path, denied_path=denied_path):
                    self.assertIn(f"deny {denied_path} rwklm,", profile)
                    self.assertIn(f"deny {denied_path} x,", profile)
            self.assertNotIn("rwklmix", profile)

    def test_resource_configuration_is_exact_and_has_no_fallback_group(self):
        artifacts = CONTRACT.artifact_files()
        for instance in CONTRACT.INSTANCES.values():
            path = instance.config_path
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

    def test_vendor_percent_I_unescapes_the_instance_name_and_selects_a_missing_config_group(self):
        # The defect: the vendor template's @%I becomes @wpcaregrid/production, a group
        # that does not exist, so mysqld would start without the instance configuration.
        for instance in CONTRACT.INSTANCES.values():
            with self.subTest(instance=instance.name):
                unescaped = systemd_unescaped_instance(instance)
                self.assertNotEqual(unescaped, instance.name)
                self.assertEqual(unescaped, instance.name.replace("-", "/"))
                self.assertEqual(f"mysqld@{instance.name}", instance.section)
                self.assertNotEqual(f"mysqld@{unescaped}", instance.section)
                with self.assertRaises(CONTRACT.Refused) as raised:
                    CONTRACT.validate_effective_execstart(
                        instance, effective_execstart(f"/usr/sbin/mysqld --defaults-group-suffix=@{unescaped}"))
                self.assertEqual(str(raised.exception), "effective_systemd_execstart_mismatch")

    def test_generated_dropins_replace_execstart_with_the_literal_instance_group(self):
        for instance in CONTRACT.INSTANCES.values():
            with self.subTest(instance=instance.name):
                contents = dropin_for(instance)
                lines = contents.splitlines()
                reset = lines.index("ExecStart=")
                override = lines.index(f"ExecStart=/usr/sbin/mysqld --defaults-group-suffix=@{instance.name}")
                self.assertEqual(override, reset + 1, "reset immediately precedes the single override")
                self.assertEqual(sum(line.startswith("ExecStart=") for line in lines), 2)
                self.assertNotIn("%", contents, "no systemd specifier is left to be mis-resolved")
                CONTRACT.validate_unit_dropin(instance, contents)
                suffix = lines[override].split("--defaults-group-suffix=", 1)[1]
                self.assertEqual("mysqld" + suffix, instance.section)
                CONTRACT.validate_effective_execstart(
                    instance, effective_execstart(f"/usr/sbin/mysqld --defaults-group-suffix=@{instance.name}"))

    def test_dropin_execstart_must_be_exact_and_ordered(self):
        production = CONTRACT.get_instance("wpcaregrid-production")
        restore = CONTRACT.get_instance("wpcaregrid-production-restore")
        good = dropin_for(production)
        override = f"ExecStart=/usr/sbin/mysqld --defaults-group-suffix=@{production.name}\n"
        bad = {
            "missing reset": good.replace("ExecStart=\n", "", 1),
            "missing override": good.replace(override, ""),
            "vendor specifier": good.replace(override, "ExecStart=/usr/sbin/mysqld --defaults-group-suffix=@%I\n"),
            "lowercase specifier": good.replace(override, "ExecStart=/usr/sbin/mysqld --defaults-group-suffix=@%i\n"),
            "unescaped suffix": good.replace(override, "ExecStart=/usr/sbin/mysqld --defaults-group-suffix=@wpcaregrid/production\n"),
            "restore suffix in production": good.replace(override, f"ExecStart=/usr/sbin/mysqld --defaults-group-suffix=@{restore.name}\n"),
            "extra argument": good.replace(override, override.rstrip("\n") + " --skip-grant-tables\n"),
            "other binary": good.replace(override, f"ExecStart=/usr/bin/mysqld --defaults-group-suffix=@{production.name}\n"),
            "duplicate override": good.replace(override, override + override),
            "override before reset": good.replace("ExecStart=\n" + override, override + "ExecStart=\n"),
            "ignore-errors prefix": good.replace(override, "ExecStart=-" + override[len("ExecStart="):]),
        }
        for name, text in bad.items():
            with self.subTest(case=name):
                with self.assertRaises(CONTRACT.Refused):
                    CONTRACT.validate_unit_dropin(production, text)
        # Each instance rejects the other instance's drop-in.
        with self.assertRaises(CONTRACT.Refused):
            CONTRACT.validate_unit_dropin(production, dropin_for(restore))
        with self.assertRaises(CONTRACT.Refused):
            CONTRACT.validate_unit_dropin(restore, dropin_for(production))

    def test_effective_execstart_requires_one_exact_command_per_instance(self):
        production = CONTRACT.get_instance("wpcaregrid-production")
        restore = CONTRACT.get_instance("wpcaregrid-production-restore")
        prod_argv = f"/usr/sbin/mysqld --defaults-group-suffix=@{production.name}"
        restore_argv = f"/usr/sbin/mysqld --defaults-group-suffix=@{restore.name}"
        CONTRACT.validate_effective_execstart(production, effective_execstart(prod_argv))
        CONTRACT.validate_effective_execstart(restore, effective_execstart(restore_argv))
        bad = {
            "production unit running the restore group": (production, effective_execstart(restore_argv)),
            "restore unit running the production group": (restore, effective_execstart(prod_argv)),
            "extra argument": (production, effective_execstart(prod_argv + " --skip-grant-tables")),
            "missing suffix": (production, effective_execstart("/usr/sbin/mysqld")),
            "wrong binary": (production, effective_execstart(prod_argv, path="/usr/bin/mysqld")),
            "ignore errors": (production, effective_execstart(prod_argv, ignore_errors="yes")),
            "two commands": (production, effective_execstart(prod_argv) + effective_execstart(prod_argv)),
            "no command": (production, ""),
            "unparseable": (production, "ExecStart=/usr/sbin/mysqld"),
        }
        for name, (instance, output) in bad.items():
            with self.subTest(case=name):
                with self.assertRaises(CONTRACT.Refused) as raised:
                    CONTRACT.validate_effective_execstart(instance, output)
                self.assertEqual(str(raised.exception), "effective_systemd_execstart_mismatch")

    def test_running_process_arguments_must_be_exact(self):
        production = CONTRACT.get_instance("wpcaregrid-production")
        restore = CONTRACT.get_instance("wpcaregrid-production-restore")
        CONTRACT.validate_process_arguments(production, f"/usr/sbin/mysqld --defaults-group-suffix=@{production.name} ")
        for text in (
            f"/usr/sbin/mysqld --defaults-group-suffix=@{restore.name} ",
            f"/usr/sbin/mysqld --defaults-group-suffix=@{production.name} --skip-grant-tables ",
            "/usr/sbin/mysqld ",
            "/usr/sbin/mysqld --defaults-group-suffix=@wpcaregrid/production ",
        ):
            with self.subTest(cmdline=text):
                with self.assertRaises(CONTRACT.Refused):
                    CONTRACT.validate_process_arguments(production, text)

    def test_vendor_template_must_contain_the_exact_execstart_the_dropin_mirrors(self):
        template = "[Service]\nType=notify\n" + VENDOR_TEMPLATE_EXECSTART + "\nRestart=on-failure\n"
        CONTRACT.validate_vendor_execstart(template)
        for text in (
            template.replace(VENDOR_TEMPLATE_EXECSTART, VENDOR_TEMPLATE_EXECSTART + " $MYSQLD_OPTS"),
            template.replace(VENDOR_TEMPLATE_EXECSTART, "ExecStart=/usr/sbin/mysqld"),
            template.replace("@%I", "@%i"),
            "[Service]\nType=notify\n",
        ):
            with self.subTest(template=text):
                with self.assertRaises(CONTRACT.Refused) as raised:
                    CONTRACT.validate_vendor_execstart(text)
                self.assertEqual(str(raised.exception), "vendor_execstart_unrecognized")

    def test_installer_uses_the_exact_validators_not_substring_checks(self):
        source = (ROOT / "production-g1-mysql.py").read_text(encoding="utf-8")
        self.assertIn("validate_effective_execstart(instance, exec_start)", source)
        self.assertIn("validate_process_arguments(instance, cmdline)", source)
        self.assertIn("validate_vendor_execstart(text)", source)
        self.assertNotIn('f"--defaults-group-suffix=@{instance.name}" not in', source)

    def _resolved_from_help(self, instance, text):
        installer = self._load_installer()
        with mock.patch.object(installer, "run", return_value=text):
            return installer._effective_help(instance)

    def test_inherited_mysqlx_bind_address_with_disabled_x_protocol_passes_for_both_instances(self):
        # MySQL 8.4.13 measurement: mysqlx=0 -> plugin DISABLED, only the instance port listens.
        for instance in CONTRACT.INSTANCES.values():
            with self.subTest(instance=instance.name):
                CONTRACT.validate_effective_options(instance, real_defaults(instance))
                found = self._resolved_from_help(instance, real_help(instance))
                CONTRACT.validate_resolved_options(instance, found)

    def test_original_unsafe_option_regression_without_the_exception_still_applies(self):
        # Before the fix every mysqlx-* option was refused. The same output without an
        # approved exact option and loopback value is still refused with that code.
        for instance in CONTRACT.INSTANCES.values():
            for option in ("--mysqlx-bind-address=0.0.0.0", "--mysqlx-bind-address=::1", "--mysqlx-bind-address=*",
                           "--mysqlx-bind-address", "--mysqlx-port=33060", "--mysqlx-port=33061",
                           "--mysqlx-socket=/tmp/mysqlx.sock", "--mysqlx-no-such-option=1",
                           "--loose-mysqlx-bind-address=127.0.0.1", "--mysqlx_bind_address=0.0.0.0"):
                with self.subTest(instance=instance.name, option=option):
                    with self.assertRaises(CONTRACT.Refused) as caught:
                        CONTRACT.validate_effective_options(instance, defaults(instance) + " " + option)
                    self.assertEqual(str(caught.exception), "unsafe_effective_option_present")

    def test_inherited_x_option_requires_every_mysqlx_value_to_be_zero(self):
        production = CONTRACT.get_instance("wpcaregrid-production")
        base = real_defaults(production)
        cases = {
            "x protocol enabled": (base.replace("--mysqlx=0", "--mysqlx=1"), "x_protocol_not_disabled"),
            "x protocol on": (base.replace("--mysqlx=0", "--mysqlx=ON"), "x_protocol_not_disabled"),
            "x protocol option without value": (base.replace("--mysqlx=0", "--mysqlx"), "x_protocol_not_disabled"),
            "disable missing": (base.replace(" --mysqlx=0", ""), "x_protocol_not_disabled"),
            "disabled then enabled": (base + " --mysqlx=1", "x_protocol_not_disabled"),
            "enabled then disabled": (base.replace("--mysqlx=0", "--mysqlx=1") + " --mysqlx=0", "x_protocol_not_disabled"),
        }
        for name, (text, code) in cases.items():
            with self.subTest(case=name):
                with self.assertRaises(CONTRACT.Refused) as caught:
                    CONTRACT.validate_effective_options(production, text)
                self.assertEqual(str(caught.exception), code)

    def test_x_protocol_enabled_without_an_inherited_option_is_still_refused(self):
        production = CONTRACT.get_instance("wpcaregrid-production")
        for text, code in (
            (defaults(production).replace("--mysqlx=0", "--mysqlx=1"), "effective_option_mismatch"),
            (defaults(production).replace(" --mysqlx=0", ""), "required_option_missing"),
        ):
            with self.subTest(code=code):
                with self.assertRaises(CONTRACT.Refused) as caught:
                    CONTRACT.validate_effective_options(production, text)
                self.assertEqual(str(caught.exception), code)

    def test_other_unsafe_options_are_still_refused_next_to_the_approved_x_option(self):
        for instance in CONTRACT.INSTANCES.values():
            for option, code in (
                ("--skip-grant-tables", "unsafe_effective_option_present"),
                ("--skip-networking", "unsafe_effective_option_present"),
                ("--init-file=/tmp/wpcg-init.sql", "unsafe_effective_option_present"),
                ("--admin-address=0.0.0.0", "unsafe_effective_option_present"),
                ("--loose-init-file=/tmp/x.sql", "unsafe_effective_option_present"),
                ("--daemonize=ON", "unsafe_effective_option_present"),
                ("--early-plugin-load=keyring_file.so", "keyring_configuration_present"),
                ("--source-host=10.0.0.1", "replication_configuration_present"),
            ):
                with self.subTest(instance=instance.name, option=option):
                    with self.assertRaises(CONTRACT.Refused) as caught:
                        CONTRACT.validate_effective_options(instance, real_defaults(instance) + " " + option)
                    self.assertEqual(str(caught.exception), code)

    def test_production_and_restore_outputs_are_not_interchangeable(self):
        production = CONTRACT.get_instance("wpcaregrid-production")
        restore = CONTRACT.get_instance("wpcaregrid-production-restore")
        for instance, other in ((production, restore), (restore, production)):
            with self.subTest(instance=instance.name):
                with self.assertRaises(CONTRACT.Refused) as caught:
                    CONTRACT.validate_effective_options(instance, real_defaults(other))
                self.assertEqual(str(caught.exception), "effective_option_mismatch")
                found = self._resolved_from_help(other, real_help(other))
                with self.assertRaises(CONTRACT.Refused) as caught:
                    CONTRACT.validate_resolved_options(instance, found)
                self.assertEqual(str(caught.exception), "mysqld_resolved_option_mismatch")

    def test_resolved_options_compare_normalized_values_and_stay_strict(self):
        production = CONTRACT.get_instance("wpcaregrid-production")
        found = self._resolved_from_help(production, real_help(production))
        CONTRACT.validate_resolved_options(production, found)
        bad = {
            "x protocol on": dict(found, mysqlx="ON"),
            "x protocol true": dict(found, mysqlx="TRUE"),
            "shared datadir": dict(found, datadir="/var/lib/mysql/"),
            "shared log": dict(found, **{"log-error": "/var/log/mysql/error.log"}),
            "other log": dict(found, **{"log-error": "stdout"}),
            "wrong port": dict(found, port="3306"),
            "encryption on": dict(found, **{"default-table-encryption": "TRUE"}),
            "secure-file-priv open": dict(found, **{"secure-file-priv": "/tmp"}),
            "non loopback bind": dict(found, **{"bind-address": "0.0.0.0"}),
        }
        for name, resolved in bad.items():
            with self.subTest(case=name):
                with self.assertRaises(CONTRACT.Refused) as caught:
                    CONTRACT.validate_resolved_options(production, resolved)
                self.assertEqual(str(caught.exception), "mysqld_resolved_option_mismatch")
        missing = {key: value for key, value in found.items() if key != "mysqlx"}
        with self.assertRaises(CONTRACT.Refused) as caught:
            CONTRACT.validate_resolved_options(production, missing)
        self.assertEqual(str(caught.exception), "mysqld_resolved_option_missing")
        # log-error is accepted as stderr (help cannot render the file) or as the exact path.
        CONTRACT.validate_resolved_options(production, dict(found, **{"log-error": production.log_file}))

    def test_installer_uses_the_shared_resolved_option_validator(self):
        source = (ROOT / "production-g1-mysql.py").read_text(encoding="utf-8")
        self.assertIn("validate_resolved_options(instance, configured)", source)
        self.assertNotIn("if actual != value:", source)

    # ---- shared /var/log/mysql directory (GO-5.1 hotfix) ---------------------------------
    def _installer_with_accounts(self):
        import types as _types
        installer = self._load_installer()
        names = {1: "root", 2: "mysql", 3: "other"}
        groups = {10: "adm", 11: "root", 12: "mysql", 13: "other"}
        installer.pwd = _types.SimpleNamespace(
            getpwuid=lambda uid: _types.SimpleNamespace(pw_name=names[uid]),
            getpwnam=lambda name: _types.SimpleNamespace(pw_uid=2))
        installer.grp = _types.SimpleNamespace(
            getgrgid=lambda gid: _types.SimpleNamespace(gr_name=groups[gid]),
            getgrnam=lambda name: _types.SimpleNamespace(gr_gid=12))
        return installer

    @staticmethod
    def _fake_dir(mode, uid=2, gid=10, kind=None):
        import stat as _stat
        import types as _types
        kind = _stat.S_IFDIR if kind is None else kind
        return _types.SimpleNamespace(lstat=lambda: _types.SimpleNamespace(st_mode=kind | mode, st_uid=uid, st_gid=gid))

    def test_shared_log_directory_accepts_exactly_0750_and_02750_for_mysql_adm(self):
        installer = self._installer_with_accounts()
        self.assertEqual(installer.SHARED_LOG_DIR_MODES, frozenset({0o750, 0o2750}))
        self.assertEqual(str(installer.SHARED_LOG_DIR).replace("\\", "/"), "/var/log/mysql")
        for mode in (0o750, 0o2750):
            with self.subTest(mode=oct(mode)):
                installer.check_directory(self._fake_dir(mode), owner="mysql", group="adm",
                                          mode=installer.SHARED_LOG_DIR_MODES)
        for mode in (0o700, 0o751, 0o755, 0o770, 0o775, 0o777, 0o2770, 0o2755, 0o1750, 0o3750, 0o4750, 0o6750, 0o640):
            with self.subTest(rejected=oct(mode)):
                with self.assertRaises(installer.Refused) as caught:
                    installer.check_directory(self._fake_dir(mode), owner="mysql", group="adm",
                                              mode=installer.SHARED_LOG_DIR_MODES)
                self.assertEqual(str(caught.exception), "managed_directory_mode_invalid")

    def test_shared_log_directory_still_requires_mysql_adm_a_real_directory_and_no_symlink(self):
        import stat as _stat
        installer = self._installer_with_accounts()
        modes = installer.SHARED_LOG_DIR_MODES
        for uid, gid, code in ((3, 10, "managed_directory_owner_invalid"), (1, 10, "managed_directory_owner_invalid"),
                               (2, 12, "managed_directory_owner_invalid"), (2, 13, "managed_directory_owner_invalid"),
                               (2, 11, "managed_directory_owner_invalid")):
            with self.subTest(uid=uid, gid=gid):
                with self.assertRaises(installer.Refused) as caught:
                    installer.check_directory(self._fake_dir(0o750, uid, gid), owner="mysql", group="adm", mode=modes)
                self.assertEqual(str(caught.exception), code)
        for kind in (_stat.S_IFLNK, _stat.S_IFREG):
            with self.subTest(kind=kind):
                with self.assertRaises(installer.Refused) as caught:
                    installer.check_directory(self._fake_dir(0o750, 2, 10, kind), owner="mysql", group="adm", mode=modes)
                self.assertEqual(str(caught.exception), "managed_directory_type_invalid")

    def test_integer_mode_checks_of_all_other_managed_directories_stay_exact(self):
        installer = self._installer_with_accounts()
        installer.check_directory(self._fake_dir(0o755, 1, 11), owner="root", group="root", mode=0o755)
        for mode in (0o2755, 0o750, 0o775, 0o700, 0o1755):
            with self.subTest(mode=oct(mode)):
                with self.assertRaises(installer.Refused) as caught:
                    installer.check_directory(self._fake_dir(mode, 1, 11), owner="root", group="root", mode=0o755)
                self.assertEqual(str(caught.exception), "managed_directory_mode_invalid")

    def test_provision_paths_checks_the_four_shared_directories_with_unchanged_strictness(self):
        installer = self._installer_with_accounts()
        calls = []

        def spy(path, *, owner, group, mode):
            calls.append((str(path).replace("\\", "/"), owner, group, mode))
            if len(calls) == 4:
                raise installer.Refused("spy_stop_after_shared_directories")

        with mock.patch.object(installer, "require_root"), mock.patch.object(installer, "verify_static_install"),                 mock.patch.object(installer, "_systemd_inactive"), mock.patch.object(installer, "check_directory", spy):
            with self.assertRaises(installer.Refused) as caught:
                installer.provision_paths()
        self.assertEqual(str(caught.exception), "spy_stop_after_shared_directories")
        self.assertEqual(calls, [
            ("/var/lib", "root", "root", 0o755),
            ("/var/log/mysql", "mysql", "adm", frozenset({0o750, 0o2750})),
            ("/var/tmp", "root", "root", 0o1777),
            ("/run/mysqld", "mysql", "mysql", 0o755),
        ])

    def test_downloaded_artifacts_have_exact_bytes_and_valid_provenance(self):
        files = {
            "production-g1-mysql.py": b"#!",
            "production_g1_mysql_contract.py": b"#!",
            "production-g1-mysql-manifest.json": b"{",
            "SHA256SUMS": b"4",
        }
        for name, prefix in files.items():
            with self.subTest(name=name):
                payload = (ROOT / name).read_bytes()
                self.assertTrue(payload.startswith(prefix))
                self.assertFalse(payload.startswith(b"_"))

        expected = {
            "production-g1-mysql.py": "457ddb292f2ddf1f094375254a031de3bb6a450838cb201429363cb2397f02da",
            "production_g1_mysql_contract.py": "45a9cab39c1276b7c9585c63b4204867ea8696b392589f750ba36cac972397a3",
            "production-g1-mysql-manifest.json": "290ed20acfdac72f4150f572521b8f0e6564de33f7ff2979fc5b5a2381410732",
        }
        sums_path = ROOT / "SHA256SUMS"
        sums_bytes = sums_path.read_bytes()
        self.assertEqual(hashlib.sha256(sums_bytes).hexdigest(),
                         "01f803d3628b47f11719751e365ab95bc664189d8dfcb457bf0fec8eb460e8e2")
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
