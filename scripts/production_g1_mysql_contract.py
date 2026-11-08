#!/usr/bin/env python3
"""Fail-closed configuration contract for the two Production G1 MySQL instances.

This module is used by the root-owned ExecStartPre checker. It is intentionally
read-only: it never initializes a datadir, creates files, loads AppArmor policy,
or starts/stops a service.
"""

from __future__ import annotations

import shlex
from dataclasses import dataclass
from typing import Iterable


class Refused(ValueError):
    """Sanitized, stable refusal code; never contains config values."""


@dataclass(frozen=True)
class Instance:
    name: str
    unit: str
    port: int
    datadir: str
    socket: str
    pid_file: str
    log_file: str
    tmpdir: str
    schema: str
    apparmor_profile: str

    @property
    def section(self) -> str:
        return f"mysqld@{self.name}"

    def expected_options(self) -> dict[str, str]:
        return {
            "bind-address": "127.0.0.1",
            "port": str(self.port),
            "datadir": self.datadir,
            "socket": self.socket,
            "pid-file": self.pid_file,
            "log-error": self.log_file,
            "tmpdir": self.tmpdir,
            "secure-file-priv": "NULL",
            "mysqlx": "0",
            "default-table-encryption": "OFF",
            "innodb-redo-log-encrypt": "OFF",
            "innodb-undo-log-encrypt": "OFF",
            "binlog-encryption": "OFF",
        }


INSTANCES = {
    "wpcaregrid-production": Instance(
        name="wpcaregrid-production",
        unit="mysql@wpcaregrid-production.service",
        port=3308,
        datadir="/var/lib/mysql-wpcaregrid-production",
        socket="/run/mysqld/wpcaregrid-production.sock",
        pid_file="/run/mysqld/wpcaregrid-production.pid",
        log_file="/var/log/mysql/wpcaregrid-production.log",
        tmpdir="/var/tmp/wpcaregrid-production",
        schema="wpcg_prod_main",
        apparmor_profile="wpcaregrid-mysql-production",
    ),
    "wpcaregrid-production-restore": Instance(
        name="wpcaregrid-production-restore",
        unit="mysql@wpcaregrid-production-restore.service",
        port=3309,
        datadir="/var/lib/mysql-wpcaregrid-production-restore",
        socket="/run/mysqld/wpcaregrid-production-restore.sock",
        pid_file="/run/mysqld/wpcaregrid-production-restore.pid",
        log_file="/var/log/mysql/wpcaregrid-production-restore.log",
        tmpdir="/var/tmp/wpcaregrid-production-restore",
        schema="wpcg_prod_restore_main",
        apparmor_profile="wpcaregrid-mysql-production-restore",
    ),
}


def provisioning_path_specs() -> tuple[tuple[str, str, str, int, str, bool], ...]:
    """Return fixed per-instance resource paths, types, and emptiness policy."""
    specs = []
    for instance in INSTANCES.values():
        specs.extend((
            (instance.datadir, "mysql", "mysql", 0o750, "directory", True),
            (instance.tmpdir, "mysql", "mysql", 0o700, "directory", True),
            (instance.log_file, "mysql", "mysql", 0o600, "file", True),
        ))
    return tuple(specs)


def validate_provisioning_resource_empty(is_empty: bool) -> None:
    """Refuse existing non-empty resources before any G1 path mutation."""
    if is_empty is not True:
        raise Refused("resource_path_not_empty")


def validate_unit_inactive(returncode: int, stdout: str) -> None:
    """Accept only systemd's exact inactive state, never FATAL or unknown."""
    if returncode != 3 or stdout.strip() != "inactive":
        raise Refused("instance_must_be_inactive")


_KEYRING_OPTIONS = frozenset({
    "early-plugin-load", "plugin-load", "plugin-load-add", "component-load",
    "component-load-add", "keyring-file-data", "keyring-encrypted-file-data",
    "keyring-migration-destination", "keyring-file", "keyring-file-data-cache-size",
})
_FORBIDDEN_PATHS = {
    "datadir": ("/var/lib/mysql",),
    "socket": ("/run/mysqld/mysqld.sock",),
    "pid-file": ("/run/mysqld/mysqld.pid",),
    "log-error": ("/var/log/mysql/error.log",),
    "tmpdir": ("/tmp", "/var/tmp/mysql"),
}


def get_instance(name: str) -> Instance:
    try:
        return INSTANCES[name]
    except (KeyError, TypeError):
        raise Refused("instance_name_invalid") from None


def _normalize_option(key: str) -> str:
    return key.lstrip("-").replace("_", "-").lower()


def normalize_resolved_value(key: str, value: str) -> str:
    """Normalize equivalent MySQL boolean and path renderings from --verbose --help."""
    normalized_key = _normalize_option(key)
    normalized = value.strip()
    if normalized_key in {
        "mysqlx", "default-table-encryption", "innodb-redo-log-encrypt",
        "innodb-undo-log-encrypt", "binlog-encryption",
    }:
        upper = normalized.upper()
        return "OFF" if upper in {"0", "FALSE", "NO", "OFF"} else (
            "ON" if upper in {"1", "TRUE", "YES", "ON"} else normalized
        )
    if normalized_key in {"datadir", "tmpdir"}:
        return normalized.rstrip("/")
    if normalized_key == "secure-file-priv" and normalized.upper() == "NULL":
        return "NULL"
    return normalized


