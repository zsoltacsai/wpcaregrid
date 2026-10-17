# M14.5 zero customer-data egress contract

## Permitted outbound request

Only after an Owner explicitly selects **Test connection**, one provider's stored credential may be sent as that provider's required authentication material to that provider's fixed official HTTPS metadata endpoint. These endpoints are non-generative model-list metadata requests, with no request body. Provider-side API accounting/retention is not claimed to be zero or absent. No scheduled health probe.

| Data class | May leave in M14.5? | Rule |
|---|---|---|
| Provider credential | Yes, narrowly | Only in the provider's documented auth header, to its fixed host; never URL/query/log/redirect target |
| Static health marker | No | GET metadata endpoints require no body; do not add one |
| Customer/site/business data | No | IDs, domain, email, subscription, tenant identifiers excluded |
| Security/malware data | No | Findings, incidents, paths, scanner output excluded |
| Backup data/metadata | No | IDs, manifests, names, sizes, content excluded |
| WordPress code/content/inventory/logs | No | No product data flows into health checker |
| PII | No | No Owner/customer identity sent |
| Other credentials | No | No cookies/session, DB, Connector, Stripe or infrastructure secrets |

## Narrow API invariant

The future interface must be `check(providerId)` (or equivalent), with no arbitrary payload, prompt, message, URL, model, tool, file, site, user or tenant argument. Adapter construction is server-owned and dependency injection is the only fake-transport seam. Do not create `chat`, `complete`, `analyzeSite`, or general request methods. No adapter is injected into Security Engine, Backup Engine, Monitoring, Site Health, reports, malware, WooCommerce, tickets or customer APIs.

## Sentinel regression

Use synthetic values for customer email, domain, website UUID, customer ID, security finding, backup UUID, plugin inventory, log line and content. Call each fake provider adapter and capture URL, query, all headers, request body, logs, audit, response, session, view and exception output. Assert none of those sentinels appear anywhere in egress or persistent/output channels. Exempt only the synthetic provider credential from captured authentication header, and assert it appears nowhere else. Assert no probe happens on save, page render, queue, scheduler, health check or boot.

This contract is testable without real provider access. Customer data processing by AI remains `NOT AUTHORIZED / FUTURE REVIEW REQUIRED`; M14.5 is not GDPR, privacy, legal, vendor terms, or regional transfer approval.

## Implementation evidence addendum — 2026-10-17

M14.5 executable work is merged and deployed to staging at `3debba99c677b68465cf439733f440442fbd0f22`. PR #102 exact CI 39017126211 and PR #103 exact CI 39020595623 each passed all 7 required jobs; merged-SHA run 39021792985 also passed all 7, including MySQL process/concurrency. The staging fake-provider transaction passed and was rolled back. There were no real provider credentials, real vendor calls, or customer-data egress. No AI feature/default/feature authorization exists. See [closure report](m14-5-closure-report.md) for full bounded evidence and limitations.
