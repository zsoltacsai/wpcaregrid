#!/usr/bin/env python3
"""Real MySQL 8.4 proof of the Production G1 option-file ordering.

The two G1 instance option files live in the distribution's `!includedir`
directory. mysqld reads that directory in file-name order and the last occurrence of
an option wins, so a G1 file that sorts before the shared `mysqld.cnf` is overridden by
the shared `[mysqld]` group (observed on the staging VPS with the old `90-` names).

These tests do not mock `my_print_defaults`/`mysqld`: they build a disposable
/etc/mysql tree inside the official MySQL image, run the real binaries and feed the
real output to the unchanged contract validators. Nothing is installed on a host.

Requirements: Docker. Set G1_REQUIRE_REAL_MYSQL=1 (CI does) to fail instead of skip
when Docker or the image is unavailable.
"""

from __future__ import annotations

import importlib.util
import io
import os
import re
import shutil
import subprocess
import sys
import tarfile
import types
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
IMAGE = os.environ.get(
    "G1_MYSQL_IMAGE",
    "mysql:8.4@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902",
)
REQUIRE = os.environ.get("G1_REQUIRE_REAL_MYSQL") == "1"

SPEC = importlib.util.spec_from_file_location("production_g1_mysql_contract", ROOT / "production_g1_mysql_contract.py")
CONTRACT = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
sys.modules[SPEC.name] = CONTRACT
SPEC.loader.exec_module(CONTRACT)

INSTALLER_SPEC = importlib.util.spec_from_file_location("production_g1_mysql_installer", ROOT / "production-g1-mysql.py")
INSTALLER = importlib.util.module_from_spec(INSTALLER_SPEC)
with mock.patch.dict(sys.modules, {"pwd": types.ModuleType("pwd"), "grp": types.ModuleType("grp")}):
    INSTALLER_SPEC.loader.exec_module(INSTALLER)

INSTANCES = CONTRACT.INSTANCES
CONF_D = "/etc/mysql/mysql.conf.d"

# What the staging VPS showed: the distribution's shared [mysqld] group carries the four
# defaults that overrode the instance files, plus the inherited loopback X address.
UBUNTU_MYSQLD_CNF = """[mysqld]
user\t\t= mysql
pid-file\t= /var/run/mysqld/mysqld.pid
socket\t\t= /var/run/mysqld/mysqld.sock
datadir\t\t= /var/lib/mysql
log-error\t= /var/log/mysql/error.log
bind-address\t\t= 127.0.0.1
mysqlx-bind-address\t= 127.0.0.1
key_buffer_size\t\t= 16M
max_binlog_size\t= 100M
"""
# A second shared file that sorts between `90-` and `mysqld.cnf` (the VPS has one).
SHARED_99_CNF = "[mysqld]\nmax_connections = 100\n"
MY_CNF = "!includedir /etc/mysql/conf.d/\n!includedir /etc/mysql/mysql.conf.d/\n"
FOUR_OVERRIDDEN = ("datadir", "socket", "pid-file", "log-error")


def docker_ready() -> bool:
    if shutil.which("docker") is None:
        return False
    probe = subprocess.run(["docker", "image", "inspect", IMAGE], stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, check=False)
    if probe.returncode == 0:
        return True
    pull = subprocess.run(["docker", "pull", "--quiet", IMAGE], stdout=subprocess.DEVNULL,
                          stderr=subprocess.DEVNULL, check=False, timeout=600)
    return pull.returncode == 0


_READY = None


def real_mysql_available() -> bool:
    global _READY
    if _READY is None:
        _READY = docker_ready()
    return _READY


def shared_tree() -> dict[str, bytes]:
    return {
        "/etc/mysql/my.cnf": MY_CNF.encode(),
        f"{CONF_D}/mysqld.cnf": UBUNTU_MYSQLD_CNF.encode(),
        f"{CONF_D}/99-wpcaregrid.cnf": SHARED_99_CNF.encode(),
    }


def g1_tree(*, legacy: bool = False) -> dict[str, bytes]:
    """The generated instance option files, from the real artifact generator."""
    files = {}
    for instance in INSTANCES.values():
        content = CONTRACT.artifact_files()[instance.config_path]
        path = instance.legacy_config_path if legacy else instance.config_path
        files[path] = content
    return files


def tar_of(files: dict[str, bytes]) -> bytes:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w") as archive:
        for path, content in sorted(files.items()):
            info = tarfile.TarInfo(path.lstrip("/"))
            info.size, info.mode, info.uid, info.gid, info.uname, info.gname = len(content), 0o644, 0, 0, "root", "root"
            archive.addfile(info, io.BytesIO(content))
    return buffer.getvalue()


