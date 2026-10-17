# M14.5 implementation test plan

## Unit / domain

- Registry contains exactly `openai`, `anthropic`, `google_gemini`; IDs, auth, hosts and paths are fixed. Arbitrary ID/class/URL is rejected. Registry order creates no default.
- State truth table proves configured does not imply healthy/enabled/default/authorized; health does not imply inference availability. Unknown provider is rejected.
- Secret input bound, control-character rejection, whitespace behavior, and no fragile provider-key-prefix regex.
- Health normalization for 2xx envelope, auth rejection, ambiguous 403, 429, 5xx, timeout, TLS/network, invalid JSON, HTML, empty, oversized, redirect.

## Feature / database

- Store encrypted `Crypt` ciphertext: DB value differs from plaintext and only intended credential abstraction resolves it. Normal model/JSON/API serialization exposes no secret.
- Save/replace/remove are transactional; replacement rotates generation and resets health; remove clears configured and health, with no environment fallback; invalid requests do not store.
- Migration empty/reapply/rollback: zero provider rows, no configured/default/enabled feature, no external calls. Existing integrations/fallback behavior remain unchanged.
- APP_KEY decrypt failure returns safe unavailable state, keeps ciphertext and makes zero provider requests.
- Controller/Blade tests on valid save, invalid secret validation, refresh/back: no secret in HTML, old input, errors, URL, session, response, audit, logs or exception output.
- Owner can view/mutate after required recent current-password confirmation; wrong/missing/reused/stale auth rejected. Customer/Operator/guest/wrong ability denied; missing CSRF denied; every denied action causes zero HTTP egress and no secret mutation.
- Existing M14.1 route authorization tests remain green.

## HTTP fakes and privacy

- Provider-specific fake success/auth/rate-limit/server-error/timeout/malformed/redirect/oversize/connection fixtures. Assert destination, method, required auth, minimal headers, no body, no follow redirects, timeout and response cap. Never hit real vendor endpoints in CI or staging.
- Sentinel customer email/domain/customer ID/site UUID/security finding/backup ID/inventory/log/content absent in URL, query, non-auth headers, body, audit/log/session/view/exceptions for all providers.
- Synthetic secret sentinel appears only in the fake authentication header and encrypted test source; absent from every output/log/audit/error/metadata channel.
- Saving or rendering config performs zero provider requests. No scheduled task/queue or global-health check probes providers.

## Concurrency / process

Use deterministic barriers and MySQL process tests where transaction semantics need proof: probe A vs replace B; probe vs remove; replace vs replace; remove vs replace; late success/failure after generation change. Current generation wins; stale result is discarded, never resurrects credential. Concurrent health actions respect shared rate limit. No arbitrary sleeps.

## Full CI / staging

Run mandatory 7 jobs: Quality, Server SQLite, Server MySQL, Server MariaDB, Connector WordPress SQLite/MySQL/MariaDB. Include MySQL process/concurrency suite where marked. Staging uses exact merged SHA, strict pinned SSH, synthetic Owner, fake provider transport injected through test seam, test transaction and verified cleanup. Prove save/mask, encrypted DB, replace, fake healthy/auth failure, remove, invalidation, unauthorized denials, audit redaction and zero-data sentinel. No real key, provider call, customer/site data or production deployment.
