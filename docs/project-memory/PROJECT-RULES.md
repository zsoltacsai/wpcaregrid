# WP CareGrid — Durable Project Rules

**Effective baseline:** Owner decisions known at 2026-11-19 20:35 CET. **Scope:** project continuity, not operational consent.

## Operating model

1. The Owner decides scope and authorizes specific gated actions. An agent, reviewer, passing CI, previous permission or this document cannot self-authorize a new production operation.
2. Keep the protected primary safe: use branches, scoped changes, PRs, exact-head CI, applicable independent review and explicit merge/deployment gates. Never merge merely because a review is positive.
3. For potentially harmful operations, default to **read-only / source-only** until explicitly scoped authorization is present. No host, root, `sudo`, database, DNS, secret, service, AppArmor, deploy, install or other live-system changes from a documentation request.
4. The VPS named `stg1.wpcaregrid.com` is currently being used to prepare **future production**; its historical hostname **does not establish that it is staging**. A separate staging VPS is a future plan, not an environment available today. Do not infer otherwise from legacy documents, names or workflow examples.
5. The `deploy` account has restricted access; do not assume general `sudo` or root capability. Owner-console-only actions remain Owner-console-only.
6. Keep the initial release target **full Laravel HU/EN marketing-only**. Registration, checkout, live payments, customer onboarding and paid SaaS operations remain disabled until separately authorized and accepted.
7. Keep product, technical, security, legal and accounting decisions distinct; follow [Engineering Decision Protocol](../ENGINEERING_DECISION_PROTOCOL.md). Do not recast an Owner product decision as a security/legal/tax signoff.
8. The multi-agent approach (Elliot coordination; Hagrid infrastructure; Moody AppSec; Hermione QA; specialist implementation/review roles) is a **role model**, not automatically delegated privileged access. Model/provider availability changes; recheck before requiring exact models.
9. Prefer concise Hungarian Owner handoffs with full evidence links, exact SHA, verdict, uncertainties and actionable next steps. Clearly mark reported vs independently verified facts.
10. Respect Owner-closed topics and exclusions. Do not keep asking for the same answered facts or reintroduce topics explicitly barred from future prompts; only the Owner may deliberately reopen them.
11. Never place private host output or sensitive operational artifacts in ChatGPT project sources unless sanitized and approved.

## Evidence precedence

1. Current explicit Owner decisions and permissions, **within applicable security gates**;
2. Latest fresh exact-SHA/host evidence and GitHub state;
3. Latest relevant dated record in [#174](https://github.com/zsoltacsai/wpcaregrid/issues/174);
4. Versioned project documents and applicable immutable runbooks;
5. Prior conversations and human memory summaries (context only).

Conflicts must be called out, not guessed away. A GO for documentation is **not** a GO for services, production G1 or infrastructure.

## Project continuity

The current source of truth is not an unlimited chat history. On every new chat, first inspect [CURRENT-STATE.md](CURRENT-STATE.md), then re-check the referenced live sources before issuing an operation-specific recommendation.
