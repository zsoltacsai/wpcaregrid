#!/usr/bin/env python3
"""Staging-next-only, fail-closed immutable release backend (Phase A).

The production profile is intentionally absent. The CLI/helper layer supplies
only the compiled staging-next profile and one exact artifact identity. Tests
may construct a disposable profile, but no CLI flag can select paths or targets.
Database-changing releases are HOLD until the separately reviewed Phase B.
"""

from __future__ import annotations

import fcntl
import base64
import grp
import hashlib
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


HELPER_VERSION = "1.3.2"
HELPER_CONTRACT = "wpcaregrid-staging-next-deploy-v3"
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
                                  "php_minimum", "database_scope", "files_sha256", "helper_version",
                                  "helper_sha256", "helper_contract", "frontend_build"}
        _require(set(manifest) == expected_manifest_keys, "release_manifest_schema_invalid")
        _require(type(manifest["schema"]) is int and manifest["schema"] == 1
                 and manifest["profile"] == PROFILE and manifest["application_sha"] == app_sha
                 and manifest["base_application_sha"] == base_sha and manifest["php_minimum"] == "8.5"
                 and manifest["database_scope"] == "unchanged"
                 and manifest["helper_version"] == HELPER_VERSION
                 and manifest["helper_contract"] == HELPER_CONTRACT
                 and _sha(manifest["helper_sha256"], SHA256_RE)
                 and manifest["helper_sha256"] == helper_artifact_sha256,
                 "release_manifest_identity_mismatch")
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
                "database_scope", "files_sha256", "artifact_sha256", "helper_version",
                "helper_sha256", "helper_contract", "frontend_build"}
    _require(set(manifest) == expected and type(manifest.get("schema")) is int and manifest["schema"] == 1
             and manifest.get("profile") == PROFILE and manifest.get("helper_version") == HELPER_VERSION
             and manifest.get("helper_contract") == HELPER_CONTRACT
             and _sha(manifest.get("helper_sha256"), SHA256_RE)
             and manifest.get("helper_sha256") == profile.helper_artifact_sha256
             and manifest.get("application_sha") == release_dir.name
             and _sha(manifest.get("base_application_sha"), APPLICATION_SHA_RE)
             and manifest.get("php_minimum") == "8.5" and manifest.get("database_scope") == "unchanged"
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


def _hash_release_tree(release_dir: Path, *, expected_uid: int,
                      shared_env_file: Path, shared_storage_dir: Path,
                      shared_vendor_dir: Path) -> dict[str, str]:
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
            _require(stat.S_ISREG(info.st_mode) and info.st_uid == expected_uid,
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
    files = _hash_release_tree(release_dir, expected_uid=profile.expected_owner_uid,
                               shared_env_file=profile.shared_env_file,
                               shared_storage_dir=profile.shared_storage_dir,
                               shared_vendor_dir=profile.shared_vendor_dir)
    _require(manifest.get("files_sha256") == _canonical_files_hash(files),
             "published_candidate_content_mismatch")
    _verify_frontend_build(manifest.get("frontend_build"), release_dir, files)
    _verify_artifact_matches_primary(profile, application_sha, release_dir, files, manifest)
    _verify_shared_inputs(profile)
    return manifest


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


def _successful_receipt_exists(profile: StagingProfile, application_sha: str,
                               artifact_sha256: str) -> bool:
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
        return True
    return False


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
               home: Path | None = None) -> bytes:
    environment = {"PATH": "/usr/bin:/bin", "HOME": "/root", "LANG": "C"}
    if home is not None:
        _require(home.is_absolute() and ".." not in home.parts, "runtime_probe_environment_invalid")
        environment["HOME"] = str(home)
    if extra_env is not None:
        _require(all(key in {"WPCG_MYSQL_HOST", "WPCG_MYSQL_DATABASE", "WPCG_MYSQL_USER", "WPCG_MYSQL_PASSWORD"}
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
    _require(result.returncode == 0, f"runtime_probe_failed_{result.returncode}")
    return result.stdout


def _run_as_php_fpm(profile: StagingProfile, argv: list[str], *, cwd: Path,
                    timeout: float, maximum: int = 4096) -> bytes:
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
        return _run_fixed([*prefix, *argv], cwd=cwd, timeout=timeout, maximum=maximum, home=home)
    _require(os.geteuid() == profile.php_fpm_uid, "runtime_probe_identity_mismatch")
    return _run_fixed(argv, cwd=cwd, timeout=timeout, maximum=maximum, home=home)


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
        _require(stat.S_ISREG(generated_info.st_mode) and generated_info.st_uid == profile.php_fpm_uid
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
    active_sha, _active, _tree = _current_release(profile)
    active_manifest = _release_manifest(profile, _active, expected_uid=profile.expected_owner_uid)
    _basic_auth_header(profile)
    artifact_sha = active_manifest.get("artifact_sha256")
    if not _successful_receipt_exists(profile, active_sha, artifact_sha):
        reconciled = _reconcile_unreceipted_active_release(
            profile, application_sha=active_sha, active_path=_active,
            active_manifest=active_manifest, artifact_sha256=artifact_sha,
        )
        if reconciled.status != "PASS":
            return {"result": "HOLD", "reason": reconciled.reason,
                    "application_sha": active_sha, "helper_version": HELPER_VERSION,
                    "helper_contract": HELPER_CONTRACT}
    runtime = _verify_runtime(profile, _active)
    return {"result": "PASS", "profile": PROFILE, "helper_version": HELPER_VERSION,
            "helper_contract": HELPER_CONTRACT, "helper_artifact_sha256": profile.helper_artifact_sha256,
            "application_sha": active_sha,
            "artifact_sha256": active_manifest.get("artifact_sha256"),
            "runtime": runtime}


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
    lock_fd = None
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
                has_prior_acceptance = _successful_receipt_exists(profile, application_sha, artifact_sha256)
                if not has_prior_acceptance:
                    return _reconcile_unreceipted_active_release(
                        profile, application_sha=application_sha, active_path=previous_path,
                        active_manifest=existing, artifact_sha256=artifact_sha256,
                    )
                _verify_runtime(profile, previous_path)
                healthy, observed_statuses, unauthenticated_statuses = _bounded_acceptance(profile)
                if not healthy:
                    return DeploymentResult("HOLD", "active_release_acceptance_failed", application_sha,
                                            previous_sha, artifact_sha256)
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
                if not _phase_a_paths_only(previous_files, candidate_files):
                    return DeploymentResult("HOLD", "database_change_requires_phase_b", application_sha,
                                            previous_sha, artifact_sha256)
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
        healthy, observed_statuses, unauthenticated_statuses = _bounded_acceptance(profile)
        if not healthy:
            raise DeployRefused("post_deploy_acceptance_failed")

        receipt = {
            "schema": 1, "profile": PROFILE, "result": "PASS", "reason": "release_accepted",
            "application_sha": application_sha, "previous_sha": previous_sha,
            "artifact_sha256": artifact_sha256, "files_sha256": manifest["files_sha256"],
            "helper_version": HELPER_VERSION, "helper_contract": HELPER_CONTRACT,
            "database_changed": False, "acceptance": [
                {"url_id": index, "expected_status": expected, "observed_status": observed}
                for index, ((_, expected), observed) in enumerate(zip(profile.acceptance_urls, observed_statuses), 1)
            ], "unauthenticated_protected_acceptance": [
                {"url_id": index, "expected_status": 401, "observed_status": observed}
                for index, observed in enumerate(unauthenticated_statuses, 1)
            ], "active_sha_after_deploy": application_sha,
            "completed_at": datetime.now(timezone.utc).isoformat(),
        }
        path, digest = _write_receipt(profile, receipt)
        return DeploymentResult("PASS", "release_accepted", application_sha,
                                previous_sha, artifact_sha256, path, digest)
    except DeployRefused as error:
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
        if lock_fd is not None:
            try:
                fcntl.flock(lock_fd, fcntl.LOCK_UN)
            finally:
                os.close(lock_fd)