def parse_defaults_output(output: str | bytes) -> list[tuple[str, str | None]]:
    """Parse my_print_defaults output in memory; caller must not print it."""
    if isinstance(output, bytes):
        try:
            output = output.decode("utf-8", errors="strict")
        except UnicodeError:
            raise Refused("defaults_output_encoding_invalid") from None
    if not isinstance(output, str) or len(output) > 1_000_000:
        raise Refused("defaults_output_invalid")
    try:
        tokens = shlex.split(output, posix=True)
    except ValueError:
        raise Refused("defaults_output_invalid") from None
    parsed: list[tuple[str, str | None]] = []
    for token in tokens:
        if not token.startswith("--"):
            raise Refused("defaults_option_invalid")
        raw = token[2:]
        key, sep, value = raw.partition("=")
        key = _normalize_option(key)
        if not key or any(ch not in "abcdefghijklmnopqrstuvwxyz0123456789-" for ch in key):
            raise Refused("defaults_option_invalid")
        parsed.append((key, value if sep else None))
    return parsed


def validate_effective_options(instance: Instance, output: str | bytes) -> None:
    options = parse_defaults_output(output)
    by_key: dict[str, list[str | None]] = {}
    for key, value in options:
        by_key.setdefault(key, []).append(value)
        if key in _KEYRING_OPTIONS or "keyring" in key or "plugin-load" in key or "component-load" in key or (
            value is not None and "keyring" in value.lower()
        ):
            raise Refused("keyring_configuration_present")
        if (key.startswith(("replicate-", "source-", "master-", "slave-", "relay-log"))
                or key in {"log-slave-updates", "log-replica-updates", "skip-slave-start", "skip-replica-start"}):
            raise Refused("replication_configuration_present")
    for key, expected in instance.expected_options().items():
        values = by_key.get(key)
        if not values or values[-1] is None:
            raise Refused("required_option_missing")
        if values[-1] != expected:
            raise Refused("effective_option_mismatch")
        if key in _FORBIDDEN_PATHS:
            if any(values[-1] == forbidden or values[-1].startswith(forbidden + "/")
                   for forbidden in _FORBIDDEN_PATHS[key]):
                raise Refused("shared_path_selected")


def validate_instance_group(instance: Instance, text: str) -> None:
    """Validate a dedicated instance cnf contains every required setting once."""
    if not isinstance(text, str) or len(text) > 100_000:
        raise Refused("instance_config_invalid")
    section = None
    sections: dict[str, list[tuple[str, str | None]]] = {}
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith(("#", ";")):
            continue
        if line.startswith("[") and line.endswith("]"):
            section = line[1:-1].strip()
            if section in sections:
                raise Refused("duplicate_section")
            sections[section] = []
            continue
        if section is None or "=" not in line:
            raise Refused("instance_config_invalid")
        key, value = line.split("=", 1)
        key = _normalize_option(key.strip())
        sections[section].append((key, value.strip()))
    entries = sections.get(instance.section)
    if entries is None or len(sections) != 1:
        raise Refused("instance_section_invalid")
    got: dict[str, list[str | None]] = {}
    for key, value in entries:
        got.setdefault(key, []).append(value)
        if (key in _KEYRING_OPTIONS or "keyring" in key or "plugin-load" in key
                or "component-load" in key or "keyring" in value.lower()):
            raise Refused("keyring_configuration_present")
        if (key.startswith(("replicate-", "source-", "master-", "slave-", "relay-log"))
                or key in {"log-slave-updates", "log-replica-updates", "skip-slave-start", "skip-replica-start"}):
            raise Refused("replication_configuration_present")
    expected = instance.expected_options()
    if set(got) != set(expected) or any(len(values) != 1 for values in got.values()):
        raise Refused("instance_option_set_invalid")
    if any(got[key][0] != value for key, value in expected.items()):
        raise Refused("instance_option_value_invalid")


def validate_unit_dropin(instance: Instance, text: str) -> None:
    """Require a reset of the vendor initializer and a fixed checker/profile."""
    lines = [line.strip() for line in text.splitlines() if line.strip() and not line.lstrip().startswith("#")]
    if not lines or lines[0] != "[Service]":
        raise Refused("dropin_section_invalid")
    expected = {
        "ExecStartPre=",
        f"ExecStartPre=+/usr/bin/python3 /usr/local/libexec/wpcaregrid-g1-mysql.py preflight --instance {instance.name}",
        f"AppArmorProfile={instance.apparmor_profile}",
        "Environment=HOME=/nonexistent",
        "Environment=PYTHONDONTWRITEBYTECODE=1",
        "IPAddressDeny=any",
        "IPAddressAllow=127.0.0.0/8",
    }
    if set(lines[1:]) != expected:
        raise Refused("dropin_contract_invalid")


