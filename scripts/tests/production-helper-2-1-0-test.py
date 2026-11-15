#!/usr/bin/env python3
"""Production helper 2.1.0: V3 review admission successor (synthetic tests only).

Proves (1) the 2.0.0 artifacts stay byte-identical, (2) the 2.1.0 manifest,
pins, installer, controller and sudoers are self-consistent, (3) the only
backend behaviour change is the replacement of the legacy V2 review admission,
and (4) the V3 admission behaves as documented in docs/RELEASE_REVIEW_POLICY.md.
Nothing here touches a host, the network, a database or production.
"""

from __future__ import annotations

import ast
import base64
import hashlib
import importlib.util
import json
import re
import subprocess
import sys
import types
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

# Policy module first: the successor backend reuses an already registered one.
from scripts import release_review_policy_v3 as POLICY
from scripts import deployment_backend_v2 as BACKEND_V2_0_0


def _load(name: str, relative: str):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


BACKEND = _load("deployment_backend_v2_1_0_under_test", "scripts/deployment_backend_v2_1_0.py")
ENTRY = _load("production_helper_2_1_0_entry_under_test",
              "scripts/wpcaregrid-deploy-production-priv-2_1_0.py")
CONTROLLER = _load("production_controller_2_1_0_under_test",
                   "scripts/production_deploy_controller_2_1_0.py")
INSTALLER = _load("production_installer_2_1_0_under_test",
                  "scripts/install_production_helper_2_1_0.py")
MANIFEST_PATH = ROOT / "deploy/release-manifests/production-deployment-helper-2.1.0.json"

# SHA-256 of every helper 2.0.0 artifact at protected primary
# 7ba182229560927dd9cb20ce9db82aa302e47255. The successor must never edit them.
FROZEN_2_0_0 = {
    "scripts/wpcaregrid-deploy-production-priv.py":
        "dde7ee7370e4968284cc10fed8dae8cf33786d93830f9f65c7157e19bbf572a1",
    "scripts/deployment_backend_v2.py":
        "d1cbde99eef1168e42797268ce170e451b28051d7d980876a6167fb19bf85576",
    "scripts/deployment_transaction_v2.py":
        "ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00",
    "scripts/deployment_environment_contract.py":
        "f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1",
    "scripts/production_deploy_controller.py":
        "a94865d3e6318c7278b6d72e797fc94d5274faeca0ea2073ecc84fad74166283",
    "scripts/install_production_helper_2_0_0.py":
        "c5c19f0655cfae5f4747264c13c988f7360f3322be32a54b5e1961d538c2bf9d",
    "deploy/sudoers/wpcaregrid-production-v2.0.0":
        "fb7716132c9d48a6a55c305ecff932a1bd518a575e7dd974162bc0d4f89fc963",
    "deploy/release-manifests/production-deployment-helper-2.0.0.json":
        "4a29947b547fae8c0c512562dfe502dd4221a71afac031fffd63c5c5750c4283",
    "docs/PRODUCTION_HELPER_INSTALL_2_0_0.md":
        "f09958bb9060f35163bf1317a9d36a692b96eac8a7255615a5003186c5964d40",
}


