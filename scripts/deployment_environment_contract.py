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
    release_root: str | None
    state_root: str | None
    backup_root: str | None
    database: str | None
    database_host: str | None
    database_port: int | None
    database_server_uuid_sha256: str | None
    isolated_database: str | None
    isolated_database_host: str | None
    isolated_database_port: int | None
    isolated_database_uuid_sha256: str | None
    host: str | None
    deploy_uid: int | None
    php_version: str | None
    php_fpm_socket: str | None
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
    release_root="/var/lib/wpcaregrid-deploy/staging-next/releases",
    state_root="/var/lib/wpcaregrid-deploy/staging-next/state",
    backup_root="/var/lib/wpcaregrid-deploy/staging-next/database-backups",
    database="wpcg_staging_next", database_host="127.0.0.1", database_port=3306,
    database_server_uuid_sha256=None,
    isolated_database="wpcg_staging_next_restore", isolated_database_host="127.0.0.1",
    isolated_database_port=3307, isolated_database_uuid_sha256=None,
    host="staging-next.wpcaregrid.com", deploy_uid=996, php_version="8.5",
    php_fpm_socket="/run/php/php8.5-fpm.sock", php_fpm_uid=33, php_fpm_gid=33,
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
    app_root=None, release_root=None, state_root=None, backup_root=None,
    database=None, database_host=None, database_port=None,
    database_server_uuid_sha256=None, isolated_database=None,
    isolated_database_host=None, isolated_database_port=None,
    isolated_database_uuid_sha256=None, host=None, deploy_uid=None,
    php_version=None, php_fpm_socket=None, php_fpm_uid=None, php_fpm_gid=None,
    worker_programs=None, scheduler_unit=None, maintenance_marker=None,
    worker_fence_marker=None, scheduler_fence_marker=None,
)

PROFILES: Mapping[str, EnvironmentContract] = MappingProxyType({
    "staging-next": STAGING_NEXT,
    "production": PRODUCTION_TEMPLATE,
})

_PRODUCTION_KEYS = frozenset({
    "schema", "profile", "enabled", "app_root", "release_root", "state_root", "backup_root",
    "database", "database_host", "database_port", "database_server_uuid_sha256",
    "isolated_database", "isolated_database_host", "isolated_database_port",
    "isolated_database_uuid_sha256", "host", "deploy_uid", "php_version",
    "php_fpm_socket", "php_fpm_uid", "php_fpm_gid", "worker_programs",
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

    paths = {
        key: _fixed_path(data[key], label=key, root="/var/www/wpcaregrid" if key in {"app_root", "release_root"}
                         else "/var/lib/wpcaregrid-deploy/production")
        for key in ("app_root", "release_root", "state_root", "backup_root", "maintenance_marker",
                    "worker_fence_marker", "scheduler_fence_marker")
    }
    if paths["app_root"] != "/var/www/wpcaregrid" or paths["release_root"] != "/var/www/wpcaregrid/releases":
        raise EnvironmentRefused("production_application_paths_mismatch")
    roots = (paths["state_root"], paths["backup_root"], paths["maintenance_marker"],
             paths["worker_fence_marker"], paths["scheduler_fence_marker"])
    if any(not value.startswith("/var/lib/wpcaregrid-deploy/production/") for value in roots):
        raise EnvironmentRefused("production_state_path_mismatch")
    if any(not _disjoint(value, "/var/lib/wpcaregrid-deploy/staging-next") for value in roots):
        raise EnvironmentRefused("production_staging_path_overlap")

    database = data["database"]
    isolated_database = data["isolated_database"]
    if (not isinstance(database, str) or _IDENTIFIER.fullmatch(database) is None
            or database == "wpcg_staging_next" or not isinstance(isolated_database, str)
            or _IDENTIFIER.fullmatch(isolated_database) is None or isolated_database == database
            or isolated_database == "wpcg_staging_next"):
        raise EnvironmentRefused("production_database_identity_invalid")
    host = data["host"]
    if not isinstance(host, str) or _HOST.fullmatch(host) is None or host.startswith("staging"):
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
    socket = _fixed_path(data["php_fpm_socket"], label="php_fpm_socket", root="/run/php")
    if not re.fullmatch(r"/run/php/php8\.[45]-fpm\.sock", socket):
        raise EnvironmentRefused("production_php_fpm_socket_invalid")
    uid, gid = _positive_int(data["php_fpm_uid"], "php_fpm_uid"), _positive_int(data["php_fpm_gid"], "php_fpm_gid")
    deploy_uid = _positive_int(data["deploy_uid"], "deploy_uid")
    if deploy_uid in {STAGING_NEXT.deploy_uid, uid}:
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
        name="production", enabled=True, app_root=paths["app_root"], release_root=paths["release_root"],
        state_root=paths["state_root"], backup_root=paths["backup_root"], database=database,
        database_host=database_host, database_port=db_port,
        database_server_uuid_sha256=data["database_server_uuid_sha256"],
        isolated_database=isolated_database, isolated_database_host=isolated_host,
        isolated_database_port=isolated_port, isolated_database_uuid_sha256=data["isolated_database_uuid_sha256"],
        host=host, deploy_uid=deploy_uid, php_version=data["php_version"], php_fpm_socket=socket,
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
