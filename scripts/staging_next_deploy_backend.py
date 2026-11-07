#!/usr/bin/env python3
"""Staging-next-only, fail-closed immutable release backend (Phase A/B).

The production profile is intentionally absent. The CLI/helper layer supplies
only the compiled staging-next profile and one exact artifact identity. Tests
may construct a disposable profile, but no CLI flag can select paths or targets.
Database-changing releases require the separately pinned Phase B policy and
durable database transaction protocol.
"""

from __future__ import annotations

import fcntl
import base64
import grp
import hashlib
import importlib.util
import io
import json
import os
import pwd
import re
import shutil
import stat
import subprocess
import tarfile
import tempfile
import time
import urllib.error
import urllib.request
from urllib.parse import quote, urlencode
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath
from typing import Mapping


_TRANSACTION_PATH = Path(__file__).with_name("staging_next_db_transaction.py")
_TRANSACTION_SPEC = importlib.util.spec_from_file_location(
    "_wpcg_staging_next_db_transaction_v1", _TRANSACTION_PATH)
_require_transaction_loader = (_TRANSACTION_SPEC is not None and _TRANSACTION_SPEC.loader is not None)
if not _require_transaction_loader:
    raise RuntimeError("database_transaction_module_unavailable")
_TRANSACTION_MODULE = importlib.util.module_from_spec(_TRANSACTION_SPEC)
import sys as _transaction_sys
_transaction_sys.modules[_TRANSACTION_SPEC.name] = _TRANSACTION_MODULE
_TRANSACTION_SPEC.loader.exec_module(_TRANSACTION_MODULE)
DatabaseReleaseTransaction = _TRANSACTION_MODULE.DatabaseReleaseTransaction
TransactionIdentity = _TRANSACTION_MODULE.TransactionIdentity
TransactionRefused = _TRANSACTION_MODULE.TransactionRefused
load_execution_policy = _TRANSACTION_MODULE.load_execution_policy
database_fingerprint = _TRANSACTION_MODULE.database_fingerprint
restore_receipt_sha256 = _TRANSACTION_MODULE.restore_receipt_sha256
create_database_backup = _TRANSACTION_MODULE.create_database_backup
restore_backup_to_isolated = _TRANSACTION_MODULE.restore_backup_to_isolated
cleanup_isolated_databases = _TRANSACTION_MODULE.cleanup_isolated_databases
verify_background_fences = _TRANSACTION_MODULE.verify_background_fences
_verify_backup_sql = _TRANSACTION_MODULE._verify_backup_sql


HELPER_VERSION = "1.4.4"
HELPER_CONTRACT = "wpcaregrid-staging-next-deploy-v8"
PHASE_B_POLICY_PATH = Path("/etc/wpcaregrid-deploy/profiles/staging-next-phase-b-1.4.4.json")
PROFILE = "staging-next"
DATABASE = "wpcg_staging_next"
PRIMARY_BRANCH = "claude/ecstatic-wozniak-oemyuf"
GITHUB_API = "https://api.github.com"
GITHUB_REPOSITORY = "zsoltacsai/wpcaregrid"
REQUIRED_CI_JOBS = (
    "Quality (lint, style, dependencies, build, secrets)",
    "Server tests (PHP 8.4, mysql)",
    "Connector tests in WordPress (PHP 8.4, sqlite)",
    "Connector tests in WordPress (PHP 8.4, mysql)",
    "Connector tests in WordPress (PHP 8.4, mariadb)",
    "Server SQLite regression gate (PHP 8.4)",
    "Server tests (PHP 8.5, mysql)",
)
APPLICATION_SHA_RE = re.compile(r"[0-9a-f]{40}\Z")
SHA256_RE = re.compile(r"[0-9a-f]{64}\Z")
MAX_ARCHIVE_BYTES = 512 * 1024 * 1024
MAX_UNPACKED_BYTES = 2 * 1024 * 1024 * 1024
MAX_ARCHIVE_FILES = 100_000
MAX_HTTP_BODY_BYTES = 1024 * 1024
REQUIRED_RUNTIME_FILES = frozenset({
    "artisan", "composer.json", "composer.lock", "bootstrap/app.php", "public/index.php",
    "routes/web.php",
})

# Phase A deliberately permits only non-runtime marketing files. Any other
# changed, added, or deleted path is treated as requiring the Phase B DB/recovery
# contract. This is a positive allowlist, not a manifest boolean.
PHASE_A_PATH_PREFIXES = (
    "lang/",
    "public/build/",
    "public/images/",
    "resources/css/",
    "resources/js/",
    "resources/views/marketing/",
)
PHASE_A_PATHS = frozenset({"resources/views/pages/home.blade.php",
                           "resources/views/pages/pricing.blade.php"})
GIT_ARCHIVE_EXCLUDED_PATHS = frozenset({".github/", "CHANGELOG.md", ".styleci.yml"})


def _git_archive_excludes(name: str) -> bool:
    return any(name.startswith(path) if path.endswith("/") else name == path
               for path in GIT_ARCHIVE_EXCLUDED_PATHS)


@dataclass(frozen=True)
class StagingProfile:
    name: str
    application_link: Path
    releases_dir: Path
    incoming_dir: Path
    inbox_dir: Path
    state_dir: Path
    receipts_dir: Path
    shared_env_file: Path
    shared_storage_dir: Path
    shared_vendor_dir: Path
    basic_auth_file: Path | None
    mysql_server_uuid_sha256: str | None
    integration_qa_reviewer: str | None
    m17_appsec_reviewer: str | None
    helper_artifact_sha256: str | None
    php_fpm_uid: int
    php_fpm_gid: int
    test_git_repo: Path | None
    allowed_uid: int
    expected_owner_uid: int
    acceptance_urls: tuple[tuple[str, int], ...]
    build_application_bootstrap: bool = False
    verify_test_database: bool = False
    test_only: bool = False
    protected_acceptance_urls: tuple[str, ...] = ()
    phase_b_policy_file: Path | None = None
    phase_b_policy_sha256: str | None = None
    phase_b_test_only_paths: Mapping[str, object] | None = None


PRODUCTION_PROFILE = StagingProfile(
    name=PROFILE,
    application_link=Path("/var/www/wpcaregrid-staging-new"),
    releases_dir=Path("/var/lib/wpcaregrid-deploy/staging-next/releases"),
    incoming_dir=Path("/var/lib/wpcaregrid-deploy/staging-next/incoming"),
    inbox_dir=Path("/var/lib/wpcaregrid-deploy/staging-next/inbox"),
    state_dir=Path("/var/lib/wpcaregrid-deploy/staging-next/state"),
    receipts_dir=Path("/var/lib/wpcaregrid-deploy/staging-next/receipts"),
    shared_env_file=Path("/var/lib/wpcaregrid-deploy/staging-next/shared/.env"),
    shared_storage_dir=Path("/var/lib/wpcaregrid-deploy/staging-next/shared/storage"),
    shared_vendor_dir=Path("/var/lib/wpcaregrid-deploy/staging-next/shared/vendor"),
    basic_auth_file=Path("/etc/wpcaregrid-deploy/staging-next-basic-auth.json"),
    mysql_server_uuid_sha256=None,  # Bound in the root-owned environment policy at installation.
    integration_qa_reviewer=None,  # Installed from the approved #174 reviewer policy.
    m17_appsec_reviewer=None,
    helper_artifact_sha256=None,  # Installed from the exact versioned helper artifact manifest.
    php_fpm_uid=-1,  # Bound by the root-owned staging-next profile policy.
    php_fpm_gid=-1,  # Bound by the root-owned staging-next profile policy.
    build_application_bootstrap=True,
    test_git_repo=None,
    allowed_uid=-1,  # Loaded from the exact root-owned profile policy.
    expected_owner_uid=0,
    acceptance_urls=(
        ("https://staging-next.wpcaregrid.com/up", 200),
        ("https://staging-next.wpcaregrid.com/hu", 200),
        ("https://staging-next.wpcaregrid.com/en", 200),
        ("https://staging-next.wpcaregrid.com/register", 404),
        ("https://staging-next.wpcaregrid.com/checkout", 404),
        ("https://staging-next.wpcaregrid.com/api", 404),
    ),
    protected_acceptance_urls=(
        "https://staging-next.wpcaregrid.com/hu",
        "https://staging-next.wpcaregrid.com/en",
    ),
    phase_b_policy_file=PHASE_B_POLICY_PATH,
    phase_b_policy_sha256=None,
)


@dataclass(frozen=True)
class DeploymentResult:
    status: str
    reason: str
    application_sha: str | None = None
    previous_sha: str | None = None
    artifact_sha256: str | None = None
    receipt_path: str | None = None
    receipt_sha256: str | None = None


class DeployRefused(Exception):
    """Sanitized fail-closed result. Message must be a fixed code."""


def _require(condition: bool, reason: str) -> None:
    if not condition:
        raise DeployRefused(reason)


def _sha(value: object, pattern: re.Pattern[str]) -> bool:
    return isinstance(value, str) and pattern.fullmatch(value) is not None


def _strict_json(raw: bytes) -> Mapping[str, object]:
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("duplicate_key")
            result[key] = value
        return result

    value = json.loads(raw.decode("utf-8"), object_pairs_hook=unique)
    _require(isinstance(value, dict), "manifest_schema_invalid")
    return value


def _safe_profile(profile: StagingProfile) -> None:
    _require(profile.name == PROFILE, "profile_not_enabled")
    _require(bool(profile.acceptance_urls), "acceptance_contract_missing")
    _require(all(type(status) is int and 100 <= status <= 599 for _, status in profile.acceptance_urls),
             "acceptance_contract_invalid")
    paths = (profile.application_link, profile.releases_dir, profile.incoming_dir, profile.inbox_dir,
             profile.state_dir, profile.receipts_dir, profile.shared_env_file,
             profile.shared_storage_dir, profile.shared_vendor_dir)
    if profile.basic_auth_file is not None:
        paths += (profile.basic_auth_file,)
    for path in paths:
        _require(path.is_absolute() and ".." not in path.parts, "profile_path_invalid")
    _require(profile.helper_artifact_sha256 is None or _sha(profile.helper_artifact_sha256, SHA256_RE),
             "helper_artifact_identity_invalid")
    if not profile.test_only:
        _require(profile.phase_b_policy_file == Path(
            "/etc/wpcaregrid-deploy/profiles/staging-next-phase-b-1.4.4.json"),
            "phase_b_policy_path_invalid")
        _require(profile.phase_b_policy_sha256 is None
                 or _sha(profile.phase_b_policy_sha256, SHA256_RE), "phase_b_policy_pin_invalid")
    _require(type(profile.php_fpm_gid) is int and profile.php_fpm_gid >= 0,
             "php_fpm_group_policy_invalid")
    _require(type(profile.php_fpm_uid) is int and profile.php_fpm_uid >= 0,
             "php_fpm_user_policy_invalid")
    if not profile.test_only:
        _require(profile.php_fpm_uid > 0 and profile.php_fpm_gid > 0,
                 "php_fpm_identity_policy_invalid")


def _lstat(path: Path, reason: str):
    try:
        return path.lstat()
    except OSError:
        raise DeployRefused(reason) from None


def _verify_directory_chain(path: Path, *, owner_uid: int, exact_mode: int | None = None) -> None:
    _require(path.is_absolute(), "directory_path_invalid")
    parts = path.parts
    cursor = Path(parts[0])
    for component in parts[1:]:
        cursor = cursor / component
        info = _lstat(cursor, "directory_unavailable")
        _require(stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode), "directory_metadata_invalid")
        _require(info.st_uid == owner_uid, "directory_owner_invalid")
        if cursor == path and exact_mode is not None:
            _require(stat.S_IMODE(info.st_mode) == exact_mode, "directory_mode_invalid")
        else:
            _require(stat.S_IMODE(info.st_mode) & 0o022 == 0, "directory_writable_by_group_or_other")


def _read_regular_nofollow(path: Path, *, max_bytes: int, expected_uid: int | None = None,
                           expected_mode: int | None = None) -> bytes:
    info = _lstat(path, "file_unavailable")
    _require(stat.S_ISREG(info.st_mode) and not stat.S_ISLNK(info.st_mode), "file_metadata_invalid")
    _require(info.st_size <= max_bytes, "file_size_invalid")
    if expected_uid is not None:
        _require(info.st_uid == expected_uid, "file_owner_invalid")
    if expected_mode is not None:
        _require(stat.S_IMODE(info.st_mode) == expected_mode, "file_mode_invalid")
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0)
    try:
        fd = os.open(path, flags)
        try:
            opened = os.fstat(fd)
            _require(stat.S_ISREG(opened.st_mode) and opened.st_ino == info.st_ino
                     and opened.st_dev == info.st_dev and opened.st_size == info.st_size,
                     "file_changed_during_open")
            if expected_uid is not None:
                _require(opened.st_uid == expected_uid, "file_owner_invalid")
            if expected_mode is not None:
                _require(stat.S_IMODE(opened.st_mode) == expected_mode, "file_mode_invalid")
            with os.fdopen(fd, "rb", closefd=False) as stream:
                raw = stream.read(max_bytes + 1)
            _require(len(raw) == opened.st_size, "file_read_incomplete")
            return raw
        finally:
            os.close(fd)
    except DeployRefused:
        raise
    except OSError:
        raise DeployRefused("file_read_failed") from None


def _canonical_files_hash(entries: Mapping[str, str]) -> str:
    raw = "".join(f"{name}\0{digest}\n" for name, digest in sorted(entries.items())).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


def _safe_member_name(name: str, *, allow_manifest: bool = False) -> str:
    _require(isinstance(name, str) and "\\" not in name and "\x00" not in name, "archive_path_invalid")
    path = PurePosixPath(name)
    _require(not path.is_absolute() and bool(path.parts), "archive_path_invalid")
    _require(all(part not in {"", ".", ".."} for part in path.parts), "archive_path_invalid")
    normalized = path.as_posix()
    _require(normalized == name.rstrip("/"), "archive_path_invalid")
    _require(not any(part in {".git", "node_modules", "storage", "vendor"} for part in path.parts),
             "archive_forbidden_path")
    _require(normalized not in {".env", "bootstrap/cache/config.php"}
             and (allow_manifest or normalized != "release-manifest.json"),
             "archive_forbidden_path")
    if normalized.startswith("bootstrap/cache/"):
        _require(normalized == "bootstrap/cache/.gitignore", "archive_forbidden_path")
    return normalized


def _extract_archive(archive_bytes: bytes, destination: Path, *, app_sha: str, base_sha: str,
                     artifact_sha: str, helper_artifact_sha256: str) -> tuple[Mapping[str, str], Mapping[str, object]]:
    _require(len(archive_bytes) <= MAX_ARCHIVE_BYTES, "artifact_size_invalid")
    _require(hashlib.sha256(archive_bytes).hexdigest() == artifact_sha, "artifact_hash_mismatch")
    try:
        import io
        tar = tarfile.open(fileobj=io.BytesIO(archive_bytes), mode="r:")
    except (tarfile.TarError, OSError):
        raise DeployRefused("artifact_archive_invalid") from None

    file_hashes: dict[str, str] = {}
    manifest = None
    seen: set[str] = set()
    unpacked_bytes = 0
    members = tar.getmembers()
    _require(0 < len(members) <= MAX_ARCHIVE_FILES, "artifact_file_count_invalid")
    try:
        for member in members:
            _require(not (member.issym() or member.islnk() or member.isdev() or member.isfifo()),
                     "archive_link_or_special_file_rejected")
            if member.isdir():
                name = member.name.rstrip("/")
                if name:
                    _safe_member_name(name)
                    (destination / name).mkdir(parents=True, exist_ok=True, mode=0o755)
                continue
            _require(member.isfile(), "archive_member_type_invalid")
            name = _safe_member_name(member.name, allow_manifest=True)
            _require(name not in seen, "archive_duplicate_path")
            seen.add(name)
            unpacked_bytes += member.size
            _require(member.size >= 0 and unpacked_bytes <= MAX_UNPACKED_BYTES, "artifact_unpacked_size_invalid")
            source = tar.extractfile(member)
            _require(source is not None, "archive_member_unreadable")
            raw = source.read(member.size + 1)
            _require(len(raw) == member.size, "archive_member_size_mismatch")
            if name == "release-manifest.json":
                manifest = _strict_json(raw)
                continue
            target = destination / name
            target.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
            fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o644)
            try:
                with os.fdopen(fd, "wb", closefd=True) as stream:
                    stream.write(raw)
                    stream.flush()
                    os.fsync(stream.fileno())
            except OSError:
                raise DeployRefused("artifact_extract_failed") from None
            file_hashes[name] = hashlib.sha256(raw).hexdigest()
        _require(manifest is not None, "release_manifest_missing")
        expected_manifest_keys = {"schema", "profile", "application_sha", "base_application_sha",
                                  "php_minimum", "database_scope", "database_migrations",
                                  "files_sha256", "helper_version",
                                  "helper_sha256", "helper_contract", "frontend_build"}
        _require(set(manifest) == expected_manifest_keys, "release_manifest_schema_invalid")
        _require(type(manifest["schema"]) is int and manifest["schema"] == 1
                 and manifest["profile"] == PROFILE and manifest["application_sha"] == app_sha
                 and manifest["base_application_sha"] == base_sha and manifest["php_minimum"] == "8.5"
                 and manifest["database_scope"] in {"unchanged", "migrations"}
                 and manifest["helper_version"] == HELPER_VERSION
                 and manifest["helper_contract"] == HELPER_CONTRACT
                 and _sha(manifest["helper_sha256"], SHA256_RE)
                 and manifest["helper_sha256"] == helper_artifact_sha256,
                 "release_manifest_identity_mismatch")
        database_migrations = manifest["database_migrations"]
        _require(isinstance(database_migrations, list) and len(database_migrations) <= 1000
                 and all(isinstance(item, dict) and set(item) == {"name", "sha256"}
                         and isinstance(item.get("name"), str)
                         and re.fullmatch(r"[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+",
                                          item["name"]) is not None
                         and _sha(item.get("sha256"), SHA256_RE) for item in database_migrations)
                 and len({item["name"] for item in database_migrations}) == len(database_migrations)
                 and database_migrations == sorted(database_migrations, key=lambda item: item["name"])
                 and ((manifest["database_scope"] == "unchanged" and not database_migrations)
                      or (manifest["database_scope"] == "migrations" and bool(database_migrations))),
                 "release_migration_manifest_invalid")
        for migration in database_migrations:
            rel = "database/migrations/" + migration["name"] + ".php"
            raw_migration = _read_regular_nofollow(
                destination / rel, max_bytes=MAX_UNPACKED_BYTES,
                expected_uid=os.geteuid(), expected_mode=0o644)
            _require(hashlib.sha256(raw_migration).hexdigest() == migration["sha256"],
                     "release_migration_identity_mismatch")
        _require(_sha(manifest["files_sha256"], SHA256_RE)
                 and manifest["files_sha256"] == _canonical_files_hash(file_hashes),
                 "release_file_manifest_mismatch")
        _verify_frontend_build(manifest["frontend_build"], destination, file_hashes)
        return file_hashes, manifest
    finally:
        tar.close()


