#!/usr/bin/env python3
"""Receipt provenance, immutable Connector reuse and no-secret fixtures."""

import contextlib
import hashlib
import io
import importlib.util
import json
import os
import shutil
import stat
import subprocess
import tempfile
import zipfile
from types import SimpleNamespace
from unittest import mock
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/write-deployment-receipt.py"
spec = importlib.util.spec_from_file_location("deployment_receipt", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def git(root, *arguments):
    return subprocess.run(["git", "-C", str(root), *arguments], check=True, capture_output=True, text=True).stdout.strip()


def source_inventory(root):
    source = root / "wordpress/wp-caregrid-connector"
    files = {}
    for path in source.rglob("*"):
        if path.is_symlink():
            raise AssertionError("fixture Connector source unexpectedly contains a symlink")
        if not path.is_file():
            continue
        relative = path.relative_to(source).as_posix()
        if (any(part.lower() in {".git", "test", "tests", "fixture", "fixtures"} for part in relative.split("/"))
                or relative.lower().endswith((".sqlite", ".sqlite3", ".log", ".zip"))
                or Path(relative).name.lower().startswith(".env")):
            continue
        files[relative] = hashlib.sha256(path.read_bytes()).hexdigest()
    return dict(sorted(files.items()))


def install_package(root, version, source_revision, *, sidecar_overrides=None):
    files = source_inventory(root)
    source_sha = hashlib.sha256(json.dumps(files, separators=(",", ":"), ensure_ascii=True).encode()).hexdigest()
    package_dir = root / "storage/app/private/connector"
    package_dir.mkdir(parents=True, exist_ok=True)
    package = package_dir / f"wp-caregrid-connector-{version}.zip"
    manifest = {"schema": 1, "version": version, "source_revision": source_revision, "source_sha256": source_sha, "files": files}
    with zipfile.ZipFile(package, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("wp-caregrid-connector/package-manifest.json", json.dumps(manifest, separators=(",", ":")))
        for relative in files:
            archive.write(root / "wordpress/wp-caregrid-connector" / relative, f"wp-caregrid-connector/{relative}")
    sidecar = {
        "schema": 1,
        "version": version,
        "source_revision": source_revision,
        "source_sha256": source_sha,
        "package_sha256": hashlib.sha256(package.read_bytes()).hexdigest(),
    }
    if sidecar_overrides:
        sidecar.update(sidecar_overrides)
    (package_dir / f"wp-caregrid-connector-{version}.zip.manifest.json").write_text(json.dumps(sidecar), encoding="utf-8")
    return package, sidecar


def receipt(root, baseline, target, runtime_head=None, mode="forward"):
    return module.receipt_data(
        root,
        baseline_sha=baseline,
        target_sha=target,
        workflow_run_id="12345",
        mode=mode,
        git_head=runtime_head or target,
        php_version="8.4.26",
        node_version="v22.0.0",
        composer_version="Composer version 2.8.0",
        migrations="passed",
        health="passed",
    )


def expect_receipt_failure(call, reason):
    try:
        call()
    except ValueError as error:
        assert getattr(error, "code", None) == reason, f"unexpected safe failure class: {type(error).__name__}"
    else:
        raise AssertionError(f"receipt accepted invalid fixture: {reason}")


def run_receipt_cli(root, receipt_directory, arguments, php_version, *, binary_state="valid"):
    old_application = module.APPLICATION
    old_receipts = module.RECEIPTS
    old_writer = module.write_receipt
    real_run = subprocess.run
    calls = []
    binary = next(
        (arguments[index + 1] for index, value in enumerate(arguments[:-1]) if value == "--php-binary"),
        module.LEGACY_PHP_BINARY,
    )
    real_lstat = Path.lstat
    real_access = os.access
    real_is_file = Path.is_file
    real_is_symlink = Path.is_symlink
    real_stat = Path.stat

    def fake_lstat(path):
        if path.as_posix() == binary:
            if binary_state == "missing":
                raise FileNotFoundError(binary)
            mode = stat.S_IFREG | (0o666 if binary_state == "writable" else 0o755)
            owner = 1000 if binary_state == "wrong_owner" else 0
            if binary_state == "symlink":
                mode = stat.S_IFLNK | 0o777
            return SimpleNamespace(st_mode=mode, st_uid=owner)
        return real_lstat(path)

    def fake_is_file(path):
        if path.as_posix() == "/usr/bin/composer":
            return True
        return real_is_file(path)

    def fake_is_symlink(path):
        if path.as_posix() == "/usr/bin/composer":
            return False
        return real_is_symlink(path)

    def fake_stat(path, *args, **kwargs):
        if path.as_posix() == "/usr/bin/composer":
            return SimpleNamespace(st_uid=0, st_mode=stat.S_IFREG | 0o755)
        return real_stat(path, *args, **kwargs)

    def fake_access(path, mode, *args, **kwargs):
        if str(path).replace(chr(92), "/") == binary:
            return binary_state != "not_executable"
        return real_access(path, mode, *args, **kwargs)

    def fake_run(command, *args, **kwargs):
        calls.append(list(command))
        if command[0] == "/usr/bin/git" and "HEAD^{commit}" in command:
            head = real_run(
                ["git", "-C", str(root), "rev-parse", "--verify", "HEAD^{commit}"],
                check=True, capture_output=True, text=True,
            ).stdout
            return subprocess.CompletedProcess(command, 0, stdout=head, stderr="")
        if command[0] in module.APPROVED_PHP_BINARIES:
            if command[1] == "-r":
                return subprocess.CompletedProcess(command, 0, stdout=php_version, stderr="")
            if command[1].replace(chr(92), "/") == "/usr/bin/composer":
                return subprocess.CompletedProcess(command, 0, stdout="Composer version 2.8.0\n", stderr="")
        if command[0] == "/usr/bin/node":
            return subprocess.CompletedProcess(command, 0, stdout="v22.0.0\n", stderr="")
        if command[0] == "/usr/bin/git":
            return real_run(["git", *command[1:]], *args, **kwargs)
        return real_run(command, *args, **kwargs)

    def safe_write(data, directory):
        directory.mkdir(parents=True, exist_ok=True)
        destination = directory / Path('receipt-fixture.json')
        destination.write_text(json.dumps(data), encoding='utf-8')
        return destination

    module.APPLICATION = root
    module.RECEIPTS = receipt_directory
    module.write_receipt = safe_write
    stdout = io.StringIO()
    stderr = io.StringIO()
    try:
        with mock.patch.object(Path, "lstat", fake_lstat):
            with mock.patch.object(os, "access", fake_access):
                with mock.patch.object(Path, "is_file", fake_is_file):
                    with mock.patch.object(Path, "is_symlink", fake_is_symlink):
                        with mock.patch.object(Path, "stat", fake_stat):
                            with mock.patch.object(subprocess, "run", fake_run):
                                with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                                    result = module.main(arguments)
    finally:
        module.APPLICATION = old_application
        module.RECEIPTS = old_receipts
        module.write_receipt = old_writer
    return result, stdout.getvalue(), stderr.getvalue(), calls


def test_cli_runtime_transition(root, temporary, baseline, target, version):
    legacy_args = [
        "--baseline", baseline, "--target", target,
        "--workflow-run-id", "12345", "--mode", "forward",
    ]
    # Exact deployed legacy call shape selects only fixed PHP 8.4.
    legacy_receipts = Path(temporary) / "legacy-receipts"
    result, stdout, stderr, calls = run_receipt_cli(root, legacy_receipts, legacy_args, "8.4.27")
    assert result == 0 and stdout.strip() and not stderr, (result, stdout, stderr, calls)
    legacy_receipt = legacy_receipts / "receipt-fixture.json"
    assert json.loads(legacy_receipt.read_text(encoding="utf-8"))["toolchain"]["php"] == "8.4.27"
    assert any(call[0] == "/usr/bin/php8.4" and call[1].replace(chr(92), "/") == "/usr/bin/composer" for call in calls)
    assert not any(call and call[0] == "/usr/bin/php" for call in calls)

    # Modern callers use the explicit allowlisted interpreter for PHP and Composer.
    for binary, php_version in (("/usr/bin/php8.4", "8.4.27"), ("/usr/bin/php8.5", "8.5.12")):
        receipts = Path(temporary) / ("modern-" + binary.rsplit(".", 1)[1])
        result, stdout, stderr, calls = run_receipt_cli(
            root, receipts, legacy_args + ["--php-binary", binary], php_version,
        )
        assert result == 0 and stdout.strip() and not stderr
        assert any(call[0] == binary and call[1].replace(chr(92), "/") == "/usr/bin/composer" for call in calls)
        modern_receipt = json.loads((receipts / "receipt-fixture.json").read_text(encoding="utf-8"))
        assert modern_receipt["toolchain"]["php"] == php_version

    # Legacy rollback remains valid and explicitly records rollback mode.
    git(root, "checkout", "--detach", baseline)
    rollback_receipts = Path(temporary) / "legacy-rollback-receipts"
    rollback_args = [
        "--baseline", target, "--target", baseline,
        "--workflow-run-id", "12346", "--mode", "rollback",
    ]
    result, stdout, stderr, calls = run_receipt_cli(root, rollback_receipts, rollback_args, "8.4.27")
    assert result == 0 and stdout.strip() and not stderr
    receipt_path = rollback_receipts / "receipt-fixture.json"
    assert json.loads(receipt_path.read_text(encoding="utf-8"))["mode"] == "rollback"
    git(root, "checkout", "--detach", target)

    rejected = [
        (["--php-binary", "/usr/bin/php"], "8.4.27", "valid", "receipt_validation_failed"),
        (["--php-binary", "/usr/bin/php8.5"], "8.4.27", "valid", "php_runtime_invalid"),
        (["--php-binary", "/usr/bin/php8.4"], "8.4.27", "missing", "php_runtime_unavailable"),
        (["--php-binary", "/usr/bin/php8.4"], "8.4.27", "writable", "php_runtime_unavailable"),
        (["--php-binary", "/usr/bin/php8.4"], "8.4.27", "wrong_owner", "php_runtime_unavailable"),
        (["--php-binary", "/usr/bin/php8.4"], "8.4.27", "not_executable", "php_runtime_unavailable"),
        (["--php-binary", "/usr/bin/php8.4"], "8.4.27", "symlink", "php_runtime_unavailable"),
        ([], "8.5.12", "valid", "php_runtime_invalid"),
    ]
    for index, (extra, php_version, state, classification) in enumerate(rejected):
        directory = Path(temporary) / ("rejected-" + str(index))
        result, stdout, stderr, calls = run_receipt_cli(
            root, directory, legacy_args + extra, php_version, binary_state=state,
        )
        assert result == 1 and not stdout
        assert classification in stderr
        assert not directory.exists(), "failed runtime evidence must not create a receipt"

    duplicate = legacy_args + ["--php-binary", "/usr/bin/php8.4",
                               "--php-binary", "/usr/bin/php8.5"]
    try:
        with contextlib.redirect_stderr(io.StringIO()):
            module.main(duplicate)
    except SystemExit as error:
        assert error.code == 2
    else:
        raise AssertionError("receipt parser accepted repeated runtime options")


def main():
    assert module.validate_php_binary_path("/usr/bin/php8.4") == "/usr/bin/php8.4"
    assert module.validate_php_binary_path("/usr/bin/php8.5") == "/usr/bin/php8.5"
    modern_controller = (ROOT / "scripts/deploy-staging.sh").read_text(encoding="utf-8")
    assert '--php-binary "$PHP_BIN"' in modern_controller
    for path in ("/usr/bin/php", "/usr/bin/php7.4", "/tmp/php8.5", "8.5"):
        try:
            module.validate_php_binary_path(path)
        except ValueError:
            pass
        else:
            raise AssertionError(f"receipt accepted a non-allowlisted PHP binary: {path}")
    with tempfile.TemporaryDirectory() as temporary:
        root = Path(temporary) / "app"
        root.mkdir()
        (root / ".env").write_text("RECEIPT_SECRET_SENTINEL=do-not-copy\n", encoding="utf-8")
        for relative in module.LOCKFILES:
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / relative, destination)
        (root / "public/build").mkdir(parents=True)
        (root / "public/build/manifest.json").write_text("{}\n", encoding="utf-8")
        (root / "wordpress/wp-caregrid-connector").parent.mkdir(parents=True, exist_ok=True)
        shutil.copytree(ROOT / "wordpress/wp-caregrid-connector", root / "wordpress/wp-caregrid-connector")
        release = root / "wordpress/wp-caregrid-connector/release.json"
        version = json.loads(release.read_text(encoding="utf-8"))["version"]
        (root / "release-target-marker.txt").write_text("initial\n", encoding="utf-8")

        git(root, "init", "--quiet")
        git(root, "config", "user.name", "Receipt Fixture")
        git(root, "config", "user.email", "receipt-fixture@example.invalid")
        git(root, "add", ".")
        git(root, "commit", "--quiet", "-m", "fixture application baseline")
        origin = git(root, "rev-parse", "HEAD")
        package, metadata = install_package(root, version, origin)

        # The same package remains an allowed immutable artifact at a later app SHA.
        (root / "release-target-marker.txt").write_text("later application release\n", encoding="utf-8")
        git(root, "add", "release-target-marker.txt")
        git(root, "commit", "--quiet", "-m", "fixture application release")
        target = git(root, "rev-parse", "HEAD")
        test_cli_runtime_transition(root, temporary, origin, target, version)
        data = receipt(root, origin, target)
        serialized = json.dumps(data)
        assert "RECEIPT_SECRET_SENTINEL" not in serialized and "do-not-copy" not in serialized
        assert data["connector"]["package_sha256"] == metadata["package_sha256"]
        assert data["connector"]["source_revision"] == origin
        assert data["target_sha"] == data["runtime_head"] == target
        assert set(data["lockfile_sha256"]) == set(module.LOCKFILES)

        # A package produced at the exact application SHA is also valid.
        _, metadata = install_package(root, version, target)
        same_commit = receipt(root, origin, target)
        assert same_commit["connector"]["source_revision"] == target

        # A rollback can reuse a matching artifact from a later related commit.
        install_package(root, version, target)
        rollback = receipt(root, target, origin, runtime_head=origin, mode="rollback")
        assert rollback["connector"]["source_revision"] == target

        receipts = Path(temporary) / "receipts"
        expected_mode = None if os.name == "nt" else 0o700
        parent_mode = None if os.name == "nt" else 0o700
        path = module.write_receipt(same_commit, receipts, expected_owner=Path(temporary).stat().st_uid, expected_mode=expected_mode, expected_parent_mode=parent_mode)
        persisted = json.loads(path.read_text(encoding="utf-8"))
        assert persisted == same_commit
        if os.name != "nt":
            assert stat.S_IMODE(path.stat().st_mode) == 0o600
        try:
            module.write_receipt(same_commit, receipts, expected_owner=Path(temporary).stat().st_uid, expected_mode=expected_mode, expected_parent_mode=parent_mode)
        except ValueError:
            pass
        else:
            raise AssertionError("duplicate receipt path was overwritten")

        try:
            receipt(root, origin, target, runtime_head=origin)
        except ValueError:
            pass
        else:
            raise AssertionError("receipt accepted a runtime HEAD different from the target")

        # Bad/missing artifact revisions, unrelated history and mismatched digests fail closed.
        install_package(root, version, "not-a-commit")
        expect_receipt_failure(lambda: receipt(root, origin, target), "connector_provenance_invalid")

        unrelated = Path(temporary) / "unrelated"
        unrelated.mkdir()
        git(unrelated, "init", "--quiet")
        git(unrelated, "config", "user.name", "Receipt Fixture")
        git(unrelated, "config", "user.email", "receipt-fixture@example.invalid")
        (unrelated / "unrelated.txt").write_text("unrelated\n", encoding="utf-8")
        git(unrelated, "add", ".")
        git(unrelated, "commit", "--quiet", "-m", "unrelated history")
        unrelated_sha = git(unrelated, "rev-parse", "HEAD")
        install_package(root, version, unrelated_sha)
        expect_receipt_failure(lambda: receipt(root, origin, target), "connector_provenance_invalid")

        install_package(root, version, target, sidecar_overrides={"source_sha256": "f" * 64})
        expect_receipt_failure(lambda: receipt(root, origin, target), "connector_provenance_invalid")
        install_package(root, version, target, sidecar_overrides={"package_sha256": "0" * 64})
        expect_receipt_failure(lambda: receipt(root, origin, target), "connector_provenance_invalid")
        install_package(root, version, target, sidecar_overrides={"version": "9.9.9"})
        expect_receipt_failure(lambda: receipt(root, origin, target), "connector_provenance_invalid")

        # Source drift after package creation is rejected by the same per-file contract.
        source_file = root / "wordpress/wp-caregrid-connector/wp-caregrid-connector.php"
        original = source_file.read_bytes()
        install_package(root, version, target)
        source_file.write_bytes(original + b"\n// stale source fixture\n")
        expect_receipt_failure(lambda: receipt(root, origin, target), "connector_provenance_invalid")
        source_file.write_bytes(original)

        # An ancestor revision with a different Connector tree is not artifact provenance.
        source_file.write_bytes(original + b"\n// changed in a later connector release\n")
        git(root, "add", "wordpress/wp-caregrid-connector/wp-caregrid-connector.php")
        git(root, "commit", "--quiet", "-m", "later connector source")
        later_target = git(root, "rev-parse", "HEAD")
        install_package(root, version, origin)
        expect_receipt_failure(lambda: receipt(root, origin, later_target), "connector_provenance_invalid")

        # Missing Git history and target/runtime mismatch cannot be converted into provenance.
        no_history = Path(temporary) / "without-git"
        shutil.copytree(root, no_history, ignore=shutil.ignore_patterns(".git", "storage"))
        shutil.copytree(root / "storage", no_history / "storage")
        expect_receipt_failure(lambda: receipt(no_history, origin, target), "connector_provenance_invalid")
        if os.name != "nt":
            unsafe_parent = Path(temporary) / "unsafe-parent"
            unsafe_parent.mkdir(mode=0o700)
            link = Path(temporary) / "parent-link"
            link.symlink_to(unsafe_parent, target_is_directory=True)
            try:
                module.write_receipt(same_commit, link / "receipts", expected_owner=Path(temporary).stat().st_uid)
            except ValueError:
                pass
            else:
                raise AssertionError("receipt accepted a symlink parent")

            directory_link = Path(temporary) / "directory-link"
            directory_link.symlink_to(unsafe_parent, target_is_directory=True)
            try:
                module.write_receipt(same_commit, directory_link, expected_owner=Path(temporary).stat().st_uid)
            except ValueError:
                pass
            else:
                raise AssertionError("receipt accepted a symlink receipt directory")

            try:
                module.write_receipt(same_commit, Path(temporary) / "wrong-owner", expected_owner=-1)
            except ValueError:
                pass
            else:
                raise AssertionError("receipt accepted a receipt parent with unexpected ownership")

            try:
                module.write_receipt(same_commit, Path(temporary) / "wrong-mode", expected_owner=Path(temporary).stat().st_uid, expected_mode=0o755)
            except ValueError:
                pass
            else:
                raise AssertionError("receipt accepted an unexpected receipt directory mode")

    print("Deployment receipt provenance fixtures passed.")


if __name__ == "__main__":
    main()
