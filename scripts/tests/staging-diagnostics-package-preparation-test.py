#!/usr/bin/env python3
"""Regression tests for canonical, non-replaced Git source extraction."""

from __future__ import annotations

import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).parents[2]
PREPARER = ROOT / "scripts/staging-diagnostics/provisioning/prepare-package.sh"
PACKAGE_FILES = (
    "scripts/staging-diagnostics/dispatch.sh",
    "scripts/staging-diagnostics/provisioning/activation-check.sh",
    "scripts/staging-diagnostics/provisioning/diagnostics-shell.sh",
    "scripts/staging-diagnostics/read-m12-cache.php",
    "scripts/staging-diagnostics/provisioning/run-m12-readback.sh",
    "scripts/staging-diagnostics/provisioning/provision.py",
    "scripts/staging-diagnostics/provisioning/verify.py",
    "scripts/staging-diagnostics/provisioning/rollback.py",
)


class CanonicalPackagePreparationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="caregrid-canonical-git-")
        self.repo = Path(self.temp.name) / "repo"
        self.repo.mkdir()
        for relative in PACKAGE_FILES:
            target = self.repo / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / relative, target)
        preparer_copy = self.repo / "scripts/staging-diagnostics/provisioning/prepare-package.sh"
        preparer_copy.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(PREPARER, preparer_copy)
        self.git("init", "-q")
        self.git("config", "user.name", "Synthetic Test")
        self.git("config", "user.email", "synthetic@example.invalid")
        self.git("add", ".")
        self.git("commit", "-qm", "approved synthetic package")
        self.approved_sha = self.git("rev-parse", "HEAD")

    def tearDown(self) -> None:
        self.temp.cleanup()

    def git(self, *args: str) -> str:
        return subprocess.check_output(
            ["git", "-C", str(self.repo), *args], text=True
        ).strip()

    def invoke(self, *, extra_env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
        env = {key: value for key, value in os.environ.items() if not key.startswith("GIT_")}
        if extra_env:
            env.update(extra_env)
        relative = "scripts/staging-diagnostics/provisioning/prepare-package.sh"
        entry = subprocess.check_output(
            [
                "git",
                "--no-replace-objects",
                "-C",
                str(self.repo),
                "ls-tree",
                self.approved_sha,
                "--",
                relative,
            ],
            text=True,
        ).strip()
        metadata, found_path = entry.split("\t", 1)
        mode, kind, blob_oid = metadata.split()
        if found_path != relative or kind != "blob" or mode not in {"100644", "100755"}:
            raise AssertionError("canonical helper blob missing or unsafe")
        helper = subprocess.check_output(
            ["git", "--no-replace-objects", "-C", str(self.repo), "cat-file", "blob", blob_oid],
            text=True,
        )
        return subprocess.run(
            ["bash", "-s", "--", "--verify-only", str(self.repo), self.approved_sha],
            env=env,
            input=helper,
            text=True,
            capture_output=True,
            check=False,
        )

    def assert_rejected(self, classification: str, **kwargs: object) -> None:
        result = self.invoke(**kwargs)
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn(f"code={classification}", result.stderr)

    def test_canonical_source_package_passes(self) -> None:
        result = self.invoke()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), "SOURCE_PROVENANCE=PASS")

    def test_working_tree_preparer_is_never_executed(self) -> None:
        working_copy = self.repo / "scripts/staging-diagnostics/provisioning/prepare-package.sh"
        working_copy.write_text("#!/bin/bash\nsudo /bin/sh -c 'echo unsafe'\n", encoding="ascii")
        result = self.invoke()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), "SOURCE_PROVENANCE=PASS")

    def test_rejects_replace_ref_that_changes_old_git_show_resolution(self) -> None:
        target = self.repo / PACKAGE_FILES[0]
        target.write_text("unreviewed replacement\n", encoding="ascii")
        self.git("commit", "-qam", "replacement content")
        replacement_sha = self.git("rev-parse", "HEAD")
        self.git("replace", self.approved_sha, replacement_sha)

        self.assertEqual(
            self.git("show", f"{self.approved_sha}:{PACKAGE_FILES[0]}"),
            "unreviewed replacement",
        )
        self.assertEqual(self.git("status", "--porcelain"), "")
        self.assert_rejected("GIT_REPLACE_REFS_REJECTED")

    def test_rejects_manipulated_git_environment_before_git_runs(self) -> None:
        self.assert_rejected(
            "UNEXPECTED_GIT_ENVIRONMENT",
            extra_env={"GIT_DIR": str(self.repo / ".git")},
        )

    def test_rejects_alternate_object_directory_metadata(self) -> None:
        alternates = self.repo / ".git/objects/info/alternates"
        alternates.parent.mkdir(parents=True, exist_ok=True)
        alternates.write_text("/tmp/untrusted-objects\n", encoding="ascii")
        self.assert_rejected("GIT_EXTERNAL_OBJECTS_REJECTED")

    def test_rejects_git_config_include_and_replace_controls(self) -> None:
        self.git("config", "--local", "include.path", "./untrusted-config")
        self.assert_rejected("GIT_PROVENANCE_CONFIG_REJECTED")

    def test_rejects_local_object_replacement_configuration(self) -> None:
        self.git("config", "--local", "core.useReplaceRefs", "true")
        self.assert_rejected("GIT_PROVENANCE_CONFIG_REJECTED")

    def test_rejects_partial_clone_promisor_configuration(self) -> None:
        self.git("config", "--local", "remote.origin.promisor", "true")
        self.assert_rejected("GIT_PROVENANCE_CONFIG_REJECTED")


if __name__ == "__main__":
    unittest.main(verbosity=2)
