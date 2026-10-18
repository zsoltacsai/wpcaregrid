# M147-E.2a — Staging identity and safety preflight evidence

**Status: BLOCKED BEFORE CONNECTING — NO LIVE STAGING ACCESS PERFORMED.**

This report records the authorization preflight and offline evidence available at the time of review. It does not claim that a staging identity, deployed revision, M12 gate, runtime configuration, or operational state was checked. The hostname `staging.wpcaregrid.com` remains a candidate from planning documentation only.

## Authorization record

The Owner subsequently supplied an execution-record draft explicitly marked **DRAFT — NOT APPROVED FOR EXECUTION**. It clarifies candidate values but is not an authorization to connect. The stop rule therefore remains in force.

| Required field | Status | Evidence / missing information |
|---|---|---|
| Exact staging target and endpoint | DRAFT / UNVERIFIED | `staging.wpcaregrid.com` is a candidate; endpoint and live identity are not verified. Application path `/var/www/wpcaregrid`, SSH user `deploy`, and port 22 are repository-documented candidates, not live facts. |
| Named authorized operator | DRAFT / INCOMPLETE | “Codex, owner supervision” is proposed; actual SSH account and permission are explicitly unverified. |
| Approved access method | DRAFT / NOT APPROVED | SSH is proposed, conditional on authenticated server identity. No connection authorization was issued. |
| Trusted host identity pin | MISSING — BLOCKER | No SSH host-key fingerprint or equivalent trusted pin was supplied. |
| Execution window and timezone | MISSING — BLOCKER | `Europe/Budapest` is specified, but start and end times are absent. |
| Exact allowed operations | DRAFT / INCOMPLETE | RO-00 through RO-09 are named, but exact commands/interfaces are not supplied; RO-05 through RO-09 are explicitly blocked pending method review. The draft also requires operation-level approval. |
| Evidence storage and redaction method | DRAFTED | Redacted Markdown in the GitHub PR is specified; raw secrets/configuration are prohibited. This does not itself authorize live collection. |
| Stop authority | DRAFTED | Owner is named. |
| Recovery contact | DRAFTED | Owner is named; recovery execution is not authorized. |
| Authorization reference / final Owner approval | MISSING — BLOCKER | No authorization reference was issued; the record remains DRAFT / DEFER / NO-GO. |

**Decision:** the missing/unapproved fields are mandatory pre-connection controls. The draft itself states that it does not authorize a connection. No credential discovery, host-key discovery, or alternative access path was attempted.

### Drafted operation inventory (not approved or executed)

| ID | Proposed check | Draft status | Execution |
|---|---|---|---|
| RO-00 | SSH server identity | BLOCKED — fingerprint missing | NOT EXECUTED |
| RO-01 | Server identity, operator and UTC time | Approval required | NOT EXECUTED |
| RO-02 | Installed application Git SHA | Approval required | NOT EXECUTED |
| RO-03 | PHP/runtime metadata | Approval required | NOT EXECUTED |
| RO-04 | Free disk capacity | Approval required | NOT EXECUTED |
| RO-05 | Six M12 effective booleans | BLOCKED — read-method safety review | NOT EXECUTED |
| RO-06 | Effective scanner/feed/security settings | BLOCKED — exact read method needed | NOT EXECUTED |
| RO-07 | Worker/scheduler state | BLOCKED — exact privilege and command needed | NOT EXECUTED |
| RO-08 | WordPress/Connector identity and pairing | BLOCKED — exact target/interface needed | NOT EXECUTED |
| RO-09 | Backup/notification routing and recovery metadata | BLOCKED — safe read interface needed | NOT EXECUTED |

The draft names categories, not an executable allowlist. Each command/interface still requires offline side-effect review and operation-level approval before any future connection.

## Offline repository evidence

