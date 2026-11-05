# Release Environment Contract

Status: contract specification only. It does not contain a verified host inventory and does not authorize host access or changes.

## Purpose

Record the non-secret runtime facts needed to decide whether one immutable release artifact is compatible with staging and the intended production environment. A successful application CI run is not evidence that either host matches this contract. The production profile stays `UNKNOWN` until an independently authorized, read-only collection produces evidence for it.

## Evidence envelope

Each environment snapshot is a versioned JSON document with these top-level fields:

| Field | Requirement |
| --- | --- |
| `schema_version` | Exact contract version, currently `1`. Unknown versions fail closed. |
| `environment` | `staging` or `production`; never inferred from a hostname. |
| `release_sha` | Full 40-character application commit SHA being assessed. |
| `collector_sha` | Full SHA of the reviewed read-only collector. |
| `collected_at_utc` | UTC timestamp from the collector. |
| `overall` | `VERIFIED`, `BLOCKED`, or `UNKNOWN`. |
| `facts` | Fixed allowlist below; no arbitrary keys or raw command output. |

Each fact has `status` (`VERIFIED`, `UNKNOWN`, `NOT_APPLICABLE`, or `MISMATCH`). Verified and mismatched facts carry only a typed, normalized value and an `evidence_sha256` computed over the canonical JSON encoding of that normalized value plus the allowlisted fact identifier. The digest must never be computed over raw command output. Unknown facts carry an allowlisted reason code and no value or evidence digest. `NOT_APPLICABLE` is allowed only for schema-designated optional facts. Raw evidence is not included in the snapshot, CI artifacts, or repository. A missing value is `UNKNOWN`, never an implicit pass.

## Required fact groups

The collector and validator must use a fixed schema. Values are normalized identifiers or enums, not copied configuration:

1. **Platform:** OS family/version, architecture, systemd version, kernel family.
2. **Laravel runtime:** PHP CLI and FPM versions, active profile, required extension presence, runtime selector state, and effective FPM socket identity/mode.
3. **Database:** engine/version, server-instance fingerprint, binary logging state/format and the relevant trigger-creation setting; no credentials, database rows, SQL, or connection strings.
4. **Web routing:** Nginx version/test status, staging/production vhost classification, PHP handler profile, and selector contract result; no raw Nginx configuration.
5. **Background execution:** Supervisor version, each allowlisted program's normalized state and observed status/PID exit codes, scheduler lock state, persistent worker/scheduler fence states, and consumer-census result. Do not infer `STOPPED` from `FATAL` or an inaccessible query.
6. **Filesystem/deployment:** application checkout SHA and cleanliness, deployment layout profile, allowlisted artifact SHA-256 values, and normalized ownership/mode/symlink facts for required paths. Do not include arbitrary paths or file contents.
7. **Recovery:** latest protected backup identity/digest/integrity result, restore-test evidence identity/result, rollback baseline SHA, migration-ledger summary, and any required DBA attestation state. No backup contents or customer data.

The v1 validator allowlist is explicit in `scripts/release_environment_contract.py` (`FACT_TYPES`). Its identifiers are grouped as follows: host identity fingerprint; platform OS, architecture, kernel and optional systemd version; PHP CLI/FPM versions, profile, required-extension set, selector state and normalized socket metadata; database engine/version/instance fingerprint/binlog facts and a coarse privilege profile; Nginx version/config-test/PHP-handler facts; Supervisor version and each worker's state/status exit code/PID/PID exit code plus consumer-census result; scheduler mechanism/lock/pause/worker-fence facts; application SHA/cleanliness/layout/config-cache facts; normalized metadata for the application, safety markers, Nginx vhost, PHP selector, Supervisor config, scheduler wrapper, recovery bundle and backup; helper/controller/verifier hashes; and backup digest/integrity, isolated-restore result, rollback SHA, pending migration count and DBA-attestation phase. Filesystem facts contain only normalized owner/group classes, mode and object type; never a path or file content.

The validator records Supervisor return codes verbatim as bounded integers. It rejects internally contradictory worker observations such as `FATAL` with a nonzero PID, `STOPPED` with a nonzero PID, or `RUNNING` with PID zero. It does not infer one Supervisor exit-code convention for all versions; compatibility comparison checks the observed codes against the release's declared contract.

Every required fact must be present exactly once. Unknown, duplicate, extra, malformed, oversized, or unrecognized input is rejected with a fixed code; secret-like or raw values are rejected rather than echoed. Snapshot status is derived, never trusted from the input: any mismatch makes it `BLOCKED`; otherwise any unknown required fact makes it `UNKNOWN`; only all verified required facts (plus permitted N/A optional facts) produce `VERIFIED`. `NOT_APPLICABLE` must include a reason code from an allowlist.

## Collection boundary

- Collection is one bounded, read-only operation against a specifically approved environment and exact release SHA.
- The collector uses fixed commands and fixed fields; it accepts no caller-provided command, path, shell fragment, or environment name beyond the enumerated values.
- It runs with the least privilege already approved for each read. If an observation needs unavailable privilege, emit `UNKNOWN` and stop dependent checks; do not add sudo rights or switch to another access path.
- Timeouts, command errors, changed host identity, unstable files, failed redaction, or output-limit violations produce `BLOCKED` with an allowlisted reason code.
- Only the normalized envelope and digests of normalized allowlisted facts may be retained. Raw command output is processed in bounded memory and discarded after successful validation.
- Snapshot generation does not install tools, change host state, restart services, inspect secrets, read application data, or mutate a database.

## Release comparison

For a candidate release, compare its immutable artifact manifest and declared compatibility contract against both environment snapshots. Each required comparison is `PASS`, `MISMATCH`, or `UNKNOWN`. Any mismatch or unknown required fact blocks promotion. Staging success does not convert an unknown production profile into a pass. A release can be promoted only when the same artifact digest is traceable from CI to staging acceptance and separately approved production readiness.

The validator must report all independent safe-to-evaluate mismatches in one sanitized result. It must stop immediately if environment identity or evidence integrity is ambiguous. It must not infer host compatibility from a version string alone where the release depends on configuration, permissions, extensions, database settings, worker behavior, or recovery evidence.

## Current evidence status

This specification itself is not an inventory. At the time this file is introduced, production baseline evidence is `UNKNOWN`. Staging values must be populated only from a fresh, authorized, read-only collection and linked to its exact collector SHA and evidence digests. Previously observed staging values are historical until re-collected.

## Acceptance for an implementation

- Schema validation rejects unknown fields, missing facts, duplicate facts, malformed hashes, unrecognized status values, oversized input, and raw/secret-bearing values.
- A production snapshot with unknown required facts remains `UNKNOWN`/blocked; it cannot inherit staging values.
- A verified staging snapshot with one incompatible runtime or database fact reports that mismatch without exposing source output.
- A missing or failed privileged read is reported as `UNKNOWN`, never converted to a success value.
- Evidence digests are reproducible from canonical normalized values; they are not hashes of raw command output.
- A release comparison reports `PASS`, `MISMATCH`, or `UNKNOWN` for each required compatibility fact, in stable order, without mutating the snapshot or application state.
- Tests use synthetic snapshots only. Host collection remains separately authorized and read-only.
