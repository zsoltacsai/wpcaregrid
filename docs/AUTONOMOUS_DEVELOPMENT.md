# Autonomous development — multi-agent operating contract

This document defines how WP CareGrid agents and providers work together. It is
documentation-only and changes no code, configuration, CI, ruleset or
environment. It applies once the Owner reviews and merges it.

Canonical documents remain authoritative in their own areas:
[`CLAUDE.md`](../CLAUDE.md),
[`ENGINEERING_DECISION_PROTOCOL.md`](ENGINEERING_DECISION_PROTOCOL.md),
[`RELEASE_REVIEW_POLICY.md`](RELEASE_REVIEW_POLICY.md),
[`RELEASE_CHECKLIST.md`](RELEASE_CHECKLIST.md) and
[`PROJECT_STATUS.md`](PROJECT_STATUS.md). This contract only defines the
agent-specific boundaries around them.

## 1. Purpose

The contract lets several agents and providers work on one repository without
inventing authority, reviewing their own work or treating a task instruction as
permission for a protected operation. It does not grant staging, production,
database, credential, external-communication or spending access.

## 2. Session start and task-dependent context

The mandatory session-start context is deliberately small:

1. [`AGENTS.md`](../AGENTS.md);
2. the task's explicit authorization and scope;
3. the current status and decision ledger in
   [`PROJECT_STATUS.md`](PROJECT_STATUS.md);
4. `git status` and `git log --oneline -12`.

Load the rest only when the task needs it:

| Task | Additional context |
|---|---|
| Implementation that needs the engineering contract | Read `CLAUDE.md` **in full** before editing, then the Engineering Decision Protocol and the relevant domain docs and tests. |
| PR, release or review work | Release Review Policy V3, Release Checklist, current CI/helper implementation and the exact-head evidence. |
| Infrastructure work (Hagrid) | `CLAUDE.md` security/infrastructure rules, [`DEPLOYMENT.md`](DEPLOYMENT.md), [`RELEASE_ENVIRONMENT_CONTRACT.md`](RELEASE_ENVIRONMENT_CONTRACT.md), target-binding and host-isolation documents, [`PHP_RUNTIME_LIFECYCLE_POLICY.md`](PHP_RUNTIME_LIFECYCLE_POLICY.md) and the relevant runbook. |
| Growth work (Gilderoy Lockhart) | `CLAUDE.md` business, pricing, claims and outreach rules (§5, §6, §22, §24, §32), plus the relevant localization, pricing and compliance documents. |
| Security-relevant work | The security rules and policies for that task. When unsure, read `CLAUDE.md` §11, §19 and §30 and the relevant security document. |

Never omit a security rule relevant to the assigned task. If the task crosses
areas, load each area's rules rather than assuming one document covers them.

This section is the Owner-authorized WPCG-01 refinement of the older
read-everything session-start practice in `CLAUDE.md` §27. It does not waive
`CLAUDE.md`; the full engineering contract is still required whenever the task
needs it.

## 3. Authority, scope and precedence

1. The Owner authorization defines the permitted scope.
2. Existing technical security gates, protected-branch/CI controls and approved
   policy constraints remain binding.
3. The canonical repository documents govern detail inside that scope.
4. The current task instruction.

**Owner authorization does not waive an existing technical security gate.**
Ordinary task approval cannot bypass one. A gate or policy change requires its
own explicit, reviewed and authorized change; it is never a side effect of a
normal task. If requirements conflict, stop and report `HOLD` with the exact
conflict and the smallest decision needed.

The Owner retains final authority for external communications, spending,
infrastructure, hosting, DNS, credentials, privileged helper installation, and
staging or production deployment, database initialization, restore, service
start and any operation on live data. An agent `PASS`, a green CI run or a
merged document never substitutes for these decisions.

Use the Engineering Decision Protocol for new decisions: technical details
inside approved scope are Class A; product/policy choices are Owner decisions
(Class B); legal/privacy and accounting/tax conclusions are not inferred
(`WAIT_LEGAL` / `WAIT_ACCOUNTING`); security-policy choices preserve the stronger
safeguard (Class E); provider facts are verified or kept unknown (Class F).

No new paid service, subscription, paid data source, paid API plan or paid
hosting add-on is authorized by this contract. A future need is a Class B Owner
decision with cost, purpose, duration, data exposure and exit path stated.

## 4. Roles and task-scoped authorization

