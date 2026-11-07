#!/usr/bin/env python3
"""Immutable, fail-closed identity contract for WP CareGrid deployments.

Environment identity is selected by a compiled profile name. Runtime callers
cannot supply target overrides. Production configuration is deliberately
incomplete until an independently approved root-owned profile is provisioned.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import PurePosixPath
from types import MappingProxyType
from typing import Mapping


class EnvironmentRefused(ValueError):
    """A stable, non-sensitive reason an environment contract was rejected."""


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
})
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
    if not isinstance(data, Mapping) or set(data) != _PRODUCTION_KEYS:
        raise EnvironmentRefused("production_profile_schema_invalid")
    if type(data["schema"]) is not int or data["schema"] != 1 or data["profile"] != "production" \
            or data["enabled"] is not True:
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
    roots = tuple(value for key, value in paths.items() if key not in {"app_root", "application_link", "release_root"})
    if any(not value.startswith("/var/lib/wpcaregrid-deploy/production/") for value in roots):
        raise EnvironmentRefused("production_state_path_mismatch")
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

    if data["php_version"] not in {"8.4", "8.5"}:
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
    if deploy_uid in {STAGING_NEXT.deploy_uid, STAGING_NEXT.php_fpm_uid, STAGING_NEXT.php_fpm_gid, uid, gid} \
            or uid == STAGING_NEXT.php_fpm_uid \
            or gid == STAGING_NEXT.php_fpm_gid:
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
        scheduler_fence_marker=paths["scheduler_fence_marker"],
    )


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
