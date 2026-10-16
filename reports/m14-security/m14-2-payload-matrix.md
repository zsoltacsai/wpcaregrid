# M14.2 Connector Payload Trust Matrix

| Payload | Existing validation | Remaining validation |
|---|---|---|
| Pair | pairing code max32, installation regex, URL/version bounds, capabilities max20 | aggregate bytes/depth, unknown fields, host confusion and fail-before-side-effect |
| Heartbeat / health | field bounds; health enum and findings max50; labels/messages bounded | nested depth/aggregate size, stale/future timestamps, control chars, unknown keys |
| Inventory | plugins max500, themes max100, filename patterns, selected fields only stored | aggregate size/depth, duplicate identifiers, Unicode/control chars and cost before validation |
| Job result | status/code/date bounds; data/error are arrays; error message max500 | explicit depth/member/byte bounds absent from visible controller rules; typed per-job result schema |
| Backup chunk | streaming; max chunk 8 MiB; max object configured; whole-object SHA check | exact length/body enforcement, concurrent offsets, buffer/proxy limits, object-count ceilings and partial cleanup |
| Finalize manifest | requires array; BackupManifest builds canonical document from received object records | nested size/schema bounds and no dispatch/state advance on invalid data |
| Admin access | UUID max36; token max128; service enforces 64 lower-hex token and hash | token replay, request scope, concurrent claim and bounded attempt behavior |
| Stripe | Stripe SDK parses raw signed payload; encrypted inbox, unique event ID | raw body/proxy cap, timestamp tolerance, duplicate ID with altered signed payload, parser-depth behavior |

Use canonical schemas with explicit max bytes, depth, array/member counts and string lengths. Set limits from representative legitimate fixtures and configured backup/job bounds, not arbitrary small values. Test valid maximum, just-over-limit, deep nesting, malformed JSON, invalid UTF-8, control characters, unexpected types and unknown fields. Rejection must precede persistence, queueing, external calls or file mutation.

## Implementation evidence update — 2026-10-16

The server now applies shared byte/depth/member/scalar bounds before Connector pair, heartbeat, inventory, job-result and backup-finalize effects. Tests cover nested result rejection with unchanged job state, declared oversized Content-Length before reading the body, and oversized finalize manifests without finalize/dispatch. Stripe ingress separately rejects raw and declared payloads above 2 MiB before inbox persistence. Full Laravel SQLite and Connector WordPress SQLite/MySQL suites are green locally. The strict CI MySQL jobs remain the release evidence for production database semantics; the candidate report must retain any current CI skips/failures explicitly.
