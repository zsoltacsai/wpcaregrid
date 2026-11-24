#!/usr/bin/env python3
"""Immutable, fail-closed production identity contract for helper 2.1.7 (GO-13, GO-20 PHP 8.5).

Successor of ``deployment_environment_contract.py``. That module stays
byte-identical because earlier helper manifests pin its hash. This module is
used only by production helper 2.1.7 and only resolves the ``production``
profile.

Successor of ``deployment_environment_contract_2_1_4.py`` (unchanged, still bound to
package 1.0.5 / PHP 8.4). The only changes in 2.1.7 are those PHP 8.5 requires: the
pin binds package 1.0.8 (``docs/PHP_FPM_CONFINEMENT_GO20_PHP85_1_0_8.md``), production runs
``wpcaregrid-production-php8.5-fpm.service`` on ``/run/php/wpcaregrid-production-php8.5-fpm.sock``
(``php_version`` ``8.5``, ``/usr/sbin/php-fpm8.5``), and the transient production CLI
classes run ``/usr/bin/php8.5``. Production FPM and Nginx are the only required service roles. The stock
``php8.5-fpm.service`` cgroup is inventoried as a forbidden sentinel, not as a role.

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

The confinement pin (``CONFINEMENT_PIN``) binds the approved and merged
Workstream B package **1.0.8**: its Git tree object, ``SHA256SUMS`` digest, the
exact seventeen installed files, the seven service roles and both transient
production CLI classes. There is no unset/placeholder pin. A pin that does not
exactly match this module still refuses with ``confinement_package_pin_invalid``.

This module is pure policy: it never reads a host, loads a profile or starts a
service. Observation data is collected as root by the helper backend.
"""

from __future__ import annotations

import hashlib
import json
import re
from dataclasses import dataclass
from pathlib import PurePosixPath
from types import MappingProxyType
from typing import Mapping


class EnvironmentRefused(ValueError):
    """A stable, non-sensitive reason an environment contract was rejected."""


CONTRACT_VERSION = "2.1.7"
POLICY_SCHEMA = 2
# The confinement binding gained a package tree id, the split build CLI profile and
# explicit transient-class bindings in GO-14; the previous shape is not accepted.
CONFINEMENT_SCHEMA = 3
OBSERVATION_SCHEMA = 3
PRIVILEGED_INVOCATION = "root-console-direct"
TRANSFER_USER = "deploy"
WWW_DATA_UID = 33
WWW_DATA_GID = 33
# Production database access from the deploy CLI is Unix-socket only (Owner decision:
# the deploy-CLI profile has no network). Source default from the repository's
# production MySQL instance contract (scripts/production_g1_mysql_contract.py,
# instance "wpcaregrid-production"); NOT yet verified on any host. The root policy
# must name exactly this path; the helper verifies on the host that this path is the
# one listening socket of that instance's mysqld, and refuses otherwise. No TCP fallback.
PRODUCTION_DATABASE_SOCKET = "/run/mysqld/wpcaregrid-production.sock"
PRODUCTION_DATABASE_UNIT = "mysql@wpcaregrid-production.service"
PRODUCTION_DATABASE_EXECUTABLE = "/usr/sbin/mysqld"
# Other instances' sockets on the same host (shared distribution instance, restore instance).
FOREIGN_DATABASE_SOCKETS = frozenset({"/run/mysqld/mysqld.sock", "/run/mysqld/wpcaregrid-production-restore.sock"})
# Identities that already exist on the shared host and must never be reused as
# the production transfer identity.
STAGING_NEXT_DEPLOY_UID = 996

# Installed paths, units and profile names follow the reviewed, merged Workstream B
# package 1.0.8 (deploy/php-fpm-confinement/1.0.8/binding.json): the original
# structure plus the split production CLI profiles
# wpcaregrid-php-cli-production-deploy (runtime probe only) and
# wpcaregrid-php-cli-production-build (offline package:discover/config:cache).
_APPARMOR = "/etc/apparmor.d"
_SYSTEMD = "/etc/systemd/system"
_P_PROFILE = f"{_APPARMOR}/wpcaregrid-php-fpm-production"
_COMMON = f"{_APPARMOR}/abstractions/wpcaregrid-php-fpm-common"
_X_PROFILE = f"{_APPARMOR}/wpcaregrid-nginx"
_P_PROFILE = f"{_APPARMOR}/wpcaregrid-php-fpm-production"
_P_UNIT = f"{_SYSTEMD}/wpcaregrid-production-php8.5-fpm.service"
_X_DROPIN = f"{_SYSTEMD}/nginx.service.d/90-wpcaregrid-confinement.conf"
_D_PROFILE = f"{_APPARMOR}/wpcaregrid-php-cli-production-deploy"
_B_PROFILE = f"{_APPARMOR}/wpcaregrid-php-cli-production-build"
_P_MASTER = "/etc/php/8.5/fpm-wpcaregrid-production/php-fpm.conf"
_P_POOL = "/etc/php/8.5/fpm-wpcaregrid-production/pool.d/wpcaregrid-production.conf"

CONFINEMENT_FILES: tuple[str, ...] = (
    _COMMON, _X_PROFILE, _B_PROFILE, _D_PROFILE, _P_PROFILE, _P_MASTER, _P_POOL, _X_DROPIN, _P_UNIT,
)


@dataclass(frozen=True)
class ConfinementPin:
    """The single approved confinement package identity (Workstream B 1.0.8)."""
    package_version: str
    sha256sums_sha256: str
    package_tree: str
    file_sha256: Mapping[str, str]


