# M14.2 Adversarial Test Plan

| Batch | Tests and layer | Pass condition |
|---|---|---|
| Credentials | Pair/re-pair, used/expired code, wrong host, concurrent pair, response loss, salt change, ciphertext tamper, redaction; Server + WordPress integration, MySQL race | one intended active credential; stale token denied; no secret disclosure; recoverable re-pair |
| Scope/auth | Revoked token on 13 endpoints; valid foreign website/job/backup/admin UUID; status/capability changes; Server MySQL | deny before DB/queue/mail/storage/provider/restore/admin effects |
| Payload | Valid maxima, over-limit/deep/malformed/unknown/hostile fields; Server + plugin integration | schema errors precede persistence, queue or file mutation; bounds effective |
| Job/replay | duplicate poll/result, stale job owner, capability change, two workers, result after revoke | at-most-once terminal effects and safe idempotent retry |
| Backup | foreign backup, offset/hash/body mismatch, oversized chunk/object, duplicate complete/finalize, restore authorization race, fake provider error | no unverified object or leakage; one transfer dispatch; truthful partial state |
| Admin access | wrong/replay/expired token, foreign site, concurrent claim, revoke during delivery, duplicate ended | at most one session, revoke/expiry terminates, no secret in output |
| Egress | private/link-local/IPv6/mapped/encoded destinations, DNS changes, redirects, TLS failure, slow/large response | documented origins only, no auth redirect leak, bounded time/bytes |
| Filesystem | traversal, symlink/race, collision, malformed archive, many entries/expanded bytes, disk/permission failure, crash | no out-of-root write; safe refusal before live mutation; bounded work and cleanup |
| Stripe | missing/wrong/expired signature, wrong mode, duplicate/id collision, concurrency, handler crash/redrive, key rotation | one business effect, durable retry, no live charge or payload/secret leak |

Use synthetic sites, fake providers and Stripe TEST fixtures. For every denial assert relevant DB state, queue dispatch, mail, storage/provider calls, Connector commands, Stripe calls, restore/remediation and audit payload. No unsafe load test. Preserve M14.1 and M8–M13 regressions.
