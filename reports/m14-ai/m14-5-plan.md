# M14.5 — AI provider configuration groundwork

**Status: planning/security/domain audit only.** Baseline: authoritative primary `claude/ecstatic-wozniak-oemyuf` at `0ed9025dca2ee7bfa23e9a6869b159f06c700659` (M14.4 closure PR #100, merge SHA verified); latest executable staging evidence remains `29bdd9489eafd6581d9d5b9bc5d7a12fe26b4209`. Connector 0.4.7 / API v1; no Connector dependency is proposed. This document proposes implementation; it does not claim runtime controls are implemented or validated.

## Decision and boundary

OQ-05 approves Owner-only configuration groundwork for exactly OpenAI, Anthropic and Google Gemini. M14.5 can store provider credentials and support a deliberately invoked, bounded credential/availability probe. It does not select a default, enable an AI feature, authorize feature use, or authorize any customer/site/business/security/backup data transfer. Configuring a key means only that an encrypted credential exists.

The five independent states are `CONFIGURED`, `ENABLED`, `HEALTHY`, `DEFAULT`, and `AUTHORIZED_FOR_FEATURE`. In M14.5 only `CONFIGURED` may become true; `HEALTHY` is a generation-bound observation; `ENABLED=false`, `DEFAULT=none`, `AUTHORIZED_FOR_FEATURE=none`. No boot, health, dashboard, security, backup, monitoring, billing or Connector path may consume an AI key or invoke inference.

## Verified source baseline

- `app/Owner/IntegrationCredentials.php` is the current fixed-ID credential store. It uses Laravel `Crypt::encryptString` / `decryptString` and an `integration_credentials(id,value,timestamps)` table. `resolve()` prefers stored ciphertext then checks each configured path. `apply()` decrypts stored records into their configured Laravel config paths except entries marked `runtime_resolved`.
- `app/Http/Controllers/Owner/IntegrationCredentialController.php`, `routes/owner.php`, `OwnerAbility::IntegrationsManage`, and `resources/views/owner/integrations.blade.php` provide the existing global Owner Integrations surface. Store/clear have `owner.integrations.manage` and `throttle:owner-sensitive`; view gets only configured/fallback booleans; the secret form is blank. The current UI distinguishes Owner-managed from environment-managed values. It does not implement recent password confirmation for integration credential mutation.
- The controller validates `id` and `value` manually and returns `back()->withErrors($validator)` without `withInput`; the Blade value is explicitly empty. This is good current evidence, but AI-specific tests must prove the secret is absent from session old input, HTML, errors, and redirects on every failure path.
- Existing `IntegrationCredentials::apply()` is called at application boot for current services. Adding AI entries with ordinary config paths would widen plaintext exposure into global runtime configuration. AI entries must be runtime-resolved only, with no config paths and no environment fallback.
- `AdminAudit` is used by the existing controller for credential lifecycle events, but generic credential changes currently carry minimal metadata. New AI events should use a dedicated allowlisted metadata schema.
- Current `OwnerAbility` already includes `IntegrationsManage`; routes use Owner auth/ability checks. Existing `owner-sensitive` limiter is 20/minute and 200/hour per Owner. Re-auth must reuse the repository's current-password confirmation pattern (e.g. backup-policy confirmation), not invent a parallel auth mechanism. Health testing needs a stricter external-call throttle.
- Current AI planning artifacts (`provider-configuration-plan.md`, `provider-security-boundary.md`) are broad concept notes. This plan replaces their unqualified “safe probe input” language with provider-specific metadata-only requests, fixed destinations, no caller payload, and explicit cost uncertainty.
- APP_KEY is the decryption root. Existing M9 documentation requires preserving APP_KEY with database recovery material; encrypted credentials cannot be recovered from a DB backup without the matching APP_KEY. No key escrow/re-encryption mechanism is created in M14.5.

### Existing credential lifecycle evidence (source audit)

| Stage / question | Current source evidence | Planning consequence |
|---|---|---|
| Create/store; plaintext at rest? | Controller validates request `value`; `IntegrationCredentials::store()` encrypts via `Crypt::encryptString` before `updateOrInsert` into `integration_credentials.value` (`longText`). | Plaintext exists transiently in request/validated memory; persisted value is ciphertext. Extend the same primitive, never a new vault. |
| Return/render after save? | Controller redirects with a localized status; index passes only configured booleans and APP_KEY-present boolean. Blade password input has `value=""`; no decrypt value is passed to the view. | Preserve write-only UX, blank on render and after redirect. |
| Validation/session echo? | Current controller uses manual `Validator` and `back()->withErrors($validator)` without `withInput`; Blade never reads `old('value')`. | Current path gives no source evidence of flashing the secret; tests must assert `_old_input.value` absent across validation failure and browser refresh/back. |
| Audit? | Existing generic credential update/clear audit records object/id; metadata is empty for most IDs (Számlázz includes provider/action only). No secret is intentionally passed to audit. | AI audit uses explicit allowlisted provider/action/status/generation metadata; assert synthetic secret absent. |
| Resolve/apply/config cache? | `resolve()` decrypts encrypted Owner value first, then checks fixed `config` paths. `apply()` runs during normal AppServiceProvider boot, skips config-cache construction, and writes decrypted values to config except `runtime_resolved` entries. | AI fields must have no config path and be marked runtime-resolved-only; no env fallback and no boot/config-cache resolution. |
| Replace/clear? | `store()` upserts encrypted value; controller transaction wraps write + audit. `clear()` deletes the credential row; generic `resolve()` may then use config fallback. | AI replace/remove rotate generation + invalidate health atomically; AI has no fallback so remove means not configured. |
| APP_KEY failure? | `resolve()`/`apply()` throw generic `RuntimeException` on decrypt failure with crypto exception chained as `previous`; no auto-clear. | For AI, catch and normalize before probe/output; preserve ciphertext; do not expose chained exception to Owner or logs. |
| Config cache / queued serialization? | Source avoids DB credential access while building config cache; no AI jobs/client exist. Existing integration consumers may call `apply()` at app boot. | Keep AI credential out of global config and queue payloads. No AI async job is planned. Add tests that secret never appears in cache/job serialization if any future path is proposed. |
| Logs / debug context? | No explicit logging of the credential value was found in the inspected controller/store; global exception/log middleware and deployed debug settings are outside source proof. | Future probe must normalize errors and never log request/response/HTTP exception context. Test representative failures against captured logs. |

This source audit establishes design facts only. It does not prove deployed Owner secrets, APP_KEY recovery state, environment values, log configuration, or vendor-side behavior.

## Provider and probe conclusion

Use exactly three fixed adapters and official provider hosts. Current official references document non-generative model metadata list endpoints: OpenAI `GET https://api.openai.com/v1/models` with Bearer auth; Anthropic `GET https://api.anthropic.com/v1/models` with `x-api-key` and `anthropic-version`; Gemini `GET https://generativelanguage.googleapis.com/v1beta/models` with `x-goog-api-key`. These carry no request body or customer-derived data. They list model metadata, which the checker must bound, minimally parse, and discard. The public references establish endpoint/auth shape, not a zero monetary cost guarantee; account billing, quotas, and provider-side logging behavior must not be represented as free absent authoritative terms. No real provider credential is needed for M14.5 implementation or CI.

Sources: [OpenAI List models](https://developers.openai.com/api/reference/resources/models/methods/list), [Anthropic List Models](https://platform.claude.com/docs/en/api/models/list), [Gemini models.list](https://ai.google.dev/api/models), [Gemini API key guidance](https://ai.google.dev/gemini-api/docs/api-key).

## Recommended architecture

1. Extend the existing credential abstraction with three fixed IDs and a runtime-resolved-only/no-environment-fallback policy. Do not add a second vault or provider-defined database registry. Store credentials only encrypted in the existing table; restrict the resolved plaintext lifetime to the explicit health-probe service.
2. Add a small provider-health metadata record/table keyed by fixed provider ID, with opaque credential generation UUID, health status, `checked_at`, normalized error code, and bounded latency bucket. It never stores account/model catalogues, response bodies, URLs, headers, or key-derived fingerprints. Determine at implementation whether the repository has an equally suitable existing status store; current inspection found none. One narrow migration is anticipated; defaults are no configured providers and unknown health.
3. Provider registry is source-owned and immutable at runtime: `openai`, `anthropic`, `google_gemini`; fixed display labels, fixed HTTPS host/path, authentication style, response parser and status normalizer. Request input can select only one of these IDs. No URL, host, scheme, port, proxy, class, model, prompt, or arbitrary payload is accepted from a request or DB row.
4. Owner Integrations is the UI home. Show provider label, configured/not configured, health/last checked and blank password input; save/replace, remove-from-WP-CareGrid, and explicit “Test connection”. No last-four/prefix/suffix/length/fingerprint. Test is separate from save. Warn that configuration does not enable AI and that no customer data is sent. Removing local storage does not revoke the key with the vendor.
5. Add Owner current-password confirmation for create/replace/remove, CSRF, existing sensitive throttle, and separate low-rate health-probe throttle. Keep the existing Owner ability initially; a separate ability is unnecessary for a global configuration page already restricted to Owner. Customer, Operator, Connector, and customer API have no route or read access.
6. A narrow `ProviderHealthChecker::check(providerId)` accepts no arbitrary payload. Each adapter performs one fixed HTTPS GET with certificate verification, redirects disabled, connect timeout 3 s / total timeout 8 s (implementation values to be tested/tuned), response cap 64 KiB (Gemini request asks for a small page; discard all body after minimal shape check), no retries. No cron. Any failure is normalized before audit/presentation.

## Data and availability semantics

`CONFIGURED` derives only from presence of the Owner-managed encrypted credential. `HEALTHY` means the latest explicit probe for the current generation received a bounded expected metadata response; it is not a promise of inference/model entitlement. Proposed states: `NOT_CONFIGURED`, `UNKNOWN`, `HEALTHY`, `AUTH_FAILED`, `RATE_LIMITED`, `UNAVAILABLE`, `TIMEOUT`, `MISCONFIGURED`, `ERROR`. Provider HTTP 401/403 semantics vary; classify only provider-documented auth rejections as `AUTH_FAILED`, otherwise a generic safe error. 429 is rate limited; 5xx/network/TLS/timeout do not mean invalid key. A health result older than seven days is presented as stale/unknown for UX only; nothing consumes it for feature authorization.

On save, atomically persist ciphertext and a new generation UUID; reset health to `UNKNOWN`. On remove, atomically clear ciphertext, rotate/invalidate generation and set `NOT_CONFIGURED`. Health captures generation before egress and compare-and-sets status only if it still matches afterwards. Replace/remove while a probe is in flight wins; old responses cannot revive configuration or mark the new key healthy. Decryption failure under changed/missing APP_KEY fails closed, does not make an outbound call, does not clear ciphertext, and displays a generic recovery warning.

## Privacy, availability, audit

Only the provider credential goes to its provider authentication mechanism. The health probe has no body and no customer/site metadata. No website/customer IDs, UUIDs, domains, emails, subscriptions, backup/security IDs, inventories, logs, code, content, PII, or other credentials leave WP CareGrid. No static marker is needed for GET metadata endpoints. Do not put key values in old input, session, HTML, redirect, audit, logs, exception context, failed jobs, metrics, or serialized jobs. Audit only provider ID, action, normalized status, opaque generation and time. AI absence/failure is an optional integration state and must not affect core health or boot.

Provider metadata-list health checks can reveal only status to the Owner; discard model/account details. No claim is made about providers' internal logging, billing, retention or training terms. M14.5 does not grant privacy/legal approval for future customer-data processing (`NOT AUTHORIZED / FUTURE REVIEW REQUIRED`).

## Findings and residuals

The project already has encrypted credential infrastructure; no current AI data egress or AI client was found in the audited source. This is not proof of external environment state. A source-level hazard for future implementation is that ordinary IntegrationCredentials fields participate in both global `apply()` and config-value fallback; AI IDs must opt out of both. Existing integration mutation has no recent re-auth; M14.5 must add the existing current-password-confirmation pattern for these sensitive actions. Official docs do not prove request cost is zero; probe remains explicit, manually initiated, bounded and no-retry. No real-provider accounts/keys are an external action, not a software blocker.

## Proposed implementation sequence

| Batch | Scope | Main acceptance |
|---|---|---|
| 14.5-A | Fixed registry; owner-managed-only IDs; generation/status persistence; encrypted write-only lifecycle; migration | exactly three IDs; no config hydration/fallback; APP_KEY failure safe; atomic replace/remove and generation invalidation |
| 14.5-B | Owner UI/access/re-auth/audit and explicit health probes | masked state; no secret old-input or output; fixed-host bounded no-body probes; normalized status; owner-only and throttled |
| 14.5-C | Adversarial, concurrency, egress and staging fake-transport proof | zero customer-data and secret sentinel leaks; stale generation rejected; fake-only staging; zero AI feature/default/authorization |
| Closure | Report-only evidence and M14 backlog update | exact-head 7-job CI and reviewed planning/closure evidence; no runtime change in planning PR |

Prefer 2 executable PRs (credential domain/metadata, then UI+probes) followed by a report-only closure PR, unless source dependencies make 3 executable PRs safer. Each executable PR needs the mandatory 7-job exact-head CI, including Server SQLite/MySQL/MariaDB and Connector WordPress SQLite/MySQL/MariaDB; Connector suites are regression-only and no Connector change is expected. No vendor SDK is justified for three bounded GETs; use Laravel HTTP client and dependency injection/fakes, not a production base-URL override.

## Planning validation and handoff

Planning branch is to be based on `0ed9025dca2ee7bfa23e9a6869b159f06c700659`, with docs-only changes and exact-head CI. Staging for implementation uses exact merged SHA, strict pinned SSH, synthetic Owner and fake HTTP transport; no real key or customer data. M14.6 reviews any dependency/workflow supply-chain impact; M14.7 includes AI secret/RBAC/CSRF/SSRF/redirect/response/rate abuse; M14.8 confirms groundwork-only/no egress/no default/no autonomous actions. M19/M20/M25/M26/M27 each require independent purpose, data-class, privacy/legal/vendor, security, tenant authorization and approval review. **No new Owner decision is required.**

M14.5 is implementation-ready after planning PR review. Estimated M14.5 readiness: 100% for planning; runtime implementation remains 0%. Overall M14 milestone completion estimate after M14.4: 4/8 major milestones, 50%; this is a planning estimate, not release status.