PRELUDE = r"""
set -u
chmod -R go-w /etc/mysql
V=$(mysqld --version | sed -E 's/.*Ver ([0-9]+\.[0-9]+).*/\1/')
defaults() { my_print_defaults --defaults-file=/etc/mysql/my.cnf --defaults-group-suffix=@"$1" mysqld server mysqld-"$V"; }
"""
DEFAULTS_BOTH = r"""
for N in wpcaregrid-production wpcaregrid-production-restore; do echo "##defaults:$N"; defaults $N; done
"""
MYSQLD_BOTH = r"""
mkdir -p /var/lib/mysql-wpcaregrid-production /var/lib/mysql-wpcaregrid-production-restore \
         /var/tmp/wpcaregrid-production /var/tmp/wpcaregrid-production-restore /var/log/mysql /run/mysqld
chown -R mysql:mysql /var/lib/mysql-wpcaregrid-production /var/lib/mysql-wpcaregrid-production-restore \
         /var/tmp/wpcaregrid-production /var/tmp/wpcaregrid-production-restore /var/log/mysql /run/mysqld
for N in wpcaregrid-production wpcaregrid-production-restore; do
  SUF="--defaults-file=/etc/mysql/my.cnf --defaults-group-suffix=@$N"
  mysqld $SUF --validate-config >/dev/null 2>&1; RC=$?; echo "##validate-config:$N"; echo "rc=$RC"
  echo "##help:$N"; mysqld $SUF --verbose --help 2>/dev/null
done
"""


def run_real(files: dict[str, bytes], script: str) -> dict[str, str]:
    """Run `script` in the MySQL image with `files` unpacked; return the ##-delimited sections."""
    done = subprocess.run(
        ["docker", "run", "--rm", "-i", "--entrypoint", "bash", IMAGE, "-c", "tar -x -C / && " + PRELUDE + script],
        input=tar_of(files), stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False, timeout=300)
    if done.returncode != 0:
        raise AssertionError("container run failed: " + done.stderr.decode(errors="replace")[-400:])
    sections: dict[str, str] = {}
    current = None
    for line in done.stdout.decode().splitlines(keepends=True):
        if line.startswith("##"):
            current = line[2:].strip()
            sections[current] = ""
        elif current is not None:
            sections[current] += line
    return sections


def last_value(output: str, key: str):
    values = [v for k, v in CONTRACT.parse_defaults_output(output) if k == key]
    return values[-1] if values else None


