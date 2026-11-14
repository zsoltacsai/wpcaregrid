#!/usr/bin/env python3
"""Deterministic, network-free regression tests for the Agent Registry validator."""
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("agent_registry_validator", ROOT / "scripts" / "validate-agent-registry.py")
VALIDATOR = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(VALIDATOR)
SOURCE = json.loads((ROOT / "agents" / "registry.yml").read_text(encoding="utf-8"))

class RegistryValidationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        (self.root / "agents" / "prompts").mkdir(parents=True)
        self.data = json.loads(json.dumps(SOURCE))
        for agent in self.data["agents"]:
            (self.root / agent["prompt_path"]).write_text("# prompt\n", encoding="utf-8")
        self.path = self.root / "agents" / "registry.yml"
        self.write()

    def tearDown(self):
        self.temp.cleanup()

    def write(self):
        self.path.write_text(json.dumps(self.data), encoding="utf-8")

    def errors(self):
        return VALIDATOR.validate_registry(self.path, self.root)

    def assertInvalid(self, mutate, expected):
        mutate()
        self.write()
        self.assertTrue(any(expected in error for error in self.errors()), self.errors())

    def agent(self, aid):
        return next(agent for agent in self.data["agents"] if agent["canonical_id"] == aid)

    def test_valid_registry(self):
        self.assertEqual([], self.errors())

    def test_missing_field(self):
        self.assertInvalid(lambda: self.agent("elliot-operator").pop("prompt_path"), "missing fields")

    def test_duplicate_canonical_id(self):
        self.assertInvalid(lambda: self.data["agents"][1].update(canonical_id="elliot-operator"), "duplicate canonical_id")

    def test_unknown_role(self):
        self.assertInvalid(lambda: self.agent("dumbledore-architecture").update(role="super_admin"), "invalid role")

    def test_provider_model_mismatch(self):
        self.assertInvalid(lambda: self.agent("hermione-research").update(provider_instance_id="claudeAgent"), "invalid provider_instance_id")

    def test_disallowed_runtime(self):
        self.assertInvalid(lambda: self.agent("elliot-operator").update(default_runtime_mode="full-access"), "runtime must be approval-required")

    def test_invalid_reasoning(self):
        self.assertInvalid(lambda: self.agent("moody-appsec").update(reasoning={"option":"effort","value":"unlimited"}), "invalid reasoning")

    def test_missing_prompt(self):
        (self.root / self.agent("hermione-research")["prompt_path"]).unlink()
        self.assertTrue(any("invalid prompt_path" in error for error in self.errors()))

    def test_path_traversal(self):
        self.assertInvalid(lambda: self.agent("hermione-research").update(prompt_path="agents/prompts/../../secret.md"), "invalid prompt_path")

    def test_symlink_prompt(self):
        target = self.root / self.agent("hermione-research")["prompt_path"]
        target.unlink()
        target.symlink_to(self.root / self.agent("elliot-operator")["prompt_path"])
        self.assertTrue(any("symlink path is prohibited" in error for error in self.errors()))

    def test_owner_go_cannot_be_disabled(self):
        self.assertInvalid(lambda: self.agent("hagrid-infrastructure").update(requires_owner_go=False), "requires_owner_go")

    def test_task_selected_model_must_have_default(self):
        self.assertInvalid(lambda: self.agent("hagrid-infrastructure").update(default_model_id=None), "invalid default_model_id")

    def test_recursive_delegation_is_rejected(self):
        self.assertInvalid(lambda: self.agent("elliot-operator")["delegation_rules"].update(recursive=True), "delegation exceeds")

    def test_silent_fallback_is_rejected(self):
        self.assertInvalid(lambda: self.agent("neville-development")["delegation_rules"].update(fallback_allowed=True), "delegation exceeds")

    def test_duplicate_json_key_is_rejected(self):
        self.path.write_text('{"schema_version":1,"schema_version":1,"source_policy":"authoritative_only_from_verified_protected_primary","agents":[]}', encoding="utf-8")
        self.assertTrue(any("duplicate key" in error for error in self.errors()))

if __name__ == "__main__":
    unittest.main(verbosity=2)
