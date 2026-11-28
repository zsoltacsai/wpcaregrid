#!/usr/bin/env python3
"""Fail-closed, offline validator for the declarative WP CareGrid agent registry."""
from __future__ import annotations
import json
import sys
from pathlib import Path

EXPECTED = {
    "elliot-operator": ("lead_operator", "codex", "gpt-6-luna", "fixed", "gpt-6-luna"),
    "dumbledore-architecture": ("architecture", "claudeAgent", "claude-opus-5-5", "fixed", "claude-opus-5-5"),
    "hermione-research": ("research_integration_qa", "antigravity", "gemini-3.8-flash", "fixed", "gemini-3.8-flash"),
    "moody-appsec": ("security_appsec", "claudeAgent", "claude-sonnet-5-5", "fixed", "claude-sonnet-5-5"),
    "hagrid-infrastructure": ("infrastructure", "codex", None, "task_selected_codex", "gpt-6-luna"),
    "neville-development": ("development_testing", "codex", None, "task_selected_codex", "gpt-6-luna"),
    "lockhart-growth": ("international_growth", "antigravity", "gemini-3.8-flash", "fixed", "gemini-3.8-flash"),
}
REQUIRED = {
    "canonical_id", "display_name", "role", "provider_instance_id", "model_id",
    "model_selection_policy", "default_model_id", "reasoning", "prompt_path",
    "default_runtime_mode", "allowed_task_types", "prohibited_operations",
    "requires_owner_go", "reviewer_role", "independence_requirements", "delegation_rules",
}
DELEGATION_KEYS = {"may_delegate", "max_depth", "recursive", "fallback_allowed"}
REVIEWERS = {
    "hermione-research": ("integration_qa", {"fresh_context", "exact_head", "no_implementer_context", "no_cross_reviewer_verdict", "actual_model_telemetry"}),
    "moody-appsec": ("appsec", {"fresh_context", "exact_head", "no_implementer_context", "no_cross_reviewer_verdict", "actual_model_telemetry"}),
}
REQUIRED_PROHIBITIONS = {
    "force_push", "history_rewrite", "protected_primary_direct_push", "owner_identity_impersonation",
    "post_v3_ledger_record", "alter_other_reviewer_evidence", "recursive_delegation",
    "silent_model_fallback", "unattended_or_scheduled_execution_without_approved_controls",
    "merge_without_exact_owner_go", "staging_without_exact_owner_go",
    "production_without_exact_owner_go", "host_database_or_secret_access_without_exact_owner_go",
    "external_communication_without_exact_owner_go",
}
def _unique_pairs(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate key: " + key)
        result[key] = value
    return result

def validate_registry(registry_path: Path, repo_root: Path) -> list[str]:
    errors = []
    try:
        data = json.loads(registry_path.read_text(encoding="utf-8"), object_pairs_hook=_unique_pairs)
    except (OSError, UnicodeError, json.JSONDecodeError, ValueError) as exc:
        return [f"registry parse/read failure: {exc}"]
    if not isinstance(data, dict) or set(data) != {"schema_version", "source_policy", "agents"}:
        return ["registry root must contain exactly schema_version, source_policy, agents"]
    if data["schema_version"] != 1 or data["source_policy"] != "authoritative_only_from_verified_protected_primary":
        errors.append("unsupported schema version or source policy")
    agents = data["agents"]
    if not isinstance(agents, list) or len(agents) != len(EXPECTED):
        return errors + ["agents must contain exactly the seven canonical roles"]
    seen = set()
    for index, agent in enumerate(agents):
        where = f"agents[{index}]"
        if not isinstance(agent, dict):
            errors.append(f"{where} must be an object")
            continue
        aid = agent.get("canonical_id")
        if aid in seen:
            errors.append(f"{where}: duplicate canonical_id {aid!r}")
        seen.add(aid)
        missing, extra = REQUIRED - set(agent), set(agent) - REQUIRED
        if missing:
            errors.append(f"{where}: missing fields {sorted(missing)}")
        if extra:
            errors.append(f"{where}: unknown fields {sorted(extra)}")
        if aid not in EXPECTED:
            errors.append(f"{where}: unknown canonical_id {aid!r}")
            continue
        role, provider, model, policy, default_model = EXPECTED[aid]
        expected = {
            "role": role, "provider_instance_id": provider, "model_id": model,
            "model_selection_policy": policy, "default_model_id": default_model,
            "prompt_path": f"agents/prompts/{aid}.md",
        }
        for key, value in expected.items():
            if agent.get(key) != value:
                errors.append(f"{where}: invalid {key} for {aid}")
        if agent.get("default_runtime_mode") != "approval-required":
            errors.append(f"{where}: runtime must be approval-required")
        if agent.get("requires_owner_go") is not True:
            errors.append(f"{where}: requires_owner_go must be true")
        if not isinstance(agent.get("display_name"), str) or not agent["display_name"].strip():
            errors.append(f"{where}: display_name must be non-empty")
        for key in ("allowed_task_types", "prohibited_operations", "independence_requirements"):
            if not isinstance(agent.get(key), list) or any(not isinstance(x, str) for x in agent[key]):
                errors.append(f"{where}: {key} must be a string array")
        if isinstance(agent.get("allowed_task_types"), list) and not agent["allowed_task_types"]:
            errors.append(f"{where}: allowed_task_types cannot be empty")
        if isinstance(agent.get("prohibited_operations"), list) and not REQUIRED_PROHIBITIONS.issubset(agent["prohibited_operations"]):
            errors.append(f"{where}: required prohibited operations are missing")
        reasoning = agent.get("reasoning")
        if reasoning is not None and (
            not isinstance(reasoning, dict) or set(reasoning) != {"option", "value"}
            or reasoning.get("option") not in {"effort", "thought_level", "reasoningEffort"}
            or reasoning.get("value") not in {"low", "medium", "high"}
        ):
            errors.append(f"{where}: invalid reasoning configuration")
        expected_reviewer, expected_independence = REVIEWERS.get(aid, (None, set()))
        if agent.get("reviewer_role") != expected_reviewer:
            errors.append(f"{where}: invalid reviewer_role")
        independence = agent.get("independence_requirements")
        if isinstance(independence, list) and set(independence) != expected_independence:
            errors.append(f"{where}: invalid independence_requirements")
        rules = agent.get("delegation_rules")
        if not isinstance(rules, dict) or set(rules) != DELEGATION_KEYS:
            errors.append(f"{where}: delegation_rules has invalid shape")
        else:
            allowed = aid == "elliot-operator"
            if (rules.get("may_delegate") is not allowed
                or rules.get("max_depth") != (1 if allowed else 0)
                or rules.get("recursive") is not False
                or rules.get("fallback_allowed") is not False):
                errors.append(f"{where}: delegation exceeds fixed policy")
        path = agent.get("prompt_path")
        if isinstance(path, str):
            candidate = repo_root / path
            try:
                rel = Path(path)
                base = (repo_root / "agents" / "prompts").resolve(strict=True)
                resolved = candidate.resolve(strict=True)
                if rel.is_absolute() or "\\" in path or ".." in rel.parts:
                    raise ValueError("unsafe path syntax")
                if not resolved.is_relative_to(base) or not candidate.is_file():
                    raise ValueError("prompt path escapes prompts directory or is not a file")
                current = repo_root
                for part in rel.parts:
                    current = current / part
                    if current.is_symlink():
                        raise ValueError("symlink path is prohibited")
            except (OSError, RuntimeError, ValueError) as exc:
                errors.append(f"{where}: invalid prompt_path: {exc}")
    if seen != set(EXPECTED):
        errors.append("canonical role roster is incomplete")
    return errors

def main() -> int:
    repo_root = Path(__file__).resolve().parents[1]
    errors = validate_registry(repo_root / "agents" / "registry.yml", repo_root)
    if errors:
        for error in errors:
            print("ERROR:", error, file=sys.stderr)
        return 1
    print("Agent Registry valid (offline structural checks only).")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
