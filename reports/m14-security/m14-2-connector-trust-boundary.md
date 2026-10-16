# M14.2 Connector Trust-Boundary Map

**Baseline:** primary 18ed2da5b0862b701ba19da6ef40b183ed6c44fb; Connector 0.4.5; source review only.

| Direction | Authentication / identity | Scope and replay | Bounds / side effects |
|---|---|---|---|
| WordPress → Laravel pair | One-time pairing code; pair limiter; code resolves one website and reported/registered host compared | Pairing code locked and consumed; response-loss recovery needs test | Field validation, but aggregate request cap not visible at controller; creates Connector, revokes old active Connector, queues supported initial jobs |
| WordPress → Laravel Connector API | cgc_ bearer + X-CareGrid-Website; server stores SHA-256 token hash and checks active state | Exact website UUID is a consistency check against token relationship; job/result scoped to website and Connector; result first-final-state wins | Named Connector limiter; schemas vary; JSON aggregate byte/depth bounds need proof; updates health/inventory, job delivery/results and disconnect |
| WordPress ↔ Laravel backup transfer | Same Connector credentials; backup scoped to Connector website | Upload requires owning Connector and acceptable state; offset exact; final object size/hash verified; download only for verified backup with active restore scope/job | Chunk max 8 MiB; object max configured 50 GiB; private server staging; S3/SFTP credentials never go to plugin |
| WordPress → Laravel admin access | Connector bearer; request has one-time token; stored token hash | Request UUID is scoped to Connector website; row lock, status and expiry checks; one-time claim; replays need concurrent test | Separate limiter; creates one dedicated admin-session job; no WordPress admin password sent |
| Connector → WordPress / WordPress.org | Same-site homepage and REST URLs; fixed checksum service URLs | Homepage probe allows bounded redirects; other fetches have distinct redirect behavior | finite timeouts; some response-size ceilings exist and others need review; no Laravel credential expected |
| Laravel → S3/SFTP | Server-side provider credentials | Keys derive from internal backup metadata and configured prefix/root | checksums/readback and provider timeouts; no Connector bearer sent |
| Stripe → Laravel webhook / Laravel → Stripe | Stripe-Signature and configured secret; server Stripe API secret for outbound calls | Event ID unique; encrypted inbox, processing lease and transactional event idempotency; timestamp/rotation tests required | subscription/invoice/payment updates; TEST fixtures only in validation |

## Invariants

1. Website authority comes from the Connector credential relationship; header must match exactly.
2. Connector transport originates from WordPress; Laravel does not call arbitrary websites for routine Connector API.
3. Request signing would not replace scope, freshness, one-time behavior or idempotency. Current TLS plus opaque bearer model should be retained unless a specific residual threat justifies another primitive.
4. Recheck tenant, Connector, job, backup and restore relationships at effect time.
5. Customer storage credentials never cross the Connector boundary.
6. Unknown filesystem/provider capability fails closed for the affected mutation.

## Final evidence cross-reference — 2026-10-16

The deployed Connector package release is 0.4.6, API v1. CI and the isolated synthetic staging results are in [M14.2 closure report](m14-2-closure-report.md). The connected synthetic M8 staging fixture still reports 0.4.5; it was deliberately not re-paired without a safe reversible WordPress admin path. No live credential was exposed or rotated.
