# M12 Wave 6 Owner Questionnaire

**Baseline:** `57fe3ef74b099f5ae6844f1ba6414149cc079d54`
**Status:** No new Owner product-policy decision is required to proceed with the non-destructive Wave 6 scope.

## Decision review

| Area | Classification | Existing decision / fact | Wave 6 handling |
|---|---|---|---|
| Customer backup post-service eligibility | ALREADY RESOLVED | RET-04 and RET-33: 30 days, independent of active-plan retention | Preserve as an eligibility input only; do not change product cadence or execute retention |
| Destructive retention | ALREADY RESOLVED | RET-18: `M12_DESTRUCTIVE_RETENTION_ENABLED=false` | Keep disabled; no Wave 6 action path enables it |
| Restore and recovery-point guards | ALREADY RESOLVED | RET-22/23/24: active restore/update blocks deletion; protect the final verified recovery point | Treat as non-configurable invariants |
| Provider deletion and verification | PROVIDER FACT / LEGAL / ACCOUNTING | Region, object versioning/lock, provider-side deletion semantics, legal entitlement, and accounting exceptions remain unverified in the provider registry and existing handoffs | Report `WAIT_FACT` / review blockers; use fakes in automated validation; no live provider deletion |
| Privacy tombstone purpose and duration | ALREADY RESOLVED / LEGAL / ACCOUNTING | RET-17 approves the replay concept, while duration and legal/accounting basis remain provisional | Build an independent-journal interface and fail-closed recovery gate; do not select a statutory duration or activate erasure |
| Independent journal infrastructure | TECHNICAL / PROVIDER FACT | The platform backup S3 service is a separate data plane from the primary DB, but current source does not establish immutable/versioned semantics or independent account/provider resilience | Do not assume capability. Keep the journal unavailable until a verified implementation and recovery proof exist; this is a provider-fact gate, not a product-policy choice |
| New customer-facing retention behavior | OWNER PRODUCT POLICY | No request to change the approved policies or the commercial backup schedule | Out of scope; no new question |

## Questionnaire result

There are **0 unanswered Owner product-policy choices that block safe Wave 6 engineering**. The previously approved RET defaults and M12 decisions remain authoritative. Provider capability facts are to be verified or surfaced as unknown; legal and accounting approvals remain provisional. Engineering must not turn those unknowns into deletion authority.

No response is requested. The work may continue with report-only reconciliation, mocked deletion verification, and fail-closed tombstone/recovery handling.
