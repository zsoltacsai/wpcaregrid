# P0 staging backup and restore handoff — PAUSED / SUPERSEDED

**Status:** `RESTORE_WORK=PAUSED_BY_OWNER`
**Release:** `STAGING_DEPLOY=NO-GO`
**Prepared:** 2026-11-04

> **Latest Owner decision (2026-11-04): this document is historical, not an active operator request. Isolated-restore provisioning and transfer investigation are stopped. Do not send this document, open a support ticket, contact RackForest, or try another transfer path. The reported weekly provider backup is Owner-reported and is not proof of an application/database restore. Reopen only after a new Owner decision.**

## Purpose, release boundary, and current blocker

This handoff concerns only the minimum infrastructure backup/recovery gate required before the broad staging release. The first production target is marketing-only: registration, Checkout/webhook provisioning, customer onboarding, paid services and SaaS activation remain disabled. Full customer-facing M15 Backup & Restore functionality is deferred; the minimum operational backup/restore proof below remains mandatory and is not an M15 product feature.

The toolchain does not expose a verified official staging-to-WSL transfer channel. A previous transfer attempt was denied by platform execution policy; the exact rule identifier and detailed reason are not available in retained evidence. No alternative transfer route has been attempted. Status: `BACKUP_TRANSFER=BLOCKED_EXTERNAL_AUTHORITY`.

## Historical path candidates (paused)

| Candidate | Assessment | State |
| --- | --- | --- |
| A. Authorized encrypted transfer to isolated WSL/MySQL lab | Would move confidential data outside the staging boundary and previously required an approved channel. | **Paused. Do not investigate or retry.** |
| B. Administrator-provided isolated restore environment in staging infrastructure | Availability was not established. | **Paused. Do not provision or request.** |

Neither candidate is currently being pursued. This report is retained as historical planning evidence only.

## Integration & QA handoff

- Keep full M15 customer-facing Backup & Restore out of the initial-release dependency chain while those routes/capabilities remain disabled.
- For the first marketing-only release, acceptance must verify that registration, checkout/payment, webhook provisioning, Connector onboarding, paid service delivery and SaaS/background operations remain inaccessible.
- Keep the minimum infrastructure gate in scope: fresh verified pre-deployment backup, protected configuration/key recovery, actual isolated restore, target migrations on a restored clone, and exact rollback/recovery evidence.
- Record the selected restore candidate and authority reference; unknown is not PASS. Verify the exact release SHA, required CI, marketing/browser acceptance, PHP 8.4 rollback baseline, PHP 8.5 compatibility evidence (without cutover), and marketing-only background fences against the release actually under review.
- Do not repeat the already accepted worker-runtime phase investigation or reclassify synthetic migration PASS as actual restore evidence.
- Once the administrator supplies the isolated target or officially approved transfer operation, run the restore acceptance below and update the release checklist with sanitized evidence only.

## Historical operator handoff text — not authorized to send

The following former request is revoked. Do not send it or use it to initiate provider contact.

Please provision one disposable restore operation for the current staging release gate:

- **Source:** fresh, consistent backup from `stg1`, associated with the then-current exact staging app SHA and its protected configuration / `.env` / original `APP_KEY` recovery record.
- **Preferred target:** administrator-provided isolated MySQL 8.4.11 instance within the staging infrastructure boundary, with a fresh private datadir/socket, no access to the live MySQL datadir/socket, no public or shared network listener, and bounded temporary credentials. If that is unavailable, return the officially approved encrypted-transfer endpoint and operation reference for the isolated WSL lab.
- **Access:** least privilege, one operation, named staging operator and Owner only. No GitHub issue, repository, Actions artifact, public log, or agent-visible plaintext.
- **Encryption and integrity:** protect the backup at rest and in transit using the approved channel; preserve the Owner's existing key custody. Record artifact byte size and SHA-256 before/after movement. Never expose key material or database contents.
- **Retention and deletion:** retain only for the restore window, then verify deletion of the export, temporary copies, disposable database and credentials according to the platform's retention policy. Any longer retention requires separate Owner decision.
- **Return evidence:** sanitized operation reference, exact source SHA, artifact size/hash, target isolation attestation, restore/migration result codes, and cleanup result. No raw SQL, rows, credentials, `.env`, `APP_KEY`, or unfiltered logs.

