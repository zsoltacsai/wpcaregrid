# M15 Host Isolation Protocol Contract v1 — Draft for Independent Review

- **Status:** Documentation-only protocol contract proposal; requires independent M17 AppSec review, Integration & QA review, and separate Owner approval before any implementation.
- **Review base:** `claude/ecstatic-wozniak-oemyuf` at primary HEAD `74f5b81e0d4e5589d255be4ba16b1b37ee987a98` (after PR #192 merge). The #190 architecture basis remains separately identified below.
- **Architecture basis:** [M15 ADR #190](https://github.com/zsoltacsai/wpcaregrid/pull/190), approved at architecture level only; final independent M17 review [comment 6413144187](https://github.com/zsoltacsai/wpcaregrid/pull/190#issuecomment-6413144187) leaves exact contract, retention, clock/key, crash and conformance gates mandatory.
- **Fail-closed contract:** [M15 RestoreIsolationGate contract](m15-restore-isolation-gate-contract.md), merged with #182.
- **Tracking:** [#189](https://github.com/zsoltacsai/wpcaregrid/issues/189); integration ledger #174.
- **Scope boundary:** This document defines proposed versioned messages, typed interfaces, state, cryptography and conformance requirements. It does not implement a provider, broker, HMR, Connector change, allowlist, deployment, restore or host operation.

## 1. Purpose, status and normative language

This contract turns the accepted host-isolation architecture into a reviewable wire and interface specification. It covers Laravel, the WordPress Connector, the per-site unprivileged agent, the root broker, the one-shot restore worker, and the independent Host Management Relay (HMR).

The protocol is **proposed**. Its message shapes are concrete contract candidates, not deployed behavior. Implementation may not silently substitute fields, accept unknown fields, accept an older ACK schema, or infer authorization from transport identity.

The words **MUST**, **MUST NOT**, **SHOULD**, and **MAY** express requirements for a future implementation. An omitted/invalid/ambiguous authorization or state record is a denial. Existing #182 behavior remains authoritative until a separately reviewed contract implementation replaces it: absent/unreviewed provider means `RESTORE_ISOLATION_UNAVAILABLE` before maintenance activation or live mutation; files-only, database, full, recovery, and compensating live changes remain gated. A readiness capability or synthetic provider does not establish host compatibility.

### 1.1 Core invariants

1. Restore and recovery are separate authorization classes. A `RECOVERY_REQUIRED` receipt is evidence and a fence state, never permission to mutate.
2. One site has at most one active or unresolved restore/recovery work chain. Exact duplicate delivery resolves to the same durable work; conflicting duplicates are rejected.
3. The broker owns the authoritative host gate, private generation, writer fence, host replay journal, accepted-work mapping, and terminal receipt outbox.
4. Broker-private generation values MUST NOT appear in Laravel state, Connector/agent/HMR messages, signed platform or host payloads, client-visible output, or logs.
5. Gate release follows a valid outcome transition and verified host state, never a TTL, process death, ACK, Connector assertion alone, or HMR message.
6. ACK v1 is unsupported. The implementation MUST accept only ACK v2; there is no fallback, downgrade, dual-accept, or automatic translation path.
7. Host receipts are retained until a corresponding valid ACK v2 has been durably verified and recorded. Failed, absent, late, stale, duplicate-conflicting, ambiguous, or lost ACKs cannot prune a receipt.
8. Every outcome is a strict discriminated union. Generic outcome-dependent `intent_id`, `backup_uuid`, or `scope` fields are forbidden in common receipt/ACK objects.
9. If state is missing, corrupt, stale, ambiguous, or from an unknown schema, the site stays fenced and no destructive retry starts.
10. No arbitrary command, shell, SQL, URL, host path, unit name, environment, or caller-selected executable is accepted through any interface.

## 2. Authority and trust boundaries

| Principal | Authoritative state / authority | Explicitly not trusted for |
|---|---|---|
| Laravel | Customer/tenant/site authorization; Connector/site/provider registration; immutable backup/manifest facts; signed intents; durable recovery authorization; platform restore/job state; receipt verification and idempotent state reconciliation; ACK issuance | Host writer quiescence, current gate ownership, private broker generation, host success claims not supported by a verified receipt |
| Connector PHP | Authenticated platform polling; bounded validation/transfer; durable handoff/outbox marker; normal-path receipt and ACK relay after safe site reopening | Host authorization based on Unix UID; live restore mutation in the provider path; gate release; recovery authorization; broker-private generation |
| Per-site agent | Bounded site-local staging/status bridge to fixed broker IPC | Platform authorization, generation, recovery approval, arbitrary cross-site operations |
| Root broker | Host policy and site mapping; gate/generation journal; accepted work/replay state; writer/ingress fence; worker launch/observation; host-signed receipts and durable outbox | Customer/business approval unless represented by a valid signed Laravel intent; generic commands from any caller |
| One-shot worker | Apply one already accepted immutable work item in the isolated site sandbox; emit bounded evidence | Gate changes, release, receipt signing, arbitrary filesystem/network/subprocess access |
| HMR | Authenticated, host-scoped transport of opaque signed protocol envelopes through fixed IPC while the site is fenced | Intent/receipt authorization, payload alteration, signing for Laravel/broker, generation lookup, unlock, pruning or mutation |
| Provider console operator | Separately approved, audited use of fixed broker interfaces for manual fallback | Ad hoc shell, SQL, marker edits, generic root RPC, bypassing signatures or durable state |

Unix peer credentials and mTLS identify a transport principal only. They never replace end-to-end signature, exact site/work binding, replay checks, or broker policy.

## 3. Wire encoding and signed-envelope rules

### 3.1 JSON and exact bytes

All protocol JSON is UTF-8. Parsers MUST reject invalid UTF-8, duplicate object keys, non-finite numbers, unknown fields, invalid enum values, trailing data, and values outside the schema. JSON Canonicalization Scheme (JCS, RFC 8785) is used for canonical payload bytes. Identifiers, enum strings, key IDs, digests, and nonce text are restricted to their schema ASCII forms; free-form Unicode text is not accepted in authorization messages.

All UUIDs use lowercase canonical hyphenated UUID text. Digests use lowercase 64-character hexadecimal SHA-256. Base64url values are unpadded, canonical, and reject alternate encodings. Protocol timestamps are RFC 3339 UTC with `Z`, whole-second precision for signed messages; parsing and reserialization MUST preserve the same value.

A signed message is a canonical JSON outer envelope with exactly these fields:

    {
      "schema": "wp-caregrid.signed-message.v1",
      "payload": {},
      "signature": {
        "alg": "Ed25519",
        "key_id": "registered-key-id",
        "value": "canonical-unpadded-base64url-64-byte-signature"
      }
    }

The nested payload has its own schema identifier. The envelope is serialized as JCS with no BOM, no leading/trailing bytes, and no newline. The signature is Ed25519 over:

    ASCII(domain_separator) || 0x00 || UTF8(JCS(payload))

The `key_id` in the signature object MUST exactly equal the payload's `key_id` where that payload defines one. For Terminal Receipt v2, `signature.key_id MUST equal payload.host_key_id` byte-for-byte; this is an additional mandatory field-name-specific rule, not an exception to key lookup. It is not itself a substitute for issuer, host/provider, signing-purpose, key-state or validity checks. A signed-envelope digest is SHA-256 over the exact canonical serialized outer-envelope bytes, including the signature object. Receivers reject noncanonical envelope bytes rather than normalizing before digest verification. Every signed schema version is closed: unknown fields are rejected.

### 3.2 Shared identifier types

| Type | Validation |
|---|---|
| `uuid` | Lowercase canonical UUID; correct entity type and uniqueness checked in durable state |
| `sha256` | Exactly 64 lowercase hex characters |
| `nonce` | Canonical unpadded base64url encoding of exactly 32 random bytes |
| `scope` | Exactly `files`, `database`, or `full`; not a path/pattern |
| `timestamp` | UTC RFC3339 `YYYY-MM-DDTHH:MM:SSZ`; time-window policy in §8 |
| `identity` | Bounded registered opaque ID; resolved through durable platform/provider registration, never accepted as a host path |
| `key_id` | Bounded registered key identifier; immutable mapping to algorithm, purpose, owner and validity state |

## 4. Signed Restore Intent v1

Schema: `wp-caregrid.restore-intent.v1`. Domain: `WPCAREGRID-RESTORE-INTENT-V1`.

Laravel creates and signs the payload only after customer/site/Connector authorization, backup ownership and immutable manifest validation, scope validation, and existing pre-restore policy checks. The broker verifies signature, key purpose, issuer/audience, exact site mapping, active Connector, backup/manifest/scope, time bounds, nonce and one-work-per-site policy before acceptance.

| Field | Type / requirement | Authoritative source | Creator → verifier | Binding |
|---|---|---|---|---|
| `schema` | Literal schema string; required | Protocol registry | Laravel → broker | Selects this closed schema |
| `iss` | Literal `wpcaregrid-platform`; required | Platform configuration | Laravel → broker | Signing principal |
| `aud` | Registered provider installation ID; required | Registered provider | Laravel → broker | Exact intended installation |
| `provider_id` | Registered bounded ID; required | Reviewed provider registration | Laravel → broker | Exact provider profile |
| `host_id` | Registered host ID; required | Host registration | Laravel → broker | Exact physical/logical host mapping |
| `tenant_id` | Immutable tenant ID; required | Durable website ownership | Laravel → broker | Tenant owning site and restore |
| `site_id` | Immutable website ID; required | Durable website record | Laravel → broker | One exact WordPress site |
| `connector_id` | Active Connector ID; required | Active Connector registration | Laravel → broker | Current pairing, not stale/revoked Connector |
| `restore_uuid` | UUID; required | Durable restore request | Laravel → broker | One original restore |
| `job_uuid` | UUID; required | Durable restore job | Laravel → broker | Exact job for the restore |
| `backup_uuid` | UUID; required | Durable backup record | Laravel → broker | Exact immutable input backup |
| `manifest_sha256` | SHA-256; required | Verified immutable backup manifest | Laravel → broker | Exact content manifest |
| `scope` | Enum; required | Authorized restore request | Laravel → broker | Exact requested operation scope |
| `intent_id` | UUID; required | Laravel intent issuance record | Laravel → broker | Unique authorization instance |
| `nonce` | 32 random bytes; required | Laravel CSPRNG | Laravel → broker | Single-use replay key |
| `issued_at`, `expires_at` | UTC timestamps; required | Platform clock | Laravel → broker | Bounded first acceptance |
| `key_id` | Restore-intent key ID; required | Platform key registry | Laravel → broker | Restore-intent signing purpose |

The signed intent does not contain a host generation, lease secret, host path, executable, SQL, URL, or operator-supplied command. Intent lifetime is at most 120 seconds from issue time. Broker acceptance reserves intent ID and nonce durably before it returns Host Work Acceptance. A byte-identical retransmission returns the same acceptance/work; same ID or nonce with different payload is rejected. After acceptance, expiry cannot cause a second work item or automatic gate release.

## 5. Signed Recovery Intent v1

Schema: `wp-caregrid.restore-recovery-intent.v1`. Domain: `WPCAREGRID-RESTORE-RECOVERY-INTENT-V1`. It uses a distinct platform key purpose and is never accepted under Restore Intent keys.

Laravel issues it only after a separate, durable recovery approval. The approval record MUST already contain the exact proposed tuple and be committed before the signed envelope is delivered. Laravel computes the SHA-256 digest of the exact canonical signed-envelope bytes and persists it in that same authorization record. The broker independently validates that digest against the exact received envelope and its durable triggering work/receipt.

| Field | Type / requirement | Authoritative source | Creator → verifier | Binding |
|---|---|---|---|---|
| `schema`, `iss` | Literal recovery schema and platform issuer; required | Protocol registry | Laravel → broker | Recovery protocol and signer |
| `aud`, `provider_id`, `host_id` | Exact registered IDs; required | Provider/host registration | Laravel → broker | One host installation |
| `tenant_id`, `site_id`, `connector_id` | Exact durable identities; required | Laravel ownership and active Connector records | Laravel → broker | Same tenant/site/current Connector as parent restore |
| `recovery_trigger_receipt_id` | UUID; required | Verified durable RECOVERY_REQUIRED receipt | Laravel → broker | Exact event that makes recovery eligible |
| `recovery_trigger_receipt_sha256` | SHA-256; required | Exact signed trigger-envelope bytes | Laravel → broker | Prevents receipt substitution |
| `original_restore_intent_id` | UUID; required | Original restore authorization | Laravel → broker | Parent restore |
| `recovery_intent_id` | New UUID; required | Durable recovery authorization record | Laravel → broker | This separately approved attempt |
| `original_restore_uuid`, `original_job_uuid` | UUIDs; required | Original restore/job records | Laravel → broker | Parent restore and job |
| `safety_backup_uuid` | UUID; required | Approved immutable safety-backup record | Laravel → broker | Exact recovery source |
| `safety_manifest_sha256` | SHA-256; required | Verified backup manifest | Laravel → broker | Exact bytes and object set |
| `recovery_scope` | Enum; required | Durable recovery approval | Laravel → broker | Exact authorized replacement scope |
| `nonce` | 32 random bytes; required | Laravel CSPRNG | Laravel → broker | Single-use recovery authorization |
| `issued_at`, `expires_at` | UTC timestamps; required | Platform clock | Laravel → broker | Bounded first acceptance |
| `key_id` | Recovery-only key ID; required | Platform recovery key registry | Laravel → broker | Recovery signing purpose |

The payload MUST NOT contain `broker_generation`. The broker maps the validated receipt/work tuple to its private generation record. It accepts only when the trigger receipt is the current durable RECOVERY_REQUIRED state, the recovery approval is unique, no conflicting/ambiguous recovery work exists, and every site/host/backup/manifest/scope identity matches. It assigns exactly one `recovery_work_id`, persists the mapping and replay reservation atomically, then returns a signed Host Work Acceptance. Identical retry returns that same acceptance. A later recovery attempt requires a new approval, nonce and intent bound to the then-current RECOVERY_REQUIRED receipt.

## 6. Host Work Acceptance v1

Schema: `wp-caregrid.host-work-acceptance.v1`. Host/broker Ed25519 domain: `WPCAREGRID-HOST-WORK-ACCEPTANCE-V1`. The broker signs only after the exact work, replay reservation and authorization binding are durable. The response is not evidence that restore execution succeeded or that the gate is open.

Common payload:

    {
      "schema": "wp-caregrid.host-work-acceptance.v1",
      "iss": "registered-host-broker",
      "aud": "wpcaregrid-platform",
      "acceptance_id": "uuid",
      "work_kind": "RESTORE|RECOVERY",
      "work_id": "uuid",
      "tenant_id": "immutable-tenant-id",
      "site_id": "immutable-website-id",
      "connector_id": "active-connector-id",
      "provider_id": "reviewed-provider-id",
      "host_id": "registered-host-id",
      "restore_uuid": "original restore UUID",
      "job_uuid": "original restore job UUID",
      "authorization": {},
      "accepted_at": "UTC RFC3339 timestamp",
      "key_id": "registered-host-signing-key-id"
    }

The `authorization` object is a strict union:

- `RESTORE`: `schema=wp-caregrid.restore-work-binding.v1`, `restore_intent_id`, `restore_intent_envelope_sha256`, `backup_uuid`, `manifest_sha256`, `scope`.
- `RECOVERY`: `schema=wp-caregrid.recovery-work-binding.v1`, `original_restore_intent_id`, `recovery_trigger_receipt_id`, `recovery_trigger_receipt_sha256`, `recovery_intent_id`, `recovery_intent_envelope_sha256`, `recovery_work_id`, `safety_backup_uuid`, `safety_manifest_sha256`, `recovery_scope`.

The recovery `work_id` equals `recovery_work_id`; the restore `work_id` equals the accepted restore work ID. Every identity tuple equals the signed intent and broker registration. The broker produces and signs acceptance. Laravel verifies its host signature and exact authorization/work tuple before recording it. Connector and HMR transport the acceptance bytes unchanged; they cannot use it to authorize state changes. The per-site agent may verify the signature for local handoff correlation but still defers authorization to the broker. It contains no generation, lease, path, command, or secret.

The broker MUST persist one stable signed response per exact accepted intent digest. Exact retries return byte-identical acceptance; conflicting intent ID/digest returns a typed denial. An acceptance ID is a correlation identifier, not a bearer capability.

### 6.1 Platform Work Acceptance Confirmation v1

Schema: `wp-caregrid.platform-work-acceptance-confirmation.v1`. Laravel signs with a key whose sole purpose is `PLATFORM_WORK_ACCEPTANCE_CONFIRMATION`, domain `WPCAREGRID-WORK-ACCEPTANCE-CONFIRMATION-V1`. This message confirms only that Laravel durably recorded one exact broker HWA. It is not a Receipt ACK v2, terminal outcome, authorization to mutate, gate-release signal, or capability.

The strict common payload is:
```json
{
  "schema": "wp-caregrid.platform-work-acceptance-confirmation.v1",
  "iss": "wpcaregrid-platform",
  "aud": "registered-host-broker",
  "confirmation_id": "stable UUID",
  "acceptance_id": "broker HWA acceptance UUID",
  "acceptance_sha256": "SHA-256 of exact canonical signed HWA envelope bytes",
  "work_kind": "RESTORE|RECOVERY",
  "work_id": "exact broker work UUID",
  "tenant_id": "immutable tenant ID",
  "site_id": "immutable website ID",
  "connector_id": "active Connector ID",
  "provider_id": "registered provider ID",
  "host_id": "registered host ID",
  "restore_uuid": "original restore UUID",
  "job_uuid": "original restore job UUID",
  "authorization": {},
  "confirmed_at": "UTC RFC3339 timestamp",
  "key_id": "registered platform confirmation-signing key ID"
}
```

`authorization` is a closed union matching the HWA:
- `RESTORE`: `schema=wp-caregrid.restore-work-binding.v1`, `restore_intent_id`, `restore_intent_envelope_sha256`, `backup_uuid`, `manifest_sha256`, `scope`.
- `RECOVERY`: `schema=wp-caregrid.recovery-work-binding.v1`, `original_restore_intent_id`, `recovery_trigger_receipt_id`, `recovery_trigger_receipt_sha256`, `recovery_intent_id`, `recovery_intent_envelope_sha256`, `recovery_work_id`, `safety_backup_uuid`, `safety_manifest_sha256`, `recovery_scope`.

Laravel validates the unique acceptance row under a transaction, allocates/stabilizes confirmation_id and confirmed_at, signs the exact confirmation with an ACTIVE_SIGN_VERIFY key, and atomically commits the accepted HWA mapping plus exact signed confirmation bytes/digest and an outstanding-confirmation inventory row to durable storage before exposing or returning any PWAC bytes. The inventory row and rotation snapshot rules are normative in §12.2; the PWAC itself is not its own proof of pre-cutoff eligibility. A crash before this transaction commits leaves no acceptance/PWAC visible; a crash after commit returns the same stored bytes on retry. It copies fields from the verified HWA and durable authorization; it MUST NOT include broker generation. One confirmation ID and byte-identical signed envelope are stored per exact HWA digest. The broker verifies the platform signature, key purpose/status, exact HWA digest, acceptance/work IDs, strict authorization union and registered identity tuple against its one private durable mapping. New signatures are never created with verify-only keys. For `work_kind=RESTORE`, it durably records the confirmation before changing that restore work from `ACCEPTANCE_PENDING_PLATFORM` to `ACCEPTED`. For `work_kind=RECOVERY`, the recovery child also begins in `ACCEPTANCE_PENDING_PLATFORM`; durable confirmation changes only that child to `RECOVERY_AUTHORIZED`, while its original parent remains `RECOVERY_REQUIRED` and fenced. Duplicate exact submission is idempotent; any changed/missing/ambiguous mapping is rejected and leaves work unactivated and the site gate unchanged. The confirmation never releases a gate.

## 7. Terminal Receipt v2

Schema: `wp-caregrid.restore-terminal.v2`. Host Terminal Receipt key-purpose domain: `WPCAREGRID-RESTORE-TERMINAL-V2`. The broker creates it only after verifying a work whose exact HWA and platform Work Acceptance Confirmation are durably bound, plus private generation, worker identity/exit, durable mutation state, exact input manifest, result evidence and the outcome invariant. It persists/fsyncs the complete signed envelope to its outbox before any eligible gate release. The worker cannot sign, release, or select an outcome.

### 7.1 Common envelope

    {
      "schema": "wp-caregrid.restore-terminal.v2",
      "issuer": "registered-host-broker",
      "audience": "wpcaregrid-platform",
      "receipt_id": "unique UUID",
      "tenant_id": "immutable-tenant-id",
      "site_id": "immutable-website-id",
      "connector_id": "active-connector-id",
      "provider_id": "reviewed-provider-id",
      "host_id": "registered-host-id",
      "restore_uuid": "original restore UUID",
      "job_uuid": "original restore job UUID",
      "outcome": "RESTORE_SUCCESS|SAFE_PRE_MUTATION_ABORT|RECOVERY_REQUIRED|RECOVERY_SUCCESS",
      "result": {},
      "evidence_sha256": "64-lowercase-hex",
      "finished_at": "UTC RFC3339 timestamp",
      "host_key_id": "registered host Terminal Receipt signing-key ID; exactly equal to outer signature.key_id"
    }

There is no common `intent_id`, `backup_uuid`, `manifest_sha256`, `scope`, `mutation_started`, or `generation`. The common restore/job IDs always identify the original restore/job. For every Terminal Receipt v2, the outer signed-envelope `signature.key_id` MUST equal `payload.host_key_id` byte-for-byte; the payload value is the registered host Terminal Receipt signing-key ID and is covered by the signature. Unknown/missing/cross-outcome fields reject the entire receipt.

### 7.2 Outcome result schemas

- **RESTORE_SUCCESS — `wp-caregrid.restore-success.v1`**: required `restore_intent_id`, `restore_work_id`, `backup_uuid`, `manifest_sha256`, `restore_scope`, `restore_mutation_started=true`. These refer to the original authorized restore.
- **SAFE_PRE_MUTATION_ABORT — `wp-caregrid.restore-safe-abort.v1`**: required `restore_intent_id`, `restore_work_id`, `backup_uuid`, `manifest_sha256`, `restore_scope`, `restore_mutation_started=false`, `no_mutation_proof_sha256`. It applies only to original restore work and requires durable proof that no mutation-start record or write exists. A recovery attempt cannot use this outcome to clear its parent RECOVERY_REQUIRED fence.
- **RECOVERY_REQUIRED — `wp-caregrid.restore-recovery-required.v1`**: required `failure_origin=ORIGINAL_RESTORE|RECOVERY_ATTEMPT`, `original_restore_intent_id`, `original_restore_work_id`, `original_backup_uuid`, `original_manifest_sha256`, `original_restore_scope`, `overall_mutation_state=STARTED|AMBIGUOUS`, `failure_stage`. If `failure_origin=RECOVERY_ATTEMPT`, a required `failed_recovery` object with schema `wp-caregrid.restore-failed-recovery.v1` contains `recovery_trigger_receipt_id`, `recovery_trigger_receipt_sha256`, `recovery_intent_id`, `recovery_intent_envelope_sha256`, `recovery_work_id`, `safety_backup_uuid`, `safety_manifest_sha256`, `recovery_scope`, `recovery_mutation_state=NOT_STARTED|STARTED|AMBIGUOUS`. If `failure_origin=ORIGINAL_RESTORE`, `failed_recovery` is forbidden. The site stays fenced in either variant. A pre-mutation recovery failure is not SAFE_PRE_MUTATION_ABORT for the original work.
- **RECOVERY_SUCCESS — `wp-caregrid.restore-recovery-success.v1`**: required `original_restore_intent_id`, `original_restore_work_id`, `recovery_trigger_receipt_id`, `recovery_trigger_receipt_sha256`, `recovery_intent_id`, `recovery_intent_envelope_sha256`, `recovery_work_id`, `recovery_scope`, `safety_backup_uuid`, `safety_manifest_sha256`, `recovery_mutation_started=true`. The trigger receipt is the exact RECOVERY_REQUIRED receipt that the accepted recovery authorization names; the recovery intent ID/digest must match Laravel's durable approved authorization and the broker's accepted work; the backup and manifest identify the actual immutable safety backup used.

For all outcomes, tenant/site/Connector/provider/host and original restore/job tuple are immutable across the authorization, receipt, platform reconciliation and ACK. `evidence_sha256` is a digest of the bounded host evidence bundle defined by the implementation; it is not a substitute for the explicit result bindings. No user-supplied output field may override a broker-recorded binding.

### 7.3 Laravel receipt acceptance

Laravel verifies strict canonical envelope encoding; byte-for-byte equality of outer `signature.key_id` and payload `host_key_id`; Ed25519 signature; registered provider/host ownership; `HOST_TERMINAL_RECEIPT` signing purpose; key status/validity and the rotation/outstanding-receipt policy in §12.2; common identity tuple; outcome schema; and exactly one durable platform acceptance/work record matching the HWA, authorization digest and work before any state transition. A signature that verifies under an HWA, ACK, intent, unknown, revoked or compromised key is not a valid receipt. RESTORE_SUCCESS and SAFE_PRE_MUTATION_ABORT require exactly one durable RESTORE acceptance record linked to the original restore/job and exact Restore Intent envelope digest. RECOVERY_SUCCESS requires exactly one durable recovery-authorization record matching recovery intent ID and exact signed-envelope digest, original restore/work and restore/job, trigger receipt ID/digest, tenant/site/Connector/provider/host, safety backup UUID/manifest and scope. It also matches the `recovery_work_id` persisted from the broker acceptance response to the same authorization. A valid host signature alone is insufficient.

RECOVERY_REQUIRED with `failure_origin=RECOVERY_ATTEMPT` must match exactly one accepted recovery-work record, including the nested trigger, intent digest, work ID and backup/scope tuple. A rejected/unaccepted recovery request cannot be represented as a completed failed recovery work item. Mismatched, stale, replayed, duplicate, conflicting, premature (receipt before accepted HWA), or ambiguous receipt causes no successful platform transition and no positive ACK; the exact broker receipt remains durable for retry after acceptance reconciliation.

### 7.4 Gate invariants

| Outcome | Durable platform state | Broker gate action |
|---|---|---|
| RESTORE_SUCCESS | Original restore succeeded after exact HWA/PWAC acceptance mapping and result reconciliation | May reopen only after signed receipt/outbox durability and all host release checks pass |
| SAFE_PRE_MUTATION_ABORT | Original work terminal; exact acceptance mapping plus durable proof no mutation started | May release only the original gate after control reconciliation; cannot retry same work |
| RECOVERY_REQUIRED | Restore or recovery state destructive/ambiguous | MUST remain fenced; receipt is not authorization; no automatic recovery or release |
| RECOVERY_SUCCESS | One separately authorized recovery work completed and reconciled | May reopen only after exact recovery proof and all release checks; ACK is never a gate signal |

## 8. Platform-signed Receipt ACK v2

Schema: `wp-caregrid.terminal-receipt-ack.v2`. Domain: `WPCAREGRID-TERMINAL-RECEIPT-ACK-V2`. ACK v1 and every unknown version are rejected without fallback.

Common payload:

    {
      "schema": "wp-caregrid.terminal-receipt-ack.v2",
      "iss": "wpcaregrid-platform",
      "ack_id": "stable UUID for this receipt/outcome",
      "receipt_id": "host-signed receipt UUID",
      "receipt_sha256": "SHA-256 of exact canonical signed receipt envelope",
      "outcome": "RESTORE_SUCCESS|SAFE_PRE_MUTATION_ABORT|RECOVERY_REQUIRED|RECOVERY_SUCCESS",
      "tenant_id": "immutable-tenant-id",
      "site_id": "immutable-website-id",
      "connector_id": "active-connector-id",
      "provider_id": "reviewed-provider-id",
      "host_id": "registered-host-id",
      "restore_uuid": "original restore UUID",
      "job_uuid": "original restore job UUID",
      "result_binding": {},
      "processed_at": "UTC RFC3339 timestamp",
      "key_id": "registered-platform-ACK-signing-key-id"
    }

`result_binding` is an exact closed union mirroring the signed receipt result:

| `outcome` | Required result binding |
|---|---|
| RESTORE_SUCCESS | `schema=wp-caregrid.restore-success-ack-binding.v2`, plus `restore_intent_id`, `restore_work_id`, `backup_uuid`, `manifest_sha256`, `restore_scope`, `restore_mutation_started=true` |
| SAFE_PRE_MUTATION_ABORT | `schema=wp-caregrid.restore-safe-abort-ack-binding.v2`, same original restore tuple, `restore_mutation_started=false`, and `no_mutation_proof_sha256` |
| RECOVERY_REQUIRED | `schema=wp-caregrid.restore-recovery-required-ack-binding.v2`, `failure_origin`, original restore/work/backup/manifest/scope, `overall_mutation_state`, `failure_stage`; the exact nested `failed_recovery` schema/fields are required only for RECOVERY_ATTEMPT |
| RECOVERY_SUCCESS | `schema=wp-caregrid.restore-recovery-ack-binding.v2`, original restore intent/work, trigger receipt ID/digest, approved Recovery Intent ID/envelope digest, recovery_work_id, recovery_scope, actual safety backup UUID/manifest digest, and `recovery_mutation_started=true` |

Laravel signs only after one durable, idempotent platform state transition has committed. Common values come from the verified host receipt plus durable tenant/site/Connector/provider/host and restore/job records; result binding is copied only after comparison with durable authorization. For RECOVERY_SUCCESS, all recovery fields including broker-returned recovery_work_id are available from the receipt and the same durable Laravel recovery-authorization/work record. No ACK field requires broker generation. `ack_id` is stable for the exact receipt/outcome; retry returns byte-identical ACK bytes.

The broker verifies platform signature/key purpose, ACK v2 schema, exact receipt ID and digest, outcome and all common/result bindings. It resolves exactly one accepted work record and checks its private generation and outbox state. Missing, malformed, duplicate/ambiguous, replayed, wrong-work, wrong-backup/scope, or wrong-generation mapping means no ACK acceptance and no receipt pruning. The broker durably records ACK ID/digest and acknowledged receipt state before any receipt pruning. Receipt bytes MUST NOT be pruned until the exact, correctly bound Terminal Receipt ACK v2 is durably accepted and the separate configured terminal-receipt retention condition is complete; ACK acceptance alone does not waive that retention. Preserve receipt ID/digest/outcome/work replay tombstones for the required audit-retention period. A missing, invalid, stale, or lost ACK retains the receipt. ACK never authorizes gate release.

**Separate HWA retention invariant:** The broker MUST durably commit the exact signed HWA bytes and digest to its outbox before exposing the HWA to Connector/HMR/Laravel. HWA bytes MUST NOT be pruned until the exact matching PWAC is durably confirmed by the broker and the configured HWA retention requirement is complete. Preserve acceptance ID, HWA digest, work/authorization binding and replay tombstone after byte pruning. A PWAC is not a Terminal Receipt ACK v2 and cannot satisfy receipt pruning conditions.

## 9. Host Management Relay envelopes

HMR runs outside the WordPress/PHP-FPM/site cgroups and credentials. It uses a host-scoped outbound mTLS channel to a pinned platform endpoint and a fixed local broker IPC endpoint. It has no public listener, no site DB/filesystem access, and no broker signing key. The client certificate authenticates only the HMR transport identity. This contract defines no HMR application-level signature: the mutually authenticated TLS channel protects transport, while nested Restore/Recovery Intent, Host Work Acceptance, Terminal Receipt and ACK signatures provide end-to-end protocol authenticity. No per-message signature profile negotiation is permitted.

All HMR JSON envelopes use JCS serialization, reject duplicate/unknown fields, and bind to the TLS-authenticated relay/host identity. Nested signed protocol envelopes are carried byte-for-byte, not parsed and reserialized by HMR.

### 9.1 HMR-to-platform poll request

Schema: `wp-caregrid.hmr-poll.v1`. Fixed operation only:

    {
      "schema": "wp-caregrid.hmr-poll.v1",
      "request_id": "uuid",
      "relay_id": "registered HMR ID",
      "provider_id": "registered provider ID",
      "host_id": "registered host ID",
      "channel_sequence": "canonical decimal uint64 string",
      "last_platform_delivery_id": "opaque bounded cursor or null",
      "max_wait_seconds": 20,
      "sent_at": "UTC RFC3339 timestamp"
    }

The API host and path are preconfigured by provider/platform enrollment; none is caller-supplied. TLS certificate identity must map to the same relay/provider/host tuple. Cursor is opaque and cannot select a site/operation. Poll is read-only, bounded, and idempotent by `request_id`.

### 9.2 Platform delivery to HMR

Schema: `wp-caregrid.hmr-delivery.v1`; delivered over the authenticated channel. Its exact required fields are `schema`, `delivery_id`, `request_id`, `relay_id`, `provider_id`, `host_id`, `platform_delivery_sequence` (canonical decimal uint64 string), `kind` (closed enum; currently `SIGNED_RECOVERY_INTENT` only), `payload_sha256`, `payload_b64url` (exact nested signed-envelope bytes), `issued_at`, and `expires_at`. All IDs must match the authenticated relay and registered host; sequence is monotonic; timestamps are UTC RFC3339 and delivery lifetime is bounded by the protocol clock policy. The inner Recovery Intent is separately platform-signed and verified by the broker. An HMR transport delivery is not authorization. An expired, duplicate-conflicting, wrong-host, wrong-site or invalid inner message is rejected; the durable broker replay state is authoritative.

### 9.3 HMR submission to platform

Schema: `wp-caregrid.hmr-submit.v1`. The fixed kind union is `HOST_SIGNED_TERMINAL_RECEIPT` or `HOST_SIGNED_WORK_ACCEPTANCE`. The exact request payload is:

    {
      "schema": "wp-caregrid.hmr-submit.v1",
      "request_id": "uuid",
      "host_message_id": "64 lowercase hexadecimal deterministic broker outbox ID",
      "relay_id": "registered HMR ID",
      "provider_id": "registered provider ID",
      "host_id": "registered host ID",
      "channel_sequence": "canonical decimal uint64 string",
      "kind": "HOST_SIGNED_TERMINAL_RECEIPT|HOST_SIGNED_WORK_ACCEPTANCE",
      "payload_sha256": "sha256 of exact nested signed envelope bytes",
      "payload_b64url": "canonical unpadded base64url of exact nested envelope bytes",
      "sent_at": "UTC RFC3339 timestamp"
    }

`host_message_id` is computed and persisted as defined in §9.5. It identifies only the exact broker-originated durable outbox row; it is not a bearer authorization. For a receipt, the nested protocol identity is `receipt_id`; for HWA, it is `acceptance_id` plus the exact work binding. No platform `delivery_id` is present.

Laravel verifies the nested host signature and the exact identity, key purpose/state, and authorization/work mapping. For a Terminal Receipt, it commits the receipt reconciliation before returning ACK v2. For HWA, it verifies against exactly one durable authorization/work record, commits the acceptance, then returns a stable platform Work Acceptance Confirmation v1. HWA does not receive Receipt ACK v2.

Response schema: `wp-caregrid.hmr-submit-response.v1`:

    {
      "schema": "wp-caregrid.hmr-submit-response.v1",
      "request_id": "uuid",
      "host_message_id": "echo of submitted broker outbox ID",
      "relay_id": "authenticated relay ID",
      "provider_id": "registered provider ID",
      "host_id": "registered host ID",
      "result": "ACK_READY|ACCEPTANCE_RECORDED|DENIED",
      "ack_sha256": "sha256 or null",
      "ack_b64url": "canonical nested ACK v2 bytes or null",
      "acceptance_confirmation_sha256": "sha256 or null",
      "acceptance_confirmation_b64url": "canonical platform-signed confirmation bytes or null",
      "completed_at": "UTC RFC3339 timestamp",
      "error_code": "stable error enum or null"
    }

This is a strict discriminated union:
- `ACK_READY`: Terminal Receipt only; both ACK fields non-null and both confirmation fields null.
- `ACCEPTANCE_RECORDED`: verified HWA only; both ACK fields null and both Work Acceptance Confirmation fields non-null. The confirmation is signed by Laravel only after durable acceptance commit. It does not release a gate.
- `DENIED`: all four artifact/digest fields null; stable non-sensitive `error_code`; no successful durable transition.

Exact retries with the same `host_message_id`, digest, kind and bindings return the same semantic result and byte-identical ACK or confirmation. Changed binding is `HOST_MESSAGE_CONFLICT`; no receipt/HWA outbox row is deleted by a response.

### 9.4 HMR delivery to broker and platform confirmation

Broker IPC permits only these closed operations over the fixed per-site host socket:

- `read_pending_host_messages.v1`: returns exact pending broker outbox rows (`host_message_id`, kind, payload digest and unchanged nested bytes) for receipts and HWAs. It reveals no gate/generation.
- `submit_platform_receipt_ack.v1`: carries exact ACK v2 bytes/digest; broker verifies §8 and its private mapping before durable ACK recording. Receipt pruning additionally waits for the separate §8/§12.3 retention condition; ACK is terminal-receipt-only.
- `submit_platform_work_acceptance_confirmation.v1`: carries exact platform-signed Work Acceptance Confirmation v1 bytes/digest. Broker verifies its signing purpose and exact HWA/outbox/work tuple. For RESTORE, durable confirmation advances the restore work from `ACCEPTANCE_PENDING_PLATFORM` to `ACCEPTED`; for RECOVERY it advances only the recovery child from `ACCEPTANCE_PENDING_PLATFORM` to `RECOVERY_AUTHORIZED`. The parent remains `RECOVERY_REQUIRED` and fenced. Neither transition changes/releases a gate.
- `submit_platform_recovery_intent.v1`: verifies §5, commits unique recovery work and HWA outbox before returning the byte-identical HWA and stable host message ID. Exact retries return the same record; conflict/replay is denied.
- `read_hmr_channel_status.v1`: bounded registered-relay sequence/health metadata only.

HMR may submit a host HWA to Laravel; on `ACCEPTANCE_RECORDED`, it forwards the returned signed confirmation unchanged through `submit_platform_work_acceptance_confirmation.v1`. It MUST NOT treat the transport response itself as broker-verifiable proof. The same confirmation operation is used by the normal Connector after Laravel returns it from the dedicated acceptance endpoint.

The broker commits and durably persists exact HWA bytes/digest and receipt bytes/digest in their respective outboxes before exposure. HWA retention is governed by the separate PWAC-confirmation-plus-HWA-retention invariant in §8; terminal receipts use ACK-v2-plus-receipt-retention in §8. Neither outbox may be pruned merely because a transport response was returned. A normal Connector can re-read an HWA through `read_work_acceptance.v1` (exact intent digest and registered tuple) or repeat `begin_restore_handoff.v1`; both return the same bytes and outbox identity. HMR retrieves the same durable rows by cursor. After Laravel commits acceptance but its response is lost, exact HWA resubmission returns the same confirmation. After the broker commits a confirmation but the response is lost, exact confirmation resubmission returns the same durable result. Before the confirmation commit the broker MUST NOT permit artifact staging that can lead to execution, handoff activation, fence acquisition or worker launch. Crashes never create a second work item; uncertainty leaves work pending and the site unchanged/fenced as appropriate.

### 9.5 HMR queue, ordering and replay rules

Platform-originated delivery_id exists only in §9.2 hmr-delivery.v1, is generated by Laravel's durable platform-delivery row, and is bound to one poll request, relay/provider/host, sequence, kind and payload digest. It never identifies a broker-originated receipt or HWA.

Broker-originated host_message_id exists only in §9.3 submits and §9.4 broker outbox responses. The broker computes it from host/provider/relay, kind and exact payload digest using §9.3's domain-separated formula, then persists it with work, nested protocol ID (receipt_id or acceptance_id), digest and bytes. HMR stores host_message_id and digest before network submission. Laravel's idempotency key is (host_id, host_message_id); exact same digest/kind/bindings returns the prior durable result, while any change is rejected and audited. The broker's ID/digest/outbox mapping is authoritative; Laravel's accepted HWA record or receipt-processing row is authoritative for platform state; HMR's journal/cursor is only a retry aid.

A lost, reordered, duplicate, or crashed delivery never acknowledges or deletes the broker row. HMR retries exact bytes with the same host_message_id and monotonic channel sequence. For HWA, HMR also persists and forwards the exact platform-signed Work Acceptance Confirmation returned with ACCEPTANCE_RECORDED; the broker durably records it before activation. Reusing an ID with a different digest, cross-host/relay identity, unsupported kind, or sequence rollback is rejected. Receipt ACK v2 remains independently submitted through submit_platform_receipt_ack.v1 and applies only to a terminal receipt; ACCEPTANCE_RECORDED/PWAC is never a Receipt ACK or gate signal. HMR spool is encrypted at rest and bounded; if it cannot durably journal an item, it stops forwarding it.

## 10. Typed API contracts

The operations below are fixed verbs and closed request/response schemas. All local operations use a fixed per-site Unix-domain SOCK_SEQPACKET endpoint with OS-authenticated peer credentials bound by the provider registration to the expected site agent identity and service/cgroup policy. Message-level authorization remains mandatory; no TCP listener or caller-selected socket/path is allowed. Responses MUST be bounded and error bodies MUST NOT contain secrets, host paths, SQL, payload content, or private generation.

Common typed RPC wrapper:

    {
      "schema": "wp-caregrid.broker-rpc.v1",
      "request_id": "uuid",
      "site_id": "registered immutable site ID",
      "operation": "one registered operation name",
      "payload": {}
    }

The wrapper is JCS-encoded, limited to a registered maximum size, and accepted only on the fixed per-site IPC endpoint. Caller UID/cgroup is an additional policy check. Every operation's payload schema is closed. Caller-supplied `site_id` is compared with socket registration; it cannot select an arbitrary site.

| Operation | Request / response | Authority and preconditions | Timeout / retry / idempotency | Audit and fail-closed behavior |
|---|---|---|---|---|
| `begin_restore_handoff.v1` | Request: exact signed Restore Intent bytes/digest and fixed object descriptors. Response: RESTORE HWA or typed denial. | Connector/agent submits; broker verifies Laravel signature, current site mapping and all fields. | 5 s; idempotency key is registered site + restore/job + intent ID + exact envelope digest; exact retry returns byte-identical HWA/outbox row. | Persist work as ACCEPTANCE_PENDING_PLATFORM and HWA outbox. No object transfer, gate acquisition, mutation, or worker before a valid platform confirmation. |
| `append_restore_object.v1` | Request: work ID, fixed object enum, offset, bounded chunk bytes, chunk digest, final flag. Response: accepted offset and object state. | Only work with broker-durable platform acceptance confirmation; broker compares expected manifest object, length, digest, and current staging state. | 30 s/chunk; exact duplicate chunk idempotent; offset/content conflict denied. Object-level limits/timeouts are provider-bound; partial input never executable. | Before confirmation: deny without staging writes. After confirmation: audit byte count/digest only; interrupted input remains incomplete/quarantined. |
| `activate_handoff.v1` | Request: work ID + platform Work Acceptance Confirmation ID/digest + complete immutable-object digest. Response: bounded state. | Broker requires durable confirmation, complete manifest-verified objects and one exact work mapping. | 5 s; exact repeat returns same saga/work. | Starts asynchronous fence saga only after all checks; no synchronous FPM self-drain. Missing/ambiguous confirmation denies before gate change. |
| `query_handoff.v1` | Request: work ID + exact binding digest. Response: allowlisted state and last transition time. | Read-only; exact registered site/work. | 2 s; safe repeat. | No IDs of other sites or internal generation. Missing/corrupt state returns UNKNOWN/deny and leaves gate unchanged. |
| `read_terminal_receipt.v1` | Request: work ID + outcome cursor. Response: exact stored signed receipt bytes/digest or NOT_READY. | Read-only authorized Connector after safe reopen, or HMR while site fenced. | 5 s; repeat exact bytes. | Receipt bytes remain available until exact bound ACK v2 is durable AND separate receipt retention completes; never consumes/prunes merely on read. Preserve replay tombstone. |
| `submit_terminal_ack.v1` | Request: exact ACK v2 bytes/digest. Response: ACK_RECORDED, ALREADY_RECORDED, or typed denial. | Broker verifies platform ACK signature and exact unique mapping. | 5 s; exact duplicate is idempotent; any changed binding denied. | Durable ACK record precedes any later pruning, but receipt bytes remain until separate configured retention completes; denial preserves receipt and gate state. ACK is not release. |
| `submit_recovery_intent.v1` | Request: exact signed Recovery Intent bytes/digest. Response: exact broker-signed RECOVERY HWA + stable host_message_id or typed denial. | HMR/fixed audited operator interface while site fenced; broker verifies exact trigger and separate authorization. | 5 s; exact retry yields same HWA/outbox ID; conflicting/replay denied. | Create a distinct recovery child in `ACCEPTANCE_PENDING_PLATFORM`; keep the original parent `RECOVERY_REQUIRED` and fenced. Laravel later persists HWA and returns `ACCEPTANCE_RECORDED` plus signed confirmation (never Receipt ACK); broker requires that confirmation before the child becomes `RECOVERY_AUTHORIZED` or any recovery worker can activate. |
| `read_work_acceptance.v1` | Request: registered site, restore/job, intent ID and exact intent-envelope digest. Response: unchanged RESTORE HWA bytes/digest or typed NOT_READY/denial. | Registered Connector/agent; broker reads one exact durable acceptance/outbox row. | 5 s; exact retry byte-identical; conflicting mapping denied. | Read-only; never consumes/prunes HWA; no generation or gate state. |
| `submit_platform_work_acceptance_confirmation.v1` | Request: exact platform-signed confirmation bytes/digest. Response: ACCEPTANCE_RECORDED or ALREADY_RECORDED, otherwise typed denial. | Connector/agent or HMR delivers to broker; broker verifies platform key purpose, HWA digest and one exact private work mapping. | 5 s; exact duplicate idempotent; different bytes/bindings conflict. | Persist PWAC before activation. RESTORE child: `ACCEPTANCE_PENDING_PLATFORM → ACCEPTED`; RECOVERY child: `ACCEPTANCE_PENDING_PLATFORM → RECOVERY_AUTHORIZED`, with parent unchanged at `RECOVERY_REQUIRED`/fenced. No gate release. HWA bytes must already have been durable before exposure and cannot be pruned until this exact PWAC is durably committed by broker AND configured HWA retention completes; preserve HWA/acceptance/work tombstones. This confirmation does not satisfy Terminal Receipt ACK v2 retention. |
| `read_broker_status.v1` | Request: registered site ID and request ID. Response: coarse state and fencing status. | Read-only operator/agent role, site-bound. | 2 s; repeat safe. | Never returns generation, secret, path, commands or another site's data. |

### 10.1 Broker-private worker operations

These are target-architecture internal operations, separate from the per-site Connector/agent and HMR socket APIs. They are available only over the fixed broker-to-worker authenticated IPC endpoint; HMR, Connector, Laravel and the per-site agent cannot invoke them.

| Operation | Request / response | Preconditions and authority | Retry / crash behavior | Forbidden result |
|---|---|---|---|---|
| assert_work_active.v1 | Request: work_id and a closed mutation_boundary enum (DB_SWAP, FILE_CREATE, FILE_WRITE, FILE_RENAME, DB_IMPORT); response: ALLOW or DENY plus bounded reason enum | Broker resolves exactly one accepted work and its private active generation, fence proof and worker identity | Bounded per mutation boundary; exact repeat is read-only; timeout/uncertainty is DENY | No generation/lease returned; no mutation after DENY/timeout |
| commit_gate_handoff.v1 | Request: work_id and durable handoff proof digest; response: COMMITTED or DENIED | Broker-only reconciliation of durable private current/previous generation records and verified provider fence proof | Idempotent for same work/digest; restart reconciles journal before response | No Connector marker or caller field selects current generation |
| finalize_terminal_outcome.v1 | Request: work_id, receipt_id, receipt_digest, outcome; response: RELEASE_ELIGIBLE or DENIED | Broker verifies worker exit, durable outcome invariants, receipt outbox persistence and exact private mapping | Idempotent exact retry; crash leaves gate fenced until journal reconciliation | ACK or HMR acceptance result cannot release gate; no generation in request/response |

### 10.2 #182-to-broker/worker migration boundary
This contract distinguishes the implementation in #182 from the future #190 target:

- Implemented today in #182: absent or unreviewed provider returns RESTORE_ISOLATION_UNAVAILABLE before maintenance activation or live file/database mutation. The reviewed RestoreIsolationGateProvider PHP interface is fail-closed and its lease checks guard the current Connector-side restore path. This is the present compatibility safeguard; it does not prove arbitrary-host isolation.
- Future #190 target, not implemented: the broker is the sole authoritative host gate/generation owner; the isolated one-shot worker is the only process that may apply live filesystem/database changes. Laravel and Connector authorize, transfer immutable work and reconcile signed results. Connector markers may contain bounded handoff/outbox correlation only; they MUST NOT contain host generation, lease token, provider secret or host-control authority. PHP-side mutation and the #182 lease methods MUST NOT be a fallback, including when a provider is present. Until the separately reviewed replacement is implemented and enabled, #182 remains the current implementation and its provider-gated semantics remain unchanged. The future broker-target mode is unavailable until then; it MUST fail closed rather than fall back to the Connector-side executor.

| Existing #182 element | Current implemented meaning | Future broker/worker adaptation | Transitional rule / forbidden fallback |
|---|---|---|---|
| RestoreIsolationGateProvider::provider_id() | Stable PHP provider identifier for the current gate adapter | Broker installation/host registration supplies stable provider ID through enrollment and fixed status metadata | Connector-reported ID is not trusted enrollment; no allowlist activation |
| acquire($lease, $previous) | Current PHP adapter obtains a gate/lease before Connector-side restore mutation | Replace with Laravel-signed begin_restore_handoff.v1 plus broker activate_handoff.v1; broker fences writers, privately allocates generation and launches only the isolated worker | No lease/generation returned to Connector or marker; broker absence never falls back to PHP apply |
| assert_active($lease) | Current PHP executor rechecks lease before database/file boundaries | Broker/worker-private assert_work_active.v1(work_id) checks durable work-to-generation mapping at each DB swap, file write/create/rename boundary | Internal authenticated worker IPC accepts work identity only and returns bounded allow/deny; generation stays private. Denial/timeout stops before next mutation |
| commit($current, $previous) | Current adapter completes a two-generation PHP marker handoff | Broker-private commit_gate_handoff.v1(work_id) reconciles durable current/prior generations and host-fence proof | Not a Connector API; crash preserves both broker-internal gate records; no marker supplies generation |
| release($lease) | Current adapter releases only after PHP outcome checks | Broker-private finalize_terminal_outcome.v1(work_id, receipt_id, receipt_digest) verifies durable worker evidence and outcome invariants before gate finalization | ACK, Connector or HMR response never invokes release; no TTL/process-exit/PHP cleanup release |
| SqlImporter, RestoreHandler and current Connector restore executor | Current implementation may mutate only after current #182 provider succeeds | A separate isolated worker receives immutable broker-accepted work; Connector transfers artifacts and ingests signed outcomes only | Retiring PHP mutation is a separate implementation PR and conformance review; this document does not change it. Until then, target operation is unavailable/fail-closed rather than using the old executor |
| .maintenance / Connector handoff marker | Current WordPress marker is not a safe cross-process host-ownership primitive; #182 retains its documented limits | Connector handoff/outbox marker stores non-secret correlation IDs/state only; broker journal owns gate and generation | Never store generation/lease; marker ownership is not a writer fence or fallback authority |

The broker-private operations above are internal typed contract operations, not public Connector endpoints. work_id is a lookup key, not a capability: peer identity, durable mapping, signed-intent binding and work state must also validate. No migration code is part of this documentation PR.

### 10.3 Connector-to-Laravel RESTORE HWA ingestion

The following proposed operation is the only Laravel path that records a normal RESTORE HWA; it is not current deployed behavior until a separately reviewed implementation PR exists.

`POST /api/v1/connector/restore/work-acceptance`; authenticated by the existing paired Connector v1 credential over HTTPS. Site, tenant and Connector identities are derived from the authenticated pairing, never trusted from request-supplied identity fields. Request body is closed and size-bounded:

```json
{
  "schema": "wp-caregrid.connector-restore-work-acceptance.v1",
  "request_id": "uuid",
  "restore_uuid": "original restore UUID",
  "job_uuid": "original restore job UUID",
  "acceptance_id": "broker acceptance UUID",
  "work_id": "broker restore work UUID",
  "hwa_sha256": "SHA-256 of exact canonical signed HWA envelope bytes",
  "hwa_b64url": "canonical unpadded base64url of exact HWA envelope bytes"
}
```

Laravel derives `tenant_id/site_id/connector_id` from the credential, resolves exactly one active restore/job and previously issued intent, verifies byte digest and canonical envelope, broker host signature and key purpose/status, then requires `work_kind=RESTORE`, `authorization.schema=wp-caregrid.restore-work-binding.v1`, exact acceptance/work IDs, intent ID and exact signed-intent envelope digest, original restore/job, backup UUID, manifest digest, scope, tenant/site/Connector/provider/host tuple. It commits a unique acceptance row linked to the original restore/job before returning success.

Acceptance idempotency key: `(site_id, restore_uuid, job_uuid, work_kind=RESTORE, restore_intent_id, restore_intent_envelope_sha256)`; separate unique constraints require `acceptance_id` and `work_id` to each map to exactly that same row. Only one active/unresolved restore work chain may exist for a site. Exact HWA retry returns the same durable acceptance and byte-identical signed Work Acceptance Confirmation; same key with changed HWA digest or any binding, or acceptance/work ID mapped elsewhere, returns `409 ACCEPTANCE_BINDING_MISMATCH`/`WORK_CONFLICT` without state mutation.

Response is closed:

```json
{
  "schema": "wp-caregrid.connector-restore-work-acceptance-response.v1",
  "request_id": "same request UUID",
  "result": "ACCEPTANCE_RECORDED|ALREADY_RECORDED|DENIED",
  "confirmation_sha256": "SHA-256 of exact signed Work Acceptance Confirmation bytes or null",
  "confirmation_b64url": "canonical bytes or null",
  "error_code": "stable enum or null"
}
```

For either success result both confirmation fields are non-null; DENIED has them null. Laravel signs and returns the same durable confirmation on exact retries. Connector persists it in its bounded outbox and sends the unchanged bytes/digest to `submit_platform_work_acceptance_confirmation.v1`. It is transport-authenticated by the existing Connector pairing on upload and by the platform signature when broker accepts it. Retries are bounded with backoff; retry after timeout uses same request_id and bytes. No endpoint response starts work. The broker's durable confirmation is the sole precondition for subsequent object staging/activation.

If a receipt arrives before this acceptance is durably recorded, Laravel returns `409 ACCEPTANCE_NOT_FOUND`, performs no restore-success/job state transition, emits no ACK and retains the broker receipt. After the exact HWA is processed, the same receipt may be retried and reconciled once. Missing, duplicate, out-of-order or ambiguous acceptance never yields success.



### 10.4 Connector ↔ agent boundary

The Connector may use only fixed object enums and accepted work IDs. It cannot supply an arbitrary path, SQL, URL, command, unit, host ID, tenant ID override, generation, or gate action. The agent validates local site registration, caller identity and bounds, then relays typed messages to broker. The broker still verifies signed intent and authorization; Connector/agent transport authentication is not authorization.

### 10.5 HMR ↔ broker boundary

HMR has only the typed methods in §9.4. HMR cannot call broker-private assert_work_active, commit_gate_handoff, or finalize_terminal_outcome, nor worker launch, arbitrary status, marker operation, filesystem operation, SQL operation, or a generic broker method. HMR unavailability leaves durable messages and gate state unchanged.

### 10.6 Error codes

Externally visible codes are stable, non-sensitive enums: `INVALID_ENVELOPE`, `UNSUPPORTED_SCHEMA`, `SIGNATURE_INVALID`, `KEY_UNKNOWN`, `KEY_PURPOSE_MISMATCH`, `KEY_REVOKED`, `KEY_COMPROMISED`, `KEY_RETIRED`, `MESSAGE_EXPIRED`, `CLOCK_UNSAFE`, `NONCE_REPLAY`, `IDENTITY_MISMATCH`, `AUTHORIZATION_MISMATCH`, `ACCEPTANCE_NOT_FOUND`, `ACCEPTANCE_PENDING`, `ACCEPTANCE_BINDING_MISMATCH`, `CONFIRMATION_NOT_READY`, `CONFIRMATION_CONFLICT`, `HOST_MESSAGE_CONFLICT`, `WORK_CONFLICT`, `WORK_NOT_FOUND`, `ARTIFACT_INCOMPLETE`, `ARTIFACT_DIGEST_MISMATCH`, `RESOURCE_LIMIT`, `FENCE_INCOMPLETE`, `LEASE_LOST`, `RECEIPT_NOT_READY`, `ACK_BINDING_MISMATCH`, `WORK_MAPPING_AMBIGUOUS`, `STATE_CORRUPT`, `RETRY_LATER`, and `UNSUPPORTED_PROVIDER`. `ACCEPTANCE_NOT_FOUND` means no unique committed platform acceptance row is available to reconcile a terminal receipt; `ACCEPTANCE_PENDING` means the exact HWA exists but its platform confirmation has not been committed by the broker; `CONFIRMATION_NOT_READY` means no exact durable HWA/outbox row is currently confirmable; `CONFIRMATION_CONFLICT` means an ID or work tuple already maps to different confirmation bytes. A revoked or compromised signing key is never accepted. Internal diagnostics are separately access-controlled and redact credentials, signed envelopes, nonce, signature, raw artifact content and private generation.

## 11. Broker durable state machine

The state machine has two related dimensions: **work lifecycle** and **site gate**. A receipt being delivered/ACKed is not the site gate state. `ACCEPTANCE_PENDING_PLATFORM` is one shared broker work state for RESTORE work and a RECOVERY child; the required `work_kind` and strict authorization union select the transition on confirmation. The parent RESTORE work stays `RECOVERY_REQUIRED` and fenced while a recovery child is accepted, authorized, executed, or reconciled. Platform `ACCEPTANCE_RECORDED` is separate bookkeeping and never aliases a broker work/gate state.

### 11.1 Work lifecycle

| From → To | Trigger and actor | Durable precondition/write | Duplicate/crash behavior |
|---|---|---|---|
| NONE → AUTHORIZED | Laravel issues signed Restore Intent | Laravel restore/job/backup authorization and exact signed intent envelope/digest committed | Same intent ID/digest stable; conflicting issuance denied |
| AUTHORIZED → ACCEPTANCE_PENDING_PLATFORM (RESTORE work) | Broker verifies Restore Intent in begin_restore_handoff.v1 | Persist nonce/intent tombstone, exact tuple, unique restore work, private generation mapping, stable signed RESTORE HWA and HWA outbox row | Exact intent retry returns same HWA/work; no object transfer, fence, worker or mutation |
| ACCEPTANCE_PENDING_PLATFORM → ACCEPTED (RESTORE work only) | Broker verifies Laravel-signed Work Acceptance Confirmation v1 for the exact RESTORE HWA | Persist exact confirmation digest, acceptance/work/intent binding and platform-confirmed timestamp before exposing accepted state | Exact confirmation retry is idempotent; mismatch/crash leaves pending and no execution |
| RECOVERY_REQUIRED (parent remains fenced) + new RECOVERY child → ACCEPTANCE_PENDING_PLATFORM (child) | Broker verifies the separately signed Recovery Intent, current trigger receipt and one durable parent mapping | Persist a distinct child recovery_work_id, exact authorization/trigger tuple, private child-generation mapping, signed RECOVERY HWA and HWA outbox; do not change parent state | Exact intent retry returns same child HWA/work; no worker, gate release or parent-state transition |
| ACCEPTANCE_PENDING_PLATFORM → RECOVERY_AUTHORIZED (RECOVERY child only) | Broker verifies Laravel-signed Work Acceptance Confirmation v1 for the exact RECOVERY HWA | Persist exact confirmation digest and child/parent/trigger/authorization binding before exposing authorized child state | Exact retry idempotent; mismatch/crash leaves child pending and parent RECOVERY_REQUIRED/fenced; no execution |
| ACCEPTED → STAGING | Connector/agent uploads fixed objects | Persist per-object offsets/digests and completeness bitmap; published object immutable only after full digest verification | Partial upload never executes; restart resumes same confirmed work only |
| STAGING → READY | Broker verifies all expected objects and manifest | Persist immutable input-set digest and ready state | Duplicate activation returns same work |
| READY → FENCING | Broker starts asynchronous gate saga | Persist fencing intent before external fence actions; requires confirmed acceptance and complete immutable input | Crash resumes reconciliation; unknown writer blocks |
| FENCING → QUIESCENT | Broker verifies all origin/CDN, in-flight, FPM/cgroup, cron/queue/CLI/deploy/DB/file writer controls | Persist exact site/work/private generation and fence proof refs | Any missing proof stays FENCING/fenced; no worker |
| QUIESCENT → APPLYING | Broker launches fixed worker once | Persist mutation-start evidence before each first live mutation boundary | Before first mutation, safe abort only with durable no-mutation proof; after ambiguity RECOVERY_REQUIRED |
| APPLYING → TERMINAL_DURABLE | Broker verifies worker evidence and writes strict receipt/outbox | Persist canonical signed receipt and exact digest before eligible reopen | Reconstruct only from durable journal; ambiguity creates RECOVERY_REQUIRED and keeps gate closed |
| TERMINAL_DURABLE → ACKED | Broker verifies ACK v2 | Persist ACK ID/digest and exact work/generation mapping | Exact duplicate no-op; invalid/conflicting ACK rejected; receipt remains until durable, correctly bound ACK and separate retention condition permit pruning |
| ACKED → RETAINED_TOMBSTONE | Broker prunes receipt bytes after retention policy | Preserve identity/digest/outcome/work tombstone for replay detection | Tombstone prevents old receipt/ACK rebinding |
| RECOVERY_REQUIRED (parent remains fenced) + new RECOVERY child → ACCEPTANCE_PENDING_PLATFORM (child) | Broker verifies separate Recovery Intent and commits unique recovery HWA/outbox | Persist exact trigger receipt/digest, recovery authorization/envelope digest, recovery_work_id and private child-generation mapping; parent row stays RECOVERY_REQUIRED | Identical intent returns same child HWA/work; no worker, parent transition or gate release |
| ACCEPTANCE_PENDING_PLATFORM → RECOVERY_AUTHORIZED (RECOVERY child only) | Broker verifies Laravel Work Acceptance Confirmation v1 for exact Recovery HWA | Persist confirmation digest and exact child/parent/trigger/authorization binding | Retry idempotent; mismatch leaves child pending and parent RECOVERY_REQUIRED/fenced; no execution |
| RECOVERY_AUTHORIZED → RECOVERING | Broker re-proves fence and launches fixed recovery worker | Persist recovery mutation-start state before first change | Any uncertain step stays fenced; no automatic destructive retry |
| RECOVERING → RECOVERY_TERMINAL_DURABLE | Broker validates RECOVERY_SUCCESS or nested RECOVERY_REQUIRED | Persist exact outcome receipt/outbox before eligible release | Lost delivery retries exact bytes; no regenerated identity |
| RECOVERY_TERMINAL_DURABLE → ACKED | Same ACK v2 checks, with exact recovery-work binding | Persist ACK before any pruning; apply the separate §8/§12.3 receipt-retention condition | Missing/invalid ACK retains exact recovery receipt; ACK alone does not prune or release |


#### Platform durable acceptance bookkeeping (not a broker gate state)

| From → To | Trigger and actor | Durable precondition/write | Duplicate/crash behavior |
|---|---|---|---|
| RESTORE_AUTHORIZED → ACCEPTANCE_RECORDED | Laravel receives RESTORE HWA through the Connector acceptance API | Verify HWA signature/key purpose and exact Restore Intent envelope digest, tenant/site/Connector/provider/host, original restore/job, acceptance ID/work ID, backup/manifest/scope; commit one acceptance row to the original restore/job, then persist/sign its Work Acceptance Confirmation | Exact same HWA returns the same confirmation; changed/ambiguous mapping denied. Lost response retries exact HWA; no worker, ACK v2, or gate change |
| PLATFORM_RECOVERY_APPROVAL_RECORDED → ACCEPTANCE_RECORDED | Laravel receives RECOVERY HWA through HMR | Verify HWA signature/key purpose and exact Recovery Intent ID/digest, trigger receipt, tenant/site/Connector/provider/host, original restore/job, acceptance ID/recovery_work_id; commit the unique recovery acceptance row linked to the approved recovery authorization and original restore/job, then persist/sign its Work Acceptance Confirmation | Exact retry is idempotent; conflict denied. HMR returns ACCEPTANCE_RECORDED plus confirmation, never Receipt ACK v2; no broker gate change |
The broker does not share this platform workflow state machine. Upon receiving the exact signed Work Acceptance Confirmation, it validates the one durable HWA/outbox/private work mapping. For RESTORE, its work row transitions from `ACCEPTANCE_PENDING_PLATFORM` to `ACCEPTED`; for RECOVERY, only the child transitions from `ACCEPTANCE_PENDING_PLATFORM` to `RECOVERY_AUTHORIZED`, while the parent remains `RECOVERY_REQUIRED` and fenced. Lost responses retry the same confirmation; no execution starts before this broker commit. The platform `ACCEPTANCE_RECORDED` state is workflow bookkeeping only and is distinct from both broker transitions. It does not itself activate work, change the broker gate, create terminal success, issue Receipt ACK v2, or prune a terminal receipt. The broker's durable validation of the separate platform-signed confirmation is the prerequisite for object staging and later activation. Normal RESTORE and RECOVERY use the same confirmation purpose but distinct strict authorization unions and durable parent records; neither confirmation is a terminal ACK.
### 11.2 Broker-private work/generation mapping

This subsection describes the future #190 broker target, not the present #182 implementation: the #182 maintenance-marker behavior remains as documented until a separately reviewed migration changes it. For each site the target broker stores a private record mapping `(site_id, generation)` to exactly one `work_id`, authorization digest, current gate state, mutation state, receipt ID/digest/outbox row, HWA/host-message outbox rows, and prior-generation handoff. For recovery it stores one unique child `recovery_work_id` mapped to the exact parent work, trigger receipt, recovery intent digest, safety backup/manifest/scope and private generation. The internal generation is generated by the broker, inaccessible to Connector/agent/HMR/Laravel, omitted from every wire schema, and redacted from logs.

For any receipt or ACK, the broker must resolve exactly one matching durable work record by public tuple and exact envelope digest, then compare the private generation locally. Zero or multiple matches, corrupt journal, wrong parent, or inconsistent generation means reject and preserve receipt/fence. No caller-provided generation can repair an ambiguous mapping.

### 11.3 Crash and restart rules

- Persist authorization/replay reservation and exact HWA outbox before returning HWA; RESTORE and RECOVERY child work both remain in `ACCEPTANCE_PENDING_PLATFORM` until the exact platform confirmation is durable. For RECOVERY, the parent remains `RECOVERY_REQUIRED`/fenced. No object staging, fence, activation or worker starts before that confirmation.
- Persist Laravel's HWA acceptance and exact signed Work Acceptance Confirmation bytes atomically before returning them; persist broker confirmation before any immutable object staging. Persist each immutable object as non-executable staging; fsync file and containing directory before marking complete.
- Persist fence-intent before changing ingress/writer controls; reconcile actual controls after restart.
- Persist QUIESCENT only after independently verifying all required controls.
- Persist mutation-start evidence before each first live file/DB mutation boundary.
- Persist/fsync signed terminal receipt/outbox before any release-eligible transition; persist the exact terminal-signing key ID and allowlist/outstanding receipt state consistently with §12.
- Persist ACK and its unique work/generation reconciliation before any receipt pruning; wait for both durable, correctly bound ACK v2 and the separate configured receipt-retention condition. Retain HWA/confirmation retry bytes until broker durable PWAC confirmation is known AND the configured HWA/confirmation retention completes, then keep identity/digest replay tombstones through audit retention.
- Boot begins with ingress/writers fenced for any nonterminal/ambiguous work. Broker journal reconciliation runs before site services; HMR reconnect does not reopen anything.
- A crash where durable ordering cannot be proven is classified ambiguous and fenced. No TTL, process-exit heuristic, stale-marker cleanup or automatic SQL staging-table deletion releases protection.

## 12. Cryptography and key lifecycle

### 12.1 Key roles

| Key | Holder | Purpose | MUST NOT |
|---|---|---|---|
| Platform Restore Intent key | Laravel key-management service | Sign Restore Intent v1 only | Sign recovery intents, ACKs, or host receipts |
| Platform Recovery Intent key | Laravel key-management service | Sign Recovery Intent v1 only | Sign normal restore intents or ACKs |
| Platform ACK key | Laravel key-management service | Sign Receipt ACK v2 only | Sign intents, work-acceptance confirmations, or host receipts |
| Platform Work Acceptance Confirmation key | Laravel key-management service | Sign Platform Work Acceptance Confirmation v1 only after exact HWA durable acceptance; new signatures use ACTIVE_SIGN_VERIFY only | Sign terminal ACKs, intents, host receipts, or key-rotation control records |
| Platform Key Lifecycle Control key | Separately protected platform key-management control plane | Sign only authenticated key-registration/rotation inventory records, including pre-cutoff outstanding-PWAC snapshots, under purpose `PLATFORM_KEY_ROTATION_CONTROL` | Sign PWACs, restore/recovery intents, HWA, terminal receipts, ACK v2, or authorize restore/work/gate operations |
| Host Work Acceptance key | Root broker security boundary | Sign Host Work Acceptance v1 only, with acceptance purpose | Be available to Connector, agent, HMR or worker; sign terminal receipts |
| Host Terminal Receipt key | Root broker security boundary | Sign Terminal Receipt v2 only, with terminal-receipt purpose; `signature.key_id` MUST equal `payload.host_key_id` | Sign HWA or be available outside the broker |
| HMR mTLS identity key/certificate | HMR OS identity | Authenticate outbound transport | Authorize protocol messages, recovery, gate changes or broker operations |

| Worker identity | One-shot worker runtime | Authenticate local result channel | Sign receipts or control gate |

Key IDs are immutable, globally unique within issuer and purpose, and bound to algorithm, public key, owner, validity interval, status and rotation lineage. No algorithm negotiation from a message is allowed; schemas require Ed25519.

### 12.2 Registration, rotation and revocation

Platform and host public keys enter the opposite trust store only through separately authenticated provider enrollment/release procedures. Connector inventory, HMR poll or an untrusted receipt cannot enroll/replace a key. Registration and rotation produce auditable records with approver, key ID, fingerprint, scope, validity interval and exact provider/host/site binding.

Rotation uses a bounded overlap at least as long as the maximum signed-message lifetime, allowed clock skew, and configured delivery-retry interval. Key state is one of ACTIVE_SIGN_VERIFY, OVERLAP_VERIFY_ONLY, RETIRED_VERIFY_ONLY, REVOKED, or COMPROMISED, scoped to issuer, provider, host and purpose. New messages—including every newly created PWAC—MUST use only ACTIVE_SIGN_VERIFY. Verify-only keys cannot sign or authorize new work.

The existing Terminal Receipt exception is unchanged: OVERLAP_VERIFY_ONLY/RETIRED_VERIFY_ONLY may verify only the exact `receipt_id + canonical signed-envelope digest` registered before the signing cutoff in the authenticated durable outstanding-receipt inventory. `finished_at` alone is never proof.

A separate, explicit verify-only rule applies to Platform Work Acceptance Confirmation v1 (PWAC), under purpose `PLATFORM_WORK_ACCEPTANCE_CONFIRMATION`. Before exposing a newly signed PWAC, Laravel MUST atomically commit (a) its accepted HWA mapping, (b) exact canonical signed PWAC bytes and SHA-256, and (c) one durable outstanding-confirmation inventory item. The item MUST bind: `confirmation_id`; exact canonical signed PWAC envelope SHA-256; exact HWA envelope digest and `acceptance_id`; `work_id` and `work_kind`; the complete RESTORE or RECOVERY authorization binding (including intent ID/digest, parent/trigger where applicable, restore/job, backup/manifest/scope); tenant/site/Connector/provider/host identity; issuer and audience; signing purpose and `key_id`; key validity interval; first-issued time; inventory sequence; rotation ID; signing cutoff; and bounded `verify_until`. The record is immutable and unique by confirmation ID and exact PWAC digest.

Pre-cutoff provenance MUST NOT be established from PWAC `confirmed_at`, `key_id`, a client timestamp, or a late-arriving envelope alone. Before the cutoff, the platform key-management control plane produces an authenticated, append-only `wp-caregrid.platform-key-rotation-inventory.v1` snapshot under domain `WPCAREGRID-PLATFORM-KEY-ROTATION-INVENTORY-V1` and dedicated purpose `PLATFORM_KEY_ROTATION_CONTROL`. Its closed payload schema has exactly these top-level members: `schema`, `iss`, `aud`, `control_key_id`, `provider_id`, `host_id`, `rotation_id`, `from_key_id`, `to_key_id`, `snapshot_sequence`, `created_at`, `signing_cutoff`, `approved_overlap_end`, and `outstanding_confirmations`. Each closed `outstanding_confirmations` item has exactly: `confirmation_id`, `pwac_envelope_sha256`, `hwa_envelope_sha256`, `acceptance_id`, `work_id`, `work_kind`, `authorization` (the exact RESTORE/RECOVERY union from §6.1), `tenant_id`, `site_id`, `connector_id`, `provider_id`, `host_id`, `confirmation_iss`, `confirmation_aud`, `signing_purpose`, `key_id`, `key_valid_from`, `key_valid_until`, `first_issued_at`, `inventory_sequence`, `rotation_id`, `signing_cutoff`, and `verify_until`. All IDs, digests, timestamps, enum values, uniqueness constraints and tuple bindings use the §3/§6.1 rules. No unlisted fields are permitted. The closed payload MUST NOT contain `snapshot_digest`, `snapshot_envelope_sha256`, or any field hashing the snapshot itself. After the complete payload is formed and domain-separated Ed25519 signature is produced, the signed outer envelope is serialized once as exact JCS bytes under §3.1; the snapshot identity is `snapshot_envelope_sha256 = SHA-256(exact canonical serialized signed outer-envelope bytes, including the complete signature object)`. The broker MUST calculate this digest itself after validating canonical bytes, signature, key ID, control-key registration, `PLATFORM_KEY_ROTATION_CONTROL` purpose, issuer/audience, provider/host scope, rotation binding, sequence and closed payload. A sender-supplied digest is never evidence. The digest is broker journal metadata only, not part of the signed snapshot payload. The control key is pre-enrolled through the authenticated provider/host key-lifecycle procedure and is separate from restore authorization and PWAC signing keys. The broker MUST durably persist/fsync the exact eligible inventory, computed envelope digest, sequence and rotation/cutoff record before the cutoff becomes effective. That broker commit (not the claimed item timestamp) is the trusted evidence that an item was outstanding before cutoff. A snapshot that arrives after cutoff, has a sequence rollback/gap, is unauthenticated/noncanonical, or conflicts with a prior item is not eligible.

During the approved overlap, and only through each item's signed `verify_until` (bounded by the approved overlap/retention policy), the broker may verify a PWAC under OVERLAP_VERIFY_ONLY or RETIRED_VERIFY_ONLY only when every field and exact envelope digest matches one item in the broker's pre-cutoff committed inventory and the item names the exact HWA/outbox/private work mapping. The broker then applies the ordinary confirmation signature, key-purpose/status, identity, work-kind and generation checks. The same exact PWAC retry is idempotent. A changed byte, ID, binding, key, or inventory entry is rejected. After `verify_until`, or if an unresolved work remains without eligible inventory, fail closed and preserve HWA/work/fence for audited reconciliation; do not infer eligibility or issue a replacement confirmation under K1.

Crash/lost-response ordering is strict: if Laravel commits the PWAC and inventory item but the broker has not yet committed the pre-cutoff authenticated snapshot, a lost response does not make the PWAC verify-only eligible; the work remains pending until the exact item is registered before cutoff or audited reconciliation occurs. If the broker has durably committed the snapshot, retries after response loss/reboot may verify only the exact inventory-matched bytes until `verify_until`. Rotation recovery reconciles the authenticated monotonic snapshot journal before accepting old-key retries; corrupt/missing journal state fails closed. REVOKED and COMPROMISED keys are never accepted for any outstanding PWAC or receipt, regardless of inventory, signature time, overlap or retention. Preserve existing receipt/outbox/fence and require audited reconciliation, with no ACK, pruning or gate release.

Revoked keys are rejected for new authorization immediately. If compromise is suspected, the verifier fails closed for affected unacknowledged receipts/intents and does not silently accept them under a replacement key; preserve fence/outbox and require an auditable reauthorization/reconciliation decision. HMR certificate renewal is bound to the same registered relay/provider/host and uses overlapping trust only during approved rotation. Expired, revoked, wrong-host or unknown certificates stop channel processing, not broker state retention.

### 12.3 Nonce and replay retention

- Unaccepted expired/rejected nonce tombstones are retained through at least `expires_at + 2 × maximum_clock_skew`; invalid signatures do not reserve arbitrary unbounded attacker-controlled nonces.
- Once an intent is accepted, its intent ID, nonce digest, exact envelope digest, work mapping and terminal tombstone are retained for the full restore-case audit retention period. They are not TTL-pruned while the work, gate, receipt or recovery chain remains unresolved.
- Recovery attempts use their own intent ID/nonce and exact trigger receipt; accepted recovery replay records remain until the recovery chain is terminal, ACKed and the audit retention policy permits pruning.
- Each outstanding PWAC inventory item, authenticated rotation snapshot `snapshot_envelope_sha256`/sequence, confirmation ID, exact PWAC digest, HWA digest/acceptance ID and work/authorization tuple are durably deduplicated and retained while acceptance/work is pending or unresolved, then for the explicit restore-case audit-retention period. Rotation snapshots/tombstones cannot be TTL-pruned while any referenced confirmation or HWA is outstanding.
- PWAC bytes in Laravel's durable confirmation outbox and broker's confirmation journal remain available for exact retry until broker durable confirmation is acknowledged and configured HWA/confirmation retention completes; retain confirmation/HWA identity and digest tombstones afterward. A retry never creates a new ID or re-signs with an old key.
- ACK IDs and receipt ID/digest/outcome bindings are durably deduplicated. Receipt bytes may be pruned only under §8 after the correctly bound ACK v2 record is durable and the separate receipt-retention condition completes; a digest/outcome/work tombstone remains for the same audit-retention period.
- HMR request IDs, platform delivery IDs, broker host_message_ids and monotonic sequence high-water marks are retained durably per relay identity and origin namespace; retry reuses the same ID and exact bytes. A lost poll response reuses delivery_id for platform-originated work; a lost submit response reuses host_message_id for broker-originated work.

The implementation PR MUST bind `restore-case audit retention` to an explicit platform retention policy before release. If the retention deadline is not available or conflicts with unresolved state, keep replay tombstones and receipts; do not invent a shorter local TTL.

### 12.4 Clock policy

First acceptance lifetime is at most 120 seconds. Maximum permitted clock difference is 30 seconds from the trusted platform/host time source; values outside the bound yield `CLOCK_UNSAFE`. The broker tracks last trusted wall-clock sample, monotonic time and boot identity. A backward wall-clock movement beyond 30 seconds, untrusted time after reboot, or inconsistent issued/expiry order blocks new intent acceptance and all transitions that require a fresh expiry decision. It does not expire an already accepted work item or open a gate. Recovery requires trusted time re-established and journal reconciliation; no message is accepted by relaxing skew.

### 12.5 Canonicalization and signature failure behavior

Every verifier checks exact schema/version, canonical bytes, outer/payload key-ID equality where specified, signer registration and host/provider ownership, purpose, key status/validity and rotation policy, signature, then issuer/audience and all durable bindings before state change. Signature and digest comparison are constant-time where applicable. Signature failure, unknown key, invalid encoding, duplicate key, unknown field, expired message, or clock uncertainty causes no work creation, no state success, no ACK/pruning and no gate release. Error responses are bounded and avoid disclosing which secret/key material failed.

## 13. Failure, retry and fencing behavior

| Condition | Required behavior |
|---|---|
| Provider absent/unreviewed | Platform and Connector refuse before live side effects; stable `RESTORE_ISOLATION_UNAVAILABLE` |
| Incomplete transfer | Keep non-executable staging; resume same work or retain for bounded audited cleanup while lease/ownership is proven |
| Lost Connector lease / broker channel | Stop before next mutation; no provider release or data cleanup without fresh proven ownership |
| Fence/quiescence timeout | No worker launch; keep/restore deny controls and reconcile; no assumption that timeout means clean |
| Worker crash before mutation | Safe abort only with durable proof no mutation-start/write occurred |
| Worker crash during/after mutation | RECOVERY_REQUIRED, durable marker and gate remain; separate approval needed |
| HMR/network outage | Persist and retry identical envelopes; site remains fenced; no Connector fallback if fenced |
| Invalid Recovery Intent | No recovery work, no worker, no unlock; preserve trigger receipt and gate |
| Receipt delivery failure | Durable exact receipt remains in outbox; no regenerated ID |
| HWA/Work Acceptance Confirmation delivery loss | Durable HWA/confirmation bytes and one work remain pending; exact retry resumes; no object staging/activation before broker confirmation |
| ACK failure/loss | Durable receipt remains; Laravel returns same ACK after retry once processing was committed |
| Ambiguous mapping/journal corruption | No state transition or pruning; fence and require audited reconciliation |
| Duplicate exact request | Idempotent response tied to same work |
| Same ID/different digest | Conflict; deny and audit; no new work |

No destructive compensation is automatic. Recovery Intent is distinct and does not authorize unrelated backup writes, customer notifications, remediation, quarantine, plugin deactivation, or deletion. Those require their existing independent controls.

## 14. Conformance and acceptance test matrix

All tests below are mandatory for a future protocol/provider implementation. They are deterministic synthetic tests first; host-specific network, cgroup, filesystem and sibling-site claims also require an isolated disposable host test profile. Every negative test must assert both the returned denial and the absence of unauthorized durable state change.

Rotation-inventory digest fixture `ROTINV-FIXTURE-01` is normative and test-only. Construct the payload with the exact closed §12.2 schema. Fixed values are:

```json
{
  "schema": "wp-caregrid.platform-key-rotation-inventory.v1",
  "iss": "wpcaregrid-platform-key-control",
  "aud": "registered-host-broker",
  "control_key_id": "test-control-k1",
  "provider_id": "00000000-0000-4000-8000-000000000001",
  "host_id": "00000000-0000-4000-8000-000000000002",
  "rotation_id": "00000000-0000-4000-8000-000000000003",
  "from_key_id": "test-k1",
  "to_key_id": "test-k2",
  "snapshot_sequence": 7,
  "created_at": "2026-01-02T03:04:05Z",
  "signing_cutoff": "2026-01-02T03:05:00Z",
  "approved_overlap_end": "2026-01-02T03:10:00Z",
  "outstanding_confirmations": [
    {
      "confirmation_id": "00000000-0000-4000-8000-000000000007",
      "pwac_envelope_sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
      "hwa_envelope_sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
      "acceptance_id": "00000000-0000-4000-8000-000000000008",
      "work_id": "00000000-0000-4000-8000-000000000009",
      "work_kind": "RESTORE",
      "authorization": {
        "schema": "wp-caregrid.restore-work-binding.v1",
        "restore_intent_id": "00000000-0000-4000-8000-000000000010",
        "restore_intent_envelope_sha256": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
        "backup_uuid": "00000000-0000-4000-8000-000000000013",
        "manifest_sha256": "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd",
        "scope": "files"
      },
      "tenant_id": "00000000-0000-4000-8000-000000000005",
      "site_id": "00000000-0000-4000-8000-000000000004",
      "connector_id": "00000000-0000-4000-8000-000000000006",
      "provider_id": "00000000-0000-4000-8000-000000000001",
      "host_id": "00000000-0000-4000-8000-000000000002",
      "confirmation_iss": "wpcaregrid-platform",
      "confirmation_aud": "registered-host-broker",
      "signing_purpose": "PLATFORM_WORK_ACCEPTANCE_CONFIRMATION",
      "key_id": "test-k1",
      "key_valid_from": "2025-12-01T00:00:00Z",
      "key_valid_until": "2026-02-01T00:00:00Z",
      "first_issued_at": "2026-01-02T03:04:00Z",
      "inventory_sequence": 7,
      "rotation_id": "00000000-0000-4000-8000-000000000003",
      "signing_cutoff": "2026-01-02T03:05:00Z",
      "verify_until": "2026-01-02T03:10:00Z"
    }
  ]
}
```

Use the test-only Ed25519 seed `9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60`; derive its public key and sign JCS(payload) using `WPCAREGRID-PLATFORM-KEY-ROTATION-INVENTORY-V1` plus NUL. Set the outer envelope to the §3.1 `wp-caregrid.signed-message.v1` schema, `alg=Ed25519`, `key_id=test-control-k1`, and the deterministic signature; serialize the full envelope as JCS with no newline. Compute `snapshot_envelope_sha256` only over those complete serialized envelope bytes. A conforming implementation test MUST record this fixture's resulting digest as a literal golden value and assert producer and broker both match it; the contract document does not claim that this vector has been executed. A fixture mutation changes one item field (for example `scope=database`) while retaining the old signature: the changed payload's envelope digest differs and signature verification fails. A separately validly re-signed envelope with the same sequence and rotation ID but a different digest is a journal conflict; exact byte-identical replay is idempotent. Noncanonical bytes or invalid signature create no cutoff evidence. The seed is test-only and MUST never be used outside conformance fixtures.

| ID | Scenario / fixture | Required expected state/result | Forbidden assertion |
|---|---|---|---|
| MSG-01 | Valid Restore Intent v1, correct signature and bindings | One durable `ACCEPTANCE_PENDING_PLATFORM` work/HWA outbox; after exact HWA is durably recorded by Laravel and its signed Work Acceptance Confirmation is committed by the broker, one `ACCEPTED` work | No object staging, fence, worker, mutation or generation disclosure before broker commits confirmation |
| MSG-02 | Duplicate key, unknown field, wrong canonical bytes, bad UTF-8 | `INVALID_ENVELOPE` or `UNSUPPORTED_SCHEMA`; no work | No parser normalization then acceptance |
| MSG-03 | Wrong domain, signature, algorithm, key purpose or key ID | Deny; journal unchanged | No downgrade/alternate algorithm |
| MSG-04 | Expired intent, >120 s lifetime, >30 s skew, clock rollback | `MESSAGE_EXPIRED`/`CLOCK_UNSAFE`; no acceptance | No gate open/closed change from unauthenticated request |
| MSG-05 | Same intent ID/nonce and exact bytes retried | Byte-identical same acceptance/work | No second work |
| MSG-06 | Same intent ID or nonce with changed bytes | `NONCE_REPLAY`/`WORK_CONFLICT`; original work preserved | No second restore job |
| AUTH-01 | Wrong tenant/site/Connector/provider/host/audience | Deny before staging or mutation | No cross-tenant/site read or write |
| AUTH-02 | Wrong restore/job/backup/manifest/scope | Deny; no accepted work | No substitute backup or broadened scope |
| AUTH-03 | Stale/revoked Connector or provider registration | Deny and audit | No readiness snapshot treated as active lease |
| HWA-01 | Broker commits recovery work as ACCEPTANCE_PENDING_PLATFORM, signs HWA and host outbox row, then crashes before returning response | Recovery Intent retry returns byte-identical HWA, acceptance_id, recovery_work_id and host_message_id from durable journal | No second work or generation; no worker activation, gate change or PHP executor fallback before confirmation |
| HWA-02 | Broker crashes before HWA/outbox transaction is durable | No HWA/host_message_id is exposed; exact Recovery Intent retry may create one `ACCEPTANCE_PENDING_PLATFORM` work | No phantom platform acceptance, worker activation or mutation |
| HWA-03 | Valid broker-signed HWA; durable platform recovery authorization exists for the exact Recovery Intent ID/digest and tuple | Persist exact HWA acceptance; return `ACCEPTANCE_RECORDED` with the same durable Work Acceptance Confirmation; broker remains `ACCEPTANCE_PENDING_PLATFORM` until that confirmation is verified and committed | No Receipt ACK v2, terminal state, worker activation, second work or gate release before confirmation |
| HWA-04 | Platform response is lost after HWA durable commit; resend same host_message_id and exact bytes | Return same semantic `ACCEPTANCE_RECORDED` and byte-identical confirmation; exactly one platform acceptance record | No duplicate authorization row, confirmation, recovery_work_id or activation |
| HWA-05 | Same host_message_id with changed HWA digest, work, site, tenant, Recovery Intent digest or recovery_work_id | DENIED/HOST_MESSAGE_CONFLICT; platform state unchanged and broker outbox retained | No acceptance, ACK, gate release or outbox deletion |
| HWA-06 | Broker/HMR/network crash before/after HWA commit, during transfer, after platform HWA-acceptance commit, during confirmation relay, or after broker confirmation commit but before response | Before HWA commit: no exposed HWA/work; after HWA commit: exact HWA is retrievable and pending; after platform commit: same confirmation is returned; after broker confirmation commit: exact retry is idempotently accepted | No phantom or duplicate work, rebinding, worker activation before durable confirmation, Receipt ACK for HWA, or gate change |
| RHA-01 | Laravel issues and persists one Restore Intent; broker accepts it and signs RESTORE HWA; Laravel receives exact HWA | One original restore/job row transitions to ACCEPTANCE_RECORDED with exact intent/HWA/acceptance/work/backup/manifest/scope/site tuple; same PWAC is durably issued | No broker object staging, fence, worker, restore-success state, Receipt ACK or gate change before broker stores PWAC |
| RHA-02 | Valid-looking HWA has forged signature, unknown key, wrong purpose, or wrong acceptance signing key | Reject; original authorization/restore/job and broker work remain unchanged/pending | No acceptance record, PWAC, object transfer, ACK or activation |
| RHA-03 | HWA has wrong/missing intent ID or digest, restore/job, backup/manifest/scope, tenant/site/Connector/provider/host, acceptance ID or work ID | Reject before state mutation; preserve exact broker HWA outbox | No success state, PWAC, cross-site mapping, Receipt ACK or gate change |
| RHA-04 | Exact same RESTORE HWA resubmitted after committed Laravel response was lost | Return ALREADY_RECORDED and byte-identical PWAC for the unique original restore/job mapping | No second acceptance row, confirmation ID, broker work, or mutation |
| RHA-05 | Same acceptance_id or work_id re-submitted with changed HWA digest or a different intent/restore/job/site | ACCEPTANCE_BINDING_MISMATCH/WORK_CONFLICT; preserve original row/outbox and broker state | No remapping, second active work, success, ACK, or gate release |
| RHA-06 | Broker commits RESTORE HWA/outbox then Connector loses begin response or crashes | Exact begin retry/read_work_acceptance returns same HWA bytes, acceptance_id, work_id and digest | No duplicate work/generation; no activation before PWAC |
| RHA-07 | Laravel commits acceptance and PWAC but API response is lost; Connector resubmits exact HWA | Same durable acceptance and byte-identical PWAC are returned; broker accepts exact PWAC once | No duplicate row/confirmation, no terminal success or gate release |
| RHA-08 | Crash at each boundary: before broker HWA commit; after HWA commit; during HWA transfer; after Laravel acceptance commit; during PWAC delivery; after broker PWAC commit before response | Before durable HWA: no work exposed; after HWA: exact row retrievable; after Laravel commit: same PWAC recoverable; after broker confirmation commit: exact idempotent confirmation result | No phantom work, duplicate, premature object staging/fence/worker, ACK or gate change |
| RHA-09 | Valid-looking Terminal Receipt arrives before HWA acceptance or without a unique matching RESTORE acceptance | ACCEPTANCE_NOT_FOUND/pending; no restore/job success transition; receipt retained for exact retry after HWA | No positive ACK v2, receipt prune, gate release or guessed mapping |
| RHA-10 | RECOVERY HWA is submitted to RESTORE acceptance endpoint or RESTORE HWA to recovery route | Strict kind/schema mismatch denial; both durable authorization records unchanged | No cross-lifecycle acceptance, ACK, work substitution or gate action |
| RKEY-01 | Receipt outer signature.key_id equals payload host_key_id; registered host/provider Terminal Receipt key active and valid; exact accepted work | Accept exact receipt if all other bindings hold; persist one outcome | No acceptance under another signing purpose |
| RKEY-02 | Outer signature.key_id differs from payload host_key_id (even if one signature verifies under either key) | Reject before durable transition | No success state, ACK v2, pruning or gate release |
| RKEY-03 | Cryptographically correct receipt signature uses HWA, ACK, intent, unknown or wrong-purpose key | Reject KEY_PURPOSE_MISMATCH/KEY_UNKNOWN; records unchanged | No receipt acceptance or positive ACK |
| RKEY-04 | Correctly signed receipt key is registered to a different provider/host or outside validity | Reject; preserve receipt/outbox and current platform state | No cross-host acceptance, ACK, pruning or gate release |
| RKEY-05 | Key is RETIRED_VERIFY_ONLY with exact receipt ID/digest pre-registered outstanding before cutoff; then REVOKED or COMPROMISED variant | Uncompromised retired exact outstanding receipt may verify under §12.2; revoked/compromised variants reject and require audited reconciliation | No new receipt accepted under retired key; no revoked/compromised acceptance, ACK, prune or gate release |
| RKEY-06 | Exact outstanding receipt under K1 arrives during approved K1/K2 overlap; new receipts use active K2 | Verify K1 only for exact pre-registered outstanding digest; K2 validates new receipts | No old key for new outcome; no loss/rebinding of outstanding receipt or replay state |

**PWAC negative-test invariant:** For every negative PWAC row below, assert no object staging, no fence transition/acquisition/release, no worker launch, no restore/recovery mutation, no Terminal Receipt ACK, no unauthorized gate release, no HWA/receipt pruning, no duplicate work, and no inconsistent broker-private generation mapping. Each row's expected durable result is authoritative; test harness state alone is insufficient.

| PWAC-01 | Initial: broker has durably committed exact signed RESTORE HWA bytes/digest to its outbox before exposure; Laravel has the exact authorized Restore Intent. Input: valid dedicated-purpose PWAC bound to exact HWA bytes and fields | Laravel acceptance/PWAC/inventory commit is durable; broker records the exact PWAC and changes only that RESTORE work to `ACCEPTED` | No second work or premature staging/fence/worker/mutation; HWA bytes are not pruned before exact PWAC durable broker confirmation AND configured HWA retention completes; terminal receipt bytes are not pruned before exact bound ACK v2 is durable AND separate receipt retention completes; preserve both required identity/digest tombstones; PWAC is not ACK v2 |
| PWAC-02 | Initial: same pending RESTORE HWA. Input: forged signature or modified confirmation bytes | Reject; broker work remains `ACCEPTANCE_PENDING_PLATFORM`; platform state unchanged | No acceptance, activation, staging, fence, worker, mutation, ACK, gate release or HWA/receipt pruning |
| PWAC-03 | Initial: pending HWA. Input: valid signature under unknown key, wrong key purpose/domain/algorithm, or unregistered confirmation key | Reject and audit stable error; no durable acceptance transition | No alternate algorithm/downgrade, work activation, mutation, ACK, gate release or pruning |
| PWAC-04 | Initial: pending HWA. Input: correct signature but wrong HWA digest, acceptance ID, work ID or work kind | Reject; retain original HWA/outbox and pending work | No remapping, second work, staging, fence, worker, mutation, ACK, gate release or pruning |
| PWAC-05 | Initial: pending RESTORE HWA. Input: confirmation with wrong/missing Restore Intent ID or exact envelope digest, restore UUID or job UUID | Reject; original restore/job and broker work remain unchanged/pending | No success mapping, work activation, staging, fence, worker, mutation, ACK, gate release or pruning |
| PWAC-06 | Initial: pending HWA. Input: confirmation with any wrong tenant/site/Connector/provider/host identity | Reject before durable transition; audit mismatch | No cross-tenant/site binding, second work, activation, mutation, ACK, gate release or pruning |
| PWAC-07 | Initial: pending RESTORE HWA. Input: wrong backup UUID, manifest digest or scope | Reject; exact signed authorization remains authoritative | No substituted input, broadened scope, staging, fence, worker, mutation, ACK, gate release or pruning |
| PWAC-08 | Initial: one valid RESTORE HWA and no confirmation. Input: byte-identical exact confirmation submitted repeatedly | One durable confirmation and one `ACCEPTED` RESTORE work; repeated response is idempotent | No duplicate acceptance/work, changed timestamp/bytes, repeated execution, extra mutation, ACK or gate release |
| PWAC-09 | Initial: confirmation ID already durably maps to one HWA. Input: same ID with changed bytes or HWA digest | Reject as confirmation conflict; preserve original mapping and work state | No overwrite/rebind, second work, activation, mutation, ACK, gate release or pruning |
| PWAC-10 | Initial: one pending work for exact tuple. Input: a second confirmation conflicts on acceptance ID/work ID or claims another intent/site | Reject as conflict/ambiguity; original row remains the sole mapping | No arbitrary selection, extra work/generation, staging, fence, worker, mutation, ACK or pruning |
| PWAC-11 | Initial: HWA is absent, not durably committed, or HWA acceptance row is not yet visible. Input: otherwise valid confirmation | Return typed NOT_READY/ACCEPTANCE_NOT_FOUND; no broker/platform confirmation commit | No implicit HWA creation, activation, staging, fence, worker, mutation, ACK, gate release or pruning |
| PWAC-12 | Initial: broker has durably committed HWA/outbox and pending work; response to begin/read acceptance is lost. Input: exact retry followed by same confirmation | Retrieve byte-identical HWA; one durable confirmation advances only its matching work | No duplicate work/HWA, new generation, premature activation, mutation, ACK or gate release |
| PWAC-13 | Initial: Laravel has not committed acceptance. Input: crash before acceptance transaction commits, then exact HWA retry | No visible partial acceptance/PWAC before commit; retry can create exactly one complete acceptance and stable PWAC | No partial row, guessed acceptance, broker activation, staging, fence, worker, mutation, ACK or pruning |
| PWAC-14 | Initial: Laravel acceptance row and exact PWAC outbox are committed, but API response is lost. Input: exact HWA retransmission | Return same durable acceptance and byte-identical PWAC; broker accepts it idempotently | No duplicate authorization/confirmation, second work, mutation, ACK or gate release |
| PWAC-15 | Initial: broker HWA/outbox transaction is committed but response is lost or process crashes before reply. Input: retry through the same registered route | Same HWA, acceptance ID, work ID, host message ID and digest remain retrievable; work stays pending until PWAC commit | No phantom/discarded work, regenerated IDs, second generation, staging, fence, worker, mutation, ACK or release |
| PWAC-16 | Initial: valid PWAC is submitted after its exact work has already reached a terminal/conflicting state or after policy expiry. Input: late confirmation | Reject or return exact already-recorded result only when durable record proves it was previously committed; otherwise state unchanged | No late activation, resurrection, new mutation, ACK, gate release or pruning |
| PWAC-17 | Initial: one RECOVERY_REQUIRED parent remains fenced; distinct recovery child is pending. Input: valid RECOVERY PWAC for that child | Only child changes `ACCEPTANCE_PENDING_PLATFORM → RECOVERY_AUTHORIZED`; parent stays `RECOVERY_REQUIRED`/fenced | No parent-state advance, automatic recovery launch, parent/child conflation, ACK, gate release or pruning |
| PWAC-18 | Initial: pending RESTORE work. Input: valid-looking RECOVERY PWAC, or pending recovery child with a RESTORE PWAC | Reject strict work-kind/schema mismatch; both authorization records unchanged | No cross-lifecycle acceptance, work substitution, staging, worker, mutation, ACK, release or pruning |
| PWAC-19 | Initial: K1 is ACTIVE; one exact PWAC and its full immutable inventory item are committed by Laravel, included in an authenticated monotonic rotation snapshot, and that snapshot is durably verified/fsynced by the broker before K1 cutoff. Input: exact same PWAC after K1 becomes OVERLAP_VERIFY_ONLY; new confirmations are K2-signed | Accept only the byte-identical inventory-matched PWAC during its approved overlap and before `verify_until`; broker records the same confirmation idempotently against the exact HWA/acceptance/work/generation mapping | No new K1 signature, timestamp-based eligibility, changed digest/binding, second work, mutation, ACK, release or pruning |
| PWAC-20 | Initial: K1 is verify-only; there is no broker-committed pre-cutoff inventory item, or the only evidence is PWAC `confirmed_at`/late-arrival data. Input: K1 PWAC claims it was issued before cutoff | Reject; work remains pending and fenced; audited reconciliation is required | No self-asserted cutoff proof, acceptance, activation, mutation, ACK, release or pruning |
| PWAC-21 | Initial: a matching PWAC item exists in the pre-cutoff inventory, but its key is REVOKED or COMPROMISED at verification. Input: exact otherwise valid PWAC | Reject regardless of inventory/signature time; preserve pending HWA/work and require audited reconciliation/key response | No verify-only exception, acceptance, fallback key, activation, mutation, ACK, gate release or pruning |
| PWAC-22 | Initial: K1/K2 rotation snapshot or monotonic inventory journal is missing, corrupt, sequence-gapped, rolled back, or the broker crashed before its pre-cutoff snapshot commit. Input: exact retry after restart | Fail closed; no eligibility inferred; retain pending work; reconcile only from authenticated durable rotation records | No timestamp substitute, old-key acceptance, pending-work activation, mutation, ACK, release or pruning |
| PWAC-23 | Initial: durable HWA exists but confirmation references a private generation mapping with zero, multiple, wrong-work or wrong-parent matches. Input: otherwise valid signed PWAC | Reject as mapping corruption/ambiguity; preserve pending state and fence | No caller-supplied generation repair, selected mapping, extra work, activation, mutation, ACK, release or pruning |
| PWAC-24 | Initial: RECOVERY_REQUIRED parent and one pending child; confirmation is missing, invalid, expired before first valid acceptance, or arrives out of order relative to HWA persistence. Input: receipt/confirmation retry | Parent stays `RECOVERY_REQUIRED`/fenced; child stays pending or denied; exact HWA/receipt remain retained | No child activation, parent transition, success state, Terminal Receipt ACK, gate release or HWA/receipt pruning |
| PWAC-25 | Initial: K1 is verify-only and the broker has no exact pre-cutoff committed inventory record. Input: late PWAC has a valid K1 signature and a `confirmed_at` earlier than cutoff | Reject; claimed timestamp cannot establish that the exact envelope existed before cutoff | No acceptance, activation, mutation, ACK, gate release or pruning |
| PWAC-26 | Initial: exact PWAC was eligible in a pre-cutoff snapshot, but its approved `verify_until` has passed while work is unresolved. Input: exact old-key retry | Reject and retain pending HWA/work/fence for audited reconciliation; do not extend overlap based on the retry | No expired verify-only acceptance, new K1 signature, activation, mutation, ACK, release or pruning |
| PWAC-27 | Initial: exact HWA and PWAC are durable, broker has committed the exact confirmation, but configured HWA retention has not elapsed. Input: retention/pruning attempt | Keep HWA bytes and required identity/digest tombstone; prune only after retention completes | No early HWA pruning, lost retry evidence, terminal receipt pruning, gate release or work mutation |
| HMR-06 | Host-originated message submitted with missing/forged host_message_id or a platform delivery_id substituted | Reject unless ID/digest/kind/host/relay/work matches exact broker outbox record | No cross-namespace replay or message acceptance |
| OBJ-01 | Interrupted fixed-object transfer | Work remains STAGING; resume exact offsets/digests | No execution of partial object |
| OBJ-02 | Duplicate chunk, conflicting offset/content, bad final digest | Exact duplicate idempotent; conflict/mismatch denied | No overwrite of accepted immutable object |
| OBJ-03 | Oversized object, path traversal filename, unexpected object enum | Deny and keep non-executable staging | No caller-selected filesystem path |
| FENCE-01 | CDN/cache/direct-origin bypass still reachable | Fence incomplete; no worker | No claim of successful acquire |
| FENCE-02 | In-flight HTTP/FPM writer or child process remains | Remain FENCING/FENCED; no worker | No kill-and-assume-quiescent |
| FENCE-03 | Cron, Action Scheduler, queue, CLI, updater, deploy, DB or filesystem writer not fenced | Deny transition to QUIESCENT | No advisory flock accepted as proof |
| GEN-01 | Broker crashes before private active-generation/work mapping is durable | No worker starts; old broker gate state remains authoritative; restart reconciles or stays fenced | No Connector marker publication containing generation/lease; no live mutation |
| GEN-02 | Broker crashes after private generation transition but before worker activation commit | Broker recovers exact private mapping, keeps site fenced, and idempotently resumes or denies the same work | No Connector-visible generation/lease; no PHP-side lease or restore fallback |
| GEN-03 | Missing, wrong, stale, duplicate or ambiguous private generation mapping | Reject assertion/ACK/finalization; preserve receipt and fence | No public generation field or caller-supplied repair |
| APPLY-01 | Worker-private broker check denies exact work before DB swap | Worker stops before swap; live DB and staged recovery evidence remain unchanged; site stays fenced | No SQL mutation or Connector-side swap |
| APPLY-02 | Worker-private broker check denies at each file create/write/rename boundary | Stop before the denied filesystem operation; preserve recovery artifacts and fence | No next file mutation, PHP fallback or cleanup after denial |
| OUT-01 | Valid RESTORE_SUCCESS receipt and ACK v2 | One platform success; exact broker mapping ACKed | No ACK-triggered gate release |
| OUT-02 | Valid SAFE_PRE_MUTATION_ABORT with proof and no mutation record | Original restore terminal; safe release after reconciliation | No safe abort after mutation-start |
| OUT-03 | RECOVERY_REQUIRED on original restore | Platform records incident/state; broker remains fenced | No automatic recovery or gate release |
| OUT-04 | RECOVERY_ATTEMPT fails before/after mutation | Nested failed_recovery exact binding; remain RECOVERY_REQUIRED | No relabel as original SAFE_PRE_MUTATION_ABORT |
| REC-01 | Valid, separately approved Recovery Intent for current exact trigger | One recovery_work_id; accepted authorization durable | No recovery from receipt alone |
| REC-02 | Missing/wrong Recovery Intent ID or signed-envelope digest | Laravel/broker reject; no work or ACK | No success state, gate release or pruning |
| REC-03 | Wrong original restore/work/job or trigger receipt ID/digest | Reject; original chain remains fenced | No mapping to another restore |
| REC-04 | Wrong safety backup UUID/manifest or recovery scope | Reject before worker | No fallback backup or broadened operation |
| REC-05 | Wrong tenant/site/Connector/provider/host, expired/revoked key, replayed nonce | Reject; no work | No site cross-binding |
| REC-06 | Two recovery attempts, active/ambiguous prior attempt, or multiple matching work rows | Reject as conflict/ambiguous; preserve state | No arbitrarily selected recovery work |
| REC-07 | Recovery crash at every durable transition | Resume same work if safe; otherwise RECOVERY_REQUIRED fenced | No duplicate destructive recovery |
| RCPT-01 | Missing/invalid/old/outcome-mismatched Terminal Receipt | Laravel rejects; no state success or ACK | No ACK v1 fallback |
| RCPT-02 | RECOVERY_SUCCESS fields mismatch durable authorization or actual safety backup | No platform success or positive ACK | No gate release/pruning |
| RCPT-03 | Repeated identical receipt | Same idempotent platform result/ACK | No duplicate transition |
| RCPT-04 | Reused receipt ID with changed digest/result or stale receipt | Reject and audit | No work rebinding |
| ACK-01 | ACK v2 exact tuple and result binding; configured terminal-receipt retention condition has elapsed | Broker durably records exact ACK ID/digest and binding before pruning receipt bytes; retain receipt replay tombstone | No pruning before both durable ACK acceptance and separate retention completion |
| ACK-02 | ACK v1, wrong key, wrong receipt digest/outcome/result binding | Reject; retain receipt | No compatibility fallback |
| ACK-03 | ACK for another restore/recovery work, wrong backup/scope, wrong private generation | Reject; retain receipt and gate state | No ACK acceptance/prune |
| ACK-04 | Duplicate exact ACK or lost response after broker persist | Idempotent success; receipt state reflects durable ACK | No second transition |
| ACK-05 | ACK persistence crash before commit | Receipt retained; retry exact ACK | No speculative prune |
| ACK-06 | Initial: exact ACK v2 is valid and durably recorded, but separate receipt retention has not elapsed. Input: receipt-pruning attempt before deadline, then retry after deadline | Retain receipt bytes until the configured retention condition completes; afterward prune only bytes while preserving receipt replay tombstone | No early prune at ACK commit, ACK rebinding, tombstone loss or gate release |
| HMR-01 | WordPress, PHP-FPM and Connector stopped; site fenced | HMR delivers signed RECOVERY_REQUIRED receipt to Laravel and routes ACK back | No dependence on site runtime |
| HMR-02 | HMR/network outage before/during receipt, intent, recovery or ACK delivery | Durable retry; fenced state preserved | No auto-unlock, deletion or Connector fallback |
| HMR-03 | Wrong/revoked/expired relay certificate, wrong host/site or sequence rollback | Channel denied; broker state unchanged | No other-host payload accepted |
| HMR-04 | Duplicate/reordered delivery; same ID different digest | Exact duplicate idempotent; conflict rejected | No message replacement or loss |
| HMR-05 | HMR crashes after reading HWA/receipt, during submit, or after platform commit but before local journal commit | Reconnect replays same host_message_id, kind and digest; platform returns same ACCEPTANCE_RECORDED or ACK_READY result | No new delivery ID for host data, no duplicate recovery work, no ACK for HWA |
| BOOT-01 | Host reboot at each state transition | Boot closed; broker reconciles journal before site services; HMR reconnects later | No services exposed before fence reconciliation |
| BOOT-02 | Corrupt/missing broker journal, key or receipt index | Stay fenced; operator escalation | No guessed recovery or TTL unlock |
| ISO-01 | Two sites share host; target fence/recovery runs | Sibling remains healthy/writable and cannot address target broker data | No shared UID/volume/DB credential escape |
| MAN-01 | Manual console fallback with explicit Owner/operator authorization | Fixed typed interface, exact signed envelopes, dual audit and Laravel reconcile | No ad hoc shell/SQL/marker deletion |
| MAN-02 | Missing approval, invalid receipt/intent, incomplete audit or broker unavailable | Remain fenced; no destructive action | No console privilege treated as protocol authority |
| KROT-01 | Old key K1 active; K2 enrolled by authenticated approval with overlap window open; sign a new authorized intent with K2 | New message verifies only under K2 and one authorization is persisted; K1 remains verification-only for eligible outstanding items | No new authorization signed by K1 after switch |
| KROT-02 | Receipt and ACK retry were durably created under K1 before rotation; submit exact outstanding bytes during approved overlap | Verify with retained K1 public key, preserve exact receipt/ACK/work mapping and complete idempotently | No loss of outstanding receipt or replay tombstone |
| KROT-03 | K1 retired after no outstanding K1 items remain; attempt fresh Restore/Recovery Intent signed by K1 | Reject KEY_RETIRED; no nonce reservation/work/state mutation | No old key accepted for new authorization |
| KROT-04 | K1 marked compromised/revoked while an unacknowledged receipt/work exists; replay old or newly signed messages | Reject new authorization; retain receipt/outbox/replay state and keep affected site fenced pending audited reconciliation | No silent acceptance under K2, no receipt pruning, no gate release |
| KROT-05 | HMR certificate C1 valid; approved same-host C2 renewal enters bounded overlap; reconnect using C2 | Accept only exact enrolled relay/provider/host; preserve durable cursor, host_message_id journal and sequence | No identity change or replay-state reset |
| KROT-06 | C1 overlap ends / is revoked; try C1, wrong-host certificate, unknown certificate and expired certificate | Reject channel processing; broker state/outbox/sequence remain durable | No cross-host access, sequence reset, ACK or pruning |
| KROT-07 | HMR certificate revoked during network outage with pending receipt/HWA | Channel stops; pending host message remains retrievable after separately approved valid re-enrollment | No message deletion, gate release or unauthorized new recovery |
| KROT-08 | HMR reconnects after cert rotation with duplicate/reordered pending messages | Exact same host_message_id/digest is idempotent; platform delivery cursor and host-message journal resume from durable state | No replay acceptance or outbox loss |
| KROT-09 | Broker/platform/HMR crash or reboot at each key/certificate rotation journal boundary, leaving status ambiguous | Fail closed; preserve old/new verification records and outstanding outbox; require audited reconciliation | No guessed active key, new work, replay reset, prune or gate release |
| KROT-10 | Deterministic test-only `ROTINV-FIXTURE-01`: construct the closed rotation-inventory payload using the exact §12.2 schema, fixed issuer/audience, provider UUID `00000000-0000-4000-8000-000000000001`, host UUID `00000000-0000-4000-8000-000000000002`, rotation UUID `00000000-0000-4000-8000-000000000003`, K1/K2 IDs `test-k1`/`test-k2`, sequence `7`, timestamps `2026-01-02T03:04:05Z`, `2026-01-02T03:05:00Z`, `2026-01-02T03:10:00Z`, and one PWAC-19-shaped item with fixed UUIDs/digests; sign with a test-only Ed25519 key derived from seed `9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60`, then JCS-serialize the full outer envelope per §3.1 | Producer and broker compute identical SHA-256 over those exact envelope bytes; payload has no snapshot self-hash; changed item list changes envelope digest and the old signature fails; same sequence/rotation with a different valid envelope digest is CONFLICT; byte-identical replay is idempotent; noncanonical or invalid-signature input creates no durable cutoff evidence | No sender-selected digest, alternate payload-hash interpretation, acceptance of altered inventory, cutoff commit before validation/durable journal commit, or implementation-test claim from this normative fixture alone |
| LOG-01 | All negative and positive flows | Logs contain bounded IDs/digests/result codes only | No key, token, signature, nonce, artifact content or generation logged |

Required test evidence records exact broker/platform states, durable records before/after, fault-injection point, assertion that no forbidden side effect occurred, and profile/runtime versions. Unit mocks are necessary but are not host compatibility evidence.

## 15. Cross-message field-source and consistency ledger

| Value | Signed Restore Intent | Recovery Intent | Terminal Receipt | ACK v2 | Durable source / rule |
|---|---|---|---|---|---|
| Tenant/site/Connector/provider/host | Exact tuple | Same tuple | Common invariant tuple | Same common tuple | Laravel registration and broker fixed site map must agree |
| Original restore/job | `restore_uuid`/`job_uuid` | `original_restore_uuid`/`original_job_uuid` | Common invariant tuple | Same common tuple | Laravel restore/job record and broker accepted restore work |
| Original intent/work | `intent_id` and accepted work from broker | `original_restore_intent_id` | Outcome-specific original fields | Mirrors receipt result | Durable original authorization plus broker accepted-work mapping |
| Recovery trigger receipt | Not applicable | Exact ID + envelope digest | RECOVERY_SUCCESS and failed-recovery nested fields | Mirrors receipt result | Verified durable RECOVERY_REQUIRED receipt and broker outbox |
| Recovery intent | Not applicable | Recovery ID; exact signed-envelope digest stored after signing | RECOVERY_SUCCESS / failed_recovery result ID + digest | Same result binding | Laravel authorization record and broker accepted recovery-work record |
| Recovery work | Not applicable | Broker assigns only upon durable acceptance; not caller-selected | Signed receipt carries unique recovery_work_id | ACK mirrors signed receipt after Laravel stores acceptance | Broker record plus platform-persisted acceptance correlation |
| Backup/manifest/scope | Original backup and scope | Safety backup, manifest and recovery scope | Strictly outcome-specific fields | Exact mirror of result | Immutable platform backup record and broker-verified manifest |
| Gate generation | Never | Never | Never | Never | Broker-private mapping only; not serialized or logged |
| Receipt / ACK digest | Not applicable | Trigger receipt exact-envelope digest | Host receipt ID and envelope digest computed by receiver | Exact current receipt ID/digest | Hash exact canonical signed-envelope bytes |
| Platform delivery ID | Not applicable | Not applicable | Not embedded in broker receipt/HWA | Not embedded in ACK | Laravel durable delivery row only; never identifies host-originated messages |
| Host message ID | Not applicable | Not a signed-intent field | Not embedded in signed receipt/HWA | Not embedded in ACK | Broker durable outbox row binds host_message_id to kind, host/relay, nested digest, receipt_id or acceptance_id and work; HMR retries same row |

### RESTORE/RECOVERY HWA acceptance and confirmation source ledger

| Value | Normal RESTORE source/binding | RECOVERY source/binding | Creator/verifier and durable state |
|---|---|---|---|
| Signed Restore Intent ID and envelope digest | Laravel durable restore/job authorization; exact canonical outer-envelope bytes | Parent Restore Intent plus separately approved Recovery Intent ID/digest | Laravel signs and stores before dispatch; broker reserves one exact intent/work |
| HWA `acceptance_id` / `work_id` | Broker durable RESTORE work and HWA outbox | Broker durable recovery child work and HWA outbox | Broker signs after durable commit; Laravel verifies against exactly one matching intent/authorization |
| HWA authorization tuple | Exact restore intent, restore/job, backup/manifest/scope, tenant/site/Connector/provider/host | Exact parent restore/job, trigger receipt, Recovery Intent, recovery work, safety backup/manifest/scope, same identity tuple | Broker derives from verified signed intent, host map and durable work; Laravel independently compares |
| Platform Work Acceptance Confirmation | HWA exact envelope digest and normal RESTORE tuple | HWA exact envelope digest and recovery authorization tuple | Laravel signs only after acceptance row commit; broker verifies against its HWA/outbox/private work record and persists before activation |
| Platform acceptance idempotency | (site_id, restore_uuid, job_uuid, RESTORE, restore_intent_id, exact intent digest); acceptance_id and work_id unique | Exact recovery authorization row keyed by recovery intent ID/digest and exact trigger/parent; acceptance_id and recovery_work_id unique | Same bytes return same durable confirmation; any conflicting mapping denies without mutation |
| Broker activation authority | Durable Work Acceptance Confirmation plus complete verified immutable input; broker then controls fence/generation | Same confirmation requirement; separate Recovery Intent still required; site stays fenced | Broker-private journal only; Laravel/Connector/HMR confirmation does not itself release a gate |
| HWA / confirmation retry source | Broker HWA outbox/read_work_acceptance; Laravel durable acceptance/confirmation row; Connector outbox | Broker HWA outbox/cursor; Laravel recovery authorization/confirmation row; HMR journal | Exact bytes/digest survive lost response and process/network crash; retries never allocate new work |



Every API operation MUST follow this ledger. In the broker state machine, `ACCEPTANCE_PENDING_PLATFORM` is the shared pending state for RESTORE and RECOVERY child work; `work_kind` plus the strict authorization union determines which confirmation transition applies. Platform `ACCEPTANCE_RECORDED` is separate workflow bookkeeping. A recovery acceptance never advances its original parent from `RECOVERY_REQUIRED` or releases its fence. Normal RESTORE is: Laravel persists and signs Restore Intent → Connector/agent submits exact bytes to begin_restore_handoff.v1 → broker durably records work and RESTORE HWA/outbox in ACCEPTANCE_PENDING_PLATFORM → Connector reads/relays unchanged HWA to the dedicated Laravel acceptance API → Laravel verifies and commits the exact HWA to the original restore/job, then returns a stable signed Work Acceptance Confirmation → Connector relays exact confirmation to the broker → broker durably confirms before allowing object staging/activation → isolated worker executes → broker persists Terminal Receipt v2 → Laravel reconciles only against the accepted work and signs ACK v2 → broker records ACK, then applies separate gate policy. Lost responses repeat identical IDs and bytes; out-of-order receipt before HWA acceptance has no success transition or ACK. Closed-site RECOVERY keeps its HMR route: HMR submits recovery HWA to Laravel, receives ACCEPTANCE_RECORDED plus the signed confirmation (never Receipt ACK), and relays it to the broker. In both flows the confirmation only proves durable platform acceptance and cannot release the gate; Receipt ACK v2 is terminal-only. Platform-originated Recovery Intent uses the separate delivery_id namespace. The broker's private generation lookup remains internal.


### 15.1 Complete payload-field provenance registry

The following registry closes the field-level source gap for each wire payload. “Producer → verifier” identifies the party that creates the value and the party that must validate it. A field inherited from a common signed envelope remains subject to the envelope rules in §3. Field names not present in a schema are forbidden; tables below are not permission to add extension fields.

#### Signed outer envelope

| Field | Type / source | Producer → verifier | Binding / validation |
|---|---|---|---|
| `schema` | Literal `wp-caregrid.signed-message.v1`; protocol registry | Signer → receiver | Exact literal; closed envelope version |
| `payload` | Object; schema-specific authoritative source in §§4–8 | Signer → receiver | JCS payload; validate its exact closed schema and all bindings |
| `signature.alg` | Literal `Ed25519`; protocol registry | Signer → receiver | No negotiation or fallback |
| `signature.key_id` | Registered key ID; signer key registry | Signer → receiver | Must resolve to correct issuer, purpose, owner, validity and key; equal payload `key_id` where defined |
| `signature.value` | Canonical unpadded base64url of 64 signature bytes | Signer → receiver | Verify over schema-specific domain separator, NUL, and JCS(payload) |

Restore Intent, Recovery Intent, HWA and Work Acceptance Confirmation fields have their individual source, producer/verifier, and binding rows in §§4–6 and in this registry. Those tables are normative and complete for each closed payload.

#### Host Work Acceptance v1

| Field | Type / source | Producer → verifier | Binding / validation |
|---|---|---|---|
| `schema`, `iss`, `aud` | Literal schema, registered broker issuer, platform audience | Broker → Laravel/authorized relay | Exact host/provider registration and direction |
| `acceptance_id` | UUID from broker CSPRNG/durable acceptance row | Broker → Laravel | Stable for the one exact accepted intent digest |
| `work_kind` | Enum `RESTORE` or `RECOVERY`; durable broker authorization | Broker → Laravel | Selects strict authorization union |
| `work_id` | UUID from durable broker work record | Broker → Laravel | Original restore work for RESTORE; exactly `recovery_work_id` for RECOVERY |
| `tenant_id`, `site_id`, `connector_id` | Immutable platform registration copied from verified intent and host map | Broker → Laravel | Must equal active platform tuple; never caller overrides |
| `provider_id`, `host_id` | Broker registration | Broker → Laravel | Exact registered installation and host |
| `restore_uuid`, `job_uuid` | Verified original Restore Intent and durable platform job | Broker → Laravel | Always the original restore/job, including recovery |
| `authorization` | Closed tagged union copied from verified intent and broker durable work record | Broker → Laravel | RESTORE or RECOVERY schema only; no extra fields |
| `authorization.schema` | Literal restore-work-binding or recovery-work-binding schema | Broker → Laravel | Must agree with `work_kind` |
| `authorization.restore_intent_id` | Verified Restore Intent | Broker → Laravel | RESTORE only; exact intent and acceptance digest mapping |
| `authorization.restore_intent_envelope_sha256` | Digest of exact canonical signed Restore Intent envelope | Broker → Laravel | RESTORE only; exact bytes accepted |
| `authorization.backup_uuid`, `manifest_sha256`, `scope` | Immutable platform backup record/verified manifest/approved scope | Broker → Laravel | RESTORE only; exact original authorization |
| `authorization.original_restore_intent_id` | Durable original restore authorization | Broker → Laravel | RECOVERY only; exact parent |
| `authorization.recovery_trigger_receipt_id`, `recovery_trigger_receipt_sha256` | Durable verified RECOVERY_REQUIRED receipt/outbox | Broker → Laravel | RECOVERY only; exact trigger ID and canonical envelope digest |
| `authorization.recovery_intent_id`, `recovery_intent_envelope_sha256` | Laravel durable separately approved authorization and exact received envelope | Broker → Laravel | RECOVERY only; exact approval and bytes |
| `authorization.recovery_work_id` | Broker CSPRNG/durable child work row | Broker → Laravel | RECOVERY only; equals top-level `work_id`; never caller-selected |
| `authorization.safety_backup_uuid`, `safety_manifest_sha256`, `recovery_scope` | Approved immutable safety-backup record, verified manifest and durable recovery approval | Broker → Laravel | RECOVERY only; exact source and bounded scope |
| `accepted_at` | Broker trusted clock | Broker → Laravel | UTC RFC3339; covered by host signature |
| `key_id` | Host acceptance signing-key registry | Broker → Laravel | Acceptance-purpose active/overlap key; not a generation |

#### Platform Work Acceptance Confirmation v1

| Field | Type / authoritative source | Producer → verifier | Binding / validation |
|---|---|---|---|
| `schema`, `iss`, `aud` | Literal schema, Laravel issuer, registered broker audience | Laravel → broker | Exact protocol version and direction |
| `confirmation_id` | UUID stored with the one committed platform acceptance row and immutable outstanding-confirmation item | Laravel → broker | Stable for exact HWA digest; unique per acceptance; item registered in the authenticated pre-cutoff rotation snapshot before a verify-only key can accept it |
| `acceptance_id`, `acceptance_sha256` | Verified broker HWA and exact canonical envelope bytes | Laravel → broker | Exact HWA and broker outbox row |
| `work_kind`, `work_id` | Verified HWA and durable platform authorization | Laravel → broker | Strict RESTORE/RECOVERY union and exact broker work |
| identity tuple and `restore_uuid/job_uuid` | Authenticated Connector pairing or HMR identity plus durable website/job records, checked against HWA | Laravel → broker | Exact tenant/site/Connector/provider/host and original restore/job |
| `authorization` | Closed union copied from verified HWA and durable authorization row | Laravel → broker | RESTORE binds intent ID/digest, backup/manifest/scope; RECOVERY binds parent/trigger/Recovery Intent/work/safety backup/manifest/scope |
| `confirmed_at`, `key_id` | Laravel trusted clock and dedicated confirmation-key registry | Laravel → broker | New PWAC signature requires ACTIVE_SIGN_VERIFY and purpose PLATFORM_WORK_ACCEPTANCE_CONFIRMATION; verification of old exact bytes may use only the §12.2 pre-cutoff inventory exception; no host generation |
| `signature` | Ed25519 over domain separator + NUL + JCS(payload) | Laravel → broker | Canonical bytes, exact purpose/key status; not ACK v2, not gate-release authority |

#### Outstanding PWAC rotation-inventory provenance

| Durable item | Authoritative source / producer → verifier | Exact binding and cutoff proof | Retention |
|---|---|---|---|
| Outstanding-confirmation item | Laravel acceptance transaction → platform key-lifecycle control plane and broker | Before any PWAC is exposed, commit confirmation ID, exact canonical signed PWAC SHA-256, exact HWA envelope digest and acceptance ID, work ID/kind, complete RESTORE/RECOVERY authorization union, restore/job IDs, tenant/site/Connector/provider/host tuple, issuer/audience, purpose/key ID, key validity, first-issued time and rotation sequence. Immutable; signing timestamp alone is not cutoff evidence. | Keep through pending/unresolved work, all approved retry windows, and restore-case audit retention; preserve digest tombstone after byte retirement. |
| Authenticated rotation inventory snapshot | Pre-enrolled `PLATFORM_KEY_ROTATION_CONTROL` key → broker key/rotation verifier | Closed `wp-caregrid.platform-key-rotation-inventory.v1` signed outer envelope; payload binds schema/issuer/audience/control-key ID, provider/host, K1→K2 IDs, monotonic sequence, creation time, cutoff, overlap end and exact item list/digests, and contains no self-referential snapshot hash. Broker verifies canonical envelope/signature/purpose/scope, computes `snapshot_envelope_sha256 = SHA-256(exact canonical serialized outer-envelope bytes including signature)`, and durably commits that value, sequence and exact inventory before cutoff is effective. The digest is broker journal metadata only. Only broker's pre-cutoff durable commit proves eligibility; late snapshots or sequence gaps/rollback fail closed. | Retain with key-rotation journal while any listed confirmation/HWA/work is unresolved and for audit retention thereafter. |
| Broker cutoff commit | Broker durable rotation journal → PWAC verifier | Exact broker-computed `snapshot_envelope_sha256`/sequence and trusted commit time precede cutoff; verifier also checks ACTIVE/verify-only key state, purpose, host/provider scope, exact item and private work mapping. No timestamp from a late PWAC can replace this record. | Never discard before referenced work/inventory tombstones expire under explicit retention. |
| `verify_until` | Authenticated approved rotation policy → broker | Per-item deadline is bounded by the approved overlap and retention policy; evaluated with trusted clock/monotonic journal. After deadline, exact retries fail closed and require audited reconciliation. | Retain deadline with its inventory item and rotation snapshot. |



#### Terminal Receipt v2 and ACK v2

The common receipt and ACK field types and source rules are fully specified in §§7–8. The table below makes the required origin and verification path explicit; outcome-result unions remain exactly those listed in §§7.2 and 8.

| Field group | Authoritative source / creator | Verifier | Exact binding |
|---|---|---|---|
| Receipt `schema`, `issuer`, `audience`, `host_key_id` | Protocol registry and host broker key registry; broker creates | Laravel | Exact v2 schema; `signature.key_id == payload.host_key_id`; key belongs to provider/host, Terminal Receipt signing purpose and allowed lifecycle status |
| Receipt outer `signature.key_id` | Host Terminal Receipt key registry | Broker signs; Laravel verifies | MUST equal `payload.host_key_id` byte-for-byte; registered provider/host and Terminal Receipt purpose/status checked before signature acceptance |
| Receipt `receipt_id` | Broker CSPRNG and durable receipt row | Laravel and broker on retries | One immutable outcome/work row; never reused with different digest |
| Receipt tenant/site/Connector/provider/host tuple | Broker's verified immutable registration and accepted work | Laravel | Exact original work's identity tuple |
| Receipt `restore_uuid`, `job_uuid` | Original accepted restore record | Laravel | Remain original IDs for all recovery outcomes |
| Receipt `outcome`, `result` | Broker derives from durable mutation/recovery evidence; worker cannot choose | Laravel | Strict discriminated union; exact accepted work and outcome invariants |
| Receipt `evidence_sha256` | Broker hashes bounded evidence bundle | Laravel | Digest only; separate explicit fields remain authoritative |
| Receipt `finished_at` | Broker trusted clock | Laravel | UTC RFC3339; signature covered |
| ACK `schema`, `iss`, `key_id` | Protocol registry and platform ACK-key registry; Laravel creates only after durable processing | Broker | ACK-v2 only; platform ACK-purpose signature |
| ACK `ack_id` | Laravel durable receipt-processing row | Broker | Stable for exact receipt/outcome; duplicate returns same ACK |
| ACK `receipt_id`, `receipt_sha256` | Verified host receipt and exact canonical outer-envelope bytes | Broker | Exact receipt instance and bytes, not parsed/re-serialized content |
| ACK `outcome`, `result_binding` | Laravel's committed receipt reconciliation record | Broker | Exact outcome-specific union mirrors accepted receipt and work |
| ACK tenant/site/Connector/provider/host tuple | Durable platform registration and processed receipt | Broker | Exact broker registration and one private work mapping |
| ACK `restore_uuid`, `job_uuid` | Durable original restore/job record | Broker | Original tuple; no recovery attempt substitution |
| ACK `processed_at` | Laravel trusted clock | Broker | UTC RFC3339; signature covered |

#### Terminal Receipt/ACK outcome-field provenance

For each row, the named creator derives fields from the authoritative durable record/evidence listed; Laravel independently validates the receipt against its own authorization record before committing state; ACK v2 copies only the committed binding; the broker checks the ACK against its single durable work mapping.

| Outcome / field | Type and authoritative source | Receipt creator → verifier | ACK binding |
|---|---|---|---|
| RESTORE_SUCCESS: `restore_intent_id` | UUID from accepted signed Restore Intent | Broker → Laravel | Exact processed restore authorization ID |
| RESTORE_SUCCESS: `restore_work_id` | UUID from broker accepted-work row | Broker → Laravel | Exact ACK work binding |
| RESTORE_SUCCESS: `backup_uuid`, `manifest_sha256`, `restore_scope` | Immutable backup record, verified manifest, approved scope | Broker → Laravel | Byte-for-byte semantic mirror of committed values |
| RESTORE_SUCCESS: `restore_mutation_started` | Literal true, broker durable mutation journal | Broker → Laravel | Literal true |
| SAFE_PRE_MUTATION_ABORT: original intent/work/backup/manifest/scope fields | Same original authorization and broker work sources as RESTORE_SUCCESS | Broker → Laravel | Exact mirror, no substituted parent |
| SAFE_PRE_MUTATION_ABORT: `restore_mutation_started` | Literal false, durable proof from broker journal that no write boundary began | Broker → Laravel | Literal false plus proof digest |
| SAFE_PRE_MUTATION_ABORT: `no_mutation_proof_sha256` | SHA-256 of bounded broker evidence proving absence of mutation start and writes | Broker → Laravel | Exact processed proof digest |
| RECOVERY_REQUIRED: `failure_origin`, `failure_stage` | Broker state transition and bounded stage enum | Broker → Laravel | Exact committed failure classification |
| RECOVERY_REQUIRED: original intent/work/backup/manifest/scope | Original accepted restore record and immutable authorization | Broker → Laravel | Exact parent binding |
| RECOVERY_REQUIRED: `overall_mutation_state` | Durable broker mutation journal; STARTED or AMBIGUOUS only | Broker → Laravel | Exact committed state; cannot authorize release |
| RECOVERY_REQUIRED: `failed_recovery` tuple | Only from one accepted failed recovery work: trigger receipt ID/digest, Recovery Intent ID/envelope digest, recovery work ID, actual safety backup/manifest/scope, recovery mutation state | Broker → Laravel | Required for RECOVERY_ATTEMPT and copied from the exact failed child work |
| RECOVERY_SUCCESS: original restore intent/work and trigger receipt ID/digest | Original accepted work plus exact durable RECOVERY_REQUIRED outbox receipt | Broker → Laravel | Exact parent and trigger binding |
| RECOVERY_SUCCESS: Recovery Intent ID/envelope digest and recovery work ID | Laravel durable approval plus broker accepted recovery child work | Broker → Laravel | Exact approved attempt and unique child work |
| RECOVERY_SUCCESS: recovery scope, actual safety backup UUID/manifest digest | Durable Recovery Intent plus worker/broker verified actual immutable input | Broker → Laravel | Exact reconciled result; mismatch means no positive ACK |
| RECOVERY_SUCCESS: `recovery_mutation_started` | Literal true, broker durable recovery mutation journal | Broker → Laravel | Literal true |

For every ACK result-binding member, the value source is the committed, verified receipt and platform processing record. The ACK schema is outcome-discriminated and copies the exact field names/values defined in §8; it cannot omit a recovery binding, add an alternative generic field, or be synthesized from a Connector/HMR assertion.

#### HMR v1 field registry

The WAN schemas are authenticated by the registered mTLS certificate; local HMR-broker IPC uses the fixed socket and OS peer credentials. HMR does not sign protocol payloads. The nested signed envelopes are carried byte-for-byte.

Closed responses:
- Poll response, wp-caregrid.hmr-poll-response.v1: schema, request_id, relay_id, provider_id, host_id, result, next_platform_delivery_id, delivery, server_time. result is OK or a stable denial enum. OK has one delivery or null when none is available; denial has null delivery and cursor.
- Submit response, wp-caregrid.hmr-submit-response.v1: schema, request_id, host_message_id, relay_id, provider_id, host_id, result, ack_sha256, ack_b64url, acceptance_confirmation_sha256, acceptance_confirmation_b64url, completed_at, error_code. ACK_READY is Terminal Receipt only; only ACK fields are non-null. ACCEPTANCE_RECORDED is HWA only; only Work Acceptance Confirmation fields are non-null. DENIED has all four artifact fields null and a stable error_code.
- HMR-broker request, wp-caregrid.hmr-broker-rpc.v1: schema, request_id, relay_id, provider_id, host_id, operation, payload. Response, wp-caregrid.hmr-broker-response.v1: schema, request_id, result, payload; payload is a strict operation-specific union.

| Schema / field | Type and authoritative source | Producer → verifier | Binding / idempotency |
|---|---|---|---|
| hmr-poll.schema | Exact registered literal | HMR → Laravel | Closed version |
| poll.request_id | UUID in durable HMR request journal | HMR → Laravel | Exact retry repeats identical request |
| poll relay_id/provider_id/host_id | HMR enrollment + TLS certificate registration | HMR → Laravel | Must equal authenticated cert mapping |
| poll.channel_sequence | Canonical decimal uint64 from HMR durable sequence journal | HMR → Laravel | Monotonic per relay; no rollback |
| poll.last_platform_delivery_id | Platform-issued opaque cursor or null | HMR → Laravel | Cursor cannot select site/work/operation |
| poll.max_wait_seconds | Integer 0..20 from fixed protocol bound | HMR → Laravel | Server-side timeout is independently bounded |
| poll.sent_at | HMR trusted clock | HMR → Laravel | UTC RFC3339; clock policy applies |
| platform delivery.schema/request_id | Literal and matching durable poll record | Laravel → HMR | Exact request binding |
| platform delivery.delivery_id | UUID allocated in Laravel's durable outbound-delivery record | Laravel → HMR | Platform-originated only; bound to relay/host/sequence/kind/digest |
| platform delivery relay_id/provider_id/host_id | Platform enrollment and authenticated relay | Laravel → HMR | Exact channel identity |
| platform_delivery_sequence | Monotonic uint64 in durable per-relay platform delivery journal | Laravel → HMR | No rollback; gaps retryable |
| platform delivery.kind | Closed enum SIGNED_RECOVERY_INTENT | Laravel → HMR | Not authorization; inner signature is |
| platform delivery.payload_sha256/payload_b64url | Exact Recovery Intent envelope and digest from durable delivery row | Laravel → HMR | Exact bytes; broker independently checks |
| platform delivery.issued_at/expires_at | Platform trusted clock and delivery record | Laravel → HMR | Bounded lifetime |
| submit.schema | Exact registered literal | HMR → Laravel | Closed version |
| submit.request_id | UUID in HMR durable submit journal | HMR → Laravel | Retry repeats same request and payload |
| submit.host_message_id | 64 lowercase hex, deterministically recomputed as defined in §9.3 and persisted in broker outbox | Broker → HMR → Laravel | Binds provider/host/relay/kind/exact payload digest; nested signed HWA/receipt supplies work binding; mismatches deny |
| submit.relay_id/provider_id/host_id | HMR enrollment/TLS identity and broker outbox binding | HMR → Laravel | Must agree across certificate, host_message_id record and nested message |
| submit.channel_sequence | HMR durable monotonic sequence journal | HMR → Laravel | No reset on reconnect/cert rotation |
| submit.kind | Closed enum receipt or HWA, copied from broker outbox row | Broker → HMR → Laravel | Must match nested signed payload schema |
| submit.payload_sha256/payload_b64url | Exact bytes from broker outbox; digest is SHA-256 of those bytes | Broker → HMR → Laravel | Byte exact; Laravel verifies host signature and digest |
| submit.sent_at | HMR trusted clock | HMR → Laravel | UTC RFC3339; replay ledger still authoritative |
| response.host_message_id/request_id/relay_id/provider_id/host_id | Echo of authenticated request plus registration | Laravel → HMR | Must match exact submitted row |
| response.result | Laravel durable processing result | Laravel → HMR | ACK_READY iff receipt; ACCEPTANCE_RECORDED iff verified HWA; DENIED has no success side effect |
| response.ack_sha256/ack_b64url | Laravel durable receipt processing row; absent for HWA | Laravel → HMR | Exact platform-signed ACK v2 only for ACK_READY; null otherwise |
| response.acceptance_confirmation_sha256/b64url | Laravel durable HWA acceptance row and deterministic confirmation envelope | Laravel → HMR | Exact platform-signed Work Acceptance Confirmation only for ACCEPTANCE_RECORDED; null otherwise; never a Receipt ACK |
| response.completed_at/error_code | Laravel trusted clock/stable error enum | Laravel → HMR | UTC RFC3339; no secret diagnostic |
| broker RPC request_id | HMR durable local request journal | HMR → broker | Exact retry idempotent |
| broker RPC relay_id/provider_id/host_id | OS peer credential plus fixed broker registration | HMR ↔ broker | Request values must equal socket identity |
| read_pending_host_messages page_size/cursor | Bounded integer and opaque broker cursor | HMR → broker | Read-only, returns only this host's outbox rows |
| HMR RPC payload kind/digest/bytes | Closed operation schema and exact outbox/inner envelope | HMR → broker | Broker re-verifies nested signature/binding; no generic RPC |

delivery_id MUST NOT appear on hmr-submit or its response. host_message_id MUST NOT replace delivery_id on platform-to-HMR deliveries. The two IDs are separate namespaces with separate durable producers and replay journals.

#### Manual recovery authorization fields

A manual console action uses the same signed Recovery Intent, Host Work Acceptance, Terminal Receipt v2, and ACK v2 schemas. The operator adds only an external audit record with operator identity, Owner approval reference, time window, reason code, target host/site, requested fixed operation, and resulting protocol message digests. This audit record is not a protocol authorization and cannot replace any signed field or broker check. Free-form commands, paths, SQL, marker edits, and direct gate changes are forbidden.


## 16. Manual recovery authorization and audit contract

Manual console fallback is an out-of-band transport, not an authorization shortcut. Each use requires a separate Owner authorization naming provider/host/site, exact restore/job/work, trigger receipt/digest, Recovery Intent ID/digest, safety backup/manifest, scope, operator, time window, allowed fixed operations, evidence location and stop authority. The platform must have approved the Recovery Intent durably before submission. A second reviewer/approver is required wherever the product policy requires dual control.

The console exposes only fixed operations: export exact signed receipt, submit exact signed ACK v2, submit exact signed Recovery Intent, and read bounded status. The operator cannot supply paths, SQL, shell, host generation, arbitrary site IDs, or a success boolean. Broker independently validates every nested signature and mapping. Audit records include approver/operator IDs, exact message IDs/digests, operation/result/timestamps and correlation IDs; no secrets or payload content. If audit evidence, broker journal, identity, authorization or reconciliation is unavailable, the site remains fenced. Manual process completion alone is not proof of successful platform state.

## 17. Implementation gates and compatibility

Before an implementation PR can claim protocol conformance, it must:

1. Implement these closed schemas and parsers with canonical byte fixtures and schema-version tests.
2. Preserve #182 refusal before live mutation when provider absent/unreviewed; no test/fake provider ID enters staging/production allowlists.
3. Implement durable work/replay/generation/outbox records and reviewed fsync/transaction ordering.
4. Implement fixed typed Connector-agent-broker and HMR-broker APIs; demonstrate denial of unknown operation/field/path/command.
5. Implement host/platform/HMR key registration, rotation/revocation, clock monitoring and replay retention.
6. Pass all §14 test cases, including crash/reboot, HMR closed-site, manual authorization and sibling-site non-impact on a disposable host.
7. Obtain Integration & QA contract review and independent M17 AppSec review on exact code/artifact/profile; only then request separate Owner approval for implementation stage sequencing.
8. Keep the provider allowlist empty until exact provider/runtime compatibility evidence, independent review and separate Owner authorization.
9. Keep unknown/shared hosting fail-closed. A synthetic suite or green GitHub CI is not staging/host compatibility evidence.

No live restore, backup deletion, M12 change, staging/production installation, deployment, allowlist activation, or M14.8 task is covered by this contract.

## 18. Review questions and acceptance handoff

M17 AppSec should review this entire contract as one protocol: schemas and field sources; signing/canonicalization; all four terminal outcomes; ACK v2 no-fallback behavior; broker state/generation mapping; all typed interfaces; HMR and manual fallback; key/clock/replay/retention lifecycle; crash/fsync semantics; and every conformance test. The review should list **all** remaining architectural blockers and required tests in one report, distinguishing proposed text from implementation evidence.

Integration & QA should then verify schema/API executability, cross-message consistency, state-machine idempotency/crash behavior, test-matrix determinism, compatibility with #182 fail-closed behavior, and that no adjacent #192/#191/M16 scope entered the change.

Owner merge approval is separate. This PR is documentation only and does not authorize implementation, staging, production, a provider allowlist, HMR deployment, or restore.