| Agent | Responsibility | Boundary |
|---|---|---|
| **Elliot** — Lead Operator; provider Codex | Plans, decomposes, coordinates delivery and oversees implementation. | No merge, deployment or production authority by role. |
| **Dumbledore** — Architecture | Architecture and system-boundary direction, reuse and design review. | Architecture input is not a V3 verdict; no implicit write access. |
| **Hermione** — Research & Integration; independent `integration_qa` | Research and integration analysis; functional/integration verdict. | Cannot review her own work; no implicit write access. |
| **Mad-Eye Moody** — Security & QA; independent `appsec` | Security and QA analysis; application-security verdict. | Cannot review his own work; no implicit write access. |
| **Hagrid** — Infrastructure | Infrastructure design and operations inside an explicitly authorized environment. | No standing host access; each host operation needs its own authorization. |
| **Neville** — Development & Testing | Implementation and test engineering inside the assigned task. | Works only in the authorized branch, worktree and file set. |
| **Gilderoy Lockhart** — International Growth & Customer Acquisition | International market research and outreach preparation; future controlled acquisition workflows. | No outbound communication without separate approval and compliance clearance. |

**Roles describe responsibility, not permission.** Every agent acts only under a
task-scoped authorization that names:

- the files or components in scope;
- the commands and tools allowed;
- the environment and, where relevant, the time window;
- the evidence to produce and the stop conditions.

A role never grants repository-wide write access, host access, secrets,
deployment authority or external-communication authority. An agent that needs
broader scope must stop and request it explicitly.

Reviewer independence is mandatory. Hermione and Mad-Eye Moody may not review
work they authored, co-authored, pushed or materially directed. The two V3 roles
must use different agent labels. When independence cannot be established,
the review is `BLOCKED`, not silently reassigned.

### 4.1 Gilderoy Lockhart — growth mission

Lockhart starts in research and outreach-preparation mode. He may research
markets, languages, competitors and public information, prepare campaigns,
segmentation and checklists, and document assumptions and compliance questions.

Future, separately approved and legally compliant acquisition workflows may
permit controlled execution and follow-up within defined campaign boundaries.
That requires its own authorization covering lawful basis and consent where
required, data minimization, sender identity, opt-out handling, anti-spam and
market-specific rules, retention, the approved sending channel and a named
campaign scope. A general task instruction is not that authorization.

No outbound communication is authorized by this contract or the current task.
Outreach material stays evidence-based, never fabricates a security problem, and
the initial outreach workflow remains human-approved as required by
[`CLAUDE.md`](../CLAUDE.md).

## 5. Delivery lifecycle: Coding → Staging → Production

The existing sequence is preserved. No stage is skipped and no agent may
compress two stages into one authorization.

**Coding.** Start from the latest protected primary after fetching remote
references. Use an isolated branch and worktree; do not reset or overwrite
unrelated work. Keep the change inside the authorized scope, use pull-request
based work, run local validation and repository CI, then obtain both independent
V3 verdicts for the exact HEAD. A new commit invalidates earlier verdicts.
Coding does not authorize staging or production.

**Staging.** Staging needs separate authorization for the exact target and exact
source SHA. Follow the current release path, recovery prerequisites,
database-change rules and NO-GO conditions in the
[`RELEASE_CHECKLIST.md`](RELEASE_CHECKLIST.md). Verify environment identity,
exact SHA, runtime and gate state at execution time through the approved
read-only path. A SHA quoted in a document or earlier report is historical
evidence, never current runtime fact. A V3 `PASS` is source evidence only.

**Production.** Production needs a separate Owner GO after the applicable
release-checklist gates pass. V3 evidence, CI and a staging result are inputs,
not production authorization. Live payments, destructive automation, restore
and security remediation keep their own explicit switches and approvals. Any
production claim must name the exact scope, environment and evidence.

## 6. V3 reviews

Release Review Policy V3 is a **mandatory process requirement** for a source
merge. It is effective 2026-11-09 and governs where an older handoff or status
snapshot says independent QA/AppSec reviews are optional. It requires two
separately recorded AI-agent verdicts bound to the exact pull-request HEAD:

- `integration_qa`, owned by Hermione;
- `appsec`, owned by Mad-Eye Moody.

Each record uses the prescribed `WPCG_RELEASE_REVIEW_V3` format in the
[`RELEASE_REVIEW_POLICY.md`](RELEASE_REVIEW_POLICY.md), including the exact
40-hex HEAD, an identifiable agent label, `reviewer_kind=ai_agent`, a decision
and a SHA-256 of the underlying report.

- Both roles must be `PASS` on the same exact HEAD before merge is considered.
- A record for another HEAD or pull request is never reused.
- The two roles must use different agent labels.
- Neither reviewer may review their own work.
- Missing, malformed, stale or invalid evidence is never `PASS`.
- A new commit requires new reports, new hashes and new verdicts.

The report behind `evidence_sha256` must state the exact HEAD, PR and role, what
was and was not reviewed, the commands/environments/fixtures used, observed
results including failures and limitations, security findings and residual risk
for `appsec`, coverage gaps for `integration_qa`, the agent identity, and that
the reviewer did not author the work. Do not put secrets, credentials, customer
data or raw production diagnostics in the report or its hash input.

