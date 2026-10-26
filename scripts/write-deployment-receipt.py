#!/usr/bin/env python3
"""Write a bounded, non-secret staging release receipt outside the app tree."""

import argparse
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import tempfile
import zipfile
from datetime import datetime, timezone
from pathlib import Path


APPLICATION = Path("/var/www/wpcaregrid")
APPROVED_PHP_BINARIES = {"/usr/bin/php8.4", "/usr/bin/php8.5"}
RECEIPTS = Path("/home/deploy/private-backups/deployment-receipts")
LOCKFILES = ("composer.lock", "wordpress/composer.lock", "package-lock.json")


class ReceiptFailure(ValueError):
    """A safe, stable receipt failure category without external error text."""

    def __init__(self, code):
        super().__init__(code)
        self.code = code


def validate_php_binary_path(value):
    if value not in APPROVED_PHP_BINARIES:
        raise ValueError("unsupported PHP runtime binary")
    return value


def connector_revision_matches_release_history(application, source_revision, target_sha, mode):
    if not isinstance(source_revision, str) or not re.fullmatch(r"[0-9a-f]{40}", source_revision):
        return False
    git = "/usr/bin/git" if Path("/usr/bin/git").is_file() else shutil.which("git")
    if not git:
        return False
    environment = {"PATH": str(Path(git).parent), "LANG": "C.UTF-8", "LC_ALL": "C.UTF-8"}
    if os.name == "nt" and os.environ.get("SystemRoot"):
        environment["SystemRoot"] = os.environ["SystemRoot"]
    try:
        result = subprocess.run(
            [git, "-C", str(application), "merge-base", "--is-ancestor", source_revision, target_sha],
            check=False, capture_output=True, timeout=5, env=environment,
        )
    except (OSError, subprocess.TimeoutExpired):
        return False
    related = result.returncode == 0
    if not related and (result.returncode != 1 or mode != "rollback"):
        return False
    if not related:
        try:
            reverse = subprocess.run(
                [git, "-C", str(application), "merge-base", "--is-ancestor", target_sha, source_revision],
                check=False, capture_output=True, timeout=5, env=environment,
            )
        except (OSError, subprocess.TimeoutExpired):
            return False
        if reverse.returncode != 0:
            return False
    try:
        source_tree = subprocess.run(
            [git, "-C", str(application), "rev-parse", "--verify", f"{source_revision}:wordpress/wp-caregrid-connector"],
            check=True, capture_output=True, text=True, timeout=5, env=environment,
        ).stdout.strip()
        target_tree = subprocess.run(
            [git, "-C", str(application), "rev-parse", "--verify", f"{target_sha}:wordpress/wp-caregrid-connector"],
            check=True, capture_output=True, text=True, timeout=5, env=environment,
        ).stdout.strip()
    except (OSError, subprocess.SubprocessError):
        return False
    return bool(re.fullmatch(r"[0-9a-f]{40}", source_tree) and source_tree == target_tree)


def verify_connector_artifact(application, package, version, source_revision, source_sha256):
    try:
        with zipfile.ZipFile(package, "r") as archive:
            entries = archive.infolist()
            if len(entries) > 1001 or sum(entry.file_size for entry in entries) > 16 * 1024 * 1024:
                return False
            names = [entry.filename for entry in entries]
            if len(names) != len(set(names)):
                return False
            if any(name.startswith("/") or "\\" in name or ".." in Path(name).parts for name in names):
                return False
            manifest_name = "wp-caregrid-connector/package-manifest.json"
            if names.count(manifest_name) != 1:
                return False
            manifest = json.loads(archive.read(manifest_name))
            if not isinstance(manifest, dict):
                return False
            files = manifest.get("files")
            if (manifest.get("schema") != 1 or manifest.get("version") != version
                    or manifest.get("source_revision") != source_revision
                    or manifest.get("source_sha256") != source_sha256
                    or not isinstance(files, dict) or not files):
                return False
            ordered_files = {}
            for relative in sorted(files):
                expected_hash = files[relative]
                relative_path = Path(relative)
                if (not isinstance(relative, str) or not isinstance(expected_hash, str)
                        or not re.fullmatch(r"[0-9a-f]{64}", expected_hash)
                        or relative_path.is_absolute() or ".." in relative_path.parts
                        or "\\" in relative):
                    return False
                archive_name = f"wp-caregrid-connector/{relative}"
                if names.count(archive_name) != 1:
                    return False
                info = archive.getinfo(archive_name)
                if (info.external_attr >> 16) & 0o170000 == 0o120000:
                    return False
                contents = archive.read(archive_name)
                if hashlib.sha256(contents).hexdigest() != expected_hash:
                    return False
                current = application / "wordpress/wp-caregrid-connector" / relative_path
                if current.is_symlink() or not current.is_file() or sha256_file(current) != expected_hash:
                    return False
                ordered_files[relative] = expected_hash
            expected_names = {manifest_name, *(f"wp-caregrid-connector/{path}" for path in ordered_files)}
            if set(names) != expected_names:
                return False
            source_digest = hashlib.sha256(json.dumps(ordered_files, separators=(",", ":"), ensure_ascii=True).encode("utf-8")).hexdigest()
            if source_digest != source_sha256:
                return False
            current_root = application / "wordpress/wp-caregrid-connector"
            current_names = set()
            for current in current_root.rglob("*"):
                if current.is_symlink():
                    return False
                if current.is_file():
                    relative = current.relative_to(current_root).as_posix()
                    if not re.search(r"(^|/)(?:\.git|tests?|fixtures?)(?:/|$)|(^|/)\.env(?:\.|$)|\.(?:sqlite|sqlite3|log|zip)$", relative, re.IGNORECASE):
                        current_names.add(relative)
            return current_names == set(ordered_files)
    except Exception:
        return False


