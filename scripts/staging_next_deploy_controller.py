#!/usr/bin/env python3
"""Single-target staging-next release packager and orchestration controller."""

from __future__ import annotations

import hashlib
import io
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import time
from pathlib import Path


PROFILE = "staging-next"
APPLICATION_DIRECTORY = Path("/var/www/wpcaregrid-staging-new")
DATABASE = "wpcg_staging_next"
HOST = "staging-next.wpcaregrid.com"
PRIMARY_BRANCH = "claude/ecstatic-wozniak-oemyuf"
SOURCE_CHECKOUT = Path("/var/lib/wpcaregrid-deploy/staging-next/source")
INCOMING_DIRECTORY = Path("/var/lib/wpcaregrid-deploy/staging-next/incoming")
HELPER = Path("/usr/local/libexec/wpcaregrid-deploy/staging-next/1.4.6/wpcaregrid-deploy-staging-next-priv")
# Refreshed from the versioned helper manifest by the cross-artifact consistency test.
HELPER_ARTIFACT_SHA256 = "e073a9f421d43911a7b8d148c6ffedf5049f127d5f3682d84292a58872459cc2"
MAX_ARTIFACT_BYTES = 512 * 1024 * 1024
MAX_FILES = 100_000
MAX_UNPACKED_BYTES = 2 * 1024 * 1024 * 1024
DEPLOYMENT_SOURCE_EXCLUDED = ("storage/", "vendor/")
SHA_RE = re.compile(r"[0-9a-f]{40}\Z")
SHA256_RE = re.compile(r"[0-9a-f]{64}\Z")
NODE_VERSION_RE = re.compile(r"v22\.\d+\.\d+\Z")
NPM_VERSION_RE = re.compile(r"10\.\d+\.\d+\Z")


class ControllerHold(Exception):
    def __init__(self, code):
        self.code = code


def _run(argv, *, timeout, maximum=128_000, cwd=None, path="/usr/bin:/bin"):
    try:
        result = subprocess.run(argv, cwd=cwd, env={"PATH": path, "HOME": "/var/lib/wpcaregrid-deploy/staging-next",
                                                    "LANG": "C", "GIT_TERMINAL_PROMPT": "0"},
                                stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                timeout=timeout, check=False)
    except subprocess.TimeoutExpired:
        raise ControllerHold("subprocess_timeout") from None
    except OSError:
        raise ControllerHold("subprocess_unavailable") from None
    if len(result.stdout) > maximum or len(result.stderr) > maximum:
        raise ControllerHold("subprocess_output_invalid")
    if result.returncode != 0:
        raise ControllerHold("subprocess_failed")
    return result.stdout


def _git(args, *, maximum=128_000):
    return _run(["/usr/bin/git", "-C", str(SOURCE_CHECKOUT), *args], timeout=60, maximum=maximum)


def _database_migrations_between(base_sha: str, application_sha: str) -> list[dict[str, str]]:
    """Pin only newly added Laravel migration files; edits/deletions fail closed."""
    if not SHA_RE.fullmatch(base_sha) or not SHA_RE.fullmatch(application_sha):
        raise ControllerHold("migration_release_identity_invalid")
    try:
        raw = _git(["diff", "--name-status", "--no-renames", base_sha, application_sha,
                    "--", "database/migrations"], maximum=256_000)
        lines = raw.decode("utf-8").splitlines()
    except (UnicodeError, ControllerHold):
        raise ControllerHold("migration_diff_unavailable") from None
    result = []
    for line in lines:
        fields = line.split("\t")
        if len(fields) != 2 or fields[0] != "A":
            raise ControllerHold("existing_migration_mutation_rejected")
        name = fields[1]
        if not re.fullmatch(r"database/migrations/[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+\.php", name):
            raise ControllerHold("migration_path_invalid")
        try:
            raw_migration = _git(["show", f"{application_sha}:{name}"], maximum=4_000_000)
        except ControllerHold:
            raise ControllerHold("migration_source_unavailable") from None
        # The deployment manifest and DB journal use Laravel's migration key
        # (the ledger value), which omits the source filename's `.php` suffix.
        result.append({"name": name.rsplit("/", 1)[1][:-4],
                       "sha256": hashlib.sha256(raw_migration).hexdigest()})
    return sorted(result, key=lambda item: item["name"])


