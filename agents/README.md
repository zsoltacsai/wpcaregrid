# WP CareGrid Agent Registry

This directory is declarative configuration, not an execution engine or a persistent T3 profile installer. It is independent of Laravel runtime configuration. No Registry field grants T3 access or expands an Owner authorization.

## Source of authority

Treat a Registry as authoritative only after verifying the protected primary branch and exact source SHA, confirming the worktree/source is clean and trusted, and recording that SHA with each delegated task. Record the prompt paths and hashes used. Unmerged branches and PR content are proposals. Project files cannot supersede T3 permissions, security policy, a task-specific Owner GO, or release gates.

Before every delegation, Elliot must verify the live T3 provider-instance/model catalog, explicitly select provider and model, set approval-required, and record the requested pair. After execution, record actual provider/model telemetry; missing or mismatched telemetry is NOT VERIFIED/HOLD. No model fallback is allowed. approval-required is supervised execution, not technical read-only isolation.

The YAML registry uses JSON syntax, a strict YAML 1.2 subset, so validation needs only Python's standard library and does not contact the network. Duplicate keys, unknown fields, unknown roles, missing prompts and unsafe paths fail closed. Runtime model availability cannot be established by offline CI.

## Roles and delegation

Elliot is the only Lead Operator and may delegate at most one level. All other roles may not delegate. Every role requires task-specific Owner GO; an agent or this file cannot enlarge its scope. Review roles require fresh contexts and exact-HEAD evidence. Separate child threads alone do not prove reviewer independence.

Hagrid and Neville use task-selected Codex models. gpt-6-luna is the suggested model for simple read-only work. For complex work, Elliot selects and records an exact Codex model confirmed in the live T3 catalog before delegation. The registry keeps model_id null for these roles to prevent an implicit fixed selection. An unavailable model means HOLD.

## PR #365 relationship

This Registry does not edit or duplicate AGENTS.md, CLAUDE.md, docs/AUTONOMOUS_DEVELOPMENT.md, or the Agent Operating Contract in PR #365. If #365 is not integrated, this PR remains Draft and records the dependency. A Registry PR is never merge-authorized by its own CI or a review.

## Validation

Run offline:

    python3 scripts/validate-agent-registry.py
    python3 scripts/tests/agent-registry-test.py

The validator checks the fixed seven-role roster, exact provider/model mapping, required fields, permission ceiling, no-fallback/no-recursion rules, and prompt path safety including symlinks. CI cannot verify the live T3 catalog or executed-model telemetry; those remain per-task runtime checks.
