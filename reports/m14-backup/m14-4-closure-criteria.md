# M14.4 closure criteria and evidence status

Implementation lineage and staging readback are recorded in [the M14.4 closure report](m14-4-closure-report.md). Runtime criteria are implemented and exact-head CI passed on PRs #96–#99. Synthetic staging shadow evaluation, transactional Owner preview/extension/reduction, fail-closed checks, and rollback/cleanup passed. The Owner route/render/access flow is covered by authenticated HTTP feature tests.

M14.4 may close after this evidence report is merged with exact-head CI green. Interactive visual inspection was not performed because staging authentication is a manual user action; it is not a missing implementation or security test.

- Canonical six-plan entitlement catalogue, immutable versioning and effective-dated subscription snapshots are implemented; unknown accounts map to review/protected, not Basic.
- Checkout/Stripe canonical product-price mapping, subscription transitions, entitlement snapshot and schedule decision contract tests agree; replay/out-of-order event remains idempotent.
- Legacy env-driven `backup:retention` cannot perform provider deletion during rollout and cannot bypass the new policy. `NULL` never maps to zero. No two mutating retention authorities exist.
- Six cadence/GFS fixtures and deterministic UTC day/ISO-week/month algorithm pass; unverified/incomplete/failed artifacts cannot satisfy points; missing slots create visible recovery gaps; history and selection are stable/idempotent.
- Routine safety backup is separate from GFS quota; last-known-good, active restore/compensation, update dependency, holds and provider uncertainty are explicit additive protection reasons.
- Owner extension-only server enforcement, below-floor denial, recent re-auth, impact preview, source fingerprint, stale preview rejection and audit pass; no force override or provider effect.
- Upgrade/downgrade/grace/cancel/offboarding/policy-version transitions are deterministic and do not synchronously delete. Post-service execution stays blocked by WAIT_LEGAL/WAIT_ACCOUNTING until resolved by qualified authority.
- Candidate evaluator produces explainable KEEP/ELIGIBLE/BLOCKED/UNKNOWN and recovery gaps only; it never invokes provider delete. WAIT_FACT is explicit; no physical absence guarantee.
- M14.3 `BACKUP_READY` remains separate and required before scheduling; authorization, subscription/workflow and safety are separately enforced.
- Migration defaults existing rows unknown/protected; migration/rollback/reapply performs zero provider calls, queue dispatch, deletion, subscription change or mass synchronous evaluation.
- Exact-head CI green, MySQL/process concurrency for relevant races, preview-only synthetic staging validation, cleanup and post-validation health documented; all six M12 privacy/destructive gates read false before/after.
- M16 receives a stable read contract; target policy is not marketed as active until implementation/commercial rollout explicitly enables it.

Provider deletion, M12 privacy execution, M14.5, M14.7 security campaign, M14.8 overall M14 closure and M15 pilot DR are outside these closure criteria.
