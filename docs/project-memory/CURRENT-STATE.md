# WP CareGrid — Current State (dated snapshot)

**Observed:** 2026-11-19 approximately 20:40 CET, Europe/Budapest.  
**WARNING:** This is a handoff snapshot, not a live dashboard. Recheck before use.

## Verified at this snapshot

| Area | Observation | Source |
|---|---|---|
| Protected primary | `claude/ecstatic-wozniak-oemyuf` at `08cf12c1be9810a2be4afeb73dd335233830a2f5` | [GitHub branch](https://github.com/zsoltacsai/wpcaregrid/tree/claude/ecstatic-wozniak-oemyuf) |
| Integration ledger | Issue #174 remains OPEN; most recent retrieved record: comment `7020055888`, dated 2026-11-19 19:35:38 UTC | [#174 / GO-20.38](https://github.com/zsoltacsai/wpcaregrid/issues/174#issuecomment-7020055888) |
| GO-20.38 | Historical reconciliation and gated plan recorded; Hagrid, Moody, Hermione: **PASS_WITH_CONDITIONS** on the plan (not a live-operation GO) | Same ledger record |
| Production G1 MySQL | `HOLD`; GO-5.4 start `NO-GO`; no new host action authorized by the review | Same ledger record |
| Production-preparation environment | Existing VPS `stg1.wpcaregrid.com` is used for future production preparation despite its name; a **separate staging VPS is planned later** | Latest Owner correction, 2026-11-19 |
| Operational access | `deploy` is limited; Owner-controlled root console is separate | Owner operations contract |
| Release target | Full Laravel application, HU/EN marketing-only; production activation not yet authorized | [Master roadmap](../MASTER_ROADMAP_AND_INTEGRATION_PLAN.md) |

## Important unknowns

- CI success for the *latest* protected-primary SHA was **not verified during this documentation snapshot**. Retrieve exact-head CI before claiming PASS.
- The environment's live runtime state was **not inspected** as part of this project-memory task.
- The ledger may acquire newer comments after the timestamp above; do not assume this is still its newest record.
- Detailed start-gate blockers and their dispositions belong to the current ledger; do not copy stale checklist language into an agent prompt or treat planning review as permission.

## Next permitted task

Create and review this project-memory documentation PR only; reconcile any conflict with existing authoritative documents. No production MySQL start, service operation, installation, deployment, root command, `reset-failed`, `daemon-reload`, AppArmor or datadir change is authorized by this continuity work.

## Next-chat handoff template

```text
WPC CONTINUITY CHECK (Europe/Budapest timestamp: ...)
Protected primary / exact SHA: ...
Exact-head CI: PASS / HOLD / UNKNOWN (link)
Latest #174 ledger: ... (date, link)
Relevant Owner decision: ... (date, source)
GO/HOLD/NO-GO by specific action: ...
Verified changes since last checkpoint: ...
Closed/forensic-only items not reopened: ...
Authorized next safe step: ...
Conflicts or unknowns: ...
Host actions performed in this conversation: NONE / explicit evidence
```