| Item | Result |
|---|---|
| Repository | `zsoltacsai/wpcaregrid` |
| Authoritative branch | `claude/ecstatic-wozniak-oemyuf` |
| Fetched primary SHA | `9e11c2035cd343b8cc1615fee4dbcf4a34c16cd9` |
| E.1 closure | PR #127 and closure PR #128 are recorded as merged in primary documentation. |
| E.1 merged-primary CI | Run `39133033349`, documented 7/7 PASS. |
| Offline review timestamp | 2026-10-18 14:15 UTC (repository/documentation review only). |
| Live execution timestamps | None; no live operation was performed. |

Reviewed planning evidence: `m14-7-e1-closure-report.md`, `m14-7-e1-readiness-plan.md`, `m14-7-e1-staging-runbook.md`, `m14-7-e1-risk-matrix.md`, and `m14-7-e1-owner-approval-checklist.md`, plus the M14.7 plan, test matrix, findings register, Owner decision register, and project status. This is offline documentation/source evidence, not proof of deployed staging state.

## Staging identity and runtime results

| Required evidence | Result |
|---|---|
| Hostname / host identity | UNKNOWN — not contacted or independently verified. |
| Laravel environment identity / deployed SHA | UNKNOWN — no staging access. |
| PHP and database runtime | UNKNOWN — no staging access. |
| WordPress installation identity/version | UNKNOWN — no staging access. |
| Connector installed version/API/fingerprint | UNKNOWN — repository expectation is 0.4.7 / API v1; installed artifact is unverified. |
| Connector pairing and tenant/site identity | UNKNOWN — no pairing interface was queried. |
| Production/customer linkage | UNKNOWN — no staging inspection performed. |

Any mismatch, unexpected customer/production identity, or inability to independently bind the app, database, storage, and WordPress target must stop a future authorized preflight.

## M12 live gate values

No gate was read. Repository defaults and historical evidence are not substituted for live values.

| Gate | Effective live value | Environment/method/time | Safe read completed |
|---|---|---|---|
| `M12_DESTRUCTIVE_RETENTION_ENABLED` | UNKNOWN | Not read | No |
| `M12_PROFILE_ANONYMIZATION_ENABLED` | UNKNOWN | Not read | No |
| `M12_BACKUP_DELETION_ENABLED` | UNKNOWN | Not read | No |
| `M12_PROVIDER_DELETION_ENABLED` | UNKNOWN | Not read | No |
| `M12_BULK_RETENTION_EXECUTION_ENABLED` | UNKNOWN | Not read | No |
| `M12_SYNTHETIC_PROFILE_ANONYMIZATION_ENABLED` | UNKNOWN | Not read | No |

All six remain **UNKNOWN**. M147-E.2b is **NO-GO / NOT AUTHORIZED**. A future read must use an explicitly approved, proven side-effect-free method and return only each effective boolean, environment identity, method ID, and UTC timestamp. Any TRUE, UNKNOWN, unreadable, stale, or ambiguous result is NO-GO; values must not be changed to pass.

## Scanner and operational readiness

| Area | Result |
|---|---|
| Effective scanner types, limits, schedule, concurrency, timeout/cancellation | UNKNOWN — no live configuration read. |
| Feed provider, URL status, credential-presence status, index freshness | UNKNOWN — no live configuration read and no feed request. Live provenance/licensing remains open under M147-F03. |
| Queue driver, workers, pending/reserved/failed jobs, active scans | UNKNOWN — no queue interface used. No jobs were claimed, dispatched, retried, or cleared. |
| Scheduler status | UNKNOWN — no scheduler interface invoked. |
| Incident and notification routing | UNKNOWN — no routing configuration inspected or notification sent. |
| Backup destination and isolation | UNKNOWN — no storage metadata read and no backup created. |
| Filesystem isolation and free capacity | UNKNOWN — no staging filesystem access. |
| Recovery evidence for this exact environment | UNKNOWN — no live backup/recovery evidence inspected; no recovery operation performed. |