def _release_manifest(profile: StagingProfile, release_dir: Path, *, expected_uid: int,
                      max_bytes: int = 32_768) -> Mapping[str, object]:
    raw = _read_regular_nofollow(release_dir / "release-manifest.json", max_bytes=max_bytes,
                                 expected_uid=expected_uid, expected_mode=0o644)
    manifest = _strict_json(raw)
    expected = {"schema", "profile", "application_sha", "base_application_sha", "php_minimum",
                "database_scope", "database_migrations", "files_sha256", "artifact_sha256", "helper_version",
                "helper_sha256", "helper_contract", "frontend_build"}
    _require(set(manifest) == expected and type(manifest.get("schema")) is int and manifest["schema"] == 1
             and manifest.get("profile") == PROFILE and manifest.get("helper_version") == HELPER_VERSION
             and manifest.get("helper_contract") == HELPER_CONTRACT
             and _sha(manifest.get("helper_sha256"), SHA256_RE)
             and manifest.get("helper_sha256") == profile.helper_artifact_sha256
             and manifest.get("application_sha") == release_dir.name
             and _sha(manifest.get("base_application_sha"), APPLICATION_SHA_RE)
             and manifest.get("php_minimum") == "8.5"
             and manifest.get("database_scope") in {"unchanged", "migrations"}
             and isinstance(manifest.get("database_migrations"), list)
             and _sha(manifest.get("files_sha256"), SHA256_RE)
             and _sha(manifest.get("artifact_sha256"), SHA256_RE),
             "release_manifest_identity_invalid")
    return manifest


def _verify_frontend_build(build: object, release_dir: Path,
                           files: Mapping[str, str]) -> None:
    """Validate generated assets, their lockfile binding, and Vite references."""
    _require(isinstance(build, dict) and set(build) == {
        "node_version", "npm_version", "package_lock_sha256", "files_sha256",
    }, "frontend_build_manifest_invalid")
    node_version = build.get("node_version")
    npm_version = build.get("npm_version")
    _require(isinstance(node_version, str) and re.fullmatch(r"v22\.\d+\.\d+", node_version)
             and isinstance(npm_version, str) and re.fullmatch(r"10\.\d+\.\d+", npm_version),
             "frontend_builder_version_unsupported")
    lock_raw = _read_regular_nofollow(release_dir / "package-lock.json", max_bytes=MAX_UNPACKED_BYTES,
                                      expected_uid=os.geteuid(), expected_mode=0o644)
    _require(_sha(build.get("package_lock_sha256"), SHA256_RE)
             and hashlib.sha256(lock_raw).hexdigest() == build["package_lock_sha256"],
             "frontend_lock_identity_mismatch")
    generated = {name: digest for name, digest in files.items() if name.startswith("public/build/")}
    _require("public/build/manifest.json" in generated, "frontend_manifest_missing")
    canonical = "".join(f"{name}\0{digest}\n" for name, digest in sorted(generated.items())).encode()
    _require(_sha(build.get("files_sha256"), SHA256_RE)
             and hashlib.sha256(canonical).hexdigest() == build["files_sha256"],
             "frontend_asset_manifest_mismatch")
    raw = _read_regular_nofollow(release_dir / "public/build/manifest.json", max_bytes=2_000_000,
                                 expected_uid=os.geteuid(), expected_mode=0o644)
    vite = _strict_json(raw)
    _require(isinstance(vite, dict) and bool(vite), "vite_manifest_invalid")
    references = set()
    for record in vite.values():
        _require(isinstance(record, dict), "vite_manifest_invalid")
        for key in ("file",):
            value = record.get(key)
            _require(isinstance(value, str), "vite_manifest_invalid")
            references.add("public/build/" + _safe_vite_asset(value))
        for key in ("css", "assets"):
            values = record.get(key, [])
            _require(isinstance(values, list), "vite_manifest_invalid")
            for value in values:
                _require(isinstance(value, str), "vite_manifest_invalid")
                references.add("public/build/" + _safe_vite_asset(value))
        for key in ("imports", "dynamicImports"):
            values = record.get(key, [])
            _require(isinstance(values, list) and all(isinstance(value, str) and value in vite
                                                     for value in values),
                     "vite_manifest_invalid")
    _require(references == (set(generated) - {"public/build/manifest.json"}) and bool(references),
             "vite_manifest_asset_missing_or_unreferenced")


def _safe_vite_asset(value: str) -> str:
    path = PurePosixPath(value)
    _require(not path.is_absolute() and "\\" not in value
             and all(part not in {"", ".", ".."} for part in path.parts),
             "vite_asset_path_invalid")
    _require(path.as_posix() == value, "vite_asset_path_invalid")
    return path.as_posix()


def _valid_interrupted_cache_file(info, profile: StagingProfile) -> bool:
    ownership = {(profile.expected_owner_uid, 0),
                 (profile.expected_owner_uid, profile.php_fpm_gid),
                 (profile.php_fpm_uid, profile.php_fpm_gid)}
    return (stat.S_ISREG(info.st_mode) and (info.st_uid, info.st_gid) in ownership
            and stat.S_IMODE(info.st_mode) in {0o600, 0o640, 0o644, 0o755}
            and info.st_nlink == 1 and info.st_size <= 16 * 1024 * 1024)


def _hash_release_tree(release_dir: Path, *, expected_uid: int,
                      shared_env_file: Path, shared_storage_dir: Path,
                      shared_vendor_dir: Path, allow_interrupted_cache: bool = False,
                      php_fpm_uid: int | None = None,
                      php_fpm_gid: int | None = None) -> dict[str, str]:
    result: dict[str, str] = {}
    for path in sorted(release_dir.rglob("*")):
        info = _lstat(path, "release_tree_unavailable")
        if stat.S_ISDIR(info.st_mode):
            continue
        rel = path.relative_to(release_dir).as_posix()
        if rel in {".env", "storage", "vendor"}:
            expected = {".env": shared_env_file, "storage": shared_storage_dir,
                        "vendor": shared_vendor_dir}[rel]
            _require(stat.S_ISLNK(info.st_mode) and os.readlink(path) == str(expected),
                     "release_shared_link_invalid")
            continue
        if rel in {"bootstrap/cache/config.php", "bootstrap/cache/packages.php", "bootstrap/cache/services.php"}:
            allowed_owners = {expected_uid}
            if allow_interrupted_cache and php_fpm_uid is not None:
                allowed_owners.add(php_fpm_uid)
            _require(stat.S_ISREG(info.st_mode) and info.st_uid in allowed_owners
                     and (not allow_interrupted_cache or info.st_gid in {0, php_fpm_gid})
                     and stat.S_IMODE(info.st_mode) in {0o600, 0o640, 0o644}
                     and info.st_nlink == 1 and info.st_size <= 16 * 1024 * 1024,
                     "release_bootstrap_cache_metadata_invalid")
            continue
        if rel.startswith("bootstrap/cache/") and rel != "bootstrap/cache/.gitignore":
            raise DeployRefused("release_bootstrap_cache_unexpected")
        _require(not stat.S_ISLNK(info.st_mode), "release_symlink_rejected")
        _require(stat.S_ISREG(info.st_mode) and info.st_uid == expected_uid
                 and stat.S_IMODE(info.st_mode) == 0o644, "release_file_metadata_invalid")
        if rel == "release-manifest.json":
            continue
        raw = _read_regular_nofollow(path, max_bytes=MAX_UNPACKED_BYTES,
                                     expected_uid=expected_uid, expected_mode=0o644)
        result[rel] = hashlib.sha256(raw).hexdigest()
    return result


def _current_release(profile: StagingProfile) -> tuple[str, Path, Mapping[str, str]]:
    info = _lstat(profile.application_link, "active_release_missing")
    _require(stat.S_ISLNK(info.st_mode), "active_release_not_symlink")
    try:
        target_text = os.readlink(profile.application_link)
    except OSError:
        raise DeployRefused("active_release_unreadable") from None
    target = Path(target_text)
    if not target.is_absolute():
        target = profile.application_link.parent / target
    _require(target.parent == profile.releases_dir and APPLICATION_SHA_RE.fullmatch(target.name) is not None,
             "active_release_target_invalid")
    release_info = _lstat(target, "active_release_missing")
    _require(stat.S_ISDIR(release_info.st_mode) and release_info.st_uid == profile.expected_owner_uid
             and stat.S_IMODE(release_info.st_mode) == 0o755, "active_release_metadata_invalid")
    manifest = _release_manifest(profile, target, expected_uid=profile.expected_owner_uid)
    _require(manifest.get("application_sha") == target.name and manifest.get("profile") == PROFILE,
             "active_release_manifest_invalid")
    files = _hash_release_tree(target, expected_uid=profile.expected_owner_uid,
                               shared_env_file=profile.shared_env_file,
                               shared_storage_dir=profile.shared_storage_dir,
                               shared_vendor_dir=profile.shared_vendor_dir)
    _require(manifest.get("files_sha256") == _canonical_files_hash(files),
             "active_release_content_mismatch")
    _verify_frontend_build(manifest.get("frontend_build"), target, files)
    _require(REQUIRED_RUNTIME_FILES.issubset(files), "active_release_runtime_incomplete")
    _verify_generated_bootstrap_caches(profile, target)
    _verify_shared_inputs(profile)
    _verify_shared_vendor_for_lock(profile, target)
    return target.name, target, files


def _verify_inactive_release_candidate(profile: StagingProfile, release_dir: Path, *,
                                       application_sha: str, base_sha: str,
                                       artifact_sha256: str) -> Mapping[str, object]:
    """Revalidate an inactive, previously published candidate after process interruption."""
    info = _lstat(release_dir, "release_destination_invalid")
    _require(stat.S_ISDIR(info.st_mode) and info.st_uid == profile.expected_owner_uid
             and stat.S_IMODE(info.st_mode) == 0o755, "release_destination_invalid")
    manifest = _release_manifest(profile, release_dir, expected_uid=profile.expected_owner_uid)
    _require(manifest.get("application_sha") == application_sha
             and manifest.get("base_application_sha") == base_sha
             and manifest.get("artifact_sha256") == artifact_sha256,
             "published_candidate_identity_mismatch")
    # Reclaim only the fixed Laravel-generated cache files before evaluating
    # their content or accepting their metadata. A PHP-FPM process can die
    # after writing these files with executable/readable mode bits. They may
    # contain secrets (config.php), so close and remove them before continuing.
    if profile.build_application_bootstrap:
        _repair_interrupted_bootstrap_cache(profile, release_dir, manifest)
    files = _hash_release_tree(release_dir, expected_uid=profile.expected_owner_uid,
                               shared_env_file=profile.shared_env_file,
                               shared_storage_dir=profile.shared_storage_dir,
                               shared_vendor_dir=profile.shared_vendor_dir,
                               allow_interrupted_cache=False)
    _require(manifest.get("files_sha256") == _canonical_files_hash(files),
             "published_candidate_content_mismatch")
    _verify_frontend_build(manifest.get("frontend_build"), release_dir, files)
    _verify_artifact_matches_primary(profile, application_sha, release_dir, files, manifest)
    _verify_shared_inputs(profile)
    return manifest


def _repair_interrupted_bootstrap_cache(profile: StagingProfile, release_path: Path,
                                        manifest: Mapping[str, object]) -> None:
    """Reclaim only validated Laravel-generated cache files in an exact inactive release."""
    if not profile.build_application_bootstrap:
        return
    active_info = _lstat(profile.application_link, "active_release_missing")
    _require(stat.S_ISLNK(active_info.st_mode), "active_release_not_symlink")
    try:
        active_text = os.readlink(profile.application_link)
    except OSError:
        raise DeployRefused("active_release_unreadable") from None
    active_path = Path(active_text)
    if not active_path.is_absolute():
        active_path = profile.application_link.parent / active_path
    _require(active_path != release_path, "cache_repair_candidate_is_active")
    cache = release_path / "bootstrap/cache"
    info = _lstat(cache, "bootstrap_cache_unavailable")
    cache_mode = stat.S_IMODE(info.st_mode)
    valid_directory_owner = (
        (info.st_uid == profile.expected_owner_uid and info.st_gid == 0
         and cache_mode in {0o700, 0o755})
        or (info.st_uid == profile.php_fpm_uid and info.st_gid == profile.php_fpm_gid
            and cache_mode in {0o700, 0o755, 0o2750})
    )
    _require(stat.S_ISDIR(info.st_mode) and valid_directory_owner,
             "interrupted_bootstrap_cache_directory_invalid")
    allowed = {".gitignore", "config.php", "packages.php", "services.php"}
    try:
        entries = {entry.name for entry in os.scandir(cache)}
    except OSError:
        raise DeployRefused("interrupted_bootstrap_cache_unavailable") from None
    _require(entries.issubset(allowed) and ".gitignore" in entries,
             "interrupted_bootstrap_cache_unexpected_entry")
    gitignore = _lstat(cache / ".gitignore", "release_bootstrap_cache_metadata_invalid")
    _require(stat.S_ISREG(gitignore.st_mode) and gitignore.st_uid == profile.expected_owner_uid
             and gitignore.st_gid == 0 and stat.S_IMODE(gitignore.st_mode) == 0o644
             and gitignore.st_nlink == 1, "release_bootstrap_cache_metadata_invalid")
    generated_names = ("config.php", "packages.php", "services.php")
    for name in generated_names:
        path = cache / name
        try:
            generated = os.lstat(path)
        except FileNotFoundError:
            continue
        _require(_valid_interrupted_cache_file(generated, profile),
                 "interrupted_bootstrap_cache_file_invalid")
    # Seal the directory before touching secret-bearing cache files. In particular,
    # never make an FPM-owned 02750 cache traversable as 0755 while config.php may
    # still be world-readable. A failed cleanup leaves the inactive release sealed.
    try:
        os.chmod(cache, 0o700)
        os.chown(cache, profile.expected_owner_uid, 0)
        for name in generated_names:
            path = cache / name
            try:
                generated = os.lstat(path)
            except FileNotFoundError:
                continue
            _require(_valid_interrupted_cache_file(generated, profile),
                     "interrupted_bootstrap_cache_file_invalid")
            try:
                os.chmod(path, 0o600, follow_symlinks=False)
            except OSError:
                raise DeployRefused("interrupted_bootstrap_cache_restrict_failed") from None
            path.unlink()
        directory_fd = os.open(cache, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
        rebuilt_files = _hash_release_tree(
            release_path, expected_uid=profile.expected_owner_uid,
            shared_env_file=profile.shared_env_file, shared_storage_dir=profile.shared_storage_dir,
            shared_vendor_dir=profile.shared_vendor_dir,
        )
        _require(manifest.get("files_sha256") == _canonical_files_hash(rebuilt_files),
                 "published_candidate_content_mismatch")
        os.chmod(cache, 0o755)
    except DeployRefused:
        raise
    except OSError:
        raise DeployRefused("interrupted_bootstrap_cache_repair_failed") from None


def _verify_shared_inputs(profile: StagingProfile) -> None:
    env_info = _lstat(profile.shared_env_file, "shared_environment_unavailable")
    _require(stat.S_ISREG(env_info.st_mode) and env_info.st_uid == profile.expected_owner_uid
             and env_info.st_gid == profile.php_fpm_gid
             and stat.S_IMODE(env_info.st_mode) == 0o640, "shared_environment_metadata_invalid")
    storage_info = _lstat(profile.shared_storage_dir, "shared_storage_unavailable")
    _require(stat.S_ISDIR(storage_info.st_mode) and storage_info.st_uid == profile.php_fpm_uid
             and storage_info.st_gid == profile.php_fpm_gid
             and stat.S_IMODE(storage_info.st_mode) == 0o2770, "shared_storage_metadata_invalid")
    vendor_info = _lstat(profile.shared_vendor_dir, "shared_vendor_unavailable")
    _require(stat.S_ISDIR(vendor_info.st_mode) and vendor_info.st_uid == profile.expected_owner_uid
             and stat.S_IMODE(vendor_info.st_mode) == 0o755, "shared_vendor_metadata_invalid")
    autoload_info = _lstat(profile.shared_vendor_dir / "autoload.php", "shared_vendor_incomplete")
    _require(stat.S_ISREG(autoload_info.st_mode) and autoload_info.st_uid == profile.expected_owner_uid
             and stat.S_IMODE(autoload_info.st_mode) == 0o644, "shared_vendor_incomplete")


def _verify_shared_vendor_for_lock(profile: StagingProfile, release_dir: Path) -> None:
    """Bind shared Composer code to this release's locked production packages."""
    lock_raw = _read_regular_nofollow(release_dir / "composer.lock", max_bytes=16_000_000,
                                      expected_uid=profile.expected_owner_uid, expected_mode=0o644)
    installed_path = profile.shared_vendor_dir / "composer" / "installed.json"
    installed_raw = _read_regular_nofollow(installed_path, max_bytes=8_000_000,
                                           expected_uid=profile.expected_owner_uid,
                                           expected_mode=0o644)
    try:
        lock = json.loads(lock_raw.decode("utf-8"))
        installed = json.loads(installed_raw.decode("utf-8"))
    except (UnicodeError, ValueError):
        raise DeployRefused("composer_provenance_invalid") from None
    _require(isinstance(lock, dict) and isinstance(lock.get("packages"), list)
             and isinstance(lock.get("packages-dev"), list)
             and isinstance(installed, dict) and installed.get("dev") is False
             and isinstance(installed.get("packages"), list), "composer_provenance_invalid")

    def package_map(packages: list[object], *, installed_tree: bool) -> dict[str, tuple[str, str]]:
        result = {}
        for package in packages:
            _require(isinstance(package, dict), "composer_provenance_invalid")
            name, version = package.get("name"), package.get("version")
            _require(isinstance(name, str) and re.fullmatch(r"[a-z0-9_.-]+/[a-z0-9_.-]+", name)
                     and isinstance(version, str) and re.fullmatch(r"[A-Za-z0-9.+~-]{1,128}", version),
                     "composer_provenance_invalid")
            dist = package.get("dist")
            source = package.get("source")
            dist_ref = dist.get("reference") if isinstance(dist, dict) else None
            source_ref = source.get("reference") if isinstance(source, dict) else None
            reference = dist_ref or source_ref
            _require(isinstance(reference, str) and re.fullmatch(r"[A-Za-z0-9._/-]{1,256}", reference),
                     "composer_provenance_invalid")
            _require(name not in result, "composer_provenance_duplicate_package")
            result[name] = (version, reference)
        return result

    locked = package_map(lock["packages"], installed_tree=False)
    observed = package_map(installed["packages"], installed_tree=True)
    _require(len(locked) <= 512 and observed == locked, "composer_vendor_lock_mismatch")


def _attach_candidate_shared_links(profile: StagingProfile, candidate: Path) -> tuple[tuple[Path, Path], ...]:
    """Temporarily attach only pinned shared inputs while rehearsing the candidate."""
    _verify_shared_inputs(profile)
    links = ((candidate / ".env", profile.shared_env_file),
             (candidate / "storage", profile.shared_storage_dir),
             (candidate / "vendor", profile.shared_vendor_dir))
    created: list[tuple[Path, Path]] = []
    try:
        for link, target in links:
            if os.path.lexists(link):
                raise DeployRefused("phase_b_candidate_shared_link_collision")
            os.symlink(str(target), link)
            created.append((link, target))
        _verify_shared_vendor_for_lock(profile, candidate)
    except BaseException:
        for link, target in reversed(created):
            try:
                info = os.lstat(link)
                if stat.S_ISLNK(info.st_mode) and os.readlink(link) == str(target):
                    os.unlink(link)
            except OSError:
                pass
        raise
    return links


def _verify_candidate_shared_links(profile: StagingProfile, candidate: Path) -> None:
    """Verify the exact shared-input links on an already published candidate.

    Published candidates can be resumed after a process interruption. Their
    links are part of the immutable release identity, so this path must verify
    them without trying to recreate or replace them.
    """
    _verify_shared_inputs(profile)
    _verify_shared_vendor_for_lock(profile, candidate)
    links = ((candidate / ".env", profile.shared_env_file),
             (candidate / "storage", profile.shared_storage_dir),
             (candidate / "vendor", profile.shared_vendor_dir))
    for link, target in links:
        info = _lstat(link, "release_shared_link_invalid")
        _require(stat.S_ISLNK(info.st_mode), "release_shared_link_invalid")
        try:
            observed = os.readlink(link)
        except OSError:
            raise DeployRefused("release_shared_link_invalid") from None
        _require(observed == str(target), "release_shared_link_invalid")


def _remove_candidate_shared_links(links: tuple[tuple[Path, Path], ...]) -> None:
    for link, target in reversed(links):
        try:
            info = os.lstat(link)
            _require(stat.S_ISLNK(info.st_mode) and os.readlink(link) == str(target),
                     "phase_b_candidate_shared_link_changed")
            os.unlink(link)
        except OSError:
            raise DeployRefused("phase_b_candidate_shared_link_cleanup_failed") from None


def _github_json(path: str, *, timeout: float = 6.0, max_bytes: int = 4_000_000):
    _require(path.startswith("/repos/zsoltacsai/wpcaregrid/"), "github_request_path_invalid")

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, request, response, code, message, headers, new_url):
            return None

    request = urllib.request.Request(
        GITHUB_API + path,
        headers={"Accept": "application/vnd.github+json",
                 "User-Agent": f"WP-CareGrid-staging-next-deploy/{HELPER_VERSION}",
                 "X-GitHub-Api-Version": "2022-11-28"},
        method="GET",
    )
    try:
        with urllib.request.build_opener(NoRedirect).open(request, timeout=timeout) as response:
            if response.status != 200:
                raise DeployRefused("github_evidence_unavailable")
            raw = response.read(max_bytes + 1)
    except DeployRefused:
        raise
    except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError, OSError):
        raise DeployRefused("github_evidence_unavailable") from None
    _require(len(raw) <= max_bytes, "github_evidence_too_large")
    try:
        value = json.loads(raw.decode("utf-8"))
    except (UnicodeError, ValueError):
        raise DeployRefused("github_evidence_invalid") from None
    _require(isinstance(value, dict) or isinstance(value, list), "github_evidence_invalid")
    return value


