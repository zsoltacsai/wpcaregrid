# M14.2 Connector Admin-Access Protocol Review

The customer authorizes a time-bounded AdminAccessRequest with opaque UUID and one-time browser token; server stores token hash. Connector claims through authenticated POST /admin-access/claim. Service scopes the UUID to Connector website, locks row, checks token/hash/status/expiry/subscription/capability/access level, transitions to Authorized, and binds one CREATE_ADMIN_SESSION job. Job result transitions session to active/failed. POST /{uuid}/ended closes a same-site open request. Connector revoke ends open access.

## Existing controls

Separate admin-access limiter, hashed one-time token, row lock, failed-attempt threshold, convergent outward failure for unknown/foreign/wrong-token requests, rechecks for subscription/capability/level, bounded session expiry, Connector website scope and revocation cascade. No WordPress admin password is sent.

## Required adversarial cases

| Case | Expected |
|---|---|
| Valid claim once | One authorized state transition and one bound session job |
| Replayed or concurrent claim | At most one winner; no duplicate job/session |
| Wrong, expired, revoked token or failed-attempt ceiling | No job/session; no token in audit/log |
| Foreign website UUID | Deny/not-found per policy; no foreign state/job/audit data |
| Connector revoked or capability removed before claim | Fresh check blocks |
| Request revoked/expired while job queued/delivered | No new/retained admin session |
| Duplicate ended callback or foreign UUID | Idempotent same-site close; foreign scope denied |
| Re-pair/disconnect with active session | Session ends; old token cannot claim/end another site |

Inspect escaping/host binding for reported admin_access_url; it is flow metadata, not authority or an outbound fetch target. Keep M14.1 Owner/customer authorization, CSRF and recent-auth controls. Staging uses isolated synthetic WordPress only, with no real admin session.
