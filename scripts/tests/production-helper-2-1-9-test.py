#!/usr/bin/env python3
"""Helper 2.1.9 (PHP 8.5 pure successor): inherited V3.1 append-only corrections, frozen 2.1.3/2.1.4 bytes and manifest pins."""
from __future__ import annotations

import hashlib
import importlib.util
import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT / filename)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module

BACKEND = load("go20_backend_216", "deployment_backend_v2_1_9.py")
REVIEW = load("go15_review_v31", "release_review_policy_v3_1.py")
FIXTURE = ROOT / "tests/fixtures/go15-malformed-v3-ledger.json"
OWNER = "zsoltacsai"
HEAD = "3f0f84ad26a5fe7b6fd01ce4be711a416ddb230f"
BASE = "822f284575306c8a5474c37ac87672df24a70ae6"


def originals():
    return json.loads(FIXTURE.read_text(encoding="utf-8"))


def comment(cid, body, created, login=OWNER):
    return {"id": cid, "body": body, "created_at": created,
            "author_association": "OWNER", "user": {"login": login}}


def correction(target, cid, kind):
    digest = hashlib.sha256(target["body"].encode()).hexdigest()
    role = "integration_qa" if target["id"] in {6945646697, 6945647262} else "appsec"
    if kind == "review":
        body = REVIEW.render_correction(
            target_comment_id=target["id"], target_body_sha256=digest, target_pr=417,
            target_head_sha=HEAD, target_role=role,
            reason_code="owner_authorized_exact_record_invalidation")
    else:
        body = BACKEND.render_exact_artifact_invalidation(target["id"])
    return comment(cid, body, "2030-01-01T00:00:00Z")


