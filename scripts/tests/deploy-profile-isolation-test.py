#!/usr/bin/env python3
"""Synthetic tests for fixed, environment-isolated privileged profiles."""

import importlib.util
import json
import hashlib
import stat
import sys
import unittest
from pathlib import Path


ROOT = __import__("pathlib").Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("deploy_profiles", ROOT / "scripts" / "wpcaregrid_deploy_profiles.py")
PROFILES = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = PROFILES
SPEC.loader.exec_module(PROFILES)

HELPER_SPEC = importlib.util.spec_from_file_location(
    "deploy_profile_entrypoint", ROOT / "scripts" / "wpcaregrid-deploy-env-priv.py"
)
HELPER = importlib.util.module_from_spec(HELPER_SPEC)
HELPER_SPEC.loader.exec_module(HELPER)


def policy(**changes):
    helper_bytes = b"synthetic staging helper bytes"
    module_bytes = b"synthetic shared profile module bytes"
    data = {
        "schema": 1,
        "profile": "staging-next",
        "enabled": True,
        "allowed_uid": 1000,
        "app_dir": "/var/www/wpcaregrid-staging-new",
        "database": "wpcg_staging_next",
        "host": "staging-next.wpcaregrid.com",
        "operations": ["preflight"],
        "helper_version": "1.0.1",
        "compatibility_contract": "wpcaregrid-deploy-profile-v1",
        "entrypoint_sha256": hashlib.sha256(helper_bytes).hexdigest(),
        "profiles_module_sha256": hashlib.sha256(module_bytes).hexdigest(),
    }
    data.update(changes)
    return data