@unittest.skipUnless(real_mysql_available() or REQUIRE, "Docker / the MySQL 8.4 image is not available")
class RealMysqlOptionOrderingTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not real_mysql_available():
            raise AssertionError("G1_REQUIRE_REAL_MYSQL=1 but Docker or the MySQL 8.4 image is unavailable")
        version = subprocess.run(["docker", "run", "--rm", "--entrypoint", "mysqld", IMAGE, "--version"],
                                 stdout=subprocess.PIPE, check=True).stdout.decode().strip()
        print(f"\nreal MySQL under test: {version}", file=sys.stderr)
        assert "Ver 8.4." in version, version
        cls.new = run_real({**shared_tree(), **g1_tree()}, DEFAULTS_BOTH + MYSQLD_BOTH)
        cls.legacy = run_real({**shared_tree(), **g1_tree(legacy=True)}, DEFAULTS_BOTH)

    def test_ordinary_file_name_sorts_after_every_distribution_file(self):
        for instance in INSTANCES.values():
            name = Path(instance.config_path).name
            self.assertTrue(name.startswith("zz-") and name.endswith(".cnf"))
            for shared in ("mysqld.cnf", "99-wpcaregrid.cnf", "mysql.cnf", "z.cnf"):
                self.assertGreater(name.encode(), shared.encode())
        self.assertEqual(sorted(g1_tree()), sorted(i.config_path for i in INSTANCES.values()))

    def test_real_read_order_places_the_new_files_last_and_the_old_ones_first(self):
        markers = lambda path: (path, f"[mysqld]\nwpcg-order-marker={Path(path).name}\n")  # noqa: E731
        shared = {p: c for p, c in (markers(f"{CONF_D}/mysqld.cnf"), markers(f"{CONF_D}/99-wpcaregrid.cnf"))}
        for legacy, expected_last in ((False, "zz-"), (True, "mysqld.cnf")):
            files = {p: c.encode() for p, c in shared.items()}
            files["/etc/mysql/my.cnf"] = MY_CNF.encode()
            for instance in INSTANCES.values():
                path = instance.legacy_config_path if legacy else instance.config_path
                files[path] = (CONTRACT.artifact_files()[instance.config_path].decode()
                               + f"[mysqld@{instance.name}]\nwpcg-order-marker={Path(path).name}\n").encode()
            sections = run_real(files, DEFAULTS_BOTH)
            order = re.findall(r"--wpcg-order-marker=(\S+)", sections["defaults:wpcaregrid-production"])
            self.assertEqual(order, sorted(order, key=lambda n: n.encode()), order)  # read in file-name order
            self.assertTrue(order[-1].startswith(expected_last), order)

    def test_new_names_give_the_exact_value_of_all_13_contract_options_for_both_instances(self):
        for instance in INSTANCES.values():
            output = self.new[f"defaults:{instance.name}"]
            for key, expected in instance.expected_options().items():
                with self.subTest(instance=instance.name, option=key):
                    self.assertEqual(last_value(output, key), expected)
            CONTRACT.validate_effective_options(instance, output)

    def test_the_four_formerly_overridden_options_are_correct_with_the_new_names(self):
        for instance in INSTANCES.values():
            output = self.new[f"defaults:{instance.name}"]
            for key in FOUR_OVERRIDDEN:
                with self.subTest(instance=instance.name, option=key):
                    self.assertEqual(last_value(output, key), instance.expected_options()[key])
                    # the shared value is still present, but earlier: the instance value wins
                    values = [v for k, v in CONTRACT.parse_defaults_output(output) if k == key]
                    self.assertGreaterEqual(len(values), 2)
                    self.assertNotEqual(values[0], values[-1])

    def test_the_old_90_names_are_refused_by_the_unchanged_validator_for_the_four_options(self):
        for instance in INSTANCES.values():
            output = self.legacy[f"defaults:{instance.name}"]
            for key in FOUR_OVERRIDDEN:
                self.assertNotEqual(last_value(output, key), instance.expected_options()[key], key)
            with self.assertRaises(CONTRACT.Refused) as caught:
                CONTRACT.validate_effective_options(instance, output)
            self.assertEqual(str(caught.exception), "effective_option_mismatch")

    def test_no_active_path_still_uses_the_old_90_instance_file_name(self):
        for instance in INSTANCES.values():
            self.assertNotIn(instance.legacy_config_path, CONTRACT.artifact_files())
            self.assertTrue(instance.legacy_config_path.startswith(f"{CONF_D}/90-"))
        for name in ("production-g1-mysql.py", "production_g1_mysql_contract.py"):
            source = (ROOT / name).read_text(encoding="utf-8")
            self.assertEqual(re.findall(r"""mysql\.conf\.d/90-|["']90-\{|90-\{instance""", source), [], name)
        installer = (ROOT / "production-g1-mysql.py").read_text(encoding="utf-8")
        self.assertEqual(installer.count("legacy_config_name_present"), 2)  # install() and effective check
        self.assertIn("instance.config_path", installer)

    def test_a_later_loading_file_overriding_any_contract_option_is_refused(self):
        keys = list(INSTANCES["wpcaregrid-production"].expected_options())
        script = "".join(
            f"""
printf '[mysqld]\\n{key}=x-late\\n' > {CONF_D}/zzz-late.cnf; chmod 644 {CONF_D}/zzz-late.cnf
for N in wpcaregrid-production wpcaregrid-production-restore; do echo "##late:{key}:$N"; defaults $N; done
""" for key in keys)
        sections = run_real({**shared_tree(), **g1_tree()}, script)
        for key in keys:
            for instance in INSTANCES.values():
                with self.subTest(option=key, instance=instance.name):
                    with self.assertRaises(CONTRACT.Refused):
                        CONTRACT.validate_effective_options(instance, sections[f"late:{key}:{instance.name}"])

    def test_a_later_file_replacing_the_four_vps_options_is_a_mismatch(self):
        late = "[mysqld]\n" + "".join(f"{k}=/var/lib/shared-{k}\n" for k in FOUR_OVERRIDDEN)
        script = f"printf '%s' '{late}' > {CONF_D}/zzz-late.cnf; chmod 644 {CONF_D}/zzz-late.cnf\n" + \
                 'for N in wpcaregrid-production wpcaregrid-production-restore; do echo "##late:$N"; defaults $N; done\n'
        sections = run_real({**shared_tree(), **g1_tree()}, script)
        for instance in INSTANCES.values():
            with self.assertRaises(CONTRACT.Refused) as caught:
                CONTRACT.validate_effective_options(instance, sections[f"late:{instance.name}"])
            self.assertEqual(str(caught.exception), "effective_option_mismatch")

    def test_production_and_restore_configuration_cannot_be_swapped(self):
        production, restore = INSTANCES["wpcaregrid-production"], INSTANCES["wpcaregrid-production-restore"]
        def swapped(owner, donor):
            text = f"[{owner.section}]\n" + "".join(f"{k}={v}\n" for k, v in donor.expected_options().items())
            return text.encode()
        files = {**shared_tree(),
                 production.config_path: swapped(production, restore),
                 restore.config_path: swapped(restore, production)}
        sections = run_real(files, DEFAULTS_BOTH)
        for instance in INSTANCES.values():
            with self.subTest(instance=instance.name):
                with self.assertRaises(CONTRACT.Refused):
                    CONTRACT.validate_effective_options(instance, sections[f"defaults:{instance.name}"])
                donor = restore if instance is production else production
                with self.assertRaises(CONTRACT.Refused):
                    CONTRACT.validate_instance_group(instance, swapped(instance, donor).decode())

    def test_only_the_expected_instance_group_is_selected(self):
        production = self.new["defaults:wpcaregrid-production"]
        restore = self.new["defaults:wpcaregrid-production-restore"]
        self.assertEqual(last_value(production, "port"), "3308")
        self.assertEqual(last_value(restore, "port"), "3309")
        self.assertNotIn("wpcaregrid-production-restore", production.replace("wpcaregrid-production-restore.", "x"))

    def test_x_protocol_stays_disabled_and_the_inherited_loopback_address_is_still_accepted(self):
        for instance in INSTANCES.values():
            output = self.new[f"defaults:{instance.name}"]
            self.assertEqual(last_value(output, "mysqlx"), "0")
            self.assertEqual(last_value(output, "mysqlx-bind-address"), "127.0.0.1")
            CONTRACT.validate_effective_options(instance, output)
            hostile = output + "--mysqlx-bind-address=0.0.0.0\n"
            with self.assertRaises(CONTRACT.Refused) as caught:
                CONTRACT.validate_effective_options(instance, hostile)
            self.assertEqual(str(caught.exception), "unsafe_effective_option_present")
            enabled = output + "--mysqlx=1\n"
            with self.assertRaises(CONTRACT.Refused) as caught:
                CONTRACT.validate_effective_options(instance, enabled)
            self.assertEqual(str(caught.exception), "x_protocol_not_disabled")

    def test_real_mysqld_accepts_the_configuration_and_resolves_the_contract_values(self):
        for instance in INSTANCES.values():
            with self.subTest(instance=instance.name):
                self.assertEqual("rc=0", self.new[f"validate-config:{instance.name}"].strip())
                with mock.patch.object(INSTALLER, "run", return_value=self.new[f"help:{instance.name}"]):
                    found = INSTALLER._effective_help(instance)
                CONTRACT.validate_resolved_options(instance, found)
                for key in ("datadir", "socket", "pid-file"):  # log-error is rendered as `stderr` by --help
                    self.assertEqual(CONTRACT.normalize_resolved_value(key, found[key]),
                                     CONTRACT.normalize_resolved_value(key, instance.expected_options()[key]))

    def test_dangerous_inherited_options_are_still_refused_in_the_real_layout(self):
        injected = {
            "skip-grant-tables": "[mysqld]\nskip-grant-tables\n",
            "init-file": "[mysqld]\ninit-file=/tmp/x.sql\n",
            "keyring": "[mysqld]\nearly-plugin-load=keyring_file.so\n",
            "replication": "[mysqld]\nrelay-log=relay\n",
            "unapproved-x": "[mysqld]\nmysqlx-port=33060\n",
        }
        script = "".join(
            f"printf '{text}' > {CONF_D}/zzz-inject.cnf; chmod 644 {CONF_D}/zzz-inject.cnf\n"
            f'echo "##inject:{name}"; defaults wpcaregrid-production\n'
            for name, text in {k: v.replace("\n", "\\n") for k, v in injected.items()}.items())
        sections = run_real({**shared_tree(), **g1_tree()}, script)
        expected = {"skip-grant-tables": "unsafe_effective_option_present", "init-file": "unsafe_effective_option_present",
                    "keyring": "keyring_configuration_present", "replication": "replication_configuration_present",
                    "unapproved-x": "unsafe_effective_option_present"}
        for name, code in expected.items():
            with self.subTest(case=name):
                with self.assertRaises(CONTRACT.Refused) as caught:
                    CONTRACT.validate_effective_options(INSTANCES["wpcaregrid-production"], sections[f"inject:{name}"])
                self.assertEqual(str(caught.exception), code)


if __name__ == "__main__":
    unittest.main(verbosity=2)
