# Optional Google Drive Secondary Destination

**Classification:** optional M9-adjacent capability, not an M9 closure requirement. The primary S3 bucket remains required and authoritative. No OAuth client, Google project, consent screen, token, or Drive resource is configured or changed in this planning task.

## Scope and product boundary

Drive provides an additional off-provider copy of an already authenticated-encrypted platform artifact. It does not replace S3, establish an immutable copy, define customer file backup behavior, or enable production restore. Store a per-destination delivery record and status; Google account/API outage must not stop a successful S3 upload, and S3 failure must remain visibly primary-degraded even if Drive succeeded.

Before implementing, prove the selected Google account and folder are operationally independent from the failed SaaS host and primary S3 credentials. Prefer a dedicated Owner-controlled Workspace account or shared drive with named break-glass custodians, not an employee’s personal account. Define account ownership, offboarding, recovery email/security key, billing/storage quota, and a documented manual download/import procedure independent of the running SaaS. Google account takeover/loss remains a correlated risk.

## OAuth authorization design

1. Register a dedicated Google Cloud project and OAuth client for this feature; separate development/test from production credentials. Configure authorized domain, privacy policy, product name, contact addresses and exact HTTPS callback URI.
2. Require Owner authorization, recent session and CSRF-protected initiation. Generate cryptographically random OAuth state, bind it to Owner ID and browser session, single-use, short expiry; compare in constant time. Require exact redirect URI and PKCE if supported by the chosen server-side flow/library. Never accept user-provided redirect targets.
3. Request offline access only when necessary for scheduled unattended uploads. Store returned refresh token encrypted using existing Laravel Crypt/APP_KEY-protected secret storage or a dedicated secret manager; never expose it again in UI. Record granted scopes, Google account stable subject ID, token version/updated time, scope expiry/revocation state, and an encrypted token reference. Do not persist access tokens longer than needed.
4. On refresh, serialize refresh operations to avoid races; treat invalid_grant/consent revoked as reauthorization-required, stop Drive retries and leave S3 unaffected. Do not log OAuth responses, authorization codes, refresh tokens, access tokens or resumable session URIs. Disconnect first marks Drive unavailable and stops new sends; separately revoke token through supported provider endpoint when authorized, then erase local ciphertext and audit the action. Existing encrypted backup files remain in Drive unless separately reviewed deletion is approved.
5. Verify OAuth state and callback in tests; deny replay, wrong Owner/session, expired state, mismatched redirect URI and unapproved scope expansion. Owner Console displays only account label/last four or masked identity, scope status and token health, not token material.

## Scope selection and Google verification

Start with the narrowest viable scope, likely Drive API drive.file, and a dedicated app-created folder. Confirm experimentally whether the selected Drive API flow can create the folder, persist file IDs, list/read only app-created files, and later read back exact files without requiring broad metadata or drive-wide access. Do not add drive or drive.readonly for convenience. If product requirements require broad file access, stop and reassess scope, OAuth verification, user data policy/security assessment and launch timeline before coding.

