#!/usr/bin/python3 -I
"""Install the pinned production helper 2.1.3 without enabling its profile.

Successor installer of ``install_production_helper_2_1_2.py`` (unchanged).
GO-13 model:

* No new users, groups or FPM UID. The existing ``deploy`` account is the
  untrusted, transfer-only identity. Its existing **staging-only** sudo grant
  for ``/usr/local/sbin/wpcaregrid-deploy-priv`` is preserved exactly: this
  installer never edits sudoers, never adds a production sudoers rule, and
  verifies that ``/etc/sudoers``, every ``/etc/sudoers.d`` entry and deploy's
  effective rule listing are byte-identical before and after installation.
* Production operations are run directly from the Owner-controlled root
  console. The installer must itself run as real/effective root, not via sudo,
  from a root-owned copy whose hash the Owner verified against the reviewed
  manifest. It never executes a deploy-owned file.
* Transfer inputs under ``deploy`` control are untrusted: they are read through
  a pinned directory descriptor with ``O_NOFOLLOW``/``O_NONBLOCK``,
  lstat/fstat device/inode/owner/mode/size/link-count checks and unchanged
  mtime/ctime, hashed as read, and copied into a root-owned private inbox
  before anything is published.
* Every helper file and the disabled policy are published root-owned with
  no-overwrite atomic hard-link publication; any failure rolls back exactly the
  inodes this run created. A partial installation is refused, a complete one is
  verified read-only.

The profile stays disabled; enabling it requires a separately approved schema-2
policy whose PHP-FPM confinement binding matches the (currently unset) pin of
``deployment_environment_contract_2_1_3.py``.
"""

from __future__ import annotations

import hashlib
import json
import os
import pwd
import re
import stat
import subprocess
import sys
import tempfile
from dataclasses import dataclass, field, replace
from pathlib import Path
from typing import Callable


VERSION = "2.1.3"
CONTRACT = "wpcaregrid-deployment-profile-v2.2"

ENTRY_SOURCE = "wpcaregrid-deploy-production-priv-2_1_3.py"
BACKEND_SOURCE = "deployment_backend_v2_1_3.py"
TRANSACTION_SOURCE = "deployment_transaction_v2.py"
ENVIRONMENT_SOURCE = "deployment_environment_contract_2_1_3.py"
REVIEW_POLICY_SOURCE = "release_review_policy_v3.py"
CONTROLLER_SOURCE = "production_deploy_controller_2_1_3.py"

ENTRY_SHA256 = "357b0e5715ed86e144a55ac50e0291159116872b266a81f66c5aa3330bbe73c3"
BACKEND_SHA256 = "52a809d42bb2ead54252053ddd48ae8dcbca756132bcc8d29e863b22ac603b37"
TRANSACTION_SHA256 = "ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00"
ENVIRONMENT_SHA256 = "eaf8f73e57f082a32cba1a1d16449f00839f096fb5af9e5baa0bb1e4504772ff"
REVIEW_POLICY_SHA256 = "e50785233d46d7d03e3f734a15fc64764ab85196482789db0c0157cb4307c9db"
HELPER_ARTIFACT_SHA256 = "c2ccbf6119bdbb1ff280bb9daadb38c24660040d61d292055c201ccc2c86b2ac"
CONTROLLER_SHA256 = "a91081dbf87864cc13b546097befbefdc6e34207ebeb74523ab9e68ae275fc1b"

TRANSFER_USER = "deploy"
STAGING_HELPER = "/usr/local/sbin/wpcaregrid-deploy-priv"
PRODUCTION_HELPER_ROOT = "/usr/local/libexec/wpcaregrid-deploy/production"
OPERATIONS = ("authorize", "deploy", "preflight", "stage", "verify-install")
DELEGATION_VARIABLES = frozenset({
    "SUDO_UID", "SUDO_GID", "SUDO_USER", "SUDO_COMMAND", "SUDO_HOME", "PKEXEC_UID", "DOAS_USER",
})
SHA256_RE = re.compile(r"[0-9a-f]{64}\Z")
MAX_SOURCE_BYTES = 2_000_000
MAX_SUDOERS_BYTES = 256_000
MAX_SUDOERS_ENTRIES = 256

