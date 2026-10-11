# M9 Infrastructure Decision Dossier

**Purpose:** record approved decisions, verified gaps and the minimum external actions for implementation/closure. This planning task did not contact or mutate a provider.

## Decisions already approved

| Decision | Status | Planning consequence |
|---|---|---|
| Dedicated S3 bucket at existing provider is primary | Approved | Mandatory M9 closure destination; separate from customer backup bucket/configuration |
| Separate S3 access key and secret | Approved | Must be bucket/prefix scoped, least privilege, not reused from customer backups |
| Owner-managed frequency and retention | Approved | Implement typed, durable, audited settings; retention deletion remains guarded |
| Google Drive as optional secondary | Approved | Optional product destination; engineering and activation must not block M9 closure |
| Independent destination state and verification | Approved | Separate delivery status/history per destination; a failed secondary cannot erase primary proof |
| No unrestricted production restore button | Approved | Console gives read-only recovery readiness and runbook access only |

Do not reopen these choices unless implementation evidence reveals a concrete incompatibility.

## Minimum Owner actions for mandatory closure

1. **Create the dedicated bucket** in the existing provider under platform/Owner control. Supply a non-secret bucket alias, region/endpoint, account identity/reference, encryption policy, public-access block evidence, versioning state and any lifecycle policy to implementation operator. Do not put bucket secrets in the report.
2. **Create separate credentials** restricted to the platform backup bucket and required prefix. Upload role: put/get/head/list exact prefix and multipart operations needed; deny public ACL, bucket administration, unrelated customer prefixes and object deletion. Use a separate retention role only if deletion is later approved. Store secret in approved runtime secret management, not OwnerSettings, source, logs or chat.
3. **Choose APP_KEY escrow location** from an existing independent enterprise password manager/secrets vault with break-glass control and two authorized custodians. Confirm this is the same exact key used by the deployment without exporting it into evidence. Approve and execute a retrieval drill without printing/copying the value into reports.
4. **Select separate backup-encryption key management**: v1 recommendation is a dedicated envelope-encryption key held by an approved cloud KMS/secret manager if available; keep recovery access independent of application host and document versioned key IDs and break-glass decrypt. If KMS purchase/provider change is needed, Owner approves cost/account policy. If no KMS is already available, select a vault-held random key with dual-control retrieval and tested offline recovery. Do not use APP_KEY as data key.
5. **Approve provider capability evidence** via read-only review: bucket/account separation, encryption, IAM scope, versioning and lifecycle. Versioning recommended before public launch; Object Lock/immutability and replication remain optional cost/provider decisions. Do not enable automatically.
6. **Approve disposable MySQL recovery test service** only if CI service is insufficient for interactive drill. CI workflow already declares MySQL 8.4 service and MySQL matrix, but prior report states it was not run. No production/customer DB is used.

## Optional Drive activation actions

Only when Drive integration is scheduled for implementation/activation: choose dedicated Google Workspace account/shared drive and recovery custodians; create dedicated Google Cloud project/OAuth client; approve consent-screen branding/privacy policy and callback; confirm required scope is drive.file or explicitly authorize a scope review; resolve Google app verification requirements; configure quota/storage budget; authorize optional provider sandbox. These are not blockers for S3-based M9 closure.

## RPO/RTO recommendation

Keep existing target 24-hour platform DB RPO and 4-hour RTO as measurable objectives, not assurances. Start S3 at every 12 hours by default after benchmark; selectable 6h/12h/daily/weekly. Weekly requires Owner to acknowledge changed RPO. Target measured restore-to-isolated-DB time under 4h from alert to verified recovery readiness; actual production cutover time can be longer and remains unproven. Alert at 20h artifact age, critical at 24h; tune after observed dump/upload/import durations. Release rollback target 2h remains an objective. Customer backup recovery is limited to last verified object and provider actual recovery time. No zero-loss claim.

## Provider features and cost controls

Separate bucket/credentials reduce accidental sharing and blast radius but remain same provider/account failure domain unless account/region differs. Verify:

- dedicated bucket identity and region/endpoint;
- block-public-access and no public ACL;
- platform-only credential scope and deny-delete permission;
- encryption at rest and client-side authenticated encryption;
- server checksum behavior for multipart and actual full-object SHA semantics;
- versioning, Object Lock/retention capability, replication and lifecycle, if enabled;
- multipart incomplete-upload listing/abort semantics and cost;
- access logs/audit events and restore of deleted version;
- cost dimensions: stored bytes × retention, PUT/GET/LIST/request charges, retrieval tier/egress, replication, versioned duplicate storage, multipart leftovers, and MySQL drill reads.

No current price or provider capability has been established in the repository; obtain current provider quote and terms before cost commitments. Do not interpret an S3-compatible API as proof of AWS-specific controls.

## Existing implementation to retain

Retain the current PlatformDatabaseBackupService’s single MySQL snapshot, release/environment/database identity metadata, private sidecar, SHA-256/gzip checks, deployed local-disk refusal and isolated import-verification requirements. Retain read-only customer and platform reconciliation commands, fixed object-key validation, customer streaming storage interfaces, object/incomplete-upload listing and the recovery-mode queue quarantine protections. Retain the recovery signal/health contract and Stripe duplicate-event persistence. Do not replace these systems with a second general-purpose backup engine.

Required engineering extension: current platform record binds one dump to one disk/key and has static scheduler slots. Add a destination-neutral artifact plus independent destination-delivery lifecycle without changing customer backup metadata semantics. Reuse the existing stream/storage primitives only where exact platform bucket credentials, key layout and policy are separately enforced. The app’s OwnerSettings and AdminAudit patterns are suitable for validated non-secret schedule settings, but OAuth and storage tokens belong in encrypted secret storage and must never enter normal settings values.

## Classification of choices

| Choice | Classification |
|---|---|
| Dedicated S3 bucket, credentials, encryption, verified remote import | Mandatory before M9 closure |
| APP_KEY escrow and retrieval evidence | Mandatory before M9 closure |
| MySQL 8.4 CI and disposable import/boot drill | Mandatory before M9 closure |
| Dynamic validated primary frequency/retention settings and destination visibility | Mandatory because Owner-managed frequency/retention were approved |
| Owner-only history/status/manual backup and safe enable/disable | Mandatory for approved Owner Console scope |
| Bucket versioning | Recommended before public launch; provider review/cost approval |
| Object Lock, separate cloud account/region, replication | Optional provider/cost decision; stronger isolation recommendation |
| Google Drive adapter and activation | Optional separate track, not M9 closure gate |
| Automatic deletion of platform/customer objects | Blocked until retention semantics and M12 legal decisions are resolved |
| Customer cancellation/deletion/legal holds | M12 legal/product decision |
| Full alert/notification center | M10; M9 only defines machine-readable health/events |

## Evidence expected for closure

Non-secret evidence: bucket identity alias and region, credential policy review result/version reference, encryption and public access checks, verified S3 object size/digest/readback, authenticated manifest result, isolated MySQL 8.4 import/schema/count and app boot, recovery queue replay test, APP_KEY escrow retrieval drill timestamp/operator/result (no key), measured snapshot/upload/import durations, retention dry-run outputs, CI run URL/status and test counts, staging deployment SHA/health only if source changes require guarded staging validation. Do not include credentials, OAuth tokens, account recovery codes, full environment, DB dumps, or signed URLs.
