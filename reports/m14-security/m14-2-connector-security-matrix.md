# M14.2 Connector Security Matrix

Current routes/api.php has **13 Connector API declarations = 10 mutators + 3 reads**. Pairing is the only route without existing Connector bearer auth; it uses one-time pairing code.

| Method / route | Auth / limiter | Input and side effect | Replay / test focus |
|---|---|---|---|
| POST /pair | pairing code / connector-pair | URL/version/capability fields bounded; creates Connector, revokes old Connector, queues initial checks | used/expired code, concurrent pair, server response loss |
| POST /heartbeat | Connector bearer + exact website UUID / connector-api | bounded versions, health up to 50 findings and capabilities up to 20; updates health/site state | stale timestamps, nested/large body, spoofed URL |
| POST /inventory | same | plugins max500, themes max100, allowlisted fields; upserts inventory | depth/aggregate bounds, unknown fields and duplicated identities |
| GET /jobs | same | max 5 ordinary jobs, one in controlled mode | stale capability/status, repeat polling and delivery attempts |
| POST /jobs/{job}/result | same | status/code/date fields bounded; data and error.details accept arrays | final result first-wins; nested/byte bounds, foreign/stale job, duplicate side effects |
| POST /disconnect | same | revokes Connector, jobs and admin access | repeat call harmless; old token fails across every route family |
| GET /backups/{backup}/objects/{name} | same + website-scoped backup / connector-transfer | read upload state | valid foreign backup is 404 without object creation |
| PUT /backups/{backup}/objects/{name} | same | raw chunk; 8 MiB max and configured object max; optional chunk checksum | offset, size/body mismatch, partial write, duplicate concurrent chunk |
| POST /backups/{backup}/objects/{name}/complete | same | positive size + SHA-256; full object hash verified | repeated same completion no-op; mismatch clears unverified data |
| GET /backups/{backup}/objects/{name}/content | same | bounded range from verified backup during active restore | restore/revoke race and no foreign object disclosure |
| POST /backups/{backup}/finalize | same | manifest array then delegated canonical validation | deep/large manifest; only one transfer dispatch |
| POST /admin-access/claim | same / connector-admin-access | request UUID + token; service requires 64-char lowercase hex | locked one-time transition; duplicate/concurrent/foreign claims |
| POST /admin-access/{uuid}/ended | same | reason enum and UUID; closes same-site request | duplicate callback harmless; foreign UUID denied |

Server production HTTPS is required by default. Plugin client verifies TLS, disables redirects and only sends bearer on authenticated requests. Existing tests prove valid auth, foreign website header rejection, foreign job result denial, disconnect revocation, per-Connector 429, inventory field sanitization and plugin token-at-rest/log/UI protections.

M14.2 must prove valid foreign backup/object/admin attempts have zero side effects; revoked token fails on all paths; payload size/depth limits; duplicate/concurrent finalize and claim; and fresh scope immediately before effects. Request-signing is not recommended by default: TLS + high-entropy bearer + exact-site binding is the current model; add nonce/signature only for a demonstrated residual threat.

## Implementation evidence update — 2026-10-16

Connector release source is now `0.4.6` (API remains v1). Added regressions cover lost pairing response/re-pair credential state, API response byte ceiling, bounded ingress payloads and finalize body size. Existing route-level active-credential, exact website/job/backup/admin scope, pairing replacement, disconnect revocation, one-time claim and idempotent result/finalize tests remain in force. Plugin code explicitly disables redirects on the central API client and caps JSON/raw responses. Full WordPress SQLite and MySQL suites passed with the generated `0.4.6` package; see `m14-2-findings.md` for exact counts and skips. These results are local; exact CI and synthetic staging validation remain pending.
