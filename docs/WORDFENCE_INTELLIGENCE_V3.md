# Wordfence Intelligence v3 feed

WP CareGrid uses the complete Wordfence Intelligence **Scanner Feed**. The feed is used only for detection; it does not enable automated remediation.

## Verified API contract

- `GET https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner`
- Header: `Authorization: Bearer <Owner-managed API key>`.
- The response is one complete JSON object keyed by vulnerability UUID. There is no pagination or narrower query parameter. Wordfence documents the fields `id`, `title`, `software`, `informational`, `references`, `published`, and `copyrights`. Each software item provides `type`, `slug`, `affected_versions`, `patched`, and `patched_versions`.
- The Scanner Feed is intentionally detection-only. CVE, CVSS, description and other detailed fields belong to the Production Feed and are not assumed here. Missing CVE/CVSS stays missing and no severity is invented.
- Wordfence applies usage limits and returns HTTP 429 when the application exceeds them. Its February 2026 notice states the default limit is one request per 30 minutes. The existing once-daily refresh is below that limit; do not add rapid retries or per-customer fetches. No stronger refresh recommendation is published in the feed docs.
- Wordfence reported a 123 MB complete JSON feed in February 2026. The importer streams records with a 160 MiB hard download ceiling, a 2 MiB per-record ceiling, a 100,000 record ceiling, and a 128 MiB compact-index ceiling. It checks disk headroom before a network download. Any exceeded limit, interrupted response, malformed record, or write failure rejects the generation and preserves the active index.

## Security and operational behavior

- Only the exact official HTTPS endpoint is accepted. Redirects are disabled. The hostname is resolved before the request; reserved/private addresses are discarded and cURL is pinned to the resolved public address for that request.
- The API key is required and read from the already Owner-managed encrypted credential at runtime. It is not copied into `.env`, persisted into feed provenance, or written to logs. HTTP failures are logged with status only; synchronization failures log the exception class without provider response text or headers.
- The streamed response is bounded and staged in a mode-0600 file. One shared cache lock serializes manual, scheduled, and local imports. The complete normalized generation is written under a private staging directory. A small manifest is atomically renamed last; scanners only use that manifest and its complete generation. Stale or invalid manifests and corrupt generations report `unavailable`, never a clean result.
- Provenance records the Wordfence v3 format, generation, completion time, byte count, record count, software-entry count, and group count. Feed data older than `SECURITY_VULNERABILITY_MAX_AGE_HOURS` (72 by default) is unavailable.
- Daily refresh is below the provider's documented default rate limit. Failed refreshes retain the last known-good generation, but it becomes stale after 72 hours.

## Licensing and attribution release gate

Wordfence's current feed documentation describes free personal and commercial use. The current Wordfence Intelligence Terms (last updated January 26, 2026) grant copying, derivative-work, sublicense and distribution rights subject to reproducing the company copyright designation, the license and disclosed licensor licenses. API keys must remain confidential and may not be shared, transferred, sold or sublicensed. These terms support a paid-service use in principle, subject to the stated notices and restrictions; WP CareGrid does not pass the key to customers.

Where supplied, the index and resulting operator finding retain the Wordfence record hyperlink, Defiant notice and license text/URL, and MITRE notice and license text/URL. The Scanner Feed does not include CVE/CVSS data; MITRE data is retained only if explicitly present. The released customer/operator display must preserve these attribution details with any displayed vulnerability data. An Owner/legal review of the current Terms and the actual account's API-key authorization remains a release gate. If that review is not approved, leave `SECURITY_VULNERABILITY_SOURCE=none`.

Official references:

- [V3 feed documentation](https://www.wordfence.com/help/wordfence-intelligence/v3-accessing-and-consuming-the-vulnerability-data-feed/)
- [Wordfence Intelligence Terms and Conditions](https://www.wordfence.com/wordfence-intelligence-terms-and-conditions/)
- [API transition and size/rate-limit notice](https://www.wordfence.com/blog/2026/02/important-notice-preserving-free-access-while-evolving-the-wordfence-intelligence-vulnerability-api/)

## Staging activation runbook

Do not run this during development. Run only after the PR is merged and deployed, and after Owner/legal approves the licensing gate.

1. Keep the encrypted Owner-managed key as configured. Do not copy it to `.env`. Keep remediation disabled and all M12 privacy gates unchanged.
2. Set the deployed application configuration to:

   ```dotenv
   SECURITY_VULNERABILITY_SOURCE=wordfence
   SECURITY_VULNERABILITY_FEED_URL=https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner
   SECURITY_VULNERABILITY_SCHEDULE_ENABLED=false
   ```

   Keep scheduled downloads disabled during the initial controlled import. Clear/rebuild Laravel's config cache using the normal deployment process, restart long-running workers, and do not print `.env` or credentials.
3. Verify effective values through an approved secret-free Owner/system status surface: source is `wordfence`, URL equals the official endpoint, key reports configured/Owner-managed, and no key value is returned. Confirm the scheduler does not yet queue provider synchronization.
4. Run exactly one controlled `php artisan security:vulnerabilities:sync` from one application node. Capture only exit status and secret-free output. Do not retry a 429 before the provider's limit window.
5. Verify a fresh manifest/generation, nonzero record/entry/group counts, feed byte count within limits, and current timestamp. Confirm no `.stage-*` or raw download remains. Do not expose the raw feed, key, or per-record license text in general logs.
6. Verify the vulnerability source is available, a deliberate fixture-controlled scan reports a known affected version and a patched version does not, and unknown/missing versions yield partial/unavailable coverage rather than clean. The scheduled sync remains off by default; enable it with `SECURITY_VULNERABILITY_SCHEDULE_ENABLED=true` only after this check and required attribution display review.
7. If any check fails, set source to `none` and scheduled sync to `false`, keep remediation disabled, verify scans report unavailable, and retain the last-known-good index for diagnosis. A stale feed must not be represented as current. Correct the cause before a new controlled import.
8. Confirm the customer and operator displays include the per-record Wordfence link and required Defiant/MITRE notices and license text/links whenever applicable. If commercial redistribution or the account/key use is not approved, do not activate the feed; obtain written authorization from Wordfence or use another licensed provider.