def _local_git_tree(profile: StagingProfile, application_sha: str) -> Mapping[str, str]:
    repo = profile.test_git_repo
    _require(profile.test_only and repo is not None, "local_source_verifier_unavailable")
    try:
        current = subprocess.run(["git", "-C", str(repo), "rev-parse", f"refs/heads/{PRIMARY_BRANCH}"],
                                 check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                 timeout=5, text=True).stdout.strip()
        _require(current == application_sha, "primary_sha_is_not_latest")
        raw = subprocess.run(["git", "-C", str(repo), "ls-tree", "-r", "-z", "--full-tree", application_sha],
                             check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                             timeout=5).stdout
    except DeployRefused:
        raise
    except (subprocess.SubprocessError, OSError):
        raise DeployRefused("source_tree_unavailable") from None
    result: dict[str, str] = {}
    for entry in raw.split(b"\0"):
        if not entry:
            continue
        try:
            metadata, name_bytes = entry.split(b"\t", 1)
            mode, kind, blob_sha = metadata.decode("ascii").split(" ")
            name = name_bytes.decode("utf-8")
        except (ValueError, UnicodeError):
            raise DeployRefused("source_tree_invalid") from None
        _require(kind == "blob" and mode in {"100644", "100755"}, "source_tree_special_file_rejected")
        if name == ".env" or name.startswith(("storage/", "vendor/")):
            continue
        if _git_archive_excludes(name):
            continue
        result[name] = blob_sha
    return result


def _github_primary_tree(profile: StagingProfile, application_sha: str) -> Mapping[str, str]:
    branch = quote(PRIMARY_BRANCH, safe="")
    ref = _github_json(f"/repos/{GITHUB_REPOSITORY}/branches/{branch}")
    commit = ref.get("commit")
    _require(isinstance(commit, dict) and commit.get("sha") == application_sha,
             "primary_sha_is_not_latest")
    commit_data = _github_json(f"/repos/{GITHUB_REPOSITORY}/git/commits/{application_sha}")
    tree_data = commit_data.get("tree")
    tree_sha = tree_data.get("sha") if isinstance(tree_data, dict) else None
    _require(isinstance(tree_sha, str) and re.fullmatch(r"[0-9a-f]{40}", tree_sha) is not None,
             "source_tree_invalid")
    query = urlencode({"recursive": "1"})
    tree = _github_json(f"/repos/{GITHUB_REPOSITORY}/git/trees/{tree_sha}?{query}")
    entries = tree.get("tree")
    _require(tree.get("truncated") is False and isinstance(entries, list), "source_tree_incomplete")
    result = {}
    for entry in entries:
        if not isinstance(entry, dict) or entry.get("type") != "blob":
            continue
        name, mode, blob_sha = entry.get("path"), entry.get("mode"), entry.get("sha")
        _require(isinstance(name, str) and isinstance(blob_sha, str)
                 and mode in {"100644", "100755"}, "source_tree_special_file_rejected")
        if name == ".env" or name.startswith(("storage/", "vendor/")):
            continue
        if _git_archive_excludes(name):
            continue
        result[name] = blob_sha
    return result


def _verify_primary_ci_and_reviews(profile: StagingProfile, application_sha: str) -> None:
    if profile.test_only:
        return
    _require(isinstance(profile.integration_qa_reviewer, str)
             and isinstance(profile.m17_appsec_reviewer, str)
             and profile.integration_qa_reviewer != profile.m17_appsec_reviewer,
             "reviewer_policy_unconfigured")
    def verify_run(commit_sha: str, *, event: str, branch: str | None, pr_number: int | None = None) -> None:
        query_values = {"head_sha": commit_sha, "event": event, "per_page": 100}
        if branch is not None:
            query_values["branch"] = branch
        runs_response = _github_json(
            f"/repos/{GITHUB_REPOSITORY}/actions/runs?{urlencode(query_values)}")
        runs = runs_response.get("workflow_runs")
        total = runs_response.get("total_count")
        _require(isinstance(runs, list) and type(total) is int and total <= len(runs),
                 "ci_evidence_incomplete")
        matches = []
        for item in runs:
            if not isinstance(item, dict) or item.get("head_sha") != commit_sha \
                    or item.get("event") != event or item.get("path") != ".github/workflows/ci.yml":
                continue
            if branch is not None and item.get("head_branch") != branch:
                continue
            if pr_number is not None:
                prs = item.get("pull_requests")
                if not isinstance(prs, list) or not any(isinstance(pr, dict)
                        and pr.get("number") == pr_number
                        and isinstance(pr.get("base"), dict)
                        and pr["base"].get("ref") == PRIMARY_BRANCH for pr in prs):
                    continue
            matches.append(item)
        _require(bool(matches), "ci_evidence_missing")
        latest = max(matches, key=lambda item: str(item.get("created_at", "")))
        _require(latest.get("status") == "completed" and latest.get("conclusion") == "success",
                 "ci_evidence_failed")
        run_id = latest.get("id")
        _require(type(run_id) is int and run_id > 0, "ci_evidence_invalid")
        jobs_response = _github_json(
            f"/repos/{GITHUB_REPOSITORY}/actions/runs/{run_id}/jobs?per_page=100")
        jobs = jobs_response.get("jobs")
        total_jobs = jobs_response.get("total_count")
        _require(isinstance(jobs, list) and type(total_jobs) is int and total_jobs <= len(jobs),
                 "ci_jobs_incomplete")
        grouped = {}
        for job in jobs:
            if isinstance(job, dict) and isinstance(job.get("name"), str):
                grouped.setdefault(job["name"], []).append(job)
        for name in REQUIRED_CI_JOBS:
            instances = grouped.get(name, [])
            _require(len(instances) == 1 and instances[0].get("status") == "completed"
                     and instances[0].get("conclusion") == "success", "required_ci_check_failed")

    pulls = _github_json(f"/repos/{GITHUB_REPOSITORY}/commits/{application_sha}/pulls?per_page=100")
    _require(isinstance(pulls, list), "merged_pr_evidence_invalid")
    merged = [pr for pr in pulls if isinstance(pr, dict)
              and pr.get("merge_commit_sha") == application_sha
              and isinstance(pr.get("base"), dict) and pr["base"].get("ref") == PRIMARY_BRANCH]
    _require(len(merged) == 1, "merged_pr_evidence_missing_or_ambiguous")
    pr_number = merged[0].get("number")
    pr_detail = _github_json(f"/repos/{GITHUB_REPOSITORY}/pulls/{pr_number}") if type(pr_number) is int else {}
    _require(isinstance(pr_detail, dict) and isinstance(pr_detail.get("merged_at"), str)
             and isinstance(pr_detail.get("base"), dict)
             and pr_detail["base"].get("ref") == PRIMARY_BRANCH,
             "merged_pr_evidence_invalid")
    pr_head = pr_detail.get("head")
    pr_head_sha = pr_head.get("sha") if isinstance(pr_head, dict) else None
    _require(type(pr_number) is int and isinstance(pr_head_sha, str)
             and re.fullmatch(r"[0-9a-f]{40}", pr_head_sha) is not None,
             "merged_pr_evidence_invalid")
    verify_run(pr_head_sha, event="pull_request", branch=None, pr_number=pr_number)
    verify_run(application_sha, event="push", branch=PRIMARY_BRANCH)
    review_data = _github_json(f"/repos/{GITHUB_REPOSITORY}/pulls/{pr_number}/reviews?per_page=100")
    _require(isinstance(review_data, list), "review_evidence_invalid")
    latest_reviews = {}
    for review in review_data:
        if (isinstance(review, dict) and isinstance(review.get("user"), dict)
                and review["user"].get("login") in {profile.integration_qa_reviewer, profile.m17_appsec_reviewer}
                and review.get("commit_id") == pr_head_sha):
            latest_reviews[review["user"]["login"]] = review.get("state")
    _require(latest_reviews.get(profile.integration_qa_reviewer) == "APPROVED"
             and latest_reviews.get(profile.m17_appsec_reviewer) == "APPROVED",
             "independent_review_evidence_missing")


def authorize_release(profile: StagingProfile, *, invoking_uid: int,
                      application_sha: str) -> Mapping[str, object]:
    """Authorize one exact merged primary SHA before any frontend build script runs."""
    try:
        _safe_profile(profile)
        _require(invoking_uid == profile.allowed_uid, "caller_identity_invalid")
        _require(_sha(application_sha, APPLICATION_SHA_RE), "release_identity_invalid")
        branch = quote(PRIMARY_BRANCH, safe="")
        ref = _github_json(f"/repos/{GITHUB_REPOSITORY}/branches/{branch}") if not profile.test_only else None
        if not profile.test_only:
            commit = ref.get("commit") if isinstance(ref, dict) else None
            _require(isinstance(commit, dict) and commit.get("sha") == application_sha,
                     "primary_sha_is_not_latest")
        (_local_git_tree(profile, application_sha) if profile.test_only
         else _github_primary_tree(profile, application_sha))
        _verify_primary_ci_and_reviews(profile, application_sha)
        return {"result": "PASS", "reason": "exact_primary_release_authorized",
                "application_sha": application_sha, "profile": PROFILE,
                "helper_version": HELPER_VERSION, "helper_contract": HELPER_CONTRACT,
                "helper_artifact_sha256": profile.helper_artifact_sha256}
    except DeployRefused as error:
        return {"result": "HOLD", "reason": str(error), "application_sha": application_sha,
                "profile": PROFILE, "helper_version": HELPER_VERSION,
                "helper_contract": HELPER_CONTRACT,
                "helper_artifact_sha256": profile.helper_artifact_sha256}
    except Exception:
        return {"result": "FAIL", "reason": "release_authorization_failed",
                "application_sha": application_sha, "profile": PROFILE,
                "helper_version": HELPER_VERSION, "helper_contract": HELPER_CONTRACT,
                "helper_artifact_sha256": profile.helper_artifact_sha256}


def _verify_artifact_matches_primary(profile: StagingProfile, app_sha: str, extracted: Path,
                                     candidate_files: Mapping[str, str],
                                     manifest: Mapping[str, object]) -> None:
    tree = (_local_git_tree(profile, app_sha) if profile.test_only
            else _github_primary_tree(profile, app_sha))
    tree = {name: digest for name, digest in tree.items() if not name.startswith("public/build/")}
    source_files = {name: digest for name, digest in candidate_files.items()
                    if not name.startswith("public/build/")}
    _require(set(tree) == set(source_files), "artifact_source_file_set_mismatch")
    for name, expected_blob_sha in tree.items():
        raw = _read_regular_nofollow(extracted / name, max_bytes=MAX_UNPACKED_BYTES,
                                     expected_uid=profile.expected_owner_uid, expected_mode=0o644)
        actual_blob_sha = hashlib.sha1(b"blob " + str(len(raw)).encode("ascii") + b"\0" + raw).hexdigest()
        _require(actual_blob_sha == expected_blob_sha, "artifact_source_content_mismatch")
    _verify_frontend_build(manifest.get("frontend_build"), extracted, candidate_files)
    _verify_primary_ci_and_reviews(profile, app_sha)


def _phase_a_paths_only(previous: Mapping[str, str], candidate: Mapping[str, str]) -> bool:
    for name in set(previous) | set(candidate):
        if previous.get(name) == candidate.get(name):
            continue
        if name not in PHASE_A_PATHS and not any(name.startswith(prefix) for prefix in PHASE_A_PATH_PREFIXES):
            return False
    return True


