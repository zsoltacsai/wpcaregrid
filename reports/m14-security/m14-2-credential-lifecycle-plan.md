# M14.2 Connector Credential Lifecycle

## Current implementation

| Property | Source-observed behavior |
|---|---|
| Issue | Pair service exchanges one-time code for cgc_ plus 48 Str::random characters; code is locked/consumed |
| Server storage | SHA-256 credential_hash lookup; raw token returned once; hash hidden from serialization |
| Plugin storage | libsodium secretbox encrypted non-autoloaded WordPress option; key derived from secure-auth salt; salt change makes token unreadable and requires re-pair |
| Binding | One Connector belongs to one website; middleware requires active state and exact X-CareGrid-Website UUID |
| Expiry/use | No token expiry; last_seen is updated at most once/minute |
| Rotation | No separate rotate/overlap endpoint found; re-pair revokes previous active Connector and creates new token |
| Revocation | Site disconnect/server revocation/re-pair revoke and clear token; revocation cascades to open admin access |

## Threat response and lifecycle

Database-only WP backup theft is mitigated by encryption, but full WordPress runtime/admin compromise can read/use plaintext. Server DB theft reveals only the hash. Logs/UI/plugin package must not disclose token. Old/disconnected Connector token must fail every endpoint and stop queued work/admin sessions.

Keep non-recoverable server hash storage; no encrypted copy for redisplay. Define issue/active/revoked transitions and audited replacement. Prefer explicit new one-time pairing code and atomic old-token revocation/new-token creation. Do not add periodic expiry without product evidence. If response is lost after server commit, provide a safe re-pair recovery path instead of reactivating old token. Any grace overlap requires one old credential, same-site binding, short bounded expiry, audit and offline/in-flight tests; never two credentials valid indefinitely.

## Required tests

Valid pair, expired/used code, wrong host, concurrent pair, lost response/client persistence failure, salt change, token hash absent from server plaintext, tampered ciphertext, redaction, re-pair, revoke/disconnect, in-flight old request, stale token on each endpoint group, queue/admin cascade. Expected: no stale token works, one active intended credential, no raw secret in logs/UI/audit/exception/package; safe customer recovery remains understandable.
