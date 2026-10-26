# ADR M15-01 v2 — Asynchronous restore handoff to a per-site isolated host worker

- **Status:** Proposed for independent M17 AppSec re-review; final Owner architecture approval required before implementation
- **Date:** 2026-10-26
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

This ADR proposes a first host-provider architecture: an isolated per-site runtime with an unprivileged per-site agent and a minimal fixed-operation root broker. The agent accepts only a Laravel-authorized restore intent, stages immutable input, hands execution to a separate one-shot restore worker, and returns a cryptographically verifiable terminal receipt. The PHP Connector never performs live restore mutations in the supported provider path.

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
- Provider readiness data is advisory admission evidence, never evidence that a host gate is currently held.

### Component responsibilities

| Component | Authoritative responsibility | Must not do |
|---|---|---|
| Laravel platform | Tenant/business authorization, immutable backup selection, intent signing, workflow state, receipt verification and idempotent result ingestion | Claim isolation from readiness alone; directly release host gates |
| WordPress Connector/PHP | Authenticated polling, non-destructive manifest/object checks, bounded host transfer, durable handoff/outbox, relay of signed receipts | Apply files, swap live tables, wait inside FPM while FPM drains, choose paths/units/credentials |
| Per-site unprivileged agent | Typed per-site RPC, transfer coordination, status relay | Possess root credentials, bypass broker policy, gain cross-site authority |
| Minimal root broker | Verify intent and anti-replay, own authoritative gate/generation journal, control fixed site resources, launch/observe worker, validate terminal evidence and sign receipt | Expose shell/root RPC; trust PHP claims; unlock on TTL/process loss |
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

## 5. Connector-to-host-worker handoff

The current #182 path runs restore mutation in Connector PHP. The supported-provider path must replace that execution path with asynchronous host-owned work:

1. **Platform authorization:** Laravel stores the restore/job and exact immutable backup/manifest/scope binding, then returns the signed intent with the authenticated job. A duplicate request is rejected or resolves to the same active restore.
2. **Connector validation and transfer:** Connector validates the authenticated response and performs only non-destructive manifest/object checks. It streams bounded backup objects to the per-site agent. It does not unzip into the live site.
3. **Durable host acceptance:** Agent relays intent and stream to the broker. Broker verifies intent and bindings, records nonce/work reservation, validates complete object digests, and durably records ACCEPTED. It returns a bounded acknowledgement with only required correlation IDs; no host generation or lease secret is returned to Laravel.
4. **Durable Connector handoff:** Connector writes its restricted restore marker/outbox entry for the accepted work item and returns from the poll/request promptly. It records HANDOFF_ACCEPTED, not restore success. The marker is recovery linkage, not host enforcement.
5. **No FPM self-drain:** Broker starts gate acquisition asynchronously after handoff acceptance. It never waits for the calling PHP-FPM request inside that request. It closes ingress, then waits until the current request and all site cgroup descendants have exited. Only then does it stop/fence the dedicated pool and prove the cgroup empty. If the caller does not exit within the bound, restore mutation does not start; the site remains closed/blocked for reconciliation. It does not kill an unknown process and assume safety.
6. **Writer fencing and gate acquisition:** Broker confirms every control in §6, persists QUIESCENT for the exact internal generation, and only then allows worker launch.
7. **Isolated execution:** Broker starts the fixed verified worker under a separate restore-worker identity, private mount namespace, site-only writable volume, exact site DB credentials, bounded resources and no arbitrary network/subprocess access. FPM/Connector has no live write path.
8. **Authenticated result relay:** Broker verifies worker exit, work binding, durable mutation state, DB/filesystem evidence and required checks. It creates, signs and fsyncs a Terminal Receipt in its durable host outbox. The broker reopens the site only after the verified terminal state is durable and all release conditions pass. Once the Connector is safely available again, it retrieves the exact stored receipt through the agent's read-only typed interface and relays it to Laravel over the existing authenticated platform channel. Laravel durably processes the exact receipt before issuing a platform-signed ACK; the agent does not relay directly to Laravel.
9. **Idempotent retry and receipt lifecycle:** Interrupted delivery retransmits the same stored receipt. Laravel returns the same durable ACK for an already processed receipt; the broker retains the receipt until it verifies and durably records that signed ACK. Invalid, absent, mismatched, replayed, delayed, or lost ACKs cannot authorize pruning. Duplicate identical receipts/ACKs do not create another transition or work item. The ACK contains no broker generation: the broker resolves the ACK's receipt ID/digest and platform-known work bindings to exactly one durable accepted-work record, then checks that record's private generation against its receipt/outbox state. Missing, ambiguous, corrupt, or wrong-generation work mapping rejects the ACK and preserves the receipt. Conflicting, stale, invalidly signed, wrong-tenant/scope/backup receipts are rejected and cannot release the host gate. Exact retention, acknowledgement, pruning and recovery semantics are contract-PR acceptance criteria.

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
    L-->>C: Sign ACK from platform-known receipt/work bindings; no broker generation
    C->>A: Relay signed ACK through typed interface
    A->>B: Submit signed ACK; broker verifies signature/bindings
    B->>B: Resolve unique accepted work by receipt/digest + intent/site bindings
    B->>B: Verify private generation mapping; fail closed on missing/ambiguous/mismatch
    B->>B: Persist ACK idempotently; prune receipt only after durable ACK
