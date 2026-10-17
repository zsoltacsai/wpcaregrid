# M14.5 eventual closure criteria

M14.5 implementation may close only when exact executable evidence proves:

- Exactly three fixed source-owned providers exist (`openai`, `anthropic`, `google_gemini`); no custom endpoint, URL, proxy, arbitrary class, default ordering or route-driven destination exists.
- Encrypted credential storage reuses the existing Laravel `Crypt` primitive; plaintext is write-only, never returned, flashed, logged, audited, serialized or hydrated into global config. AI has no environment fallback and removal does not reactivate a fallback.
- Save/replace/remove are Owner-only, CSRF-protected, rate-limited, recent-authenticated and transactionally atomic; customer/operator/guest/unauthorized Owner calls cause no mutation and no network call.
- Health is explicit, non-generative, fixed-destination, no-body, bounded by timeout/response limits, TLS verified, redirect-disabled and no-retry. No real provider key is required for CI/staging.
- Health output is normalized and tied to credential generation; replacement/removal invalidates it and all concurrent stale responses are discarded.
- Tests cover validation old-input, HTML, session, audit/log/exception redaction, encryption, APP_KEY decryption failure, provider status categories, large/malformed/redirect/timeouts, generation races, and customer/site/security/backup sentinel absence.
- `CONFIGURED`, `ENABLED`, `HEALTHY`, `DEFAULT`, and `AUTHORIZED_FOR_FEATURE` remain independent. No AI feature/client, customer-data path, default, enablement, authorization, autonomous action or “AI-powered” claim exists. Configuration failure/unconfigured state has no core-health impact.
- Privacy/data transfer remains `NOT AUTHORIZED / FUTURE REVIEW REQUIRED`; M14.7 and M14.8 handoffs are recorded.
- Exact-head required 7-job CI passes; fake-provider staging validation runs on exact merged SHA via strict pinned SSH, with synthetic Owner and verified cleanup; no real key, customer/site payload, production deployment or provider call is used.
- No M14.5 retention, privacy, backup, billing, Connector or M12 safety gate changes occur. All six M12 gates remain false; customer backup and provider deletion remain disabled.

Current planning documents do not mark any runtime criterion PASS. CI must include Quality, Server SQLite/MySQL/MariaDB and Connector WordPress SQLite/MySQL/MariaDB. Connector test matrix remains regression-only.