Offline repository evidence remains relevant only as a risk signal: E.1 records that `SecurityScanService::finish()` may persist scan/finding/audit/posture state and may open incidents and request a full backup; `config/security.php` defaults incident auto-open and auto-backup to true. Effective staging settings and destinations are unknown. M147-F04 remains open, so a later scan is not authorized by this preflight task.

## Operation audit trail

| Operation | Approved method | Time | Classification | Evidence / side effects |
|---|---|---|---|---|
| Repository primary refresh and SHA verification | Git fetch/read-only local repository inspection | 2026-10-18, before report preparation | COMPLETED — OFFLINE | Primary SHA `9e11c2035cd343b8cc1615fee4dbcf4a34c16cd9`; no staging connection. |
| E.1 planning and closure document review | Read-only local file inspection | 2026-10-18, before report preparation | COMPLETED — OFFLINE | Planning and closure evidence listed above; no live settings inferred. |
| Staging identity, M12 gates, runtime and operational state | None; authorization preflight stopped | Not applicable | BLOCKED — NOT EXECUTED | No staging SSH/API/HTTP/database request, credentials, secret, or live data accessed. |

## Decision

**Decision A — read-only preflight:** **BLOCKED**. Required execution authorization fields were missing, so live checks could not safely begin.

**Decision B — M147-E.2b readiness:** **NO-GO**. The execution authorization is incomplete and required staging identity, all six gates, scanner/feed configuration, queue/routing, isolation, capacity, and exact-environment recovery evidence remain unknown. This is not a claim that a live unsafe condition was observed; the prerequisite evidence is absent.

## Scope compliance and next authorization

No scanner, feed download/synchronization, queue job, incident, notification, backup, restore, remediation, quarantine, deployment, or configuration modification occurred. No production/customer system or secret was accessed. This report does not authorize M147-E.2b, M147-F, or M14.8.

Before a future E.2a attempt, the Owner must provide a completed execution record with the exact target, named operator, approved access interface, trusted identity pin, active execution window, exact read-only operations, evidence handling, stop authority, recovery contact, and authorization reference. The reviewer must then validate each command offline for side effects before any connection. A successful read-only preflight still requires separate explicit Level 3 approval for scanner execution.

## Authorized RO-00 attempt — 2026-10-18

Owner authorized RO-00–RO-04 for 18:00–18:30 Europe/Budapest. During the window (observed 2026-10-18 16:00:09–16:02:15 UTC), RO-00 was attempted only via unauthenticated SSH key scanning to the approved endpoint on port 22, requesting the ED25519 host key. The server returned an SSH identification banner, but key exchange failed locally with `choose_kex: unsupported KEX method sntrup761x25519-sha512@openssh.com`. A second attempt to provide a curve25519 KEX option was rejected because this `ssh-keyscan` client does not support that option. No host key or fingerprint was obtained or compared.

No SSH authentication, credential use, remote command, or authenticated staging access occurred. RO-00 is BLOCKED; RO-01–RO-04 were NOT EXECUTED under the stop rule. This was an unauthenticated SSH protocol contact only. No M12 gate, scanner, feed, queue, backup, restore, configuration, or deployment operation occurred. All six live M12 values remain UNKNOWN; M147-E.2b remains NO-GO / NOT AUTHORIZED.

This supersedes the earlier statement that no host-key discovery was attempted: an authorized unauthenticated keyscan was attempted, but it did not retrieve a key. It does not change the earlier finding that no authenticated live staging access occurred.

## Local SSH client diagnosis (2026-10-18)

A local-only follow-up found that the Windows OpenSSH 9.5p2 KEX list excludes the method named in the failed RO-00 attempt, while a locally installed Git for Windows OpenSSH 10.5p1 client advertises it. This supports a client-build compatibility cause but does not establish the exact LibreSSL/build-time cause or the server's full KEX offer. No network connection was made during the diagnostics pass. A separately authorized retry proposal, provenance caveat, binary versions, and remaining risks are recorded in [local SSH diagnostics](m14-7-e2a-local-ssh-diagnostics.md).


