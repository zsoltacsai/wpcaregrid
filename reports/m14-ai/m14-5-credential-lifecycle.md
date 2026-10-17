# M14.5 credential lifecycle

## Storage and resolution

Extend `App\Owner\IntegrationCredentials` and its fixed ID allowlist. Reuse `Crypt::encryptString` and the existing `integration_credentials` table; no custom cryptography, hash-only storage, SDK vault, or second encrypted secret store. Add AI IDs with no Laravel config destinations and runtime-resolved-only behavior. This prevents `apply()` boot-time config hydration. Resolver must return only Owner-managed AI ciphertext plaintext at explicit probe time and must have no environment fallback. Clearing an AI row therefore yields not configured; it cannot reveal a latent `.env` key.

Plaintext exists transiently in the HTTPS form request and the probe process only. Never persist/serialize it outside the encrypted `value`, return it, flash it, render it, put it in a session, log context, audit, metric, failed-job payload, URL, query, generic config, config cache, or provider response display. Avoid exception chaining into provider or HTTP-client exceptions that may contain headers.

## State metadata and generation

Anticipated one small `ai_provider_status` migration/table keyed by provider ID, storing current opaque generation UUID, latest health state, health generation UUID, check timestamp, normalized error category and bounded latency bucket. Configured remains derived from the encrypted credential row, not duplicated in this table. No secret fingerprint, raw provider response, account metadata or endpoint value. Migration creates no credentials, probes, provider defaults, feature grants, or network calls. If implementation finds an existing suitable status record, reuse it instead of adding a table.

Create/replace in one DB transaction: validate bounds/control chars without provider-prefix regex; authorize Owner and verify recent current password; encrypt and write credential; rotate generation; invalidate health to `UNKNOWN`; append allowlisted audit event. Remove similarly clears only WP CareGrid's local ciphertext, rotates generation and sets `NOT_CONFIGURED`; this is not remote provider-side revoke. Do not contact vendor automatically on save.

## Health result race

At probe start read the encrypted credential and generation; decrypt only inside the operation. The checker returns a normalized enum, not raw transport response. Persist by compare-and-set (`provider_id` + captured generation still current). If replaced/removed while the request is in flight, discard the result. No code path lets a stale successful response recreate a credential or update the new generation's health. Use DB transaction/row lock where needed; network I/O remains outside the transaction.

## APP_KEY, fallback, and rotation

Laravel APP_KEY is the encryption root. Matching APP_KEY recovery is required with platform DB backup recovery. If decryption fails after APP_KEY loss/change, return a generic unavailable/configuration-recovery state, do not send ciphertext, do not expose it, and do not silently erase it. Recovery/rotation is out-of-band platform DR work; M14.5 does not introduce key versioning or plaintext export.

Environment fallback is expressly disabled for all AI IDs, regardless of `owner_integration_credentials.environment_fallbacks`. Do not allow “remove from WP CareGrid” to reactivate a configured key. Existing integrations retain their existing fallback behavior; AI policy must not alter them.

## UI, audit, auth

Reuse Owner Integrations. GET exposes boolean configured state and normalized health metadata only. Input always blank, including refresh; no last-four/prefix/suffix/length. Mutations are Owner-only with CSRF, current-password recent confirmation using existing pattern and `owner-sensitive`; probe uses Owner ability plus a dedicated low-frequency per-Owner throttle. Save, replace, remove, probe are distinct audited actions. Audit metadata allowlist: provider ID, action/result enum, opaque generation and timestamp; no body/headers/raw message.

On removal say “Remove from WP CareGrid”; separately advise the Owner to revoke/delete the provider key in that provider's account if desired. Health failure does not erase the key. `HEALTHY` status is tied to the current generation and ages to stale/unknown for display after seven days; no cron refresh.

## Implementation evidence addendum — 2026-10-17

M14.5 executable work is merged and deployed to staging at `3debba99c677b68465cf439733f440442fbd0f22`. PR #102 exact CI 39017126211 and PR #103 exact CI 39020595623 each passed all 7 required jobs; merged-SHA run 39021792985 also passed all 7, including MySQL process/concurrency. The staging fake-provider transaction passed and was rolled back. There were no real provider credentials, real vendor calls, or customer-data egress. No AI feature/default/feature authorization exists. See [closure report](m14-5-closure-report.md) for full bounded evidence and limitations.
