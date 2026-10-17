# M14.5 provider registry and probe contract

Registry is fixed server code, never Owner/database supplied. Exactly three IDs are accepted: `openai`, `anthropic`, `google_gemini`. No custom provider, Azure OpenAI, Bedrock, Vertex AI, OpenRouter, OpenAI-compatible endpoint, local Ollama, proxy, self-hosted inference, or user-supplied URL.

| ID | Credential ID (proposed) | Fixed official endpoint | Auth / required headers | Probe contract | Cost / response note |
|---|---|---|---|---|---|
| `openai` | `ai_openai_api_key` | `https://api.openai.com/v1/models` | `Authorization: Bearer …` | one GET; no body; discard model metadata | metadata only, no inference; public endpoint docs do not guarantee zero account cost; cap response at 64 KiB and parse only expected list envelope |
| `anthropic` | `ai_anthropic_api_key` | `https://api.anthropic.com/v1/models` | `x-api-key: …`; pinned documented `anthropic-version` header | one GET; no body; no workspace selector; discard model metadata | metadata only, no message generation; no zero-cost claim; cap response; consider `limit=1` if documented and supported, then stop pagination |
| `google_gemini` | `ai_google_gemini_api_key` | `https://generativelanguage.googleapis.com/v1beta/models?pageSize=1` | `x-goog-api-key: …` | one GET; empty body; no page-token follow-up; discard model metadata | metadata only, no generation; API-key guidance describes key/project association but not zero-cost probe; page size bounds work |

Provider references checked 2026-10-17: [OpenAI models list](https://developers.openai.com/api/reference/resources/models/methods/list) documents GET `/models` and Bearer auth; [Anthropic models list](https://platform.claude.com/docs/en/api/models/list) documents GET `/v1/models`, `x-api-key`, API version and optional workspace header; [Gemini models list](https://ai.google.dev/api/models) documents GET `/v1beta/models`, empty request body, pagination; [Gemini API key guidance](https://ai.google.dev/gemini-api/docs/api-key) describes API key types/project association. Implementer must re-check docs at implementation; provider API churn remains isolated per adapter.

## Shared transport rules

- HTTPS and normal certificate/hostname verification are mandatory. Fixed origin and path are source-owned. Reject any request ID outside the exact allowlist; do not resolve adapter classes or URLs from caller strings.
- Redirects are disabled. If an official endpoint begins requiring redirects, classify `MISCONFIGURED`/`UNAVAILABLE` and review the change; never forward auth to another host.
- Proposed connect timeout 3 seconds, total timeout 8 seconds, response maximum 64 KiB, no automatic retry. Tests assert timeout, response cap, no redirect and no auth forwarding. Keep values centralized and evidence-tested.
- Header set is minimal. Do not add customer/site headers, account/project IDs, correlation IDs that identify customers, cookies, or product telemetry. Do not persist returned model IDs/catalogues, organization/workspace IDs or account details.
- A 2xx plus a bounded expected metadata envelope means `HEALTHY` for “credential accepted by this metadata endpoint at check time”; it does not prove inference entitlement, billing, availability of a model, or future feature authorization.
- No prompt/completion, `generateContent`, `Messages`, embedding, moderation, upload, streaming, tools, or marker request is used.

Owner action is explicit and rate-limited; there is no scheduled check. A provider may impose quota or billing outside the request's inference usage; M14.5 does not claim the operation is zero-cost. Provider terms/account status remain external facts; no production key is needed for tests.
