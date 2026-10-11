# Dual-Destination Platform Backup Architecture

**Status:** proposed target design; S3 mandatory primary, Google Drive optional secondary. No provider resources or credentials were changed or inspected live.

## Existing verified code contracts

The current platform backup service creates one DB dump and writes one object plus a private JSON sidecar to a configured Laravel disk. One platform_database_backups row stores storage_disk, object_key, manifest key, size, hash and verification state. It supports MySQL logical dump and separate disposable MySQL import verification; SQLite VACUUM INTO supports the local drill. Platform schedules are static daily/weekly/monthly entries. The customer BackupStorage interface accepts streams and offers put/verify/readRange/delete, with S3 streaming upload, SHA-256 checksum or readback verification, object listing and incomplete multipart listing. That customer abstraction is useful but its per-site ObjectKey contract and credential configuration cannot be reused unchanged for platform backup objects. Platform data requires a separate bucket, prefix, access key and policy.

## Target entities and identity

Separate logical snapshot from destination copy:

- PlatformBackupArtifact: stable UUID, source environment, DB identity hash, release SHA, DB engine/version, schema/manifest version, creation time, plaintext size (restricted internal metadata), encrypted size, encryption key ID/version, ciphertext SHA-256, authenticated manifest reference, source backup record and verification level. Do not store plaintext SQL or secrets in ordinary logs.
- PlatformBackupDestinationDelivery: artifact UUID, destination enum (s3_primary / google_drive_secondary), immutable remote bucket/folder+object identity, upload attempt/idempotency key, multipart/session reference (secret-bearing values encrypted and short-lived), state, provider-native metadata, remote size/hash, readback result, verification timestamp, retention class, failure category and retry timing. Unique constraint on artifact+destination+delivery generation prevents duplicate logical deliveries.
- Schedule occurrence/settings version: destination, UTC slot, immutable configuration version, artifact UUID, dispatch idempotency key. This enables retries without duplicate dumps and gives independent history.

Migration compatibility: retain existing platform_database_backups UUID and sidecar identity. Introduce additive tables/columns with nullable/default-safe values. Backfill old single-destination rows as legacy platform artifact deliveries only after verifying their current disk/object/manifest mapping. Existing object keys and evidence remain valid; no mass rewrite or deletion.

## Artifact pipeline

1. Take a single consistent DB snapshot. MySQL uses existing single-transaction dump approach with routines, triggers, events and binary-safe options; require quiescence/engine guarantees documented by the existing service. SQLite remains a test/drill path, not production substitute.
2. Validate dump completion/exit status, nonzero size, gzip/readability, expected schema/table manifest, and source identity.
3. Encrypt the completed artifact before any remote upload using a reviewed authenticated-encryption library/API and a separate versioned backup data key. Store algorithm/format version, key version ID, nonce/tag metadata as required by library contract, but never key material. APP_KEY is not the artifact encryption key.
4. Hash the exact encrypted bytes with SHA-256 while streaming. Produce an authenticated manifest binding artifact UUID, DB identity hash, source env, release SHA, format version, encrypted length and ciphertext digest. The manifest may be signed/MACed with a separately protected integrity key or authenticated inside the encrypted envelope; select one construction during implementation review and test tampering. Do not trust an adjacent unsigned checksum as authenticity proof.
5. Upload encrypted bytes to every due enabled destination, independently, through bounded streaming/multipart or resumable upload. Use deterministic object key from validated UUIDs and fixed names, not caller path fragments. Keep remote objects private.
6. Verify each destination independently. Check provider object ID/key, size, metadata and provider checksum semantics. For native full-object SHA-256, prove it is not a multipart composite. Otherwise perform a streaming readback and compare digest of ciphertext. Separately validate manifest authentication. A storage HEAD/ETag alone is not cryptographic readback proof.
7. Run periodic isolated decrypt/import verification for recovery readiness. Keep separate per-artifact states: bytes_verified, decrypt_verified, database_import_verified, boot_sanity_verified. A destination can only report the level actually run.
8. Emit machine-readable signals with artifact and destination IDs plus safe category codes; no SQL, tokens, presigned URLs, credential material or customer payloads.

## Storage layout and isolation

S3 primary uses a dedicated platform bucket and its own scoped credentials, distinct from customer-backup credentials. Prefix partition: platform-db/v1/YYYY/MM/DD/<artifact-uuid>/artifact.enc, manifest.auth.json. Enforce private ACL/block-public-access at bucket level and bucket-scoped IAM policy; actions only needed for put/get/head/list-prefix/multipart abort as designed. Deletion is excluded from upload credential; a separate retention operator role is reserved for separately audited cleanup after policy approval. The service validates bucket/prefix against fixed configuration at construction and refuses local disk in deployed environments. Encryption at rest requested/verified separately (SSE-S3 or owner/provider-selected SSE-KMS); client-side encryption remains required for Drive and recommended uniformly for both destinations.