def _verify_install_identity():
    if os.geteuid() == 0:
        raise ControllerHold("unprivileged_controller_required")
    uid = _run(["/usr/bin/id", "-u", "deploy-staging-next"], timeout=3, maximum=32).decode("ascii").strip()
    if not uid.isdigit() or int(uid) != os.geteuid():
        raise ControllerHold("controller_identity_invalid")
    for path, final_owner in ((INCOMING_DIRECTORY.parent, 0), (SOURCE_CHECKOUT, os.geteuid())):
        current = Path(path.anchor)
        for component in path.parts[1:]:
            current /= component
            item = os.lstat(current)
            if not stat.S_ISDIR(item.st_mode) or stat.S_ISLNK(item.st_mode):
                raise ControllerHold("deployment_path_component_invalid")
            is_final = current == path
            expected_owner = final_owner if is_final else 0
            if item.st_uid != expected_owner or stat.S_IMODE(item.st_mode) & 0o022:
                raise ControllerHold("deployment_path_component_metadata_invalid")
    info = os.lstat(INCOMING_DIRECTORY)
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_uid != os.geteuid() \
            or stat.S_IMODE(info.st_mode) != 0o700:
        raise ControllerHold("incoming_directory_metadata_invalid")
    source = os.lstat(SOURCE_CHECKOUT)
    if not stat.S_ISDIR(source.st_mode) or stat.S_ISLNK(source.st_mode) or source.st_uid != os.geteuid() \
            or stat.S_IMODE(source.st_mode) & 0o022:
        raise ControllerHold("source_checkout_metadata_invalid")
    origin = _git(["remote", "get-url", "origin"], maximum=4096).decode("utf-8").strip()
    if origin != "https://github.com/zsoltacsai/wpcaregrid.git":
        raise ControllerHold("source_remote_identity_invalid")
    if _git(["status", "--porcelain", "--untracked-files=all"], maximum=4096).strip():
        raise ControllerHold("source_checkout_dirty")
    free = shutil.disk_usage(INCOMING_DIRECTORY).free
    if free < MAX_ARTIFACT_BYTES * 2:
        raise ControllerHold("free_space_below_release_floor")


def _helper(operation, *arguments):
    raw = _run(["/usr/bin/sudo", "-n", str(HELPER), operation, *arguments], timeout=120, maximum=64_000)
    try:
        result = json.loads(raw.decode("utf-8"))
    except (UnicodeError, ValueError):
        raise ControllerHold("helper_response_invalid") from None
    if not isinstance(result, dict) or result.get("helper_version") != "1.4.6" \
            or result.get("helper_contract") != "wpcaregrid-staging-next-deploy-v10" \
            or result.get("helper_artifact_sha256") != HELPER_ARTIFACT_SHA256:
        raise ControllerHold("helper_contract_mismatch")
    if result.get("result") not in {"PASS", "NO_OP", "HOLD", "FAIL"}:
        raise ControllerHold("helper_response_invalid")
    return result


def _latest_primary_sha():
    refs = _run(["/usr/bin/git", "ls-remote", "origin", f"refs/heads/{PRIMARY_BRANCH}"],
                cwd=SOURCE_CHECKOUT, timeout=15, maximum=4096).decode("ascii").splitlines()
    if len(refs) != 1:
        raise ControllerHold("primary_ref_ambiguous")
    fields = refs[0].split("\t")
    if len(fields) != 2 or fields[1] != f"refs/heads/{PRIMARY_BRANCH}" or not SHA_RE.fullmatch(fields[0]):
        raise ControllerHold("primary_ref_invalid")
    return fields[0]


