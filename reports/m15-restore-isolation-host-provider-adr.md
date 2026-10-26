# ADR M15-01 v2 — Asynchronous restore handoff to a per-site isolated host worker

- **Status:** Proposed for independent M17 AppSec re-review; final Owner architecture approval required before implementation
- **Date:** 2026-10-26
- **Review basis:** M17 identified the closed-site recovery transport gap at [PR #190 comment 6410907234](https://github.com/zsoltacsai/wpcaregrid/pull/190#issuecomment-6410907234), then found outcome-ambiguous recovery authorization binding at [comment 6411511806](https://github.com/zsoltacsai/wpcaregrid/pull/190#issuecomment-6411511806). This revision proposes an independent host management control plane, audited manual fallback, and outcome-specific receipt/ACK bindings; none is implemented or operationally validated.
- **Tracking:** [Issue #189](https://github.com/zsoltacsai/wpcaregrid/issues/189)
- **Architecture review:** M17 v1 review was BLOCKED at [PR #190 comment 6404599176](https://github.com/zsoltacsai/wpcaregrid/pull/190#issuecomment-6404599176). This v2 addresses its five technical blockers; it does not claim M17 has accepted them.
- **Baseline:** claude/ecstatic-wozniak-oemyuf at de54ed273478ec6739cf27a40c8eda27697f0974
- **Current fail-closed contract:** [M15 restore isolation gate contract](../reports/m15-restore-isolation-gate-contract.md)
- **Related product direction:** [Issue #191](https://github.com/zsoltacsai/wpcaregrid/issues/191)

## 1. Decision and product scope

The Owner has accepted this product direction:

- The default recovery feature is an authenticated, integrity-checked customer-downloadable backup package.
- Direct destructive restore is optional and available only on an exact host profile whose isolation provider passed independent security and compatibility review.
- Shared or unknown hosting remains fail-closed for direct restore. Provider absence does not disable otherwise authorized backup creation, retention, or download.
- Eligible backup download-only access may continue for 14 calendar days after effective service/subscription termination, subject to privacy, legal erasure, retention and the separate implementation/review in #191. This ADR does not implement or change that policy.

This ADR proposes a first host-provider architecture: an isolated per-site runtime with an unprivileged per-site agent, an independent Host Management Relay, and a minimal fixed-operation root broker. The Connector-mediated path handles healthy-site handoff and normal receipt relay; the HMR carries signed receipt/ACK/recovery-intent envelopes when the site is fenced. The broker accepts only verified Laravel intents, stages immutable input, hands execution to a separate one-shot restore worker, and returns a cryptographically verifiable terminal receipt. The PHP Connector never performs live restore mutations in the supported provider path.

**Design only.** Provider code, Connector/Laravel contract changes, allowlist activation, staging/production installation, live restore, and changing #182’s fail-closed behavior are not authorized by this ADR.

## 2. Threat model and trust boundaries

### Protected assets and guarantees

The design protects live WordPress files, database state, active restore ownership, customer/tenant separation, and maintenance/host-gate availability against stale/replayed Connector requests or results, compromised/buggy WordPress PHP attempts to fabricate a restore or regain writes, concurrent web/cron/queue/CLI/updater/deployment/database/filesystem writers, process or host crash, malformed backup archives, manifest substitution, path traversal, CDN/cache/direct-origin bypass, and sibling-site impact.

### Trust assumptions

- Laravel’s restore authorization and signing key are protected by existing platform key-management controls.
- The host kernel and root broker are trusted; compromised host root is outside this design’s protection claim.
- The exact host profile can identify and control every ingress and writer class. Unknown writers, shared credentials, unobservable routes, or unverified provider behavior mean unsupported/no acquire.
- The worker artifact and broker are delivered through a separately reviewed signed release process. This ADR does not establish their supply-chain provenance.
- The Connector’s Unix UID is an identity signal only, never sufficient restore authorization.
- HMR availability is independent of target WordPress/FPM/site cgroups and credentials but not of host or network failure. No liveness guarantee is claimed under host/network outage; safety wins and the site remains fenced.
- Provider readiness data is advisory admission evidence, never evidence that a host gate is currently held.

### Component responsibilities

| Component | Authoritative responsibility | Must not do |
|---|---|---|
| Laravel platform | Tenant/business authorization, immutable backup selection, intent signing, workflow state, receipt verification and idempotent result ingestion | Claim isolation from readiness alone; directly release host gates |
| WordPress Connector/PHP | Authenticated polling, non-destructive manifest/object checks, bounded host transfer, durable handoff/outbox, relay of signed receipts | Apply files, swap live tables, wait inside FPM while FPM drains, choose paths/units/credentials |
| Per-site unprivileged agent | Typed site-local handoff and status relay while the site is available | Possess root credentials, bypass broker policy, gain cross-site authority |
| Host Management Relay (HMR) | Independent host-level outbound authenticated transport for broker outbox, signed ACKs and signed recovery intents; fixed typed local IPC | Run in the site PHP/FPM cgroup; use site credentials/ingress; authorize recovery; expose general root RPC; unlock or mutate site state |
| Minimal root broker | Verify intent and anti-replay, own authoritative gate/generation journal, control fixed site resources, launch/observe worker, validate terminal evidence and sign receipt | Expose shell/root RPC; trust PHP/relay claims; unlock on TTL/process loss |
| One-shot restore worker | Validate accepted immutable work; stage/apply within fixed site sandbox; emit bounded evidence | Change gate state, access arbitrary host paths/secrets, use general network or subprocesses |
| Edge/origin/DB/storage controls | Enforce site-specific deny, drain, and writer fence | Affect unrelated sites or rely on cooperative flock |

## 3. First supported topology and least privilege

The first reference profile is a dedicated/managed VPS with one isolated WordPress site:

- Unique site UID, cgroup, PHP-FPM pool/container, filesystem volume and database instance/credentials.
- Site-specific origin listener and enforceable CDN/edge policy. Direct-origin access is denied except for separately scoped authenticated control and health paths.
- Site-scoped cron, Action Scheduler, queue workers, WP-CLI, update jobs, and deployment units, all inventoried and fenceable.
- A per-site unprivileged agent has no host-control credentials. It communicates with one narrowly scoped broker endpoint.
- A root-owned broker exposes only typed operations for fixed root-owned site mappings. It uses fixed systemd/unit/mount/database policy; no caller-supplied command, executable, environment, URL, SQL, path, or unit name.
- A separate one-shot restore-worker UID/process namespace receives only verified work and exact site filesystem/database access. It has no general network egress, cross-site mount, agent-state write access, or gate-release authority.
- Broker durable state and signing key are root-only. Platform intent verification keys are pinned/rotatable root-owned public keys. Site PHP never reads broker secrets.
- A site-specific host public key is enrolled into Laravel through a separately approved authenticated provider-registration process. Connector inventory alone cannot enroll or rotate a trusted key.

For a fenced-site control path, the reference profile also requires a **Host Management Relay (HMR)** as a distinct host-level service and trust principal. It runs under a dedicated unprivileged OS identity and separate systemd unit/cgroup, outside every WordPress/PHP-FPM/site cgroup; it has no site PHP, database, backup, or broker signing credentials. Its only network path is outbound mutually authenticated TLS to a pinned platform management endpoint (no public inbound listener), and its only local path is a fixed broker IPC interface. A site gate must not stop, firewall, or revoke the HMR's host-scoped channel. If the host itself, its network route, or HMR is unavailable, the broker stays fenced and durable outbox state is retained.

HMR is transport only. It forwards opaque, bounded, signed protocol envelopes and returns bounded responses; it cannot sign, alter, authorize, replay-reset, prune receipts, choose arbitrary sites/paths/commands, change gate state, or execute recovery. The broker independently verifies platform signatures, expiry, replay state, exact registered host/site identity, current work mapping, and allowed state transition. HMR has a host identity certificate/key usable only for platform channel authentication, not as a Restore Intent or receipt-signing key. Enrollment, rotation, revocation, endpoint pinning and audit semantics require contract review before implementation.

The broker is a small privileged reference monitor, not a general-purpose root agent. Its operation set, IPC schema, systemd policy, syscall restrictions, file descriptors, key access, update mechanism, and failure behavior require independent review. If the exact platform cannot maintain this boundary, it is unsupported.

A multi-site host is conditionally supportable only with independent site UID/cgroup, FPM, DB, storage, ingress, queue/cron/CLI/deploy and agent state. A test must prove a sibling site remains healthy and writable throughout the target site’s gate. Shared PHP-FPM identities, common writable volumes, shared/unfenced DB credentials, or unknown external writers remain unsupported for direct restore. These restore restrictions do not remove separately authorized backup/download rights.

## 4. Signed Restore Intent v1 proposal

### Encoding and signature

Laravel creates a **Signed Restore Intent v1** only after validating the restore request, target website, active Connector, customer permissions, immutable backup manifest, scope, and required safety backup. It signs a canonical JSON payload using RFC 8785 JSON Canonicalization Scheme and Ed25519 with a domain separator such as WPCAREGRID-RESTORE-INTENT-V1 followed by a NUL byte. The signature covers every field. The broker accepts only the configured algorithm and pinned key ID; unknown algorithms, fields, key IDs or schema versions are rejected.

The private signing key remains in platform key management. The broker receives only the relevant public verification keys through authenticated host release/configuration. Key rotation has a bounded overlap: new intents use the new key; the previous key is accepted only through the maximum intent acceptance period. No secret or bearer capability is written to Connector logs, maintenance markers or user output.

### Required payload

    {
      "schema": "wp-caregrid.restore-intent.v1",
      "iss": "wpcaregrid-platform",
      "aud": "provider-installation-id",
      "provider_id": "reviewed-provider-id",
      "host_id": "registered-host-id",
      "tenant_id": "immutable-tenant-id",
      "site_id": "immutable-website-id",
      "connector_id": "active-connector-id",
      "restore_uuid": "uuid",
      "job_uuid": "uuid",
      "backup_uuid": "uuid",
      "manifest_sha256": "64-lowercase-hex",
      "scope": "files|database|full",
      "intent_id": "uuid",
      "nonce": "base64url-32-random-bytes",
      "issued_at": "UTC RFC3339 timestamp",
      "expires_at": "UTC RFC3339 timestamp",
      "key_id": "platform-signing-key-id"
    }

The example is a schema proposal, not a real credential or site identifier. Implementation must define canonical validation, maximum lifetime, clock-skew policy, key rotation and rejection codes. Proposed maximum lifetime is two minutes for first acceptance. Once durably accepted, execution relies on the host’s immutable accepted-work record, not on the token’s continuing wall-clock validity. Unsafe clock rollback is fail-closed.

The broker verifies signature, issuer, exact audience/provider/host, tenant/site mapping, active Connector identity, scope, backup/manifest binding, UUID syntax, issue/expiry window, and nonce. It durably reserves intent ID and nonce before acknowledging. A byte-identical duplicate returns the same work item; the same ID or nonce with different content is rejected. A terminal/cancelled intent is never re-executed by replay.

The signed manifest binds the exact backup UUID/scope, fixed object names, sizes and SHA-256 hashes. The host accepts only fixed object names for the scope, not archive paths or host paths. Each streamed object is size-limited, hashed, fsynced into a root-owned inbox, and atomically published as immutable input only after digest verification. Partial uploads are not executable. A crash resumes the same intent or leaves non-executable quarantined data; it cannot create a second work item.

### Recovery Intent v1 (separate authorization)

Recovery is a distinct mutation class and uses a separate Signed Recovery Intent v1, signature domain and platform authorization record; the original restore intent and RECOVERY_REQUIRED receipt do not grant it. Laravel may issue it only after the applicable Owner/customer authorization and recovery policy checks are durably recorded. Proposed canonical signed fields are:

    {
      "schema": "wp-caregrid.restore-recovery-intent.v1",
      "iss": "wpcaregrid-platform",
      "aud": "provider-installation-id",
      "provider_id": "reviewed-provider-id",
      "host_id": "registered-host-id",
      "tenant_id": "immutable-tenant-id",
      "site_id": "immutable-website-id",
      "connector_id": "active-connector-id",
      "recovery_trigger_receipt_id": "RECOVERY_REQUIRED receipt UUID",
      "recovery_trigger_receipt_sha256": "64-lowercase-hex-of-exact-signed-receipt-envelope",
      "original_restore_intent_id": "original restore intent UUID",
      "recovery_intent_id": "new UUID",
      "original_restore_uuid": "original restore UUID",
      "original_job_uuid": "original restore job UUID",
      "safety_backup_uuid": "immutable safety backup UUID",
      "safety_manifest_sha256": "64-lowercase-hex",
      "recovery_scope": "files|database|full",
      "nonce": "base64url-32-random-bytes",
      "issued_at": "UTC RFC3339 timestamp",
      "expires_at": "UTC RFC3339 timestamp",
      "key_id": "platform-recovery-signing-key-id"
    }

The platform signature uses a distinct domain separator such as WPCAREGRID-RESTORE-RECOVERY-INTENT-V1 followed by NUL and a recovery-authorized key purpose. It does not contain broker_generation. After signing the canonical envelope, Laravel computes SHA-256 over the exact signed envelope bytes and persists that digest with the approved Recovery Intent ID and immutable authorization tuple before delivery. Laravel sources fields from its durable RECOVERY_REQUIRED receipt, original restore/job, tenant/site/Connector, registered provider/host and separately approved safety-backup records. The broker binds the receipt ID and exact digest to its durable RECOVERY_REQUIRED work record, checks its private current generation and site mapping, and rejects missing, stale, ambiguous, terminal-incompatible or wrong-generation mappings. It atomically reserves the recovery intent ID and nonce before acknowledging. Identical retransmission returns the same recovery_work_id; changed payload, duplicate nonce for another work, expiry, revoked key or clock rollback is rejected. A distinct later attempt requires a new separately approved Recovery Intent bound to the then-current RECOVERY_REQUIRED receipt and cannot overlap an active or ambiguous recovery work item. Exact lifetime, clock skew, retention and key rotation policy remain contract-PR requirements.

## 5. Connector-to-host-worker handoff

The current #182 path runs restore mutation in Connector PHP. The supported-provider path must replace that execution path with asynchronous host-owned work:

1. **Platform authorization:** Laravel stores the restore/job and exact immutable backup/manifest/scope binding, then returns the signed intent with the authenticated job. A duplicate request is rejected or resolves to the same active restore.
2. **Connector validation and transfer:** Connector validates the authenticated response and performs only non-destructive manifest/object checks. It streams bounded backup objects to the per-site agent. It does not unzip into the live site.
3. **Durable host acceptance:** Agent relays the normal Restore Intent and stream to the broker. Broker verifies intent and bindings, records nonce/work reservation, validates complete object digests, and durably records ACCEPTED. Recovery Intent acceptance is a separate state-specific operation: broker verifies the exact trigger receipt/digest, recovery authorization envelope/digest and backup/scope bindings, allocates exactly one recovery_work_id, and durably records that recovery work before acknowledging it. It returns only required correlation IDs; no host generation or lease secret is returned to Laravel.
4. **Durable Connector handoff:** Connector writes its restricted restore marker/outbox entry for the accepted work item and returns from the poll/request promptly. It records HANDOFF_ACCEPTED, not restore success. The marker is recovery linkage, not host enforcement.
5. **No FPM self-drain:** Broker starts gate acquisition asynchronously after handoff acceptance. It never waits for the calling PHP-FPM request inside that request. It closes ingress, then waits until the current request and all site cgroup descendants have exited. Only then does it stop/fence the dedicated pool and prove the cgroup empty. If the caller does not exit within the bound, restore mutation does not start; the site remains closed/blocked for reconciliation. It does not kill an unknown process and assume safety.
6. **Writer fencing and gate acquisition:** Broker confirms every control in §6, persists QUIESCENT for the exact internal generation, and only then allows worker launch.
7. **Isolated execution:** Broker starts the fixed verified worker under a separate restore-worker identity, private mount namespace, site-only writable volume, exact site DB credentials, bounded resources and no arbitrary network/subprocess access. FPM/Connector has no live write path.
8. **Authenticated result relay:** Broker verifies worker exit, exact work binding, durable mutation state, DB/filesystem evidence and required checks. It creates the strict outcome-specific Terminal Receipt v2 (including the exact recovery authorization and trigger bindings for RECOVERY_SUCCESS), signs and fsyncs it in the durable host outbox. Only RESTORE_SUCCESS or a fully verified RECOVERY_SUCCESS may proceed to controlled site reopening, and only after the exact outcome is durable and every release condition passes. SAFE_PRE_MUTATION_ABORT may release only the original restore fence after durable proof of no mutation and reconciliation of every control. RECOVERY_REQUIRED—including an unsuccessful or ambiguous recovery attempt—never reopens the site. Once the Connector is safely available again, it retrieves the exact stored receipt through the agent's read-only typed interface and relays it to Laravel over the existing authenticated platform channel. Laravel durably processes the exact receipt before issuing a platform-signed ACK; the agent does not relay directly to Laravel.
9. **Idempotent retry and receipt lifecycle:** Interrupted delivery retransmits the same stored receipt. Laravel returns the same durable outcome-specific ACK for an already processed receipt; the broker retains the receipt until it verifies and durably records that signed ACK. Invalid, absent, mismatched, replayed, delayed, or lost ACKs cannot authorize pruning. Duplicate identical receipts/ACKs do not create another transition or work item. The ACK contains no broker generation: for normal outcomes the broker resolves the ACK's receipt ID/digest and outcome binding to exactly one durable restore-work record; for RECOVERY_SUCCESS it resolves the recovery receipt ID/digest, recovery intent ID/digest, recovery-work ID, original-work and backup/scope bindings to exactly one durable recovery-work record. It then checks the record's private generation and receipt/outbox state. Missing, ambiguous, corrupt, or wrong-generation work mapping rejects the ACK and preserves the receipt. Conflicting, stale, invalidly signed, wrong-tenant/scope/backup receipts are rejected and cannot release the host gate. Exact retention, acknowledgement, pruning and recovery semantics are contract-PR acceptance criteria.

~~~mermaid
sequenceDiagram
    participant L as Laravel
    participant C as Connector PHP
    participant A as Per-site unprivileged agent
    participant B as Root broker / durable gate
    participant E as CDN + origin
    participant W as One-shot restore worker
    participant D as Site DB + volume
    L->>L: Authorize site, backup, scope; bind immutable manifest
    L->>C: Authenticated job + signed one-use Restore Intent
    C->>C: Validate intent and stage only non-live input
    C->>A: Stream fixed backup objects + signed intent
    A->>B: Typed begin/stream RPC
    B->>B: Verify signature, audience, bindings, nonce; persist ACCEPTED
    B-->>A: Idempotent handoff acknowledgement
    A-->>C: Handoff accepted
    C->>C: Persist marker/outbox; return poll promptly
    C-->>L: Handoff accepted; not a terminal result
    B->>E: Deny public edge/origin paths and cache bypass
    B->>B: Drain FPM caller and all site writers; fail closed on timeout
    B->>B: Persist QUIESCENT generation; verify writer fences
    B->>W: Launch fixed worker in isolated site sandbox
    W->>D: Verify inputs, apply selected scope, check result
    W-->>B: Bounded work result and evidence digest
    B->>B: Validate result; persist and sign terminal receipt/outbox
    B->>E: Reopen only after verified terminal state
    C->>A: After safe reopen, request stored receipt (read-only)
    A->>B: Read exact durable receipt by work ID
    B-->>A: Return same signed receipt; retain until platform ACK
    A-->>C: Return receipt to Connector; no direct platform relay
    C->>L: Relay over authenticated platform channel (retryable/idempotent)
    L->>L: Verify signature and intent binding; update once
    L->>L: Durably process exact receipt; persist stable ACK record
    L-->>C: Sign outcome-specific ACK v2 from verified receipt and durable authorization/work bindings; no broker generation
    C->>A: Relay signed ACK through typed interface
    A->>B: Submit signed ACK; broker verifies signature/bindings
    B->>B: Resolve unique restore-work record by receipt/digest and exact normal outcome binding
    B->>B: Verify private generation mapping; fail closed on missing/ambiguous/mismatch
    B->>B: Persist ACK idempotently; prune receipt only after durable ACK
~~~

Handoff does not authorize scanner jobs, backups, notifications, remediation, quarantine, deployment, or unrelated maintenance. The worker receives a restore-only typed operation.

### 5.1 Closed-site host management control plane

The normal path above uses outcome-specific receipt v2 and ACK v2; it remains Connector-mediated while the site runtime is healthy and has been safely reopened. It is not the recovery transport. When the site is fenced or in RECOVERY_REQUIRED, the Connector/PHP/FPM process is assumed unavailable.

In that state the independent HMR drains the broker's durable, host-signed receipt outbox over fixed local IPC and sends the exact signed envelope to Laravel over outbound mTLS. Laravel verifies the enrolled host key and receipt bindings, then durably processes the receipt before returning the existing platform-signed ACK. HMR forwards that exact ACK to the broker. The broker independently verifies platform signature, receipt digest and exact durable work bindings, resolves its private generation mapping, and persists ACK before pruning. ACK is never an unlock command.

Separately, only after the platform's Owner/customer authorization workflow approves recovery, Laravel issues a new platform-signed **Recovery Intent** with a distinct protocol/domain and key purpose. It binds the registered host/provider and exact tenant/site/Connector, RECOVERY_REQUIRED receipt ID and digest, original Restore Intent/restore/job IDs, immutable safety-backup ID and manifest digest, permitted recovery scope, expiry, nonce and key ID. Laravel stores the Recovery Intent ID and SHA-256 of the exact signed envelope in the durable authorization record before delivery. The HMR obtains this envelope through its authenticated outbound channel and forwards the exact bytes over fixed broker IPC. The broker—not Laravel, HMR, or a caller UID—checks signature, issuer/audience, signed-envelope digest, registered host/site/work mapping, current broker-private generation, exact RECOVERY_REQUIRED receipt ID/digest and original work, backup/manifest/scope binding, expiry, durable nonce/intent replay state, key status and permitted recovery transition. It creates one unique recovery_work_id and durably binds it to that exact authorization before returning the correlation ID; Laravel persists the returned ID against the same authorization. Recovery begins only after the broker durably accepts this distinct intent and re-proves the site's writer/ingress fence. A RECOVERY_REQUIRED receipt alone never authorizes mutation. On completion, the broker's RECOVERY_SUCCESS result identifies both original work and recovery work, the exact trigger receipt and its digest, the accepted Recovery Intent ID and signed-envelope digest, and the actual safety backup/manifest/scope used.

HMR's broker IPC accepts only typed operations such as read_pending_signed_receipt, submit_platform_signed_receipt_ack, submit_platform_signed_recovery_intent, and bounded read_channel_status. The actual API names are proposals. The interface has no arbitrary method, path, SQL, command, unit, environment, URL, unlock, gate-release, receipt-prune, or restore operation. Broker IPC caller identity is an additional restriction, never a substitute for cryptographic message verification. Host/site identity and authorization source for every envelope are recorded in the protocol matrix below. The outcome-specific receipt/ACK binding is matched to one Laravel authorization and one broker recovery-work record; no generic outcome-dependent intent or backup field is used.

If the HMR, platform endpoint, DNS/TLS path, or network is unavailable, messages remain durable and retry idempotently; the HMR uses an outbound authenticated long-poll/request-response channel so the platform can return pending signed intents without opening inbound host ingress. There is no automatic unlock, implicit success, destructive retry, expiry-based discard, or switch to Connector PHP while fenced. A host reboot starts with ingress and writers fenced before site services start; broker reconciliation runs first, and HMR reconnects afterward to deliver the same receipt or retrieve the same pending authorized intent. A missing/invalid/revoked identity or replayed/stale envelope is rejected and audited; the site remains fenced.

#### Manual host-console fallback

If the HMR path is unavailable while the host and broker remain healthy and their durable state has reconciled, a separately audited Owner-authorized operator may use the provider's authenticated host console only under a distinct operation approval naming the exact host, site, restore/work, safety backup, scope, operator, time window and stop authority. The console procedure must use fixed, broker-owned typed receipt-export and signed-envelope submission/status interfaces—not an ad hoc root shell, direct SQL, marker deletion, filesystem editing, or general RPC. The operation record must capture Owner approval reference, named operator, host/site/restore/work IDs, exact receipt and intent digests, scope, UTC start/end, fixed interface actions, broker decisions, evidence references, and final Laravel reconciliation result. The operator first exports the broker-signed RECOVERY_REQUIRED receipt to Laravel through the approved audited evidence channel. Laravel verifies and durably reconciles it, then returns the platform-signed receipt ACK; the operator submits this ACK through the fixed broker interface and the broker verifies/persists it before the receipt bytes may be pruned. Only then may the separate recovery approval workflow issue the exact Signed Recovery Intent. The operator transfers that signed intent to the broker through the same fixed interface; broker signature, identity, replay, state and fence checks are identical to HMR delivery. The operator cannot manufacture an intent or bypass broker policy.

After recovery, the broker persists and signs the terminal recovery receipt. The operator transfers only that signed receipt through the approved audited evidence channel to Laravel; Laravel verifies and durably reconciles it, then issues the same platform-signed ACK. The operator submits the ACK through the fixed broker interface, and the broker verifies/persists it before receipt pruning. Recovery completion and gate release depend on broker-verified recovery evidence and the controlled release sequence, never on operator assertion or ACK. If the platform cannot issue the signed intent, the receipt cannot be authenticated, or the fixed broker interface/audit trail is unavailable, no destructive recovery or reopen occurs; remain fenced and escalate for a separately reviewed break-glass protocol. This fallback is a proposed procedure, not an available or executed capability.

## 6. Ingress, drain, database and filesystem fencing

Broker first durably records a closed/acquiring state, then denies new origin requests and direct-origin traffic. It applies a site-specific edge deny/cache policy and requires provider acknowledgement. IPv4/IPv6, aliases, alternate ports/load balancers, CDN cache, webhooks, admin/REST routes, and direct-origin paths are compatibility evidence. Generic WAF or DNS matching is not proof. If any public path can reach the app or serve a mutable endpoint, acquisition fails.

After ingress deny, broker drains requests, persistent connections, PHP-FPM and its cgroup descendants. It identifies the handoff caller and lets it return before waiting for the pool to empty. Broker RPC is asynchronous; no PHP request waits for its own FPM shutdown. Unknown or stuck processes are not force-killed and treated as drained. Bounded failure prevents worker start and remains closed/manual-reconcile unless the broker positively proves safe rollback before mutation.

Broker fences:

- Dedicated PHP-FPM and all HTTP entrypoints.
- WP-Cron plus every external/system/panel cron trigger.
- Action Scheduler and queues: stop new claims/producers; drain or fence active jobs; retain queued work.
- WP-CLI, maintenance scripts, interactive sessions and automation.
- Core/plugin/theme updater mechanisms.
- Deployment/release/migration jobs: they must acquire the same broker lock or fail before changing code/schema/config.
- Direct DB sessions/writers: first profile requires a dedicated per-site DB instance/credentials. Drain existing sessions; restrict network/credentials so no untracked writer bypasses broker. Shared/global DB read-only mode is prohibited.
- Filesystem/sync/backup/antivirus/deploy writers: unique site volume and identity; fence all site writers. Only the isolated worker gets the scoped writable mount during apply. No other site/shared UID is affected.
- Human/provider operations: only separately approved audited recovery may operate while closed.

Broker reports acquired only after every control acknowledges the exact site/state, no in-flight writers remain, and durable state agrees. Missing evidence, unsupported edge/DB/provider APIs, shared PHP-FPM, shared writable storage, direct DB access or unknown writer is NO-GO. flock and Connector locks are cooperative serialization only, not isolation.

## 7. Outcome-specific Verified Terminal Receipt v2

Only the root broker creates a terminal receipt after independently checking accepted work, the current private generation, worker identity/exit, durable mutation state, exact input manifest, output/evidence digest, site DB/filesystem result and required non-mutating checks. The worker cannot release the gate or sign a host receipt. Broker durably stores the signed receipt/outbox before any release transition. A caller-supplied success flag is insufficient.

The canonical JSON envelope is signed with the enrolled site/provider Ed25519 host key. Laravel pins and validates that key through provider enrollment. The common envelope uses `wp-caregrid.restore-terminal.v2` and contains only fields whose meanings are invariant across outcomes:

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
      "result": "strict outcome-specific object defined below",
      "evidence_sha256": "64-lowercase-hex",
      "finished_at": "UTC RFC3339 timestamp",
      "host_key_id": "registered-host-signing-key-id"
    }

There is deliberately no common `intent_id`, `backup_uuid`, `manifest_sha256`, `scope`, or `mutation_started`: those values are outcome-specific. Each `outcome` selects exactly one result schema; fields from another variant, missing required fields, unknown fields, or mismatched outcome/result schema are rejected.

### Outcome-specific result schemas

- **RESTORE_SUCCESS — `wp-caregrid.restore-success.v1`**: `restore_intent_id`, unique `restore_work_id`, `backup_uuid`, `manifest_sha256`, `restore_scope`, and `restore_mutation_started=true`. These identify the original authorized restore inputs and work.
- **SAFE_PRE_MUTATION_ABORT — `wp-caregrid.restore-safe-abort.v1`**: `restore_intent_id`, unique `restore_work_id`, `backup_uuid`, `manifest_sha256`, `restore_scope`, `restore_mutation_started=false`, and `no_mutation_proof_sha256`. This outcome applies only to original restore work before any mutation; a recovery attempt that aborts before its own mutation retains the prior RECOVERY_REQUIRED state and cannot emit this outcome as if the original restore were safe.
- **RECOVERY_REQUIRED — `wp-caregrid.restore-recovery-required.v1`**: common result fields are `failure_origin=ORIGINAL_RESTORE|RECOVERY_ATTEMPT`, `original_restore_intent_id`, `original_restore_work_id`, `original_backup_uuid`, `original_manifest_sha256`, `original_restore_scope`, durable `overall_mutation_state=STARTED|AMBIGUOUS`, and bounded `failure_stage`. For `ORIGINAL_RESTORE`, only that variant is present. For `RECOVERY_ATTEMPT`, a required nested `failed_recovery` object with schema `wp-caregrid.restore-failed-recovery.v1` binds `recovery_trigger_receipt_id`, `recovery_trigger_receipt_sha256`, `recovery_intent_id`, `recovery_intent_envelope_sha256`, `recovery_work_id`, `safety_backup_uuid`, `safety_manifest_sha256`, `recovery_scope`, and `recovery_mutation_state=NOT_STARTED|STARTED|AMBIGUOUS`. No optional ambiguous fields are allowed. Either variant leaves the site RECOVERY_REQUIRED and fenced; a pre-mutation recovery abort cannot be misreported as SAFE_PRE_MUTATION_ABORT for the original restore.
- **RECOVERY_SUCCESS — `wp-caregrid.restore-recovery-success.v1`**: the exact bindings below; no generic intent/backup/scope field is used.

    {
      "schema": "wp-caregrid.restore-recovery-success.v1",
      "original_restore_intent_id": "original Signed Restore Intent UUID",
      "original_restore_work_id": "original accepted restore-work UUID",
      "recovery_trigger_receipt_id": "RECOVERY_REQUIRED receipt UUID",
      "recovery_trigger_receipt_sha256": "digest of exact signed receipt envelope",
      "recovery_intent_id": "separately authorized Recovery Intent UUID",
      "recovery_intent_envelope_sha256": "digest of exact signed Recovery Intent envelope",
      "recovery_work_id": "unique broker-accepted recovery-work UUID",
      "recovery_scope": "files|database|full",
      "safety_backup_uuid": "exact immutable backup actually used",
      "safety_manifest_sha256": "digest of the exact safety-backup manifest",
      "recovery_mutation_started": true
    }

For RECOVERY_SUCCESS, the common `restore_uuid` and `job_uuid` always identify the original restore/job. The result object's `original_restore_intent_id` and `original_restore_work_id` identify the original accepted work; the trigger receipt fields bind the exact RECOVERY_REQUIRED event; the Recovery Intent ID plus signed-envelope digest bind the platform authorization that the broker actually accepted; and the recovery work, scope and safety-backup fields identify the distinct recovery execution and its actual source. Tenant/site/Connector/provider/host remain common immutable bindings. No field changes meaning by outcome.

The Recovery Intent envelope digest is computed over the exact canonical signed envelope bytes, including its signature and key identifier, and stored by Laravel in the durable recovery-authorization record when that authorization is issued. The broker records the same exact digest when accepting the envelope. The RECOVERY_SUCCESS result copies that value from the broker's accepted recovery-work record, not from worker-supplied data.

### Laravel receipt acceptance

Laravel verifies the host signature/key status, common identity tuple and outcome/result schema before state transition. For RESTORE_SUCCESS and SAFE_PRE_MUTATION_ABORT it reconciles the exact restore-intent ID, restore-work ID, original restore/job, backup/manifest/scope and outcome invariants to the durable original authorization. For RECOVERY_REQUIRED it does the same against the original restore authorization and records the exact signed receipt ID/digest as the recovery trigger. For RECOVERY_SUCCESS it additionally requires exactly one durable Laravel recovery-authorization record matching recovery intent ID **and exact signed-envelope digest**, original intent/work and restore/job, trigger receipt ID/digest, site/tenant/Connector/provider/host, safety-backup UUID/manifest and scope. The record must represent the Recovery Intent actually approved and issued; merely valid host signature is insufficient. A RECOVERY_REQUIRED result with failure_origin=RECOVERY_ATTEMPT must also match exactly one prior Laravel recovery-authorization record and the referenced failed recovery-work ID, trigger receipt digest, attempted backup/manifest/scope and nested failed_recovery fields; an unaccepted/rejected Recovery Intent cannot be fabricated into a terminal attempt receipt.

Missing, mismatched, stale, replayed, duplicate/ambiguous, or cross-attempt bindings cause no successful platform state transition and no positive ACK. Identical receipt replay returns the same durable outcome/ACK only after full reconciliation; conflicting reuse of a receipt ID is rejected. The signed receipt contains no internal generation or lease token. Broker retains its own exact work-to-generation mapping privately.

### Outcome and gate invariants

- **RESTORE_SUCCESS:** the original restore inputs were applied and approved checks passed. Broker may reopen only after verified terminal state is durable and controlled-release checks pass.
- **SAFE_PRE_MUTATION_ABORT:** durable proof shows no mutation intent/write occurred; that work item is terminal and cannot execute later.
- **RECOVERY_REQUIRED:** mutation started or state is ambiguous; gate stays closed. The receipt reports this state and does not authorize recovery.
- **RECOVERY_SUCCESS:** a separately authorized Recovery Intent was accepted, one exact recovery work item used the specified safety backup/scope and passed verification. Broker may reopen only after the recovery result and required checks are durably verified. ACK is not a gate-release signal.

Before every first live DB/file mutation, broker durably records that operation's mutation-started state and verifies its active private generation. A crash between that record and first write is conservatively destructive/ambiguous. No automatic destructive compensation or retry is allowed. Pre-release health checks must be enumerated and non-mutating; if WordPress boot needs writes, use an isolated verification copy or keep the site closed.

## 8. Durable state ownership, atomicity and reconciliation

### Authoritative state

- **Laravel database:** business request, selected backup/scope, tenant authorization, platform job and customer-facing state. Stores normal/recovery intent IDs and exact signed-envelope digests, immutable approval bindings, broker-returned recovery_work_id, verified outcome-specific receipts and ACK records, not host generation, lease token, broker journal or secrets.
- **Connector marker/outbox:** durable relay pointer from Connector job to handoff/receipt. Not authority to open the host gate.
- **Root broker state store:** authoritative host enforcement state. Stores accepted normal/recovery intent IDs and exact envelope digests/nonces, unique original and recovery work IDs, trigger receipt IDs/digests, site/restore/job/backup/scope bindings, internal generation and previous/pending/current generations, control acknowledgements, mutation state, outcome-specific signed receipt/outbox, ACK retention state, and durable recovery authorization/replay state.
- **Host Management Relay:** transport cursor/retry metadata only; not authoritative work, receipt, ACK, generation, replay, or gate state. Its authenticated host identity proves the channel endpoint, while each protocol envelope is separately signature-verified by Laravel or broker.
- **Per-site agent:** ephemeral orchestration/cache only.
- **Restore worker:** immutable accepted input plus ephemeral work; never gate/terminal authority.

This explicitly amends the v1 interpretation of #182’s marker-only wording: the provider needs protected durable enforcement state to survive restart. Proposed #182 follow-up must permit root-only provider-private generation state while prohibiting exposure to Laravel, WordPress/PHP and logs. Connector marker remains minimal recovery linkage. Owner and M17 approval are required before implementation.

### Persistence and external-effect ordering

1. Laravel commits the authorized restore/job and manifest binding before signing. A transactional outbox makes retries return the same intent identity, or a new same-job intent only if the earlier one expired/unaccepted and no host work exists.
2. Broker verifies intent and atomically reserves unique intent/nonce in RECEIVING before acknowledgement. Recovery uses the separate Recovery Intent v1 and state-specific validation in §4; it cannot be accepted as a new restore or inferred from RECOVERY_REQUIRED alone.
3. Broker writes bounded objects to root-owned no-follow temporary files, hashes exact bytes, fsyncs files, atomically renames verified objects and fsyncs the directory. Only after every object matches does a durable transaction move work to ACCEPTED. Crash between object publication and transaction leaves non-executable inbox data; accepted work with missing/corrupt input never runs.
4. Broker persists ACQUIRING(g) before gate side effects and records each ingress/drain/writer/DB acknowledgement. Partial effects never mean acquired.
5. Broker persists QUIESCENT(g) only after positive verification; no worker starts before commit.
6. Connector writes marker/outbox and returns. Broker verifies marker linkage only after FPM/site writers are fenced, using a fixed path, no-follow descriptor-based read, expected owner/schema/size and exact restore binding. Marker is not host ownership.
7. Before every live mutation phase, worker/broker verify exact generation. Broker durably commits mutation-started before apply.
8. Broker stores/fsyncs validated terminal receipt/outbox before unlock. It controls the open sequence. Receipt relay may happen later and retry without duplicate transitions.
9. Host journal uses root-owned storage, restrictive modes, transactional updates, explicit fsync/directory-sync guarantees and startup integrity checks. Store technology and filesystem durability semantics must be selected and fault-tested during implementation; generic rename is not a multi-resource transaction.

Host controls cannot share one DB transaction. Broker implements a durable saga: record intent before each external effect, record verified acknowledgement after it. A crash resumes the same generation or enters closed error. It never assumes an unacknowledged control succeeded or failed.

### State machine

~~~mermaid
stateDiagram-v2
    [*] --> OPEN
    OPEN --> RECEIVING: verified intent reserved
    RECEIVING --> ACCEPTED: immutable inputs verified and durable
    ACCEPTED --> ACQUIRING: async activation
    ACQUIRING --> DRAINING: ingress denied
    DRAINING --> QUIESCENT: all writers fenced
    DRAINING --> CLOSED_ERROR: timeout or unknown writer
    ACQUIRING --> CLOSED_ERROR: partial control failure
    QUIESCENT --> APPLYING: durable mutation intent
    QUIESCENT --> SAFE_ABORT: no mutation proven
    APPLYING --> VERIFYING: worker result
    APPLYING --> RECOVERY_REQUIRED: crash, loss or ambiguity
    VERIFYING --> RELEASING: verified terminal success
    VERIFYING --> RECOVERY_REQUIRED: failed or unknown checks
    RECOVERY_REQUIRED --> QUIESCENT: separately authorized recovery
    QUIESCENT --> APPLYING: recovery worker
    RELEASING --> OPEN: healthy writers; ingress opens last
    SAFE_ABORT --> RELEASING: verified safe abort
    CLOSED_ERROR --> QUIESCENT: audited safe reconciliation
    CLOSED_ERROR --> RECOVERY_REQUIRED: mutation possible/unknown
~~~

No TTL, Connector disappearance, PHP restart, reboot, absent marker or stale process memory transitions a closed/recovery state to OPEN.

## 9. Crash, retry, cancellation and recovery

| Interruption | Rule |
|---|---|
| Before intent acceptance | Laravel may redeliver the same job; no accepted host work or live side effect exists. |
| During transfer | Resume the same intent/object digests. Partial data is quarantined/non-executable. |
| After ACCEPTED, before gate | Query returns the same work item; activation is idempotent. Cancellation only after broker reconciles its own effects and proves no mutation. |
| During caller/FPM drain | Worker does not start. Unknown writer means remain closed and require reconciliation. |
| During gate acquire | Resume the same generation and inspect each control. No unrelated generation. |
| Before first live mutation | For original restore work, SAFE_PRE_MUTATION_ABORT requires durable proof of no mutation and may reopen only after its control state is reconciled. For a recovery attempt, preserve the parent RECOVERY_REQUIRED fence and report that attempt distinctly; never relabel it as an original restore abort or reopen. |
| During/after DB or file mutation | No automatic duplicate restore. Preserve artifacts/gate; issue RECOVERY_REQUIRED and require a separately authorized Recovery Intent. |
| After worker result, before receipt persistence | Reconstruct only from durable evidence; otherwise persist RECOVERY_REQUIRED and remain closed. |
| After receipt persistence, before delivery/ACK | HMR retries the exact signed receipt; Laravel processes once and returns the same durable signed ACK. Broker retains receipt until ACK signature, exact work mapping and persistence are verified. |
| During recovery-intent delivery | HMR/network retry resubmits the same signed envelope. Broker's durable nonce/work reservation makes an identical retry idempotent; altered, expired, revoked, or cross-site intent is rejected. |
| HMR/network outage | No Connector fallback while site is fenced; no automatic unlock, implicit success, destructive retry, or message pruning. Broker retains state/outbox and site remains fenced. |
| Host reboot or broker/HMR restart | Boot ordering keeps ingress and writers fenced before site services start. Broker reconciles its durable journal first; HMR reconnects and resumes same outbox/control envelopes. Corrupt/ambiguous state remains closed. |
| Manual console fallback unavailable or incomplete | No alternate shell/database/marker operation. Preserve fence and require Owner escalation or a separately reviewed break-glass protocol. |

A new restore cannot start while any prior restore is active, terminal-but-unreported or recovery-required for that site. Platform and broker enforce one active restore per site. Worker retry is not full restore replay; post-mutation ambiguity requires an exact, separately authorized recovery operation.

Recovery uses named roles, exact site/restore/work/backup/scope, dual approval where policy requires, bounded audited actions and integrity evidence. Operators cannot clear state by deleting .maintenance, removing a lock or marking a job successful. A RECOVERY_REQUIRED receipt is evidence for workflow and audit; it is not a recovery capability.

### Message, identity and state-source matrix

| Message / path | Transport and endpoint identity | Authorization/integrity evidence | State source and receiver decision |
|---|---|---|---|
| Normal restore job, Laravel → Connector | Existing authenticated Connector platform channel | Platform job authorization plus Signed Restore Intent; Connector auth is transport identity only | Laravel owns customer/tenant/job state; broker verifies the signed intent before acceptance |
| Normal receipt, broker → agent → Connector → Laravel | Broker durable outbox over fixed broker/agent IPC; Connector platform channel | Host-broker Ed25519 signature and exact intent/site/work bindings; Laravel verifies enrolled host key | Broker owns receipt/outbox; Laravel durably records once before ACK |
| Closed-site receipt, broker → HMR → Laravel | Fixed local IPC, then HMR outbound mTLS to pinned platform endpoint | Broker-signed receipt remains end-to-end signed; HMR host certificate authenticates transport only | Broker owns durable outbox; Laravel verifies receipt and exact host/site/work bindings without Connector/FPM |
| Receipt ACK, Laravel → HMR → broker | Same authenticated outbound HMR channel and fixed IPC | Platform-signed ACK; broker checks enrolled platform key, receipt ID/digest and unique work mapping/private generation | Laravel ACK record is durable before send; broker ACK record durable before pruning; ACK never opens gate |
| Closed-site recovery, Laravel → HMR → broker | HMR authenticated outbound management channel; fixed broker IPC | Distinct platform-signed Recovery Intent, Owner authorization, exact host/provider/tenant/site/Connector/receipt/restore/job/backup/scope, expiry, nonce, key ID; broker signature and replay checks | Laravel owns authorization; broker owns host gate/generation/replay state and accepts only a valid current-state transition |
| Manual fallback | Provider-authenticated console, fixed broker-owned typed submission/status interface, audited evidence transfer | Same signed Recovery Intent and broker validation; separate Owner operation approval and named operator; signed receipt/ACK retain normal verification | Provider console authenticates operator access, not recovery authorization; broker/Laravel remain authoritative for their respective state |

The HMR's mTLS identity is enrolled/revoked independently from platform signing keys. It is scoped to one registered host and only to the registered site set; each message carries a site ID that the broker maps locally to an immutable site/provider/host tuple. The relay cannot assert or rebind that mapping. HMR certificates, broker host-signing keys, Laravel intent/ACK keys, rotation overlap, revocation propagation, clock-skew/rollback policy, audit retention, payload bounds, retry limits and protocol version negotiation are mandatory contract-PR requirements. No single transport credential is accepted as a protocol signature.

### Outcome field-source and correlation invariants

| Signed message/result | Issuer-side source | Durable receiver-side record and acceptance rule |
|---|---|---|
| Signed Restore Intent | Laravel's committed restore/job authorization, immutable backup manifest, selected scope, tenant/site/active Connector and registered provider/host; platform key record | Broker verifies signature and exact bindings, then persists intent ID, exact envelope digest, nonce and one original restore_work_id before acceptance. |
| Signed Recovery Intent | Laravel's approved RECOVERY_REQUIRED receipt and exact signed receipt digest, original restore/job, a separately approved safety-backup manifest/scope, host/site identity and recovery key record; Laravel persists the exact signed envelope digest | Broker verifies all envelope fields and signature, current RECOVERY_REQUIRED state, exact private generation mapping and replay state; it creates one recovery_work_id and durably records the full tuple before returning the ID for Laravel to persist. |
| RESTORE_SUCCESS / SAFE_PRE_MUTATION_ABORT / RECOVERY_REQUIRED receipt | Broker's authoritative work journal, mutation evidence, immutable input manifest and current private generation; each result branch has its own strict schema | Laravel checks the branch against its exact normal Restore Intent and job/backup records; SAFE abort additionally requires durable no-mutation evidence, and RECOVERY_REQUIRED records the exact signed receipt ID/digest used by any later recovery. A RECOVERY_ATTEMPT result also matches one durable prior recovery authorization/work record and its exact failed-recovery nested binding. |
| RECOVERY_SUCCESS receipt | Broker's accepted recovery-work record, exact trigger receipt, accepted Recovery Intent signed-envelope digest, original work mapping, actual safety-backup input/manifest, scope and private generation | Laravel requires one exact durable recovery-authorization record matching the complete result and common tenant/site/Connector/provider/host bindings before it may transition to success or issue ACK v2. |
| Outcome-specific platform ACK v2 | Laravel's committed outcome transition, exact verified receipt, matching durable authorization/work record and ACK signing-key record | Broker matches receipt ID/digest, outcome and full result_binding to exactly one original or recovery work record, checks its private generation, then durably records ACK before receipt pruning. |

The recovery_work_id is assigned by the broker only after durable acceptance and returned as a bounded correlation value; Laravel persists it against the exact recovery authorization. If that response is lost, identical Recovery Intent retry returns the same ID. The value is not a bearer capability. The platform-signed Recovery Intent digest is computed over the exact canonical signed envelope including signature and key ID, and is never supplied by the worker.

### Crash/recovery sequence diagram

The first branch covers the ordinary Connector relay after the site is safely reopened. The fenced branch uses the independent management channel while PHP/FPM/Connector and public ingress are stopped. If that channel is unavailable, a separately Owner-authorized manual console path uses the same signed intent and broker validation; otherwise the fence remains.

~~~mermaid
sequenceDiagram
    participant L as Laravel platform
    participant C as Connector PHP
    participant A as Per-site agent
    participant B as Root broker / durable state
    participant H as Independent Host Management Relay
    participant O as Owner-authorized operator
    participant P as Provider host console
    participant W as Isolated restore worker
    participant D as Site DB + volume
    participant E as Site ingress / writers

    B->>B: Persist APPLYING and mutation_started for private generation g
    B->>W: Start exact accepted work
    W->>D: Apply authorized restore scope
    Note over W,D: Crash or ambiguous outcome; completion not proven
    B--xW: Process or host interruption
    B->>E: Keep target site ingress and all writers fenced
    B->>B: Reconcile journal; persist RECOVERY_REQUIRED; never TTL-unlock
    B->>B: Sign and durably retain exact RECOVERY_REQUIRED receipt

    alt HMR and platform channel available
        B->>H: Expose pending signed receipt via fixed local IPC
        H->>L: Relay exact receipt over outbound mTLS
        L->>L: Verify host signature/bindings; durably record once
        L-->>H: Return outcome-specific platform-signed ACK v2 binding receipt and exact result
        H->>B: Forward exact ACK via fixed broker IPC
        B->>B: Verify ACK, exact receipt/work map and private generation; persist before prune
        L->>L: Persist separate Owner recovery authorization and signed-envelope digest; bind exact safety backup/scope
        L-->>H: Deliver distinct signed Recovery Intent through management channel
        H->>B: Forward opaque intent via fixed IPC
        B->>B: Verify platform signature, identity, nonce/replay, expiry, receipt/work/generation and scope
        B->>E: Re-prove target writer and ingress fences; keep site closed
        B->>W: Start only the accepted recovery work
        W->>D: Apply exact authorized safety recovery
        W-->>B: Bounded result and evidence digest
        B->>B: Sign RECOVERY_SUCCESS with original work, trigger receipt digest, Recovery Intent envelope digest, recovery_work_id, actual safety backup/manifest/scope
        B->>E: Reopen in controlled order only after verified recovery success
        B->>H: Publish recovery receipt in durable outbox
        H->>L: Relay exact signed receipt while Connector/FPM may remain stopped
        L->>L: Durably process receipt and return stable outcome-specific signed ACK v2
        H->>B: Forward ACK; broker verifies mapping and persists before pruning
    else HMR or network unavailable
        Note over B,H: Retain receipt/state; no unlock, no Connector fallback, no destructive retry
        O->>P: Separately approved access to authenticated provider console
        B-->>P: Export exact signed RECOVERY_REQUIRED receipt via fixed read-only interface
        O->>L: Transfer receipt through approved audited evidence channel
        L->>L: Verify and durably record receipt; issue platform-signed ACK
        O->>P: Submit ACK via fixed broker interface
        P->>B: Broker verifies ACK and persists before pruning receipt bytes
        L->>L: Separately authorize exact recovery and issue signed Recovery Intent
        O->>P: Transfer Recovery Intent through audited console session
        P->>B: Submit signed intent only through fixed broker interface
        B->>B: Apply the same signature, replay, identity, work, scope and fence checks
        B->>W: Run exact recovery only after durable acceptance
        W->>D: Apply authorized recovery and verify
        B->>B: Persist/sign recovery receipt; release only after verified success
        B-->>P: Export signed receipt through fixed read-only interface
        O->>L: Transfer receipt through approved audited evidence channel
        L->>L: Verify and durably reconcile; issue platform-signed ACK
        O->>P: Submit ACK through fixed broker interface
        P->>B: Forward ACK; broker independently verifies and persists
        Note over B,E: If any fixed interface or evidence is unavailable, remain fenced and escalate
    end
~~~

The manual branch is a proposed contingency, not an alternate authorization system. Provider-console authentication does not permit an operator to fabricate a signed intent or bypass the broker. If the host/broker is unavailable or its journal is ambiguous, or if a fixed, auditable submission/export interface cannot be implemented and independently tested, manual destructive recovery remains unsupported; no ad hoc root command is an acceptable substitute.

## 10. Proposed #182 contract/API changes

Current RestoreIsolationGateProvider methods alone are insufficient for async host execution, signed authorization, worker handoff and terminal proof. Keep fail-closed behavior and prepare a separate contract-change PR after M17 review with these typed concepts:

- **SignedRestoreIntent:** validated payload/signature/key metadata.
- **RestoreArtifactManifest:** fixed scope/object enum, backup ID, expected sizes/SHA-256, digest and strict byte limit.
- **HostWorkAcceptance:** correlation and accepted/rejected/in-progress status; no host paths, secrets or internal generation.
- **begin_handoff(intent, manifest)** and **append_artifact(work_id, object_enum, bounded_stream):** asynchronous, idempotent staging; execute only after digest validation.
- **activate_handoff(work_id):** nonblocking request to begin durable acquire/drain saga; returns before caller FPM/CLI process is drained.
- **query_handoff(work_id):** bounded Connector retry status.
- **assert_active(internal_lease)** and generation-bound two-phase commit: broker/worker-side only; PHP is not the mutation executor.
- **take_terminal_receipt(work_id):** read broker’s durable signed receipt outbox; normal delivery uses Connector only after safe reopen, while closed-site delivery uses HMR.
- **HMR typed transport:** proposed `read_pending_signed_receipt`, `submit_platform_signed_receipt_ack`, `submit_platform_signed_recovery_intent`, and `read_channel_status`; transport-only, fixed schemas, bounded payloads, no arbitrary RPC. The HMR and platform channel do not become a new restore authorization source.
- **SignedRecoveryIntent:** distinct key purpose/domain and schema bound to the exact RECOVERY_REQUIRED receipt/digest, original restore/job, immutable safety backup/manifest, scope, tenant/site/Connector/provider/host, expiry and nonce; broker maps private generation internally and persists replay reservation before acceptance.
- **verify_and_ack_receipt(signed_ack):** verify the platform signature and exact stored receipt/work bindings, then resolve the unique accepted-work record and verify its broker-private generation from durable broker state. The signed ACK contains no generation. Missing, ambiguous, corrupt, or mismatched mappings fail closed. Durably record the verified ACK idempotently before any receipt pruning. This is never an unlock signal.
- Replace release(lease): bool with broker-owned finalization that validates its durable terminal record; Connector cannot submit a success boolean.
- Laravel receipt verifier: validate signature, host key enrollment, intent/manifest binding, outcome invariants, uniqueness and replay before a single transaction updates status.

Exact method signatures, result enums, schema migrations, key enrollment/rotation, backwards compatibility, and Laravel job-state mapping require contract review. Old Connector mutation code must not remain as fallback when provider is absent. Unsupported hosts continue to refuse before live mutation. Backup creation, verification and download remain independent.

The separate contract PR must make the following mandatory acceptance criteria explicit and testable: (1) nonce and intent-ID retention, replay prevention, and safe pruning; (2) receipt identity, durable outbox, platform-acknowledgement, retry, retention/pruning, and crash-recovery lifecycle; (3) host/platform key enrollment, rotation overlap, revocation, and compromised-key handling; (4) clock-skew bounds, monotonic/rollback behavior, expiry and reboot handling; (5) terminal-outcome invariants, including that SAFE_PRE_MUTATION_ABORT is impossible after any durable mutation-start evidence and RECOVERY_REQUIRED never reopens the site; and (6) ACK-to-work reconciliation using receipt ID/digest plus exact platform-known work bindings, with deterministic rejection tests for absent, ambiguous, corrupt, or wrong-generation broker mappings and proof that rejection preserves the receipt; (7) closed-site HMR transport authentication, host/site enrollment and scoping, outbound-only reconnect/retry, message integrity, and no dependence on PHP/FPM/Connector; (8) distinct recovery-intent signature purpose, exact RECOVERY_REQUIRED receipt/work/backup/scope/generation mapping, expiry/nonce/replay/revocation, and denial with durable fencing; (9) HMR/network loss, host reboot, lost/duplicate receipt or intent, and connector-stopped delivery; (10) ACK and receipt retention/pruning over HMR and manual fallback; and (11) manual-console receipt/intent/ACK transfer, dual authorization/audit and Laravel reconciliation, with no generic root command; (12) strict outcome-specific Terminal Receipt and ACK schemas, with field-source proofs and no outcome-dependent generic intent/backup/scope fields; (13) RECOVERY_SUCCESS correlation to exactly one persisted Laravel recovery authorization and broker recovery-work record, including trigger receipt ID/digest, Recovery Intent ID/exact signed-envelope digest, original intent/restore/job, recovery_work_id, actual safety backup/manifest and scope; and (14) negative tests for every missing/wrong/duplicate/stale/ambiguous binding and private-generation mismatch, proving no success transition, no gate release, no positive ACK and no receipt pruning. ADR v2 records these as unresolved contract requirements; it does not define final wire encodings, availability guarantees, recovery-time SLOs or operational proof.

### Outcome-specific platform-signed Terminal Receipt ACK v2

Laravel signs an ACK only after verifying the host-signed receipt, matching its outcome-specific binding to durable authorization/work records, and committing the corresponding idempotent platform state transition. It uses Ed25519 over RFC 8785 canonical JSON with a distinct domain such as `WPCAREGRID-TERMINAL-RECEIPT-ACK-V2\0`. The broker accepts only enrolled, currently valid platform ACK keys.

The ACK common envelope is `wp-caregrid.terminal-receipt-ack.v2`. It has a strict `outcome`-discriminated `result_binding` union; no generic `intent_id`, `backup_uuid`, or `scope` appears in the common envelope.

    {
      "schema": "wp-caregrid.terminal-receipt-ack.v2",
      "iss": "wpcaregrid-platform",
      "ack_id": "stable UUID for this receipt/outcome",
      "receipt_id": "host-signed receipt UUID",
      "receipt_sha256": "SHA-256 of exact signed receipt envelope bytes",
      "outcome": "RECOVERY_SUCCESS",
      "tenant_id": "immutable-tenant-id",
      "site_id": "immutable-website-id",
      "connector_id": "active-connector-id",
      "provider_id": "reviewed-provider-id",
      "host_id": "registered-host-id",
      "restore_uuid": "original restore UUID",
      "job_uuid": "original restore job UUID",
      "result_binding": {
        "schema": "wp-caregrid.restore-recovery-ack-binding.v2",
        "original_restore_intent_id": "original Signed Restore Intent UUID",
        "original_restore_work_id": "original accepted restore-work UUID",
        "recovery_trigger_receipt_id": "RECOVERY_REQUIRED receipt UUID",
        "recovery_trigger_receipt_sha256": "digest of exact signed trigger receipt envelope",
        "recovery_intent_id": "approved Recovery Intent UUID",
        "recovery_intent_envelope_sha256": "digest of exact signed Recovery Intent envelope",
        "recovery_work_id": "unique broker-accepted recovery-work UUID",
        "recovery_scope": "files|database|full",
        "safety_backup_uuid": "exact immutable backup actually used",
        "safety_manifest_sha256": "digest of exact safety-backup manifest",
        "recovery_mutation_started": true
      },
      "processed_at": "UTC RFC3339 timestamp",
      "key_id": "platform ACK signing-key ID"
    }

Laravel's `result_binding` is a strict outcome-specific schema and mirrors the Terminal Receipt v2 result exactly:

| ACK outcome | Required result-binding fields |
|---|---|
| RESTORE_SUCCESS | `schema=wp-caregrid.restore-success-ack-binding.v2`, `restore_intent_id`, `restore_work_id`, `backup_uuid`, `manifest_sha256`, `restore_scope`, `restore_mutation_started=true` |
| SAFE_PRE_MUTATION_ABORT | `schema=wp-caregrid.restore-safe-abort-ack-binding.v2`, `restore_intent_id`, `restore_work_id`, `backup_uuid`, `manifest_sha256`, `restore_scope`, `restore_mutation_started=false`, `no_mutation_proof_sha256` |
| RECOVERY_REQUIRED | `schema=wp-caregrid.restore-recovery-required-ack-binding.v2`, `failure_origin`, `original_restore_intent_id`, `original_restore_work_id`, `original_backup_uuid`, `original_manifest_sha256`, `original_restore_scope`, `overall_mutation_state=STARTED|AMBIGUOUS`, `failure_stage`; for RECOVERY_ATTEMPT, exact `failed_recovery` object (schema and fields from §7) is mandatory |
| RECOVERY_SUCCESS | `schema=wp-caregrid.restore-recovery-ack-binding.v2`, `original_restore_intent_id`, `original_restore_work_id`, `recovery_trigger_receipt_id`, `recovery_trigger_receipt_sha256`, `recovery_intent_id`, `recovery_intent_envelope_sha256`, `recovery_work_id`, `recovery_scope`, `safety_backup_uuid`, `safety_manifest_sha256`, `recovery_mutation_started=true` |

The ACK `outcome` must equal the signed receipt outcome and its binding must exactly mirror that receipt's corresponding result object. Unknown/missing or cross-outcome fields are rejected.

Laravel sources common values from the verified receipt and registered tenant/site/Connector/provider/host plus the original restore/job records; the result binding is copied from the signed receipt only after exact comparison with the durable source records. For RECOVERY_SUCCESS, Laravel also requires a unique durable recovery-authorization record containing the exact Recovery Intent ID and signed-envelope digest, trigger receipt ID/digest, original intent/work and restore/job, safety-backup UUID/manifest, scope and host/site identity. The ACK's `recovery_work_id` comes from the verified signed receipt and broker acceptance response, persisted in that same authorization/work record. `ack_id` and `processed_at` come from the durable ACK transaction; `key_id` comes from the platform signing-key record. No ACK field is sourced from broker-private generation state.

The broker verifies the platform signature and `receipt_sha256`, outcome, receipt ID, all common bindings, and the exact outcome-specific result binding. For RECOVERY_SUCCESS it must match the ACK to exactly one durable accepted recovery-work record, including the exact Recovery Intent envelope digest and trigger receipt digest, then verify the broker-private generation mapping and the receipt/outbox record. Missing, malformed, replayed, conflicting, wrong-attempt, wrong-backup/scope, ambiguous or wrong-generation binding means no ACK acceptance, no positive terminal reconciliation and no receipt pruning. Generation is never transmitted to Laravel or included in any signed platform message. Broker persists the verified ACK ID/digest and acknowledged state durably before pruning receipt bytes. A byte-identical retry is idempotent; a conflicting reuse is rejected. Missing/lost ACK retains the receipt; Laravel returns the same durable ACK after retry.

This is a proposed contract, not an implemented protocol. The future contract PR must define ACK/receipt retention and replay windows, stable outcome-specific ACK generation, crash recovery on both sides, host/platform key enrollment/rotation/revocation/compromise, clock policy, and strict schema-version migration with no ambiguous v1 fallback.

## 11. Answers to M17 v1 blockers

| Blocker | v2 proposal | Required evidence |
|---|---|---|
| Connector-to-host-worker handoff and FPM self-drain | Connector validates immutable input, submits async handoff, persists marker/outbox and returns. Broker drains after caller exits; separate UID/process/mount/DB worker applies. | Duplicate/disconnect/caller-exit/FPM timeout/process-tree/no-PHP-write tests. |
| Peer credentials are not authorization | Canonical Ed25519 Signed Restore Intent binds tenant/site/active Connector/restore/job/backup digest/scope/provider+host audience/expiry/nonce. Durable anti-replay. | Tamper, expiry, cross-site/tenant/provider, stale Connector, duplicate and nonce tests. |
| No terminal proof and receipt lifecycle | Broker validates worker evidence and persists/signs an outcome-specific Terminal Receipt. It binds the receipt/outbox to the broker-private generation in durable work state; generation is absent from the signed receipt and platform messages. Laravel durably processes the exact receipt and matching outcome-specific bindings before signing an ACK. The broker resolves its private generation through one unique durable work mapping before accepting the ACK or pruning. | Forged/missing/stale/conflicting receipt; forged ACK; wrong/missing/ambiguous generation-to-work mapping; crash/retry and no-prune-on-rejection tests. |
| Durable-state ownership conflict | Laravel owns business state; Connector owns relay marker/outbox; root broker owns enforcement/generation/replay journal. Contract amendment permits private host state, no platform/log exposure. | Crash-consistency/fsync review, reboot/reconciliation, corruption/mismatch fail-closed tests. |
| Least privilege | Per-site unprivileged agent, fixed root broker, isolated one-shot worker and site-specific FPM/UID/DB/volume. | Socket/cross-site tests, broker operation/syscall review, artifact pinning and escape tests. |
| Closed-site recovery transport | Independent HMR with host-scoped mTLS and fixed typed IPC; signed envelopes remain end-to-end verified; manual fallback uses the same envelopes through audited fixed console interfaces. | FPM/Connector-stopped receipt/ACK tests; signed recovery-intent/replay tests; HMR/network/reboot faults; manual audit and Laravel reconciliation tests; unavailable paths stay fenced. |
| Outcome-specific recovery proof | RECOVERY_SUCCESS and ACK v2 bind exact original restore, trigger receipt, approved Recovery Intent envelope digest, unique recovery work, actual safety backup/manifest/scope and host/site identity; broker checks its private generation mapping. | Field-source audit plus positive exact-match and negative wrong/missing/duplicate/old/ambiguous binding tests; no transition/ACK/prune/release on mismatch. |

These are proposed resolutions, not completed implementation or evidence.

## 12. Compatibility and conformance matrix

Before adding a provider ID to the allowlist, test:

- Intent canonicalization/signature/key rotation/expiry/clock rollback/nonce replay and exact duplicate delivery.
- Wrong tenant/site/Connector/job/backup/manifest/scope/provider/host/audience; unknown fields; stream interruption; digest mismatch; path traversal.
- Same handoff ID on Connector retry; no duplicate worker; HANDOFF_ACCEPTED never means success.
- FPM self-drain: PHP returns promptly; cgroup empties before worker start; child process, persistent connection, timeout and unknown process fail closed.
- CDN/cache/direct-origin IPv4/IPv6/aliases/ports/load balancers, all HTTP write routes and policy propagation.
- Concurrent HTTP, cron, Action Scheduler, queue, CLI, updater, deploy, DB and filesystem writers; open DB sessions and independent non-cooperating filesystem writer.
- Dedicated DB/volume credentials and sibling-site non-impact.
- Wrong/stale generation in broker-private accepted-work state, commit retry, receipt replay, missing/forged receipt, outcome invariants, early finalize request, broker/agent/worker termination at every transition.
- Signed ACK v2 origin and all platform-known bindings; each outcome ACK names the acknowledged receipt and strict result binding. For RECOVERY_SUCCESS, prove all Recovery Intent, trigger receipt, original work, unique recovery_work_id, actual backup/manifest and scope fields are available to Laravel from its receipt and durable authorization record. Missing, duplicate/ambiguous, corrupted, wrong-attempt, or wrong-generation receipt-to-work mapping rejects ACK and preserves the receipt. Assert no generation is transmitted in the ACK, Terminal Receipt, Connector marker or Laravel state.
- Power loss/reboot between file fsync, directory fsync, DB transaction, edge ack, marker publish, mutation intent, DB/file apply, receipt persistence and release. Corrupt/missing state boots closed.
- Stop WordPress, PHP-FPM and Connector and fence public ingress; prove HMR alone delivers the broker-signed RECOVERY_REQUIRED receipt to Laravel and relays the platform-signed ACK back without site-runtime execution.
- HMR mTLS host identity enrollment, wrong-host/wrong-site mapping, expired/revoked host certificate or platform key, endpoint/certificate/key rotation, lost channel, reconnect after host reboot, outbound-only restriction, bounded payloads and audit records.
- Lost, duplicated, reordered and delayed receipts, ACKs and recovery intents across HMR retries; exact duplicates are idempotent, conflicts/replays reject, and missing ACK retains the durable receipt.
- Recovery Intent denial, expired/revoked/replayed key and nonce, altered receipt digest, wrong site/tenant/Connector/host/work/backup/scope, stale private generation mapping, and corrupted/ambiguous work mapping; assert no worker/mutation, no unlock, and durable RECOVERY_REQUIRED state.
- RECOVERY_SUCCESS receipt field-source and Laravel authorization reconciliation: reject missing/wrong Recovery Intent ID or exact envelope digest; wrong original restore intent/work/restore/job; wrong trigger RECOVERY_REQUIRED receipt ID/digest; another safety backup or manifest; wrong scope/site/tenant/Connector/provider/host; another/duplicate recovery attempt; repeated, old, conflicting or ambiguous receipt; and wrong/missing/ambiguous private generation-to-recovery-work mapping, including every nested failed-recovery binding.
- For every negative recovery-receipt/ACK case, assert no successful platform state transition, no positive ACK, no gate release, no ACK acceptance, and no receipt pruning. Positive controls prove one exact accepted recovery attempt can be reconciled idempotently and ACKed once.
- Outcome-union schema tests: each of RESTORE_SUCCESS, SAFE_PRE_MUTATION_ABORT, RECOVERY_REQUIRED and RECOVERY_SUCCESS accepts only its own required fields; reject missing fields, unknown fields, cross-outcome result schemas, ambiguous generic intent/backup/scope fields, and inconsistent mutation/outcome invariants.
- Management relay/network outage before receipt delivery, during intent delivery, during recovery, and before ACK; assert no Connector fallback, automatic unlock, implicit success, destructive retry, or receipt pruning.
- Same-host sibling-site availability and isolation while HMR, broker and target-site gate operate; no relay identity or broker command can address another site.
- Manual fallback: separate Owner approvals, provider-console identity, fixed typed interface, signed receipt export, Laravel verification/ACK, exact signed Recovery Intent submission, audit completeness and reconciliation. Any unavailable/ambiguous evidence leaves the site fenced.
- Safe pre-mutation abort, recovery, ambiguous state and no automatic destructive retry.
- No token, signature, nonce, private key, credential, lease token or generation in logs.
- Independent provider-specific adversarial tests and proof sibling sites remain writable.

Mocks and Connector unit tests are necessary but not host compatibility evidence. Support is bound to exact vendor/plan/OS/runtime/edge/DB/storage/service configuration and provider artifact.

## 13. Implementation sequence after separate approval

1. **Contract PR:** amend #182 lifecycle for signed intent, async handoff, immutable inputs, terminal receipt, state ownership, HMR transport messages, distinct Recovery Intent and idempotent result ingestion. No allowlist.
2. **Protocol/conformance PR:** schemas, canonicalization/signature fixtures, replay/state model and handoff/result tests; no privileged broker or supported-host claim.
3. **Broker/agent/worker PR:** separate module, fixed site operations, unprivileged per-site agent, minimal root broker, isolated worker, durable state, receipt process.
4. **Disposable-host integration PR:** adversarial ingress/writer/drain/crash/reboot/recovery tests, sibling-site non-impact and runbook.
5. **Independent M17 review and Owner decision:** review exact profile/artifact; only then may an exact provider ID be separately approved for allowlisting. Staging and live restore each require independent operation-specific authorization.

M16 transaction emails and #191 backup-download implementation are separate workstreams and must not be mixed into provider PRs.

## 14. Owner decision request

This v2 adopts M17’s recommendations as proposals and asks for explicit final disposition:

1. Per-site agent + isolated runtime for dedicated/managed VPS only; shared/unknown remains unsupported.
2. Unprivileged site agent plus minimal fixed-operation root broker; reject general root RPC.
3. Signed Restore Intent v1 with durable host replay protection and §4 bindings.
4. Root-only provider-private durable generation state via reviewed #182 contract amendment; never expose generations/secrets to Laravel or logs.
5. Broker-verified signed Terminal Receipt; boolean-only release rejected.
6. Dedicated per-site DB instance and filesystem volume for first profile; defer shared-DB proxy.
7. Concrete per-site CDN/origin adapter and direct-origin closure tests required.
8. Named audited recovery roles, approval for destructive break-glass, no manual marker/lock deletion; HMR-unavailable recovery is allowed only through the fixed audited console interfaces with identical signed-intent checks and Laravel reconciliation.
9. Unknown/shared hosting fail-closed; allowlist only an exact independently tested profile.

M17 must explicitly accept or block this ADR and list residual gaps. Then request separate final Owner architecture/implementation authorization. Directional approval alone does not authorize implementation.

## 15. Current status and boundary

- This revision addresses M17 comments 6410907234 and 6411511806 with an independent closed-site HMR/manual fallback and strict outcome-specific terminal-receipt/ACK bindings; independent M17 acceptance remains pending.
- Customer backup/download is the default recovery capability. Host isolation is optional restore only for qualified hosts.
- #191 remains separate.
- Provider, HMR and manual-console interfaces are not implemented; COMPATIBILITY_RESTORE_ISOLATION_PROVIDER_IDS remains empty.
- No host profile is verified; no key is enrolled; no provider artifact/allowlist is approved.
- PR #182 fail-closed behavior remains. Generic PHP .maintenance path TOCTOU is not claimed solved. No receipt/ACK/recovery control path is implemented; this ADR only proposes HMR and a fixed-interface manual fallback.
- No staging/production action, live restore, backup deletion, retention/M12 change, or PHP cutover occurred.
- This ADR changes documentation only. M16 email-localization files are not in scope.