# Approved, merged Workstream B package 1.0.8. The tree id is the immutable Git
# tree object of deploy/php-fpm-confinement/1.0.8; SHA256SUMS and the per-file
# hashes are re-derived from the package bytes. No intermediate/unset pin exists.
CONFINEMENT_PIN: ConfinementPin | None = ConfinementPin(
    package_version="1.0.8",
    sha256sums_sha256="12b3a60f6cb0211a57b048cfe83c9243ab7726704fdcff12dffd0011c8da93e2",
    package_tree="305dc9f453b8f531bb5db5642d59852741c7c709",
    file_sha256=MappingProxyType({
        '/etc/apparmor.d/abstractions/wpcaregrid-php-fpm-common':
            '995c8f7ce449342bd033bc1258c13a588d3485dbd3788277224c8cb217b5fc35',
        '/etc/apparmor.d/wpcaregrid-nginx':
            '0fcb54303fbd75174593ca18f780c499e34b6646413254297a97374d9f64f787',
        '/etc/apparmor.d/wpcaregrid-php-cli-production-build':
            'ffc05f34fec57b2ce3f68cd9833d786ea0bff0b404e720c40b08a6f5c713db56',
        '/etc/apparmor.d/wpcaregrid-php-cli-production-deploy':
            '9499659d4f3247d3eddafa4f16bd7ff3fb853d656a70bbca48894f65c1017310',
        '/etc/apparmor.d/wpcaregrid-php-fpm-production':
            '67cf2f9ae307927fd08ef3c8bd84eee596a3ca09124beac57b5140ddb189c032',
        '/etc/php/8.5/fpm-wpcaregrid-production/php-fpm.conf':
            '653d2e77e5eca47d9b552a4731fc59f5eb878c8d01b361b9eea397a1c8599e6d',
        '/etc/php/8.5/fpm-wpcaregrid-production/pool.d/wpcaregrid-production.conf':
            '7da6cf53b437173f9faed64ea8f572ecfd69c4cede8a502c2d12f6e3fe4cf96f',
        '/etc/systemd/system/nginx.service.d/90-wpcaregrid-confinement.conf':
            '50e5b0479ae043675f74039dfd7a71ed0cb10b5adc64b8faf68e03134f927194',
        '/etc/systemd/system/wpcaregrid-production-php8.5-fpm.service':
            '0109efb13bf3d1bb08b78e75f47fecd4285022905b139a35d0065f12bd07fd28',
    }),
)

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
        "production", "wpcaregrid-production-php8.5-fpm.service", "wpcaregrid-php-fpm-production",
        _P_PROFILE, _P_UNIT, (_P_PROFILE, _COMMON, _P_UNIT, _P_MASTER, _P_POOL)),
    "nginx": ServiceBinding(
        "nginx", "nginx.service", "wpcaregrid-nginx", _X_PROFILE, _X_DROPIN, (_X_PROFILE, _X_DROPIN)),
})
REQUIRED_ROLES: tuple[str, ...] = ("production", "nginx")
# Scan the stock FPM unit as a forbidden cgroup sentinel; it is never an install role.
INVENTORY_SERVICE_UNITS: tuple[str, ...] = (
    "wpcaregrid-production-php8.5-fpm.service", "nginx.service", "php8.5-fpm.service")

# Production application PHP run by the root helper, never by the FPM profile.
DEPLOY_CLI_PROFILE = "wpcaregrid-php-cli-production-deploy"
DEPLOY_CLI_PROFILE_PATH = _D_PROFILE
DEPLOY_CLI_EXECUTABLE = "/usr/bin/php8.5"
BUILD_CLI_PROFILE = "wpcaregrid-php-cli-production-build"
BUILD_CLI_PROFILE_PATH = _B_PROFILE
BUILD_CLI_EXECUTABLE = "/usr/bin/php8.5"


@dataclass(frozen=True)
class TransientClass:
    """One short-lived production www-data class started by the root helper console."""
    name: str
    apparmor_profile: str
    profile_path: str
    files: tuple[str, ...]
    executable: str


# Both production application-PHP classes are transient, run only inside the
# root helper's own console session and never as a service. Since 1.0.5 the package splits the
# read-only runtime probe (deploy) from the offline cache builders (build).
TRANSIENT_CLASSES: Mapping[str, TransientClass] = MappingProxyType({
    "production-deploy-cli": TransientClass(
        "production-deploy-cli", DEPLOY_CLI_PROFILE, DEPLOY_CLI_PROFILE_PATH,
        (_COMMON, DEPLOY_CLI_PROFILE_PATH), DEPLOY_CLI_EXECUTABLE),
    "production-build-cli": TransientClass(
        "production-build-cli", BUILD_CLI_PROFILE, BUILD_CLI_PROFILE_PATH,
        (_COMMON, BUILD_CLI_PROFILE_PATH), BUILD_CLI_EXECUTABLE),
})
TRANSIENT_ROLES: tuple[str, ...] = tuple(TRANSIENT_CLASSES)
# Long-running roles that must be active with at least one www-data worker.
SERVING_ROLES: tuple[str, ...] = ("production", "nginx")
# Job roles may be idle (oneshot scheduler, stopped programs) but stay attached and enforced.
JOB_ROLES: tuple[str, ...] = ()
JOBS_PROFILE = None
# Shim-free model (Owner decision): no www-data crontab, /etc/crontab or
# /etc/cron.d entry, and no www-data at job may exist; there is no unconfined class.
WWW_DATA_CRONTAB_PATH = "/var/spool/cron/crontabs/www-data"
AT_SPOOL_PATH = "/var/spool/cron/atjobs"
# The root helper console: a root login session scope, never a service unit.
_HELPER_SESSION_CGROUP = re.compile(r"/user\.slice/user-0\.slice/session-[1-9][0-9]{0,9}\.scope")
_JOB_EXECUTABLES = frozenset({"/usr/bin/php8.5", "/usr/bin/dash", "/usr/bin/bash",
                              "/usr/bin/flock", "/usr/bin/logger"})