def validate_keyring_registry_count(output: str | bytes) -> None:
    """Accept only a sanitized SQL count proving zero registered keyring components."""
    if isinstance(output, bytes):
        try:
            output = output.decode("ascii", errors="strict")
        except UnicodeError:
            raise Refused("component_registry_result_invalid") from None
    if not isinstance(output, str) or output.strip() != "0":
        raise Refused("keyring_component_registered")


def validate_port_probe(available: bool) -> None:
    if available is not True:
        raise Refused("instance_port_unavailable")


def sanitized_result(callable_, *args, **kwargs) -> str:
    """Return stable status only; never surface config, stderr, or command output."""
    try:
        callable_(*args, **kwargs)
    except Refused as exc:
        return f"MYSQL_G1=STOP;CODE={exc}"
    except Exception:
        return "MYSQL_G1=STOP;CODE=internal_error"
    return "MYSQL_G1=PASS"


def artifact_files() -> dict[str, bytes]:
    """Source templates for the exact map. Installer must publish no-overwrite."""
    result: dict[str, bytes] = {}
    for instance in INSTANCES.values():
        opts = instance.expected_options()
        cnf = f"[{instance.section}]\n" + "".join(f"{key}={value}\n" for key, value in opts.items())
        dropin = "\n".join((
            "[Service]", "ExecStartPre=",
            f"ExecStartPre=+/usr/bin/python3 /usr/local/libexec/wpcaregrid-g1-mysql.py preflight --instance {instance.name}",
            f"AppArmorProfile={instance.apparmor_profile}",
            "Environment=HOME=/nonexistent",
            "Environment=PYTHONDONTWRITEBYTECODE=1",
            "IPAddressDeny=any", "IPAddressAllow=127.0.0.0/8", "",
        ))
        result[f"/etc/mysql/mysql.conf.d/90-{instance.name}.cnf"] = cnf.encode()
        result[f"/etc/systemd/system/{instance.unit}.d/90-wpcaregrid-g1.conf"] = dropin.encode()
        result[f"/etc/apparmor.d/{instance.apparmor_profile}"] = apparmor_profile(instance).encode()
    return result


def apparmor_profile(instance: Instance) -> str:
    """Narrow named profile; no attachment, selected explicitly by systemd."""
    # Filesystem access is scoped to the instance. Common executable/config
    # reads are shared; no common datadir, socket, log, keyring, or temp path is
    # granted. A real Ubuntu parser/start test is still required before G1.
    other = next(item for item in INSTANCES.values() if item.name != instance.name)
    return f"""#include <tunables/global>
profile {instance.apparmor_profile} {{
  #include <abstractions/base>
  /usr/sbin/mysqld ix,
  /usr/bin/python3* ix,
  /usr/lib/python3*/** mr,
  /usr/lib/x86_64-linux-gnu/** mr,
  /usr/local/libexec/wpcaregrid-g1-mysql.py r,
  /usr/local/libexec/production_g1_mysql_contract.py r,
  /usr/local/libexec/production_g1_mysql_manifest.json r,
  /sys/kernel/security/apparmor/profiles r,
  /usr/bin/my_print_defaults mr,
  /usr/bin/my_print_defaults ix,
  /usr/lib/mysql/plugin/ r,
  /usr/lib/mysql/plugin/** mr,
  /usr/share/mysql-8.4/ r,
  /usr/share/mysql-8.4/** r,
  /etc/mysql/ r,
  /etc/mysql/my.cnf r,
  /etc/mysql/conf.d/ r,
  /etc/mysql/conf.d/** r,
  /etc/mysql/mysql.conf.d/ r,
  /etc/mysql/mysql.conf.d/** r,
  /usr/lib/systemd/system/ r,
  /usr/lib/systemd/system/mysql@.service r,
  /etc/apparmor.d/ r,
  /etc/apparmor.d/{instance.apparmor_profile} r,
  /etc/systemd/system/ r,
  /etc/systemd/system/mysql@.service.d/ r,
  /etc/systemd/system/mysql@.service.d/** r,
  /etc/systemd/system/{instance.unit}.d/ r,
  /etc/systemd/system/{instance.unit}.d/** r,
  /var/lib/ r,
  {instance.datadir}/ rw,
  {instance.datadir}/** rwk,
  deny /var/lib/mysql/ rwklmix,
  deny /var/lib/mysql/** rwklmix,
  deny {other.datadir}/ rwklmix,
  deny {other.datadir}/** rwklmix,
  /run/mysqld/ r,
  {instance.socket} rwk,
  {instance.pid_file} rwk,
  deny /run/mysqld/mysqld.sock rwk,
  deny {other.socket} rwk,
  deny {other.pid_file} rwk,
  /var/log/mysql/ r,
  {instance.log_file} rw,
  deny {other.log_file} rw,
  /var/tmp/ r,
  {instance.tmpdir}/ rw,
  {instance.tmpdir}/** rwk,
  deny {other.tmpdir}/ rwklmix,
  deny {other.tmpdir}/** rwklmix,
  network inet stream,
  network unix stream,
}}
"""