## Utólagos RSA fingerprint egyeztetés — 2026-10-18 19:16 UTC

A helyi publikus `id_rsa.pub` frissen újraszámított SHA-256 fingerprintje: `SHA256:Cy8+tFoYq7gsUPjpPBCqnNOvdzksvB6VsLE4AwdunkU` (RSA, 3072 bit), a Git for Windows hivatalos release-hez byte-egyezéssel ellenőrzött `ssh-keygen.exe -lf <USERPROFILE>\.ssh\id_rsa.pub -E sha256` módszerrel. Az Owner legutóbb közölt teljes értéke pontosan azonos, és az Owner közlése szerint a Rackforest konzolon külön ellenőrizte a `root` és `deploy` `authorized_keys` kulcsát. Ezen közlések összehasonlítása alapján **PUBLIC KEY FINGERPRINT MATCH: PASS**. A szerveroldali állományokat és konzolt Codex nem vizsgálta; ez Owner által jelentett független forrás.

A korábban kapott `SHA256+tFo...` hibás átírás és az akkori eltérés/UNKNOWN eredmény változatlanul történeti bizonyíték. Ez a későbbi, teljes fingerprinttel végzett reconciliation nem állítja át visszamenőleg az eredeti RO-00 futás akkori kapu-eredményét PASS-ra. Az eredeti keyscan során látott host ED25519 fingerprint külön, Owner által utólag megerősített értéke változatlanul dokumentált; azóta új keyscan vagy SSH kapcsolat nem történt.

A publikus RSA fingerprint egyezése **nem** bizonyítja, hogy a privát kulcs elérhető, hogy SSH autentikáció működik, vagy hogy a `deploy` fiók használata engedélyezett. Mindhárom **UNKNOWN**; privát kulcsot/agentet/credential store-t nem nyitottunk meg és nem teszteltünk. RO-01–RO-04 és RO-05–RO-09 nem futottak. Mind a hat élő M12 gate **UNKNOWN**; M147-E.2b **NO-GO / NOT AUTHORIZED**.

## M14.7 full-closure evidence reconciliation — 2026-10-18 19:38 UTC

No staging operation was performed during this review: no SSH connection/authentication, remote command, M12 read, scanner/feed request, queue/scheduler action, incident, notification, backup, restore, configuration change or deployment.

### Host and public-key identity history

Approved keyscan evidence records ED25519 fingerprint SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ. The Owner later reported independently computing this same value in the Rackforest console using ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256. The original authorization record contained a mistranscribed expected value. The prior observation is independently reconciled after execution; the original run remains FAILED against its then-supplied comparison and is not retroactively marked PASS. No new keyscan or connection occurred.

Fresh local metadata-only checking of id_rsa.pub yielded 3072 SHA256:Cy8+tFoYq7gsUPjpPBCqnNOvdzksvB6VsLE4AwdunkU (RSA), exactly matching the complete RSA fingerprint in the Owner's latest record. The Owner reports this fingerprint for both server root and deploy authorized_keys. This confirms public-key fingerprint correspondence only. A local private-key file exists by metadata (2,622 bytes); its content was not read. Private-key correspondence/usability, SSH authentication and account authorization remain UNKNOWN.

| Operation | Current status |
|---|---|
| RO-00 | Prior keyscan observation reconciled to independent Owner host-key value; original comparison-gate result remains FAILED. No new scan. |
| RO-01–RO-04 | NOT EXECUTED / NO-GO; no current exact account, credential-use and time-window authorization. |
| RO-05–RO-09 | NOT AUTHORIZED / NOT EXECUTED. |
| M147-E.2b scanner execution | NO-GO / NOT AUTHORIZED. |
| Six live M12 gates | UNKNOWN; not read. |

An earlier cleanup attempt for task-specific temporary public-key evidence was rejected by local command policy. The prior report says it may remain and contains no credentials. It was neither inspected nor deleted in this review; no file from it is trusted without revalidation. A future approval must specify safe handling and cleanup.