# Exact environment schema owned by deployment_environment_contract_2_1_3.py.
# Values stay null until a separately approved policy is installed.
ENVIRONMENT_FIELDS = (
    "app_root", "application_link", "release_root", "incoming_root",
    "inbox_root", "state_root", "backup_root", "shared_env_file", "shared_storage_root", "shared_vendor_root",
    "database", "database_host", "database_port", "database_server_uuid_sha256",
    "isolated_database", "isolated_database_host", "isolated_database_port",
    "isolated_database_uuid_sha256", "host", "mysql_version", "deploy_user", "deploy_uid", "php_version",
    "php_fpm_socket", "php_fpm_unit", "php_fpm_uid", "php_fpm_gid", "worker_programs",
    "scheduler_unit", "maintenance_marker", "worker_fence_marker", "scheduler_fence_marker",
    "privileged_invocation", "php_fpm_confinement",
)
POLICY_KEYS = frozenset({
    "schema", "profile", "enabled", *ENVIRONMENT_FIELDS,
    "entrypoint_sha256", "backend_sha256", "transaction_module_sha256",
    "environment_contract_sha256", "release_review_policy_sha256",
    "helper_artifact_sha256", "phase_b_policy_sha256", "operations",
})

SOURCE_HASHES = {
    ENTRY_SOURCE: ENTRY_SHA256,
    BACKEND_SOURCE: BACKEND_SHA256,
    TRANSACTION_SOURCE: TRANSACTION_SHA256,
    ENVIRONMENT_SOURCE: ENVIRONMENT_SHA256,
    REVIEW_POLICY_SOURCE: REVIEW_POLICY_SHA256,
    CONTROLLER_SOURCE: CONTROLLER_SHA256,
}


@dataclass(frozen=True)
class Layout:
    """Every host path the installer touches. Production uses ``PRODUCTION`` only."""
    root_uid: int
    root_gid: int
    source_dir: Path
    inbox_parent: Path
    helper_parent: Path
    helper_dir: Path
    policy: Path
    sudoers_main: Path
    sudoers_dir: Path
    required_dirs: tuple[Path, ...]
    legacy_paths: tuple[Path, ...]
    phase_b_policy: Path
    database_credentials: Path
    installer_path: Path | None = None
    extra: dict = field(default_factory=dict)

    @property
    def inbox(self) -> Path:
        return self.inbox_parent / VERSION

    @property
    def entrypoint(self) -> Path:
        return self.helper_dir / "wpcaregrid-deploy-production-priv"

    def targets(self) -> tuple[tuple[str, Path, int], ...]:
        """(source name or "policy", installed path, mode) in publication order."""
        return (
            (ENTRY_SOURCE, self.entrypoint, 0o755),
            (BACKEND_SOURCE, self.helper_dir / "deployment_backend_v2_1_3.py", 0o644),
            (TRANSACTION_SOURCE, self.helper_dir / "deployment_transaction_v2.py", 0o644),
            (ENVIRONMENT_SOURCE, self.helper_dir / "deployment_environment_contract_2_1_3.py", 0o644),
            (REVIEW_POLICY_SOURCE, self.helper_dir / "release_review_policy_v3.py", 0o644),
            (CONTROLLER_SOURCE, self.helper_dir / "production_deploy_controller.py", 0o644),
            ("policy", self.policy, 0o644),
        )


def _legacy(version: str) -> tuple[Path, ...]:
    return (
        Path(f"{PRODUCTION_HELPER_ROOT}/{version}"),
        Path(f"/etc/wpcaregrid-deploy/profiles/production-v{version}.json"),
        Path(f"/etc/sudoers.d/wpcaregrid-production-v{version}"),
    )