# Exact executables each www-data class may run (from the exec rules of B's profiles).
CLASS_EXECUTABLES: Mapping[str, frozenset] = MappingProxyType({
    "production": frozenset({"/usr/sbin/php-fpm8.5", "/usr/bin/kill"}),
    "nginx": frozenset({"/usr/sbin/nginx"}),
    "production-deploy-cli": frozenset({DEPLOY_CLI_EXECUTABLE}),
    "production-build-cli": frozenset({BUILD_CLI_EXECUTABLE}),
})

# Descriptor targets a peer of each side may never hold (other environment's secrets/state).
_PRODUCTION_RELEASES = "/var/www/wpcaregrid-production/releases/"
_PRODUCTION_PRIVATE = ("/var/lib/wpcaregrid-deploy/production/",
                       "/var/www/wpcaregrid-production/shared/",
                       _PRODUCTION_RELEASES,
                       "/var/log/wpcaregrid-production/")
_STAGING_PRIVATE = ("/var/lib/wpcaregrid-deploy/staging-next/", "/var/www/wpcaregrid-staging-new/",
                    "/var/www/wpcaregrid/")


@dataclass(frozen=True)
class PhpFpmConfinement:
    """Compiled expectation bound into a resolved production contract."""
    package_version: str
    sha256sums_sha256: str
    package_tree: str
    file_sha256: Mapping[str, str]
    roles: tuple[str, ...]
    transients: tuple[str, ...]


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
    database_socket: str | None = None


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
    "database", "database_host", "database_port", "database_socket", "database_server_uuid_sha256",
    "isolated_database", "isolated_database_host", "isolated_database_port",
    "isolated_database_uuid_sha256", "host", "mysql_version", "deploy_user", "deploy_uid", "php_version",
    "php_fpm_socket", "php_fpm_unit", "php_fpm_uid", "php_fpm_gid", "worker_programs",
    "scheduler_unit", "maintenance_marker", "worker_fence_marker", "scheduler_fence_marker",
    "privileged_invocation", "php_fpm_confinement",
)
_PRODUCTION_KEYS = frozenset({"schema", "profile", "enabled", *_ENVIRONMENT_FIELDS})
_CONFINEMENT_KEYS = frozenset({"schema", "package_version", "package_sha256sums_sha256", "package_tree", "files",
                               "services", "transients"})
_SERVICE_KEYS = frozenset({"unit", "apparmor_profile", "profile_path", "attachment_path"})
_TRANSIENT_KEYS = frozenset({"apparmor_profile", "profile_path", "executable"})
_SHA256 = re.compile(r"[0-9a-f]{64}\Z")
_TREE = re.compile(r"[0-9a-f]{40}\Z")
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
            or not isinstance(pin.package_tree, str) or _TREE.fullmatch(pin.package_tree) is None
            or not isinstance(pin.file_sha256, Mapping)
            or set(pin.file_sha256) != set(CONFINEMENT_FILES)
            or any(not _sha(value) for value in pin.file_sha256.values())):
        raise EnvironmentRefused("confinement_package_pin_invalid")
    return pin


def confinement_binding() -> Mapping[str, object]:
    """Canonical schema-3 binding: package, tree, every file hash, service and transient role."""
    pin = confinement_pin()
    return {
        "schema": CONFINEMENT_SCHEMA,
        "package_version": pin.package_version,
        "package_sha256sums_sha256": pin.sha256sums_sha256,
        "package_tree": pin.package_tree,
        "files": dict(pin.file_sha256),
        "services": {role: {"unit": binding.unit, "apparmor_profile": binding.apparmor_profile,
                            "profile_path": binding.profile_path,
                            "attachment_path": binding.attachment_path}
                     for role, binding in CONFINEMENT_SERVICES.items()},
        "transients": {role: {"apparmor_profile": transient.apparmor_profile,
                              "profile_path": transient.profile_path,
                              "executable": transient.executable}
                       for role, transient in TRANSIENT_CLASSES.items()},
    }


