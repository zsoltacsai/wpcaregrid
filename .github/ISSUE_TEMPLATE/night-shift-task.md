---
name: Night-shift task
about: Request an unattended or night execution task. Disabled by default; requires an explicit Owner GO.
title: "[night-shift]"
---

> This template is a **request form**. Filling it in does not authorize
> execution. T3 unattended/night execution is disabled by default and starts
> only after an explicit Owner GO for this task. A general instruction, a
> previous approval or this issue alone is not authorization.

## 1. Authorization (required)

- **Owner GO reference:**
- **Authorized by:**
- **Authorization scope:**
- **Execution window (UTC, start–end):**
- **Environment:**
- **Base ref (latest protected primary):**
- **Base SHA (40 hex, verified at run time):**
- **Task ID / milestone:**

## 2. Task

- **Assigned agent(s):**
- **Role(s):**
- **Single bounded outcome:**

## 3. Allowed scope

Authorization is **task-scoped**. A role does not grant repository-wide write
access, host access, secrets, deployment authority or external-communication
authority. Only the files, commands, environment and window listed here are
allowed.

- **Files or directories:**
- **Branch / worktree:**
- **Commands allowed:**
- **Network access:**
- **Environment and time window:**
- **Maximum runtime:**
- **Artifacts to produce:**

### Model and cost

- **Model(s) allowed:**
- **Basis:** prefer a free model for a low-risk bounded task; use an
  already-subscribed model where additional capability is justified.
- **Spending approval reference:** none, unless new spending was separately
  approved.
- **Critical review independence confirmed:** yes / no.

New spending, purchases, subscriptions or additional paid services are not
authorized by this template.

## 4. Explicitly out of scope

List everything the run must not touch, especially other milestones, runtime
code, CI, rulesets, deployment files and unrelated documentation.

## 5. Prohibited actions (must remain true)

- [ ] No push to a protected primary branch.
- [ ] No force-push, history rewrite or branch deletion.
- [ ] No pull-request merge, auto-merge or merge queue enablement.
- [ ] No GitHub ruleset, branch-protection or CI configuration change.
- [ ] No staging, production, database, credential or secret-store access.
- [ ] No live-data, restore, service-start or privileged-helper operation.
- [ ] No external communication, customer contact or provider ticket.
- [ ] No spending, purchase, subscription or new paid service.
- [ ] No bypass or weakening of a technical security gate.
- [ ] No gate or policy change under this task; that needs its own reviewed
      and authorized change.
- [ ] No repository-wide or host permission inferred from a role.
- [ ] No secrets, credentials or customer data in logs, reports or artifacts.
- [ ] No V3 verdict from an agent that authored, co-authored, pushed or
      materially directed the change.
- [ ] No continuation after a stop condition.

## 6. Required reviews

- `integration_qa` agent: **Hermione** (or another eligible independent agent).
- `appsec` agent: **Mad-Eye Moody** (or another eligible independent agent).
- The two roles must use different agent labels.
- Neither reviewer may review their own work.
- Both verdicts must bind to the exact final HEAD and the prescribed evidence.
- Any new commit invalidates earlier verdicts.
- V3 is a mandatory process requirement. Do not claim it is machine-enforced
  unless the current CI/helper implementation has been verified.

## 7. Evidence required

- Exact base and final HEAD SHA.
- Files changed and a diff summary.
- Commands run, environment and results, including failures and skips.
- Tests or checks that were not run, and why.
- Known limitations and residual risk.
- Report path plus SHA-256 for each V3 verdict.
- Confirmation that no prohibited action occurred.

## 8. Stop conditions

The run stops and reports `HOLD` if:

- the base SHA, environment or authorization cannot be verified;
- the work would exceed the allowed files, commands or time window;
- a security or technical gate blocks the work;
- a secret, credential or customer datum could be exposed;
- a reviewer is unavailable or conflicted;
- the next step needs an Owner-only decision or external contact;
- the next step would be irreversible or destructive.

## 9. Merge and deployment policy

- **No automatic merge is authorized.**
- CI green and two V3 `PASS` records are evidence, not permission to merge.
- Merge requires a separate Owner decision or an explicitly delegated action
  for this named change.
- Staging and production each require their own authorization; a night Coding
  run never authorizes either.

## 10. Preflight checklist

- [ ] Latest remote references fetched.
- [ ] Base SHA matches the latest protected primary and is recorded.
- [ ] Isolated worktree and task branch created; existing work untouched.
- [ ] Assigned agent and role match the seven-agent roster (`AGENTS.md` §3)
      and the task scope.
- [ ] Authorized file list, commands, environment and window match this issue.
- [ ] Relevant security rules for the task have been loaded.
- [ ] Model/cost choice is recorded; no new spend or paid service is needed.
- [ ] Independent reviewers identified and eligible.
- [ ] V3 enforcement status will be verified before any machine-enforcement claim.
- [ ] Stop conditions and evidence requirements understood.
- [ ] No secret, credential or production access is needed.

## 11. Owner approval

- [ ] I authorize this task within the scope, window and constraints above.
- [ ] I understand that this does not authorize a merge, deployment or any
      external, financial or production action.

**Owner GO reference:**
**Date (UTC):**
