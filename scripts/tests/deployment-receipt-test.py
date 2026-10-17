#!/usr/bin/env python3
"""Receipt provenance and no-secret-content fixtures."""

import hashlib
import importlib.util
import json
import os
import shutil
import stat
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/write-deployment-receipt.py"
spec = importlib.util.spec_from_file_location("deployment_receipt", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def main():
    target = "a" * 40
    baseline = "b" * 40
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
        (root / "wordpress/wp-caregrid-connector").mkdir(parents=True)
        release = ROOT / "wordpress/wp-caregrid-connector/release.json"
        shutil.copyfile(release, root / "wordpress/wp-caregrid-connector/release.json")
        version = json.loads(release.read_text(encoding="utf-8"))["version"]
        package_dir = root / "storage/app/private/connector"
        package_dir.mkdir(parents=True)
        package = package_dir / f"wp-caregrid-connector-{version}.zip"
        package.write_bytes(b"synthetic connector artifact")
        package_hash = hashlib.sha256(package.read_bytes()).hexdigest()
        (package_dir / f"wp-caregrid-connector-{version}.zip.manifest.json").write_text(
            json.dumps({"version": version, "source_revision": target, "source_sha256": "c" * 64, "package_sha256": package_hash}),
            encoding="utf-8",
        )

        data = module.receipt_data(
            root,
            baseline_sha=baseline,
            target_sha=target,
            workflow_run_id="12345",
            mode="forward",
            git_head=target,
            php_version="8.4.26",
            node_version="v22.0.0",
            composer_version="Composer version 2.8.0",
            migrations="passed",
            health="passed",
        )
        serialized = json.dumps(data)
        assert "RECEIPT_SECRET_SENTINEL" not in serialized and "do-not-copy" not in serialized
        assert data["connector"]["package_sha256"] == package_hash
        assert data["target_sha"] == data["runtime_head"] == target
        assert set(data["lockfile_sha256"]) == set(module.LOCKFILES)

        receipts = Path(temporary) / "receipts"
        expected_mode = None if os.name == "nt" else 0o700
        parent_mode = None if os.name == "nt" else 0o700
        path = module.write_receipt(data, receipts, expected_owner=Path(temporary).stat().st_uid, expected_mode=expected_mode, expected_parent_mode=parent_mode)
        persisted = json.loads(path.read_text(encoding="utf-8"))
        assert persisted == data
        if os.name != "nt":
            assert stat.S_IMODE(path.stat().st_mode) == 0o600
        try:
            module.write_receipt(data, receipts, expected_owner=Path(temporary).stat().st_uid, expected_mode=expected_mode, expected_parent_mode=parent_mode)
        except ValueError:
            pass
        else:
            raise AssertionError("duplicate receipt path was overwritten")

        (package_dir / f"wp-caregrid-connector-{version}.zip.manifest.json").write_text(
            json.dumps({"version": version, "source_revision": baseline, "source_sha256": "c" * 64, "package_sha256": package_hash}),
            encoding="utf-8",
        )
        try:
            module.receipt_data(
                root,
                baseline_sha=baseline,
                target_sha=target,
                workflow_run_id="12345",
                mode="forward",
                git_head=target,
                php_version="8.4.26",
                node_version="v22.0.0",
                composer_version="Composer version 2.8.0",
                migrations="passed",
                health="passed",
            )
        except ValueError:
            pass
        else:
            raise AssertionError("mismatched Connector source revision was recorded")

    print("Deployment receipt provenance fixtures passed.")


if __name__ == "__main__":
    main()
