#!/usr/bin/env python3
"""Immutable, fail-closed identity contract for WP CareGrid deployments (2.1.3 successor).

Environment identity is selected by a compiled profile name. Runtime callers
cannot supply target overrides. Production configuration is deliberately
incomplete until an independently approved root-owned profile is provisioned.

Successor of ``deployment_environment_contract.py`` (which stays byte-identical:
seven release manifests pin its hash). Difference: GO-13 forbids a new FPM
identity, so production PHP-FPM keeps the shared ``www-data`` UID/GID 33 and
isolation is instead bound to exact versioned, root-owned AppArmor/systemd
confinement artifacts (``deploy/php-fpm-confinement/1.0.1``). Production policy
schema 2 must echo the compiled hashes and service attachments; a root-collected
observation is checked by ``verify_confinement_observation``.

This module is source policy only. It cannot show that a kernel enforces a
profile. A passing source-policy or observation check means the *recorded*
files, unit properties and process labels match; kernel enforcement is shown by
the separate staged-cutover and live-acceptance host gates in
``docs/PHP_FPM_CONFINEMENT_GO13.md``. Nothing here reads a host, loads a
profile or starts a service.

Helper 2.1.3 must load this module and call both ``resolve_environment`` and
``verify_confinement_observation`` in preflight and fail closed. The contract is
not sufficient on its own: it cannot create the evidence it checks.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import PurePosixPath
from types import MappingProxyType
from typing import Mapping


class EnvironmentRefused(ValueError):
    """A stable, non-sensitive reason an environment contract was rejected."""


WWW_DATA_UID = 33
WWW_DATA_GID = 33
CONFINEMENT_SCHEMA = 1
CONFINEMENT_VERSION = "1.0.1"
# SHA-256 of deploy/php-fpm-confinement/1.0.1/SHA256SUMS; pins the whole package.
CONFINEMENT_SUMS_SHA256 = "7d4f1cc98296d75589722b5e864debd57f76feb6c00587bfc654a394152bc137"
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
# Exact installed path -> SHA-256 of the committed 1.0.1 artifact. A unit test
# recomputes these from the repository and fails on drift.
CONFINEMENT_FILE_SHA256: Mapping[str, str] = MappingProxyType({
    _P_PROFILE: "28411d7d3aa861b0961999b89132a53b403960e6b5c90dfa3fa15676a846427f",
    _N_PROFILE: "a0fd100add8c3c98b71af33feb20c739f2eea5df5f2976d90960a6d9403bda08",
    _L_PROFILE: "747eecff50e9c36b6926313cfa58ee70ff1a866c2e5405dfa0bfbb781145118b",
    _COMMON: "7314a492aedd66a9e0afe7fabb83701dda272f4700d9d124fb7352344eeea586",
    _P_UNIT: "3c93e13946f97e48f0c0aa5e5c0fd4b15c46bffd9da2c8e9f47e6ce01b735b0d",
    _N_DROPIN: "a088f9387396ce914ea39d6279cdf0e31a101434073b859434066c957aec4211",
    _L_DROPIN: "be70ceb043fe8468bea21684e96a7b8e423f02ae79dc9b14cee5af219132de77",
    _P_MASTER: "96839f3888df89d9d14724226f292b6d2c634d35057903120251c2756427ecf5",
    _P_POOL: "103a9502bbe9b19422708cba3c5d9a71cdc51147b70a7788734607e794e4b09e",
})


@dataclass(frozen=True)
class ServiceBinding:
    """One FPM service that must be attached to one exact profile."""
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


@dataclass(frozen=True)
class PhpFpmConfinement:
    """Compiled expectation: exact artifact hashes and which services must be attached."""
    package_version: str
    package_sha256sums_sha256: str
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
    php_fpm_confinement: PhpFpmConfinement | None = None


STAGING_NEXT = EnvironmentContract(
    name="staging-next", enabled=True,
    app_root="/var/www/wpcaregrid-staging-new",
    application_link="/var/www/wpcaregrid-staging-new",
    release_root="/var/lib/wpcaregrid-deploy/staging-next/releases",
    incoming_root="/var/lib/wpcaregrid-deploy/staging-next/incoming",
    inbox_root="/var/lib/wpcaregrid-deploy/staging-next/inbox",
    state_root="/var/lib/wpcaregrid-deploy/staging-next/state",
    backup_root="/var/lib/wpcaregrid-deploy/staging-next/database-backups",
    shared_env_file="/var/lib/wpcaregrid-deploy/staging-next/shared/.env",
    shared_storage_root="/var/lib/wpcaregrid-deploy/staging-next/shared/storage",
    shared_vendor_root="/var/lib/wpcaregrid-deploy/staging-next/shared/vendor",
    database="wpcg_staging_next", database_host="127.0.0.1", database_port=3306,
    database_server_uuid_sha256="bc35682d80f0894c2c2c2879552fb8fe13dc2a530868d79d817a6280e31bde1c",
    isolated_database="wpcg_staging_next_restore", isolated_database_host="127.0.0.1",
    isolated_database_port=3307, isolated_database_uuid_sha256=None,
    host="staging-next.wpcaregrid.com", mysql_version="8.4", deploy_user="deploy-staging-next",
    deploy_uid=996, php_version="8.5",
    php_fpm_socket="/run/php/php8.5-fpm.sock", php_fpm_unit="php8.5-fpm.service",
    php_fpm_uid=33, php_fpm_gid=33,
    worker_programs=("wpcaregrid-staging-next-default", "wpcaregrid-staging-next-backups"),
    scheduler_unit="wpcaregrid-staging-next-scheduler.timer",
    maintenance_marker="/var/lib/wpcaregrid-deploy/staging-next/state/maintenance",
    worker_fence_marker="/var/lib/wpcaregrid-deploy/staging-next/state/workers-disabled",
    scheduler_fence_marker="/var/lib/wpcaregrid-deploy/staging-next/state/scheduler-paused",
    # An unattached staging FPM service is not isolated from production (same UID).
    php_fpm_confinement=PhpFpmConfinement(CONFINEMENT_VERSION, CONFINEMENT_SUMS_SHA256,
                                          ("staging-next", "staging-legacy")),
)

# Production is a separate compiled identity with no guessed host or database
# facts. It cannot be resolved until a complete, validated root policy exists.
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

PROFILES: Mapping[str, EnvironmentContract] = MappingProxyType({
    "staging-next": STAGING_NEXT,
    "production": PRODUCTION_TEMPLATE,
})

_PRODUCTION_KEYS = frozenset({
    "schema", "profile", "enabled", "app_root", "application_link", "release_root", "incoming_root",
    "inbox_root", "state_root", "backup_root", "shared_env_file", "shared_storage_root", "shared_vendor_root",
    "database", "database_host", "database_port", "database_server_uuid_sha256",
    "isolated_database", "isolated_database_host", "isolated_database_port",
    "isolated_database_uuid_sha256", "host", "mysql_version", "deploy_user", "deploy_uid", "php_version",
    "php_fpm_socket", "php_fpm_unit", "php_fpm_uid", "php_fpm_gid", "worker_programs",
    "scheduler_unit", "maintenance_marker", "worker_fence_marker", "scheduler_fence_marker",
    "php_fpm_confinement",
})
_CONFINEMENT_KEYS = frozenset({"schema", "package_version", "package_sha256sums_sha256", "files", "services"})
_SERVICE_KEYS = frozenset({"unit", "apparmor_profile", "profile_path", "attachment_path"})
_SHA256 = re.compile(r"[0-9a-f]{64}\Z")
_IDENTIFIER = re.compile(r"[a-z][a-z0-9_]{0,62}\Z")
_HOST = re.compile(r"(?=.{1,253}\Z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\Z")
_PROFILE_NAME = re.compile(r"[a-z][a-z0-9-]{1,31}\Z")


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


def _production_config(data: object) -> EnvironmentContract:
    if not isinstance(data, Mapping):
        raise EnvironmentRefused("production_profile_schema_invalid")
    if type(data.get("schema")) is not int or data["schema"] != 2:
        # Schema 1 (dedicated FPM identity, no confinement binding) is not accepted by this contract.
        raise EnvironmentRefused("production_profile_schema_invalid")
    if set(data) == _PRODUCTION_KEYS - {"php_fpm_confinement"}:
        raise EnvironmentRefused("production_confinement_missing")
    if set(data) != _PRODUCTION_KEYS:
        raise EnvironmentRefused("production_profile_schema_invalid")
    if data["profile"] != "production" or data["enabled"] is not True:
        raise EnvironmentRefused("production_profile_not_enabled")

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
    database_host = data["database_host"]
    isolated_host = data["isolated_database_host"]
    if (not isinstance(database_host, str) or not isinstance(isolated_host, str)
            or database_host != "127.0.0.1" or isolated_host != "127.0.0.1"):
        raise EnvironmentRefused("production_database_host_invalid")
    db_port = _positive_int(data["database_port"], "database_port", maximum=65535)
    isolated_port = _positive_int(data["isolated_database_port"], "isolated_database_port", maximum=65535)
    if db_port == isolated_port:
        raise EnvironmentRefused("production_restore_endpoint_not_isolated")
    for field in ("database_server_uuid_sha256", "isolated_database_uuid_sha256"):
        if not isinstance(data[field], str) or _SHA256.fullmatch(data[field]) is None:
            raise EnvironmentRefused(f"{field}_invalid")
    if data["database_server_uuid_sha256"] == data["isolated_database_uuid_sha256"]:
        raise EnvironmentRefused("production_restore_instance_not_isolated")

    if data["php_version"] != "8.4":
        # The 1.0.1 confinement artifacts bind PHP 8.4 executable, socket and unit names.
        raise EnvironmentRefused("production_php_version_invalid")
    if data["mysql_version"] != "8.4":
        raise EnvironmentRefused("production_mysql_version_invalid")
    deploy_user = data["deploy_user"]
    if deploy_user != "deploy-production":
        raise EnvironmentRefused("production_deploy_identity_invalid")
    socket = _fixed_path(data["php_fpm_socket"], label="php_fpm_socket", root="/run/php")
    expected_socket = f"/run/php/wpcaregrid-production-php{data['php_version']}-fpm.sock"
    expected_unit = f"wpcaregrid-production-php{data['php_version']}-fpm.service"
    if socket != expected_socket or data["php_fpm_unit"] != expected_unit:
        raise EnvironmentRefused("production_php_fpm_socket_invalid")
    uid, gid = _positive_int(data["php_fpm_uid"], "php_fpm_uid"), _positive_int(data["php_fpm_gid"], "php_fpm_gid")
    deploy_uid = _positive_int(data["deploy_uid"], "deploy_uid")
    # GO-13: no new FPM identity. The shared www-data identity is required (not merely allowed);
    # separation comes from the bound confinement below, never from a different UID.
    if uid != WWW_DATA_UID or gid != WWW_DATA_GID \
            or uid != STAGING_NEXT.php_fpm_uid or gid != STAGING_NEXT.php_fpm_gid:
        raise EnvironmentRefused("production_php_fpm_identity_must_be_shared_www_data")
    if deploy_uid in {STAGING_NEXT.deploy_uid, STAGING_NEXT.php_fpm_uid, STAGING_NEXT.php_fpm_gid, uid, gid}:
        raise EnvironmentRefused("production_unix_identity_not_isolated")
    confinement = _confinement_config(data["php_fpm_confinement"])
    workers = data["worker_programs"]
    if (not isinstance(workers, list) or len(workers) != 2
            or any(not isinstance(item, str) for item in workers)
            or len(set(workers)) != 2
            or any(not item.startswith("wpcaregrid-production-")
                   or _PROFILE_NAME.fullmatch(item) is None for item in workers)):
        raise EnvironmentRefused("production_worker_scope_invalid")
    scheduler = data["scheduler_unit"]
    if not isinstance(scheduler, str) or not scheduler.startswith("wpcaregrid-production-") \
            or _PROFILE_NAME.fullmatch(scheduler.removesuffix(".timer")) is None:
        raise EnvironmentRefused("production_scheduler_scope_invalid")

    return EnvironmentContract(
        name="production", enabled=True, app_root=paths["app_root"], application_link=paths["application_link"],
        release_root=paths["release_root"], incoming_root=paths["incoming_root"], inbox_root=paths["inbox_root"],
        state_root=paths["state_root"], backup_root=paths["backup_root"], shared_env_file=paths["shared_env_file"],
        shared_storage_root=paths["shared_storage_root"], shared_vendor_root=paths["shared_vendor_root"], database=database,
        database_host=database_host, database_port=db_port,
        database_server_uuid_sha256=data["database_server_uuid_sha256"],
        isolated_database=isolated_database, isolated_database_host=isolated_host,
        isolated_database_port=isolated_port, isolated_database_uuid_sha256=data["isolated_database_uuid_sha256"],
        host=host, mysql_version=data["mysql_version"], deploy_user=deploy_user, deploy_uid=deploy_uid,
        php_version=data["php_version"], php_fpm_socket=socket, php_fpm_unit=expected_unit,
        php_fpm_uid=uid, php_fpm_gid=gid, worker_programs=tuple(workers), scheduler_unit=scheduler,
        maintenance_marker=paths["maintenance_marker"], worker_fence_marker=paths["worker_fence_marker"],
        scheduler_fence_marker=paths["scheduler_fence_marker"], php_fpm_confinement=confinement,
    )


def _sha(value: object) -> bool:
    return isinstance(value, str) and _SHA256.fullmatch(value) is not None


def _confinement_config(data: object) -> PhpFpmConfinement:
    """Require the root policy to echo the exact compiled confinement bindings."""
    if not isinstance(data, Mapping) or set(data) != _CONFINEMENT_KEYS \
            or type(data["schema"]) is not int or data["schema"] != CONFINEMENT_SCHEMA:
        raise EnvironmentRefused("production_confinement_schema_invalid")
    if data["package_version"] != CONFINEMENT_VERSION or data["package_sha256sums_sha256"] != CONFINEMENT_SUMS_SHA256:
        raise EnvironmentRefused("production_confinement_package_mismatch")
    files = data["files"]
    if not isinstance(files, Mapping) or set(files) != set(CONFINEMENT_FILE_SHA256) \
            or any(not _sha(value) for value in files.values()):
        raise EnvironmentRefused("production_confinement_file_set_invalid")
    if any(files[path] != expected for path, expected in CONFINEMENT_FILE_SHA256.items()):
        raise EnvironmentRefused("production_confinement_hash_mismatch")
    services = data["services"]
    if not isinstance(services, Mapping) or set(services) != set(CONFINEMENT_SERVICES):
        raise EnvironmentRefused("production_confinement_service_binding_invalid")
    for role, binding in CONFINEMENT_SERVICES.items():
        entry = services[role]
        if not isinstance(entry, Mapping) or set(entry) != _SERVICE_KEYS or entry != {
                "unit": binding.unit, "apparmor_profile": binding.apparmor_profile,
                "profile_path": binding.profile_path, "attachment_path": binding.attachment_path}:
            raise EnvironmentRefused("production_confinement_service_binding_invalid")
    return PhpFpmConfinement(CONFINEMENT_VERSION, CONFINEMENT_SUMS_SHA256,
                             ("production", "staging-next", "staging-legacy"))


def resolve_environment(name: str, *, production_config: Mapping[str, object] | None = None) -> EnvironmentContract:
    """Return a compiled staging profile or validate complete production policy."""
    if not isinstance(name, str) or name not in PROFILES:
        raise EnvironmentRefused("environment_unknown")
    if name == "staging-next":
        if production_config is not None:
            raise EnvironmentRefused("environment_override_rejected")
        return STAGING_NEXT
    if production_config is None:
        raise EnvironmentRefused("production_profile_configuration_missing")
    return _production_config(production_config)


def production_required_fields() -> frozenset[str]:
    """Expose schema requirements without leaking any deployment secrets."""
    return _PRODUCTION_KEYS


_OBSERVED_FILE_KEYS = frozenset({"sha256", "uid", "gid", "mode", "type", "nlink"})
_OBSERVED_SERVICE_KEYS = frozenset({"unit", "active", "apparmor_profile_property", "loaded_profile_mode",
                                    "process_labels"})
_OBSERVATION_KEYS = frozenset({"schema", "files", "services", "same_uid_peers"})
_PEER_KEYS = frozenset({"pid", "comm", "label", "unit"})
# Same-UID (www-data) processes allowed to stay unconfined. Empty on purpose: nginx workers, Supervisor CLI
# workers and the scheduler share the UID and can read the other environment's group-readable secrets.
# Accepting any of them is an Owner decision made in a reviewed contract revision, never a default.
ACCEPTED_UNCONFINED_SAME_UID_COMM: frozenset = frozenset()


def _strict_int(value: object) -> bool:
    return type(value) is int


def verify_confinement_observation(contract: EnvironmentContract, observation: object) -> None:
    """Fail closed unless a root-collected observation matches the compiled bindings.

    ``observation`` is data the helper preflight collected as root (never a path or
    command supplied by a caller):

    * ``schema`` = 1
    * ``same_uid_peers``: ``{complete: bool, processes: [{pid, comm, label, unit}]}`` -- every process
      whose real/effective UID is www-data, from a root scan that could read all of them
    * ``files``: ``{installed_path: {sha256, uid, gid, mode, type, nlink}}``
    * ``services``: ``{role: {unit, active, apparmor_profile_property,
      loaded_profile_mode, process_labels}}`` where ``apparmor_profile_property`` is the unit's
      effective ``AppArmorProfile=`` value, ``loaded_profile_mode`` is ``enforce``, ``complain`` or
      ``unloaded``, and ``process_labels`` are the ``/proc/<pid>/attr/current`` values of the master
      and every worker (empty only when ``active`` is false).

    Raises ``EnvironmentRefused`` with a stable code. Returning means only that the *observed*
    files, unit properties and labels match; it is not evidence of kernel enforcement.
    """
    confinement = contract.php_fpm_confinement
    if confinement is None:
        raise EnvironmentRefused("confinement_not_bound")
    if not isinstance(observation, Mapping) or set(observation) != _OBSERVATION_KEYS \
            or type(observation["schema"]) is not int or observation["schema"] != 1 \
            or not isinstance(observation["files"], Mapping) or not isinstance(observation["services"], Mapping):
        raise EnvironmentRefused("confinement_observation_invalid")
    files, services = observation["files"], observation["services"]
    for role in confinement.roles:
        binding = CONFINEMENT_SERVICES[role]
        entry = services.get(role)
        if entry is None:
            raise EnvironmentRefused("confinement_service_unobserved")
        if not isinstance(entry, Mapping) or set(entry) != _OBSERVED_SERVICE_KEYS \
                or not isinstance(entry["process_labels"], (list, tuple)) \
                or any(not isinstance(label, str) for label in entry["process_labels"]) \
                or type(entry["active"]) is not bool \
                or not isinstance(entry["apparmor_profile_property"], str) \
                or not isinstance(entry["loaded_profile_mode"], str):
            raise EnvironmentRefused("confinement_observation_invalid")
        if entry["unit"] != binding.unit:
            raise EnvironmentRefused("confinement_service_unit_mismatch")
        for path in binding.files:
            observed = files.get(path)
            if observed is None:
                raise EnvironmentRefused("confinement_file_missing")
            if not isinstance(observed, Mapping) or set(observed) != _OBSERVED_FILE_KEYS \
                    or not all(_strict_int(observed[key]) for key in ("uid", "gid", "mode", "nlink")):
                raise EnvironmentRefused("confinement_observation_invalid")
            if observed["type"] != "regular":
                raise EnvironmentRefused("confinement_file_not_regular")
            if observed["uid"] != 0 or observed["gid"] != 0:
                raise EnvironmentRefused("confinement_file_not_root_owned")
            if observed["mode"] != 0o644 or observed["nlink"] != 1:
                raise EnvironmentRefused("confinement_file_mode_invalid")
            if not _sha(observed["sha256"]) or observed["sha256"] != CONFINEMENT_FILE_SHA256[path]:
                raise EnvironmentRefused("confinement_file_hash_mismatch")
        # Unmanaged service: an empty or different AppArmorProfile= means this pool is not isolated,
        # whatever files exist on disk.
        if entry["apparmor_profile_property"] == "":
            raise EnvironmentRefused("confinement_service_unattached")
        if entry["apparmor_profile_property"] != binding.apparmor_profile:
            raise EnvironmentRefused("confinement_service_profile_mismatch")
        if entry["loaded_profile_mode"] != "enforce":
            raise EnvironmentRefused("confinement_profile_not_enforced")
        if entry["active"]:
            labels = entry["process_labels"]
            if not labels:
                raise EnvironmentRefused("confinement_process_labels_missing")
            if any(label != f"{binding.apparmor_profile} (enforce)" for label in labels):
                raise EnvironmentRefused("confinement_process_not_confined")
        elif entry["process_labels"]:
            raise EnvironmentRefused("confinement_observation_invalid")
    _verify_same_uid_peers(confinement, observation["same_uid_peers"], services)


def _verify_same_uid_peers(confinement: PhpFpmConfinement, peers: object, services: Mapping) -> None:
    """All same-UID peers must be enforce-attached; an unconfined peer can read the other environment's secrets."""
    if not isinstance(peers, Mapping) or set(peers) != {"complete", "processes"} \
            or not isinstance(peers["processes"], (list, tuple)):
        raise EnvironmentRefused("confinement_observation_invalid")
    if peers["complete"] is not True:
        raise EnvironmentRefused("confinement_peer_inventory_incomplete")
    enforced = {f"{b.apparmor_profile} (enforce)": role for role, b in CONFINEMENT_SERVICES.items()}
    seen_roles: set = set()
    for proc in peers["processes"]:
        if not isinstance(proc, Mapping) or set(proc) != _PEER_KEYS or type(proc["pid"]) is not int \
                or not all(isinstance(proc[k], str) for k in ("comm", "label", "unit")):
            raise EnvironmentRefused("confinement_observation_invalid")
        role = enforced.get(proc["label"])
        if role is None:
            if proc["comm"] in ACCEPTED_UNCONFINED_SAME_UID_COMM:
                continue
            raise EnvironmentRefused("confinement_same_uid_peer_unconfined")
        if proc["unit"] != CONFINEMENT_SERVICES[role].unit:
            raise EnvironmentRefused("confinement_peer_label_outside_unit")
        seen_roles.add(role)
    for role in confinement.roles:
        if services[role]["active"] and role not in seen_roles:
            raise EnvironmentRefused("confinement_peer_inventory_incomplete")