def committed_bytes(path: str) -> bytes:
    """Read the committed blob so line-ending conversion cannot falsify pins."""
    return subprocess.run(["git", "cat-file", "blob", f"HEAD:{path}"], cwd=ROOT, check=True,
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


class FrozenLegacyHelperTests(unittest.TestCase):
    def test_helper_2_0_0_artifacts_remain_byte_identical(self):
        for path, expected in FROZEN_2_0_0.items():
            with self.subTest(path=path):
                self.assertEqual(sha256(committed_bytes(path)), expected)
                self.assertEqual(sha256((ROOT / path).read_bytes()), expected)

    def test_helper_2_0_0_still_carries_the_legacy_v2_admission(self):
        # Documents what is superseded, so nobody "fixes" 2.0.0 in place.
        source = (ROOT / "scripts/deployment_backend_v2.py").read_text(encoding="utf-8")
        self.assertIn("WPCG_RELEASE_REVIEW_V2", source)
        self.assertIn("_verify_independent_release_reviews", source)
        self.assertEqual(BACKEND_V2_0_0.HELPER_VERSION, "2.0.0")

    def test_successor_never_reuses_a_2_0_0_install_name(self):
        self.assertEqual(ENTRY.ENTRYPOINT.parent.name, "2.1.0")
        for path in (ENTRY.ENTRYPOINT, ENTRY.POLICY, INSTALLER.SUDOERS, INSTALLER.SOURCE_DIR):
            self.assertNotIn("2.0.0", str(path))
        self.assertEqual(ENTRY.ENTRYPOINT, INSTALLER.ENTRYPOINT)
        self.assertEqual(ENTRY.POLICY, INSTALLER.POLICY)


class ManifestAndPinTests(unittest.TestCase):
    def setUp(self):
        self.manifest = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))

    def test_manifest_matches_exact_committed_bytes(self):
        m = self.manifest
        self.assertEqual((m["schema"], m["profile"], m["helper_version"]), (1, "production", "2.1.0"))
        self.assertEqual(m["supersedes_helper_version"], "2.0.0")
        self.assertEqual(m["release_review_policy"], "V3")
        self.assertEqual(m["compatibility_contract"], "wpcaregrid-deployment-profile-v2.1")
        pins = {
            "entrypoint": "entrypoint_sha256", "backend": "backend_sha256",
            "transaction_module": "transaction_module_sha256",
            "environment_contract": "environment_contract_sha256",
            "release_review_policy_module": "release_review_policy_sha256",
            "controller": "controller_sha256", "sudoers_template": "sudoers_template_sha256",
            "root_installer": "root_installer_sha256",
        }
        for path_key, digest_key in pins.items():
            with self.subTest(path_key):
                self.assertEqual(m[digest_key], sha256(committed_bytes(m[path_key])))
        material = {"schema": 1, "helper_version": m["helper_version"],
                    "compatibility_contract": m["compatibility_contract"],
                    **{key: m[key] for key in (
                        "entrypoint_sha256", "backend_sha256", "transaction_module_sha256",
                        "environment_contract_sha256", "release_review_policy_sha256")}}
        digest = sha256(json.dumps(material, sort_keys=True, separators=(",", ":")).encode())
        self.assertEqual(m["helper_artifact_sha256"], digest)
        self.assertEqual(m["phase_b_default"], "DISABLED")
        self.assertEqual(m["production_activation"],
                         "BLOCKED_UNTIL_APPROVED_ENVIRONMENT_POLICY_AND_RECOVERY_EVIDENCE")

    def test_manifest_shares_only_the_unchanged_transaction_and_environment_modules(self):
        m = self.manifest
        old = json.loads((ROOT / "deploy/release-manifests/production-deployment-helper-2.0.0.json").read_text())
        self.assertEqual(m["transaction_module_sha256"], old["transaction_module_sha256"])
        self.assertEqual(m["environment_contract_sha256"], old["environment_contract_sha256"])
        self.assertNotEqual(m["entrypoint_sha256"], old["entrypoint_sha256"])
        self.assertNotEqual(m["backend_sha256"], old["backend_sha256"])
        self.assertNotEqual(m["helper_artifact_sha256"], old["helper_artifact_sha256"])
        self.assertNotEqual(m["controller_sha256"], old["controller_sha256"])

    def test_controller_and_installer_pin_the_manifest_values(self):
        m = self.manifest
        controller = committed_bytes(m["controller"]).decode("utf-8")
        for constant, value in (("HELPER_ARTIFACT_SHA256", m["helper_artifact_sha256"]),
                                ("HELPER_VERSION", "2.1.0"),
                                ("HELPER_CONTRACT", m["compatibility_contract"])):
            self.assertRegex(controller, rf'(?m)^{constant} = "{re.escape(value)}"$')
        self.assertIn('/production/2.1.0/wpcaregrid-deploy-production-priv', controller)
        self.assertNotIn("2.0.0", controller.split('"""', 2)[2])
        installer = committed_bytes(m["root_installer"]).decode("utf-8")
        for constant, value in {
            "ENTRY_SHA256": m["entrypoint_sha256"], "BACKEND_SHA256": m["backend_sha256"],
            "TRANSACTION_SHA256": m["transaction_module_sha256"],
            "ENVIRONMENT_SHA256": m["environment_contract_sha256"],
            "REVIEW_POLICY_SHA256": m["release_review_policy_sha256"],
            "HELPER_ARTIFACT_SHA256": m["helper_artifact_sha256"],
            "CONTROLLER_SHA256": m["controller_sha256"], "SUDOERS_SHA256": m["sudoers_template_sha256"],
        }.items():
            self.assertRegex(installer, rf'(?m)^{constant} = "{re.escape(value)}"$')
        self.assertEqual(INSTALLER.VERSION, "2.1.0")
        self.assertEqual(INSTALLER.CONTRACT, m["compatibility_contract"])
        self.assertEqual(ENTRY.VERSION, "2.1.0")
        self.assertEqual(ENTRY.CONTRACT, m["compatibility_contract"])
        self.assertEqual(BACKEND.HELPER_VERSION, "2.1.0")
        self.assertEqual(BACKEND.HELPER_CONTRACT, m["compatibility_contract"])
        self.assertEqual(CONTROLLER.HELPER, ENTRY.ENTRYPOINT)

    def test_installer_source_set_is_the_manifest_artifact_set(self):
        m = self.manifest
        self.assertEqual(set(INSTALLER.SOURCE_HASHES), {
            Path(m["entrypoint"]).name, Path(m["backend"]).name,
            Path(m["transaction_module"]).name, Path(m["environment_contract"]).name,
            Path(m["release_review_policy_module"]).name, Path(m["controller"]).name,
            Path(m["sudoers_template"]).name,
        })
        for name, digest in INSTALLER.SOURCE_HASHES.items():
            self.assertRegex(digest, r"[0-9a-f]{64}")
        self.assertEqual(INSTALLER.REVIEW_POLICY.name, "release_review_policy_v3.py")
        self.assertIn(INSTALLER.REVIEW_POLICY, INSTALLER._installation_targets())

    def test_runbook_lists_every_pinned_digest(self):
        m = self.manifest
        doc = (ROOT / "docs/PRODUCTION_HELPER_INSTALL_2_1_0.md").read_text(encoding="utf-8")
        for key in ("entrypoint_sha256", "backend_sha256", "transaction_module_sha256",
                    "environment_contract_sha256", "release_review_policy_sha256",
                    "helper_artifact_sha256", "controller_sha256", "sudoers_template_sha256",
                    "root_installer_sha256"):
            self.assertIn(f"`{m[key]}`", doc, key)
        self.assertIn("PREPARATION ONLY", doc)
        self.assertIn(POLICY.RECORD_PREFIX, doc)
        self.assertIn("7ba182229560927dd9cb20ce9db82aa302e47255", doc)

    def test_sudoers_is_five_fixed_operations_on_the_versioned_entrypoint(self):
        raw = (ROOT / "deploy/sudoers/wpcaregrid-production-v2.1.0").read_bytes()
        INSTALLER._verify_sudoers_source(raw)
        rules = [line for line in raw.decode("ascii").splitlines()
                 if line.strip() and not line.lstrip().startswith("#")]
        self.assertEqual(len(rules), 5)
        self.assertTrue(all(str(ENTRY.ENTRYPOINT) in line and "*" not in line
                            and "staging" not in line and "2.0.0" not in line for line in rules))
        self.assertEqual(sorted(INSTALLER.OPERATIONS), sorted(ENTRY.OPERATIONS))
        self.assertEqual(ENTRY.OPERATIONS, ["authorize", "deploy", "preflight", "stage", "verify-install"])


