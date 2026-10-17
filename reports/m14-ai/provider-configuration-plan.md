# M14 AI provider configuration plan index

The M14.5 source-based plan and security/domain design supersede the earlier concept note. This planning work changes no runtime behavior.

- [M14.5 plan](m14-5-plan.md)
- [Fixed provider registry and probe contract](m14-5-provider-registry.md)
- [Credential lifecycle](m14-5-credential-lifecycle.md)
- [Status domain](m14-5-status-domain.md)
- [Zero customer-data egress contract](m14-5-zero-data-egress-contract.md)
- [Health-probe matrix](m14-5-health-probe-matrix.md)
- [Owner security matrix](m14-5-owner-security-matrix.md)
- [Test plan](m14-5-test-plan.md)
- [Findings](m14-5-findings.md)
- [Implementation backlog](m14-5-implementation-backlog.md)
- [Closure criteria](m14-5-closure-criteria.md)

The approved allowlist is OpenAI, Anthropic and Google Gemini. Configuration is not feature enablement, health, a default, or feature authorization. Customer-data egress remains unauthorized. Do not build inference, routing, custom endpoints, models, prompts, or autonomous actions in M14.5.
