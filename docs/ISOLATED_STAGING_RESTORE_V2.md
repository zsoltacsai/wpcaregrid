# Isolated staging restore v2

This versioned tool is a source implementation only. Its presence, local tests,
or merge do not authorize a host operation. At the time this guide was written,
the currently observed backup has not been approved or hash-verified and no
isolated destination has been approved. **Execution remains HOLD.** Do not
create a manifest from the historical or loose backup observations.

The historical `isolated-staging-restore.py` and its pinned `inspect` path are
unchanged. A historical inspection receipt is not a v2 recovery receipt and is
not release-bound evidence.

Authenticated capture records, chained restore receipts and migration
rehearsal receipts built on this contract are described in
[AUTHENTICATED_RESTORE_GO14.md](AUTHENTICATED_RESTORE_GO14.md).

## Bounded input sizes

The v2 tool currently permits at most **32 MiB of compressed backup bytes** and
at most **32 MiB of decompressed SQL bytes**. These are independent limits.
The compressed file is fully read and SHA-256 checked before gzip processing;
decompression is output-bounded and rejects trailing data. No limits should be
raised until the exact approved backup size and compression ratio are known,
resource use is bounded, regression coverage is added, and independent review
passes.

## Identity model

The protected manifest binds two distinct application identities:

- `release.application_sha` is the exact target candidate whose release
  artifact is being qualified.
- `backup.source_application_sha` is the exact application SHA deployed when
  the source database backup was captured.
- `source_identity.application_sha` must equal
  `backup.source_application_sha` or validation refuses with
  `SOURCE_BACKUP_BINDING_MISMATCH`.

The source and target SHAs may differ during an upgrade rehearsal. Neither is
inferred from the other. The release artifact SHA, base SHA, backup identifier,
backup SHA-256, byte length, source host/database/server UUID, destination
policy hash, destination identity, MySQL identity/settings, expected schema,
trigger and data inventory, and protected configuration fingerprints remain
separately bound by the root-owned manifest and destination policy. The tool
does not discover or certify those facts on behalf of the operator.

The manifest is strict JSON schema version `2`; unknown, missing, or duplicate
keys are refused. Its top-level keys are:

```text
schema_version, manifest_id, owner_authorization_id, release, backup,
source_identity, destination_policy_sha256, target_schema, mysql,
approved_definer, expected, database_charset, database_collation, config,
issued_at, expires_at
```

The nested objects are also exact-key validated by the implementation. In
particular, `backup` requires `id`, absolute `path`, `sha256`, `size_bytes`,
`source_schema`, and `source_application_sha`. Do not copy the illustrative
schema or invent values: create a production manifest only from verified
backup metadata and an explicit Owner recovery authorization.

## Destination policy and protected inputs

The destination policy is a separate, hash-pinned JSON file naming the
environment and host, isolated MySQL service and server UUID, client defaults
file, client binaries, disposable network, and no-public-port isolation
attestation. A root operator must establish those facts through an approved
environment procedure. The current policy contract only accepts a disposable
MySQL container with a dedicated `wpcg_restore_net_<10 hex>` network and no
published ports.

Before invocation, manifest, destination policy, protected application
configuration, and MySQL client defaults must each be reviewed and installed
through the approved root-controlled path. Manifest and destination policy
are root-owned mode `0600`; the source archive is root-owned mode `0600` and
opened without following symlinks. The app configuration is checked against
its protected identity and APP_KEY fingerprint without printing its value.
The restore receipt and pending journal are written atomically and bind the
manifest hash, destination-policy hash, target schema, and server identity.

The destination must be empty for the generated target schema and different
from the source environment, host, and MySQL server UUID. Restore refuses an
existing target schema. It never restores into the running application
database, never changes global MySQL settings, and never grants privileges.
The approved DEFINER must be an exact, non-reserved identity, with the
manifest's minimal grant inventory checked before and after import.

## Commands

The root-controlled v2 entry point accepts `restore` and `cleanup`. The legacy
`inspect` action does **not** inspect a v2 manifest. Do not run these examples
until the exact backup, protected inputs, destination, and Owner-authorized
scope have all passed review:

```sh
sudo /usr/bin/python3 /root/isolated-staging-restore-v2.py restore \
  --release-manifest /root/wpcaregrid-recovery/release-manifest.json \
  --destination-policy /root/wpcaregrid-recovery/destination-policy.json \
  --config-path /etc/wpcaregrid/shared/.env \
  --receipt /root/wpcaregrid-recovery/restore-receipt.json
```

The restore command emits one compact machine-readable receipt. Preserve its
exact bytes and SHA-256 with the approved recovery record. A PASS means only
that the recorded isolated restore assertions passed; it does not authorize
DBA migrations or staging deployment. Target migrations belong on a separately
restored disposable clone and require their own controller/DBA gates.

Cleanup is a separate operation and requires the still-valid manifest, policy,
receipt, and bound journal. Preserve and independently review all evidence
before cleanup. Expired or conflicting evidence fails closed; do not work
around it by editing a manifest or deleting journal files. Use only a reviewed
cleanup authorization and procedure.

```sh
sudo /usr/bin/python3 /root/isolated-staging-restore-v2.py cleanup \
  --release-manifest /root/wpcaregrid-recovery/release-manifest.json \
  --destination-policy /root/wpcaregrid-recovery/destination-policy.json \
  --receipt /root/wpcaregrid-recovery/restore-receipt.json
```

Any refusal, uncertain result, process interruption, or identity mismatch is a
STOP. Preserve the journal and destination for review; do not retry into a new
or existing schema without a reviewed recovery procedure. No production
database operation is authorized by this tool or this document.
