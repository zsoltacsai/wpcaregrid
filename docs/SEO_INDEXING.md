# Public search indexing policy

The application defaults to **noindex, nofollow**. Only the homepage and pricing page opt into public indexing, and only when all deployment checks pass:

- `APP_ENV=production`
- `SEO_INDEXING_ENABLED=true`
- `SEO_INDEXING_PRODUCTION_HOST` is the exact public production hostname (host only, no scheme or path)
- the configured `APP_URL` hostname and the current request hostname both match that configured hostname

The request `Host` value is only an additional mismatch check. It cannot enable indexing by itself. Missing or inconsistent configuration fails closed. Staging must keep `SEO_INDEXING_ENABLED=false`; if staging uses `APP_ENV=production`, it must still use its own `APP_URL` hostname and must not receive the production opt-in/hostname configuration. Do not infer environment from the request host.

No deployment environment was changed or queried as part of SEO Batch 1. Production marketing pages will stay noindex until the production owner explicitly configures the opt-in and hostname. The code fails closed for staging/non-production and for missing or mismatched host configuration; verify staging and production runtime values before release.

The only approved marketing equivalents are `/en/`, `/hu/`, `/en/pricing`, and `/hu/pricing`. Legacy `/` and `/pricing` permanently redirect to their English equivalents and are not sitemap entries. New marketing pages must explicitly opt in in their Blade layout call and be added to rendered-HTML regression tests. Preview pages, checkout and authentication pages remain noindex. Account, Owner and Operator access remains governed by existing authentication and authorization; robots directives are not access control.

`/robots.txt` is served dynamically through Laravel so it can advertise the sitemap only when the same production indexing gate passes. It intentionally does not disallow HTML paths: crawlers need to fetch pages to see `noindex` metadata. `/sitemap.xml` contains only the four approved canonical marketing URLs and is unavailable unless the production gate passes. The application-level authentication and authorization rules remain the protection for private data.

Canonical URLs are generated from configured `APP_URL`, never the request `Host` header, and omit query parameters. Owner-provided localized canonical values are accepted only when they exactly match that page's expected locale URL. Home and pricing emit reciprocal `en`, `hu`, and `x-default` alternates only when all content sections used by both variants resolve in their requested locale. The same eligibility logic feeds sitemap alternates.
