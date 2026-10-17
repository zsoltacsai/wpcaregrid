# M14.5 implementation and validation closure

Validation date: 2026-10-17. Scope: Owner-only provider configuration and explicit, bodyless metadata health probes. This milestone does not enable AI product functionality or authorize customer-data egress.

## Lineage and release evidence

| Item | Evidence |
|---|---|
| Planning PR #101 | Reviewed head `2d2d23ca377590e3bae2ea87776bbc0e1d50a71d`; exact CI [39014883682](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39014883682), 7/7 mandatory jobs passed. Planning merge `b08bd136d5be2c019e8a044b4564c5887df21eef`. |
| Implementation baseline | Planning-merged primary `b08bd136d5be2c019e8a044b4564c5887df21eef`. |
| PR #102 | Credential domain, registry, no-fallback/runtime-only AI fields, status table and credential lifecycle. Candidate `395269e92cdfe478ac72c780d07a343ca533e7db`; exact CI [39017126211](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39017126211), 7/7 passed. Merge `7aa21be2a2f5f84ba5fc6beffdec89b058bfd570`. |
| PR #103 | Owner health action, fixed provider probes, bounded transport, status normalization, generation compare-and-set and adversarial tests. Final candidate `9598d8a2741a6e7078b78eb977e8a0bb9fc283ae`; exact CI [39020595623](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39020595623), 7/7 passed. Merge/final executable SHA `3debba99c677b68465cf439733f440442fbd0f22`. |
| Merged-SHA CI | [39021792985](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39021792985), head `3debba99c677b68465cf439733f440442fbd0f22`, 7/7 passed including Server MySQL and required process/concurrency gate. |
| Staging | Guarded deployment of exact merged SHA `3debba99c677b68465cf439733f440442fbd0f22`; strict pinned SSH host key remained enabled. |
| Connector | Before/after `0.4.7`, API `v1`; no Connector changes or version bump. |
| Migration | `2026_10_17_000005_create_ai_provider_statuses_table`; applied on staging with no initial provider rows, defaults, or feature activation. CI covered empty migration, full rollback and reapply. |

## Implemented boundary

The source-owned provider registry contains exactly `openai`, `anthropic`, and `google_gemini`. Destinations are fixed in code; there is no custom endpoint input. Registry order is not priority and `defaultProvider()` returns `null`. No default, enabled-provider, model, routing, fallback, or feature-authorization state was added.

Credentials reuse `IntegrationCredentials` and Laravel `Crypt`; the AI allowlist entries are runtime-resolved, have no config paths or environment fallback, and are skipped by global config hydration. Configured state comes from the encrypted credential row. Credential writes rotate an opaque UUID generation and invalidate health. Remove deletes WP CareGrid's encrypted copy and does not call a provider; it does not claim vendor-side revocation. APP_KEY loss prevents decryption, fails closed, preserves ciphertext and emits no probe.

The secret field remains blank in the Owner UI. Failed AI credential validation does not flash old input. Save, replace and remove require Owner authorization and current-password confirmation, retain CSRF protection, and use the existing owner-sensitive limiter. Health checks are explicit Owner POSTs with a separate throttle; there is no cron, boot-time probe, save-time probe, or retry.

Health result writes are compared against the credential generation captured before network I/O. Provider I/O occurs outside a DB transaction; stale responses after replace/remove are discarded. Stored health is normalized, generation-bound, and informational for seven days. It has no consumer that can enable an AI feature.

## Provider protocols rechecked for implementation