Drive secondary uses a dedicated Owner-selected Google account/shared drive and private app-created folder. Store file IDs, not filenames as authority. Drive access tokens are short-lived; refresh token/connection state is encrypted under APP_KEY-backed credential storage and therefore APP_KEY escrow is a prerequisite. OAuth grant is per-owner connection and revocable. Keep Drive status distinct from S3. Drive provides no S3-equivalent retention/Object Lock guarantee in this design; do not promise immutability/versioning.

## Scheduling and destination semantics

The primary S3 cadence is mandatory and owner-managed. Recommend 12-hour default after a capacity benchmark, choices 6h/12h/daily/weekly; weekly explicitly displays loss of 24-hour RPO target. Drive schedule is separately enabled and chooses a cadence. A Drive due slot reuses the newest verified encrypted artifact only if artifact age is within Drive freshness setting; otherwise snapshot once and fan out to all destinations currently due. No duplicate mysqldump just because S3 and Drive have different slot times. Manual backup creates one artifact and attempts currently enabled destinations, each with independent outcome.

A DB-backed schedule runner checks each minute. It writes a unique UTC slot row transactionally before dispatch, backed by a distributed lock (existing Laravel cache lock convention) and unique DB idempotency. If scheduler was down, dispatch only the latest due slot per destination inside a configured bounded catch-up window; older missed slots are skipped with an audited event, never flooded. Schedule changes persist as typed Owner settings/schedule versions. A running occurrence captures the old version; settings take effect at the next not-started UTC slot. Show local time using Owner timezone only for presentation; compute slots in UTC, avoiding DST duplicates/misses. Enforce one active snapshot generation globally; destination transfers may parallelize under bounded concurrency and their own idempotency keys.

State matrix:

| S3 primary | Drive secondary | System result |
|---|---|---|
| verified | verified | both destinations independently recovery-ready at their recorded verification levels |
| verified | failed/unavailable | primary healthy; secondary degraded/failed; retry Drive boundedly; no duplicate dump if artifact still fresh |
| failed | verified | S3 primary unavailable; do not claim policy success; preserve Drive copy; alert primary failure and retry S3 |
| failed | failed | backup system unavailable; preserve dump locally only in private controlled temp until bounded retry/cleanup; alert and avoid claiming recovery point |
| running | any | same artifact/attempt idempotent; unique active snapshot lock prevents second DB dump |
| partially uploaded | any | resume multipart/resumable session if provider supports; otherwise abort/replace same deterministic object version under CAS and verify before advancing; never mark verified on partial |

## Failure and consistency controls

- Snapshot crash before artifact completion: record failed/incomplete, remove only private temp under exact generated UUID after failure audit; no destination sees it.
- Upload timeout after remote success but before response: query deterministic object key/file ID and verify checksum; do not blindly create another copy.
- Provider unavailable: mark that destination unavailable and preserve the other destination result.
- Credential revoked: stop retries on auth failures, emit credential action needed, keep verified historical objects untouched.
- Drive quota/API limit: honor retry-after, exponential backoff with jitter, max attempts/time window; report quota exceeded separately from storage unavailable. Use resumable uploads for large artifacts; protect resumable URI as secret and note its provider expiry; after expiration query known file ID/object state and restart only if no verified object exists.
- Metadata divergence: report-only until operator classifies exact artifact; never auto-delete or rewrite unknown remote objects.
- Retention: mark candidate eligible only after computing all destination classes and holds. Refuse deletion if it is the last verified point for that destination, active restore/drill reference, in-progress upload, metadata divergence, legal/security hold or incident mode. Retention deletes are future opt-in, dry-run first, separately scoped role and dual confirmation.
- Reconciliation: compare DB deliveries ↔ sidecar/authenticated manifest ↔ provider listing/readback. Record unavailable distinct from missing. S3 listing is constrained to dedicated prefix; Drive enumerates known app folder and exact app-created MIME/IDs. Orphans are quarantined/reported, never automatically deleted in M9.

## Verification vocabulary

Created = source dump closed and artifact row durable. Encrypted = authenticated encryption finished. Uploaded = provider accepted bytes. Provider-integrity-checked = size plus a cryptographic native full-object checksum whose semantics are verified. Readback-verified = remote ciphertext streamed and SHA-256 equals expected. Decrypt-verified = authentication/decryption succeeded with a recovery key. Import-verified = import to disposable DB passed engine/schema/count checks. Recovery-drill-tested = application booted/read representative records in isolated recovery environment. Owner UI must show the highest attained level and timestamp, never collapse all to “verified”.

## Provider resilience statement

A separate bucket and key at the existing provider reduces accidental cross-use and credential blast radius. It does not remove provider account takeover, account suspension, provider-wide outage, region failure, correlated administrative deletion, shared billing failure, or legal hold risks. Versioning, Object Lock/immutability, cross-region replication, separate provider account, lifecycle, and their costs require Owner/provider review and are not enabled or claimed here.
