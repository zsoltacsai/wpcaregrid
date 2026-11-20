# WP CareGrid — Continuity / Project Memory

**Purpose:** A small, versioned handoff contract for ChatGPT Projects and multi-agent development. This folder supplements, not replaces, the engineering source of truth. It is **not** a deployment or execution authorization.

## Read order for every new conversation

1. [PROJECT-RULES.md](PROJECT-RULES.md): durable Owner rules, evidence hierarchy and permissions.
2. [CURRENT-STATE.md](CURRENT-STATE.md): dated snapshot only; **verify the latest live state**.
3. [DECISION-LOG.md](DECISION-LOG.md): material decisions with source, date and supersession.
4. [CLOSED-TOPICS.md](CLOSED-TOPICS.md): Owner-closed discussions and obsolete assumptions.
5. [CHATGPT-PROJECT-INSTRUCTIONS.md](CHATGPT-PROJECT-INSTRUCTIONS.md): text to paste into the ChatGPT Project settings.

## Authority and drift

- The authoritative code branch is `claude/ecstatic-wozniak-oemyuf`, protected by GitHub rules. Always read its *current* SHA instead of trusting a snapshot.
- [Issue #174](https://github.com/zsoltacsai/wpcaregrid/issues/174) is the live integration/evidence ledger. Read recent entries **including their timestamps**; no single old comment is sufficient.
- Existing [docs/PROJECT_STATUS.md](../PROJECT_STATUS.md), [docs/MASTER_ROADMAP_AND_INTEGRATION_PLAN.md](../MASTER_ROADMAP_AND_INTEGRATION_PLAN.md), [docs/ENGINEERING_DECISION_PROTOCOL.md](../ENGINEERING_DECISION_PROTOCOL.md) and [CLAUDE.md](../../CLAUDE.md) remain in force, within their scope.
- If the latest Owner decision conflicts with an old document, **flag the drift** and propose a documentation correction through protected PR review. Do not silently rewrite controls or turn a conversational decision into host authorization.
- The source of truth for a runtime state is current, exact-head, appropriately authorized evidence. A summary, agent verdict or passing synthetic test alone does not authorize actions.
- Never commit secrets, raw logs, keys, credentials, personal data or unsanitized host output to project memory.

## Conversation handoff protocol

At each meaningful milestone, provide a short copyable handoff with:
- Timestamp in Europe/Budapest and scope;
- Protected primary full SHA and corresponding exact-head CI status (or UNKNOWN, not guessed);
- Latest #174 record permalink and evidence quality;
- GO/HOLD/NO-GO **by action**; clearly separate code-review PASS from host execution permission;
- Current actionable blockers, without reopening Owner-closed subjects;
- What changed, what did not happen, and the next **non-destructive, permitted** action;
- Conflicts between chat/history and the repository that require explicit reconciliation.

After approval, update these files through a reviewed PR. Avoid parallel copies of the same project-state truth. Snapshot files may become stale and must never silently override the ledger.