class InstallerTests(unittest.TestCase):
    def test_disabled_policy_schema_includes_the_review_policy_pin(self):
        policy = json.loads(INSTALLER._disabled_policy().decode("utf-8"))
        self.assertEqual(set(policy), INSTALLER.POLICY_KEYS)
        self.assertIn("release_review_policy_sha256", policy)
        self.assertEqual(policy["release_review_policy_sha256"], INSTALLER.REVIEW_POLICY_SHA256)
        self.assertIs(policy["enabled"], False)
        self.assertIsNone(policy["phase_b_policy_sha256"])
        self.assertTrue(all(policy[key] is None for key in INSTALLER.ENVIRONMENT_FIELDS))

    def test_helper_policy_schema_equals_installer_policy_schema(self):
        helper_source = (ROOT / "scripts/wpcaregrid-deploy-production-priv-2_1_0.py").read_text()
        for key in INSTALLER.POLICY_KEYS - set(INSTALLER.ENVIRONMENT_FIELDS) - {"schema", "profile", "enabled"}:
            self.assertIn(f'"{key}"', helper_source, key)

    def test_legacy_2_0_0_helper_presence_refuses_installation(self):
        for legacy in INSTALLER.LEGACY_2_0_0_PATHS:
            with self.subTest(legacy=str(legacy)):
                real_lstat = INSTALLER.os.lstat

                def lstat(path, _legacy=legacy, _real=real_lstat):
                    if Path(path) == _legacy:
                        return object()
                    raise FileNotFoundError(path)

                with patch.object(INSTALLER.os, "lstat", side_effect=lstat), \
                        self.assertRaises(INSTALLER.InstallError) as error:
                    INSTALLER._check_legacy_helper_absent()
                self.assertEqual(str(error.exception), "legacy_production_helper_2_0_0_present")
        with patch.object(INSTALLER.os, "lstat", side_effect=FileNotFoundError):
            INSTALLER._check_legacy_helper_absent()

    def test_complete_installation_is_read_only_no_op_and_partial_fails_closed(self):
        receipt = {"result": "NO_OP"}
        with patch.object(INSTALLER, "_existing_installation_state", return_value="COMPLETE"), \
                patch.object(INSTALLER, "_verify_existing_install", return_value=receipt), \
                patch.object(INSTALLER, "_preflight", side_effect=AssertionError), \
                patch.object(INSTALLER, "_publish", side_effect=AssertionError):
            self.assertEqual(INSTALLER.install(effective_uid=0), receipt)
        with patch.object(INSTALLER, "_existing_installation_state", return_value="PARTIAL"), \
                patch.object(INSTALLER, "_publish", side_effect=AssertionError):
            with self.assertRaises(INSTALLER.InstallError) as error:
                INSTALLER.install(effective_uid=0)
        self.assertEqual(str(error.exception), "partial_installation_detected")

    def test_installer_requires_root_and_is_not_a_helper_operation(self):
        with self.assertRaises(INSTALLER.InstallError) as error:
            INSTALLER.install(effective_uid=1000)
        self.assertEqual(str(error.exception), "root_required")
        source = (ROOT / "scripts/install_production_helper_2_1_0.py").read_text()
        for operation in ("authorize", "stage", "deploy"):
            self.assertNotIn(f'"{operation}"]', source.replace(
                'OPERATIONS = ("authorize", "deploy", "preflight", "stage", "verify-install")', ""))

    def test_sudo_listing_must_be_exactly_the_five_2_1_0_operations(self):
        prefix = f"(root) NOPASSWD: NOSETENV: {INSTALLER.ENTRYPOINT} "
        output = "\n".join(prefix + operation for operation in INSTALLER.OPERATIONS).encode()
        with patch.object(INSTALLER, "_run", return_value=SimpleNamespace(
                returncode=0, stdout=output, stderr=b"")):
            INSTALLER._verify_installed_sudoers("deploy-production", lambda *_a, **_k: None)
        legacy = output + b"\n(root) NOPASSWD: NOSETENV: " \
            b"/usr/local/libexec/wpcaregrid-deploy/production/2.0.0/wpcaregrid-deploy-production-priv preflight"
        with patch.object(INSTALLER, "_run", return_value=SimpleNamespace(
                returncode=0, stdout=legacy, stderr=b"")):
            with self.assertRaises(INSTALLER.InstallError):
                INSTALLER._verify_installed_sudoers("deploy-production", lambda *_a, **_k: None)