def _package_release(application_sha: str, base_sha: str, *, test_only: bool = False) -> tuple[Path, str]:
    if not SHA_RE.fullmatch(application_sha) or not SHA_RE.fullmatch(base_sha):
        raise ControllerHold("release_identity_invalid")
    nonce = f"{application_sha}-{os.getpid()}-{time.monotonic_ns()}"
    source_tar = INCOMING_DIRECTORY / f".source-{nonce}.tar"
    temporary = INCOMING_DIRECTORY / f".package-{nonce}.tar"
    fd = os.open(source_tar, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        with os.fdopen(fd, "wb", closefd=True) as output:
            try:
                subprocess.run(["/usr/bin/git", "-C", str(SOURCE_CHECKOUT), "archive",
                                "--format=tar", application_sha],
                               stdin=subprocess.DEVNULL, stdout=output, stderr=subprocess.PIPE,
                               timeout=60, check=True,
                               env={"PATH": "/usr/bin:/bin", "HOME": "/var/lib/wpcaregrid-deploy/staging-next",
                                    "LANG": "C", "GIT_TERMINAL_PROMPT": "0"})
            except (OSError, subprocess.TimeoutExpired):
                raise ControllerHold("release_archive_failed") from None
            except subprocess.CalledProcessError:
                raise ControllerHold("release_archive_failed")
            output.flush()
            os.fsync(output.fileno())
        if os.path.getsize(source_tar) > MAX_ARTIFACT_BYTES:
            raise ControllerHold("artifact_size_invalid")

        # Compile production assets from the exact source archive in a private
        # disposable build tree. Generated Vite files are included in the
        # immutable release archive and are separately bound to package-lock.
        build_root = Path(tempfile.mkdtemp(prefix="wpcg-staging-next-build-", dir="/tmp"))
        os.chmod(build_root, 0o700)
        try:
            with tarfile.open(source_tar, mode="r:") as source:
                for member in source.getmembers():
                    if member.isdir():
                        continue
                    if not member.isfile() or member.name.startswith("/") or ".." in Path(member.name).parts:
                        raise ControllerHold("source_archive_member_invalid")
                    stream = source.extractfile(member)
                    if stream is None:
                        raise ControllerHold("source_archive_member_unreadable")
                    target = build_root / member.name
                    target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                    with target.open("xb") as output:
                        shutil.copyfileobj(stream, output, length=1024 * 1024)
            node = shutil.which("node", path="/usr/local/bin:/usr/bin")
            npm = shutil.which("npm", path="/usr/local/bin:/usr/bin")
            if node is None or npm is None:
                raise ControllerHold("frontend_builder_unavailable")
            builder_path = "/usr/local/bin:/usr/bin:/bin"
            node_version = _run([node, "--version"], cwd=build_root, timeout=5, maximum=4096,
                                path=builder_path).decode("ascii").strip()
            npm_version = _run([npm, "--version"], cwd=build_root, timeout=5, maximum=4096,
                               path=builder_path).decode("ascii").strip()
            if not NODE_VERSION_RE.fullmatch(node_version) or not NPM_VERSION_RE.fullmatch(npm_version):
                raise ControllerHold("frontend_builder_version_unsupported")
            builder_prefix = []
            if os.geteuid() == 0:
                runuser = shutil.which("runuser", path="/usr/sbin:/usr/bin")
                if runuser is None or not test_only:
                    raise ControllerHold("frontend_build_requires_unprivileged_identity")
                try:
                    os.chown(build_root, 1000, 1000)
                    for path in build_root.rglob("*"):
                        os.chown(path, 1000, 1000, follow_symlinks=False)
                except OSError:
                    raise ControllerHold("frontend_build_identity_setup_failed") from None
                builder_prefix = [runuser, "-u", "deploy-staging-next", "--"]
            try:
                npm_cache = ("/opt/wpcg-npm-cache" if test_only else str(build_root / ".npm-cache"))
                subprocess.run([*builder_prefix, npm, "ci", "--no-audit", "--no-fund"], cwd=build_root,
                               env={"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": str(build_root / ".home"),
                                    "CI": "1", "LANG": "C", "NPM_CONFIG_CACHE": npm_cache,
                                    **({"npm_config_offline": "true"} if test_only else {})},
                               stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                               timeout=300, check=True)
                subprocess.run([*builder_prefix, npm, "run", "build"], cwd=build_root,
                               env={"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": str(build_root / ".home"),
                                    "CI": "1", "LANG": "C", "NPM_CONFIG_CACHE": npm_cache,
                                    **({"npm_config_offline": "true"} if test_only else {})},
                               stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                               timeout=180, check=True)
            except (OSError, subprocess.TimeoutExpired, subprocess.CalledProcessError):
                raise ControllerHold("frontend_build_failed") from None
            build_dir = build_root / "public" / "build"
            if not build_dir.is_dir() or build_dir.is_symlink():
                raise ControllerHold("frontend_build_output_missing")
            asset_files = {}
            for path in sorted(build_dir.rglob("*")):
                if path.is_symlink():
                    raise ControllerHold("frontend_build_symlink_rejected")
                if path.is_dir():
                    continue
                if not path.is_file():
                    raise ControllerHold("frontend_build_file_type_invalid")
                name = path.relative_to(build_root).as_posix()
                if name in asset_files:
                    raise ControllerHold("frontend_build_path_duplicate")
                asset_files[name] = path.read_bytes()
            if "public/build/manifest.json" not in asset_files or not asset_files:
                raise ControllerHold("frontend_build_manifest_missing")
            package_lock = (build_root / "package-lock.json").read_bytes()
        except ControllerHold:
            raise
        except (OSError, tarfile.TarError):
            raise ControllerHold("frontend_build_source_invalid") from None
        hashes = {}
        unpacked = 0
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
        os.close(fd)
        with tarfile.open(source_tar, mode="r:") as source:
            members = source.getmembers()
            if not members or len(members) > MAX_FILES:
                raise ControllerHold("artifact_file_count_invalid")
            for member in members:
                if member.isdir():
                    continue
                if not member.isfile():
                    raise ControllerHold("artifact_member_type_invalid")
                if member.name == ".env" or member.name.startswith(DEPLOYMENT_SOURCE_EXCLUDED):
                    continue
                if member.name in hashes or member.size < 0:
                    raise ControllerHold("artifact_member_invalid")
                unpacked += member.size
                if unpacked > MAX_UNPACKED_BYTES:
                    raise ControllerHold("artifact_unpacked_size_invalid")
                stream = source.extractfile(member)
                if stream is None:
                    raise ControllerHold("artifact_member_unreadable")
                digest = hashlib.sha256()
                remaining = member.size
                while remaining:
                    chunk = stream.read(min(1024 * 1024, remaining))
                    if not chunk:
                        raise ControllerHold("artifact_member_unreadable")
                    digest.update(chunk)
                    remaining -= len(chunk)
                hashes[member.name] = digest.hexdigest()
        for name, raw in asset_files.items():
            if name in hashes:
                raise ControllerHold("frontend_build_path_conflict")
            hashes[name] = hashlib.sha256(raw).hexdigest()
            unpacked += len(raw)
            if unpacked > MAX_UNPACKED_BYTES:
                raise ControllerHold("artifact_unpacked_size_invalid")
        frontend_build = {
            "node_version": node_version, "npm_version": npm_version,
            "package_lock_sha256": hashlib.sha256(package_lock).hexdigest(),
            "files_sha256": hashlib.sha256("".join(
                f"{name}\0{digest}\n" for name, digest in sorted(hashes.items())
                if name.startswith("public/build/")).encode()).hexdigest(),
        }
        canonical = "".join(f"{name}\0{digest}\n" for name, digest in sorted(hashes.items())).encode()
        database_migrations = _database_migrations_between(base_sha, application_sha)
        manifest = {
            "schema": 1, "profile": PROFILE, "application_sha": application_sha,
            "base_application_sha": base_sha, "php_minimum": "8.5",
            "database_scope": "migrations" if database_migrations else "unchanged",
            "database_migrations": database_migrations,
            "helper_version": "1.4.6", "helper_sha256": HELPER_ARTIFACT_SHA256,
            "helper_contract": "wpcaregrid-staging-next-deploy-v10",
            "frontend_build": frontend_build,
            "files_sha256": hashlib.sha256(canonical).hexdigest(),
        }
        with tarfile.open(source_tar, mode="r:") as source, tarfile.open(temporary, mode="w:") as target:
            for member in source.getmembers():
                if not member.isfile():
                    continue
                stream = source.extractfile(member)
                if stream is None:
                    raise ControllerHold("artifact_member_unreadable")
                normalized = tarfile.TarInfo(member.name)
                normalized.size = member.size
                normalized.mode = 0o644
                normalized.uid = normalized.gid = 0
                normalized.mtime = 0
                target.addfile(normalized, stream)
            for name, raw in sorted(asset_files.items()):
                info = tarfile.TarInfo(name)
                info.size = len(raw)
                info.mode = 0o644
                info.uid = info.gid = 0
                info.mtime = 0
                target.addfile(info, fileobj=io.BytesIO(raw))
            raw_manifest = json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode("utf-8")
            info = tarfile.TarInfo("release-manifest.json")
            info.size = len(raw_manifest)
            info.mode = 0o644
            target.addfile(info, fileobj=io.BytesIO(raw_manifest))
        if os.path.getsize(temporary) > MAX_ARTIFACT_BYTES:
            raise ControllerHold("artifact_size_invalid")
        archive = temporary.read_bytes()
        artifact_sha = hashlib.sha256(archive).hexdigest()
        destination = INCOMING_DIRECTORY / f"{application_sha}.tar"
        try:
            os.link(temporary, destination, follow_symlinks=False)
        except FileExistsError:
            existing = destination.lstat()
            if not stat.S_ISREG(existing.st_mode) or existing.st_uid != os.geteuid() \
                    or stat.S_IMODE(existing.st_mode) != 0o600 \
                    or hashlib.sha256(destination.read_bytes()).hexdigest() != artifact_sha:
                raise ControllerHold("incoming_artifact_conflict") from None
        return destination, artifact_sha
    finally:
        if "build_root" in locals():
            shutil.rmtree(build_root, ignore_errors=True)
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass
        try:
            source_tar.unlink()
        except FileNotFoundError:
            pass


def sync() -> dict[str, object]:
    _verify_install_identity()
    readiness = _helper("preflight")
    if readiness.get("result") != "PASS":
        return {"result": "HOLD", "reason": "helper_preflight_failed"}
    latest_sha = _latest_primary_sha()
    base_sha = readiness.get("application_sha")
    if not isinstance(base_sha, str) or not SHA_RE.fullmatch(base_sha):
        raise ControllerHold("active_release_identity_invalid")
    if latest_sha == base_sha:
        active_artifact_sha = readiness.get("artifact_sha256")
        if not isinstance(active_artifact_sha, str) or not SHA256_RE.fullmatch(active_artifact_sha):
            raise ControllerHold("active_artifact_identity_invalid")
        return _helper("deploy", latest_sha, active_artifact_sha)
    _git(["fetch", "--quiet", "--no-tags", "origin", f"{PRIMARY_BRANCH}"])
    local_sha = _git(["rev-parse", f"refs/remotes/origin/{PRIMARY_BRANCH}"]).decode("ascii").strip()
    if local_sha != latest_sha:
        raise ControllerHold("primary_fetch_identity_mismatch")
    authorization = _helper("authorize", latest_sha)
    if authorization.get("result") != "PASS" or authorization.get("application_sha") != latest_sha:
        return {"result": "HOLD", "reason": "release_not_authorized_for_build",
                "application_sha": latest_sha}
    archive, artifact_sha = _package_release(latest_sha, base_sha)
    staged = _helper("stage", latest_sha, artifact_sha)
    if staged.get("result") != "PASS":
        return staged
    return _helper("deploy", latest_sha, artifact_sha)


def main(argv=None) -> int:
    args = list(sys.argv[1:] if argv is None else argv)
    if args != ["sync"]:
        print(json.dumps({"result": "HOLD", "reason": "operation_not_allowed"}, separators=(",", ":")))
        return 3
    try:
        result = sync()
    except ControllerHold as error:
        result = {"result": "HOLD", "reason": error.code}
    except Exception:
        result = {"result": "FAIL", "reason": "controller_internal_error"}
    print(json.dumps(result, sort_keys=True, separators=(",", ":")))
    return 0 if result.get("result") in {"PASS", "NO_OP"} else 3 if result.get("result") == "HOLD" else 4


if __name__ == "__main__":
    raise SystemExit(main())
