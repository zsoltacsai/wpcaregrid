#!/usr/bin/env python3
"""Regression checks for production confinement 1.0.13 stacked-label acceptance."""
import hashlib
import importlib.util
import json
import sys
import unittest
from pathlib import Path

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "deploy/php-fpm-confinement/1.0.13"
OLD = ROOT / "deploy/php-fpm-confinement/1.0.12"
EXPECTED_SHA256SUMS = "f27b59eb0c093881d4246d0ac667cf8161ef2d7ed544300b8b674f29e6bc3002"
EXPECTED_BINDING = "afe9a539851103c1c7907c72363ddf9de5c25d277d8021435f76eb6ae8f37cfe"


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


class StackedLabelAcceptance(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.probe = load("production_confinement_1013_probe", PKG / "acceptance/probe.py")
        cls.verifier = load("production_confinement_1013_verifier", PKG / "acceptance/verify-acceptance.py")

    def test_accepts_only_direct_or_exact_unconfined_enforce_stack(self):
        profile = "wpcaregrid-php-fpm-production"
        accepted = (
            f"{profile} (enforce)",
            f"unconfined//&{profile} (enforce)",
        )
        rejected = (
            "unconfined",
            f"{profile} (complain)",
            f"unconfined//&{profile} (complain)",
            f"other//&{profile} (enforce)",
            f"unconfined//&{profile}//&extra (enforce)",
            "wpcaregrid-php-fpm-production-probe (enforce)",
        )
        for label in accepted:
            self.assertTrue(self.probe.matches_enforced_profile_label(label, profile), label)
            self.assertTrue(self.verifier.matches_enforced_profile_label(label, profile), label)
        for label in rejected:
            self.assertFalse(self.probe.matches_enforced_profile_label(label, profile), label)
            self.assertFalse(self.verifier.matches_enforced_profile_label(label, profile), label)

    def test_inventory_maps_stacked_roles_only_to_their_exact_units(self):
        profile = "wpcaregrid-php-fpm-production"
        labels = self.verifier.role_label_map({
            "production": profile,
            "nginx": "wpcaregrid-nginx",
        })
        self.assertEqual(labels[f"unconfined//&{profile} (enforce)"], ["production"])
        self.assertEqual(labels["unconfined//&wpcaregrid-nginx (enforce)"], ["nginx"])
        self.assertNotIn(f"other//&{profile} (enforce)", labels)

    def test_new_manifest_is_pinned_complete_and_installed_runtime_bytes_are_unchanged(self):
        sums_bytes = (PKG / "SHA256SUMS").read_bytes()
        binding_bytes = (PKG / "binding.json").read_bytes()
        self.assertEqual(hashlib.sha256(sums_bytes).hexdigest(), EXPECTED_SHA256SUMS)
        self.assertEqual(hashlib.sha256(binding_bytes).hexdigest(), EXPECTED_BINDING)
        binding = json.loads(binding_bytes)
        self.assertEqual(binding["package_version"], "1.0.13")
        matrix = json.loads((PKG / "acceptance/matrix.json").read_text(encoding="utf-8"))
        self.assertEqual(matrix["version"], "1.0.13")
        entries = {}
        for line in sums_bytes.decode("utf-8").splitlines():
            digest, name = line.split("  ", 1)
            entries[name] = digest
        actual = {p.relative_to(PKG).as_posix() for p in PKG.rglob("*")
                  if p.is_file() and p.name != "SHA256SUMS"}
        self.assertEqual(set(entries), actual)
        for name, digest in entries.items():
            self.assertEqual(hashlib.sha256((PKG / name).read_bytes()).hexdigest(), digest, name)
        old_binding = json.loads((OLD / "binding.json").read_text(encoding="utf-8"))
        self.assertEqual(binding["installed_files"], old_binding["installed_files"])
        for relative in binding["installed_files"].values():
            self.assertEqual((PKG / relative).read_bytes(), (OLD / relative).read_bytes(), relative)


if __name__ == "__main__":
    unittest.main(verbosity=2)