class SuccessorDifferenceTests(unittest.TestCase):
    """The successor backend/controller differ from 2.0.0 only where intended."""

    @staticmethod
    def top_level(path: str) -> dict[str, str]:
        tree = ast.parse((ROOT / path).read_text(encoding="utf-8"))
        items = {}
        for index, node in enumerate(tree.body):
            if isinstance(node, (ast.FunctionDef, ast.ClassDef)):
                key = node.name
            elif isinstance(node, ast.Assign) and isinstance(node.targets[0], ast.Name):
                key = node.targets[0].id
            else:
                key = f"__{index}_{type(node).__name__}"
            items[key] = ast.dump(node)
        return items

    def test_backend_changes_are_limited_to_review_admission_and_identity(self):
        old = self.top_level("scripts/deployment_backend_v2.py")
        new = self.top_level("scripts/deployment_backend_v2_1_0.py")
        removed = {name for name in old if name not in new}
        self.assertEqual(removed, {"RELEASE_REVIEW_RECORD_RE", "_release_review_records",
                                   "_verify_independent_release_reviews"})
        added = {name for name in new if name not in old}
        self.assertEqual(added, {
            "_REVIEW_POLICY_PATH", "_REVIEW_POLICY_MODULE_NAME", "_REVIEW_POLICY_MODULE",
            "ReviewRefused", "verify_agent_release_reviews",
            "RELEASE_REVIEW_LEDGER_PAGE_SIZE", "RELEASE_REVIEW_LEDGER_MAX_COMMENTS",
            "RELEASE_REVIEW_LEDGER_MAX_PAGE_BYTES",
            "RELEASE_REVIEW_REFUSAL_CODES", "_github_release_review_ledger",
            "_verify_agent_release_reviews_v3", "REVIEW_ARTIFACT_PREFIX",
            "REVIEW_ARTIFACT_RE", "MAX_REVIEW_REPORT_BYTES",
            "_verify_review_report_artifacts",
        } | {key for key in new if key.startswith("__") and key not in old})
        changed = {name for name in old if name in new and old[name] != new[name]
                   and not name.startswith("__")}
        self.assertEqual(changed, {"HELPER_VERSION", "HELPER_CONTRACT", "_github_json",
                                   "_verify_primary_ci_and_reviews", "authorize_release"})

    def test_backend_has_no_v2_or_github_review_object_dependency(self):
        source = (ROOT / "scripts/deployment_backend_v2_1_0.py").read_text(encoding="utf-8")
        body = source.split('"""', 2)[2]  # the module docstring describes what was removed
        for forbidden in ("WPCG_RELEASE_REVIEW_V2", '"APPROVED"', "/reviews", "pr_author",
                          "m17_appsec", "review_id"):
            self.assertNotIn(forbidden, body, forbidden)
        self.assertIn("verify_agent_release_reviews", body)

    def test_controller_differs_from_2_0_0_only_in_pins_and_docstring(self):
        old = (ROOT / "scripts/production_deploy_controller.py").read_text(encoding="utf-8")
        new = (ROOT / "scripts/production_deploy_controller_2_1_0.py").read_text(encoding="utf-8")
        old_lines, new_lines = old.splitlines(), new.splitlines()
        changed = [line for line in new_lines if line not in old_lines]
        self.assertTrue(any(line.startswith("HELPER_ARTIFACT_SHA256") for line in changed))
        allowed = ("HELPER =", "HELPER_ARTIFACT_SHA256", "HELPER_VERSION", "HELPER_CONTRACT",
                   '"""Single-target', "Successor of", "re-pins", "uses release-review",
                   "and packages", '"""')
        for line in changed:
            self.assertTrue(line.startswith(allowed) or line.strip() == '"""', line)
        self.assertEqual(len(old_lines) + 5, len(new_lines))

    def test_installer_keeps_the_2_0_0_publication_safety_functions(self):
        old = ast.parse((ROOT / "scripts/install_production_helper_2_0_0.py").read_text())
        new = ast.parse((ROOT / "scripts/install_production_helper_2_1_0.py").read_text())
        old_f = {n.name: ast.dump(n) for n in old.body if isinstance(n, ast.FunctionDef)}
        new_f = {n.name: ast.dump(n) for n in new.body if isinstance(n, ast.FunctionDef)}
        changed = {name for name in old_f if old_f[name] != new_f.get(name)}
        self.assertEqual(changed, {"_validate_sudoers", "_disabled_policy", "_installation_targets",
                                   "_verify_existing_install", "_preflight", "install"})
        self.assertEqual(set(new_f) - set(old_f), {"_check_legacy_helper_absent"})
        for name in ("_publish", "_rollback", "_read_source", "_hash_path", "_check_directory",
                     "_verify_installed_sudoers", "_negative_authorization_tests",
                     "_verify_sudoers_source", "_check_clean_privilege_baseline"):
            self.assertEqual(old_f[name], new_f[name], name)


class EntrypointTests(unittest.TestCase):
    def test_entrypoint_authorization_checks_are_the_2_0_0_checks(self):
        old = _load("production_helper_2_0_0_entry_for_diff", "scripts/wpcaregrid-deploy-production-priv.py")
        for name in ("_read_trusted", "_trusted_parent", "_environment_config", "_parse_operation"):
            self.assertEqual(ast.dump(ast.parse(__import__("inspect").getsource(getattr(old, name)))),
                             ast.dump(ast.parse(__import__("inspect").getsource(getattr(ENTRY, name)))), name)
        self.assertEqual(old.OPERATIONS, ENTRY.OPERATIONS)
        for source in (ROOT / "scripts/wpcaregrid-deploy-production-priv-2_1_0.py",):
            text = source.read_text()
            for guard in ('sys.argv[0] != str(ENTRYPOINT)', 'deploy_user != "deploy-production"',
                          'os.geteuid() != 0', 'pwd.getpwuid(uid).pw_name != deploy_user',
                          'production_artifact_identity_mismatch', 'production_helper_manifest_mismatch',
                          'production_phase_b_policy_unpinned', 'sudo_uid != str(uid)'):
                self.assertIn(guard, text, guard)

    def test_policy_module_bytes_are_registered_and_backend_is_bound_to_them(self):
        raw = (ROOT / "scripts/release_review_policy_v3.py").read_bytes()
        name = "_wpcg_release_review_policy_v3"
        saved = sys.modules.get(name)
        try:
            review = ENTRY._load_review_policy(raw)
            self.assertIs(sys.modules[name], review)
            backend_raw = (ROOT / "scripts/deployment_backend_v2_1_0.py").read_bytes()
            transaction_raw = (ROOT / "scripts/deployment_transaction_v2.py").read_bytes()
            ENTRY._load_transaction(transaction_raw)
            backend = ENTRY._load_backend(backend_raw, review)
            self.assertIs(backend._REVIEW_POLICY_MODULE, review)
            # A backend bound to some other (e.g. disk-loaded) policy is refused.
            other = types.ModuleType(name)
            other.__dict__.update(review.__dict__)
            with self.assertRaisesRegex(ENTRY.Refused, "release_review_policy_binding_invalid"):
                ENTRY._load_backend(backend_raw, other)
        finally:
            if saved is None:
                sys.modules.pop(name, None)
            else:
                sys.modules[name] = saved

    def test_tampered_or_wrong_policy_bytes_are_refused(self):
        name = "_wpcg_release_review_policy_v3"
        saved = sys.modules.get(name)
        try:
            with self.assertRaisesRegex(ENTRY.Refused, "release_review_policy_load_failed"):
                ENTRY._load_review_policy(b"raise RuntimeError('boom')\n")
            self.assertNotIn(name, sys.modules)
            with self.assertRaisesRegex(ENTRY.Refused, "release_review_policy_contract_invalid"):
                ENTRY._load_review_policy(b"RECORD_PREFIX='WPCG_RELEASE_REVIEW_V2'\n")
            raw = (ROOT / "scripts/release_review_policy_v3.py").read_bytes().replace(
                b'ROLES = ("integration_qa", "appsec")', b'ROLES = ("integration_qa",)')
            with self.assertRaisesRegex(ENTRY.Refused, "release_review_policy_contract_invalid"):
                ENTRY._load_review_policy(raw)
        finally:
            if saved is None:
                sys.modules.pop(name, None)
            else:
                sys.modules[name] = saved

    def test_profile_is_refused_when_the_review_policy_pin_does_not_match(self):
        """Drive _load_profile with trusted-read stubs: a changed policy file is refused."""
        policy_raw = (ROOT / "scripts/release_review_policy_v3.py").read_bytes()
        files = {
            ENTRY.ENTRYPOINT: (ROOT / "scripts/wpcaregrid-deploy-production-priv-2_1_0.py").read_bytes(),
            ENTRY.BACKEND: (ROOT / "scripts/deployment_backend_v2_1_0.py").read_bytes(),
            ENTRY.TRANSACTION: (ROOT / "scripts/deployment_transaction_v2.py").read_bytes(),
            ENTRY.ENVIRONMENT_CONTRACT: (ROOT / "scripts/deployment_environment_contract.py").read_bytes(),
            ENTRY.REVIEW_POLICY: policy_raw,
        }
        env_module = _load("env_contract_for_2_1_0_test", "scripts/deployment_environment_contract.py")
        fields = sorted(env_module.production_required_fields())
        enabled_policy = {key: None for key in fields}
        manifest = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
        enabled_policy.update(
            schema=1, profile="production", enabled=True, operations=list(ENTRY.OPERATIONS),
            entrypoint_sha256=manifest["entrypoint_sha256"], backend_sha256=manifest["backend_sha256"],
            transaction_module_sha256=manifest["transaction_module_sha256"],
            environment_contract_sha256=manifest["environment_contract_sha256"],
            release_review_policy_sha256=manifest["release_review_policy_sha256"],
            helper_artifact_sha256=manifest["helper_artifact_sha256"],
            phase_b_policy_sha256="a" * 64)

        def run(review_raw: bytes, policy: dict):
            served = dict(files)
            served[ENTRY.REVIEW_POLICY] = review_raw
            served[ENTRY.POLICY] = json.dumps(policy).encode()

            def read_trusted(path, *, mode, maximum):
                return served[Path(path)]

            with patch.object(ENTRY, "_read_trusted", side_effect=read_trusted), \
                    patch.object(ENTRY, "_trusted_parent"), \
                    patch.object(ENTRY.sys, "argv", [str(ENTRY.ENTRYPOINT), "preflight"]):
                return ENTRY._load_profile(["preflight"], "1000")

        # Policy file missing the new pin key -> exact-schema refusal.
        legacy_schema = {k: v for k, v in enabled_policy.items() if k != "release_review_policy_sha256"}
        with self.assertRaisesRegex(ENTRY.Refused, "production_policy_schema_invalid"):
            run(policy_raw, legacy_schema)
        # Review-policy bytes that differ from the pinned digest -> identity mismatch.
        with self.assertRaisesRegex(ENTRY.Refused, "production_artifact_identity_mismatch"):
            run(policy_raw + b"\n# changed\n", enabled_policy)
        # Pin matches but the combined artifact digest does not -> manifest mismatch.
        wrong = dict(enabled_policy, helper_artifact_sha256="0" * 64)
        with self.assertRaisesRegex(ENTRY.Refused, "production_helper_manifest_mismatch"):
            run(policy_raw, wrong)


