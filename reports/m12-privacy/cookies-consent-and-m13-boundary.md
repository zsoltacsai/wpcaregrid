# Cookies, consent and M13 analytics boundary

## Technical inventory found

- Laravel web session cookie (configured through `config/session.php`; HTTPS-dependent Secure default, HttpOnly default, SameSite=Lax default; session driver/lifetime are environment-configurable).
- CSRF/session-related browser state follows Laravel middleware defaults; confirm exact `Set-Cookie` headers from staging in M12 implementation verification rather than infer every cookie from source.
- `caregrid_locale` remembers a supported display-language choice for 365 days (`config/localization.php`, `docs/LOCALIZATION.md`); cookie is encrypted/signed by Laravel cookie middleware and validates locale. Signed-in customer locale also persists in `users.locale`.
- No analytics/marketing-cookie implementation, consent management platform, or consent ledger was found in the audited app source. This does not prove that infrastructure-level logs/tags outside the repository do not exist.

## Classification candidates only

Session/auth and CSRF cookies: strictly necessary candidate. Locale cookie: preference/strictly necessary candidate to assess. Analytics identifiers, pixels, embedded media, advertising and marketing storage: none found in source; if introduced in M13, treat as consent-gated until legal review confirms the applicable rule. Cookie legal classification, consent validity and exemptions are **LEGAL REVIEW REQUIRED**.

## M12 interface / M13 requirements

M12 must define a consent state source of truth (version, purposes, choices, timestamp, locale, source, withdrawal), notice versions, preference center, evidence minimization, expiry and rights. Do not put consent choices in the locale cookie. Consent withdrawal must be as accessible as granting it and stop relevant future tags; deletion/account closure must not erase legally needed consent proof without policy review.

M13 may implement analytics/SEO tools only after approved purpose/vendor inventory, consent category and default behavior, Consent Mode (if chosen), tag-manager governance, region/transfer review, retention, opt-out and no pre-consent requests where required. Test browser network requests for denied/default/accepted/withdrawn states and new sessions. No GA4/GSC work is part of M12 planning.
