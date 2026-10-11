# M9 Owner Infrastructure Handoff

No secrets are included in this handoff. Do not send credential values through chat or reports.

## Required before M9 closure

### 1. Dedicated platform S3 destination

Owner/operator actions:

1. Create a dedicated private platform-backup bucket at the approved existing provider, distinct from the customer backup bucket.
2. Create a separate access key restricted to the dedicated bucket and required object operations; do not reuse customer-backup credentials.
3. Decide whether provider versioning, deletion restrictions/Object Lock, and replication are required and approve any material cost or retention implications.
4. Inject endpoint, region, bucket and credentials through the approved secret/configuration mechanism; never commit them or return them in Owner status pages.
5. After reviewed release and guarded staging deployment, validate bucket identity, upload, object size/hash, readback integrity, and isolated decrypt/import. A separate bucket in the same account/provider does not protect from provider/account compromise or provider-wide outage.

The implementation fails closed for deployed identities if platform backup points to local-only storage. No bucket was provisioned or contacted during this task.

### 2. APP_KEY escrow

Recommended method: the Owner's independent enterprise password/secrets manager vault with restricted break-glass access and an audited recovery procedure. Keep the existing key value out of source control, tickets, logs, this report and chat.

Procedure:

1. An authorized operator stores the existing APP_KEY directly from the approved secret source into the independent vault.
2. A second authorized person verifies access policy and recovery instructions without copying the value into an artifact or displaying it in a report.
3. Record only non-secret attestation metadata: vault item reference, verification date, approvers, and successful access/retrieval confirmation.
4. In a disposable recovery environment, validate that the escrowed key is available to the recovery process; do not rotate the live key during this task.

Losing APP_KEY can render Laravel-encrypted integration credentials and other encrypted application values unrecoverable. APP_KEY escrow and retrieval attestation remain mandatory closure gates and were not performed here.

### 3. Validation and guarded staging release

After the infrastructure actions above, run the normal remote CI workflow and require all required jobs green. Then use the existing guarded staging release process: verify strict SSH host identity, clean source baseline, pre-deploy backup and target SHA; deploy; confirm migrations/health/worker state; run one harmless platform backup to the dedicated S3 destination; verify remote readback and disposable import; leave M8 restore gates unchanged. Do not exercise production recovery.

## Optional, not a primary M9 closure blocker

Google Drive remains optional secondary storage. No OAuth client, consent-screen activation or Google API credentials are configured. If the Owner wants it later: register the approved OAuth application; review the least-privilege scope and consent-screen verification requirements; store client/refresh credentials in the approved secret store; authorize an Owner-controlled Drive account; run mocked contract tests plus a separately approved real upload/readback/revoke test. Google Drive must not replace S3 as primary and cannot be represented as provider-immutable without evidence.

## Current status

- S3 destination code and local tests: present; real bucket and credential: absent/unverified.
- APP_KEY escrow: absent/unverified.
- MySQL 8.4 disposable local import drill: passed (1 test, 5 assertions).
- Remote CI: not run.
- Staging/production deployment or provider action: none.
- M8 evidence and restore gates: unchanged.