class AppendOnlyCorrectionTests(unittest.TestCase):
    def test_exact_four_targets_are_pinned_by_id_and_body_hash(self):
        data = originals()
        expected = {
            6945646697: ("artifact", "integration_qa", "f21e60bcc35288d3b2501a5afb99f19f996e82e1bac22bfa7c6e264834af8b27"),
            6945647262: ("review", "integration_qa", "5ff342365ea396c1a7dc0e1cdbba919ec6765837c87fd9f63462665180949f9a"),
            6945647897: ("artifact", "appsec", "4c6d2f0c7490fdcf26b2cdfe9e66c812b1b4af490fcb8bddd30849d71addeeaa"),
            6945648524: ("review", "appsec", "efbf3be019215080a10198906b717f21915aeb245b307067f02d47bd79d539f9"),
        }
        self.assertEqual([x["id"] for x in data], list(expected))
        for item in data:
            self.assertEqual(hashlib.sha256(item["body"].encode()).hexdigest(),
                             item["body_sha256"])
            self.assertEqual(item["user"]["login"], OWNER)
            self.assertEqual(item["author_association"], "OWNER")
            kind, role, digest = expected[item["id"]]
            self.assertEqual((item["kind"], item["body_sha256"]), (kind, digest))
            self.assertIn(f"pr=417", item["body"])
            self.assertIn("head_sha=3f0f84ad26a5fe7b6fd01ce4be711a416ddb230f", item["body"])
            self.assertIn(f"role={role}", item["body"])

    def test_backend_accepts_only_the_two_exact_artifact_invalidations(self):
        data = originals()
        targets = [x for x in data if x["kind"] == "artifact"]
        corrected = data + [correction(x, 8000000000 + x["id"], "artifact") for x in targets]
        actual = BACKEND._authorized_artifact_corrections(corrected, owner=OWNER)
        self.assertEqual(set(actual), {6945646697, 6945647897})
        for invalid_id in (6945647262, 1234567890):
            with self.subTest(invalid_id=invalid_id), self.assertRaisesRegex(
                    BACKEND.DeployRefused, "artifact_correction_target_invalid"):
                BACKEND.render_exact_artifact_invalidation(invalid_id)

    def test_unrelated_malformed_artifact_and_reason_remain_refused(self):
        data = originals()
        targets = [x for x in data if x["kind"] == "artifact"]
        good = [correction(x, 8100000000 + x["id"], "artifact") for x in targets]
        unrelated = comment(8200000001, "WPCG_RELEASE_REVIEW_ARTIFACT_V3 malformed",
                            "2030-01-01T00:00:01Z")
        with self.assertRaisesRegex(BACKEND.DeployRefused, "review_artifact_invalid"):
            BACKEND._verify_review_report_artifacts(
                data + good + [unrelated], records={"integration_qa": {}, "appsec": {}},
                pr_number=417, head_sha=HEAD, base_sha=BASE, repository_owner=OWNER)
        bad = correction(targets[0], 8300000001, "artifact")
        bad["body"] = bad["body"].replace(
            "owner_authorized_exact_artifact_invalidation", "legacy_artifact_schema_invalid")
        with self.assertRaisesRegex(BACKEND.DeployRefused,
                                    "artifact_correction_subject_mismatch"):
            BACKEND._authorized_artifact_corrections(data + [bad], owner=OWNER)

    def test_artifact_invalidation_rejects_each_wrong_bound_field(self):
        target = next(x for x in originals() if x["id"] == 6945646697)
        valid = correction(target, 8300001001, "artifact")
        mutations = [
            ("target_comment_id=6945646697", "target_comment_id=6945646698",
             "artifact_correction_target_invalid"),
            ("target_body_sha256=f21e60bcc35288d3b2501a5afb99f19f996e82e1bac22bfa7c6e264834af8b27",
             "target_body_sha256=" + "0" * 64, "artifact_correction_target_invalid"),
            ("target_pr=417", "target_pr=418", "artifact_correction_subject_mismatch"),
            ("target_head_sha=" + HEAD, "target_head_sha=" + "f" * 40,
             "artifact_correction_subject_mismatch"),
            ("target_base_sha=" + BASE, "target_base_sha=" + "e" * 40,
             "artifact_correction_subject_mismatch"),
            ("target_role=integration_qa", "target_role=appsec",
             "artifact_correction_subject_mismatch"),
            ("owner_authorized_exact_artifact_invalidation", "legacy_artifact_schema_invalid",
             "artifact_correction_subject_mismatch"),
        ]
        for old, new, reason in mutations:
            altered = dict(valid, body=valid["body"].replace(old, new))
            with self.subTest(field=old.split("=", 1)[0]), self.assertRaisesRegex(
                    BACKEND.DeployRefused, reason):
                BACKEND._authorized_artifact_corrections([target, altered], owner=OWNER)
        non_owner = dict(valid, user={"login": "not-owner"})
        with self.assertRaisesRegex(BACKEND.DeployRefused, "artifact_correction_invalid"):
            BACKEND._authorized_artifact_corrections([target, non_owner], owner=OWNER)
        backdated = dict(valid, created_at="2026-11-16T00:00:00Z")
        with self.assertRaisesRegex(BACKEND.DeployRefused, "artifact_correction_not_append_only"):
            BACKEND._authorized_artifact_corrections([target, backdated], owner=OWNER)

    def test_review_invalidation_never_counts_as_pass_without_fresh_records(self):
        data = originals()
        targets = [x for x in data if x["kind"] == "review"]
        corrections = [correction(x, 8400000000 + x["id"], "review") for x in targets]
        with self.assertRaises(REVIEW.ReviewRefused):
            REVIEW.verify_agent_release_reviews(
                data + corrections, pr_number=417, head_sha=HEAD, repository_owner=OWNER)

    def test_unrelated_owner_malformed_review_still_fails_closed(self):
        data = originals()
        targets = [x for x in data if x["kind"] == "review"]
        corrections = [correction(x, 8500000000 + x["id"], "review") for x in targets]
        unrelated = comment(8600000001, "WPCG_RELEASE_REVIEW_V3 malformed",
                            "2030-01-01T00:00:01Z")
        with self.assertRaisesRegex(REVIEW.ReviewRefused, "review_record_invalid"):
            REVIEW.verify_agent_release_reviews(
                data + corrections + [unrelated], pr_number=417, head_sha=HEAD,
                repository_owner=OWNER)

    def test_immutable_2_1_3_and_2_1_4_entrypoints_and_backends_have_separate_files(self):
        frozen = {
            "wpcaregrid-deploy-production-priv-2_1_3.py": "357b0e5715ed86e144a55ac50e0291159116872b266a81f66c5aa3330bbe73c3",
            "deployment_backend_v2_1_3.py": "1f4071a7f9acca93a76211e20294a0efa06b00f288a1970407a5c3c8176846b2",
            "production_deploy_controller_2_1_3.py": "be653c5c668736b7db7493ac27eca3eb2af60224e7735d5005693c98cc0c767f",
            "install_production_helper_2_1_3.py": "fab85b5711bacee71931128c23c09073ec76fce017989cd1fdfd72c534fc6156",
            "deployment_environment_contract_2_1_3.py": "a093d9c8f7de3f5d0cef0bdd9cee248ff0a85054a88d3e50d114796accdc897d",
            "release_review_policy_v3.py": "e50785233d46d7d03e3f734a15fc64764ab85196482789db0c0157cb4307c9db",
        }
        for name, digest in frozen.items():
            self.assertEqual(hashlib.sha256((ROOT / name).read_bytes()).hexdigest(), digest)
        manifest_path = ROOT.parent / "deploy/release-manifests/production-deployment-helper-2.1.3.json"
        self.assertEqual(hashlib.sha256(manifest_path.read_bytes()).hexdigest(),
                         "ef7eb389b0fe4b8527adb6c77fa5e636c59bc6c9a936c447443fc9b29ae98c82")
        self.assertTrue((ROOT / "wpcaregrid-deploy-production-priv-2_1_3.py").is_file())
        self.assertTrue((ROOT / "deployment_backend_v2_1_3.py").is_file())
        frozen_214 = {
            "wpcaregrid-deploy-production-priv-2_1_4.py": "fe25f28a5cdc85eff17961e7893d72c17015ff318c353909fad77f1ddfefe9d0",
            "deployment_backend_v2_1_4.py": "1aaacf5c194d59d5d623aaffab4b960e386ad5fbcb9ae9ab5cc20d8693fad519",
            "deployment_environment_contract_2_1_4.py": "65b06392f26fe8fc3104b67c82b2b56d59d3dc3698661e658dec7f96725a46b7",
            "production_deploy_controller_2_1_4.py": "58070256654714b2f7830890794ff4971e1095ae435804c7fc5f88e5a3dd7c62",
            "install_production_helper_2_1_4.py": "fa3d9f73b2c86ade9fba0c37191852523e3acec0af72d7da3d187872581c17bf",
            "provision_wpcaregrid_deploy_parent_dirs_2_1_4.py": "936dd92c6a68a5df84966d43e8d19c925e51252ba2292f1e54fbda0dfb9fabd1",
            "release_review_policy_v3_1.py": "5559217003066b77fb2533c8a113fa6d19c497924add59e1db3e023641143d6f",
            "deployment_transaction_v2.py": "ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00",
        }
        for name, digest in frozen_214.items():
            self.assertEqual(hashlib.sha256((ROOT / name).read_bytes()).hexdigest(), digest, name)
        for relative, digest in (
                ("deploy/release-manifests/production-deployment-helper-2.1.4.json",
                 "591f1574ba88c95db99599149a85f95028cf99cd533a39144220c60f1d6b5a98"),
                ("docs/PRODUCTION_HELPER_INSTALL_2_1_4.md",
                 "bf0cdc67c58fc2589c1c4f27737b35905a61ccc5e5bbc5f673cff9cb81571697"),
                ("deploy/release-manifests/production-deployment-helper-2.1.5.json",
                 "8b14972f4c500aa0e17aae75f971b9e40d2088c87225fd4a780260624dc6648a"),
                ("docs/PRODUCTION_HELPER_INSTALL_2_1_5.md",
                 "25295b3f23a02ad113b4361acba75ce1282022465b792135267e237582b294ed")):
            self.assertEqual(hashlib.sha256((ROOT.parent / relative).read_bytes()).hexdigest(), digest, relative)
        self.assertIn('VERSION = "2.1.4"',
                      (ROOT / "wpcaregrid-deploy-production-priv-2_1_4.py").read_text())
        self.assertIn('VERSION = "2.1.5"',
                      (ROOT / "wpcaregrid-deploy-production-priv-2_1_5.py").read_text())
        self.assertIn('VERSION = "2.1.9"',
                      (ROOT / "wpcaregrid-deploy-production-priv-2_1_9.py").read_text())
        self.assertIn('HELPER_VERSION = "2.1.9"',
                      (ROOT / "deployment_backend_v2_1_9.py").read_text())

    def test_manifest_and_installer_bind_exact_216_bytes(self):
        manifest = json.loads((ROOT.parent / "deploy/release-manifests/production-deployment-helper-2.1.9.json").read_text())
        paths = {
            "entrypoint_sha256": ROOT / "wpcaregrid-deploy-production-priv-2_1_9.py",
            "backend_sha256": ROOT / "deployment_backend_v2_1_9.py",
            "transaction_module_sha256": ROOT / "deployment_transaction_v2.py",
            "environment_contract_sha256": ROOT / "deployment_environment_contract_2_1_9.py",
            "release_review_policy_sha256": ROOT / "release_review_policy_v3_1.py",
            "controller_sha256": ROOT / "production_deploy_controller_2_1_9.py",
            "root_installer_sha256": ROOT / "install_production_helper_2_1_9.py",
        }
        for key, path in paths.items():
            self.assertEqual(manifest[key], hashlib.sha256(path.read_bytes()).hexdigest(), key)
        helper_material = json.dumps({
            "schema": 1, "helper_version": "2.1.9",
            "compatibility_contract": "wpcaregrid-deployment-profile-v2.2",
            **{key: manifest[key] for key in (
                "entrypoint_sha256", "backend_sha256", "transaction_module_sha256",
                "environment_contract_sha256", "release_review_policy_sha256")},
        }, sort_keys=True, separators=(",", ":")).encode()
        self.assertEqual(manifest["helper_artifact_sha256"],
                         hashlib.sha256(helper_material).hexdigest())


if __name__ == "__main__":
    unittest.main()