def confinement_binding_sha256() -> str:
    """Digest of the canonical binding, carried verbatim by manifest, controller and installer."""
    raw = json.dumps(confinement_binding(), sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


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
            or data["package_sha256sums_sha256"] != pin.sha256sums_sha256 \
            or data["package_tree"] != pin.package_tree:
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
    transients = data["transients"]
    if not isinstance(transients, Mapping) or set(transients) != set(TRANSIENT_CLASSES):
        raise EnvironmentRefused("production_confinement_transient_binding_invalid")
    for role, transient in TRANSIENT_CLASSES.items():
        entry = transients[role]
        if not isinstance(entry, Mapping) or set(entry) != _TRANSIENT_KEYS or dict(entry) != {
                "apparmor_profile": transient.apparmor_profile, "profile_path": transient.profile_path,
                "executable": transient.executable}:
            raise EnvironmentRefused("production_confinement_transient_binding_invalid")
    return PhpFpmConfinement(pin.package_version, pin.sha256sums_sha256, pin.package_tree,
                             MappingProxyType(dict(pin.file_sha256)), REQUIRED_ROLES, TRANSIENT_ROLES)


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
    socket_path = data["database_socket"]
    if not isinstance(socket_path, str) or re.fullmatch(r"/run/mysqld/[a-z0-9][a-z0-9._-]{0,62}\.sock", socket_path) is None:
        raise EnvironmentRefused("production_database_socket_invalid")
    if socket_path in FOREIGN_DATABASE_SOCKETS or socket_path != PRODUCTION_DATABASE_SOCKET:
        raise EnvironmentRefused("production_database_socket_mismatch")
    db_port = _positive_int(data["database_port"], "database_port", maximum=65535)
    isolated_port = _positive_int(data["isolated_database_port"], "isolated_database_port", maximum=65535)
    if db_port == isolated_port:
        raise EnvironmentRefused("production_restore_endpoint_not_isolated")
    for field in ("database_server_uuid_sha256", "isolated_database_uuid_sha256"):
        if not _sha(data[field]):
            raise EnvironmentRefused(f"{field}_invalid")
    if data["database_server_uuid_sha256"] == data["isolated_database_uuid_sha256"]:
        raise EnvironmentRefused("production_restore_instance_not_isolated")

    if data["php_version"] != "8.5":
        # The confinement package binds the PHP 8.5 executable, socket and unit names.
        raise EnvironmentRefused("production_php_version_invalid")
    if data["mysql_version"] != "8.4":
        raise EnvironmentRefused("production_mysql_version_invalid")
    if data["deploy_user"] != TRANSFER_USER:
        # deploy is the untrusted transfer identity only; no new account is created.
        raise EnvironmentRefused("production_deploy_identity_invalid")
    socket = _fixed_path(data["php_fpm_socket"], label="php_fpm_socket", root="/run/php")
    expected_socket = "/run/php/wpcaregrid-production-php8.5-fpm.sock"
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
        php_version="8.5", php_fpm_socket=socket, php_fpm_unit=expected_unit,
        php_fpm_uid=uid, php_fpm_gid=gid, worker_programs=tuple(workers), scheduler_unit=scheduler,
        maintenance_marker=paths["maintenance_marker"], worker_fence_marker=paths["worker_fence_marker"],
        scheduler_fence_marker=paths["scheduler_fence_marker"],
        privileged_invocation=PRIVILEGED_INVOCATION, php_fpm_confinement=confinement,
        database_socket=socket_path,
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
# Schema-3 observation (collected as root by the helper backend).
# --------------------------------------------------------------------------

_OBSERVATION_KEYS = frozenset({"schema", "apparmor_enabled", "production_jobs_fence", "files", "services",
                               "www_data_scheduled_jobs", "helper", "same_uid_peers", "service_members"})
_SCHEDULED_JOB_KEYS = frozenset({"complete", "crontab_present", "system_cron_entries", "at_jobs"})
_HELPER_KEYS = frozenset({"pid", "starttime", "cgroup", "deploy_cli_profile_mode", "deploy_cli_cmdline_sha256",
                          "build_cli_profile_mode", "build_cli_cmdline_sha256"})
_FENCE_KEYS = frozenset({"units", "supervisor", "markers"})
_FENCE_UNIT_KEYS = frozenset({"load_state", "active_state", "unit_file_state"})
_FENCE_SUPERVISOR_KEYS = frozenset({"unit_state", "config_complete", "runtime_available", "programs"})
_FENCE_PROGRAM_KEYS = frozenset({"name", "state", "autostart"})
_FENCE_MARKER_KEYS = frozenset({"type", "uid", "gid", "mode", "nlink", "sha256"})
# Program states the collector may report. Only STOPPED (runtime) or SUPERVISOR_INACTIVE
# (supervisor.service observed inactive) can be fenced; NOT_LOADED and every other state refuse.
_FENCE_PROGRAM_STATES = frozenset({"STOPPED", "STARTING", "RUNNING", "BACKOFF", "STOPPING", "EXITED", "FATAL",
                                   "UNKNOWN", "NOT_LOADED", "SUPERVISOR_INACTIVE"})
PRODUCTION_PRIVATE_ROOTS = ("/var/www/wpcaregrid-production", "/var/lib/wpcaregrid-deploy/production")


def fence_marker_bytes(kind: str) -> bytes:
    """Exact Phase B fence marker content (deployment_transaction_v2.verify_background_fences)."""
    if kind == "worker_fence_marker":
        return b"schema=1;profile=production;workers=disabled\n"
    if kind == "scheduler_fence_marker":
        return b"schema=1;profile=production;scheduler=paused\n"
    raise EnvironmentRefused("confinement_observation_invalid")


def production_job_fence_scope(contract: EnvironmentContract) -> Mapping[str, object]:
    """The exact units, Supervisor program scope and marker paths the production fence covers."""
    timer = contract.scheduler_unit
    if not isinstance(timer, str) or not timer.endswith(".timer") or not contract.worker_programs \
            or not contract.worker_fence_marker or not contract.scheduler_fence_marker:
        raise EnvironmentRefused("confinement_not_bound")
    return MappingProxyType({
        "units": (timer, timer.removesuffix(".timer") + ".service"),
        "worker_programs": tuple(contract.worker_programs),
        "program_prefix": "wpcaregrid-production-",
        "markers": MappingProxyType({"worker_fence_marker": contract.worker_fence_marker,
                                     "scheduler_fence_marker": contract.scheduler_fence_marker}),
    })


def production_program_scoped(name: str, scope: Mapping[str, object]) -> bool:
    base = name.split(":", 1)[-1]
    stem = re.sub(r"_[0-9]+\Z", "", base)  # numprocs instances: <name>_00, <name>_01, ...
    return any(item in scope["worker_programs"] or item.startswith(scope["program_prefix"])
               for item in (base, stem))
_OBSERVED_FILE_KEYS = frozenset({"sha256", "uid", "gid", "mode", "type", "nlink", "parents_trusted"})
_OBSERVED_SERVICE_KEYS = frozenset({"unit", "load_state", "active_state", "main_pid", "main_label",
                                    "apparmor_profile_property", "loaded_profile_mode"})
_PEERS_KEYS = frozenset({"complete", "incomplete_reasons", "processes"})
_PEER_KEYS = frozenset({"pid", "starttime", "uids", "gids", "groups", "ppid", "state", "comm", "exe", "argv0",
                        "cmdline_sha256", "cgroup", "unit", "label", "fds"})
_REASON = re.compile(r"[a-z][a-z0-9_]{0,63}\Z")
_ACTIVE_SERVICE_STATES = frozenset({"active"})
_JOB_SERVICE_STATES = frozenset({"active", "inactive", "activating", "deactivating"})


def _pid(value: object) -> bool:
    return type(value) is int and 1 < value < 2**22 + 1


def _enforce(profile: str) -> str:
    return f"{profile} (enforce)"


def _verify_files(pin: ConfinementPin, files: object) -> None:
    if not isinstance(files, Mapping):
        raise EnvironmentRefused("confinement_observation_invalid")
    if set(CONFINEMENT_FILES) - set(files):
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


def _verify_services(services: object) -> None:
    if not isinstance(services, Mapping):
        raise EnvironmentRefused("confinement_observation_invalid")
    if set(REQUIRED_ROLES) - set(services):
        raise EnvironmentRefused("confinement_service_unobserved")
    if set(services) != set(REQUIRED_ROLES):
        raise EnvironmentRefused("confinement_observation_extra_service")
    for role in REQUIRED_ROLES:
        binding = CONFINEMENT_SERVICES[role]
        entry = services[role]
        if not isinstance(entry, Mapping) or set(entry) != _OBSERVED_SERVICE_KEYS \
                or not all(isinstance(entry[key], str) for key in (
                    "unit", "load_state", "active_state", "apparmor_profile_property", "loaded_profile_mode",
                    "main_label")) or type(entry["main_pid"]) is not int:
            raise EnvironmentRefused("confinement_observation_invalid")
        if entry["unit"] != binding.unit:
            raise EnvironmentRefused("confinement_service_unit_mismatch")
        allowed = _ACTIVE_SERVICE_STATES if role in SERVING_ROLES else _JOB_SERVICE_STATES
        if entry["load_state"] != "loaded" or entry["active_state"] not in allowed:
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
        if entry["active_state"] == "active":
            # The (often root) master/main process is attached too, not only its www-data workers.
            if not _pid(entry["main_pid"]):
                raise EnvironmentRefused("confinement_service_not_active")
            if entry["main_label"] != _enforce(binding.apparmor_profile):
                raise EnvironmentRefused("confinement_master_not_confined")
        elif entry["main_label"] != "":
            # Not active: no main label is read; its www-data processes are still in the peer inventory.
            raise EnvironmentRefused("confinement_observation_invalid")


def _verify_no_www_data_scheduled_jobs(jobs: object) -> None:
    """Shim-free model: www-data may own no crontab, system cron line or at job."""
    if not isinstance(jobs, Mapping) or set(jobs) != _SCHEDULED_JOB_KEYS or type(jobs["complete"]) is not bool \
            or type(jobs["crontab_present"]) is not bool \
            or type(jobs["system_cron_entries"]) is not int or type(jobs["at_jobs"]) is not int \
            or jobs["system_cron_entries"] < 0 or jobs["at_jobs"] < 0:
        raise EnvironmentRefused("confinement_observation_invalid")
    if jobs["complete"] is not True:
        raise EnvironmentRefused("confinement_www_data_jobs_unverifiable")
    if jobs["crontab_present"] or jobs["system_cron_entries"] or jobs["at_jobs"]:
        raise EnvironmentRefused("confinement_www_data_scheduled_job_present")


def _verify_helper(helper: object) -> Mapping:
    """The root helper console that may own transient production CLI peers.

    Both split profiles (deploy runtime probe, build cache commands) must be
    loaded in enforce mode and the helper must declare the exact command-line
    digests allowed for each class.
    """
    if not isinstance(helper, Mapping) or set(helper) != _HELPER_KEYS or not _pid(helper["pid"]) \
            or type(helper["starttime"]) is not int or helper["starttime"] < 0 \
            or not isinstance(helper["cgroup"], str) \
            or not isinstance(helper["deploy_cli_profile_mode"], str) \
            or not isinstance(helper["build_cli_profile_mode"], str):
        raise EnvironmentRefused("confinement_observation_invalid")
    for key in ("deploy_cli_cmdline_sha256", "build_cli_cmdline_sha256"):
        digests = helper[key]
        if not isinstance(digests, (list, tuple)) or not 1 <= len(digests) <= 8 \
                or len(set(digests)) != len(digests) or not all(_sha(item) for item in digests):
            raise EnvironmentRefused("confinement_observation_invalid")
    if _HELPER_SESSION_CGROUP.fullmatch(helper["cgroup"]) is None:
        raise EnvironmentRefused("confinement_helper_not_root_console_session")
    if helper["deploy_cli_profile_mode"] != "enforce":
        raise EnvironmentRefused("confinement_deploy_cli_profile_not_enforced")
    if helper["build_cli_profile_mode"] != "enforce":
        raise EnvironmentRefused("confinement_build_cli_profile_not_enforced")
    return helper


def _peer_valid(peer: object) -> bool:
    return (isinstance(peer, Mapping) and set(peer) == _PEER_KEYS and _pid(peer["pid"])
            and type(peer["starttime"]) is int and peer["starttime"] >= 0
            and type(peer["ppid"]) is int and peer["ppid"] >= 0
            and isinstance(peer["uids"], (list, tuple)) and len(peer["uids"]) == 4
            and isinstance(peer["gids"], (list, tuple)) and len(peer["gids"]) == 4
            and isinstance(peer["groups"], (list, tuple))
            and all(type(v) is int and v >= 0 for v in (*peer["uids"], *peer["gids"], *peer["groups"]))
            and all(isinstance(peer[k], str) for k in ("state", "comm", "exe", "argv0", "cgroup", "unit", "label"))
            and _sha(peer["cmdline_sha256"])
            and isinstance(peer["fds"], (list, tuple)) and all(isinstance(fd, str) for fd in peer["fds"]))


def _forbidden_descriptor(peer: Mapping, production_side: bool, role: str | None = None) -> None:
    forbidden = _STAGING_PRIVATE if production_side else _PRODUCTION_PRIVATE
    for target in peer["fds"]:
        path = target.removesuffix(" (deleted)")
        if not production_side and role == "nginx" and path.startswith(_PRODUCTION_RELEASES):
            # Nginx may hold descriptors only to the public assets of a pinned release.
            # Resolved /proc fd links use the real releases/<sha>/public path, not current/.
            tail = path[len(_PRODUCTION_RELEASES):]
            release, separator, public_path = tail.partition("/")
            if separator and re.fullmatch(r"[0-9a-f]{40}", release) and public_path.startswith("public/") \
                    and public_path != "public/storage" and not public_path.startswith("public/storage/"):
                continue
        if path.startswith(forbidden) or path + "/" in forbidden:
            raise EnvironmentRefused("confinement_peer_foreign_descriptor")


def _classify_peer(peer: Mapping, helper: Mapping) -> str:
    """Map one www-data process to exactly one permitted class or refuse. No class is unconfined."""
    unit, label, exe = peer["unit"], peer["label"], peer["exe"]
    if unit and peer["cgroup"] != f"/system.slice/{unit}":
        raise EnvironmentRefused("confinement_peer_cgroup_unexpected")
    if label == "unconfined":
        raise EnvironmentRefused("confinement_same_uid_peer_unconfined")
    if not label.endswith(" (enforce)"):
        raise EnvironmentRefused("confinement_process_not_confined")  # complain, kill, unknown modes
    transient = next((item for item in TRANSIENT_CLASSES.values()
                      if label == _enforce(item.apparmor_profile)), None)
    if transient is not None:
        # Transient application PHP started by this root helper console, nowhere else.
        # Since 1.0.5 the package splits the read-only runtime probe (deploy) from the offline cache
        # builders (build); each class has its own helper-declared command digests.
        prefix = "deploy" if transient.name == "production-deploy-cli" else "build"
        if unit or peer["cgroup"] != helper["cgroup"]:
            raise EnvironmentRefused(f"confinement_{prefix}_cli_outside_helper_session")
        if exe != transient.executable or peer["argv0"] != transient.executable:
            raise EnvironmentRefused("confinement_peer_executable_unexpected")
        if any(v != WWW_DATA_UID for v in peer["uids"]) or any(v != WWW_DATA_GID for v in peer["gids"]) \
                or any(v != WWW_DATA_GID for v in peer["groups"]):
            raise EnvironmentRefused(f"confinement_{prefix}_cli_identity_invalid")
        if peer["starttime"] < helper["starttime"]:
            # Only children of this helper run: nothing left over from an earlier run.
            raise EnvironmentRefused(f"confinement_{prefix}_cli_stale")
        if peer["cmdline_sha256"] not in helper[f"{prefix}_cli_cmdline_sha256"]:
            raise EnvironmentRefused(f"confinement_{prefix}_cli_command_unexpected")
        _forbidden_descriptor(peer, production_side=True)
        return transient.name
    candidates = [role for role in REQUIRED_ROLES if CONFINEMENT_SERVICES[role].unit == unit]
    if not candidates:
        if any(label == _enforce(b.apparmor_profile) for b in CONFINEMENT_SERVICES.values()):
            raise EnvironmentRefused("confinement_peer_label_outside_unit")
        raise EnvironmentRefused("confinement_same_uid_peer_unknown")
    role = candidates[0]
    if label != _enforce(CONFINEMENT_SERVICES[role].apparmor_profile):
        raise EnvironmentRefused("confinement_peer_label_outside_unit")
    if exe not in CLASS_EXECUTABLES[role]:
        raise EnvironmentRefused("confinement_peer_executable_unexpected")
    _forbidden_descriptor(peer, production_side=role == "production", role=role)
    return role


def _verify_peers(peers: object, services: Mapping, helper: Mapping, *, allow_transients: bool) -> None:
    if not isinstance(peers, Mapping) or set(peers) != _PEERS_KEYS or type(peers["complete"]) is not bool \
            or not isinstance(peers["processes"], (list, tuple)) \
            or not isinstance(peers["incomplete_reasons"], (list, tuple)) \
            or any(not isinstance(r, str) or _REASON.fullmatch(r) is None for r in peers["incomplete_reasons"]):
        raise EnvironmentRefused("confinement_observation_invalid")
    if peers["complete"] is not True or peers["incomplete_reasons"]:
        raise EnvironmentRefused("confinement_peer_inventory_incomplete")
    seen: set[int] = set()
    roles_seen: set[str] = set()
    roles_by_pid: dict[int, str] = {}
    for peer in peers["processes"]:
        if not _peer_valid(peer) or peer["pid"] in seen:
            raise EnvironmentRefused("confinement_observation_invalid")
        seen.add(peer["pid"])
        if WWW_DATA_UID not in peer["uids"] and WWW_DATA_GID not in peer["gids"] \
                and WWW_DATA_GID not in peer["groups"]:
            raise EnvironmentRefused("confinement_observation_invalid")  # collector must list only www-data peers
        classified = _classify_peer(peer, helper)
        if not allow_transients and classified in TRANSIENT_ROLES:
            raise EnvironmentRefused("confinement_cli_process_survived")
        roles_seen.add(classified)
        roles_by_pid[peer["pid"]] = classified
    for role in SERVING_ROLES:
        if role not in roles_seen:
            raise EnvironmentRefused("confinement_worker_labels_missing")
        main_pid = services[role]["main_pid"]
        if role == "production":
            # The immutable production unit deliberately runs its FPM master as www-data.
            # It must be in the complete peer inventory and classify as production.
            if main_pid not in seen or roles_by_pid.get(main_pid) != "production":
                raise EnvironmentRefused("confinement_worker_labels_missing")
        elif main_pid in seen:
            raise EnvironmentRefused("confinement_observation_invalid")


def _verify_service_members(members: object, services: Mapping, peers: object) -> None:
    """Verify descriptors and labels for every member of each bound service cgroup."""
    if not isinstance(members, Mapping) or set(members) != _PEERS_KEYS \
            or type(members["complete"]) is not bool or not isinstance(members["processes"], (list, tuple)) \
            or not isinstance(members["incomplete_reasons"], (list, tuple)) \
            or any(not isinstance(r, str) or _REASON.fullmatch(r) is None for r in members["incomplete_reasons"]):
        raise EnvironmentRefused("confinement_observation_invalid")
    if members["complete"] is not True or members["incomplete_reasons"]:
        raise EnvironmentRefused("confinement_peer_inventory_incomplete")
    by_pid: dict[int, Mapping] = {}
    roles_by_unit = {binding.unit: role for role, binding in CONFINEMENT_SERVICES.items()}
    found_by_unit: dict[str, set[int]] = {unit: set() for unit in roles_by_unit}
    for process in members["processes"]:
        if not _peer_valid(process) or process["pid"] in by_pid:
            raise EnvironmentRefused("confinement_observation_invalid")
        role = roles_by_unit.get(process["unit"])
        if role is None:
            raise EnvironmentRefused("confinement_peer_cgroup_unexpected")
        expected_cgroup = f"/system.slice/{process['unit']}"
        if process["cgroup"] != expected_cgroup and not process["cgroup"].startswith(expected_cgroup + "/"):
            raise EnvironmentRefused("confinement_peer_cgroup_unexpected")
        if process["label"] != _enforce(CONFINEMENT_SERVICES[role].apparmor_profile):
            raise EnvironmentRefused("confinement_master_not_confined")
        _forbidden_descriptor(process, production_side=role == "production", role=role)
        by_pid[process["pid"]] = process
        found_by_unit[process["unit"]].add(process["pid"])
    for role, binding in CONFINEMENT_SERVICES.items():
        main_pid = services[role]["main_pid"]
        if services[role]["active_state"] == "active":
            process = by_pid.get(main_pid)
            if process is None or process["unit"] != binding.unit \
                    or process["label"] != services[role]["main_label"]:
                raise EnvironmentRefused("confinement_service_member_missing")
        elif found_by_unit[binding.unit]:
            raise EnvironmentRefused("confinement_service_member_unexpected")
    for peer in peers["processes"]:
        if peer["unit"] in roles_by_unit:
            member = by_pid.get(peer["pid"])
            if member is None or any(member[key] != peer[key] for key in (
                    "starttime", "uids", "gids", "groups", "cgroup", "label", "exe")):
                raise EnvironmentRefused("confinement_service_member_missing")


def _verify_production_jobs_fenced(contract: EnvironmentContract, fence: object, services: object) -> None:
    """Production queue workers and scheduler must be provably unable to run."""
    scope = production_job_fence_scope(contract)
    if not isinstance(fence, Mapping) or set(fence) != _FENCE_KEYS or not isinstance(fence["units"], Mapping) \
            or not isinstance(fence["supervisor"], Mapping) or not isinstance(fence["markers"], Mapping) \
            or not isinstance(services, Mapping):
        raise EnvironmentRefused("confinement_observation_invalid")
    units = fence["units"]
    if set(units) != set(scope["units"]):
        raise EnvironmentRefused("confinement_production_jobs_unverifiable")
    for unit in scope["units"]:
        state = units[unit]
        if not isinstance(state, Mapping) or set(state) != _FENCE_UNIT_KEYS \
                or not all(isinstance(state[key], str) for key in _FENCE_UNIT_KEYS):
            raise EnvironmentRefused("confinement_observation_invalid")
        # masked = linked to /dev/null: nothing (timer, dependency, systemctl start) can start it.
        if state["load_state"] != "masked" or state["unit_file_state"] != "masked" \
                or state["active_state"] != "inactive":
            raise EnvironmentRefused("confinement_production_jobs_not_fenced")
    supervisor = fence["supervisor"]
    if set(supervisor) != _FENCE_SUPERVISOR_KEYS or type(supervisor["config_complete"]) is not bool \
            or type(supervisor["runtime_available"]) is not bool \
            or not isinstance(supervisor["programs"], (list, tuple)):
        raise EnvironmentRefused("confinement_observation_invalid")
    supervisor_unit = supervisor["unit_state"]
    if not isinstance(supervisor_unit, Mapping) or set(supervisor_unit) != _FENCE_UNIT_KEYS \
            or not all(isinstance(supervisor_unit[key], str) for key in _FENCE_UNIT_KEYS):
        raise EnvironmentRefused("confinement_observation_invalid")
    if supervisor["config_complete"] is not True:
        raise EnvironmentRefused("confinement_production_jobs_unverifiable")
    absent = (supervisor_unit["load_state"] == "not-found"
              and supervisor_unit["active_state"] == "inactive"
              and supervisor_unit["unit_file_state"] == "not-found")
    active = (supervisor_unit["load_state"] == "loaded"
              and supervisor_unit["active_state"] == "active"
              and supervisor_unit["unit_file_state"] not in {"", "not-found", "masked"})
    if absent:
        if supervisor["runtime_available"] is not False or supervisor["programs"]:
            raise EnvironmentRefused("confinement_production_jobs_unverifiable")
    elif active:
        if supervisor["runtime_available"] is not True:
            raise EnvironmentRefused("confinement_production_jobs_unverifiable")
    else:
        # Transitional, failed, malformed and loaded-inactive states never mean "no jobs".
        raise EnvironmentRefused("confinement_production_jobs_unverifiable")
    names = set()
    for program in supervisor["programs"]:
        if not isinstance(program, Mapping) or set(program) != _FENCE_PROGRAM_KEYS \
                or not isinstance(program["name"], str) or program["state"] not in _FENCE_PROGRAM_STATES \
                or type(program["autostart"]) is not bool or program["name"] in names:
            raise EnvironmentRefused("confinement_observation_invalid")
        names.add(program["name"])
        stopped = active and program["state"] == "STOPPED"
        # Absent is preferred; a defined production program must be exactly stopped and never autostart.
        if not stopped or program["autostart"] is not False:
            raise EnvironmentRefused("confinement_production_jobs_not_fenced")
    markers = fence["markers"]
    if set(markers) != set(scope["markers"]):
        raise EnvironmentRefused("confinement_observation_invalid")
    for kind in scope["markers"]:
        marker = markers[kind]
        if marker is None:
            raise EnvironmentRefused("confinement_production_jobs_not_fenced")
        if not isinstance(marker, Mapping) or set(marker) != _FENCE_MARKER_KEYS \
                or not all(type(marker[key]) is int for key in ("uid", "gid", "mode", "nlink")):
            raise EnvironmentRefused("confinement_observation_invalid")
        if marker["type"] != "regular" or marker["uid"] != 0 or marker["gid"] != 0 or marker["mode"] != 0o600 \
                or marker["nlink"] != 1 \
                or marker["sha256"] != hashlib.sha256(fence_marker_bytes(kind)).hexdigest():
            raise EnvironmentRefused("confinement_production_fence_marker_invalid")


def verify_confinement_observation(contract: EnvironmentContract, observation: object, *,
                                   allow_transients: bool = True) -> None:
    """Refuse unless a root-collected schema-3 observation matches the binding exactly.

    ``observation``::

        {"schema": 3, "apparmor_enabled": bool,
         "production_jobs_fence": {
             "units": {<scheduler .timer>, <twin .service>: {load_state, active_state, unit_file_state}},
             "supervisor": {config_complete, runtime_available,
                            programs: [{name, state, autostart}]},   # production-scoped only
             "markers": {worker_fence_marker, scheduler_fence_marker:
                         {type, uid, gid, mode, nlink, sha256} | None}},
         "files": {installed_path: {sha256, uid, gid, mode, type, nlink, parents_trusted}},
         "services": {role: {unit, load_state, active_state, main_pid, main_label,
                             apparmor_profile_property, loaded_profile_mode}},
         "www_data_scheduled_jobs": {complete, crontab_present, system_cron_entries, at_jobs},
         "helper": {pid, starttime, cgroup,
                    deploy_cli_profile_mode, deploy_cli_cmdline_sha256: [hex],
                    build_cli_profile_mode, build_cli_cmdline_sha256: [hex]},
         "same_uid_peers": {complete, incomplete_reasons,
                            processes: [{pid, starttime, uids, gids, groups, ppid, state, comm, exe, argv0,
                                         cmdline_sha256, cgroup, unit, label, fds}]}}

    ``same_uid_peers`` lists every live process whose real, effective, saved or
    filesystem UID or GID, or any supplementary group, is www-data, independent
    of its command name. Each
    must map to exactly one class: its systemd unit (from the kernel cgroup),
    the exact ``<profile> (enforce)`` label bound to that unit, an executable
    allowed for that class and no descriptor into the other environment's
    private trees. There is no unconfined class. The production deploy-CLI
    class (``wpcaregrid-php-cli-production-deploy``) is only ``/usr/bin/php8.5``
    (exe and argv0) as 33/33 with no other supplementary group, started after the
    current helper process, running one of the helper's exact command lines inside
    the helper's own root console session cgroup, never a service unit. The
    production build-CLI class (``wpcaregrid-php-cli-production-build``) has the
    same identity, lifetime and cgroup rules and must run one of the helper's
    build command lines; both profiles must be loaded in enforce mode. No www-data
    crontab, system cron line or at job may exist. Unknown, unconfined, complain-mode,
    mislabelled or incomplete evidence refuses.

    The production jobs fence is checked first: both scheduler units masked and
    inactive, every production-scoped Supervisor program (by name or by a
    command/directory under a production tree) exactly STOPPED with
    ``autostart`` false (or supervisor itself observed inactive), and both
    root-owned ``0600`` Phase B fence markers with their exact content.
    Passing means the recorded state matches; kernel enforcement is a separate
    live gate.
    """
    pin = confinement_pin()
    confinement = getattr(contract, "php_fpm_confinement", None)
    if (getattr(contract, "name", None) != "production" or not isinstance(confinement, PhpFpmConfinement)
            or confinement.package_version != pin.package_version
            or confinement.sha256sums_sha256 != pin.sha256sums_sha256
            or confinement.package_tree != pin.package_tree
            or dict(confinement.file_sha256) != dict(pin.file_sha256)
            or tuple(confinement.roles) != REQUIRED_ROLES
            or tuple(confinement.transients) != TRANSIENT_ROLES):
        raise EnvironmentRefused("confinement_not_bound")
    if not isinstance(observation, Mapping) or set(observation) != _OBSERVATION_KEYS \
            or type(observation["schema"]) is not int or observation["schema"] != OBSERVATION_SCHEMA \
            or type(observation["apparmor_enabled"]) is not bool:
        raise EnvironmentRefused("confinement_observation_invalid")
    # GO-13 keeps background SaaS processing disabled: the production jobs fence is
    # checked before any confinement evidence and refuses on any missing element.
    _verify_production_jobs_fenced(contract, observation["production_jobs_fence"], observation["services"])
    if observation["apparmor_enabled"] is not True:
        raise EnvironmentRefused("confinement_apparmor_disabled")
    _verify_files(pin, observation["files"])
    _verify_services(observation["services"])
    _verify_no_www_data_scheduled_jobs(observation["www_data_scheduled_jobs"])
    helper = _verify_helper(observation["helper"])
    _verify_peers(observation["same_uid_peers"], observation["services"], helper,
                  allow_transients=allow_transients)
    _verify_service_members(observation["service_members"], observation["services"], observation["same_uid_peers"])
