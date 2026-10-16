# Consent and Measurement Plan

## Audited state

No cookie banner/consent manager, consent persistence, Google Consent Mode call, GA4/GTM script, or third-party analytics integration was found in source. Existing session and locale cookies are not an analytics consent system. M12 cookie handoff records legal classification as unresolved. Do not infer which cookies legally require consent or choose a legal basis.

## Safe technical architecture

- Analytics configuration must be empty/disabled by default, especially on local, CI and staging.
- Load no analytics/advertising scripts before the configured, reviewed consent decision; keep core page, checkout and authentication behavior independent of analytics.
- Model categories independently where the approved policy requires them. Google Consent Mode has separate `analytics_storage`, `ad_storage`, `ad_user_data`, and `ad_personalization` settings; technical availability does not establish legal requirements.
- Persist only the minimum consent choice, policy/version reference, timestamp and preference needed by approved policy. Do not store sensitive content or use analytics identifiers as authentication.
- Withdrawal must update the runtime gate and prevent subsequent optional requests; test reload, navigation and locale change.
- Script allowlist/CSP and vendor destination inventory should be reviewed by M14 AppSec when third-party tags are introduced.
- No Owner UI toggle that casually changes legal consent behavior. Policy copy and jurisdictional choices remain reviewed/configured separately.

## Consent Mode decision boundary

If Google tooling is adopted, implement the default state and updates only after the Owner/legal policy questionnaire is complete. Regional configuration, whether denied-state pings are sent, ad features, and retention are policy decisions. Keep the library adapter capable of passing explicit states but default all optional storage off. Google documentation describes the technical consent types and expressly does not substitute for legal advice: [consent types](https://support.google.com/analytics/answer/12334711?hl=en), [consent management](https://support.google.com/analytics/answer/12329599?hl=en).

## External gates

- **Owner action:** create/select GA4 property and stream, provide measurement ID through secret-safe deployment config, identify Search Console property, grant access, and decide whether production measurement is desired at launch.
- **Legal review:** consent wording/category and regional behavior; analytics data purpose and retention; cookie notice consistency. M12 `WAIT_LEGAL` remains open as applicable.
- **No repository secret:** property IDs are config, not source literals; credentials/API tokens are never committed.

## Environment separation

Recommended: analytics entirely disabled on staging and all non-production. If a later explicit test needs a separate staging stream, use separate config, mark debug traffic, and never send customer data. CI uses a fake sink/assertions only. Verify no production Measurement ID in staging markup/network trace.