def _atomic_symlink(target: Path, link_path: Path, *, expected_owner_uid: int) -> None:
    parent_info = _lstat(link_path.parent, "application_parent_unavailable")
    _require(stat.S_ISDIR(parent_info.st_mode) and parent_info.st_uid == expected_owner_uid
             and stat.S_IMODE(parent_info.st_mode) & 0o022 == 0, "application_parent_metadata_invalid")
    temporary = link_path.parent / f".{link_path.name}.next-{os.getpid()}-{time.monotonic_ns()}"
    try:
        os.symlink(str(target), temporary)
        if link_path.exists() or link_path.is_symlink():
            existing = _lstat(link_path, "active_release_unavailable")
            _require(stat.S_ISLNK(existing.st_mode) and existing.st_uid == expected_owner_uid,
                     "active_release_metadata_invalid")
        os.replace(temporary, link_path)
        dir_fd = os.open(link_path.parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(dir_fd)
        finally:
            os.close(dir_fd)
    except DeployRefused:
        raise
    except OSError:
        raise DeployRefused("active_release_switch_failed") from None
    finally:
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass


def _write_receipt(profile: StagingProfile, receipt: Mapping[str, object]) -> tuple[str, str]:
    _verify_directory_chain(profile.receipts_dir, owner_uid=profile.expected_owner_uid, exact_mode=0o700)
    raw = json.dumps(receipt, sort_keys=True, separators=(",", ":")).encode("utf-8") + b"\n"
    app_sha = receipt.get("application_sha")
    artifact_sha = receipt.get("artifact_sha256")
    result = receipt.get("result")
    completed_at = receipt.get("completed_at")
    _require(isinstance(completed_at, str) and len(completed_at) <= 64,
             "receipt_timestamp_invalid")
    safe_id = hashlib.sha256(f"{app_sha}:{artifact_sha}:{result}".encode()).hexdigest()[:20]
    observation_id = hashlib.sha256(completed_at.encode("utf-8")).hexdigest()[:12]
    path = profile.receipts_dir / f"deploy-{safe_id}-{observation_id}.json"
    temp = profile.receipts_dir / f".receipt-{os.getpid()}-{time.monotonic_ns()}"
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        with os.fdopen(fd, "wb", closefd=True) as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
        os.link(temp, path, follow_symlinks=False)
        dir_fd = os.open(profile.receipts_dir, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(dir_fd)
        finally:
            os.close(dir_fd)
    except FileExistsError:
        raise DeployRefused("receipt_already_exists") from None
    except OSError:
        raise DeployRefused("receipt_write_failed") from None
    finally:
        try:
            temp.unlink()
        except FileNotFoundError:
            pass
    return str(path), hashlib.sha256(raw).hexdigest()


def _successful_receipt(profile: StagingProfile, application_sha: str,
                        artifact_sha256: str) -> tuple[Mapping[str, object], str] | None:
    identity = hashlib.sha256(
        f"{application_sha}:{artifact_sha256}:PASS".encode("utf-8")
    ).hexdigest()[:20]
    prefix = f"deploy-{identity}-"
    matches = sorted(profile.receipts_dir.glob(prefix + "*.json"))
    _require(len(matches) <= 32, "release_receipt_count_exceeded")
    for path in matches:
        raw = _read_regular_nofollow(path, max_bytes=16_384,
                                     expected_uid=profile.expected_owner_uid, expected_mode=0o600)
        receipt = _strict_json(raw)
        _require(receipt.get("profile") == PROFILE
                 and receipt.get("application_sha") == application_sha
                 and receipt.get("artifact_sha256") == artifact_sha256
                 and receipt.get("result") == "PASS"
                 and receipt.get("helper_version") == HELPER_VERSION
                 and receipt.get("helper_contract") == HELPER_CONTRACT,
                 "release_receipt_identity_invalid")
        return receipt, hashlib.sha256(raw).hexdigest()
    return None


def _successful_receipt_exists(profile: StagingProfile, application_sha: str,
                               artifact_sha256: str) -> bool:
    return _successful_receipt(profile, application_sha, artifact_sha256) is not None


def _reconcile_database_success_receipt(profile: StagingProfile, *, receipt: Mapping[str, object],
                                        receipt_sha256: str, application_sha: str,
                                        active_path: Path, active_manifest: Mapping[str, object]) -> None:
    """Finish only a receipt-backed transaction after independently rechecking its live DB state."""
    migration_scope = active_manifest.get("database_scope") == "migrations"
    _require(receipt.get("database_changed") is migration_scope,
             "phase_b_release_receipt_scope_mismatch")
    if receipt.get("database_changed") is not True:
        return
    observed_sha, observed_path, _observed_files = _current_release(profile)
    _require(observed_sha == application_sha and observed_path == active_path,
             "phase_b_receipt_active_release_mismatch")
    _verify_runtime(profile, active_path)
    healthy, _statuses, _unauthenticated = _bounded_acceptance(profile)
    _require(healthy, "phase_b_receipt_acceptance_reverification_failed")
    transaction_id = receipt.get("database_transaction_id")
    _require(isinstance(transaction_id, str) and re.fullmatch(r"[0-9a-f]{32}", transaction_id),
             "phase_b_release_receipt_transaction_invalid")
    migration_items = active_manifest.get("database_migrations")
    _require(isinstance(migration_items, list) and migration_items
             and all(isinstance(item, Mapping) and isinstance(item.get("name"), str)
                     for item in migration_items), "phase_b_release_migration_manifest_invalid")
    migrations = tuple(item["name"] for item in migration_items)
    identity, _observed_journal = DatabaseReleaseTransaction.load_existing_by_transaction_id(
        profile.state_dir, transaction_id)
    _require(identity.application_sha == application_sha
             and identity.previous_sha == active_manifest.get("base_application_sha")
             and identity.artifact_sha256 == active_manifest.get("artifact_sha256")
             and identity.migrations == migrations
             and identity.backup_sha256 == receipt.get("database_backup_sha256")
             and receipt.get("restore_receipt_sha256")
             and receipt.get("migration_rehearsal_receipt_sha256")
             and receipt.get("migration_receipt_sha256")
             and receipt.get("active_sha_after_deploy") == application_sha
             and receipt.get("files_sha256") == active_manifest.get("files_sha256"),
             "phase_b_release_receipt_journal_mismatch")
    policy = _load_phase_b_policy(profile)
    _require(identity.mysql_uuid_sha256 == profile.mysql_server_uuid_sha256
             and identity.mysql_uuid_sha256 == policy.target_uuid_sha256,
             "phase_b_database_identity_mismatch")
    backup_path = policy.backup_directory / identity.backup_filename
    backup_sha, _backup_size = _verify_backup_sql(backup_path, expected_uid=0, expected_mode=0o600)
    _require(backup_sha == identity.backup_sha256, "phase_b_pinned_backup_identity_mismatch")
    transaction = DatabaseReleaseTransaction(profile.state_dir, identity)
    try:
        transaction.__enter__()
        state = transaction.state
        _require(state.get("state") in {"ACCEPTANCE_VERIFIED", "COMPLETE"}
                 and state.get("migration_cursor") == len(migrations),
                 "phase_b_receipt_transaction_not_accepted")
        evidence = state["evidence"]
        restore = evidence.get("RESTORE_REHEARSAL_VERIFIED", {})
        rehearsal = evidence.get("MIGRATION_REHEARSAL_VERIFIED", {})
        migrated = evidence.get("MIGRATIONS_VERIFIED", {})
        final_migration = evidence.get(f"migration:{migrations[-1]}", {})
        _require(restore.get("backup_sha256") == identity.backup_sha256
                 and restore.get("restore_receipt_sha256") == receipt.get("restore_receipt_sha256")
                 and rehearsal.get("migration_rehearsal_receipt_sha256")
                 == receipt.get("migration_rehearsal_receipt_sha256")
                 and migrated.get("migration_receipt_sha256") == receipt.get("migration_receipt_sha256")
                 and all(isinstance(final_migration.get(key), str) for key in (
                     "schema_sha256", "ledger_sha256", "data_sha256")),
                 "phase_b_receipt_evidence_mismatch")
        current = database_fingerprint(policy, database=DATABASE, isolated=False)
        _require(current.get("mysql_uuid_sha256") == identity.mysql_uuid_sha256
                 and current.get("schema_sha256") == final_migration.get("schema_sha256")
                 and current.get("ledger_sha256") == final_migration.get("ledger_sha256")
                 and current.get("data_sha256") == final_migration.get("data_sha256")
                 and all(name in current.get("migrations", ()) for name in migrations),
                 "phase_b_receipt_database_state_mismatch")
        _verify_phase_b_fences(profile, policy)
        if state.get("state") == "ACCEPTANCE_VERIFIED":
            transaction.advance("COMPLETE", {"release_receipt_sha256": receipt_sha256})
        else:
            _require(state.get("evidence", {}).get("COMPLETE", {}).get("release_receipt_sha256")
                     == receipt_sha256, "phase_b_release_receipt_hash_mismatch")
    except TransactionRefused as error:
        raise DeployRefused(str(error)) from None
    finally:
        if transaction._lock_fd is not None:
            transaction.__exit__(None, None, None)


def _verify_release_directory(profile: StagingProfile, release_path: Path, application_sha: str):
    info = _lstat(release_path, "rollback_release_unavailable")
    _require(stat.S_ISDIR(info.st_mode) and info.st_uid == profile.expected_owner_uid
             and stat.S_IMODE(info.st_mode) == 0o755 and release_path.name == application_sha,
             "rollback_release_identity_invalid")
    manifest = _release_manifest(profile, release_path, expected_uid=profile.expected_owner_uid)
    files = _hash_release_tree(release_path, expected_uid=profile.expected_owner_uid,
                               shared_env_file=profile.shared_env_file,
                               shared_storage_dir=profile.shared_storage_dir,
                               shared_vendor_dir=profile.shared_vendor_dir)
    _require(manifest.get("files_sha256") == _canonical_files_hash(files),
             "rollback_release_content_invalid")
    _verify_frontend_build(manifest.get("frontend_build"), release_path, files)
    return manifest


def _reconcile_unreceipted_active_release(profile: StagingProfile, *, application_sha: str,
                                          active_path: Path, active_manifest: Mapping[str, object],
                                          artifact_sha256: str) -> DeploymentResult:
    """Reconcile a pointer switch that occurred before a durable success receipt."""
    if active_manifest.get("database_scope") == "migrations":
        try:
            _create_maintenance_gate(profile)
        except DeployRefused as error:
            if str(error) != "phase_b_maintenance_gate_preexisting":
                return DeploymentResult("HOLD", "phase_b_recovery_gate_unverified", application_sha,
                                        artifact_sha256=artifact_sha256)
            try:
                _verify_maintenance_gate_closed(profile)
            except DeployRefused:
                return DeploymentResult("HOLD", "phase_b_recovery_gate_unverified", application_sha,
                                        artifact_sha256=artifact_sha256)
        return DeploymentResult("HOLD", "phase_b_unreceipted_activation_requires_recovery",
                                application_sha, artifact_sha256=artifact_sha256)
    base_sha = active_manifest.get("base_application_sha")
    _require(_sha(base_sha, APPLICATION_SHA_RE) and base_sha != application_sha,
             "interrupted_release_base_identity_invalid")
    base_path = profile.releases_dir / base_sha
    _verify_release_directory(profile, base_path, base_sha)
    active_statuses: tuple[int, ...] = ()
    active_unauthenticated: tuple[int, ...] = ()
    healthy = False
    try:
        _reload_php_fpm(profile)
        _verify_runtime(profile, active_path)
        healthy, active_statuses, active_unauthenticated = _bounded_acceptance(profile)
    except Exception:
        healthy = False
    if healthy:
        receipt = {
            "schema": 1, "profile": PROFILE, "result": "PASS",
            "reason": "interrupted_activation_reconciled", "application_sha": application_sha,
            "previous_sha": base_sha, "artifact_sha256": artifact_sha256,
            "files_sha256": active_manifest.get("files_sha256"), "helper_version": HELPER_VERSION,
            "helper_contract": HELPER_CONTRACT, "database_changed": False,
            "acceptance": [
                {"url_id": index, "expected_status": expected, "observed_status": observed}
                for index, ((_, expected), observed) in enumerate(
                    zip(profile.acceptance_urls, active_statuses), 1)
            ],
            "unauthenticated_protected_acceptance": [
                {"url_id": index, "expected_status": 401, "observed_status": observed}
                for index, observed in enumerate(active_unauthenticated, 1)
            ],
            "active_sha_after_deploy": application_sha,
            "completed_at": datetime.now(timezone.utc).isoformat(),
        }
        path, digest = _write_receipt(profile, receipt)
        return DeploymentResult("PASS", "interrupted_activation_reconciled", application_sha,
                                base_sha, artifact_sha256, path, digest)

    # Never leave an unaccepted candidate active after a restart. Restore the
    # previously verified immutable release, then verify its runtime and gate.
    _atomic_symlink(base_path, profile.application_link, expected_owner_uid=profile.expected_owner_uid)
    _reload_php_fpm(profile)
    recovered_sha, recovered_path, _ = _current_release(profile)
    _require(recovered_sha == base_sha and recovered_path == base_path,
             "interrupted_release_rollback_identity_invalid")
    _verify_runtime(profile, recovered_path)
    base_healthy, base_statuses, base_unauthenticated = _bounded_acceptance(profile)
    _require(base_healthy, "interrupted_release_rollback_acceptance_failed")
    receipt = {
        "schema": 1, "profile": PROFILE, "result": "FAIL",
        "reason": "interrupted_activation_acceptance_failed_rolled_back",
        "application_sha": application_sha, "previous_sha": base_sha,
        "artifact_sha256": artifact_sha256, "helper_version": HELPER_VERSION,
        "helper_contract": HELPER_CONTRACT, "database_changed": False,
        "acceptance": [
            {"url_id": index, "expected_status": expected, "observed_status": observed}
            for index, ((_, expected), observed) in enumerate(
                zip(profile.acceptance_urls, active_statuses), 1)
        ],
        "unauthenticated_protected_acceptance": [
            {"url_id": index, "expected_status": 401, "observed_status": observed}
            for index, observed in enumerate(active_unauthenticated, 1)
        ],
        "recovery_acceptance": [
            {"url_id": index, "expected_status": expected, "observed_status": observed}
            for index, ((_, expected), observed) in enumerate(zip(profile.acceptance_urls, base_statuses), 1)
        ],
        "recovery_unauthenticated_protected_acceptance": [
            {"url_id": index, "expected_status": 401, "observed_status": observed}
            for index, observed in enumerate(base_unauthenticated, 1)
        ],
        "active_sha_after_recovery": base_sha,
        "completed_at": datetime.now(timezone.utc).isoformat(),
    }
    path, digest = _write_receipt(profile, receipt)
    return DeploymentResult("FAIL", "interrupted_activation_acceptance_failed_rolled_back",
                            application_sha, base_sha, artifact_sha256, path, digest)


def _bounded_acceptance(profile: StagingProfile, *, timeout: float = 8.0,
                        opener=None) -> tuple[bool, tuple[int, ...], tuple[int, ...]]:
    """Probe only fixed profile URLs with bounded responses; never accepts caller-selected targets."""
    if profile.test_only:
        _require(all(url.startswith(("http://127.0.0.1:", "http://localhost:"))
                     for url, _status in profile.acceptance_urls),
                 "health_target_invalid")
        _require(all(url.startswith(("http://127.0.0.1:", "http://localhost:"))
                     for url in profile.protected_acceptance_urls),
                 "auth_probe_target_invalid")
    else:
        _require(all(url.startswith("https://staging-next.wpcaregrid.com/") for url, _status in profile.acceptance_urls),
                 "health_target_invalid")
        _require(bool(profile.protected_acceptance_urls)
                 and all(url.startswith("https://staging-next.wpcaregrid.com/")
                         for url in profile.protected_acceptance_urls),
                 "auth_probe_target_invalid")
    observed: list[int] = []
    authorization = _basic_auth_header(profile)

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, request, response, code, message, headers, new_url):
            return None

    client = opener or urllib.request.build_opener(NoRedirect)
    passed = True
    unauthenticated_statuses: list[int] = []
    unauthenticated_client = urllib.request.build_opener(NoRedirect)
    _require(not profile.protected_acceptance_urls or bool(authorization),
             "http_probe_credentials_unconfigured")
    for url in profile.protected_acceptance_urls:
        request = urllib.request.Request(url, headers={"User-Agent": f"WP-CareGrid-staging-next-deploy/{HELPER_VERSION}"})
        try:
            with unauthenticated_client.open(request, timeout=timeout) as response:
                body = response.read(MAX_HTTP_BODY_BYTES + 1)
                code = response.status
                passed = passed and code == 401 and len(body) <= MAX_HTTP_BODY_BYTES
        except urllib.error.HTTPError as response:
            code = response.code
            body = response.read(MAX_HTTP_BODY_BYTES + 1)
            passed = passed and code == 401 and len(body) <= MAX_HTTP_BODY_BYTES
        except (urllib.error.URLError, TimeoutError, OSError):
            code = 0
            passed = False
        unauthenticated_statuses.append(code)

    for url, expected_status in profile.acceptance_urls:
        headers = {"User-Agent": f"WP-CareGrid-staging-next-deploy/{HELPER_VERSION}"}
        if authorization:
            headers["Authorization"] = authorization
        request = urllib.request.Request(url, headers=headers)
        try:
            with (client.urlopen(request, timeout=timeout) if hasattr(client, "urlopen")
                  else client.open(request, timeout=timeout)) as response:
                body = response.read(MAX_HTTP_BODY_BYTES + 1)
                code = response.status
                passed = passed and code == expected_status and len(body) <= MAX_HTTP_BODY_BYTES
        except urllib.error.HTTPError as response:
            code = response.code
            body = response.read(MAX_HTTP_BODY_BYTES + 1)
            passed = passed and code == expected_status and len(body) <= MAX_HTTP_BODY_BYTES
        except (urllib.error.URLError, TimeoutError, OSError):
            code = 0
            passed = False
        observed.append(code)
    return passed, tuple(observed), tuple(unauthenticated_statuses)


def _basic_auth_header(profile: StagingProfile) -> str | None:
    if profile.test_only and profile.basic_auth_file is None:
        return None
    _require(profile.basic_auth_file is not None, "http_probe_credentials_unconfigured")
    raw = _read_regular_nofollow(profile.basic_auth_file, max_bytes=4096,
                                 expected_uid=profile.expected_owner_uid, expected_mode=0o600)
    try:
        import json
        credentials = json.loads(raw.decode("utf-8"))
    except (UnicodeError, ValueError):
        raise DeployRefused("http_probe_credentials_invalid") from None
    _require(isinstance(credentials, dict) and set(credentials) == {"username", "password"}
             and all(isinstance(value, str) and 1 <= len(value) <= 1024
                     and "\r" not in value and "\n" not in value
                     for value in credentials.values()), "http_probe_credentials_invalid")
    token = base64.b64encode(f"{credentials['username']}:{credentials['password']}".encode()).decode("ascii")
    return f"Basic {token}"


def _run_fixed(argv: list[str], *, cwd: Path | None = None, timeout: float = 15.0,
               maximum: int = 4096, extra_env: Mapping[str, str] | None = None,
               home: Path | None = None, classify_failure: bool = False) -> bytes:
    environment = {"PATH": "/usr/bin:/bin", "HOME": "/root", "LANG": "C"}
    if home is not None:
        _require(home.is_absolute() and ".." not in home.parts, "runtime_probe_environment_invalid")
        environment["HOME"] = str(home)
    if extra_env is not None:
        _require(all(key in {"WPCG_MYSQL_HOST", "WPCG_MYSQL_PORT", "WPCG_MYSQL_DATABASE",
                             "WPCG_MYSQL_USER", "WPCG_MYSQL_PASSWORD", "WPCG_MIGRATION_PATH",
                             "WPCG_RELEASE_PATH"}
                     and isinstance(value, str) and len(value) <= 1024
                     and "\x00" not in value for key, value in extra_env.items()), "runtime_probe_environment_invalid")
        environment.update(extra_env)
    try:
        result = subprocess.run(argv, cwd=cwd, env=environment,
                                stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=timeout, check=False)
    except subprocess.TimeoutExpired:
        raise DeployRefused("runtime_probe_timeout") from None
    except OSError:
        raise DeployRefused("runtime_probe_unavailable") from None
    _require(len(result.stdout) <= maximum and len(result.stderr) <= maximum, "runtime_probe_output_invalid")
    if result.returncode != 0:
        reason = (_runtime_failure_code(result.stdout, result.stderr, result.returncode)
                  if classify_failure else f"runtime_probe_failed_{result.returncode}")
        raise DeployRefused(reason)
    return result.stdout


def _runtime_failure_code(stdout: bytes, stderr: bytes, returncode: int) -> str:
    """Reduce transient PHP command output to fixed failure classes and DB codes."""
    raw = stderr[:16_384] + b"\n" + stdout[:16_384]
    diagnostic = raw.lower()
    sqlstate = re.search(rb"sqlstate\s*\[([a-z0-9]{3,8})\]", diagnostic)
    mysql_errno = re.search(rb"(?:error\s+code\s*: ?\s*|errno\s*[=:]?\s*|error\s*:\s*)([0-9]{1,5})\b",
                            diagnostic)
    if mysql_errno is not None:
        number = mysql_errno.group(1).decode("ascii")
        state = sqlstate.group(1).decode("ascii") if sqlstate is not None else "unknown"
        return f"runtime_probe_mysql_errno_{number}_sqlstate_{state}"
    if b"permission denied" in diagnostic or b"failed to open stream" in diagnostic:
        if b"database/migrations" in diagnostic:
            return "runtime_probe_migration_file_permission_denied"
        if b"bootstrap/cache" in diagnostic:
            return "runtime_probe_bootstrap_cache_permission_denied"
        if b"storage/framework/cache" in diagnostic or b"storage/framework/sessions" in diagnostic:
            return "runtime_probe_storage_cache_permission_denied"
        if b".env" in diagnostic:
            return "runtime_probe_env_file_permission_denied"
        if b"vendor/autoload.php" in diagnostic:
            return "runtime_probe_vendor_permission_denied"
        return "runtime_probe_permission_denied"
    if b"could not find driver" in diagnostic:
        return "runtime_probe_database_driver_missing"
    if b"allowed memory size" in diagnostic:
        return "runtime_probe_php_fatal_memory_limit"
    if b"maximum execution time" in diagnostic:
        return "runtime_probe_php_fatal_execution_timeout"
    if b"fatal error" in diagnostic or b"uncaught" in diagnostic:
        kind = next((code for marker, code in (
            (b"undefined function", "undefined_function"),
            (b"class ", "class_error"),
            (b"undefined method", "undefined_method"),
            (b"undefined array key", "undefined_array_key"),
            (b"trying to access array offset", "array_offset_access"),
            (b"array offset on value", "array_offset_access"),
            (b"typeerror", "type_error"),
            (b"parse error", "parse_error"),
            (b"failed opening required", "required_file_missing"),
            (b"no such file or directory", "file_missing"),
            (b"cache", "cache_error"),
            (b"permission denied", "permission_denied"),
        ) if marker in diagnostic), "unknown")
        exception_match = re.search(rb"uncaught\s+([A-Za-z_\\][A-Za-z0-9_\\]*)", raw, re.I)
        exception_name = "unknown"
        if exception_match:
            short_name = exception_match.group(1).rsplit(b"\\", 1)[-1].lower()
            if re.fullmatch(rb"[a-z][a-z0-9_]{0,63}", short_name):
                exception_name = short_name.decode("ascii")
        file_match = re.search(rb"(?:\s+in\s+|\s+at\s+)([^\s:]{1,512}\.php):[0-9]+", raw, re.I)
        file_name = "unknown"
        if file_match:
            basename = re.split(rb"[/\\]", file_match.group(1))[-1][:-4].lower()
            if re.fullmatch(rb"[a-z][a-z0-9_.-]{0,95}", basename):
                file_name = re.sub(r"[^a-z0-9]+", "_", basename.decode("ascii")).strip("_") or "unknown"
        safe_words = {b"trying", b"access", b"array", b"offset", b"value", b"type", b"null",
                       b"undefined", b"key", b"variable", b"function", b"method", b"class",
                       b"file", b"open", b"stream", b"permission", b"denied", b"cache",
                       b"directory", b"missing", b"failed", b"exists", b"headers", b"sent",
                       b"already", b"string", b"conversion", b"foreach", b"argument", b"must",
                       b"deprecated", b"memory", b"limit", b"timeout", b"resource", b"invalid"}
        tokens = sorted(set(re.findall(rb"[a-z]+", diagnostic)) & safe_words)[:8]
        token_code = "_".join(token.decode("ascii") for token in tokens) or "none"
        return f"runtime_probe_php_fatal_{kind}_{exception_name}_in_{file_name}_tokens_{token_code}"
    if b"exception" in diagnostic:
        return "runtime_probe_php_exception"
    return f"runtime_probe_failed_{returncode}"


def _run_as_php_fpm(profile: StagingProfile, argv: list[str], *, cwd: Path,
                    timeout: float, maximum: int = 4096,
                    extra_env: Mapping[str, str] | None = None,
                    classify_failure: bool = False) -> bytes:
    """Run application-controlled PHP only as the fixed, unprivileged FPM identity."""
    _require(type(profile.php_fpm_uid) is int and profile.php_fpm_uid >= 0
             and type(profile.php_fpm_gid) is int and profile.php_fpm_gid >= 0,
             "php_fpm_identity_policy_invalid")
    home = profile.shared_storage_dir
    if os.geteuid() == 0:
        try:
            fpm_user = pwd.getpwuid(profile.php_fpm_uid).pw_name
            fpm_group = grp.getgrgid(profile.php_fpm_gid).gr_name
        except KeyError:
            raise DeployRefused("php_fpm_identity_unavailable") from None
        _require(bool(fpm_user) and bool(fpm_group), "php_fpm_identity_unavailable")
        prefix = ["/usr/sbin/runuser", "--user", fpm_user,
                  "--group", fpm_group, "--"]
        return _run_fixed([*prefix, *argv], cwd=cwd, timeout=timeout, maximum=maximum,
                          extra_env=extra_env, home=home, classify_failure=classify_failure)
    _require(os.geteuid() == profile.php_fpm_uid, "runtime_probe_identity_mismatch")
    return _run_fixed(argv, cwd=cwd, timeout=timeout, maximum=maximum,
                      extra_env=extra_env, home=home, classify_failure=classify_failure)


def _execute_exact_migration(profile: StagingProfile, release_path: Path, *,
                             migration: Mapping[str, str], database: str,
                             isolated: bool, operation: str, policy) -> str:
    """Run one hash-pinned Laravel migration against one fixed-profile database."""
    name = migration.get("name")
    digest = migration.get("sha256")
    _require(isinstance(name, str) and _TRANSACTION_MODULE.MIGRATION_RE.fullmatch(name) is not None
             and _sha(digest, SHA256_RE), "release_migration_identity_invalid")
    _require(operation in {"rehearsal", "target"}, "phase_b_migration_operation_invalid")
    _require(re.fullmatch(r"wpcg_staging_next(?:_(?:restore|rehearsal)_[0-9a-f]{32})?", database)
             is not None, "phase_b_database_name_invalid")
    migration_path = release_path / "database" / "migrations" / f"{name}.php"
    raw = _read_regular_nofollow(migration_path, max_bytes=4_000_000,
                                 expected_uid=profile.expected_owner_uid, expected_mode=0o644)
    _require(hashlib.sha256(raw).hexdigest() == digest, "release_migration_identity_mismatch")
    database_policy = policy.credentials["isolated"] if isolated else policy.credentials["target"]
    host = policy.restore_host if isolated else policy.target_host
    port = policy.restore_port if isolated else policy.target_port
    script = r'''$wpcgOutputLevel=ob_get_level(); ob_start();
try {
$releasePath=getenv("WPCG_RELEASE_PATH");
if (!is_string($releasePath) || $releasePath === "") { throw new \RuntimeException("release_path_unavailable"); }
require $releasePath."/vendor/autoload.php";
$app=require $releasePath."/bootstrap/app.php";
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$config=$app["config"];
$config->set("database.default","mysql");
foreach (["host"=>"WPCG_MYSQL_HOST","port"=>"WPCG_MYSQL_PORT",
          "database"=>"WPCG_MYSQL_DATABASE","username"=>"WPCG_MYSQL_USER",
          "password"=>"WPCG_MYSQL_PASSWORD"] as $key=>$env) {
  $config->set("database.connections.mysql.".$key,getenv($env));
}
$app["db"]->purge("mysql");
$status=Illuminate\Support\Facades\Artisan::call("migrate",[
  "--path"=>getenv("WPCG_MIGRATION_PATH"),"--realpath"=>true,"--force"=>true,
  "--isolated"=>true,"--no-interaction"=>true,"--no-ansi"=>true,
],new \Symfony\Component\Console\Output\NullOutput());
  $response=["exit_code"=>$status];
} catch (\Throwable $error) {
  $sqlstate=null; $mysqlCode=null;
  if ($error instanceof \Illuminate\Database\QueryException && is_array($error->errorInfo)) {
    $sqlstate=is_string($error->errorInfo[0] ?? null) ? $error->errorInfo[0] : null;
    $mysqlCode=is_numeric($error->errorInfo[1] ?? null) ? (string)$error->errorInfo[1] : null;
  }
  $class=get_class($error); $message=strtolower($error->getMessage());
  $failureClass=match ($class) {
    "Illuminate\\Database\\QueryException" => "query_exception",
    "ErrorException" => "php_error_exception",
    "ParseError" => "php_parse_error",
    "Error" => "php_engine_error",
    default => "php_exception",
  };
  $phpCode=match (true) {
    str_contains($message,"failed to open stream") || str_contains($message,"failed opening required") => "file_open_failed",
    str_contains($message,"permission denied") => "permission_denied",
    str_contains($message,"could not find driver") => "database_driver_missing",
    str_contains($message,"undefined function") => "function_missing",
    str_contains($message,"undefined method") => "method_missing",
    (str_contains($message,"class ") && str_contains($message," not found")) => "class_missing",
    str_contains($message,"undefined constant") => "constant_missing",
    str_contains($message,"undefined variable") => "variable_missing",
    str_contains($message,"undefined array key") => "undefined_array_key",
    str_contains($message,"call to a member function") => "invalid_object_state",
    str_contains($message,"syntax error") => "syntax_error",
    str_contains($message,"sqlstate") => "database_exception",
    str_contains($message,"cache") && str_contains($message,"lock") => "cache_lock_failed",
    str_contains($message,"connection") && str_contains($message,"refused") => "database_connection_refused",
    str_contains($message,"no application encryption key") => "application_key_missing",
    str_contains($message,"not configured") => "configuration_missing",
    str_contains($message,"does not exist") => "resource_missing",
    str_contains($message,"could not be found") => "dependency_missing",
    str_contains($message,"could not be resolved") => "dependency_resolution_failed",
    str_contains($message,"connection") || str_contains($message,"database") => "database_related",
    str_contains($message,"container") || str_contains($message,"binding") => "container_related",
    str_contains($message,"cache") => "cache_related",
    str_contains($message,"filesystem") || str_contains($message,"file ") => "filesystem_related",
    default => "unclassified",
  };
  $shortClass=substr($class,strrpos("\\".$class,"\\")+1);
  $classCode=match ($shortClass) {
    "QueryException" => "query_exception",
    "BindingResolutionException" => "container_resolution_exception",
    "LockTimeoutException" => "cache_lock_timeout",
    "PDOException" => "pdo_exception",
    "InvalidArgumentException" => "invalid_argument_exception",
    "RuntimeException" => "runtime_exception",
    "LogicException" => "logic_exception",
    "UnexpectedValueException" => "unexpected_value_exception",
    "Exception" => "generic_exception",
    "TypeError" => "type_error",
    "FileNotFoundException" => "file_not_found_exception",
    "ErrorException" => "php_error_exception",
    "BadMethodCallException" => "bad_method_call_exception",
    "ReflectionException" => "reflection_exception",
    "OutOfBoundsException" => "out_of_bounds_exception",
    "DomainException" => "domain_exception",
    default => "framework_exception",
  };
  if ($error instanceof \PDOException) { $failureClass="pdo_exception"; }
  elseif ($error instanceof \InvalidArgumentException) { $failureClass="invalid_argument_exception"; }
  elseif ($error instanceof \RuntimeException) { $failureClass="runtime_exception"; }
  elseif ($error instanceof \LogicException) { $failureClass="logic_exception"; }
  $errorFile=$error->getFile();
  $errorFileBase=basename($errorFile);
  $phpFileComponent=match ($errorFileBase) {
    "FileStore.php" => "cache_file_store",
    "FileLock.php" => "cache_file_lock",
    "CacheLock.php" => "cache_lock",
    "LockTimeoutException.php" => "cache_lock_timeout",
    "CacheManager.php" => "cache_manager",
    "DatabaseStore.php" => "cache_database_store",
    "ArrayStore.php" => "cache_array_store",
    "Repository.php" => "cache_repository",
    "Migrator.php" => "database_migrator",
    "Command.php" => "console_command",
    "HandleExceptions.php" => "foundation_handle_exceptions",
    "Handler.php" => "foundation_exception_handler",
    "Kernel.php" => "foundation_console_kernel",
    "Application.php" => "foundation_application",
    "LoadConfiguration.php" => "foundation_load_configuration",
    "RegisterProviders.php" => "foundation_register_providers",
    "BootProviders.php" => "foundation_boot_providers",
    default => "other",
  };
  $phpFileClass=str_contains($errorFile,"/database/migrations/") ? "migration" :
    (str_contains($errorFile,"/Illuminate/Database/") ? "database_vendor" :
    (str_contains($errorFile,"/Illuminate/Console/") ? "console_vendor" :
    (str_contains($errorFile,"/Illuminate/Cache/") ? "cache_vendor" :
    (str_contains($errorFile,"/Illuminate/Foundation/") ? "foundation_vendor" :
    (str_contains($errorFile,"/Symfony/") ? "symfony_vendor" :
    (str_contains($errorFile,"/vendor/") ? "vendor" :
    (str_contains($errorFile,"/bootstrap/") ? "bootstrap" : "application")))))));
  $exceptionType=preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/',$shortClass) === 1 ? strtolower(preg_replace('/(?<!^)[A-Z]/','_$0',$shortClass)) : "unknown";
  $errorFileName=preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,95}$/',$errorFileBase) === 1 ? strtolower(str_replace('.php','',$errorFileBase)) : "unknown";
  $response=["exit_code"=>255,"failure_class"=>$failureClass,"exception_class"=>$classCode,
    "exception_type"=>$exceptionType,"php_file_name"=>$errorFileName,"php_code"=>$phpCode,
    "php_file_class"=>$phpFileClass,"php_file_component"=>$phpFileComponent,"php_line"=>is_int($error->getLine()) ? $error->getLine() : null,
    "php_severity"=>$error instanceof \ErrorException ? $error->getSeverity() : null,
    "sqlstate"=>$sqlstate,"mysql_code"=>$mysqlCode];
} finally {
  while (ob_get_level() > $wpcgOutputLevel) { ob_end_clean(); }
}
echo json_encode($response,JSON_UNESCAPED_SLASHES);'''
    env = {
        "WPCG_MYSQL_HOST": host,
        "WPCG_MYSQL_PORT": str(port),
        "WPCG_MYSQL_DATABASE": database,
        "WPCG_MYSQL_USER": database_policy["username"],
        "WPCG_MYSQL_PASSWORD": database_policy["password"],
        "WPCG_MIGRATION_PATH": str(migration_path),
        "WPCG_RELEASE_PATH": str(release_path),
    }
    try:
        output = _run_as_php_fpm(profile, ["/usr/local/bin/php", "-r", script],
                                 cwd=release_path, timeout=900, maximum=16_384,
                                 extra_env=env, classify_failure=True)
        response = _strict_json(output.strip())
    except DeployRefused as error:
        failure = str(error)
        safe_failure = failure if re.fullmatch(r"[a-z0-9_]{1,96}", failure) else "command_failed"
        raise DeployRefused(f"phase_b_migration_{operation}_{name}_{safe_failure}") from None
    except ValueError:
        raise DeployRefused(f"phase_b_migration_{operation}_{name}_response_invalid") from None
    if response.get("exit_code") != 0:
        sqlstate = response.get("sqlstate")
        mysql_code = response.get("mysql_code")
        if (isinstance(sqlstate, str) and re.fullmatch(r"[A-Z0-9]{3,8}", sqlstate)
                and isinstance(mysql_code, str) and re.fullmatch(r"[0-9]{1,5}", mysql_code)):
            failure = f"mysql_errno_{mysql_code}_sqlstate_{sqlstate.lower()}"
        else:
            failure_class = response.get("failure_class")
            failure = failure_class if failure_class in {
                "query_exception", "php_error_exception", "php_parse_error", "php_engine_error",
                "pdo_exception", "invalid_argument_exception", "runtime_exception", "logic_exception",
                "php_exception"} else "migration_failed"
            exception_class = response.get("exception_class")
            if exception_class in {"query_exception", "container_resolution_exception", "cache_lock_timeout",
                                  "pdo_exception", "invalid_argument_exception", "runtime_exception",
                                  "logic_exception", "unexpected_value_exception", "generic_exception",
                                  "type_error", "file_not_found_exception", "php_error_exception",
                                  "bad_method_call_exception", "reflection_exception",
                                  "out_of_bounds_exception", "domain_exception", "framework_exception"}:
                failure = f"{failure}_{exception_class}"
            php_code = response.get("php_code")
            if php_code in {"file_open_failed", "permission_denied", "database_driver_missing",
                            "function_missing", "method_missing", "class_missing", "constant_missing",
                            "variable_missing", "undefined_array_key", "invalid_object_state",
                            "syntax_error", "database_exception", "cache_lock_failed",
                            "database_connection_refused", "application_key_missing",
                            "configuration_missing", "resource_missing", "dependency_missing",
                            "dependency_resolution_failed", "database_related", "container_related",
                            "cache_related", "filesystem_related", "unclassified"}:
                failure = f"{failure}_{php_code}"
            severity = response.get("php_severity")
            if failure_class == "php_error_exception" and type(severity) is int and 1 <= severity <= 8:
                failure = f"{failure}_severity_{severity}"
            php_file_class = response.get("php_file_class")
            if php_file_class in {"migration", "database_vendor", "console_vendor", "cache_vendor",
                                  "foundation_vendor", "symfony_vendor", "vendor", "bootstrap", "application"}:
                failure = f"{failure}_at_{php_file_class}"
            php_file_component = response.get("php_file_component")
            if php_file_component in {"cache_file_store", "cache_file_lock", "cache_lock",
                                      "cache_lock_timeout", "cache_manager", "cache_database_store",
                                      "cache_array_store", "cache_repository", "database_migrator",
                                      "console_command", "foundation_handle_exceptions",
                                      "foundation_exception_handler", "foundation_console_kernel",
                                      "foundation_application", "foundation_load_configuration",
                                      "foundation_register_providers", "foundation_boot_providers", "other"}:
                failure = f"{failure}_in_{php_file_component}"
            exception_type = response.get("exception_type")
            if isinstance(exception_type, str) and re.fullmatch(r"[a-z0-9_]{1,64}", exception_type):
                failure = f"{failure}_type_{exception_type}"
            php_file_name = response.get("php_file_name")
            if isinstance(php_file_name, str) and re.fullmatch(r"[a-z0-9_.-]{1,96}", php_file_name):
                failure = f"{failure}_file_{php_file_name}"
            php_line = response.get("php_line")
            if type(php_line) is int and 1 <= php_line <= 1_000_000:
                failure = f"{failure}_line_{php_line}"
        raise DeployRefused(f"phase_b_migration_{operation}_{name}_{failure}")
    receipt_material = json.dumps({
        "migration": name, "migration_sha256": digest, "database": database,
        "isolated": isolated, "exit_code": 0,
    }, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(receipt_material).hexdigest()


def _load_phase_b_policy(profile: StagingProfile):
    _require(profile.phase_b_policy_file is not None and profile.phase_b_policy_sha256 is not None,
             "phase_b_policy_unavailable")
    test_paths = profile.phase_b_test_only_paths if profile.test_only else None
    try:
        return load_execution_policy(
            profile.phase_b_policy_file, profile.phase_b_policy_sha256,
            expected_target_uuid_sha256=profile.mysql_server_uuid_sha256 or "",
            maintenance_file=profile.shared_storage_dir / "framework" / "down",
            test_only_paths=test_paths,
        )
    except TransactionRefused as error:
        raise DeployRefused(str(error)) from None


def _verify_phase_b_fences(profile: StagingProfile, policy) -> str:
    runner = None
    if profile.test_only and profile.phase_b_test_only_paths is not None:
        candidate = profile.phase_b_test_only_paths.get("command_runner")
        if candidate is not None:
            _require(callable(candidate), "phase_b_test_probe_invalid")
            runner = candidate
    try:
        return verify_background_fences(policy, **({"runner": runner} if runner else {}))
    except TransactionRefused as error:
        raise DeployRefused(str(error)) from None


def _create_maintenance_gate(profile: StagingProfile) -> tuple[Path, int, str]:
    path = profile.shared_storage_dir / "framework" / "down"
    parent = path.parent
    info = _lstat(parent, "phase_b_maintenance_directory_unavailable")
    _require(stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode),
             "phase_b_maintenance_directory_invalid")
    payload = json.dumps({
        "except": [], "redirect": None, "retry": None, "refresh": None,
        "status": 503, "template": None, "secret": None,
    }, sort_keys=True, separators=(",", ":")).encode("utf-8")
    try:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o640)
    except FileExistsError:
        raise DeployRefused("phase_b_maintenance_gate_preexisting") from None
    except OSError:
        raise DeployRefused("phase_b_maintenance_gate_create_failed") from None
    try:
        os.fchown(fd, profile.expected_owner_uid, profile.php_fpm_gid)
        os.fchmod(fd, 0o640)
        with os.fdopen(fd, "wb", closefd=False) as stream:
            stream.write(payload)
            stream.flush()
            os.fsync(fd)
        file_info = os.fstat(fd)
        _require(stat.S_ISREG(file_info.st_mode) and file_info.st_uid == profile.expected_owner_uid
                 and file_info.st_gid == profile.php_fpm_gid
                 and stat.S_IMODE(file_info.st_mode) == 0o640,
                 "phase_b_maintenance_gate_metadata_invalid")
    except BaseException:
        os.close(fd)
        try:
            path.unlink()
        except OSError:
            pass
        raise
    os.close(fd)
    parent_fd = os.open(parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
    try:
        os.fsync(parent_fd)
    finally:
        os.close(parent_fd)
    return path, file_info.st_ino, hashlib.sha256(payload).hexdigest()


def _ensure_maintenance_gate_closed(profile: StagingProfile) -> tuple[Path, int, str]:
    """Return an identity token for a verified closed gate, creating it if absent."""
    try:
        return _create_maintenance_gate(profile)
    except DeployRefused as error:
        if str(error) != "phase_b_maintenance_gate_preexisting":
            raise
    _verify_maintenance_gate_closed(profile)
    path = profile.shared_storage_dir / "framework" / "down"
    info = _lstat(path, "phase_b_maintenance_gate_missing")
    raw = _read_regular_nofollow(path, max_bytes=4096, expected_uid=profile.expected_owner_uid,
                                 expected_mode=0o640)
    return path, info.st_ino, hashlib.sha256(raw).hexdigest()


def _remove_maintenance_gate(profile: StagingProfile, token: tuple[Path, int, str]) -> None:
    path, inode, digest = token
    _require(path == profile.shared_storage_dir / "framework" / "down",
             "phase_b_maintenance_gate_identity_invalid")
    info = _lstat(path, "phase_b_maintenance_gate_missing")
    _require(stat.S_ISREG(info.st_mode) and not stat.S_ISLNK(info.st_mode)
             and info.st_uid == profile.expected_owner_uid and info.st_gid == profile.php_fpm_gid
             and stat.S_IMODE(info.st_mode) == 0o640 and info.st_ino == inode,
             "phase_b_maintenance_gate_metadata_invalid")
    raw = _read_regular_nofollow(path, max_bytes=4096, expected_uid=profile.expected_owner_uid,
                                 expected_mode=0o640)
    _require(hashlib.sha256(raw).hexdigest() == digest, "phase_b_maintenance_gate_content_mismatch")
    path.unlink()
    parent_fd = os.open(path.parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
    try:
        os.fsync(parent_fd)
    finally:
        os.close(parent_fd)


def _verify_maintenance_gate_closed(profile: StagingProfile) -> None:
    path = profile.shared_storage_dir / "framework" / "down"
    info = _lstat(path, "phase_b_maintenance_gate_missing")
    _require(stat.S_ISREG(info.st_mode) and not stat.S_ISLNK(info.st_mode)
             and info.st_uid == profile.expected_owner_uid and info.st_gid == profile.php_fpm_gid
             and stat.S_IMODE(info.st_mode) == 0o640,
             "phase_b_maintenance_gate_metadata_invalid")
    raw = _read_regular_nofollow(path, max_bytes=4096, expected_uid=profile.expected_owner_uid,
                                 expected_mode=0o640)
    try:
        value = _strict_json(raw)
    except ValueError:
        raise DeployRefused("phase_b_maintenance_gate_invalid") from None
    expected = json.dumps({
        "except": [], "redirect": None, "retry": None, "refresh": None,
        "status": 503, "template": None, "secret": None,
    }, sort_keys=True, separators=(",", ":")).encode("utf-8")
    _require(raw == expected and value.get("status") == 503 and value.get("except") == [],
             "phase_b_maintenance_gate_invalid")


def _prepare_phase_b_database(profile: StagingProfile, release_path: Path, *,
                              previous_sha: str, application_sha: str,
                              artifact_sha256: str, migrations: list[dict[str, str]]):
    """Back up, restore and rehearse, resuming only one exact durable transaction."""
    _require(bool(migrations) and migrations == sorted(migrations, key=lambda item: item["name"]),
             "release_migration_manifest_invalid")
    policy = _load_phase_b_policy(profile)
    _verify_phase_b_fences(profile, policy)
    transaction = None
    try:
        target_before = database_fingerprint(policy, database=DATABASE, isolated=False)
        _require(target_before["mysql_uuid_sha256"] == profile.mysql_server_uuid_sha256,
                 "phase_b_target_identity_mismatch")
        migration_names = tuple(item["name"] for item in migrations)
        pending = DatabaseReleaseTransaction.find_unfinished_for_release(
            profile.state_dir, application_sha=application_sha, previous_sha=previous_sha,
            artifact_sha256=artifact_sha256, mysql_uuid_sha256=target_before["mysql_uuid_sha256"],
            migrations=migration_names,
        )
        if pending is None:
            backup_path, backup_sha = create_database_backup(policy, application_sha=application_sha)
            identity = TransactionIdentity(
                application_sha=application_sha, previous_sha=previous_sha,
                artifact_sha256=artifact_sha256, backup_sha256=backup_sha,
                backup_filename=backup_path.name,
                mysql_uuid_sha256=target_before["mysql_uuid_sha256"], migrations=migration_names,
            )
            previous_state = None
        else:
            identity, previous_state = pending
            _require(previous_state.get("state") != "RECOVERY_REQUIRED",
                     "phase_b_manual_recovery_required")
            backup_path = policy.backup_directory / identity.backup_filename
            backup_sha, _backup_size = _verify_backup_sql(
                backup_path, expected_uid=0, expected_mode=0o600)
            _require(backup_sha == identity.backup_sha256,
                     "phase_b_pinned_backup_identity_mismatch")
        transaction = DatabaseReleaseTransaction(profile.state_dir, identity)
        transaction.__enter__()
        state = transaction.state
        _require(state.get("state") != "RECOVERY_REQUIRED", "phase_b_manual_recovery_required")
        _require(state.get("state") in {
            "PREPARED", "BACKUP_VERIFIED", "RESTORE_REHEARSAL_VERIFIED",
            "MIGRATION_REHEARSAL_VERIFIED", "FENCES_CLOSED", "MIGRATING",
            "MIGRATIONS_VERIFIED",
        }, "phase_b_transaction_state_requires_recovery")
        # Rebuild only the two transaction-named schemas on the pinned isolated
        # MySQL instance. This recovers process death during restore or rehearsal.
        cleanup_isolated_databases(policy, transaction.transaction_id)
        restore_name, rehearsal_name = restore_backup_to_isolated(
            policy, backup_path, transaction.transaction_id)
        restored = database_fingerprint(policy, database=restore_name, isolated=True)
        rehearsal_base = database_fingerprint(policy, database=rehearsal_name, isolated=True)
        target_may_have_changed = transaction.state["state"] in {
            "FENCES_CLOSED", "MIGRATING", "MIGRATIONS_VERIFIED",
        }
        if not target_may_have_changed and target_before["schema_sha256"] != restored["schema_sha256"]:
            components = target_before.get("schema_components", {})
            restored_components = restored.get("schema_components", {})
            differing = [name for name in ("tables", "columns", "indexes", "foreign_keys", "triggers")
                         if components.get(name) != restored_components.get(name)]
            suffix = "_".join(differing) if differing else "unknown_component"
            raise DeployRefused(f"phase_b_restore_target_schema_mismatch_{suffix}")
        if not target_may_have_changed:
            _require(target_before["ledger_sha256"] == restored["ledger_sha256"],
                     "phase_b_restore_target_ledger_mismatch")
            _require(target_before["data_sha256"] == restored["data_sha256"],
                     "phase_b_restore_target_data_mismatch")
        _require(restored["schema_sha256"] == rehearsal_base["schema_sha256"],
                 "phase_b_restore_rehearsal_schema_mismatch")
        _require(restored["ledger_sha256"] == rehearsal_base["ledger_sha256"],
                 "phase_b_restore_rehearsal_ledger_mismatch")
        _require(restored["data_sha256"] == rehearsal_base["data_sha256"],
                 "phase_b_restore_rehearsal_data_mismatch")
        if "BACKUP_VERIFIED" in state["evidence"]:
            _require(state["evidence"]["BACKUP_VERIFIED"].get("backup_sha256") == backup_sha,
                     "phase_b_backup_journal_mismatch")
        elif state["state"] == "PREPARED":
            transaction.advance("BACKUP_VERIFIED", {"backup_sha256": backup_sha})
        state = transaction.state
        if "RESTORE_REHEARSAL_VERIFIED" in state["evidence"]:
            checkpoint = state["evidence"]["RESTORE_REHEARSAL_VERIFIED"]
            _require(checkpoint.get("backup_sha256") == backup_sha
                     and checkpoint.get("restored_schema_sha256") == restored["schema_sha256"]
                     and checkpoint.get("restored_ledger_sha256") == restored["ledger_sha256"]
                     and checkpoint.get("restored_data_sha256") == restored["data_sha256"]
                     and checkpoint.get("restore_mysql_uuid_sha256") == restored["mysql_uuid_sha256"]
                     and (target_may_have_changed
                          or (checkpoint.get("target_schema_sha256") == target_before["schema_sha256"]
                              and checkpoint.get("target_ledger_sha256") == target_before["ledger_sha256"]
                              and checkpoint.get("target_data_sha256") == target_before["data_sha256"]))
                     and restore_receipt_sha256({key: checkpoint[key] for key in checkpoint
                                                 if key != "restore_receipt_sha256"})
                     == checkpoint.get("restore_receipt_sha256"),
                     "phase_b_restore_journal_mismatch")
            restore_receipt = checkpoint["restore_receipt_sha256"]
        elif state["state"] == "BACKUP_VERIFIED":
            checkpoint = {
                "backup_sha256": backup_sha,
                "target_schema_sha256": target_before["schema_sha256"],
                "target_ledger_sha256": target_before["ledger_sha256"],
                "target_data_sha256": target_before["data_sha256"],
                "restored_schema_sha256": restored["schema_sha256"],
                "restored_ledger_sha256": restored["ledger_sha256"],
                "restored_data_sha256": restored["data_sha256"],
                "restore_mysql_uuid_sha256": restored["mysql_uuid_sha256"],
            }
            restore_receipt = restore_receipt_sha256(checkpoint)
            checkpoint["restore_receipt_sha256"] = restore_receipt
            transaction.advance("RESTORE_REHEARSAL_VERIFIED", checkpoint)
        else:
            raise DeployRefused("phase_b_restore_checkpoint_missing")
        expected_after: dict[str, dict[str, str]] = {}
        rehearsal_parts = []
        current = rehearsal_base
        for migration in migrations:
            name = migration["name"]
            _require(name not in current["migrations"], "phase_b_migration_already_applied_in_baseline")
            command_receipt = _execute_exact_migration(
                profile, release_path, migration=migration, database=rehearsal_name,
                isolated=True, operation="rehearsal", policy=policy,
            )
            after = database_fingerprint(policy, database=rehearsal_name, isolated=True)
            _require(name in after["migrations"]
                     and len(after["migrations"]) == len(current["migrations"]) + 1,
                     "phase_b_migration_rehearsal_postcondition_failed")
            expected_after[name] = {
                "schema_sha256": after["schema_sha256"],
                "ledger_sha256": after["ledger_sha256"],
                "data_sha256": after["data_sha256"],
            }
            rehearsal_parts.append({"migration": name, "migration_sha256": migration["sha256"],
                                    "command_receipt_sha256": command_receipt,
                                    "schema_sha256": after["schema_sha256"],
                                    "ledger_sha256": after["ledger_sha256"],
                                    "data_sha256": after["data_sha256"]})
            current = after
        rehearsal_receipt = hashlib.sha256(json.dumps({
            "transaction_id": transaction.transaction_id,
            "backup_sha256": backup_sha, "migrations": rehearsal_parts,
        }, sort_keys=True, separators=(",", ":")).encode("utf-8")).hexdigest()
        state = transaction.state
        if "MIGRATION_REHEARSAL_VERIFIED" in state["evidence"]:
            _require(state["evidence"]["MIGRATION_REHEARSAL_VERIFIED"].get(
                "migration_rehearsal_receipt_sha256") == rehearsal_receipt,
                "phase_b_migration_rehearsal_journal_mismatch")
        elif state["state"] == "RESTORE_REHEARSAL_VERIFIED":
            transaction.advance("MIGRATION_REHEARSAL_VERIFIED", {
                "migration_rehearsal_receipt_sha256": rehearsal_receipt,
            })
        state = transaction.state
        if state["state"] == "MIGRATION_REHEARSAL_VERIFIED":
            gate = _ensure_maintenance_gate_closed(profile)
            fences_sha256 = _verify_phase_b_fences(profile, policy)
            current_fence_receipt = hashlib.sha256((fences_sha256 + gate[2]).encode("ascii")).hexdigest()
            transaction.advance("FENCES_CLOSED", {"fence_receipt_sha256": current_fence_receipt})
        elif state["state"] in {"FENCES_CLOSED", "MIGRATING", "MIGRATIONS_VERIFIED"}:
            gate = _ensure_maintenance_gate_closed(profile)
            fences_sha256 = _verify_phase_b_fences(profile, policy)
            current_fence_receipt = hashlib.sha256((fences_sha256 + gate[2]).encode("ascii")).hexdigest()
            recorded = state["evidence"].get("FENCES_CLOSED", {}).get("fence_receipt_sha256")
            _require(recorded == current_fence_receipt, "phase_b_fence_journal_mismatch")
        else:
            gate = None
        return {
            "transaction": transaction, "policy": policy, "backup_path": backup_path,
            "backup_sha256": backup_sha, "restore_name": restore_name,
            "rehearsal_name": rehearsal_name, "expected_after": expected_after,
            "restore_receipt_sha256": restore_receipt,
            "migration_rehearsal_receipt_sha256": rehearsal_receipt,
            "target_before": target_before, "maintenance_gate": gate,
        }
    except DeployRefused:
        if transaction is not None:
            transaction.__exit__(None, None, None)
        raise
    except TransactionRefused as error:
        if transaction is not None:
            transaction.__exit__(None, None, None)
        raise DeployRefused(str(error)) from None
    except Exception:
        if transaction is not None:
            transaction.__exit__(None, None, None)
        raise DeployRefused("phase_b_preparation_failed") from None


def _stop_after_database_mutation(profile: StagingProfile, *, phase_b,
                                  application_sha: str, previous_sha: str | None,
                                  artifact_sha256: str, reason_code: str) -> DeploymentResult:
    """Keep the release fenced after any uncertain target-database mutation."""
    try:
        _ensure_maintenance_gate_closed(profile)
    except Exception:
        return DeploymentResult("FAIL", "phase_b_fence_reassertion_failed", application_sha,
                                previous_sha, artifact_sha256)
    try:
        if phase_b is not None:
            transaction = phase_b["transaction"]
            state = transaction.state["state"]
            if state == "ACCEPTANCE_VERIFIED":
                durable = _successful_receipt(profile, application_sha, artifact_sha256)
                receipt_backed = (durable is not None
                                  and durable[0].get("database_changed") is True
                                  and durable[0].get("database_transaction_id") == transaction.transaction_id)
            else:
                receipt_backed = False
            if state not in {"COMPLETE", "RECOVERY_REQUIRED"} and not receipt_backed:
                transaction.mark_recovery_required(reason_code)
    except Exception:
        return DeploymentResult("FAIL", "phase_b_journal_recovery_mark_failed", application_sha,
                                previous_sha, artifact_sha256)
    return DeploymentResult("HOLD", "phase_b_recovery_required", application_sha,
                            previous_sha, artifact_sha256)


def _verify_generated_bootstrap_caches(profile: StagingProfile, release_path: Path) -> None:
    if not profile.build_application_bootstrap:
        return
    cache = release_path / "bootstrap/cache"
    cache_info = _lstat(cache, "bootstrap_cache_unavailable")
    _require(stat.S_ISDIR(cache_info.st_mode) and cache_info.st_uid == profile.expected_owner_uid
             and stat.S_IMODE(cache_info.st_mode) == 0o755, "bootstrap_cache_metadata_invalid")
    for name in ("packages.php", "services.php"):
        info = _lstat(cache / name, "bootstrap_package_cache_missing")
        _require(stat.S_ISREG(info.st_mode) and info.st_uid == profile.expected_owner_uid
                 and info.st_gid == 0 and stat.S_IMODE(info.st_mode) == 0o644,
                 "bootstrap_package_cache_metadata_invalid")
    config_info = _lstat(cache / "config.php", "bootstrap_config_cache_missing")
    _require(stat.S_ISREG(config_info.st_mode) and config_info.st_uid == profile.expected_owner_uid
             and config_info.st_gid == profile.php_fpm_gid and stat.S_IMODE(config_info.st_mode) == 0o640,
             "bootstrap_config_cache_metadata_invalid")


def _prepare_package_discovery_cache(profile: StagingProfile, release_path: Path) -> None:
    """Build Laravel package manifests before rehearsal, then restore immutable cache metadata."""
    if not profile.build_application_bootstrap:
        return
    php = "/usr/local/bin/php" if profile.test_only else "/usr/bin/php8.5"
    cache = release_path / "bootstrap/cache"
    cache_info = _lstat(cache, "bootstrap_cache_unavailable")
    _require(stat.S_ISDIR(cache_info.st_mode) and cache_info.st_uid == profile.expected_owner_uid
             and stat.S_IMODE(cache_info.st_mode) == 0o755, "bootstrap_cache_metadata_invalid")
    try:
        os.chown(cache, profile.php_fpm_uid, profile.php_fpm_gid)
        os.chmod(cache, 0o2750)
        _run_as_php_fpm(profile, [php, "artisan", "package:discover", "--ansi", "--no-interaction"],
                        cwd=release_path, timeout=60, maximum=8192)
        for name in ("packages.php", "services.php"):
            generated = cache / name
            info = _lstat(generated, "bootstrap_package_cache_missing")
            _require(stat.S_ISREG(info.st_mode) and info.st_uid == profile.php_fpm_uid
                     and info.st_size > 0, "bootstrap_package_cache_metadata_invalid")
            os.chown(generated, profile.expected_owner_uid, 0)
            os.chmod(generated, 0o644)
    except DeployRefused:
        raise
    except OSError:
        raise DeployRefused("bootstrap_package_cache_publish_failed") from None
    finally:
        try:
            os.chown(cache, profile.expected_owner_uid, 0)
            os.chmod(cache, 0o755)
        except OSError:
            raise DeployRefused("bootstrap_package_cache_publish_failed") from None
    for name in ("packages.php", "services.php"):
        info = _lstat(cache / name, "bootstrap_package_cache_missing")
        _require(stat.S_ISREG(info.st_mode) and info.st_uid == profile.expected_owner_uid
                 and info.st_gid == 0 and stat.S_IMODE(info.st_mode) == 0o644,
                 "bootstrap_package_cache_metadata_invalid")


def _prepare_application_bootstrap(profile: StagingProfile, release_path: Path) -> None:
    if not profile.build_application_bootstrap:
        return
    php = "/usr/local/bin/php" if profile.test_only else "/usr/bin/php8.5"
    cache = release_path / "bootstrap/cache"
    cache_info = _lstat(cache, "bootstrap_cache_unavailable")
    _require(stat.S_ISDIR(cache_info.st_mode) and cache_info.st_uid == profile.expected_owner_uid
             and stat.S_IMODE(cache_info.st_mode) == 0o755, "bootstrap_cache_metadata_invalid")
    try:
        os.chown(cache, profile.php_fpm_uid, profile.php_fpm_gid)
        os.chmod(cache, 0o2750)
    except OSError:
        raise DeployRefused("bootstrap_cache_publish_failed") from None
    _run_as_php_fpm(profile, [php, "artisan", "package:discover", "--ansi", "--no-interaction"],
                    cwd=release_path, timeout=60, maximum=8192)
    _run_as_php_fpm(profile, [php, "artisan", "config:cache", "--no-interaction"],
                    cwd=release_path, timeout=60, maximum=8192)
    for name in ("packages.php", "services.php"):
        generated = release_path / "bootstrap/cache" / name
        generated_info = _lstat(generated, "bootstrap_package_cache_missing")
        _require(stat.S_ISREG(generated_info.st_mode)
                 and generated_info.st_uid in {profile.php_fpm_uid, profile.expected_owner_uid}
                 and generated_info.st_size > 0, "bootstrap_package_cache_metadata_invalid")
        try:
            os.chown(generated, profile.expected_owner_uid, 0)
            os.chmod(generated, 0o644)
        except OSError:
            raise DeployRefused("bootstrap_package_cache_publish_failed") from None
    config_cache = release_path / "bootstrap/cache/config.php"
    info = _lstat(config_cache, "bootstrap_config_cache_missing")
    _require(stat.S_ISREG(info.st_mode) and info.st_uid == profile.php_fpm_uid and info.st_size > 0,
             "bootstrap_config_cache_metadata_invalid")
    try:
        os.chown(config_cache, profile.expected_owner_uid, profile.php_fpm_gid)
        os.chmod(config_cache, 0o640)
        os.chown(cache, profile.expected_owner_uid, 0)
        os.chmod(cache, 0o755)
        fd = os.open(config_cache, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    except OSError:
        raise DeployRefused("bootstrap_config_cache_publish_failed") from None
    _verify_generated_bootstrap_caches(profile, release_path)


def _verify_runtime(profile: StagingProfile, active_path: Path) -> Mapping[str, object]:
    if profile.test_only:
        if profile.verify_test_database and os.environ.get("WPCG_PHP_MYSQL_E2E") == "1":
            expected_uuid_hash = profile.mysql_server_uuid_sha256
            _require(_sha(expected_uuid_hash, SHA256_RE), "database_identity_policy_missing")
            probe = (
                "$pdo=new PDO(sprintf('mysql:host=%s;port=3306;dbname=%s;charset=utf8mb4',"
                "getenv('WPCG_MYSQL_HOST'),getenv('WPCG_MYSQL_DATABASE')),getenv('WPCG_MYSQL_USER'),"
                "getenv('WPCG_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>3]);"
                "$r=$pdo->query('SELECT DATABASE() AS db,VERSION() AS version,@@server_uuid AS uuid')->fetch(PDO::FETCH_ASSOC);"
                "echo json_encode(['db'=>$r['db'],'version'=>$r['version'],'uuid_sha256'=>hash('sha256',$r['uuid'])]);"
            )
            env = {key: os.environ.get(key, "") for key in
                   ("WPCG_MYSQL_HOST", "WPCG_MYSQL_DATABASE", "WPCG_MYSQL_USER", "WPCG_MYSQL_PASSWORD")}
            raw = _run_fixed(["/usr/local/bin/php", "-r", probe], cwd=active_path,
                             timeout=10, maximum=2048, extra_env=env)
            try:
                observed = json.loads(raw.decode("utf-8"))
            except (UnicodeError, ValueError):
                raise DeployRefused("database_identity_probe_invalid") from None
            _require(isinstance(observed, dict) and observed.get("db") == DATABASE
                     and isinstance(observed.get("version"), str)
                     and observed["version"].startswith("8.4.")
                     and observed.get("uuid_sha256") == expected_uuid_hash,
                     "database_identity_mismatch")
            return {"php": "8.5-test", "mysql": "8.4", "database": DATABASE}
        return {"php": "8.5-test", "mysql": "8.4-test", "database": DATABASE}
    php_version = _run_fixed(["/usr/bin/php8.5", "-r", "echo PHP_VERSION;"], maximum=128).decode("ascii")
    _require(php_version.startswith("8.5."), "php_runtime_version_mismatch")
    _run_fixed(["/bin/systemctl", "is-active", "--quiet", "php8.5-fpm"])
    _run_fixed(["/usr/sbin/nginx", "-t"], timeout=15)
    php_probe = (
        "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; "
        "$kernel=$app->make('Illuminate\\\\Contracts\\\\Console\\\\Kernel'); $kernel->bootstrap(); "
        "$c=$app['db']->connection(); $r=$c->selectOne('SELECT DATABASE() AS db, VERSION() AS version, @@server_uuid AS uuid'); "
        "echo json_encode(['database'=>$r->db,'version'=>$r->version,'uuid_sha256'=>hash('sha256',$r->uuid)]);"
    )
    raw = _run_as_php_fpm(profile, ["/usr/bin/php8.5", "-r", php_probe],
                          cwd=active_path, timeout=20, maximum=4096)
    try:
        result = json.loads(raw.decode("utf-8"))
    except (UnicodeError, ValueError):
        raise DeployRefused("database_identity_probe_invalid") from None
    version = result.get("version") if isinstance(result, dict) else None
    _require(isinstance(result, dict) and result.get("database") == DATABASE
             and isinstance(version, str) and version.startswith("8.4.")
             and _sha(profile.mysql_server_uuid_sha256, SHA256_RE)
             and result.get("uuid_sha256") == profile.mysql_server_uuid_sha256,
             "database_identity_mismatch")
    return {"php": "8.5", "mysql": ".".join(version.split(".")[:2]), "database": DATABASE}


def _reload_php_fpm(profile: StagingProfile) -> None:
    if not profile.test_only:
        _run_fixed(["/bin/systemctl", "reload", "php8.5-fpm"], timeout=20)


def preflight(profile: StagingProfile, *, invoking_uid: int) -> Mapping[str, object]:
    _safe_profile(profile)
    _require(invoking_uid == profile.allowed_uid, "caller_identity_invalid")
    _verify_directory_chain(profile.incoming_dir.parent, owner_uid=profile.expected_owner_uid, exact_mode=0o755)
    incoming = _lstat(profile.incoming_dir, "incoming_directory_unavailable")
    _require(stat.S_ISDIR(incoming.st_mode) and incoming.st_uid == profile.allowed_uid
             and stat.S_IMODE(incoming.st_mode) == 0o700, "incoming_directory_metadata_invalid")
    for directory in (profile.releases_dir, profile.inbox_dir, profile.state_dir, profile.receipts_dir):
        _verify_directory_chain(directory, owner_uid=profile.expected_owner_uid,
                                exact_mode=0o700 if directory in {profile.state_dir, profile.receipts_dir} else 0o755)
    lock_fd = None
    try:
        lock_path = profile.state_dir / "deploy.lock"
        try:
            lock_fd = os.open(lock_path, os.O_RDWR | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0), 0o600)
        except OSError:
            raise DeployRefused("deployment_lock_unavailable") from None
        lock_info = os.fstat(lock_fd)
        _require(stat.S_ISREG(lock_info.st_mode) and lock_info.st_uid == profile.expected_owner_uid
                 and stat.S_IMODE(lock_info.st_mode) == 0o600, "deployment_lock_metadata_invalid")
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return {"result": "HOLD", "reason": "deployment_lock_busy",
                    "helper_version": HELPER_VERSION, "helper_contract": HELPER_CONTRACT}
        active_sha, active_path, _tree = _current_release(profile)
        active_manifest = _release_manifest(profile, active_path, expected_uid=profile.expected_owner_uid)
        _basic_auth_header(profile)
        artifact_sha = active_manifest.get("artifact_sha256")
        prior_receipt = _successful_receipt(profile, active_sha, artifact_sha)
        if prior_receipt is None:
            reconciled = _reconcile_unreceipted_active_release(
                profile, application_sha=active_sha, active_path=active_path,
                active_manifest=active_manifest, artifact_sha256=artifact_sha,
            )
            if reconciled.status != "PASS":
                return {"result": "HOLD", "reason": reconciled.reason,
                        "application_sha": active_sha, "helper_version": HELPER_VERSION,
                        "helper_contract": HELPER_CONTRACT}
        runtime = _verify_runtime(profile, active_path)
        if prior_receipt is not None:
            _reconcile_database_success_receipt(
                profile, receipt=prior_receipt[0], receipt_sha256=prior_receipt[1],
                application_sha=active_sha, active_path=active_path, active_manifest=active_manifest,
            )
        return {"result": "PASS", "profile": PROFILE, "helper_version": HELPER_VERSION,
                "helper_contract": HELPER_CONTRACT, "helper_artifact_sha256": profile.helper_artifact_sha256,
                "application_sha": active_sha,
                "artifact_sha256": active_manifest.get("artifact_sha256"),
                "runtime": runtime}
    finally:
        if lock_fd is not None:
            try:
                fcntl.flock(lock_fd, fcntl.LOCK_UN)
            finally:
                os.close(lock_fd)


def stage_artifact(profile: StagingProfile, *, invoking_uid: int, application_sha: str,
                   artifact_sha256: str) -> DeploymentResult:
    """Copy one deploy-owned archive into the root-owned immutable inbox, without overwrite."""
    if invoking_uid != profile.allowed_uid:
        return DeploymentResult("HOLD", "caller_identity_invalid", application_sha,
                                artifact_sha256=artifact_sha256)
    if not _sha(application_sha, APPLICATION_SHA_RE) or not _sha(artifact_sha256, SHA256_RE):
        return DeploymentResult("HOLD", "release_identity_invalid", artifact_sha256=artifact_sha256)
    try:
        _safe_profile(profile)
        _verify_directory_chain(profile.inbox_dir, owner_uid=profile.expected_owner_uid, exact_mode=0o755)
        _verify_directory_chain(profile.incoming_dir.parent, owner_uid=profile.expected_owner_uid, exact_mode=0o755)
        incoming_info = _lstat(profile.incoming_dir, "incoming_directory_unavailable")
        _require(stat.S_ISDIR(incoming_info.st_mode) and incoming_info.st_uid == profile.allowed_uid
                 and stat.S_IMODE(incoming_info.st_mode) == 0o700, "incoming_directory_metadata_invalid")
        source = profile.incoming_dir / f"{application_sha}.tar"
        raw = _read_regular_nofollow(source, max_bytes=MAX_ARCHIVE_BYTES,
                                     expected_uid=profile.allowed_uid, expected_mode=0o600)
        _require(hashlib.sha256(raw).hexdigest() == artifact_sha256, "artifact_hash_mismatch")
        destination = profile.inbox_dir / f"{application_sha}.tar"
        if destination.exists() or destination.is_symlink():
            existing = _read_regular_nofollow(destination, max_bytes=MAX_ARCHIVE_BYTES,
                                               expected_uid=profile.expected_owner_uid, expected_mode=0o600)
            _require(hashlib.sha256(existing).hexdigest() == artifact_sha256,
                     "staged_artifact_identity_mismatch")
            return DeploymentResult("PASS", "artifact_already_staged", application_sha,
                                    artifact_sha256=artifact_sha256)
        temporary = profile.inbox_dir / f".stage-{application_sha}-{os.getpid()}-{time.monotonic_ns()}"
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
        try:
            with os.fdopen(fd, "wb", closefd=True) as stream:
                stream.write(raw)
                stream.flush()
                os.fsync(stream.fileno())
            _require(hashlib.sha256(_read_regular_nofollow(
                temporary, max_bytes=MAX_ARCHIVE_BYTES, expected_uid=profile.expected_owner_uid,
                expected_mode=0o600,
            )).hexdigest() == artifact_sha256, "artifact_copy_verification_failed")
            try:
                os.link(temporary, destination, follow_symlinks=False)
            except FileExistsError:
                return DeploymentResult("HOLD", "staged_artifact_already_exists", application_sha,
                                        artifact_sha256=artifact_sha256)
            dir_fd = os.open(profile.inbox_dir, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
            try:
                os.fsync(dir_fd)
            finally:
                os.close(dir_fd)
        finally:
            try:
                temporary.unlink()
            except FileNotFoundError:
                pass
        return DeploymentResult("PASS", "artifact_staged", application_sha,
                                artifact_sha256=artifact_sha256)
    except DeployRefused as error:
        return DeploymentResult("HOLD", str(error), application_sha,
                                artifact_sha256=artifact_sha256)
    except Exception:
        return DeploymentResult("FAIL", "artifact_staging_failed", application_sha,
                                artifact_sha256=artifact_sha256)


def deploy_release(profile: StagingProfile, *, invoking_uid: int, application_sha: str,
                   artifact_sha256: str) -> DeploymentResult:
    """Stage and activate one verified, non-DB release under an exclusive lock."""
    if invoking_uid != profile.allowed_uid:
        return DeploymentResult("HOLD", "caller_identity_invalid", application_sha,
                                artifact_sha256=artifact_sha256)
    if not _sha(application_sha, APPLICATION_SHA_RE) or not _sha(artifact_sha256, SHA256_RE):
        return DeploymentResult("HOLD", "release_identity_invalid", artifact_sha256=artifact_sha256)

    previous_sha = None
    activated = False
    phase_b = None
    maintenance_gate = None
    database_mutation_started = False
    lock_fd = None
    migration_failure_code = None
    observed_statuses: tuple[int, ...] = ()
    unauthenticated_statuses: tuple[int, ...] = ()
    try:
        _safe_profile(profile)
        _verify_directory_chain(profile.incoming_dir.parent, owner_uid=profile.expected_owner_uid, exact_mode=0o755)
        incoming_info = _lstat(profile.incoming_dir, "incoming_directory_unavailable")
        _require(stat.S_ISDIR(incoming_info.st_mode) and incoming_info.st_uid == profile.allowed_uid
                 and stat.S_IMODE(incoming_info.st_mode) == 0o700, "incoming_directory_metadata_invalid")
        for directory in (profile.releases_dir, profile.inbox_dir, profile.state_dir, profile.receipts_dir):
            _verify_directory_chain(directory, owner_uid=profile.expected_owner_uid,
                                    exact_mode=0o700 if directory in {profile.state_dir, profile.receipts_dir} else 0o755)
        lock_path = profile.state_dir / "deploy.lock"
        lock_fd = os.open(lock_path, os.O_RDWR | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0), 0o600)
        lock_info = os.fstat(lock_fd)
        _require(stat.S_ISREG(lock_info.st_mode) and lock_info.st_uid == profile.expected_owner_uid
                 and stat.S_IMODE(lock_info.st_mode) == 0o600, "deployment_lock_metadata_invalid")
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return DeploymentResult("HOLD", "deployment_lock_busy", application_sha,
                                    artifact_sha256=artifact_sha256)

        previous_sha, previous_path, previous_files = _current_release(profile)
        _basic_auth_header(profile)
        if previous_sha != application_sha:
            _verify_runtime(profile, previous_path)
        if previous_sha == application_sha:
            existing = _release_manifest(profile, previous_path, expected_uid=profile.expected_owner_uid)
            if existing.get("artifact_sha256") == artifact_sha256:
                prior_receipt = _successful_receipt(profile, application_sha, artifact_sha256)
                if prior_receipt is None:
                    return _reconcile_unreceipted_active_release(
                        profile, application_sha=application_sha, active_path=previous_path,
                        active_manifest=existing, artifact_sha256=artifact_sha256,
                    )
                _verify_runtime(profile, previous_path)
                healthy, observed_statuses, unauthenticated_statuses = _bounded_acceptance(profile)
                if not healthy:
                    return DeploymentResult("HOLD", "active_release_acceptance_failed", application_sha,
                                            previous_sha, artifact_sha256)
                _reconcile_database_success_receipt(
                    profile, receipt=prior_receipt[0], receipt_sha256=prior_receipt[1],
                    application_sha=application_sha, active_path=previous_path, active_manifest=existing,
                )
                has_prior_acceptance = True
                receipt_result = "NO_OP" if has_prior_acceptance else "PASS"
                receipt_reason = ("exact_release_already_active" if has_prior_acceptance
                                  else "interrupted_activation_reconciled")
                receipt = {
                    "schema": 1, "profile": PROFILE, "result": receipt_result, "reason": receipt_reason,
                    "application_sha": application_sha, "previous_sha": previous_sha,
                    "artifact_sha256": artifact_sha256, "helper_version": HELPER_VERSION,
                    "helper_contract": HELPER_CONTRACT, "database_changed": False,
                    "acceptance": [
                        {"url_id": index, "expected_status": expected, "observed_status": observed}
                        for index, ((_, expected), observed) in enumerate(
                            zip(profile.acceptance_urls, observed_statuses), 1)
                    ],
                    "unauthenticated_protected_acceptance": [
                        {"url_id": index, "expected_status": 401, "observed_status": observed}
                        for index, observed in enumerate(unauthenticated_statuses, 1)
                    ],
                    "active_sha_after_deploy": previous_sha,
                    "completed_at": datetime.now(timezone.utc).isoformat(),
                }
                path, digest = _write_receipt(profile, receipt)
                return DeploymentResult(receipt_result, receipt_reason, application_sha,
                                        previous_sha, artifact_sha256, path, digest)
            return DeploymentResult("HOLD", "active_sha_artifact_mismatch", application_sha,
                                    previous_sha, artifact_sha256)

        inbox_path = profile.inbox_dir / f"{application_sha}.tar"
        archive_bytes = _read_regular_nofollow(inbox_path, max_bytes=MAX_ARCHIVE_BYTES,
                                               expected_uid=profile.expected_owner_uid, expected_mode=0o600)
        _require(hashlib.sha256(archive_bytes).hexdigest() == artifact_sha256, "artifact_hash_mismatch")

        final = profile.releases_dir / application_sha
        if final.exists() or final.is_symlink():
            # A previous process may have published the immutable candidate and
            # died while preparing Laravel caches. Resume only after proving that
            # the existing directory is the exact archive for this base and SHA.
            manifest = _verify_inactive_release_candidate(
                profile, final, application_sha=application_sha, base_sha=previous_sha,
                artifact_sha256=artifact_sha256,
            )
            _prepare_application_bootstrap(profile, final)
            if manifest.get("database_scope") == "migrations":
                migrations = manifest.get("database_migrations")
                _require(isinstance(migrations, list) and migrations,
                         "release_migration_manifest_invalid")
                _require(profile.phase_b_policy_file is not None and profile.phase_b_policy_sha256 is not None,
                         "phase_b_policy_unavailable")
                candidate_links = ()
                try:
                    _verify_candidate_shared_links(profile, final)
                    # _prepare_application_bootstrap above already rebuilt and
                    # verified package discovery caches for this published
                    # release. Re-running the rehearsal-only builder would
                    # require PHP-FPM ownership on files that are deliberately
                    # restored to immutable root ownership after publication.
                    phase_b = _prepare_phase_b_database(
                        profile, final, previous_sha=previous_sha,
                        application_sha=application_sha, artifact_sha256=artifact_sha256,
                        migrations=migrations,
                    )
                except DeployRefused as error:
                    return DeploymentResult("HOLD", str(error), application_sha,
                                            previous_sha, artifact_sha256)
                finally:
                    if candidate_links:
                        _remove_candidate_shared_links(candidate_links)
        else:
            staging_dir = Path(tempfile.mkdtemp(prefix=f".stage-{application_sha}-", dir=profile.releases_dir))
            os.chmod(staging_dir, 0o700)
            try:
                candidate_files, manifest = _extract_archive(
                    archive_bytes, staging_dir, app_sha=application_sha, base_sha=previous_sha,
                    artifact_sha=artifact_sha256, helper_artifact_sha256=profile.helper_artifact_sha256,
                )
                _verify_artifact_matches_primary(profile, application_sha, staging_dir, candidate_files, manifest)
                _require(REQUIRED_RUNTIME_FILES.issubset(candidate_files), "release_runtime_incomplete")
                # Once the archive and primary tree are verified, make the
                # root-owned candidate traversable (never writable) by PHP-FPM
                # so isolated Laravel migration rehearsal can bootstrap it.
                os.chmod(staging_dir, 0o755)
                if not _phase_a_paths_only(previous_files, candidate_files):
                    migrations = manifest.get("database_migrations")
                    if not isinstance(migrations, list) or not migrations:
                        return DeploymentResult("HOLD", "database_scope_not_rehearsed", application_sha,
                                                previous_sha, artifact_sha256)
                    if profile.phase_b_policy_file is None or profile.phase_b_policy_sha256 is None:
                        return DeploymentResult("HOLD", "phase_b_policy_unavailable", application_sha,
                                                previous_sha, artifact_sha256)
                    candidate_links = ()
                    try:
                        candidate_links = _attach_candidate_shared_links(profile, staging_dir)
                        _prepare_package_discovery_cache(profile, staging_dir)
                        phase_b = _prepare_phase_b_database(
                            profile, staging_dir, previous_sha=previous_sha,
                            application_sha=application_sha, artifact_sha256=artifact_sha256,
                            migrations=migrations,
                        )
                    except DeployRefused as error:
                        return DeploymentResult("HOLD", str(error), application_sha,
                                                previous_sha, artifact_sha256)
                    finally:
                        if candidate_links:
                            _remove_candidate_shared_links(candidate_links)
                _verify_shared_inputs(profile)
                release_manifest = dict(manifest)
                release_manifest["artifact_sha256"] = artifact_sha256
                manifest_path = staging_dir / "release-manifest.json"
                fd = os.open(manifest_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL
                             | getattr(os, "O_NOFOLLOW", 0), 0o644)
                raw_manifest = json.dumps(release_manifest, sort_keys=True, separators=(",", ":")).encode("utf-8") + b"\n"
                try:
                    with os.fdopen(fd, "wb", closefd=True) as stream:
                        stream.write(raw_manifest)
                        stream.flush()
                        os.fsync(stream.fileno())
                except OSError:
                    raise DeployRefused("release_manifest_write_failed") from None
                for path in sorted(staging_dir.rglob("*"), reverse=True):
                    if path.is_dir():
                        os.chmod(path, 0o755)
                    else:
                        os.chmod(path, 0o644)
                # Create only these fixed shared-data symlinks after chmod so
                # chmod never follows a link and changes secret/storage metadata.
                os.symlink(str(profile.shared_env_file), staging_dir / ".env")
                os.symlink(str(profile.shared_storage_dir), staging_dir / "storage")
                os.symlink(str(profile.shared_vendor_dir), staging_dir / "vendor")
                os.chmod(staging_dir, 0o755)
                os.rename(staging_dir, final)
                try:
                    parent_fd = os.open(profile.releases_dir, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
                    try:
                        os.fsync(parent_fd)
                    finally:
                        os.close(parent_fd)
                    # Laravel caches contain absolute paths. Build them after the
                    # candidate has its immutable final pathname.
                    _prepare_application_bootstrap(profile, final)
                except Exception:
                    candidate_info = os.lstat(final)
                    _require(stat.S_ISDIR(candidate_info.st_mode)
                             and candidate_info.st_uid == profile.expected_owner_uid
                             and not final.is_symlink(), "failed_candidate_metadata_invalid")
                    shutil.rmtree(final)
                    parent_fd = os.open(profile.releases_dir, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
                    try:
                        os.fsync(parent_fd)
                    finally:
                        os.close(parent_fd)
                    raise
            finally:
                if staging_dir.exists():
                    shutil.rmtree(staging_dir, ignore_errors=True)

        if phase_b is not None:
            tx = phase_b["transaction"]
            policy = phase_b["policy"]
            maintenance_gate = phase_b.get("maintenance_gate")
            if maintenance_gate is None:
                maintenance_gate = _ensure_maintenance_gate_closed(profile)
            fences_sha256 = _verify_phase_b_fences(profile, policy)
            gate_evidence = hashlib.sha256((fences_sha256 + maintenance_gate[2]).encode("ascii")).hexdigest()
            if tx.state["state"] == "MIGRATION_REHEARSAL_VERIFIED":
                tx.advance("FENCES_CLOSED", {"fence_receipt_sha256": gate_evidence})
            else:
                _require(tx.state["evidence"].get("FENCES_CLOSED", {}).get(
                    "fence_receipt_sha256") == gate_evidence, "phase_b_fence_journal_mismatch")

            def observe_target_database():
                snapshot = database_fingerprint(policy, database=DATABASE, isolated=False)
                return {key: snapshot[key] for key in (
                    "schema_sha256", "ledger_sha256", "data_sha256", "migrations")}

            def apply_target_migration(name: str) -> bool:
                nonlocal database_mutation_started, migration_failure_code
                migration = next((item for item in manifest["database_migrations"]
                                  if item["name"] == name), None)
                _require(migration is not None, "phase_b_migration_not_in_manifest")
                database_mutation_started = True
                try:
                    _execute_exact_migration(profile, final, migration=migration, database=DATABASE,
                                             isolated=False, operation="target", policy=policy)
                    return True
                except DeployRefused as error:
                    failure_code = str(error)
                    mysql_failure = re.search(
                        r"mysql_errno_([0-9]{1,5})_sqlstate_([a-z0-9]{3,8})", failure_code)
                    migration_failure_code = (
                        f"mysql_errno_{mysql_failure.group(1)}_sqlstate_{mysql_failure.group(2)}"
                        if mysql_failure else "phase_b_migration_command_failed")
                    return False

            try:
                tx.apply_rehearsed_migrations(
                    expected_after=phase_b["expected_after"], observe=observe_target_database,
                    apply_one=apply_target_migration,
                )
            except TransactionRefused as error:
                # The transaction coordinator resets MIGRATING to FENCES_CLOSED
                # only after independently observing the exact pre-migration
                # schema and ledger hashes. Preserve that explicit retryable
                # checkpoint; never mark it ambiguous or retry in this process.
                tx_state = tx.state
                if (str(error) == "migration_execution_not_verified"
                        and tx_state.get("state") == "FENCES_CLOSED"):
                    _verify_maintenance_gate_closed(profile)
                    observed_fences = _verify_phase_b_fences(profile, policy)
                    gate_info = _lstat(profile.shared_storage_dir / "framework" / "down",
                                       "phase_b_maintenance_gate_missing")
                    gate_raw = _read_regular_nofollow(
                        profile.shared_storage_dir / "framework" / "down", max_bytes=4096,
                        expected_uid=profile.expected_owner_uid, expected_mode=0o640)
                    gate_digest = hashlib.sha256(gate_raw).hexdigest()
                    observed_gate_evidence = hashlib.sha256(
                        (observed_fences + gate_digest).encode("ascii")).hexdigest()
                    recorded_gate_evidence = tx_state["evidence"].get("FENCES_CLOSED", {}).get(
                        "fence_receipt_sha256")
                    _require(recorded_gate_evidence == observed_gate_evidence and gate_info.st_ino > 0,
                             "phase_b_safe_retry_fence_mismatch")
                    reason_code = migration_failure_code or "phase_b_migration_retry_safe"
                    if not re.fullmatch(r"[a-z0-9_]{1,95}", reason_code):
                        reason_code = "phase_b_migration_retry_safe"
                    return DeploymentResult("HOLD", reason_code, application_sha,
                                            previous_sha, artifact_sha256)
                raise DeployRefused(str(error)) from None
            database_mutation_started = True
            final_database_state = observe_target_database()
            _require(tx.state["state"] == "MIGRATIONS_VERIFIED"
                     and all(name in final_database_state["migrations"]
                             for name in tx.identity.migrations),
                     "phase_b_target_migration_state_invalid")
            final_expected = phase_b["expected_after"][tx.identity.migrations[-1]]
            _require(final_database_state["schema_sha256"] == final_expected["schema_sha256"]
                     and final_database_state["ledger_sha256"] == final_expected["ledger_sha256"]
                     and final_database_state["data_sha256"] == final_expected["data_sha256"],
                     "phase_b_target_final_fingerprint_mismatch")
            cleanup_isolated_databases(policy, tx.transaction_id)

        # The atomic exchange may replace the pointer successfully and then
        # fail while fsyncing its parent directory. Treat the operation as
        # potentially activated before entering it so every such failure
        # attempts and verifies restoration of the previous release.
        activated = True
        _atomic_symlink(final, profile.application_link, expected_owner_uid=profile.expected_owner_uid)
        active = Path(os.readlink(profile.application_link))
        _require(active == final, "active_release_readback_mismatch")
        _reload_php_fpm(profile)
        _verify_runtime(profile, final)
        if phase_b is not None:
            tx = phase_b["transaction"]
            activation_sha = hashlib.sha256(json.dumps({
                "active_sha": application_sha, "release_path": str(final),
                "runtime": _verify_runtime(profile, final),
            }, sort_keys=True, separators=(",", ":")).encode("utf-8")).hexdigest()
            tx.advance("RELEASE_ACTIVATED", {"activation_receipt_sha256": activation_sha})
            _remove_maintenance_gate(profile, maintenance_gate)
            maintenance_gate = None
        healthy, observed_statuses, unauthenticated_statuses = _bounded_acceptance(profile)
        if not healthy:
            raise DeployRefused("post_deploy_acceptance_failed")
        if phase_b is not None:
            fences_sha256 = _verify_phase_b_fences(profile, phase_b["policy"])
            acceptance_sha = hashlib.sha256(json.dumps({
                "statuses": observed_statuses,
                "unauthenticated_statuses": unauthenticated_statuses,
                "fences_sha256": fences_sha256,
            }, sort_keys=True, separators=(",", ":")).encode("utf-8")).hexdigest()
            phase_b["transaction"].advance("ACCEPTANCE_VERIFIED", {
                "acceptance_receipt_sha256": acceptance_sha,
            })

        receipt = {
            "schema": 1, "profile": PROFILE, "result": "PASS", "reason": "release_accepted",
            "application_sha": application_sha, "previous_sha": previous_sha,
            "artifact_sha256": artifact_sha256, "files_sha256": manifest["files_sha256"],
            "helper_version": HELPER_VERSION, "helper_contract": HELPER_CONTRACT,
            "database_changed": phase_b is not None, "acceptance": [
                {"url_id": index, "expected_status": expected, "observed_status": observed}
                for index, ((_, expected), observed) in enumerate(zip(profile.acceptance_urls, observed_statuses), 1)
            ], "unauthenticated_protected_acceptance": [
                {"url_id": index, "expected_status": 401, "observed_status": observed}
                for index, observed in enumerate(unauthenticated_statuses, 1)
            ], "active_sha_after_deploy": application_sha,
            "completed_at": datetime.now(timezone.utc).isoformat(),
        }
        if phase_b is not None:
            receipt.update({
                "database_backup_sha256": phase_b["backup_sha256"],
                "database_transaction_id": phase_b["transaction"].transaction_id,
                "restore_receipt_sha256": phase_b["restore_receipt_sha256"],
                "migration_rehearsal_receipt_sha256": phase_b["migration_rehearsal_receipt_sha256"],
                "migration_receipt_sha256": phase_b["transaction"].state["evidence"]["MIGRATIONS_VERIFIED"][
                    "migration_receipt_sha256"],
            })
        path, digest = _write_receipt(profile, receipt)
        if phase_b is not None:
            phase_b["transaction"].advance("COMPLETE", {
                "release_receipt_sha256": digest,
            })
        return DeploymentResult("PASS", "release_accepted", application_sha,
                                previous_sha, artifact_sha256, path, digest)
    except DeployRefused as error:
        if database_mutation_started:
            reason_code = str(error) if re.fullmatch(r"[a-z][a-z0-9_]{2,95}", str(error)) else "deployment_refused"
            return _stop_after_database_mutation(
                profile, phase_b=phase_b, application_sha=application_sha,
                previous_sha=previous_sha, artifact_sha256=artifact_sha256,
                reason_code=reason_code,
            )
        if activated and previous_sha is not None:
            try:
                _atomic_symlink(profile.releases_dir / previous_sha, profile.application_link,
                                expected_owner_uid=profile.expected_owner_uid)
                _reload_php_fpm(profile)
                active_sha, _active_path, _active_files = _current_release(profile)
                _require(active_sha == previous_sha, "rollback_verification_failed")
            except Exception:
                return DeploymentResult("FAIL", "rollback_verification_failed", application_sha,
                                        previous_sha, artifact_sha256)
            receipt = {
                "schema": 1, "profile": PROFILE, "result": "FAIL", "reason": str(error),
                "application_sha": application_sha, "previous_sha": previous_sha,
                "artifact_sha256": artifact_sha256, "helper_version": HELPER_VERSION,
                "helper_contract": HELPER_CONTRACT, "database_changed": False,
                "acceptance": [
                    {"url_id": index, "expected_status": expected, "observed_status": observed}
                    for index, ((_, expected), observed) in enumerate(
                        zip(profile.acceptance_urls, observed_statuses), 1)
                ],
                "unauthenticated_protected_acceptance": [
                    {"url_id": index, "expected_status": 401, "observed_status": observed}
                    for index, observed in enumerate(unauthenticated_statuses, 1)
                ],
                "active_sha_after_recovery": previous_sha,
                "completed_at": datetime.now(timezone.utc).isoformat(),
            }
            try:
                path, digest = _write_receipt(profile, receipt)
            except Exception:
                return DeploymentResult("FAIL", "receipt_write_failed_after_rollback", application_sha,
                                        previous_sha, artifact_sha256)
            return DeploymentResult("FAIL", str(error), application_sha,
                                    previous_sha, artifact_sha256, path, digest)
        return DeploymentResult("HOLD", str(error), application_sha, previous_sha,
                                artifact_sha256)
    except Exception:
        if database_mutation_started:
            return _stop_after_database_mutation(
                profile, phase_b=phase_b, application_sha=application_sha,
                previous_sha=previous_sha, artifact_sha256=artifact_sha256,
                reason_code="backend_internal_error",
            )
        if activated and previous_sha is not None:
            try:
                _atomic_symlink(profile.releases_dir / previous_sha, profile.application_link,
                                expected_owner_uid=profile.expected_owner_uid)
                _reload_php_fpm(profile)
                active_sha, _active_path, _active_files = _current_release(profile)
                _require(active_sha == previous_sha, "rollback_verification_failed")
            except Exception:
                return DeploymentResult("FAIL", "rollback_verification_failed", application_sha,
                                        previous_sha, artifact_sha256)
            receipt = {
                "schema": 1, "profile": PROFILE, "result": "FAIL", "reason": "backend_internal_error",
                "application_sha": application_sha, "previous_sha": previous_sha,
                "artifact_sha256": artifact_sha256, "helper_version": HELPER_VERSION,
                "helper_contract": HELPER_CONTRACT, "database_changed": False,
                "acceptance": [
                    {"url_id": index, "expected_status": expected, "observed_status": observed}
                    for index, ((_, expected), observed) in enumerate(
                        zip(profile.acceptance_urls, observed_statuses), 1)
                ],
                "unauthenticated_protected_acceptance": [
                    {"url_id": index, "expected_status": 401, "observed_status": observed}
                    for index, observed in enumerate(unauthenticated_statuses, 1)
                ],
                "active_sha_after_recovery": previous_sha,
                "completed_at": datetime.now(timezone.utc).isoformat(),
            }
            try:
                path, digest = _write_receipt(profile, receipt)
            except Exception:
                return DeploymentResult("FAIL", "receipt_write_failed_after_rollback", application_sha,
                                        previous_sha, artifact_sha256)
            return DeploymentResult("FAIL", "backend_internal_error", application_sha,
                                    previous_sha, artifact_sha256, path, digest)
        return DeploymentResult("FAIL", "backend_internal_error", application_sha, previous_sha,
                                artifact_sha256)
    finally:
        if phase_b is not None:
            try:
                phase_b["transaction"].__exit__(None, None, None)
            except Exception:
                pass
        if lock_fd is not None:
            try:
                fcntl.flock(lock_fd, fcntl.LOCK_UN)
            finally:
                os.close(lock_fd)
