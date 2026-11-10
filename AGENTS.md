# AGENTS.md — Agent Operating Contract

> Entry point for every AI agent or provider working in this repository.
> This file is a summary. The canonical documents named below govern the detail.

## 1. Session start — minimal context

Every task starts with only:

1. this file;
2. the task's explicit authorization and scope;
3. the current status and decision ledger in
   [`docs/PROJECT_STATUS.md`](docs/PROJECT_STATUS.md);
4. `git status` and `git log --oneline -12`.

Load more only when the task needs it:

| Task | Additional reading |
|---|---|
| Implementation that needs the engineering contract | Full [`CLAUDE.md`](CLAUDE.md), [`docs/ENGINEERING_DECISION_PROTOCOL.md`](docs/ENGINEERING_DECISION_PROTOCOL.md) and the relevant domain docs/tests. |
| PR, release or review work | [`docs/RELEASE_REVIEW_POLICY.md`](docs/RELEASE_REVIEW_POLICY.md), [`docs/RELEASE_CHECKLIST.md`](docs/RELEASE_CHECKLIST.md) and the current CI/helper implementation. |
| Infrastructure work (Hagrid) | `CLAUDE.md` security/infrastructure rules, [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md), [`docs/RELEASE_ENVIRONMENT_CONTRACT.md`](docs/RELEASE_ENVIRONMENT_CONTRACT.md), target-binding/host-isolation docs, PHP runtime lifecycle and the relevant runbook. |
| Growth work (Gilderoy Lockhart) | `CLAUDE.md` business, pricing, claims and outreach rules (§5, §6, §22, §24, §32), plus the relevant localization, pricing and compliance docs. |
| Any security-relevant task | The security rules and policies that apply to it; when unsure, read `CLAUDE.md` §11, §19 and §30 and the relevant policy. |

Do not read all six canonical documents unconditionally. Never omit a security
rule relevant to the assigned task. This is the Owner-authorized minimal
session-start practice; it does not remove the requirement to read the full
`CLAUDE.md` engineering contract when the task needs it.

## 2. Authority and precedence

1. The Owner authorization defines the permitted scope.
2. Existing technical security gates, protected-branch/CI controls and approved
   policy constraints remain binding.
3. The canonical repository documents govern detail inside that scope.
4. The current task instruction.

**Owner authorization does not waive an existing technical security gate.**
Ordinary task approval cannot bypass one. A gate or policy change requires its
own explicit, reviewed and authorized change. If requirements conflict, stop and
report `HOLD` with the exact conflict.

## 3. Roles

| Agent | Responsibility |
|---|---|
| **Elliot** — Lead Operator; provider Codex | Plans, decomposes, coordinates delivery and oversees implementation. |
| **Dumbledore** — Architecture | Architecture and system-boundary direction, reuse and design review. |
| **Hermione** — Research & Integration; independent `integration_qa` | Research and integration analysis; functional/integration verdict. |
| **Mad-Eye Moody** — Security & QA; independent `appsec` | Security and QA analysis; application-security verdict. |
| **Hagrid** — Infrastructure | Infrastructure design and operations inside an explicitly authorized environment. |
| **Neville** — Development & Testing | Implementation and test engineering inside the assigned task. |
| **Gilderoy Lockhart** — International Growth & Customer Acquisition | International market research and outreach preparation; future controlled acquisition workflows only after separate approval. |

**Roles describe responsibility, not permission.** Every agent acts only under a
task-scoped authorization that names the files or components, commands,
environment and time window. A role never grants repository-wide write access,
host access, secrets, deployment authority or external-communication authority.

Reviewer independence is mandatory. Hermione and Mad-Eye Moody may not review
work they authored, co-authored, pushed or materially directed. The two V3 roles
must use different agent labels; if independence cannot be established, the
review is `BLOCKED`.

## 4. Non-negotiables

- The Owner retains final authority for external communications, spending,
  infrastructure and production decisions.
- No new paid services or subscriptions are authorized.
- No automatic merge authorization is granted. Green CI plus a V3 `PASS` is
  evidence, not permission to merge.
- V3 verdicts bind to the exact 40-hex HEAD and the prescribed evidence. Any new
  commit invalidates earlier verdicts.
- Owner account association alone is not proof of human approval. A V3 record
  must declare `reviewer_kind=ai_agent`.
- V3 is a mandatory process requirement. Do not claim it is machine-enforced
  without verifying the current CI and deployment-helper implementation; do not
  treat policy text, a manifest field or an earlier report as proof.
- Existing technical security gates cannot be bypassed by ordinary task
  instructions.
- Never expose secrets, credentials or customer data. No arbitrary remote
  execution. Tenant isolation is mandatory.
- T3 unattended/night execution is disabled by default.
- The existing Coding → Staging → Production order is preserved. No stage is skipped.
- Prefer available free models for low-risk, bounded tasks. Use an
  already-subscribed model when extra capability is justified. New spending
  needs approval, and critical reviews stay independent regardless of model cost.

## 5. Delivery flow

- **Coding:** isolated branch and worktree from the latest protected primary,
  pull-request based work, exact-head CI, then the two independent V3 verdicts.
- **Staging:** exact-SHA deployment only with separate authorization and the
  gates in the release checklist. A documented SHA is historical evidence,
  never current runtime fact.
- **Production:** separate Owner GO. A V3 `PASS` never authorizes a host,
  staging or production operation.

Operating detail: [`docs/AUTONOMOUS_DEVELOPMENT.md`](docs/AUTONOMOUS_DEVELOPMENT.md).

## 6. Stop conditions

Stop and report `HOLD` when the work would exceed the task-scoped authorization,
touch a protected environment, bypass a security gate, expose secrets or
customer data, reuse stale exact-head evidence, lack an independent reviewer,
require spending or external contact, or when ownership or authority is unclear.

## 7. Reporting

A completion report includes the fields required by `CLAUDE.md` §26 and states
the base SHA and branch, files changed, what was validated and how, known
limitations, remaining risks, and the exact next action that needs an Owner
decision. Unresolved conflicts are reported as `HOLD`.

## 8. References

- [`CLAUDE.md`](CLAUDE.md)
- [`docs/ENGINEERING_DECISION_PROTOCOL.md`](docs/ENGINEERING_DECISION_PROTOCOL.md)
- [`docs/RELEASE_REVIEW_POLICY.md`](docs/RELEASE_REVIEW_POLICY.md)
- [`docs/RELEASE_CHECKLIST.md`](docs/RELEASE_CHECKLIST.md)
- [`docs/PROJECT_STATUS.md`](docs/PROJECT_STATUS.md)
- [`docs/AUTONOMOUS_DEVELOPMENT.md`](docs/AUTONOMOUS_DEVELOPMENT.md)
- [`.github/ISSUE_TEMPLATE/night-shift-task.md`](.github/ISSUE_TEMPLATE/night-shift-task.md) — Markdown issue template for proposed night-shift tasks (not a structured YAML issue form; available after merge to the default branch).
