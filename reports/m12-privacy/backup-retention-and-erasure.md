# Backup retention, deletion and erasure semantics

## Distinguish the two backup planes

### Customer WordPress backups — existing behavior

`app/Backups/Retention.php`, `BackupDeletion`, `BackupStorage`, `config/backup.php`, `backup:retention` and `docs/BACKUP_ENGINE.md` implement object deletion and metadata retention for customer-site archives. Product backup cadence is defined by pricing; retention is independent and per plan. Local config has no default retention values; actual staging/production values were not read. An existing daily scheduler can delete verified artifacts when environment config enables it. It keeps the newest verified point and protects artifacts needed for active restores/waiting updates. The single-verified-backup refusal can be explicitly overridden by an operator command, so M12 must strengthen review/authorization for erasure and not rely on this protection as a legal policy.

The delete sequence iterates the artifact's object names and then updates status/audit. Partial provider delete failure can leave a subset removed while metadata still says prior state; current reconciliation does not represent a per-object confirmed deletion ledger. M12 must add per-object/provider task state and read-after-delete verification before completion. Existing storage adapters include S3/SFTP/local; capability differences such as versioned-object deletion are provider-dependent.

### Platform database backups — M9 behavior

M9 platform artifact is a compressed and authenticated-encrypted DB snapshot, with encrypted manifest/delivery metadata, key ID and expiry class. APP_KEY is separate from backup key. `expires_at` is not proof of deletion. M9 explicitly deferred automatic platform backup cleanup; there is no verified provider lifecycle delete behavior in the code. Preserve historic M9/M8 evidence and recovery copies under their approved evidence policy.

## Erasure vs historical backups

Do not promise immediate removal from all immutable/offsite backup generations. Approved semantics should specify: (1) stop active processing and erase permitted live rows/providers promptly under an approved workflow; (2) historical encrypted recovery images expire only under a separately approved, short and measurable schedule, or cryptographic erasure only if key isolation is per subject (current platform-wide key model is not per-subject); (3) a data-subject request/tombstone ledger remains outside the backup rollback domain or is replayed from an independently protected journal; (4) before restored database/website is exposed or workers run, apply all erasure/suppression records newer than snapshot; (5) disclose residual backup retention accurately and preserve only the minimum tombstone evidence.

A tombstone should identify a stable subject/account and scopes with minimal or keyed/pseudonymous reference, request date/status, applied systems, policy version and restoration sequence. Hashing email alone is vulnerable to guessing; use keyed reference or stable internal identifier protected outside rollback, with a designed key-rotation/recovery model. Counsel must determine lawful basis/period for tombstone and backup residual data.

## Eligibility and verification states

Candidate states: `not_eligible`, `dry_run_candidate`, `blocked_active_restore`, `blocked_last_recovery_point`, `blocked_hold`, `delete_requested`, `delete_pending`, `partially_deleted`, `provider_confirmed`, `metadata_retained`, `reconciliation_required`, `complete`, `manual_review`. Never call a backup deleted because an API accepted a request. For versioned/object-locked stores, determine whether a delete marker, version purge, immutable retention or provider lifecycle changes actual recoverability.

## Mandatory guards

- No delete while upload/transfer/restore/compensation/update is active or the backup is referenced by source/safety restore IDs.
- Protect a destination-specific minimum set of verified recovery points; never globally compare S3 and Drive/replica counts as interchangeable.
- Run per-destination integrity and deletion reconciliation; keep object, manifest and metadata states linked by immutable IDs.
- Legal/security hold blocks deletion only when the hold concept and authority are approved; no indefinite vague hold.
- Canceled account is not a deletion instruction by itself. Separate cancel, paid-through end, restricted state, erasure request, statutory record exception and backup aging.
- Retention reduction or bucket cleanup always starts with a dry-run diff and cannot delete in-progress uploads or unresolved divergence.

## Failure handling

S3 timeout/unknown response → preserve pending state and retry idempotently; do not report success. Partial multi-object removal → `partially_deleted`, enumerate missing/present objects, stop user-facing restore of incomplete artifact, preserve metadata and escalate. Provider versioning may retain historical versions → record that the API-level delete did not prove physical erasure. Restoring older backup → stop queue/workers, replay tombstones, reconcile Stripe/account state and audit before resume.