~~~

Handoff does not authorize scanner jobs, backups, notifications, remediation, quarantine, deployment, or unrelated maintenance. The worker receives a restore-only typed operation.

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

## 7. Verified Terminal Receipt v1 proposal

Only the root broker creates a terminal receipt, after independently checking the accepted intent, internal current generation, worker identity/exit, durable mutation marker, exact input manifest, output/evidence digest, DB/filesystem result, and required non-mutating health checks. Worker cannot release the gate or sign a host receipt. Broker stores the receipt durably before a release transition. A caller-supplied success flag is insufficient.

The receipt is canonical JSON signed with a site/provider-specific Ed25519 host key. Laravel pins the public key through a separately approved provider-enrollment flow. It includes:

    {
      "schema": "wp-caregrid.restore-terminal.v1",
      "issuer": "registered-host-broker",
      "audience": "wpcaregrid-platform",
      "receipt_id": "uuid",
      "intent_id": "uuid",
      "tenant_id": "immutable-tenant-id",
      "site_id": "immutable-website-id",
      "connector_id": "active-connector-id",
      "restore_uuid": "uuid",
      "job_uuid": "uuid",
      "backup_uuid": "uuid",
      "manifest_sha256": "64-lowercase-hex",
      "scope": "files|database|full",
      "outcome": "RESTORE_SUCCESS|SAFE_PRE_MUTATION_ABORT|RECOVERY_SUCCESS|RECOVERY_REQUIRED",
      "mutation_started": true,
      "failure_stage": "bounded-enum-or-null",
      "evidence_sha256": "64-lowercase-hex",
      "started_at": "UTC RFC3339 timestamp",
      "finished_at": "UTC RFC3339 timestamp",
      "host_key_id": "registered-host-signing-key-id"
    }

The receipt omits the internal generation and lease token. Broker durably binds intent/receipt IDs to the exact internal generation, so the signed receipt is generation-bound without disclosing that generation to Laravel or logs. Platform verifies it against the original intent and atomically records its unique receipt ID and final state. Identical replay is idempotent; conflicting replay is rejected.

Outcome rules:

- **RESTORE_SUCCESS:** apply and approved integrity/health checks completed; broker persists terminal evidence; gate may reopen in controlled order.
- **SAFE_PRE_MUTATION_ABORT:** broker proves no live DB/file write intent or mutation occurred and the work item is abandoned. Missing proof forbids this outcome.
- **RECOVERY_SUCCESS:** separately authorized recovery completed and passed checks under a new bound recovery intent/generation. Only then may the broker reopen.
- **RECOVERY_REQUIRED:** mutation began or state is ambiguous, checks failed, evidence is missing, or a critical process crashed. Gate stays closed. This receipt reports state; it never authorizes release.

Before the first live DB/file write, broker durably records mutation_started=true and verifies the active gate. A crash between that record and the first write is conservatively treated as destructive/ambiguous. No automatic destructive compensation is allowed. Pre-release health checks must be enumerated and proven non-mutating. If WordPress boot requires writes, use an isolated verification copy or keep the site closed for manual review.

## 8. Durable state ownership, atomicity and reconciliation

### Authoritative state

- **Laravel database:** business request, selected backup/scope, tenant authorization, platform job and customer-facing state. Stores signed-intent digest/key IDs and verified receipt, not host generation, lease token, broker journal or secrets.
- **Connector marker/outbox:** durable relay pointer from Connector job to handoff/receipt. Not authority to open the host gate.
- **Root broker state store:** authoritative host enforcement state. Stores accepted intent ID/hash/nonce, work state, site/restore/job binding, internal generation and previous/pending/current generations, control acknowledgements, mutation state and receipt/outbox state.
- **Per-site agent:** ephemeral orchestration/cache only.
- **Restore worker:** immutable accepted input plus ephemeral work; never gate/terminal authority.