class DeployProfileIsolationTest(unittest.TestCase):
    def expect_refused(self, code, callback):
        with self.assertRaises(PROFILES.ProfileRefused) as caught:
            callback()
        self.assertEqual(str(caught.exception), code)

    def test_fixed_staging_entrypoint_and_target_are_resolved(self):
        profile = PROFILES.resolve_profile(PROFILES.STAGING_NEXT_ENTRYPOINT)
        self.assertEqual(profile.name, "staging-next")
        self.assertEqual(profile.app_dir, "/var/www/wpcaregrid-staging-new")
        self.assertEqual(profile.database, "wpcg_staging_next")
        self.assertEqual(profile.host, "staging-next.wpcaregrid.com")

    def test_production_entrypoint_is_present_but_inert(self):
        profile = PROFILES.resolve_profile(PROFILES.PRODUCTION_ENTRYPOINT)
        self.assertEqual(profile.name, "production")
        self.assertFalse(profile.enabled)
        self.assertIsNone(profile.app_dir)
        self.assertIsNone(profile.database)
        self.assertEqual(profile.operations, frozenset())
        self.expect_refused("profile_not_enabled", lambda: PROFILES.authorize_invocation(
            profile.entrypoint, ["preflight"], effective_uid=0, invoking_uid=1000, policy_uid=1000
        ))

    def test_runtime_profile_and_target_arguments_are_rejected(self):
        self.expect_refused("operation_not_allowed", lambda: PROFILES.authorize_invocation(
            PROFILES.STAGING_NEXT_ENTRYPOINT, ["preflight", "--profile=production"],
            effective_uid=0, invoking_uid=1000, policy_uid=1000,
        ))
        self.expect_refused("operation_not_allowed", lambda: PROFILES.authorize_invocation(
            PROFILES.STAGING_NEXT_ENTRYPOINT, ["preflight", "--database=wpcaregrid"],
            effective_uid=0, invoking_uid=1000, policy_uid=1000,
        ))

    def test_production_paths_cannot_be_selected_from_staging_entrypoint(self):
        production = PROFILES.resolve_profile(
            "/usr/local/libexec/wpcaregrid-deploy/production/inactive/wpcaregrid-deploy-production-priv"
        )
        self.assertFalse(production.enabled)
        self.expect_refused("profile_not_enabled", lambda: PROFILES.authorize_invocation(
            production.entrypoint, ["preflight"], effective_uid=0, invoking_uid=2000, policy_uid=2000
        ))
        self.expect_refused("unknown_entrypoint", lambda: PROFILES.resolve_profile(
            "/tmp/wpcaregrid-deploy-staging-next-priv"
        ))
        self.expect_refused("unknown_entrypoint", lambda: PROFILES.resolve_profile(
            "/usr/local/libexec/wpcaregrid-deploy/staging-next/../production/wpcaregrid-deploy-staging-next-priv"
        ))

    def test_helper_requires_root_and_exact_staging_caller_identity(self):
        for effective_uid, invoking_uid, policy_uid in [
            (1000, 1000, 1000), (0, None, 1000), (0, 1001, 1000), (0, 1000, 1001),
        ]:
            self.expect_refused("caller_identity_invalid", lambda: PROFILES.authorize_invocation(
                PROFILES.STAGING_NEXT_ENTRYPOINT, ["preflight"],
                effective_uid=effective_uid, invoking_uid=invoking_uid, policy_uid=policy_uid,
            ))

    def test_sudo_uid_parser_is_strict(self):
        self.assertEqual(PROFILES.invoking_uid_from_sudo("1000"), 1000)
        for value in [None, "", "0", "-1", "+1000", " 1000", "1000 ", "1e3", "10000000000", True]:
            self.expect_refused("caller_identity_invalid", lambda value=value: PROFILES.invoking_uid_from_sudo(value))

    def test_root_policy_is_exact_and_rejects_profile_target_or_operation_changes(self):
        expected_uid = PROFILES._validate_policy(PROFILES.STAGING_NEXT, policy())
        self.assertEqual(expected_uid, 1000)
        for bad in [
            policy(profile="production"),
            policy(enabled=False),
            policy(allowed_uid=0),
            policy(app_dir="/var/www/wpcaregrid"),
            policy(database="wpcaregrid"),
            policy(host="wpcaregrid.com"),
            policy(operations=["preflight", "deploy"]),
            {**policy(), "extra": "ignored fields are forbidden"},
        ]:
            self.expect_refused("policy_schema_invalid" if "extra" in bad else (
                "profile_not_enabled" if bad.get("profile") == "production" or bad.get("enabled") is False
                else "caller_identity_invalid" if bad.get("allowed_uid") == 0
                else "target_identity_mismatch" if any(bad.get(k) != policy()[k] for k in ("app_dir", "database", "host"))
                else "operation_allowlist_mismatch"
            ), lambda bad=bad: PROFILES._validate_policy(PROFILES.STAGING_NEXT, bad))

    def test_installed_policy_requires_regular_root_owned_non_writable_file(self):
        policy_data = policy()
        encoded = json.dumps(policy_data, separators=(",", ":")).encode()
        helper_bytes = b"synthetic staging helper bytes"
        module_bytes = b"synthetic shared profile module bytes"

        class Info:
            def __init__(self, mode, uid=0, size=256):
                self.st_mode = mode
                self.st_uid = uid
                self.st_size = size

        def safe_stat(path, **_kwargs):
            path = str(path)
            if path.endswith("staging-next.json") or path.endswith("wpcaregrid_deploy_profiles.py"):
                return Info(stat.S_IFREG | 0o644, size=256)
            if path.endswith("wpcaregrid-deploy-staging-next-priv"):
                return Info(stat.S_IFREG | 0o755, size=256)
            return Info(stat.S_IFDIR | 0o755)

        def safe_read(path):
            path = str(path)
            if path.endswith("staging-next.json"):
                return encoded
            if path.endswith("wpcaregrid-deploy-staging-next-priv"):
                return helper_bytes
            if path.endswith("wpcaregrid_deploy_profiles.py"):
                return module_bytes
            raise AssertionError("unexpected synthetic file read")

        uid = PROFILES.load_root_policy(
            PROFILES.STAGING_NEXT,
            stat_fn=safe_stat,
            read_bytes_fn=safe_read,
        )
        self.assertEqual(uid, 1000)

        for info in [
            type("Info", (), {"st_mode": stat.S_IFLNK | 0o777, "st_uid": 0})(),
            type("Info", (), {"st_mode": stat.S_IFREG | 0o666, "st_uid": 0})(),
            type("Info", (), {"st_mode": stat.S_IFREG | 0o644, "st_uid": 1000})(),
        ]:
            self.expect_refused("policy_metadata_invalid", lambda info=info: PROFILES.load_root_policy(
                PROFILES.STAGING_NEXT,
                stat_fn=lambda path, **_kwargs: info if str(path).endswith("staging-next.json") else safe_stat(path),
                read_bytes_fn=safe_read,
            ))

        self.expect_refused("policy_parent_metadata_invalid", lambda: PROFILES.load_root_policy(
            PROFILES.STAGING_NEXT,
            stat_fn=lambda path, **_kwargs: Info(stat.S_IFDIR | 0o777) if str(path).endswith("profiles")
                else Info(stat.S_IFREG | 0o644) if str(path).endswith("staging-next.json")
                else Info(stat.S_IFDIR | 0o755),
            read_bytes_fn=lambda _path: encoded,
        ))
        self.expect_refused("policy_size_invalid", lambda: PROFILES.load_root_policy(
            PROFILES.STAGING_NEXT,
            stat_fn=lambda path, **_kwargs: Info(stat.S_IFREG | 0o644, size=4097)
            if str(path).endswith("staging-next.json") else Info(stat.S_IFDIR | 0o755),
            read_bytes_fn=safe_read,
        ))
        self.expect_refused("policy_unavailable", lambda: PROFILES.load_root_policy(
            PROFILES.STAGING_NEXT,
            stat_fn=safe_stat,
            read_bytes_fn=lambda path: b'{"schema":1,"schema":1}' if str(path).endswith("staging-next.json") else safe_read(path),
        ))

    def test_installed_helper_must_be_root_owned_regular_0755(self):
        good = type("Info", (), {"st_mode": stat.S_IFREG | 0o755, "st_uid": 0})()
        parent = type("Info", (), {"st_mode": stat.S_IFDIR | 0o755, "st_uid": 0})()
        PROFILES.verify_installed_entrypoint(
            PROFILES.STAGING_NEXT_ENTRYPOINT,
            lstat_fn=lambda path: good if str(path).endswith("wpcaregrid-deploy-staging-next-priv") else parent,
        )
        for bad in [
            type("Info", (), {"st_mode": stat.S_IFLNK | 0o777, "st_uid": 0})(),
            type("Info", (), {"st_mode": stat.S_IFREG | 0o755, "st_uid": 1000})(),
            type("Info", (), {"st_mode": stat.S_IFREG | 0o775, "st_uid": 0})(),
        ]:
            self.expect_refused("helper_metadata_invalid", lambda bad=bad: PROFILES.verify_installed_entrypoint(
                PROFILES.STAGING_NEXT_ENTRYPOINT,
                lstat_fn=lambda path: bad if str(path).endswith("wpcaregrid-deploy-staging-next-priv") else parent,
            ))
        self.expect_refused("helper_parent_metadata_invalid", lambda: PROFILES.verify_installed_entrypoint(
            PROFILES.STAGING_NEXT_ENTRYPOINT,
            lstat_fn=lambda path: good if str(path).endswith("wpcaregrid-deploy-staging-next-priv")
            else type("Info", (), {"st_mode": stat.S_IFDIR | 0o777, "st_uid": 0})()
            if str(path).endswith("1.0.1") else parent,
        ))

    def test_entrypoint_verifies_shared_module_hash_before_execution(self):
        module_path = str(PROFILES.STAGING_NEXT_ENTRYPOINT.parent / "wpcaregrid_deploy_profiles.py")
        source = b"raise RuntimeError('must not execute before hash validation')"
        expected = hashlib.sha256(b"approved different bytes").hexdigest()

        def lstat(path):
            if path == HELPER.EXPECTED_ENTRYPOINT:
                return type("Info", (), {"st_mode": stat.S_IFREG | 0o755, "st_uid": 0})()
            if path == module_path:
                return type("Info", (), {"st_mode": stat.S_IFREG | 0o644, "st_uid": 0, "st_size": len(source)})()
            return type("Info", (), {"st_mode": stat.S_IFDIR | 0o755, "st_uid": 0})()

        with self.assertRaises(HELPER.BootstrapRefused) as caught:
            HELPER._load_verified_profiles_module(
                HELPER.EXPECTED_ENTRYPOINT,
                lstat_fn=lstat,
                read_module_fn=lambda _path: source,
                expected_module_sha256=expected,
            )
        self.assertEqual(str(caught.exception), "helper_identity_mismatch")

    def test_entrypoint_preload_rejects_untrusted_paths_and_never_reads_module(self):
        module_path = str(PROFILES.STAGING_NEXT_ENTRYPOINT.parent / "wpcaregrid_deploy_profiles.py")
        source = b"VERIFIED_MODULE = True"
        expected = hashlib.sha256(source).hexdigest()
        directory = type("Info", (), {"st_mode": stat.S_IFDIR | 0o755, "st_uid": 0})()
        entry = type("Info", (), {"st_mode": stat.S_IFREG | 0o755, "st_uid": 0})()
        module = type("Info", (), {"st_mode": stat.S_IFREG | 0o644, "st_uid": 0, "st_size": len(source)})()
        reads = []

        def read_module(path):
            reads.append(path)
            return source

        def ordinary(path):
            if path == HELPER.EXPECTED_ENTRYPOINT:
                return entry
            if path == module_path:
                return module
            return directory

        self._expect_bootstrap_refused("unknown_entrypoint", lambda: HELPER._read_profiles_module(
            "/tmp/staging-helper", lstat_fn=ordinary, read_module_fn=read_module,
            expected_module_sha256=expected,
        ))
        self._expect_bootstrap_refused("helper_parent_metadata_invalid", lambda: HELPER._read_profiles_module(
            HELPER.EXPECTED_ENTRYPOINT,
            lstat_fn=lambda path: type("Info", (), {"st_mode": stat.S_IFLNK | 0o777, "st_uid": 0})()
            if path == "/usr/local/libexec" else ordinary(path),
            read_module_fn=read_module, expected_module_sha256=expected,
        ))
        self._expect_bootstrap_refused("helper_metadata_invalid", lambda: HELPER._read_profiles_module(
            HELPER.EXPECTED_ENTRYPOINT,
            lstat_fn=lambda path: type("Info", (), {"st_mode": stat.S_IFLNK | 0o777, "st_uid": 0})()
            if path == module_path else ordinary(path),
            read_module_fn=read_module, expected_module_sha256=expected,
        ))
        self.assertEqual(reads, [])

    def _expect_bootstrap_refused(self, code, callback):
        with self.assertRaises(HELPER.BootstrapRefused) as caught:
            callback()
        self.assertEqual(str(caught.exception), code)

    def test_entrypoint_executes_only_the_exactly_pinned_shared_module_bytes(self):
        module_path = str(PROFILES.STAGING_NEXT_ENTRYPOINT.parent / "wpcaregrid_deploy_profiles.py")
        source = b"VERIFIED_MODULE = True"
        expected = hashlib.sha256(source).hexdigest()

        def lstat(path):
            if path == HELPER.EXPECTED_ENTRYPOINT:
                return type("Info", (), {"st_mode": stat.S_IFREG | 0o755, "st_uid": 0})()
            if path == module_path:
                return type("Info", (), {"st_mode": stat.S_IFREG | 0o644, "st_uid": 0, "st_size": len(source)})()
            return type("Info", (), {"st_mode": stat.S_IFDIR | 0o755, "st_uid": 0})()

        loaded = HELPER._load_verified_profiles_module(
            HELPER.EXPECTED_ENTRYPOINT,
            lstat_fn=lstat,
            read_module_fn=lambda _path: source,
            expected_module_sha256=expected,
        )
        self.assertTrue(loaded.VERIFIED_MODULE)

    def test_target_path_is_fixed_and_symlink_free(self):
        directory = type("Info", (), {"st_mode": stat.S_IFDIR | 0o755})()
        PROFILES.verify_target_directory(PROFILES.STAGING_NEXT, lstat_fn=lambda _path: directory)

        def symlink_www(path):
            if path == "/var/www":
                return type("Info", (), {"st_mode": stat.S_IFLNK | 0o777})()
            return directory

        self.expect_refused("target_path_symlink", lambda: PROFILES.verify_target_directory(
            PROFILES.STAGING_NEXT, lstat_fn=symlink_www
        ))
        self.expect_refused("target_path_invalid", lambda: PROFILES.verify_target_directory(
            PROFILES.STAGING_NEXT,
            lstat_fn=lambda path: type("Info", (), {"st_mode": stat.S_IFREG | 0o644})()
            if path.endswith("wpcaregrid-staging-new") else directory,
        ))

    def test_staging_helper_manifest_pins_final_source_bytes_and_not_app_releases(self):
        manifest_path = ROOT / "deploy" / "release-manifests" / "staging-next-helper.json"
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        policy_spec = importlib.util.spec_from_file_location("daily_sync_policy_for_manifest", ROOT / "scripts" / "daily_staging_sync_policy.py")
        sync_policy = importlib.util.module_from_spec(policy_spec)
        sys.modules[policy_spec.name] = sync_policy
        policy_spec.loader.exec_module(sync_policy)
        self.assertEqual(manifest["profile"], "staging-next")
        self.assertTrue(manifest["enabled"])
        self.assertEqual(manifest["helper_version"], "1.0.1")
        self.assertEqual(manifest["helper_version"], sync_policy.HELPER_VERSION)
        self.assertEqual(manifest["compatibility_contract"], "wpcaregrid-deploy-profile-v1")
        self.assertNotIn("application_sha", manifest)
        self.assertEqual(manifest["entrypoint"], str(PROFILES.STAGING_NEXT_ENTRYPOINT))
        self.assertEqual(manifest["target"], {
            "app_dir": "/var/www/wpcaregrid-staging-new",
            "database": "wpcg_staging_next",
            "host": "staging-next.wpcaregrid.com",
        })
        self.assertEqual(manifest["operations"], ["preflight"])
        self.assertEqual({item["path"] for item in manifest["artifacts"]}, {
            "scripts/wpcaregrid_deploy_profiles.py", "scripts/wpcaregrid-deploy-env-priv.py"
        })
        for artifact in manifest["artifacts"]:
            actual = hashlib.sha256((ROOT / artifact["path"]).read_bytes()).hexdigest()
            self.assertRegex(artifact["sha256"], r"^[0-9a-f]{64}$")
            self.assertEqual(actual, artifact["sha256"], artifact["path"])
            if artifact["path"] == "scripts/wpcaregrid_deploy_profiles.py":
                self.assertEqual(actual, HELPER.EXPECTED_PROFILES_MODULE_SHA256)
        self.assertIn("#!/usr/bin/python3 -I", (ROOT / "scripts" / "wpcaregrid-deploy-env-priv.py").read_text())

    def test_production_manifest_is_explicitly_inert_without_target_or_allowlist(self):
        manifest = json.loads((ROOT / "deploy" / "release-manifests" / "production-helper.json").read_text(encoding="utf-8"))
        self.assertEqual(manifest["profile"], "production")
        self.assertFalse(manifest["enabled"])
        self.assertIsNone(manifest["entrypoint"])
        self.assertIsNone(manifest["target"])
        self.assertEqual(manifest["operations"], [])
        self.assertEqual(manifest["artifacts"], [])


if __name__ == "__main__":
    unittest.main()
