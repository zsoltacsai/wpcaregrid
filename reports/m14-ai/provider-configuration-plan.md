# M14 AI Provider Configuration Plan

**Current source audit:** no AI provider client, provider keys, configuration surface, routes, job or customer data flow identified in application/config/routes. No provider is configured or authorized by this source finding. This is not a claim about external account state.

## Separate provider states

| State | Meaning | Default |
|---|---|---|
| CONFIGURED | provider identifier and secret saved and syntactically valid | false |
| ENABLED | platform permits health checks/requests through an explicit feature switch | false |
| HEALTHY | recent bounded health check succeeded | unknown until checked |
| DEFAULT | selected provider for one explicitly approved future feature | none |
| AUTHORIZED FOR FEATURE | a reviewed feature/data purpose has a separate data-access and action grant | false |

One state must not imply the next. A configured key is not consent, a customer-data grant, an AI feature, or permission for autonomous changes.

## Minimal M14 foundation proposal

- provider registry allowlisting exact provider and API origin; no caller-supplied URL;
- encrypted write-only credentials using a versioned key model and masked status;
- explicit environment separation and disabled-by-default feature capability;
- bounded connect/read timeouts, response size, redacted request/response and health probes;
- test connection uses fixed non-customer probe text and cannot submit site content;
- audit events for add/replace/remove/enable/test without key or prompt content;
- queue-level egress/data allowlist; no secrets or tenant/site content in telemetry;
- no model routing/default until Owner selects provider and separate feature/purpose review is approved;
- no autonomous action or write-back capability in M14 config foundation.

## Required tests

Missing/invalid key; key masking; encrypted storage and decryption after key rotation plan; outbound endpoint allowlist; timeout/oversize response; secret redaction; concurrent key replace vs test; disabled provider refuses calls; disabled integration has zero network egress; test API never includes customer data; tenant A config cannot be read by tenant B (global configuration should be Owner/system-only).

Provider selection, AI scope, and whether Owner Console exposes configuration require Owner decisions. Legal/privacy and vendor transfer review remain prerequisites before customer data is sent to any provider.
