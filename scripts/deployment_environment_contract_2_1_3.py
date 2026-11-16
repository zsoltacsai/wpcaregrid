#!/usr/bin/env python3
"""Immutable, fail-closed production identity contract for helper 2.1.3 (GO-13).

Successor of ``deployment_environment_contract.py``. That module stays
byte-identical because earlier helper manifests pin its hash. This module is
used only by production helper 2.1.3 and only resolves the ``production``
profile.

Differences from the original contract:

* No new users, groups or FPM UID. Production PHP-FPM keeps the shared
  ``www-data`` identity (33/33). That identity is accepted **only** together
  with an exact PHP-FPM confinement binding (``php_fpm_confinement``) whose
  package/file pin is compiled here, and only while a root-collected
  observation of the host passes ``verify_confinement_observation``.
* The existing ``deploy`` account is the production *transfer* identity only:
  it may own the incoming transfer directory and nothing else. Privileged
  production operations are run directly by the Owner-controlled root
  console (``privileged_invocation = "root-console-direct"``), never through
  sudo.

The confinement pin (``CONFINEMENT_PIN``) is deliberately ``None`` until the
GO-13 Workstream B package is reviewed and approved. While it is unset every
resolution and every observation check refuses with
``confinement_package_not_approved``. The only remaining source change is to
replace ``CONFINEMENT_PIN = None`` with the approved ``ConfinementPin(...)``
(and then re-pin the helper artifacts that hash this file).

This module is pure policy: it never reads a host, loads a profile or starts a
service. Observation data is collected as root by the helper backend.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import PurePosixPath
from types import MappingProxyType
from typing import Mapping


class EnvironmentRefused(ValueError):
    """A stable, non-sensitive reason an environment contract was rejected."""


CONTRACT_VERSION = "2.1.3"
POLICY_SCHEMA = 2
CONFINEMENT_SCHEMA = 2
OBSERVATION_SCHEMA = 2
PRIVILEGED_INVOCATION = "root-console-direct"
TRANSFER_USER = "deploy"
WWW_DATA_UID = 33
WWW_DATA_GID = 33
# Identities that already exist on the shared host and must never be reused as
# the production transfer identity.
STAGING_NEXT_DEPLOY_UID = 996

_APPARMOR = "/etc/apparmor.d"
_SYSTEMD = "/etc/systemd/system"
_P_PROFILE = f"{_APPARMOR}/wpcaregrid-php-fpm-production"
_N_PROFILE = f"{_APPARMOR}/wpcaregrid-php-fpm-staging-next"
_L_PROFILE = f"{_APPARMOR}/wpcaregrid-php-fpm-staging-legacy"
_COMMON = f"{_APPARMOR}/abstractions/wpcaregrid-php-fpm-common"
_P_UNIT = f"{_SYSTEMD}/wpcaregrid-production-php8.4-fpm.service"
_N_DROPIN = f"{_SYSTEMD}/php8.5-fpm.service.d/90-wpcaregrid-confinement.conf"
_L_DROPIN = f"{_SYSTEMD}/php8.4-fpm.service.d/90-wpcaregrid-confinement.conf"
_P_MASTER = "/etc/php/8.4/fpm-wpcaregrid-production/php-fpm.conf"
_P_POOL = "/etc/php/8.4/fpm-wpcaregrid-production/pool.d/wpcaregrid-production.conf"

# Exact installed path set of the confinement package. The hashes are part of
# the pin below; the set itself is fixed by this contract version.
CONFINEMENT_FILES: tuple[str, ...] = (
    _P_PROFILE, _N_PROFILE, _L_PROFILE, _COMMON, _P_UNIT, _N_DROPIN, _L_DROPIN, _P_MASTER, _P_POOL,
)


@dataclass(frozen=True)
class ConfinementPin:
    """The single approved confinement package identity (Workstream B)."""
    package_version: str
    sha256sums_sha256: str
    file_sha256: Mapping[str, str]


# Follow-on pin update: set this to the reviewed ConfinementPin only after the
# Workstream B package is approved. Any intermediate hash is unapproved.
CONFINEMENT_PIN: ConfinementPin | None = None


@dataclass(frozen=True)
class ServiceBinding:
    """One FPM service that must be attached to one exact AppArmor profile."""
    role: str
    unit: str
    apparmor_profile: str
    profile_path: str
    attachment_path: str
    files: tuple[str, ...]


CONFINEMENT_SERVICES: Mapping[str, ServiceBinding] = MappingProxyType({
    "production": ServiceBinding(
        "production", "wpcaregrid-production-php8.4-fpm.service", "wpcaregrid-php-fpm-production",
        _P_PROFILE, _P_UNIT, (_P_PROFILE, _COMMON, _P_UNIT, _P_MASTER, _P_POOL)),
    "staging-next": ServiceBinding(
        "staging-next", "php8.5-fpm.service", "wpcaregrid-php-fpm-staging-next",
        _N_PROFILE, _N_DROPIN, (_N_PROFILE, _COMMON, _N_DROPIN)),
    "staging-legacy": ServiceBinding(
        "staging-legacy", "php8.4-fpm.service", "wpcaregrid-php-fpm-staging-legacy",
        _L_PROFILE, _L_DROPIN, (_L_PROFILE, _COMMON, _L_DROPIN)),
})
# Every pool runs as the same www-data UID, so production is only isolated when
# all three are attached and enforced, not merely the production pool.
REQUIRED_ROLES: tuple[str, ...] = ("production", "staging-next", "staging-legacy")


@dataclass(frozen=True)
class PhpFpmConfinement:
    """Compiled expectation bound into a resolved production contract."""
    package_version: str
    sha256sums_sha256: str
    file_sha256: Mapping[str, str]
    roles: tuple[str, ...]


@dataclass(frozen=True)
class EnvironmentContract:
    name: str
    enabled: bool
    app_root: str | None
    application_link: str | None
    release_root: str | None
    incoming_root: str | None
    inbox_root: str | None
    state_root: str | None
    backup_root: str | None
    shared_env_file: str | None
    shared_storage_root: str | None
    shared_vendor_root: str | None
    database: str | None
    database_host: str | None
    database_port: int | None
    database_server_uuid_sha256: str | None
    isolated_database: str | None
    isolated_database_host: str | None
    isolated_database_port: int | None
    isolated_database_uuid_sha256: str | None
    host: str | None
    mysql_version: str | None
    deploy_user: str | None
    deploy_uid: int | None
    php_version: str | None
    php_fpm_socket: str | None
    php_fpm_unit: str | None
    php_fpm_uid: int | None
    php_fpm_gid: int | None
    worker_programs: tuple[str, ...] | None
    scheduler_unit: str | None
    maintenance_marker: str | None
    worker_fence_marker: str | None
    scheduler_fence_marker: str | None
    privileged_invocation: str | None = None
    php_fpm_confinement: PhpFpmConfinement | None = None


PRODUCTION_TEMPLATE = EnvironmentContract(
    name="production", enabled=False,
    app_root=None, application_link=None, release_root=None, incoming_root=None, inbox_root=None,
    state_root=None, backup_root=None, shared_env_file=None, shared_storage_root=None, shared_vendor_root=None,
    database=None, database_host=None, database_port=None,
    database_server_uuid_sha256=None, isolated_database=None,
    isolated_database_host=None, isolated_database_port=None,
    isolated_database_uuid_sha256=None, host=None, mysql_version=None, deploy_user=None, deploy_uid=None,
    php_version=None, php_fpm_socket=None, php_fpm_unit=None, php_fpm_uid=None, php_fpm_gid=None,
    worker_programs=None, scheduler_unit=None, maintenance_marker=None,
    worker_fence_marker=None, scheduler_fence_marker=None,
)

PROFILES: Mapping[str, EnvironmentContract] = MappingProxyType({"production": PRODUCTION_TEMPLATE})

_ENVIRONMENT_FIELDS = (
    "app_root", "application_link", "release_root", "incoming_root",
    "inbox_root", "state_root", "backup_root", "shared_env_file", "shared_storage_root", "shared_vendor_root",
    "database", "database_host", "database_port", "database_server_uuid_sha256",
    "isolated_database", "isolated_database_host", "isolated_database_port",
    "isolated_database_uuid_sha256", "host", "mysql_version", "deploy_user", "deploy_uid", "php_version",
    "php_fpm_socket", "php_fpm_unit", "php_fpm_uid", "php_fpm_gid", "worker_programs",
    "scheduler_unit", "maintenance_marker", "worker_fence_marker", "scheduler_fence_marker",
    "privileged_invocation", "php_fpm_confinement",
)
_PRODUCTION_KEYS = frozenset({"schema", "profile", "enabled", *_ENVIRONMENT_FIELDS})
_CONFINEMENT_KEYS = frozenset({"schema", "package_version", "package_sha256sums_sha256", "files", "services"})
_SERVICE_KEYS = frozenset({"unit", "apparmor_profile", "profile_path", "attachment_path"})
_SHA256 = re.compile(r"[0-9a-f]{64}\Z")
_VERSION = re.compile(r"[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\Z")
_IDENTIFIER = re.compile(r"[a-z][a-z0-9_]{0,62}\Z")
_PROFILE_NAME = re.compile(r"[a-z][a-z0-9-]{1,31}\Z")


def environment_fields() -> tuple[str, ...]:
    """Environment keys of a production policy (all null in a disabled policy)."""
    return _ENVIRONMENT_FIELDS


def production_required_fields() -> frozenset[str]:
    """Exact production policy keys owned by this contract."""
    return _PRODUCTION_KEYS


def _sha(value: object) -> bool:
    return isinstance(value, str) and _SHA256.fullmatch(value) is not None


def confinement_pin() -> ConfinementPin:
    """Return the approved, well-formed confinement pin or refuse."""
    pin = CONFINEMENT_PIN
    if pin is None:
        raise EnvironmentRefused("confinement_package_not_approved")
    if (not isinstance(pin, ConfinementPin) or not isinstance(pin.package_version, str)
            or _VERSION.fullmatch(pin.package_version) is None or not _sha(pin.sha256sums_sha256)
            or not isinstance(pin.file_sha256, Mapping)
            or set(pin.file_sha256) != set(CONFINEMENT_FILES)
            or any(not _sha(value) for value in pin.file_sha256.values())):
        raise EnvironmentRefused("confinement_package_pin_invalid")
    return pin


def _fixed_path(value: object, *, label: str, root: str | None = None) -> str:
    if not isinstance(value, str):
        raise EnvironmentRefused(f"{label}_invalid")
    path = PurePosixPath(value)
    if (not path.is_absolute() or str(path) != value or ".." in path.parts
            or any(part in {"", "."} for part in path.parts)):
        raise EnvironmentRefused(f"{label}_invalid")
    if root is not None and not _is_within(path, PurePosixPath(root)):
        raise EnvironmentRefused(f"{label}_outside_profile_root")
    return value


def _is_within(path: PurePosixPath, root: PurePosixPath) -> bool:
    return path == root or root in path.parents


def _disjoint(left: str, right: str) -> bool:
    a, b = PurePosixPath(left), PurePosixPath(right)
    return not _is_within(a, b) and not _is_within(b, a)


def _positive_int(value: object, label: str, *, maximum: int = 2**31 - 1) -> int:
    if type(value) is not int or not 0 < value <= maximum:
        raise EnvironmentRefused(f"{label}_invalid")
    return value


def _confinement_config(data: object) -> PhpFpmConfinement:
    """Require the root policy to echo the exact compiled confinement binding."""
    pin = confinement_pin()
    if not isinstance(data, Mapping) or set(data) != _CONFINEMENT_KEYS \
            or type(data["schema"]) is not int or data["schema"] != CONFINEMENT_SCHEMA:
        raise EnvironmentRefused("production_confinement_schema_invalid")
    if data["package_version"] != pin.package_version \
            or data["package_sha256sums_sha256"] != pin.sha256sums_sha256:
        raise EnvironmentRefused("production_confinement_package_mismatch")
    files = data["files"]
    if not isinstance(files, Mapping) or set(files) != set(CONFINEMENT_FILES) \
            or any(not _sha(value) for value in files.values()):
        raise EnvironmentRefused("production_confinement_file_set_invalid")
    if any(files[path] != pin.file_sha256[path] for path in CONFINEMENT_FILES):
        raise EnvironmentRefused("production_confinement_hash_mismatch")
    services = data["services"]
    if not isinstance(services, Mapping) or set(services) != set(CONFINEMENT_SERVICES):
        raise EnvironmentRefused("production_confinement_service_binding_invalid")
    for role, binding in CONFINEMENT_SERVICES.items():
        entry = services[role]
        if not isinstance(entry, Mapping) or set(entry) != _SERVICE_KEYS or dict(entry) != {
                "unit": binding.unit, "apparmor_profile": binding.apparmor_profile,
                "profile_path": binding.profile_path, "attachment_path": binding.attachment_path}:
            raise EnvironmentRefused("production_confinement_service_binding_invalid")
    return PhpFpmConfinement(pin.package_version, pin.sha256sums_sha256,
                             MappingProxyType(dict(pin.file_sha256)), REQUIRED_ROLES)


def _production_config(data: object) -> EnvironmentContract:
    if not isinstance(data, Mapping):
        raise EnvironmentRefused("production_profile_schema_invalid")
    if type(data.get("schema")) is not int or data["schema"] != POLICY_SCHEMA:
        # Schema 1 (dedicated FPM UID, sudo caller) is never accepted by this contract.
        raise EnvironmentRefused("production_profile_schema_invalid")
    if set(data) == _PRODUCTION_KEYS - {"php_fpm_confinement"}:
        raise EnvironmentRefused("production_confinement_missing")
    if set(data) != _PRODUCTION_KEYS:
        raise EnvironmentRefused("production_profile_schema_invalid")
    if data["profile"] != "production" or data["enabled"] is not True:
        raise EnvironmentRefused("production_profile_not_enabled")
    if data["privileged_invocation"] != PRIVILEGED_INVOCATION:
        raise EnvironmentRefused("production_privileged_invocation_invalid")

    paths = {}
    for key in ("app_root", "application_link", "release_root", "incoming_root", "inbox_root", "state_root",
                "backup_root", "shared_env_file", "shared_storage_root", "shared_vendor_root",
                "maintenance_marker", "worker_fence_marker", "scheduler_fence_marker"):
        root = "/var/www/wpcaregrid-production" if key in {"app_root", "application_link", "release_root"} else \
            "/var/lib/wpcaregrid-deploy/production"
        paths[key] = _fixed_path(data[key], label=key, root=root)
    if (paths["app_root"] != "/var/www/wpcaregrid-production"
            or paths["application_link"] != "/var/www/wpcaregrid-production/current"
            or paths["release_root"] != "/var/www/wpcaregrid-production/releases"):
        raise EnvironmentRefused("production_application_paths_mismatch")
    expected_data_paths = {
        "incoming_root": "/var/lib/wpcaregrid-deploy/production/incoming",
        "inbox_root": "/var/lib/wpcaregrid-deploy/production/inbox",
        "state_root": "/var/lib/wpcaregrid-deploy/production/state",
        "backup_root": "/var/lib/wpcaregrid-deploy/production/database-backups",
        "shared_env_file": "/var/lib/wpcaregrid-deploy/production/shared/.env",
        "shared_storage_root": "/var/lib/wpcaregrid-deploy/production/shared/storage",
        "shared_vendor_root": "/var/lib/wpcaregrid-deploy/production/shared/vendor",
        "maintenance_marker": "/var/lib/wpcaregrid-deploy/production/state/maintenance",
        "worker_fence_marker": "/var/lib/wpcaregrid-deploy/production/state/workers-disabled",
        "scheduler_fence_marker": "/var/lib/wpcaregrid-deploy/production/state/scheduler-paused",
    }
    if any(paths[key] != expected for key, expected in expected_data_paths.items()):
        raise EnvironmentRefused("production_path_layout_invalid")
    roots = tuple(expected_data_paths[key] for key in (
        "incoming_root", "inbox_root", "state_root", "backup_root",
        "shared_env_file", "shared_storage_root", "shared_vendor_root",
    ))
    if any(not _disjoint(left, right) for index, left in enumerate(roots) for right in roots[index + 1:]):
        raise EnvironmentRefused("production_path_roles_overlap")
    if any(not _disjoint(value, "/var/lib/wpcaregrid-deploy/staging-next") for value in roots):
        raise EnvironmentRefused("production_staging_path_overlap")

    database = data["database"]
    isolated_database = data["isolated_database"]
    if (not isinstance(database, str) or _IDENTIFIER.fullmatch(database) is None
            or not database.startswith("wpcg_prod_") or not isinstance(isolated_database, str)
            or _IDENTIFIER.fullmatch(isolated_database) is None or isolated_database == database
            or not isolated_database.startswith("wpcg_prod_restore_")):
        raise EnvironmentRefused("production_database_identity_invalid")
    host = data["host"]
    if not isinstance(host, str) or host not in {"wpcaregrid.com", "www.wpcaregrid.com"}:
        raise EnvironmentRefused("production_host_identity_invalid")
    if data["database_host"] != "127.0.0.1" or data["isolated_database_host"] != "127.0.0.1":
        raise EnvironmentRefused("production_database_host_invalid")
    db_port = _positive_int(data["database_port"], "database_port", maximum=65535)
    isolated_port = _positive_int(data["isolated_database_port"], "isolated_database_port", maximum=65535)
    if db_port == isolated_port:
        raise EnvironmentRefused("production_restore_endpoint_not_isolated")
    for field in ("database_server_uuid_sha256", "isolated_database_uuid_sha256"):
        if not _sha(data[field]):
            raise EnvironmentRefused(f"{field}_invalid")
    if data["database_server_uuid_sha256"] == data["isolated_database_uuid_sha256"]:
        raise EnvironmentRefused("production_restore_instance_not_isolated")

    if data["php_version"] != "8.4":
        # The confinement package binds the PHP 8.4 executable, socket and unit names.
        raise EnvironmentRefused("production_php_version_invalid")
    if data["mysql_version"] != "8.4":
        raise EnvironmentRefused("production_mysql_version_invalid")
    if data["deploy_user"] != TRANSFER_USER:
        # deploy is the untrusted transfer identity only; no new account is created.
        raise EnvironmentRefused("production_deploy_identity_invalid")
    socket = _fixed_path(data["php_fpm_socket"], label="php_fpm_socket", root="/run/php")
    expected_socket = "/run/php/wpcaregrid-production-php8.4-fpm.sock"
    expected_unit = CONFINEMENT_SERVICES["production"].unit
    if socket != expected_socket or data["php_fpm_unit"] != expected_unit:
        raise EnvironmentRefused("production_php_fpm_socket_invalid")
    uid = _positive_int(data["php_fpm_uid"], "php_fpm_uid")
    gid = _positive_int(data["php_fpm_gid"], "php_fpm_gid")
    deploy_uid = _positive_int(data["deploy_uid"], "deploy_uid")
    # Validate the confinement binding before admitting the shared identity: the
    # shared www-data UID/GID is acceptable only with the exact approved binding.
    confinement = _confinement_config(data["php_fpm_confinement"])
    if uid != WWW_DATA_UID or gid != WWW_DATA_GID:
        raise EnvironmentRefused("production_php_fpm_identity_must_be_shared_www_data")
    if deploy_uid in {STAGING_NEXT_DEPLOY_UID, WWW_DATA_UID, WWW_DATA_GID}:
        raise EnvironmentRefused("production_unix_identity_not_isolated")
    workers = data["worker_programs"]
    if (not isinstance(workers, list) or len(workers) != 2
            or any(not isinstance(item, str) for item in workers)
            or len(set(workers)) != 2
            or any(not item.startswith("wpcaregrid-production-")
                   or _PROFILE_NAME.fullmatch(item) is None for item in workers)):
        raise EnvironmentRefused("production_worker_scope_invalid")
    scheduler = data["scheduler_unit"]
    if not isinstance(scheduler, str) or not scheduler.startswith("wpcaregrid-production-") \
            or not scheduler.endswith(".timer") \
            or _PROFILE_NAME.fullmatch(scheduler.removesuffix(".timer")) is None:
        raise EnvironmentRefused("production_scheduler_scope_invalid")

    return EnvironmentContract(
        name="production", enabled=True, app_root=paths["app_root"], application_link=paths["application_link"],
        release_root=paths["release_root"], incoming_root=paths["incoming_root"], inbox_root=paths["inbox_root"],
        state_root=paths["state_root"], backup_root=paths["backup_root"], shared_env_file=paths["shared_env_file"],
        shared_storage_root=paths["shared_storage_root"], shared_vendor_root=paths["shared_vendor_root"],
        database=database, database_host="127.0.0.1", database_port=db_port,
        database_server_uuid_sha256=data["database_server_uuid_sha256"],
        isolated_database=isolated_database, isolated_database_host="127.0.0.1",
        isolated_database_port=isolated_port, isolated_database_uuid_sha256=data["isolated_database_uuid_sha256"],
        host=host, mysql_version="8.4", deploy_user=TRANSFER_USER, deploy_uid=deploy_uid,
        php_version="8.4", php_fpm_socket=socket, php_fpm_unit=expected_unit,
        php_fpm_uid=uid, php_fpm_gid=gid, worker_programs=tuple(workers), scheduler_unit=scheduler,
        maintenance_marker=paths["maintenance_marker"], worker_fence_marker=paths["worker_fence_marker"],
        scheduler_fence_marker=paths["scheduler_fence_marker"],
        privileged_invocation=PRIVILEGED_INVOCATION, php_fpm_confinement=confinement,
    )


def resolve_environment(name: str, *, production_config: Mapping[str, object] | None = None) -> EnvironmentContract:
    """Validate a complete production policy. Staging is not served by this contract."""
    confinement_pin()
    if not isinstance(name, str) or name not in PROFILES:
        raise EnvironmentRefused("environment_unknown")
    if production_config is None:
        raise EnvironmentRefused("production_profile_configuration_missing")
    return _production_config(production_config)


# --------------------------------------------------------------------------
# Schema-2 observation (collected as root by the helper backend).
# --------------------------------------------------------------------------

_OBSERVATION_KEYS = frozenset({"schema", "apparmor_enabled", "files", "services", "unbound_fpm_processes"})
_OBSERVED_FILE_KEYS = frozenset({"sha256", "uid", "gid", "mode", "type", "nlink", "parents_trusted"})
_OBSERVED_SERVICE_KEYS = frozenset({"unit", "load_state", "active_state", "main_pid",
                                    "apparmor_profile_property", "loaded_profile_mode", "processes"})
_OBSERVED_PROCESS_KEYS = frozenset({"pid", "role", "label"})
_UNBOUND_KEYS = frozenset({"pid", "label"})


def _pid(value: object) -> bool:
    return type(value) is int and 1 < value < 2**22 + 1


def verify_confinement_observation(contract: EnvironmentContract, observation: object) -> None:
    """Refuse unless a root-collected schema-2 observation matches the binding exactly.

    ``observation``::

        {"schema": 2, "apparmor_enabled": bool,
         "files": {installed_path: {sha256, uid, gid, mode, type, nlink, parents_trusted}},
         "services": {role: {unit, load_state, active_state, main_pid,
                             apparmor_profile_property, loaded_profile_mode,
                             processes: [{pid, role: "master"|"worker"|"other", label}]}},
         "unbound_fpm_processes": [{pid, label}]}

    Missing, extra or malformed evidence refuses. Every role's service must be
    loaded, active, attached (exact ``AppArmorProfile=``, no ``-`` fail-open
    prefix) to its profile, the profile must be loaded in enforce mode, and the
    master plus every worker must carry ``<profile> (enforce)``. Any PHP-FPM
    process outside the bound services refuses. Passing means only that the
    recorded state matches; kernel enforcement is a separate live gate.
    """
    pin = confinement_pin()
    confinement = getattr(contract, "php_fpm_confinement", None)
    if (getattr(contract, "name", None) != "production" or not isinstance(confinement, PhpFpmConfinement)
            or confinement.package_version != pin.package_version
            or confinement.sha256sums_sha256 != pin.sha256sums_sha256
            or dict(confinement.file_sha256) != dict(pin.file_sha256)
            or tuple(confinement.roles) != REQUIRED_ROLES):
        raise EnvironmentRefused("confinement_not_bound")
    if not isinstance(observation, Mapping) or set(observation) != _OBSERVATION_KEYS \
            or type(observation["schema"]) is not int or observation["schema"] != OBSERVATION_SCHEMA \
            or not isinstance(observation["files"], Mapping) or not isinstance(observation["services"], Mapping) \
            or not isinstance(observation["unbound_fpm_processes"], (list, tuple)) \
            or type(observation["apparmor_enabled"]) is not bool:
        raise EnvironmentRefused("confinement_observation_invalid")
    if observation["apparmor_enabled"] is not True:
        raise EnvironmentRefused("confinement_apparmor_disabled")

    files = observation["files"]
    missing_files = set(CONFINEMENT_FILES) - set(files)
    if missing_files:
        raise EnvironmentRefused("confinement_file_missing")
    if set(files) != set(CONFINEMENT_FILES):
        raise EnvironmentRefused("confinement_observation_extra_file")
    for path in CONFINEMENT_FILES:
        observed = files[path]
        if not isinstance(observed, Mapping) or set(observed) != _OBSERVED_FILE_KEYS \
                or not all(type(observed[key]) is int for key in ("uid", "gid", "mode", "nlink")) \
                or type(observed["parents_trusted"]) is not bool or not isinstance(observed["type"], str):
            raise EnvironmentRefused("confinement_observation_invalid")
        if observed["type"] != "regular":
            raise EnvironmentRefused("confinement_file_not_regular")
        if observed["uid"] != 0 or observed["gid"] != 0:
            raise EnvironmentRefused("confinement_file_not_root_owned")
        if observed["mode"] != 0o644 or observed["nlink"] != 1:
            raise EnvironmentRefused("confinement_file_mode_invalid")
        if observed["parents_trusted"] is not True:
            raise EnvironmentRefused("confinement_file_parent_untrusted")
        if not _sha(observed["sha256"]) or observed["sha256"] != pin.file_sha256[path]:
            raise EnvironmentRefused("confinement_file_hash_mismatch")

    services = observation["services"]
    if set(REQUIRED_ROLES) - set(services):
        raise EnvironmentRefused("confinement_service_unobserved")
    if set(services) != set(REQUIRED_ROLES):
        raise EnvironmentRefused("confinement_observation_extra_service")
    seen_pids: set[int] = set()
    for role in REQUIRED_ROLES:
        binding = CONFINEMENT_SERVICES[role]
        entry = services[role]
        if not isinstance(entry, Mapping) or set(entry) != _OBSERVED_SERVICE_KEYS \
                or not all(isinstance(entry[key], str) for key in (
                    "unit", "load_state", "active_state", "apparmor_profile_property", "loaded_profile_mode")) \
                or type(entry["main_pid"]) is not int or not isinstance(entry["processes"], (list, tuple)):
            raise EnvironmentRefused("confinement_observation_invalid")
        if entry["unit"] != binding.unit:
            raise EnvironmentRefused("confinement_service_unit_mismatch")
        if entry["load_state"] != "loaded" or entry["active_state"] != "active" or not _pid(entry["main_pid"]):
            raise EnvironmentRefused("confinement_service_not_active")
        attachment = entry["apparmor_profile_property"]
        if attachment == "":
            raise EnvironmentRefused("confinement_service_unattached")
        if attachment.startswith("-"):
            raise EnvironmentRefused("confinement_service_attachment_fail_open")
        if attachment != binding.apparmor_profile:
            raise EnvironmentRefused("confinement_service_profile_mismatch")
        if entry["loaded_profile_mode"] != "enforce":
            raise EnvironmentRefused("confinement_profile_not_enforced")
        masters = workers = 0
        expected_label = f"{binding.apparmor_profile} (enforce)"
        for process in entry["processes"]:
            if not isinstance(process, Mapping) or set(process) != _OBSERVED_PROCESS_KEYS \
                    or not _pid(process["pid"]) or not isinstance(process["label"], str) \
                    or process["role"] not in {"master", "worker", "other"}:
                raise EnvironmentRefused("confinement_observation_invalid")
            if process["pid"] in seen_pids:
                raise EnvironmentRefused("confinement_observation_invalid")
            seen_pids.add(process["pid"])
            if process["role"] == "other":
                raise EnvironmentRefused("confinement_service_foreign_process")
            if process["role"] == "master":
                if process["pid"] != entry["main_pid"]:
                    raise EnvironmentRefused("confinement_observation_invalid")
                masters += 1
            else:
                workers += 1
            if process["label"] != expected_label:
                raise EnvironmentRefused("confinement_process_not_confined")
        if masters != 1:
            raise EnvironmentRefused("confinement_master_label_missing")
        if workers < 1:
            raise EnvironmentRefused("confinement_worker_labels_missing")

    for process in observation["unbound_fpm_processes"]:
        if not isinstance(process, Mapping) or set(process) != _UNBOUND_KEYS or not _pid(process["pid"]) \
                or not isinstance(process["label"], str):
            raise EnvironmentRefused("confinement_observation_invalid")
    if observation["unbound_fpm_processes"]:
        raise EnvironmentRefused("confinement_unbound_fpm_process")