# --------------------------------------------------------------------------
# V3 admission behaviour through the backend's real admission function.
# --------------------------------------------------------------------------

OWNER = "zsoltacsai"
PRIMARY = "claude/ecstatic-wozniak-oemyuf"
APP_SHA = "a" * 40
HEAD_SHA = "b" * 40
OTHER_HEAD = "c" * 40
PR = 412
BASE_SHA = "e" * 40
REPORT_BYTES = b"synthetic review report bytes\n"
EVIDENCE = hashlib.sha256(REPORT_BYTES).hexdigest()
REPOSITORY_PATH = "/repos/zsoltacsai/wpcaregrid"
LEDGER_PATH = f"{REPOSITORY_PATH}/issues/174"


def review(role, *, head=HEAD_SHA, pr=PR, agent=None, decision="PASS", comment_id=None,
           created="2026-11-15T10:00:00Z", login=OWNER, association="OWNER", body=None):
    agent = agent or ("qa-agent-one" if role == "integration_qa" else "appsec-agent-one")
    comment_id = comment_id if comment_id is not None else review.next_id
    review.next_id += 1
    return {"id": comment_id, "created_at": created, "author_association": association,
            "user": {"login": login},
            "body": body if body is not None else POLICY.render_record(
                role=role, pr=pr, head_sha=head, agent=agent, decision=decision,
                evidence_sha256=EVIDENCE)}


review.next_id = 9_000_000


def review_artifact(role, *, head=HEAD_SHA, pr=PR, agent=None, report=REPORT_BYTES,
                    digest=None, comment_id=None, created="2026-11-15T10:01:00Z",
                    login=OWNER, association="OWNER"):
    agent = agent or ("qa-agent-one" if role == "integration_qa" else "appsec-agent-one")
    digest = digest or hashlib.sha256(report).hexdigest()
    body = (f"{BACKEND.REVIEW_ARTIFACT_PREFIX}\nrole={role}\npr={pr}\nhead_sha={head}\n"
            f"base_sha={BASE_SHA}\nagent={agent}\nreport_sha256={digest}\n"
            f"report_base64={base64.b64encode(report).decode('ascii')}\n")
    comment_id = comment_id if comment_id is not None else review_artifact.next_id
    review_artifact.next_id += 1
    return {"id": comment_id, "created_at": created, "author_association": association,
            "user": {"login": login}, "body": body}


review_artifact.next_id = 9_100_000


def v2_record(role, *, head=HEAD_SHA, reviewer="someone", review_id=77):
    comment_id = review.next_id
    review.next_id += 1
    return {"id": comment_id, "created_at": "2026-11-15T09:00:00Z",
            "author_association": "OWNER", "user": {"login": OWNER},
            "body": f"WPCG_RELEASE_REVIEW_V2\nrole={role}\npr={PR}\nhead_sha={head}\n"
                    f"reviewer={reviewer}\nreview_id={review_id}\ndecision=PASS\n"}


