# M14.4 ordered implementation backlog

## Implementation status

PR #96 closed M144-01 retention containment; PR #97 implemented M144-02/03 entitlement catalogue/snapshots; PR #98 implemented M144-04/05/06/07 GFS shadow evaluator, protection and scheduler comparison; PR #99 implemented M144-08/09/10 Owner extension, preview and desired post-service setting. All four executable PRs merged with exact-head 7/7 CI. Exact-SHA staging shadow and transactional synthetic Owner preview/reduction evidence is complete and cleanup verified. Only report PR merge/CI evidence remains; no destructive work is authorized. See [closure report](m14-4-closure-report.md).

No item authorizes backup deletion.

| ID | Priority | Domain / dependency | Files/systems | Migration? / code | Destructive? | Tests | Staging / rollback | Acceptance |
|---|---|---|---|---|---|---|---|---|
| M144-01 | P0 | Legacy deletion containment; first | `backup:retention`, `Retention`, scheduler, config | Runtime guard/report-only change; separate explicit backup-retention gate if needed | No; must prevent effects | Env set/unset command path, zero provider/queue calls | Read-only staging; rollback cannot re-enable env-driven deletes | Proven legacy path cannot delete during M14.4 rollout |
| M144-02 | P0 | Canonical six-plan domain | `config/pricing.php`, `PricingCatalog`, backup policy adapter | Extend canonical plan catalogue; no duplicate IDs | No | All six values, price identity contract | Feature flag/shadow; revert adapter | One source identity for price/checkout/entitlement |
| M144-03 | P0 | Versioned snapshot | Subscription lifecycle/model/migrations | Immutable entitlement history & backup origin reference, additive safe defaults | No | Migration empty/old rows, Stripe event replay/order | Synthetic DB, rollback/reapply | Unknown mappings stay protected; effective time/idempotency correct |
| M144-04 | P0 | GFS shadow evaluator | Backup models/services/evaluation store | UTC slot selector, gaps, reason output | No | 6-plan × history/time/protection fixtures | Report-only synthetic/staging | Deterministic candidates and no provider call |
| M144-05 | P0 | Scheduling integration | `backup:schedule`, `BackupPolicy`, M14.3 `BACKUP_READY` | Idempotent entitlement cadence and bounded gap handling | Backup creation is mutating but non-destructive | Unique slot, capability false, failure/no burst | Synthetic site; keep old safe route | Requires entitlement + BACKUP_READY + auth/workflow/safety |
| M144-06 | P1 | Lifecycle consistency | `SubscriptionSync`, management/confirmation, webhook idempotency | Append snapshots for activation/effective change/cancel states | No | upgrade/downgrade/grace/cancel/replay | Stripe TEST; rollback to previous reader | No duplicate or early transition; cancellation never deletes |
| M144-07 | P1 | Protection domain | backups/restores/update dependencies/incidents | Reasoned protection relationships and bounded history | No | last-good, active restore, safety, holds | Synthetic DB only | Multiple reasons independently protect |
| M144-08 | P1 | Owner extension/reduction | Owner routes/forms/services, M14.1 auth | Extension-only policy, immutable preview, audit | Policy metadata only | RBAC, floor denial, re-auth, stale hash, zero effects | Synthetic Owner staging; revert UI not policy history | Reduction cannot bypass floor and never queues deletion |
| M144-09 | P1 | Post-service preference | Owner config/policy settings | Store desired duration only; legal/accounting gate status | No | 0 semantics, unknown gate stays blocked | Preview-only | No duration presented as approved; no candidate execution |
| M144-10 | P0 | Shadow reconciliation/closure | reports, command output, M10 signals | Compare legacy and GFS, gap/candidate metrics | No | deterministic drift report | Exact merged staging SHA, synthetic only | One authority, zero deletes, evidence closed |

## Dependencies and PR grouping

Recommended 3 executable PRs: PR-A M144-01–03 (safe cutover + domain/schema), PR-B M144-04–07 (shadow selection/scheduling/lifecycle), PR-C M144-08–10 (Owner preview, reconciliation and closure fixes). Add report-only closure PR after exact executable SHA staging evidence. Each code PR needs exact-head required CI. No production deploy or provider deletion in M14.4.