PRODUCTION = Layout(
    root_uid=0, root_gid=0,
    # Transfer location only; bytes are authenticated by the SHA-256 pins.
    source_dir=Path("/home/deploy/.private/wpcg-production-helper-2.1.3"),
    inbox_parent=Path("/var/lib/wpcaregrid-deploy/production-helper-inbox"),
    helper_parent=Path(PRODUCTION_HELPER_ROOT),
    helper_dir=Path(f"{PRODUCTION_HELPER_ROOT}/{VERSION}"),
    policy=Path("/etc/wpcaregrid-deploy/profiles/production-v2.1.3.json"),
    sudoers_main=Path("/etc/sudoers"),
    sudoers_dir=Path("/etc/sudoers.d"),
    required_dirs=(Path("/usr/local/libexec/wpcaregrid-deploy"), Path("/etc/wpcaregrid-deploy"),
                   Path("/etc/wpcaregrid-deploy/profiles"), Path("/var/lib/wpcaregrid-deploy")),
    legacy_paths=_legacy("2.0.0") + _legacy("2.1.0") + _legacy("2.1.1") + _legacy("2.1.2"),
    # The reused transaction module fixes these Phase B names; both stay absent.
    phase_b_policy=Path("/etc/wpcaregrid-deploy/profiles/production-phase-b-2.0.0.json"),
    database_credentials=Path("/etc/wpcaregrid-deploy/credentials/production-database-2.0.0.json"),
)


class InstallError(Exception):
    """A safe, stable installation refusal code."""


def _fail(code: str):
    raise InstallError(code)


def _sha(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def _mode(info: os.stat_result) -> int:
    return stat.S_IMODE(info.st_mode)


# --------------------------------------------------------------------------
# Identity
# --------------------------------------------------------------------------

def _require_root_console(environ, *, uid: int, euid: int) -> None:
    if DELEGATION_VARIABLES & set(environ):
        _fail("delegated_invocation_refused")
    if uid != 0 or euid != 0:
        _fail("root_required")


def _require_trusted_self(layout: Layout) -> None:
    """The installer runs only from a root-owned, non-writable copy (never deploy's)."""
    path = layout.installer_path
    if path is None:
        _fail("installer_location_unknown")
    cursor = Path("/")
    info = None
    for component in path.parts[1:]:
        cursor /= component
        if layout.root_uid != 0 and not _under(cursor, layout):
            continue  # synthetic layouts check only their own tree
        try:
            info = os.lstat(cursor)
        except OSError:
            _fail("installer_location_untrusted")
        if stat.S_ISLNK(info.st_mode) or info.st_uid != layout.root_uid or _mode(info) & 0o022:
            _fail("installer_location_untrusted")
    if info is None or not stat.S_ISREG(info.st_mode):
        _fail("installer_location_untrusted")


def _transfer_uid(layout: Layout) -> int:
    if layout.root_uid != 0 and "transfer_uid" in layout.extra:
        return layout.extra["transfer_uid"]  # synthetic layouts only
    try:
        uid = pwd.getpwnam(TRANSFER_USER).pw_uid
    except KeyError:
        _fail("transfer_identity_missing")
    if uid <= 0 or uid == layout.root_uid:
        _fail("transfer_identity_invalid")
    return uid


# --------------------------------------------------------------------------
# Directory checks
# --------------------------------------------------------------------------

def _check_directory(path: Path, layout: Layout, *, expected_mode: int | None = None) -> os.stat_result:
    try:
        info = os.lstat(path)
    except OSError:
        _fail("directory_unavailable")
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != layout.root_uid:
        _fail("directory_metadata_invalid")
    if _mode(info) & 0o022:
        _fail("directory_writable_by_group_or_other")
    if expected_mode is not None and _mode(info) != expected_mode:
        _fail("directory_mode_invalid")
    return info


def _check_parent_chain(path: Path, layout: Layout, *, include_self: bool = True) -> None:
    cursor = Path("/")
    parts = path.parts[1:] if include_self else path.parts[1:-1]
    for component in parts:
        cursor /= component
        # Production checks every ancestor; synthetic layouts only their own tree.
        if layout.root_uid == 0 or _under(cursor, layout):
            _check_directory(cursor, layout)


def _under(path: Path, layout: Layout) -> bool:
    base = layout.extra.get("test_root")
    return base is not None and (path == base or base in path.parents)


def _exists(path: Path) -> bool:
    try:
        os.lstat(path)
        return True
    except FileNotFoundError:
        return False
    except OSError:
        _fail("destination_state_unknown")


def _check_absent(path: Path) -> None:
    if _exists(path):
        _fail("destination_already_exists")


def _fsync_directory(path: Path) -> None:
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def _ensure_dir(path: Path, mode: int, layout: Layout, created_dirs: list[tuple]) -> None:
    if _exists(path):
        _check_directory(path, layout, expected_mode=mode)
        return
    try:
        os.mkdir(path, 0o700)
        info = os.lstat(path)
        if not stat.S_ISDIR(info.st_mode):
            _fail("created_directory_type_invalid")
        created_dirs.append((path, info.st_dev, info.st_ino))
        os.chown(path, layout.root_uid, layout.root_gid, follow_symlinks=False)
        os.chmod(path, mode)
        _fsync_directory(path.parent)
    except InstallError:
        raise
    except OSError:
        _fail("directory_create_failed")
    _check_directory(path, layout, expected_mode=mode)


# --------------------------------------------------------------------------
# Untrusted transfer input
# --------------------------------------------------------------------------

def _open_source_directory(layout: Layout, transfer_uid: int) -> int:
    """Pin the deploy-owned transfer directory by descriptor; deploy can rename its parents."""
    try:
        before = os.lstat(layout.source_dir)
    except OSError:
        _fail("source_directory_unavailable")
    if not stat.S_ISDIR(before.st_mode) or before.st_uid != transfer_uid or _mode(before) != 0o700:
        _fail("source_directory_metadata_invalid")
    try:
        fd = os.open(layout.source_dir, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0)
                     | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0))
    except OSError:
        _fail("source_directory_unavailable")
    opened = os.fstat(fd)
    if (opened.st_dev != before.st_dev or opened.st_ino != before.st_ino
            or opened.st_uid != transfer_uid or _mode(opened) != 0o700):
        os.close(fd)
        _fail("source_directory_changed_during_open")
    return fd