This explicitly amends the v1 interpretation of #182’s marker-only wording: the provider needs protected durable enforcement state to survive restart. Proposed #182 follow-up must permit root-only provider-private generation state while prohibiting exposure to Laravel, WordPress/PHP and logs. Connector marker remains minimal recovery linkage. Owner and M17 approval are required before implementation.

### Persistence and external-effect ordering

1. Laravel commits the authorized restore/job and manifest binding before signing. A transactional outbox makes retries return the same intent identity, or a new same-job intent only if the earlier one expired/unaccepted and no host work exists.
2. Broker verifies intent and atomically reserves unique intent/nonce in RECEIVING before acknowledgement.
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
| Before intent acceptance | Laravel may redeliver same job; no accepted host work or live side effect exists. |
| During transfer | Resume same intent/object digests. Partial data is quarantined/non-executable. |
| After ACCEPTED, before gate | Query returns same work item; activation idempotent. Cancellation only after broker reconciles its own effects and proves no mutation. |
| During caller/FPM drain | Worker does not start. Unknown writer means remain closed and require reconciliation. |
| During gate acquire | Resume same generation and inspect each control. No unrelated generation. |
| Before first live mutation | Safe abort only with durable proof no mutation intent/write occurred; reopen only after own control state is reconciled. |
| During/after DB or file mutation | No automatic duplicate restore. Preserve artifacts/gate; issue RECOVERY_REQUIRED and require separately authorized recovery. |
| After worker result, before receipt persistence | Reconstruct only from durable evidence; otherwise recovery required and closed. |
| After receipt persistence, before opening | Revalidate stored receipt/generation; continue idempotently or remain closed. |
| After opening, before platform receipt delivery | Retry identical signed receipt from outbox. Laravel records once; no second worker. |
| Reboot or state corruption | Boot holds ingress/writers closed before site services start; manual audited reconciliation. |

A new restore cannot start while any prior restore is active, terminal-but-unreported or recovery-required for that site. Platform and broker enforce one active restore per site. Worker retry is not full restore replay; post-mutation ambiguity requires recovery.

Recovery uses named roles, exact site/restore/work/backup/scope, dual approval where policy requires, bounded audited actions and integrity evidence. Operators cannot clear state by deleting .maintenance, removing a lock or marking a job successful.

### Crash/recovery sequence diagram

The diagram shows a crash after the durable mutation boundary but before terminal receipt publication. The broker does not infer success from process exit or an absent Connector marker. A separately authorized recovery intent is required; if integrity cannot be established, the gate stays closed.

~~~mermaid
sequenceDiagram
    participant B as Root broker / durable state
    participant W as Isolated restore worker
    participant D as Site DB + volume
    participant C as Connector relay
    participant L as Laravel
    participant O as Authorized recovery operator
    B->>B: Persist APPLYING and mutation_started for generation g
    B->>W: Start exact accepted work item
    W->>D: Apply selected restore scope
    Note over W,D: Host or worker crashes; completion is not proven
    B--xW: Process/host loss
    Note over B: Restart boot holds ingress and all writers closed
    B->>B: Reconcile journal, controls and exact work binding
    B->>B: Outcome ambiguous; persist RECOVERY_REQUIRED
    B->>B: No TTL unlock; do not rerun original restore
    B-->>C: Signed RECOVERY_REQUIRED receipt in durable outbox, if safely reconstructable
    C->>L: Relay receipt (may be retried)
    L->>L: Verify signature/bindings; mark manual recovery required
    O->>L: Separately authorize recovery of exact site/restore/scope
    L->>C: New recovery intent bound to safety backup and current restore
    C->>B: Relay signed recovery intent
    B->>B: Verify intent, replay state, current gate; retain prior generation
    B->>W: Launch isolated recovery worker only after writer/ingress proof
    W->>D: Restore safety source and run bounded verification
    W-->>B: Recovery outcome + evidence digest
    B->>B: Verify outcome; persist and sign RECOVERY_SUCCESS receipt
    B->>B: Reconcile/open in controlled order; ingress opens last
    B-->>C: Durable signed receipt
    C->>L: Relay same receipt idempotently
    L->>L: Verify and record terminal recovery once
    Note over B,D: If any evidence/control is missing or recovery fails, remain closed
~~~

## 10. Proposed #182 contract/API changes

