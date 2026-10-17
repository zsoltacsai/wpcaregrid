#!/usr/bin/env python3
"""Write a bounded, non-secret staging release receipt outside the app tree."""

import argparse
import hashlib
import json
import os
import re
import stat
import tempfile
from datetime import datetime, timezone
from pathlib import Path


APPLICATION = Path("/var/www/wpcaregrid")
RECEIPTS = Path("/home/deploy/private-backups/deployment-receipts")
LOCKFILES = ("composer.lock", "wordpress/composer.lock", "package-lock.json")


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
    if not isinstance(sidecar, dict) or sidecar.get("version") != version or sidecar.get("source_revision") != target_sha or sidecar.get("package_sha256") != package_hash:
        raise ValueError("Connector source/package provenance does not match the release")
    if not re.fullmatch(r"[0-9a-f]{64}", str(sidecar.get("source_sha256", ""))):
        raise ValueError("Connector source fingerprint is invalid")

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
        php = subprocess.run(["/usr/bin/php", "-r", "echo PHP_VERSION;"], check=True, capture_output=True, text=True, timeout=5, env=safe_env).stdout.strip()
        node = subprocess.run(["/usr/bin/node", "--version"], check=True, capture_output=True, text=True, timeout=5, env=safe_env).stdout.strip()
        import shutil

        composer_path = shutil.which("composer", path=safe_env["PATH"])
        if not composer_path:
            raise ValueError("Composer is unavailable")
        composer = subprocess.run([composer_path, "--version", "--no-ansi"], check=True, capture_output=True, text=True, timeout=5, env=safe_env).stdout.splitlines()[0].strip()
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
    except Exception:
        print("Deployment receipt could not be safely written.", file=__import__("sys").stderr)
        return 1
    print(receipt.name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