def _read_transfer_file(dir_fd: int, name: str, expected_sha: str, *, transfer_uid: int) -> bytes:
    try:
        before = os.stat(name, dir_fd=dir_fd, follow_symlinks=False)
    except OSError:
        _fail("source_unavailable")
    if (not stat.S_ISREG(before.st_mode) or before.st_uid != transfer_uid or _mode(before) != 0o600
            or before.st_nlink != 1 or before.st_size > MAX_SOURCE_BYTES):
        _fail("source_file_metadata_invalid")
    try:
        fd = os.open(name, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
                     | getattr(os, "O_CLOEXEC", 0), dir_fd=dir_fd)
    except OSError:
        _fail("source_changed_during_open")
    try:
        opened = os.fstat(fd)
        if (not stat.S_ISREG(opened.st_mode) or opened.st_dev != before.st_dev
                or opened.st_ino != before.st_ino or opened.st_uid != transfer_uid
                or _mode(opened) != 0o600 or opened.st_size != before.st_size or opened.st_nlink != 1):
            _fail("source_changed_during_open")
        chunks = []
        total = 0
        while True:
            chunk = os.read(fd, 64 * 1024)
            if not chunk:
                break
            total += len(chunk)
            if total > opened.st_size:
                _fail("source_changed_during_read")
            chunks.append(chunk)
        after = os.fstat(fd)
        if (total != opened.st_size or after.st_size != opened.st_size
                or after.st_mtime_ns != opened.st_mtime_ns or after.st_ctime_ns != opened.st_ctime_ns
                or after.st_nlink != 1):
            _fail("source_changed_during_read")
    finally:
        os.close(fd)
    raw = b"".join(chunks)
    if _sha(raw) != expected_sha:
        _fail("source_hash_mismatch")
    return raw


def _read_transfer_sources(layout: Layout, transfer_uid: int) -> dict[str, bytes]:
    dir_fd = _open_source_directory(layout, transfer_uid)
    try:
        names = set(os.listdir(dir_fd))
        if names != set(SOURCE_HASHES):
            _fail("source_directory_contents_invalid")
        return {name: _read_transfer_file(dir_fd, name, expected, transfer_uid=transfer_uid)
                for name, expected in SOURCE_HASHES.items()}
    finally:
        os.close(dir_fd)


# --------------------------------------------------------------------------
# Root-owned files
# --------------------------------------------------------------------------