Current RestoreIsolationGateProvider methods alone are insufficient for async host execution, signed authorization, worker handoff and terminal proof. Keep fail-closed behavior and prepare a separate contract-change PR after M17 review with these typed concepts:

- **SignedRestoreIntent:** validated payload/signature/key metadata.
- **RestoreArtifactManifest:** fixed scope/object enum, backup ID, expected sizes/SHA-256, digest and strict byte limit.
- **HostWorkAcceptance:** correlation and accepted/rejected/in-progress status; no host paths, secrets or internal generation.
- **begin_handoff(intent, manifest)** and **append_artifact(work_id, object_enum, bounded_stream):** asynchronous, idempotent staging; execute only after digest validation.
- **activate_handoff(work_id):** nonblocking request to begin durable acquire/drain saga; returns before caller FPM/CLI process is drained.
- **query_handoff(work_id):** bounded Connector retry status.
- **assert_active(internal_lease)** and generation-bound two-phase commit: broker/worker-side only; PHP is not the mutation executor.
- **take_terminal_receipt(work_id):** read broker’s durable signed receipt outbox.
- **verify_and_ack_receipt(signed_ack):** verify the platform signature and exact stored receipt/work bindings, then resolve the unique accepted-work record and verify its broker-private generation from durable broker state. The signed ACK contains no generation. Missing, ambiguous, corrupt, or mismatched mappings fail closed. Durably record the verified ACK idempotently before any receipt pruning. This is never an unlock signal.
- Replace release(lease): bool with broker-owned finalization that validates its durable terminal record; Connector cannot submit a success boolean.
- Laravel receipt verifier: validate signature, host key enrollment, intent/manifest binding, outcome invariants, uniqueness and replay before a single transaction updates status.

Exact method signatures, result enums, schema migrations, key enrollment/rotation, backwards compatibility, and Laravel job-state mapping require contract review. Old Connector mutation code must not remain as fallback when provider is absent. Unsupported hosts continue to refuse before live mutation. Backup creation, verification and download remain independent.

The separate contract PR must make the following mandatory acceptance criteria explicit and testable: (1) nonce and intent-ID retention, replay prevention, and safe pruning; (2) receipt identity, durable outbox, platform-acknowledgement, retry, retention/pruning, and crash-recovery lifecycle; (3) host/platform key enrollment, rotation overlap, revocation, and compromised-key handling; (4) clock-skew bounds, monotonic/rollback behavior, expiry and reboot handling; (5) terminal-outcome invariants, including that SAFE_PRE_MUTATION_ABORT is impossible after any durable mutation-start evidence and RECOVERY_REQUIRED never reopens the site; and (6) ACK-to-work reconciliation using receipt ID/digest plus exact platform-known work bindings, with deterministic rejection tests for absent, ambiguous, corrupt, or wrong-generation broker mappings and proof that rejection preserves the receipt. ADR v2 records these as unresolved contract requirements; it does not define their final wire-level policy.

### Proposed platform-signed Terminal Receipt ACK v1

Laravel emits an ACK only after it has durably processed the exact signed receipt and committed the corresponding idempotent platform state transition. The ACK is a protocol message signed with an Ed25519 platform key over RFC 8785 canonical JSON with a domain separator such as `WPCAREGRID-TERMINAL-RECEIPT-ACK-V1\0`. The broker trusts only explicitly enrolled platform public keys and verifies the signature before accepting the ACK.

Proposed signed fields (all are covered by the signature):

    {
      "schema": "wp-caregrid.terminal-receipt-ack.v1",
      "iss": "wpcaregrid-platform",
      "ack_id": "uuid",
      "receipt_id": "uuid",
      "receipt_sha256": "64-lowercase-hex-of-exact-stored-signed-receipt-envelope",
      "intent_id": "uuid",
      "restore_uuid": "uuid",
      "job_uuid": "uuid",
      "tenant_id": "immutable-tenant-id",
      "site_id": "immutable-website-id",
      "connector_id": "active-connector-id",
      "provider_id": "reviewed-provider-id",
      "host_id": "registered-host-id",
      "processed_at": "UTC RFC3339 timestamp",
      "key_id": "platform-ack-signing-key-id"
    }

