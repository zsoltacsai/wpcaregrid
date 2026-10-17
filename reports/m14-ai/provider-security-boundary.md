# M14 AI provider security boundary

This M14.5 design refines the earlier boundary note. See [zero-data-egress contract](m14-5-zero-data-egress-contract.md), [credential lifecycle](m14-5-credential-lifecycle.md), and [Owner security matrix](m14-5-owner-security-matrix.md) for implementation-ready rules.

## M14.5 permitted operation

Only an explicit Owner request may cause a provider health probe. The probe sends the stored provider credential in the provider's documented authentication mechanism to a fixed official HTTPS metadata-list endpoint. It has no body and accepts no arbitrary payload. The model metadata response is bounded, minimally checked, discarded, and never displayed or stored. Cost/account quota is not claimed to be zero. No customer or site data, security/backup data, code/content, logs, PII or other credentials may leave WP CareGrid.

## Must remain absent

- default provider, model preference, routing or fallback;
- completion/chat/embedding/moderation, streaming, tools, file upload or AI feature client;
- customer/site/security/backup/operations data path to a provider;
- AI enablement, feature authorization, autonomous actions or “AI-powered” claim;
- arbitrary provider URL, host, proxy or endpoint;
- Operator/Customer visibility of credentials or even provider status.

Future AI work requires its own purpose/data classification, provider and vendor review, privacy/legal approval, security review, tenant/customer authorization and human approval model. Configuration alone provides none of these.