def _hash_path(path: Path, *, mode: int, layout: Layout, expected_inode: int | None = None,
               expected_device: int | None = None) -> str:
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
                 | getattr(os, "O_CLOEXEC", 0))
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != layout.root_uid or info.st_gid != layout.root_gid
                or _mode(info) != mode or info.st_size > MAX_SOURCE_BYTES or info.st_nlink != 1
                or (expected_inode is not None and info.st_ino != expected_inode)
                or (expected_device is not None and info.st_dev != expected_device)):
            _fail("installed_file_metadata_invalid")
        digest = hashlib.sha256()
        with os.fdopen(fd, "rb", closefd=False) as stream:
            for chunk in iter(lambda: stream.read(64 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()
    finally:
        os.close(fd)


def _read_root_file(path: Path, expected_sha: str, *, mode: int, layout: Layout) -> bytes:
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
                 | getattr(os, "O_CLOEXEC", 0))
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != layout.root_uid or _mode(info) != mode
                or info.st_nlink != 1 or info.st_size > MAX_SOURCE_BYTES):
            _fail("inbox_file_metadata_invalid")
        with os.fdopen(fd, "rb", closefd=False) as stream:
            raw = stream.read(MAX_SOURCE_BYTES + 1)
    finally:
        os.close(fd)
    if _sha(raw) != expected_sha:
        _fail("inbox_file_hash_mismatch")
    return raw


def _publish(path: Path, raw: bytes, mode: int, layout: Layout, created: list[tuple]) -> None:
    """No-overwrite atomic publication: write a private temp, then hard-link it into place."""
    _check_absent(path)
    fd, temp_name = tempfile.mkstemp(prefix=f".{path.name}.tmp.", dir=path.parent)
    temp_path = Path(temp_name)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(raw)
            stream.flush()
            os.fchown(stream.fileno(), layout.root_uid, layout.root_gid)
            os.fchmod(stream.fileno(), mode)
            os.fsync(stream.fileno())
        temp_info = os.lstat(temp_path)
        os.link(temp_path, path, follow_symlinks=False)  # fails if the name now exists
        created.append((path, temp_info.st_dev, temp_info.st_ino, mode, _sha(raw)))
        os.unlink(temp_path)
        if _hash_path(path, mode=mode, layout=layout, expected_inode=temp_info.st_ino,
                      expected_device=temp_info.st_dev) != _sha(raw):
            _fail("published_file_hash_mismatch")
        _fsync_directory(path.parent)
    except InstallError:
        raise
    except FileExistsError:
        _fail("destination_already_exists")
    except OSError:
        _fail("atomic_publish_failed")
    finally:
        try:
            os.unlink(temp_path)
        except FileNotFoundError:
            pass


def _rollback(created_files: list[tuple], created_dirs: list[tuple], layout: Layout) -> bool:
    """Remove only the exact inodes and empty directories this run created."""
    ok = True
    for path, device, inode, mode, digest in reversed(created_files):
        try:
            info = os.lstat(path)
            if (info.st_dev != device or info.st_ino != inode or not stat.S_ISREG(info.st_mode)
                    or _hash_path(path, mode=mode, layout=layout, expected_inode=inode,
                                  expected_device=device) != digest):
                ok = False
                continue
            os.unlink(path)
            _fsync_directory(path.parent)
        except (OSError, InstallError):
            ok = False
    for path, device, inode in reversed(created_dirs):
        try:
            info = os.lstat(path)
            if info.st_dev != device or info.st_ino != inode or not stat.S_ISDIR(info.st_mode) \
                    or os.listdir(path):
                ok = False
                continue
            os.rmdir(path)
            _fsync_directory(path.parent)
        except OSError:
            ok = False
    return ok