Every signed ACK field is available to Laravel from the receipt it received, its durable restore/job/site/tenant records, the active Connector and registered provider/host bindings, or its durable ACK/signing-key record; none requires reading broker-private state. The broker verifies the ACK signature and platform-known bindings, finds exactly one accepted-work record by the exact receipt ID and digest plus intent/restore/job/tenant/site/Connector/provider/host bindings, then checks the receipt's durable broker-private generation against that record and outbox entry. Generation is never sent to Laravel, included in the Terminal Receipt, or accepted from the ACK. Missing, duplicate/ambiguous, corrupted, or wrong-generation mappings reject ACK acceptance and leave the receipt unpruned. Only after successful verification does the broker atomically persist the ACK identity/digest and acknowledged receipt state (with implementation-reviewed durability guarantees) before pruning receipt bytes. A repeated identical ACK is an idempotent no-op; conflicting ACK IDs or bindings are rejected. A missing, invalid, late, replayed, or lost ACK never triggers automatic receipt deletion. If the platform committed ingestion but the ACK was lost, it returns the same durable ACK on receipt retry; pruning remains prohibited until the broker receives and commits it.

This is an architecture contract proposal, not an implemented protocol. The contract PR must define nonce and ACK-ID retention/pruning, stable ACK replay behavior, receipt/ACK recovery after either-side crash, public-key enrollment/rotation/revocation and compromise response, clock skew/rollback, and terminal-outcome invariants with deterministic negative and crash tests.

## 11. Answers to M17 v1 blockers

| Blocker | v2 proposal | Required evidence |
|---|---|---|
| Connector-to-host-worker handoff and FPM self-drain | Connector validates immutable input, submits async handoff, persists marker/outbox and returns. Broker drains after caller exits; separate UID/process/mount/DB worker applies. | Duplicate/disconnect/caller-exit/FPM timeout/process-tree/no-PHP-write tests. |
| Peer credentials are not authorization | Canonical Ed25519 Signed Restore Intent binds tenant/site/active Connector/restore/job/backup digest/scope/provider+host audience/expiry/nonce. Durable anti-replay. | Tamper, expiry, cross-site/tenant/provider, stale Connector, duplicate and nonce tests. |
| No terminal proof | Broker validates worker evidence, persists/signs generation-bound Terminal Receipt; only valid terminal state can release. Laravel verifies before user-visible success. | Forged/missing/stale/conflicting receipt and crash-before/after-persist tests. |
| Durable-state ownership conflict | Laravel owns business state; Connector owns relay marker/outbox; root broker owns enforcement/generation/replay journal. Contract amendment permits private host state, no platform/log exposure. | Crash-consistency/fsync review, reboot/reconciliation, corruption/mismatch fail-closed tests. |
| Least privilege | Per-site unprivileged agent, fixed root broker, isolated one-shot worker and site-specific FPM/UID/DB/volume. | Socket/cross-site tests, broker operation/syscall review, artifact pinning and escape tests. |

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
- Wrong/stale generation, commit retry, receipt replay, missing/forged receipt, outcome invariants, early finalize request, broker/agent/worker termination at every transition.
- Power loss/reboot between file fsync, directory fsync, DB transaction, edge ack, marker publish, mutation intent, DB/file apply, receipt persistence and release. Corrupt/missing state boots closed.
- Safe pre-mutation abort, recovery, ambiguous state and no automatic destructive retry.
- No token, signature, nonce, private key, credential, lease token or generation in logs.
- Independent provider-specific adversarial tests and proof sibling sites remain writable.

Mocks and Connector unit tests are necessary but not host compatibility evidence. Support is bound to exact vendor/plan/OS/runtime/edge/DB/storage/service configuration and provider artifact.

## 13. Implementation sequence after separate approval

1. **Contract PR:** amend #182 lifecycle for signed intent, async handoff, immutable inputs, terminal receipt, state ownership and idempotent result ingestion. No allowlist.
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
8. Named audited recovery roles, approval for destructive break-glass, no manual marker/lock deletion.
9. Unknown/shared hosting fail-closed; allowlist only an exact independently tested profile.

M17 must explicitly accept or block this ADR and list residual gaps. Then request separate final Owner architecture/implementation authorization. Directional approval alone does not authorize implementation.

## 15. Current status and boundary

- Customer backup/download is the default recovery capability. Host isolation is optional restore only for qualified hosts.
- #191 remains separate.
- Provider is not implemented; COMPATIBILITY_RESTORE_ISOLATION_PROVIDER_IDS remains empty.
- No host profile is verified; no key is enrolled; no provider artifact/allowlist is approved.
- PR #182 fail-closed behavior remains. Generic PHP .maintenance path TOCTOU is not claimed solved.
- No staging/production action, live restore, backup deletion, retention/M12 change, or PHP cutover occurred.
- This ADR changes documentation only. M16 email-localization files are not in scope.