Official current guidance classifies drive.file as non-sensitive and broad drive/drive.readonly access as restricted; OAuth scopes must be declared in Cloud Console and requested in code. Public apps requesting sensitive or restricted scopes need applicable verification, and restricted-scope server storage can trigger a security assessment. Verification may also require accurate brand/domain/privacy-policy details and the stated user-data use case. Exact review applicability depends on final account type, audience and scope, so treat Google Cloud console confirmation and legal/privacy review as a pre-activation gate. A non-sensitive scope still requires correct product/app identity and compliance with Google API policies. OAuth verification approval is not evidenced. Sources reviewed 2026-10-11: [Drive API scopes](https://developers.google.com/workspace/drive/api/guides/api-specific-auth), [OAuth verification requirements](https://support.google.com/cloud/answer/13464321?hl=en), [unverified apps](https://support.google.com/cloud/answer/7454865?hl=en).

## Folder and object ownership

Use one dedicated folder under the connected account/shared drive, created by the app and stored by stable Drive file ID. Do not search the user's entire Drive. Store only application-created files in a fixed MIME type and naming pattern containing artifact UUID and format version; object name is not trusted identity. Persist Drive file ID, parent folder ID, account subject, size, encrypted SHA-256, app properties for opaque artifact UUID, and creation/update time. Validate server-side folder/file IDs against the previously connected account and parent. Prevent arbitrary Drive ID injection and cross-account object access.

Sharing remains private and limited to explicit recovery custodians according to Owner policy. Do not create public or anyone links. A human-accessible off-host procedure requires an authorized custodian to sign into the dedicated account, export encrypted bytes and manifest, verify authenticated manifest/ciphertext checksum offline, retrieve the separate decryption key from escrow, decrypt in isolated environment, and import only after approval. Google login must not depend on the failed SaaS. Record recovery evidence but not tokens or secret values.

## Large/resumable transfer and retries

Use Drive API resumable upload for large artifacts, stream encrypted bytes, and persist upload-session state encrypted and short-lived. Google documents a multi-step initiation/upload/resume flow; sessions expire after one week. Treat session URI as a bearer secret. On interruption query upload status, continue from provider-acknowledged range, then verify final file ID and size. If a session expired, check for completed artifact by app properties/file ID before restarting. Use bounded exponential backoff with jitter; honor Retry-After/429/5xx/quota errors. Authentication failure stops retry and asks for reauthorization. Do not create a new logical artifact on retry. Partial uploads remain incomplete/unverified; cleanup is a separate report-only reconciliation initially.

## Verification claims

Drive file metadata (file ID, size, checksum field when present, modified time, parent and appProperties) proves provider accepted a file and can establish expected identity/size. Provider metadata or ETag alone does not prove ciphertext SHA-256 nor authenticated manifest. If Drive exposes a supported checksum with exact whole-object semantics for uploaded binary content, verify semantics against test vectors before relying on it. Baseline acceptance is independent streaming download/readback and local SHA-256 comparison, followed by authenticated manifest check. Periodically decrypt/import a disposable copy; the Owner UI calls this recovery-drill-tested, not merely uploaded or verified. A successful upload is not a restore drill.

## Quota, API limits and failure states

Classify quota exceeded, rate-limited, storage full, authorization revoked, access denied, provider unavailable, session expired, remote object missing, checksum mismatch and manifest authentication failure separately. API limits use bounded jitter/retry; quota exhausted pauses Drive attempts and surfaces an Owner action. S3 remains independent. Do not automatically remove old Drive files to free space while legal-retention/hold policy is unresolved. Drive failure never alters or deletes S3 state; S3 failure remains a closure/primary-health failure even when Drive is healthy.

## Retention and deletion

Give Drive its own retention class/expiry candidates and reconciliation state, but do not enable automatic deletion initially. Drive does not provide S3-equivalent Object Lock/versioning guarantees in this architecture; retention is application-controlled and vulnerable to connected-account Owner actions. Keep audit history and last verified recovery point, backup holds, active drill references, in-progress session, and divergence safeguards. Any later deletion requires dry run, confirmation, last-point check, exact app-created file ID/parent verification, and separate least-privilege capability. Never delete files merely by broad folder/name match.

## Optional acceptance tests

Mocked tests: state/CSRF, scope allowlist, token encryption/redaction, refresh race, revocation, pagination/known-ID lookup, upload resume state, Retry-After, quota/auth isolation, duplicate upload prevention, S3/Drive independent status, readback checksum mismatch and no public sharing. Provider sandbox tests: OAuth consent, dedicated folder, large resumable upload interruption/resume, readback bytes, quota/error handling, revocation/reconnect, and manual recovery without SaaS. Do not claim these provider behaviors from mocks. Do not activate until app verification/privacy requirements and account ownership are confirmed.
