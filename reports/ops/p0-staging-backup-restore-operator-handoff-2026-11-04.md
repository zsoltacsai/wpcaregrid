# P0 staging backup and restore handoff

**Status:** `BACKUP_TRANSFER=BLOCKED_EXTERNAL_AUTHORITY`
**Release:** `STAGING_DEPLOY=NO-GO`
**Prepared:** 2026-11-04

## Purpose, release boundary, and current blocker

This handoff concerns only the minimum infrastructure backup/recovery gate required before the broad staging release. The first production target is marketing-only: registration, Checkout/webhook provisioning, customer onboarding, paid services and SaaS activation remain disabled. Full customer-facing M15 Backup & Restore functionality is deferred; the minimum operational backup/restore proof below remains mandatory and is not an M15 product feature.

The toolchain does not expose a verified official staging-to-WSL transfer channel. A previous transfer attempt was denied by platform execution policy; the exact rule identifier and detailed reason are not available in retained evidence. No alternative transfer route has been attempted. Status: `BACKUP_TRANSFER=BLOCKED_EXTERNAL_AUTHORITY`.

## Approved-path candidates and recommendation

| Candidate | Assessment | State |
| --- | --- | --- |
| A. Authorized encrypted transfer to isolated WSL/MySQL lab | Uses the known rootless Ubuntu 24.04 / MySQL 8.4.11 lab and avoids changes to staging MySQL, but takes confidential database ciphertext outside the staging boundary. Requires an official platform-approved one-time channel. | Fallback only; unavailable until external authority provisions the channel. |
| B. Administrator-provided isolated restore environment in staging infrastructure | Keeps backup data within the staging infrastructure boundary and avoids off-host transfer. Prefer this if an already supported isolated target can be provided without touching the live DB, changing AppArmor, weakening platform policy, or adding a general privileged path. | **Recommended first:** ask the staging administrator to provide a disposable, isolated MySQL 8.4.11 restore target and approved one-operation restore path. It is not yet evidenced as available. |

The external administrator/operator must either provide candidate B under the constraints above, authorize candidate A through an official managed transfer service, or explicitly report that neither supported path exists. Do not substitute SCP, shell streaming, CI artifacts, browser uploads, or another transport.

## Integration & QA handoff

- Keep full M15 customer-facing Backup & Restore out of the initial-release dependency chain while those routes/capabilities remain disabled.
- For the first marketing-only release, acceptance must verify that registration, checkout/payment, webhook provisioning, Connector onboarding, paid service delivery and SaaS/background operations remain inaccessible.
- Keep the minimum infrastructure gate in scope: fresh verified pre-deployment backup, protected configuration/key recovery, actual isolated restore, target migrations on a restored clone, and exact rollback/recovery evidence.
- Record the selected restore candidate and authority reference; unknown is not PASS. Verify the exact release SHA, required CI, marketing/browser acceptance, PHP 8.4 rollback baseline, PHP 8.5 compatibility evidence (without cutover), and marketing-only background fences against the release actually under review.
- Do not repeat the already accepted worker-runtime phase investigation or reclassify synthetic migration PASS as actual restore evidence.
- Once the administrator supplies the isolated target or officially approved transfer operation, run the restore acceptance below and update the release checklist with sanitized evidence only.

## Exact operator handoff request

Please provision one disposable restore operation for the current staging release gate:

- **Source:** fresh, consistent backup from `stg1`, associated with the then-current exact staging app SHA and its protected configuration / `.env` / original `APP_KEY` recovery record.
- **Preferred target:** administrator-provided isolated MySQL 8.4.11 instance within the staging infrastructure boundary, with a fresh private datadir/socket, no access to the live MySQL datadir/socket, no public or shared network listener, and bounded temporary credentials. If that is unavailable, return the officially approved encrypted-transfer endpoint and operation reference for the isolated WSL lab.
- **Access:** least privilege, one operation, named staging operator and Owner only. No GitHub issue, repository, Actions artifact, public log, or agent-visible plaintext.
- **Encryption and integrity:** protect the backup at rest and in transit using the approved channel; preserve the Owner's existing key custody. Record artifact byte size and SHA-256 before/after movement. Never expose key material or database contents.
- **Retention and deletion:** retain only for the restore window, then verify deletion of the export, temporary copies, disposable database and credentials according to the platform's retention policy. Any longer retention requires separate Owner decision.
- **Return evidence:** sanitized operation reference, exact source SHA, artifact size/hash, target isolation attestation, restore/migration result codes, and cleanup result. No raw SQL, rows, credentials, `.env`, `APP_KEY`, or unfiltered logs.

## Operator execution after approval

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

## Automatic continuation point

Once the administrator provides candidate B or formally enables candidate A and supplies its operation reference and isolation evidence, resume at operator-execution step 2. Continue through restore and migration proof using the existing approved memory-only diagnostic collector. Do not repeat the closed worker-runtime investigation, successful CI/E2E, or synthetic-only migration checks. Re-evaluate remaining staging gates once against the then-current exact primary SHA. No staging deploy occurs unless every release gate passes and the existing conditional deployment authority applies.

## Current evidence snapshot

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

**Single requested administrator action:** provide a one-use, disposable MySQL 8.4.11 restore target inside the existing staging security boundary, together with the supported internal restore operation, isolation attestation, named operator/Owner access, expiry and cleanup commitment. This is preferred over transferring confidential DB data to WSL. If such a target is not already supportable, the administrator should instead provide the official encrypted-transfer channel and operation reference; do not substitute another transport. Resume only after one approved path is evidenced.