class FakeGitHub:
    """Synthetic GitHub: serves a merged PR, green CI and a release ledger."""

    def __init__(self, ledger, *, head=HEAD_SHA, ledger_count=None, ci_ok=True):
        self.ledger = list(ledger)
        self.head = head
        self.ledger_count = ledger_count
        self.ci_ok = ci_ok
        self.paths = []
        self.count_reads = 0
        self.after_first_count = None

    def jobs(self, sha):
        return [{"id": index + 1, "name": name, "status": "completed",
                 "conclusion": "success" if self.ci_ok else "failure"}
                for index, name in enumerate(BACKEND.REQUIRED_CI_JOBS)]

    def __call__(self, path, **_kwargs):
        self.paths.append(path)
        route = path.split("?", 1)[0]
        query = dict(part.split("=", 1) for part in path.split("?", 1)[1].split("&")) if "?" in path else {}
        if route == f"{REPOSITORY_PATH}/commits/{APP_SHA}/pulls":
            return [{"number": PR, "merge_commit_sha": APP_SHA, "base": {"ref": PRIMARY}}]
        if route == f"{REPOSITORY_PATH}/pulls/{PR}":
            return {"merged_at": "2026-11-15T11:00:00Z", "base": {"ref": PRIMARY},
                    "head": {"sha": self.head}, "user": {"login": "any-author"}}
        if route == f"{REPOSITORY_PATH}/commits/{APP_SHA}":
            return {"parents": [{"sha": BASE_SHA}]}
        if route == f"{REPOSITORY_PATH}/actions/runs":
            sha, event = query["head_sha"], query["event"]
            run = {"id": 100 if event == "pull_request" else 200, "head_sha": sha, "event": event,
                   "path": ".github/workflows/ci.yml", "status": "completed",
                   "conclusion": "success", "created_at": "2026-11-15T10:30:00Z",
                   "head_branch": "feature" if event == "pull_request" else PRIMARY,
                   "pull_requests": [{"number": PR, "base": {"ref": PRIMARY}}]}
            return {"workflow_runs": [run], "total_count": 1}
        if route.startswith(f"{REPOSITORY_PATH}/actions/runs/") and route.endswith("/jobs"):
            jobs = self.jobs(None)
            return {"jobs": jobs, "total_count": len(jobs)}
        if route == REPOSITORY_PATH:
            return {"owner": {"login": OWNER}}
        if route == LEDGER_PATH:
            self.count_reads += 1
            if self.count_reads > 1 and self.after_first_count is not None:
                return {"comments": self.after_first_count}
            return {"comments": len(self.ledger) if self.ledger_count is None else self.ledger_count}
        if route == f"{LEDGER_PATH}/comments":
            size, page = int(query["per_page"]), int(query["page"])
            return self.ledger[(page - 1) * size:page * size]
        raise AssertionError(f"unexpected GitHub path {path}")