## Historical operator execution plan — inactive

The following procedure is retained for audit history only. It is not execution authorization.

1. Confirm the administrator's operation reference, source/target, isolation, access, expiry, encryption, and cleanup commitments. Stop if the scope is incomplete or broader than authorized.
2. Record the current staging app SHA and create a fresh, mutually associated application/configuration/database backup. Preserve `.env` and the original `APP_KEY` without printing either value. Do not alter the live database.
3. Verify backup consistency and record size/hash. For candidate B, transfer only into the administrator-provided isolated target using its supported internal path. For candidate A, encrypt before transfer and verify ciphertext hash at both ends through the approved channel. No transfer before authority approval.
4. Restore to a newly created disposable MySQL 8.4.11 instance, with a private datadir/socket and no access to live staging services. For WSL, use `network=none`; for an infrastructure-provided target, require equivalent isolation evidence. Capture diagnostics using the approved memory-only collector; expose only allowlisted status/error codes and exit codes.
5. Verify restore using aggregate structural checks (migration table, expected table/index presence, referential/integrity checks, bounded row counts without row values). Run the seven target migrations only on a separate fresh clone of the restored disposable database. Do not use `migrate:rollback` as recovery evidence.
6. Verify application/config/database recovery as a unit without exposing `.env`, `APP_KEY`, credentials, SQL, or customer rows. Record only sanitized PASS/FAIL statuses, version/SHA identifiers and artifact hashes.
7. Stop the isolated MySQL instance. Remove only this attempt's verified temporary paths after evidence processing; verify absence. Confirm staging app, live database, workers, scheduler, Nginx and configuration remain unchanged.

## Acceptance and stop conditions

Accept the recovery gate only if backup consistency and artifact hash are verified; any authorized transfer has matching source/destination ciphertext hashes; isolated restore, all seven migrations on the restored clone, application/config/key pairing, and cleanup are independently evidenced as PASS. For the preferred in-boundary restore target, do not require an off-host ciphertext transfer. A successful gzip check or an initialization-only test is insufficient.

Stop without staging deployment if approval is absent or mismatched; encryption/hash checks fail; plaintext is exposed; the database is not isolated; the restore or any migration fails; evidence sanitization fails; cleanup cannot be verified; or any live staging state changes unexpectedly. Do not automatically retry a failed restore or migration.

## Previous continuation point — paused

The prior continuation condition is suspended by the latest Owner decision. Do not resume without a new Owner decision.

## Historical evidence snapshot at preparation time

- Primary: `7355788882e026db7c4a6d37db2b957740b5f385`.
- Read-only `git ls-remote` matched `claude/ecstatic-wozniak-oemyuf`; exact time 2026-11-04 08:55 UTC.
- Staging app SHA: `761e0e5d37558da808ac961ca2d2a68b4ffab0db`, read through the existing `caregrid-staging` SSH alias at 2026-11-04 08:55 UTC; deployed repository worktree status was clean.
- GitHub compare reports primary 545 commits ahead of staging and 0 behind. The diff is a full application release (billing/checkout, Connector, backup, migrations, deployment/runtime, localization and marketing), not an isolated marketing-only file change. The safe target is the whole exact primary through the protected controller after all gates pass.
- Exact merge CI: `41747134447` — 38/38 PASS.
- Marketing E2E: `41748568634` — PASS.
- Staging baseline: `761e0e5d37558da808ac961ca2d2a68b4ffab0db`.
- PHP 8.4 preflight and Nginx check: PASS; scheduler lock: PASS.
- Worker phase classification: accepted and closed; no source defect found.
- Synthetic MySQL migration checks: previously PASS; these do not replace restoring the current staging backup.
- Fresh staging backup and actual isolated restore: not completed; transfer remains blocked by external authority.
- Full M15 customer Backup & Restore product functionality: deferred to the first post-production milestone; unavailable capabilities must remain disabled.

**Previous requested administrator action — REVOKED:** the former request for a disposable restore target or transfer channel is no longer active. Do not send it or resume the work under this document.
