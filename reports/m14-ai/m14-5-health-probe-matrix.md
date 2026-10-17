# M14.5 explicit provider health-probe matrix

Endpoints/auth details were verified against official docs on 2026-10-17: [OpenAI](https://developers.openai.com/api/reference/resources/models/methods/list), [Anthropic](https://platform.claude.com/docs/en/api/models/list), [Gemini](https://ai.google.dev/api/models) and [Gemini key types](https://ai.google.dev/gemini-api/docs/api-key). Re-verify at implementation because APIs change.

| Provider | Fixed request | Request data | Normal success check | Additional identity/header |
|---|---|---|---|---|
| OpenAI | `GET https://api.openai.com/v1/models` | no body; Bearer key only | 2xx, bounded valid list envelope | omit optional org/project headers |
| Anthropic | `GET https://api.anthropic.com/v1/models?limit=1` if current API confirms; otherwise fixed GET with no pagination | no body; `x-api-key`, documented API version | 2xx, bounded valid list envelope | workspace ID is optional for multi-workspace credentials; omit, do not collect it |
| Google Gemini | `GET https://generativelanguage.googleapis.com/v1beta/models?pageSize=1` | no body; `x-goog-api-key` | 2xx, bounded valid models envelope | no project/account identifier is required by documented request |

These prove metadata endpoint authentication only; none generates model content. Official docs do not establish guaranteed zero-cost/billing, no provider-side request logging, or plan eligibility from a successful list. Do not claim those properties. Avoid exposing or persisting returned model data; parser checks only status/envelope then discards. Avoid follow-on pagination.

## Failure matrix (all three adapters)

| Fixture | Result | Credential remains configured? | Required assertions |
|---|---|---|---|
| Valid synthetic 2xx response | `HEALTHY` | yes | generation matches; response body discarded |
| Documented invalid auth response | `AUTH_FAILED` | yes | no raw response displayed/logged; key not cleared |
| Ambiguous 403/permission response | `MISCONFIGURED` or `ERROR` | yes | do not call it invalid key absent provider-specific evidence |
| 429 | `RATE_LIMITED` | yes | no retry; respect only safe bounded retry-after metadata if ever shown as wait hint |
| 5xx/provider outage | `UNAVAILABLE` | yes | not labeled auth failure |
| connect/read timeout | `TIMEOUT` | yes | total request within hard bound; no retry |
| TLS/DNS/refused | `UNAVAILABLE` or `ERROR` | yes | TLS verification on; no raw host/network detail to user |
| malformed JSON / empty / HTML | `ERROR` | yes | no dump; bounded parser |
| oversized response | `ERROR` | yes | read cap enforced before full buffering; transport aborts/does not retain body |
| 3xx | `MISCONFIGURED`/`ERROR` | yes | redirects disabled; auth not forwarded |
| response after replace/remove | discarded | current state unchanged | compare-and-set generation rejects stale result |
| provider model list lacks inference access | still metadata `HEALTHY` only | yes | UI explains credential probe does not prove feature/model access |

Probe is explicit Owner action only. Proposed transport ceiling: 3 s connect, 8 s total, 64 KiB response, zero automatic retries. No real account/key needed for CI or staging; fake HTTP transport must emulate statuses, malformed and slow/oversized responses, redirect and concurrency races.

## Implementation evidence addendum — 2026-10-17

M14.5 executable work is merged and deployed to staging at `3debba99c677b68465cf439733f440442fbd0f22`. PR #102 exact CI 39017126211 and PR #103 exact CI 39020595623 each passed all 7 required jobs; merged-SHA run 39021792985 also passed all 7, including MySQL process/concurrency. The staging fake-provider transaction passed and was rolled back. There were no real provider credentials, real vendor calls, or customer-data egress. No AI feature/default/feature authorization exists. See [closure report](m14-5-closure-report.md) for full bounded evidence and limitations.