class V3AdmissionTests(unittest.TestCase):
    PROFILE = SimpleNamespace(test_only=False)

    def admit(self, ledger, **kwargs):
        ledger = self.with_artifacts(ledger)
        fake = FakeGitHub(ledger, **kwargs)
        with patch.object(BACKEND, "_github_json", side_effect=fake):
            BACKEND._verify_primary_ci_and_reviews(self.PROFILE, APP_SHA)
        return fake

    def refusal(self, ledger, **kwargs):
        ledger = self.with_artifacts(ledger)
        fake = FakeGitHub(ledger, **kwargs)
        with patch.object(BACKEND, "_github_json", side_effect=fake), \
                self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_primary_ci_and_reviews(self.PROFILE, APP_SHA)
        return str(error.exception)

    def passing_ledger(self):
        return [review("integration_qa"), review("appsec")]

    @staticmethod
    def with_artifacts(ledger):
        result = list(ledger)
        for comment in ledger:
            if not isinstance(comment, dict) or not isinstance(comment.get("body"), str):
                continue
            match = POLICY._RECORD.fullmatch(comment["body"])
            if match is None:
                continue
            role, raw_pr, head, agent, _kind, _decision, _digest = match.groups()
            result.append(review_artifact(role, head=head, pr=int(raw_pr), agent=agent,
                                          created=comment["created_at"]))
        return result

    def test_valid_owner_qa_and_appsec_pass_records_admit_and_no_review_object_is_read(self):
        fake = self.admit(self.passing_ledger())
        self.assertFalse(any("/reviews" in path for path in fake.paths))
        self.assertTrue(any(path.endswith("/issues/174") for path in fake.paths))
        self.assertTrue(any("/issues/174/comments" in path for path in fake.paths))

    def test_review_reports_are_hash_verified_and_bound_to_records(self):
        ledger = self.passing_ledger()
        records = POLICY.verify_agent_release_reviews(
            ledger, pr_number=PR, head_sha=HEAD_SHA, repository_owner=OWNER)
        valid = [review_artifact("integration_qa"), review_artifact("appsec")]
        BACKEND._verify_review_report_artifacts(
            valid, records=records, pr_number=PR, head_sha=HEAD_SHA,
            base_sha=BASE_SHA, repository_owner=OWNER)
        bad_hash = [review_artifact("integration_qa", digest="0" * 64), valid[1]]
        with self.assertRaisesRegex(BACKEND.DeployRefused, "review_artifact_hash_mismatch"):
            BACKEND._verify_review_report_artifacts(
                bad_hash, records=records, pr_number=PR, head_sha=HEAD_SHA,
                base_sha=BASE_SHA, repository_owner=OWNER)
        wrong_report = [review_artifact("integration_qa", report=b"other report"), valid[1]]
        with self.assertRaisesRegex(BACKEND.DeployRefused, "review_artifact_record_mismatch"):
            BACKEND._verify_review_report_artifacts(
                wrong_report, records=records, pr_number=PR, head_sha=HEAD_SHA,
                base_sha=BASE_SHA, repository_owner=OWNER)
        with self.assertRaisesRegex(BACKEND.DeployRefused, "review_artifact_missing"):
            BACKEND._verify_review_report_artifacts(
                valid[:1], records=records, pr_number=PR, head_sha=HEAD_SHA,
                base_sha=BASE_SHA, repository_owner=OWNER)

    def test_legacy_v2_ledger_and_github_approvals_no_longer_admit(self):
        ledger = [v2_record("integration_qa", reviewer="r1"), v2_record("m17_appsec", reviewer="r2")]
        self.assertEqual(self.refusal(ledger), "review_evidence_missing")

    def test_v2_records_do_not_satisfy_v3_even_beside_one_v3_record(self):
        ledger = [v2_record("integration_qa"), v2_record("m17_appsec"), review("integration_qa")]
        self.assertEqual(self.refusal(ledger), "review_evidence_missing")

    def test_missing_role_is_refused(self):
        self.assertEqual(self.refusal([review("integration_qa")]), "review_evidence_missing")
        self.assertEqual(self.refusal([review("appsec")]), "review_evidence_missing")
        self.assertEqual(self.refusal([]), "review_evidence_missing")

    def test_stale_head_or_other_pr_evidence_is_never_reused(self):
        stale = [review("integration_qa", head=OTHER_HEAD), review("appsec", head=OTHER_HEAD)]
        self.assertEqual(self.refusal(stale), "review_evidence_missing")
        other_pr = [review("integration_qa", pr=PR + 1), review("appsec", pr=PR + 1)]
        self.assertEqual(self.refusal(other_pr), "review_evidence_missing")
        half = [review("integration_qa"), review("appsec", head=OTHER_HEAD)]
        self.assertEqual(self.refusal(half), "review_evidence_missing")

    def test_the_exact_pr_head_from_github_not_the_merge_commit_is_the_evidence_subject(self):
        merge_bound = [review("integration_qa", head=APP_SHA), review("appsec", head=APP_SHA)]
        self.assertEqual(self.refusal(merge_bound), "review_evidence_missing")

    def test_new_pr_head_invalidates_earlier_pass_records(self):
        old_pass = self.passing_ledger()
        self.assertEqual(self.refusal(old_pass, head=OTHER_HEAD), "review_evidence_missing")

    def test_agent_labels_must_be_distinct(self):
        ledger = [review("integration_qa", agent="shared-agent"), review("appsec", agent="shared-agent")]
        self.assertEqual(self.refusal(ledger), "review_roles_not_separate")

    def test_latest_non_pass_record_supersedes_an_earlier_pass(self):
        for decision in ("CHANGES_REQUIRED", "BLOCKED"):
            ledger = self.passing_ledger() + [review("appsec", decision=decision,
                                                     created="2026-11-15T12:00:00Z")]
            with self.subTest(decision=decision):
                self.assertEqual(self.refusal(ledger), "review_not_pass")

    def test_a_later_pass_can_restore_a_role_after_a_non_pass(self):
        ledger = [review("integration_qa"), review("appsec", decision="BLOCKED"),
                  review("appsec", created="2026-11-15T12:00:00Z")]
        self.admit(ledger)

    def test_malformed_owner_record_fails_closed_even_beside_valid_passes(self):
        bad_bodies = [
            POLICY.RECORD_PREFIX + "\nrole=appsec\npr=412\n",
            POLICY.render_record(role="appsec", pr=PR, head_sha=HEAD_SHA, agent="appsec-agent-one",
                                 decision="PASS", evidence_sha256=EVIDENCE).replace(
                                     "agent=appsec-agent-one", "agent=Mad-Eye Moody"),
            POLICY.render_record(role="appsec", pr=PR, head_sha=HEAD_SHA, agent="appsec-agent-one",
                                 decision="PASS", evidence_sha256=EVIDENCE).replace(
                                     "reviewer_kind=ai_agent", "reviewer_kind=human"),
        ]
        for body in bad_bodies:
            with self.subTest(body=body[:50]):
                ledger = self.passing_ledger() + [review("appsec", body=body)]
                self.assertEqual(self.refusal(ledger), "review_record_invalid")

    def test_owner_record_without_owner_association_fails_closed(self):
        ledger = self.passing_ledger() + [review("appsec", association="CONTRIBUTOR")]
        self.assertEqual(self.refusal(ledger), "review_record_invalid")

    def test_records_by_other_users_never_count_and_cannot_disturb_the_ledger(self):
        forged = [review("integration_qa", login="intruder", association="NONE"),
                  review("appsec", login="intruder", association="NONE")]
        self.assertEqual(self.refusal(forged), "review_evidence_missing")
        malformed_intruder = review("appsec", login="intruder", association="NONE",
                                    body=POLICY.RECORD_PREFIX + "\ngarbage")
        self.admit(self.passing_ledger() + [malformed_intruder])
        blocking_intruder = review("appsec", login="intruder", association="NONE",
                                   decision="BLOCKED", created="2026-11-15T13:00:00Z")
        self.admit(self.passing_ledger() + [blocking_intruder])

    def test_pr_author_and_github_reviews_are_irrelevant(self):
        # The fake PR author is arbitrary and no /reviews endpoint exists on the fake.
        self.admit(self.passing_ledger())

    def test_ci_must_still_pass_for_the_exact_pr_head_and_merge_commit(self):
        self.assertEqual(self.refusal(self.passing_ledger(), ci_ok=False), "required_ci_check_failed")

    def test_policy_exceptions_are_mapped_to_fixed_codes(self):
        with patch.object(BACKEND, "verify_agent_release_reviews", side_effect=RuntimeError("secret")):
            with self.assertRaisesRegex(BACKEND.DeployRefused, "^review_evidence_invalid$"):
                BACKEND._verify_agent_release_reviews_v3([], pr_number=PR, head_sha=HEAD_SHA,
                                                         repository_owner=OWNER)
        with patch.object(BACKEND, "verify_agent_release_reviews",
                          side_effect=BACKEND.ReviewRefused("free text with detail")):
            with self.assertRaisesRegex(BACKEND.DeployRefused, "^review_evidence_invalid$"):
                BACKEND._verify_agent_release_reviews_v3([], pr_number=PR, head_sha=HEAD_SHA,
                                                         repository_owner=OWNER)

    def test_implausible_policy_result_is_not_trusted(self):
        records = {"integration_qa": {"decision": "PASS", "pr": PR, "head_sha": HEAD_SHA,
                                      "reviewer_kind": "ai_agent", "agent": "same-agent"},
                   "appsec": {"decision": "PASS", "pr": PR, "head_sha": HEAD_SHA,
                              "reviewer_kind": "ai_agent", "agent": "same-agent"}}
        with patch.object(BACKEND, "verify_agent_release_reviews", return_value=records), \
                self.assertRaisesRegex(BACKEND.DeployRefused, "^review_evidence_invalid$"):
            BACKEND._verify_agent_release_reviews_v3([], pr_number=PR, head_sha=HEAD_SHA,
                                                     repository_owner=OWNER)
        records["appsec"]["agent"] = "other-agent"
        records["appsec"]["decision"] = "BLOCKED"
        with patch.object(BACKEND, "verify_agent_release_reviews", return_value=records), \
                self.assertRaisesRegex(BACKEND.DeployRefused, "^review_evidence_invalid$"):
            BACKEND._verify_agent_release_reviews_v3([], pr_number=PR, head_sha=HEAD_SHA,
                                                     repository_owner=OWNER)

    def test_every_policy_refusal_code_is_allowlisted_and_surfaces_unchanged(self):
        source = (ROOT / "scripts/release_review_policy_v3.py").read_text()
        codes = set(re.findall(r'ReviewRefused\("([a-z_]+)"\)', source))
        self.assertTrue(codes)
        self.assertTrue(codes <= set(BACKEND.RELEASE_REVIEW_REFUSAL_CODES))
        self.assertEqual(set(BACKEND.RELEASE_REVIEW_REFUSAL_CODES) - codes, {
            "review_artifact_invalid", "review_artifact_size_invalid",
            "review_artifact_hash_mismatch", "review_artifact_encoding_invalid",
            "review_artifact_missing", "review_artifact_record_mismatch"})

    def test_helper_blocks_authorize_stage_and_deploy_through_the_same_admission(self):
        source = (ROOT / "scripts/deployment_backend_v2_1_0.py").read_text()
        self.assertEqual(source.count("_verify_primary_ci_and_reviews("), 3)  # def + authorize + artifact check

    def test_test_only_profile_still_skips_github_evidence(self):
        BACKEND._verify_primary_ci_and_reviews(SimpleNamespace(test_only=True), APP_SHA)


