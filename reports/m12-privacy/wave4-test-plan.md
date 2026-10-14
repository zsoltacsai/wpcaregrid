# Wave 4 Report-Only Retention Engine — Test Plan

No Wave 4 implementation or tests are added by this planning task. This is the acceptance plan for a later implementation.

## Unit tests

1. **Deterministic timing:** fixed UTC clock; exact boundary, zero-day eligibility, future trigger, null trigger/duration, DST display conversion, unsupported unit.
2. **Policy selection:** category/trigger/version selection, not-yet-effective, draft, superseded, conflicting versions, missing policy, missing legal/accounting reference.
3. **Result classification:** all proposed result states and blocker precedence; unknown input never becomes `ELIGIBLE_DRY_RUN`.
4. **Version pinning:** evaluation stores exact policy version and snapshot time; later policy change does not rewrite old result.
5. **Category coverage:** known registry categories; unknown category is surfaced, not skipped silently.
6. **No destructive outcome:** evaluator exposes no delete/anonymize completion; gate false and true test configurations both produce report-only output and zero mutation. Wave 4 must not consume `isExecutable()` to authorize action.

## Source adapter / feature tests

- Account/service closure versus erasure request cooling-off are separate triggers.
- Active plan backup retention and post-service retention are separate rules; existing backup behavior is unchanged.
- Platform backup candidates are separate from customer-site backup candidates.
- Webhook raw payload and idempotency evidence are separate categories.
- Email rendered body and delivery metadata are separate categories.
- Accounting evidence with missing approval is `BLOCKED_ACCOUNTING`; profile data does not inherit accounting retention automatically.
- Active restore, update, compensation, export lock, unresolved provider task and last verified recovery point produce fail-closed blockers.
- Missing lock/provider/source state returns `UNKNOWN`/`MANUAL_REVIEW`.
- Tenant-scoped customer discovery cannot include or reveal another tenant's candidate; Owner aggregate counts contain no PII.

## Impact preview tests

- Newly eligible/no-longer-eligible/blocked counts are deterministic and pinned to policy version and source watermark.
- Partial source scan is visibly partial; it cannot display a complete/zero result.
- Editing a draft invalidates prior preview; a preview cannot be activated against a different policy hash/version.
- Policy change does not schedule jobs or delete data; actual deletion count remains zero.
- Concurrent activation/evaluation detects stale version rather than mixing versions.

## Abuse/security tests

- Non-Owner cannot read global previews or create/activate policy versions.
- CSRF protection, authorization, audit actor attribution, optimistic concurrency/version uniqueness.
- No secrets, raw request bodies, addresses, account emails, site URLs, provider credentials or invoice contents in logs, labels, metrics, aggregate response, or exception messages.
- Oversized/ambiguous source rows are bounded and reported; query injection/path traversal are not applicable through user-provided category/trigger codes (strict allowlist required).
- No provider delete, queue dispatch, filesystem delete or identity mutation regardless of policy/gate values.

## Integration and operational checks

- Use disposable synthetic fixtures and test DBs only; no provider mutations.
- Verify candidate reads use indexes/keyset pagination and do not load full backup/webhook/email payloads.
- Verify feature health reports fail closed if policy/source registry unavailable.
- Verify dry-run audit and preview history is append-only or immutable.
- Full relevant Laravel privacy, backup-retention, queue/restore, system-status suites; Connector tests only if restore lock integration changes.
- Pint/PHP syntax/static checks plus required CI. No staging destructive validation for Wave 4.

## Acceptance evidence

Exact SHA CI results, test/ assertion counts, test database type, skipped test reason, sample synthetic preview with no PII, evidence of unchanged source rows/provider mocks, zero queue dispatch/delete calls, destructive gate false, and review of query bounds/tenant authorization. Any skip in a required safety gate needs a justified alternative verification; no unexplained skip is acceptable.