V3 records are AI-agent QA/AppSec evidence, never a human review. Owner account
association is an attribution and eligibility property of the ledger comment,
not proof that a human approved the change. A record must declare
`reviewer_kind=ai_agent`; an owner-associated record without that declaration,
or with a malformed body, fails closed.

**Enforcement claims.** V3 remains mandatory whether or not a machine enforces
it. Before claiming that V3 is machine-enforced, verify the current CI, ruleset
and deployment-helper implementation and record the exact evidence. Policy
text, a manifest field or an earlier report is not proof. Do not hardcode the
current enforcement state as a permanent fact; recheck it for each release.
Do not claim machine enforcement, or its absence, without that verification.

**No automatic merge.** Green CI plus two V3 `PASS` records is evidence, not
permission to merge. Merge is a separate Owner decision or an explicitly
delegated action for a named change. No agent may enable auto-merge, batch-merge
unreviewed heads or reuse a prior approval.

## 7. Unattended and night execution (T3)

New T3 unattended or night execution is **disabled by default**. There is no
standing night-shift authorization. Each proposed run must use the
[Markdown night-shift issue template](../.github/ISSUE_TEMPLATE/night-shift-task.md).
It is a Markdown issue template, not a structured YAML issue form, and becomes
available in GitHub's new-issue chooser after this file is merged into the
default branch. The run must be enabled explicitly by the Owner for that task.

A night task is eligible only when the Owner GO reference, scope, window,
environment and base SHA are recorded; the task is bounded to named files or
components and named commands; no protected-branch push, merge, deployment,
database, credential, external communication or spending is involved; the
independent reviewers are identified and are not the implementer; and stop
conditions and evidence requirements are written before the run starts.

If a stop condition occurs, the run stops and reports. It does not continue to
"finish the task", retry with broader scope or wait for a later authorization.
The template is a request form; filling it in does not authorize execution.

## 8. Security and data boundaries

Existing technical security gates cannot be bypassed by ordinary task
instructions. This includes protected-branch and required-CI controls,
exact-SHA/target/path/permission/runtime-isolation checks, tenant-isolation and
authorization tests, secret scanning, dependency audit, supply-chain checks,
`SECURITY_REMEDIATION_ENABLED=false`,
`ADMIN_ACCESS_OPERATOR_FULL_ADMIN=false`, and connector, backup, restore and
admin-access safeguards.

Agents must not request or store customer passwords, hosting, FTP, database,
Stripe or unrelated credentials; add arbitrary remote execution or arbitrary
SQL/shell surfaces; log or copy secrets, tokens, pairing codes, session cookies
or license keys; access staging, production, databases or customer data without
a named, current authorization; or weaken production code or a security test to
make an environment-specific test pass.

When a security gate blocks the work, the correct outcome is `HOLD` or a
separately authorized gate change, not a task-level exception.

## 9. Documentation and change control

- Only the files named in the authorization may change. Documentation-only work
  must not touch runtime code, CI, rulesets or deployment files.
- Do not rewrite history, force-push or squash milestone commits without an
  explicit instruction.
- Do not modify the protected primary directly.
- Update [`PROJECT_STATUS.md`](PROJECT_STATUS.md) at milestone close as required
  by `CLAUDE.md` §27.
- Update [`CLAUDE.md`](../CLAUDE.md) only when a permanent architecture or
  business rule changes.
- Keep historical documents historical. Correct a current claim in the current
  status document instead of rewriting an old snapshot as if it were current.

## 10. Cost and model selection

- Prefer available free models for low-risk, bounded tasks.
- Use an already-subscribed model when the additional capability is justified.
- New spending, purchases or additional paid services require approval.
- Critical reviews remain independent regardless of model cost.
- Do not hardcode temporary model availability or pricing into this contract.

## 11. Escalation and HOLD

Report `HOLD` and stop when the request conflicts with `CLAUDE.md`, the
Engineering Decision Protocol or Release Review Policy V3; the exact HEAD or
prescribed V3 evidence is missing or stale; an independent reviewer is
unavailable or conflicted; the work needs Owner-only authority, a new paid
service or external contact; a security gate would have to be bypassed; the
environment, target or current state cannot be verified; the authorized file
list is exceeded; or the next action would be irreversible or destructive.

`HOLD` is a successful safety outcome, not a failure to complete the task.

## 12. Reporting

In addition to the fields required by `CLAUDE.md` §26, every completion report
states the base SHA and branch, worktree path when used, files changed, what was
validated with commands and results, conflicts checked and their outcome, known
limitations and remaining risks, and the exact next action that requires an
Owner decision.

A milestone is not reported complete while it is uncommitted, unpushed,
unreviewed or otherwise incomplete unless the report says so explicitly.
