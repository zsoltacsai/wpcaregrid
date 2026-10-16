# M14 AI Provider Security Boundary

## Data and authority boundary

M14 provider configuration is infrastructure groundwork only. It does not authorize customer site inventory, backup content, code, database data, credentials, security findings, billing data, privacy requests, free-form prompts or logs to leave WP CareGrid. No provider key grants the holder additional tenant or application authority.

## Required controls before any future feature

1. Data-flow inventory and purpose approval; classify each input and explicitly exclude secrets, credentials, backup archives and unnecessary identifiers.
2. Separate provider secret storage from feature authorization. Secrets are encrypted, masked after save, least privileged, replaceable and revocable.
3. Fixed HTTPS provider origins; never accept tenant-supplied endpoint URLs. SSRF egress policy, redirect policy and response bounds are mandatory.
4. Explicit per-feature enablement, tenant eligibility, user/Owner authorization, retention/deletion policy and human approval for site changes.
5. No provider response is executable code or trusted instruction. Validate output against a narrow schema and treat model output as untrusted.
6. Prompt/data minimization, no model training/data retention claims without contractual evidence, region/subprocessor review and legal/privacy review before personal data transfer.
7. Audit metadata only: provider/model/version, feature, purpose, status and opaque correlation ID. Avoid prompt/response content unless separately justified and protected.
8. Timeouts, retry budgets, circuit breaker, cost/token ceiling, queue isolation and fail-closed unavailable state.

## Prohibited M14 implications

- no “AI-powered” or autonomous marketing claim;
- no automatic remediation/update/restore from model output;
- no use of customer data to test credentials;
- no provider key exposure in logs/Owner page after entry;
- no provider default just because a key is configured;
- no AI call while configuration or authorization is missing/ambiguous.

## Owner decision application — 2026-10-16

OQ-05 approves Owner-only configuration groundwork for OpenAI, Anthropic and Google Gemini under the boundaries above. It does not approve customer-data egress, feature enablement, a default provider, autonomous operations, or “AI-powered” marketing. Provider states must remain distinct: `CONFIGURED`, `ENABLED`, `HEALTHY`, `DEFAULT` and `AUTHORIZED_FOR_FEATURE`. A non-customer health/test probe must use fixed safe input. Separate purpose, data classification, security, privacy/legal/vendor and applicable tenant/customer approvals are required before any customer data transfer.