class LedgerCompletenessTests(unittest.TestCase):
    def fetch(self, fake):
        with patch.object(BACKEND, "_github_json", side_effect=fake):
            return BACKEND._github_release_review_ledger()

    def test_pages_are_read_completely_in_small_pages(self):
        size = BACKEND.RELEASE_REVIEW_LEDGER_PAGE_SIZE
        comments = [{"id": i} for i in range(1, size * 2 + 7)]
        fake = FakeGitHub(comments)
        self.assertEqual(self.fetch(fake), comments)
        self.assertTrue(all(f"per_page={size}" in p for p in fake.paths if "/comments" in p))
        self.assertEqual(sum("/comments" in p for p in fake.paths), 3)

    def test_exact_page_multiple_terminates(self):
        size = BACKEND.RELEASE_REVIEW_LEDGER_PAGE_SIZE
        comments = [{"id": i} for i in range(1, size * 2 + 1)]
        self.assertEqual(self.fetch(FakeGitHub(comments)), comments)

    def test_truncated_ledger_is_refused(self):
        comments = [{"id": i} for i in range(1, 11)]
        with self.assertRaisesRegex(BACKEND.DeployRefused, "release_review_ledger_changed"):
            self.fetch(FakeGitHub(comments, ledger_count=11))

    def test_ledger_growing_during_read_is_refused(self):
        fake = FakeGitHub([{"id": i} for i in range(1, 11)])
        fake.after_first_count = 11
        with self.assertRaisesRegex(BACKEND.DeployRefused, "release_review_ledger_changed"):
            self.fetch(fake)

    def test_more_comments_than_declared_is_refused(self):
        with self.assertRaisesRegex(BACKEND.DeployRefused, "release_review_ledger_changed"):
            self.fetch(FakeGitHub([{"id": i} for i in range(1, 11)], ledger_count=3))

    def test_oversized_or_invalid_ledger_metadata_is_refused(self):
        for count in (-1, BACKEND.RELEASE_REVIEW_LEDGER_MAX_COMMENTS + 1, "10", None, True):
            def fake(path, _count=count, **_kwargs):
                return {"comments": _count}
            with self.subTest(count=count), self.assertRaisesRegex(
                    BACKEND.DeployRefused, "release_review_ledger_unavailable"):
                self.fetch(fake)
        with self.assertRaisesRegex(BACKEND.DeployRefused, "release_review_ledger_unavailable"):
            self.fetch(lambda path, **_kwargs: [])

    def test_non_list_page_is_refused(self):
        def fake(path, **_kwargs):
            return {"comments": 1} if path.endswith("/issues/174") else {"not": "a list"}
        with self.assertRaisesRegex(BACKEND.DeployRefused, "release_review_ledger_invalid"):
            self.fetch(fake)

    def test_github_path_guard_accepts_only_the_repository_and_paths_below_it(self):
        captured = []

        class Boom(Exception):
            pass

        def opener(*_a, **_k):
            captured.append(1)
            raise Boom()

        for path in ("/repos/zsoltacsai/wpcaregrid", "/repos/zsoltacsai/wpcaregrid/issues/174"):
            with patch.object(BACKEND.urllib.request, "build_opener",
                              return_value=SimpleNamespace(open=opener)), self.assertRaises(Boom):
                BACKEND._github_json(path)
        self.assertEqual(len(captured), 2)
        for path in ("/repos/zsoltacsai/wpcaregridX", "/repos/other/wpcaregrid",
                     "/repos/zsoltacsai", "/users/zsoltacsai"):
            with self.subTest(path=path), self.assertRaisesRegex(
                    BACKEND.DeployRefused, "github_request_path_invalid"):
                BACKEND._github_json(path)


if __name__ == "__main__":
    unittest.main(verbosity=2)