def sha256_file(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def regular_file(root, relative):
    path = root / relative
    current = root
    if root.is_symlink():
        raise ValueError("application root is a symlink")
    for part in Path(relative).parts:
        current = current / part
        if current.is_symlink():
            raise ValueError("release evidence contains a symlink")
    if not path.is_file():
        raise ValueError("release evidence file is unavailable")
    return path


def receipt_data(application, *, baseline_sha, target_sha, workflow_run_id, mode, git_head, php_version, node_version, composer_version, migrations, health, now=None):
    for value in (baseline_sha, target_sha, git_head):
        if not re.fullmatch(r"[0-9a-f]{40}", value):
            raise ValueError("invalid release SHA")
    if target_sha != git_head or not re.fullmatch(r"[1-9][0-9]*", str(workflow_run_id)):
        raise ValueError("release receipt does not match the runtime source")
    if mode not in {"forward", "rollback"} or migrations != "passed" or health != "passed":
        raise ValueError("release receipt requires successful validated deployment state")
    for value in (php_version, node_version, composer_version):
        if not isinstance(value, str) or not value or len(value) > 120 or any(ord(ch) < 32 for ch in value):
            raise ValueError("invalid toolchain metadata")

    hashes = {}
    for relative in LOCKFILES:
        path = regular_file(application, relative)
        hashes[relative] = sha256_file(path)

    manifest_path = regular_file(application, "public/build/manifest.json")

    release_path = regular_file(application, "wordpress/wp-caregrid-connector/release.json")
    release = json.loads(release_path.read_text(encoding="utf-8"))
    version = release.get("version") if isinstance(release, dict) else None
    if not isinstance(version, str) or not re.fullmatch(r"\d+\.\d+\.\d+", version):
        raise ValueError("Connector version is invalid")
    package = regular_file(application, f"storage/app/private/connector/wp-caregrid-connector-{version}.zip")
    sidecar_path = regular_file(application, f"storage/app/private/connector/wp-caregrid-connector-{version}.zip.manifest.json")
    sidecar = json.loads(sidecar_path.read_text(encoding="utf-8"))
    package_hash = sha256_file(package)
    source_revision = sidecar.get("source_revision") if isinstance(sidecar, dict) else None
    if not isinstance(sidecar, dict) or sidecar.get("version") != version or sidecar.get("package_sha256") != package_hash:
        raise ReceiptFailure("connector_provenance_invalid")
    if not re.fullmatch(r"[0-9a-f]{64}", str(sidecar.get("source_sha256", ""))):
        raise ReceiptFailure("connector_provenance_invalid")
    if (not verify_connector_artifact(application, package, version, source_revision, sidecar["source_sha256"])
            or not connector_revision_matches_release_history(application, source_revision, target_sha, mode)):
        raise ReceiptFailure("connector_provenance_invalid")

    timestamp = (now or datetime.now(timezone.utc)).astimezone(timezone.utc).isoformat().replace("+00:00", "Z")
    return {
        "schema": 1,
        "mode": mode,
        "baseline_sha": baseline_sha,
        "target_sha": target_sha,
        "runtime_head": git_head,
        "workflow_run_id": int(workflow_run_id),
        "mandatory_checks": 7,
        "deployment_time_utc": timestamp,
        "toolchain": {"php": php_version, "node": node_version, "composer": composer_version},
        "lockfile_sha256": hashes,
        "migrations": migrations,
        "connector": {
            "version": version,
            "source_revision": source_revision,
            "source_sha256": sidecar["source_sha256"],
            "package_sha256": package_hash,
        },
        "vite_manifest_sha256": sha256_file(manifest_path),
        "health": health,
    }


def write_receipt(data, directory=RECEIPTS, expected_owner=None, expected_mode=0o700, expected_parent_mode=0o700):
    parent_info = directory.parent.lstat()
    owner = os.geteuid() if expected_owner is None else expected_owner
    if not stat.S_ISDIR(parent_info.st_mode) or stat.S_ISLNK(parent_info.st_mode) or parent_info.st_uid != owner or (expected_parent_mode is not None and stat.S_IMODE(parent_info.st_mode) != expected_parent_mode):
        raise ValueError("unsafe receipt parent directory")
    directory.mkdir(mode=0o700, parents=False, exist_ok=True)
    info = directory.lstat()
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_uid != owner or (expected_mode is not None and stat.S_IMODE(info.st_mode) != expected_mode):
        raise ValueError("unsafe receipt directory")
    name = f"release-{data['deployment_time_utc'].replace(':', '').replace('-', '')}-{data['target_sha']}.json"
    destination = directory / name
    if destination.exists() or destination.is_symlink():
        raise ValueError("receipt already exists")
    payload = (json.dumps(data, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")
    fd, temporary = tempfile.mkstemp(prefix=".receipt-", dir=directory)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "wb", closefd=False) as stream:
            stream.write(payload)
            stream.flush()
            os.fsync(stream.fileno())
        os.close(fd)
        fd = -1
        os.link(temporary, destination, follow_symlinks=False)
        os.unlink(temporary)
        if hasattr(os, "O_DIRECTORY"):
            dir_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | getattr(os, "O_NOFOLLOW", 0))
            try:
                os.fsync(dir_fd)
            finally:
                os.close(dir_fd)
    except BaseException:
        if fd >= 0:
            os.close(fd)
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass
        raise
    return destination


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--baseline", required=True)
    parser.add_argument("--target", required=True)
    parser.add_argument("--workflow-run-id", required=True)
    parser.add_argument("--mode", choices=("forward", "rollback"), required=True)
    parser.add_argument("--php-binary", choices=("/usr/bin/php8.4", "/usr/bin/php8.5"), required=True)
    args = parser.parse_args()
    try:
        git_head = (APPLICATION / ".git").exists()
        if git_head:
            import subprocess

            git_head = subprocess.run(
                ["/usr/bin/git", "-C", str(APPLICATION), "rev-parse", "--verify", "HEAD^{commit}"],
                check=True, capture_output=True, text=True, timeout=5,
                env={"PATH": "/usr/bin:/bin", "LANG": "C.UTF-8"},
            ).stdout.strip()
        else:
            raise ValueError("runtime Git checkout is unavailable")
        import subprocess

        safe_env = {"PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8", "LC_ALL": "C.UTF-8"}
        php_binary = Path(validate_php_binary_path(args.php_binary))
        php_info = php_binary.lstat()
        if not stat.S_ISREG(php_info.st_mode) or php_info.st_uid != 0 or php_info.st_mode & 0o022 or not os.access(php_binary, os.X_OK):
            raise ValueError("selected PHP binary is unavailable")
        php = subprocess.run([str(php_binary), "-r", "echo PHP_VERSION;"], check=True, capture_output=True, text=True, timeout=5, env=safe_env).stdout.strip()
        node = subprocess.run(["/usr/bin/node", "--version"], check=True, capture_output=True, text=True, timeout=5, env=safe_env).stdout.strip()
        import shutil

        composer_path = Path("/usr/bin/composer")
        if not composer_path.is_file() or composer_path.is_symlink() or composer_path.stat().st_uid != 0 or composer_path.stat().st_mode & 0o022:
            raise ValueError("Composer is unavailable")
        composer = subprocess.run([str(php_binary), str(composer_path), "--version", "--no-ansi"], check=True, capture_output=True, text=True, timeout=5, env=safe_env).stdout.splitlines()[0].strip()
        data = receipt_data(
            APPLICATION,
            baseline_sha=args.baseline,
            target_sha=args.target,
            workflow_run_id=args.workflow_run_id,
            mode=args.mode,
            git_head=git_head,
            php_version=php,
            node_version=node,
            composer_version=composer,
            migrations="passed",
            health="passed",
        )
        receipt = write_receipt(data)
    except ReceiptFailure as error:
        print(f"Deployment receipt could not be safely written ({error.code}).", file=__import__("sys").stderr)
        return 1
    except OSError:
        print("Deployment receipt could not be safely written (receipt_storage_failed).", file=__import__("sys").stderr)
        return 1
    except Exception:
        print("Deployment receipt could not be safely written (receipt_validation_failed).", file=__import__("sys").stderr)
        return 1
    print(receipt.name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
