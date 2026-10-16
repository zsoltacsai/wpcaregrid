# M14 AI Provider Configuration Plan

**Current source audit:** no AI provider client, provider keys, configuration surface, routes, job or customer data flow identified in application/config/routes. No provider is configured or authorized by this source finding. This is not a claim about external account state.

## Separate provider states

| State | Meaning | Default |
|---|---|---|
| CONFIGURED | provider identifier and secret saved and syntactically valid | false |
| ENABLED | an AI product feature is enabled for execution | false throughout M14; safe bounded health/test probes are a separate configuration function and carry no customer data |
| HEALTHY | recent bounded health check succeeded | unknown until checked |
| DEFAULT | selected provider for one explicitly approved future feature | none |
| AUTHORIZED FOR FEATURE | a reviewed feature/data purpose has a separate data-access and action grant | false |

One state must not imply the next. A configured key is not consent, a customer-data grant, an AI feature, or permission for autonomous changes.

## Approved M14 configuration-only foundation

- Owner-only provider registry for the approved allowlist **OpenAI, Anthropic and Google Gemini**, each with fixed API origin; no caller-supplied URL;
- encrypted write-only credentials with masked configured state, replacement and revocation;
- explicit environment separation and disabled-by-default feature capability;
- bounded connect/read timeouts, response size, redacted request/response and safe health/test probes;
- health/test connection uses fixed non-customer probe input and cannot submit site/customer content;
- audit events for add/replace/revoke/health-test without key or prompt content;
- no customer-data, security finding, backup, code/content, credential or other site data egress;
- no default provider, model routing, feature enablement or authorization inferred from configuration;
- no autonomous action or write-back capability in M14 configuration groundwork.

## Required tests

Missing/invalid key; key masking; encrypted storage and decryption after key rotation plan; outbound endpoint allowlist; timeout/oversize response; secret redaction; concurrent key replace vs test; disabled provider refuses calls; disabled integration has zero network egress; test API never includes customer data; tenant A config cannot be read by tenant B (global configuration should be Owner/system-only).

OQ-05 was approved on 2026-10-16 for this configuration-only groundwork and the three-provider registry above. It does not select a default or approve an AI feature, customer-data transfer, autonomous action, or marketing claim. Legal/privacy and vendor-transfer review, a defined purpose/data classification, security review, and applicable tenant/customer authorization remain prerequisites before customer data is sent to any provider. Provider account access or vendor setup is an external action only if an approved non-customer health-check integration is actually configured.