| Provider | Request | Interpretation |
|---|---|---|
| OpenAI | `GET https://api.openai.com/v1/models`, bearer authentication, no body | Metadata/authentication probe only; success does not prove model access, inference permission or billing readiness. [Official API reference](https://developers.openai.com/api/reference/resources/models/methods/list). |
| Anthropic | `GET https://api.anthropic.com/v1/models?limit=1`, `x-api-key`, `anthropic-version: 2023-06-01`, no body | Supports workspace-scoped credentials only and omits `anthropic-workspace-id`. Multi-workspace/unscoped identity-linked credentials needing that selector are unsupported; the UI does not collect workspace data. [List Models](https://platform.claude.com/docs/en/api/models/list), [authentication/workspace guidance](https://platform.claude.com/docs/en/manage-claude/authentication). |
| Google Gemini | `GET https://generativelanguage.googleapis.com/v1beta/models?pageSize=1`, `x-goog-api-key`, no body | Metadata/authentication probe only; no generation endpoint. No key-prefix assumptions. [Models API](https://ai.google.dev/api/models), [API key guidance](https://ai.google.dev/gemini-api/docs/api-key). |

The shared transport uses verified TLS, redirects disabled, no retries, empty proxy configuration, 3-second connect and 8-second overall timeouts. It caps response bytes at 64 KiB during streaming, rejects oversized declared Content-Length before body read, and parses only the minimal expected collection envelope. Model/account data and raw provider errors are discarded. Status is normalized; ambiguous 400/403 is not mislabeled as invalid authentication.

## Security and staging evidence

Focused provider/credential/authentication tests passed: 37 tests / 948 assertions across the combined local focused run. Provider-health and credential suites rerun after stream-bound implementation passed 21 tests / 280 assertions. The full local SQLite PHPUnit suite passed 1,398 tests / 20,409 assertions with 25 expected skips. PR #103 exact-head mandatory CI passed all seven jobs; Server MySQL includes the required deterministic process/concurrency gate. Hosted per-engine PHPUnit totals were not recovered from the GitHub log export, so no per-engine totals are claimed here.

Local quality checks passed: Pint across 999 PHP files; PHP syntax; Composer validation and audit (zero advisories); frontend build; npm audit (zero advisories); full-history Gitleaks; and `git diff --check`. The initial PR #103 CI run rejected a DB-specific JSON-key-order assertion; the assertion was corrected to check the relevant value, after which all seven exact-head jobs passed. No safety condition was weakened.

On staging, the migration is applied and deployed HEAD matched the candidate SHA. A disposable PHP harness injected an in-memory fake `ProviderHealthTransport` inside one DB transaction and rolled it back. It validated three encrypted synthetic credentials, three successful provider-envelope checks, normalized auth/rate-limit/unavailable/timeout outcomes, cross-provider credential isolation, generation-CAS rejection after replacement, and removal with a simulated fallback config value. Transport invocations were fake-only; no real provider request or credential was used. Customer sentinels were absent from transport inputs; the production checker accepts no payload parameter. The temporary harness was removed; rollback discarded synthetic credential/status rows.

The deployed guest Owner Integrations route redirected to authentication. Authenticated Owner behavior, Customer/Operator denial, CSRF, throttling, write-only rendering, old-input protection and audit redaction are covered by feature tests; no staging Owner credential was created.

Staging `system:check` returned OK for application, database, queue, scheduler, Connector API, Stripe, Cloudflare DNS, backup storage, mail, safety and privacy checks. The scanner warning “No completed security scan yet” remains expected and is carried to M14.7. The scheduled `backup:retention` command reported shadow candidates/gaps and explicitly said no backups were deleted.

All six M12 switches were read before and after staging validation and remain false:

| Gate | Value |
|---|---|
| `M12_DESTRUCTIVE_RETENTION_ENABLED` | `false` |
| profile anonymization | `false` |
| customer backup deletion | `false` |
| provider deletion | `false` |
| bulk destructive execution | `false` |
| normal-staging synthetic execution | `false` |

## Final state and handoff

- Exactly three provider IDs; custom endpoint unsupported; default provider none.
- AI product features enabled: no. Authorized AI features: zero.
- Customer/site/security/backup data authorized for AI egress: no. Real customer-data egress: zero. Real provider calls: zero. Real provider credentials: zero.
- Secret sentinel leakage in tested response/session/audit/log/status channels: zero. Cross-provider secret isolation passed.
- Legacy scheduled `backup:retention` remains report-only; customer backup and provider deletion remain disabled.
- No Connector change, SDK or new runtime dependency was introduced.
- No production deployment or destructive functionality was enabled.
- M14.6: review workflow permissions, action pinning, dependencies and provenance; no new dependency was added.
- M14.7: carry AI credential leakage, Owner RBAC, CSRF/rate abuse, endpoint rejection/SSRF, redirects, response bounds, error/log injection and generation races into the integrated attack campaign; AS-16 scanner work remains there.
- M14.8: verify configuration-only status, empty default/feature authorization, zero customer-data egress authorization and Owner-only credential scope.

Closure documentation PR and exact-head CI are recorded after this report is opened. This report does not claim a completed M14 audit or production readiness.

Cleanup readback on exact staging SHA `3debba99c677b68465cf439733f440442fbd0f22` returned zero AI credential rows for all three fixed IDs and zero AI provider status rows after transaction rollback. The temporary validation script was absent. Post-validation M12 readback remained six of six `false`; `backup:retention` again reported no deletion; post-validation `system:check` remained green except the expected M14.7 scanner warning.