def _disabled_policy() -> bytes:
    values: dict[str, object] = {
        "schema": 2,
        "profile": "production",
        "enabled": False,
        **{key: None for key in ENVIRONMENT_FIELDS},
        "entrypoint_sha256": ENTRY_SHA256,
        "backend_sha256": BACKEND_SHA256,
        "transaction_module_sha256": TRANSACTION_SHA256,
        "environment_contract_sha256": ENVIRONMENT_SHA256,
        "release_review_policy_sha256": REVIEW_POLICY_SHA256,
        "helper_artifact_sha256": HELPER_ARTIFACT_SHA256,
        "phase_b_policy_sha256": None,
        "operations": list(OPERATIONS),
    }
    values["privileged_invocation"] = "root-console-direct"
    if set(values) != POLICY_KEYS or values["enabled"] is not False:
        _fail("disabled_policy_schema_invalid")
    return (json.dumps(values, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")


# --------------------------------------------------------------------------
# Sudo invariants (read-only; this installer never writes sudoers)
# --------------------------------------------------------------------------

def _run(argv: list[str], *, runner: Callable[..., subprocess.CompletedProcess], timeout: int = 15,
         maximum: int = 32_000) -> subprocess.CompletedProcess:
    try:
        result = runner(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                        timeout=timeout, check=False, env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C"})
    except (OSError, subprocess.SubprocessError):
        _fail("required_command_unavailable")
    if len(result.stdout) > maximum or len(result.stderr) > maximum:
        _fail("command_output_invalid")
    return result


def _read_sudoers_file(path: Path) -> bytes:
    try:
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
                     | getattr(os, "O_CLOEXEC", 0))
    except OSError:
        _fail("sudoers_unreadable")
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_SUDOERS_BYTES:
            _fail("sudoers_entry_invalid")
        with os.fdopen(fd, "rb", closefd=False) as stream:
            return stream.read(MAX_SUDOERS_BYTES + 1)
    finally:
        os.close(fd)


def _sudoers_snapshot(layout: Layout) -> dict[str, str]:
    """Byte identity of /etc/sudoers and every /etc/sudoers.d entry."""
    snapshot = {str(layout.sudoers_main): _sha(_read_sudoers_file(layout.sudoers_main))}
    try:
        names = sorted(os.listdir(layout.sudoers_dir))
    except OSError:
        _fail("sudoers_directory_unavailable")
    if len(names) > MAX_SUDOERS_ENTRIES:
        _fail("sudoers_directory_crowded")
    for name in names:
        snapshot[str(layout.sudoers_dir / name)] = _sha(_read_sudoers_file(layout.sudoers_dir / name))
    return snapshot


def _sudoers_production_rules(layout: Layout) -> None:
    """No sudoers entry may grant anything on a production helper path."""
    for path in [layout.sudoers_main, *(layout.sudoers_dir / name for name in sorted(os.listdir(layout.sudoers_dir)))]:
        raw = _read_sudoers_file(path)
        if PRODUCTION_HELPER_ROOT.encode() in raw or b"wpcaregrid-deploy-production" in raw:
            _fail("production_sudoers_rule_present")
        if path.name.startswith("wpcaregrid-production"):
            _fail("production_sudoers_rule_present")


def _parse_sudo_list(raw: bytes) -> tuple[str, ...]:
    try:
        text = raw.decode("utf-8")
    except UnicodeError:
        _fail("sudo_list_response_invalid")
    return tuple(line.strip() for line in text.splitlines() if line.strip().startswith("("))


def _deploy_sudo_rules(runner: Callable[..., subprocess.CompletedProcess]) -> tuple[str, ...]:
    """deploy's effective rules: only the existing staging helper grant, never production."""
    result = _run(["/usr/bin/sudo", "-n", "-l", "-U", TRANSFER_USER], runner=runner, maximum=16_000)
    if result.returncode not in {0, 1}:
        _fail("sudo_privilege_baseline_unavailable")
    rules = _parse_sudo_list(result.stdout + b"\n" + result.stderr)
    for rule in rules:
        match = re.fullmatch(r"\(root\) NOPASSWD: (\S+)(?: (.*))?", rule)
        if match is None or match.group(1) != STAGING_HELPER:
            _fail("deploy_sudo_rule_outside_staging_helper")
        if PRODUCTION_HELPER_ROOT in rule or "deploy-production" in rule or "ALL" in (match.group(2) or ""):
            _fail("deploy_sudo_rule_outside_staging_helper")
    return rules


def _verify_existing_sudoers(runner: Callable[..., subprocess.CompletedProcess]) -> None:
    if _run(["/usr/sbin/visudo", "-c"], runner=runner, maximum=4096).returncode != 0:
        _fail("existing_sudoers_invalid")


def _negative_authorization_tests(layout: Layout, runner: Callable[..., subprocess.CompletedProcess]) -> None:
    """deploy can neither sudo nor directly run any production operation."""
    entry = str(layout.entrypoint)
    forbidden = [
        ["/usr/sbin/runuser", "-u", TRANSFER_USER, "--", "/usr/bin/sudo", "-n", entry, "preflight"],
        ["/usr/sbin/runuser", "-u", TRANSFER_USER, "--", "/usr/bin/sudo", "-n", entry, "verify-install"],
        ["/usr/sbin/runuser", "-u", TRANSFER_USER, "--", entry, "preflight"],
    ]
    for argv in forbidden:
        if _run(argv, runner=runner, maximum=4096).returncode == 0:
            _fail("negative_authorization_test_failed")


# --------------------------------------------------------------------------
# Install / verify
# --------------------------------------------------------------------------

def _check_production_conflicts(layout: Layout, runner) -> tuple[dict[str, str], tuple[str, ...]]:
    """Validate only production-specific conflicts; the staging grant is preserved, not judged."""
    for path in layout.legacy_paths:
        if _exists(path):
            _fail("legacy_production_helper_present")
    if _exists(layout.phase_b_policy):
        _fail("phase_b_policy_must_remain_unconfigured")
    if _exists(layout.database_credentials):
        _fail("database_credentials_must_remain_unconfigured")
    _sudoers_production_rules(layout)
    _verify_existing_sudoers(runner)
    return _sudoers_snapshot(layout), _deploy_sudo_rules(runner)


def _state(layout: Layout) -> str:
    present = [_exists(path) for _name, path, _mode_value in layout.targets()]
    inbox = _exists(layout.inbox)
    if not any(present) and not inbox and not _exists(layout.helper_dir):
        return "NONE"
    if all(present) and not inbox:
        return "COMPLETE"
    return "PARTIAL"


def _expected_files(policy_raw: bytes, layout: Layout) -> tuple[tuple[Path, int, str], ...]:
    hashes = dict(SOURCE_HASHES, policy=_sha(policy_raw))
    return tuple((path, mode, hashes[name]) for name, path, mode in layout.targets())


def _readback(files, layout: Layout) -> None:
    for path, mode, digest in files:
        try:
            info = os.lstat(path)
        except OSError:
            _fail("installed_file_unavailable")
        if not stat.S_ISREG(info.st_mode):
            _fail("installed_file_metadata_invalid")
        if _hash_path(path, mode=mode, layout=layout, expected_inode=info.st_ino,
                      expected_device=info.st_dev) != digest:
            _fail("installed_file_hash_mismatch")


def _receipt(result: str, files, snapshot, rules) -> dict[str, object]:
    return {
        "result": result, "helper_version": VERSION, "compatibility_contract": CONTRACT,
        "helper_artifact_sha256": HELPER_ARTIFACT_SHA256, "controller_sha256": CONTROLLER_SHA256,
        "environment_contract_sha256": ENVIRONMENT_SHA256,
        "release_review_policy_sha256": REVIEW_POLICY_SHA256, "release_review_policy": "V3",
        "profile": "production", "profile_enabled": False, "phase_b_policy": "DISABLED",
        "privileged_invocation": "root-console-direct", "production_sudoers": "ABSENT",
        "deploy_sudo_rules_unchanged": list(rules), "sudoers_sha256_unchanged": dict(snapshot),
        "installed_file_sha256": {str(path): digest for path, _mode_value, digest in files},
    }


def verify_existing(layout: Layout = PRODUCTION, *, runner=subprocess.run) -> dict[str, object]:
    """Idempotent read-only verification of a complete installation."""
    for path in layout.required_dirs:
        _check_parent_chain(path, layout)
    _check_directory(layout.helper_parent, layout, expected_mode=0o755)
    _check_directory(layout.helper_dir, layout, expected_mode=0o755)
    try:
        names = set(os.listdir(layout.helper_dir))
    except OSError:
        _fail("installed_helper_directory_unavailable")
    if names != {path.name for name, path, _m in layout.targets() if name != "policy"}:
        _fail("installed_helper_directory_contents_invalid")
    snapshot, rules = _check_production_conflicts(layout, runner)
    files = _expected_files(_disabled_policy(), layout)
    _readback(files, layout)
    return _receipt("NO_OP", files, snapshot, rules)


def install(layout: Layout = PRODUCTION, *, runner=subprocess.run) -> dict[str, object]:
    state = _state(layout)
    if state == "PARTIAL":
        _fail("partial_installation_detected")
    if state == "COMPLETE":
        return verify_existing(layout, runner=runner)

    for path in layout.required_dirs:
        _check_parent_chain(path, layout)
    _check_parent_chain(layout.helper_parent, layout, include_self=False)
    snapshot_before, rules_before = _check_production_conflicts(layout, runner)
    transfer_uid = _transfer_uid(layout)
    policy_raw = _disabled_policy()
    files = _expected_files(policy_raw, layout)

    created_files: list[tuple] = []
    created_dirs: list[tuple] = []
    try:
        # 1. Untrusted transfer bytes -> root-owned private inbox.
        sources = _read_transfer_sources(layout, transfer_uid)
        _ensure_dir(layout.inbox_parent, 0o700, layout, created_dirs)
        _check_absent(layout.inbox)
        _ensure_dir(layout.inbox, 0o700, layout, created_dirs)
        for name, raw in sources.items():
            _publish(layout.inbox / name, raw, 0o600, layout, created_files)
        inbox = {name: _read_root_file(layout.inbox / name, SOURCE_HASHES[name], mode=0o600, layout=layout)
                 for name in SOURCE_HASHES}
        # 2. Root-owned helper and disabled policy, published from inbox bytes only.
        _ensure_dir(layout.helper_parent, 0o755, layout, created_dirs)
        _ensure_dir(layout.helper_dir, 0o755, layout, created_dirs)
        for name, path, mode in layout.targets():
            _publish(path, policy_raw if name == "policy" else inbox[name], mode, layout, created_files)
        _readback(files, layout)
        # 3. deploy can neither sudo nor directly run the helper; then, last, the
        #    staging grant and every sudoers byte are unchanged and no production rule exists.
        _negative_authorization_tests(layout, runner)
        snapshot_after, rules_after = _check_production_conflicts(layout, runner)
        if snapshot_after != snapshot_before:
            _fail("sudoers_changed_during_install")
        if rules_after != rules_before:
            _fail("deploy_sudo_rules_changed_during_install")
        # 4. Remove the private inbox copies and inbox directories (exact inodes) this run created.
        inbox_dirs = {layout.inbox, layout.inbox_parent}
        inbox_entries = [entry for entry in created_files if entry[0].parent == layout.inbox]
        if not _rollback(inbox_entries, [d for d in created_dirs if d[0] in inbox_dirs], layout):
            _fail("inbox_cleanup_failed")
        created_files[:] = [entry for entry in created_files if entry[0].parent != layout.inbox]
        created_dirs[:] = [d for d in created_dirs if d[0] not in inbox_dirs]
        return _receipt("PASS", files, snapshot_before, rules_before)
    except BaseException as error:
        rollback_ok = _rollback(created_files, created_dirs, layout)
        reason = str(error) if isinstance(error, InstallError) else (
            "interrupted" if isinstance(error, (KeyboardInterrupt, SystemExit)) else "internal_error")
        raise InstallError(f"{reason};ROLLBACK={'PASS' if rollback_ok else 'HOLD'}") from None


def main(argv: list[str] | None = None) -> int:
    args = list(sys.argv[1:] if argv is None else argv)
    if args not in ([], ["--verify"]):
        print("INSTALL=HOLD;CODE=arguments_not_allowed")
        return 3
    inherited = dict(os.environ)
    os.environ.clear()
    os.environ.update({"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C"})
    try:
        _require_root_console(inherited, uid=os.getuid(), euid=os.geteuid())
        if sys.flags.isolated != 1:
            _fail("isolated_interpreter_required")
        layout = replace(PRODUCTION, installer_path=Path(os.path.abspath(__file__)))
        _require_trusted_self(layout)
        receipt = verify_existing(layout) if args == ["--verify"] else install(layout)
    except InstallError as error:
        print(f"INSTALL=HOLD;CODE={error}")
        return 3
    print(f"INSTALL={receipt['result']}")
    print("PRODUCTION_PROFILE=DISABLED")
    print("PRODUCTION_SUDOERS=ABSENT")
    print("DEPLOY_STAGING_SUDO=UNCHANGED")
    print(json.dumps(receipt, sort_keys=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
