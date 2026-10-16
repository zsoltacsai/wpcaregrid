# M14.2 Stripe Webhook Security Matrix

| Property | Current source evidence | Closure test |
|---|---|---|
| Endpoint | One POST /stripe/webhook behind recovery-quarantine middleware | route boundary, request-body/proxy size and no browser-CSRF exception drift |
| Signature | Stripe SDK Webhook::constructEvent on raw body and configured secret; invalid signature/JSON returns 400; missing secret returns 500 | effective timestamp tolerance, malformed signature, altered bytes, clock skew |
| Mode | event livemode checked against configured test/live mode and live-enable flag; recognizable secret-key prefix checked | wrong mode refuses before inbox/effects; no live test credentials |
| Durable intake | Unique stripe_event_id inbox row; encrypted payload cast; event identifiers and status stored | DB ciphertext, same ID different signed payload behavior, DB failure and PII handling |
| Idempotency | Unique StripeEvent ID and transactional handler; duplicate event no-op | same event concurrent/repeated and one business effect/invoice/subscription |
| Retry | RECEIVED/PROCESSING/FAILED/PROCESSED, attempts, five-minute lease; scheduled drain requeues eligible rows | duplicate workers, lease expiry, crash around handler commit, bounded poisoned-event backoff |
| Logging | exception class/category and event ID; raw payload/secret not logged in inspected path | hostile payload/log redaction, retention and retry storm visibility |
| Rotation | one configured webhook_secret visible; no dual-secret support evidenced | Stripe TEST rotation sequence; overlap only if application supports it, otherwise controlled cutover/retry |

Current signature verification, mode guard, unique event ID, encrypted inbox, durable retry and event idempotency are substantial controls. Timestamp boundary, secret rotation, raw request limits and all worker races still need explicit proof. No webhook vulnerability is confirmed. Stripe TEST only; no live charge.
