# Structured Data Plan

## Current state

No JSON-LD / `application/ld+json` output or schema.org vocabulary was found in the marketing templates or app source search. Existing page metadata is HTML head metadata; it is not structured data.

## Recommended minimal types

| Type | Appropriate use | Data source | Gate |
|---|---|---|---|
| `Organization` or a more specific organization subtype, after entity facts are approved | one shared brand/provider identity | approved legal/company identity, canonical URL, logo and public contact | do not invent/expose private company fields |
| `WebSite` | site name and canonical origin | same deployment canonical origin and brand name | validate origin against approved production host |
| `WebPage` | home/pricing localized page facts | rendered page title, canonical, language | should match visible content and canonical |
| `BreadcrumbList` | future hierarchical feature/resource pages only, if visible breadcrumbs exist | route hierarchy | no breadcrumb schema without matching visible links |
| `Service` | only if public page describes a defined service and provider/scope accurately | reviewed service facts | optional; avoid generic schema duplication |
| `Product`/`Offer`/`SoftwareApplication` | only after choosing accurate entity semantics and dynamic offer rules | shared PricingCatalog and visible price/currency/billing terms | no static duplicated prices; verify visible price, active market, currency and tax display |
| `FAQPage` | not a default objective; only if current search feature rules and visible Q&A support it | visible FAQ content | no rich-result promise; Google display eligibility can change |

Do not add review/rating, availability, or fabricated Offer values. Do not emit plan prices for a market/interval that is not actually sellable. Prefer HTML content and machine-readable facts derived from the same typed source; no manually copied JSON-LD strings.

## Test plan

- Parse JSON-LD as JSON and validate expected `@context`, `@type`, stable identifiers and URL format.
- Every URL must use the current canonical origin and correct localized path.
- For Offer/Product data, assert currency/amount/interval against `PricingCatalog` and visible page copy; test HUF and every enabled international currency only where offerings exist.
- Assert no prices/claims appear in JSON-LD that are not visible on the page.
- Validate on deterministic fixtures; external Google rich-result tools are advisory, not required CI dependencies.

Technical references: [Google structured data policies](https://developers.google.com/search/docs/appearance/structured-data/sd-policies), [Google software app structured data](https://developers.google.com/search/docs/appearance/structured-data/software-app).
