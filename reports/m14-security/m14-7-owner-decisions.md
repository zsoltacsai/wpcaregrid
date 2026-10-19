# M14.7 Owner Decisions and Execution Authorization

## M147-E.2a authorization preflight — 2026-10-18

The Owner supplied a draft execution record marked **NOT APPROVED FOR EXECUTION**. It names a candidate target, SSH user/method, Owner as supervisor/stop authority/recovery contact, and RO-00 through RO-09 operation categories. The host-key fingerprint, active execution window, authorization reference/final approval, verified SSH account privileges, and exact reviewed commands remain missing; RO-05 through RO-09 are explicitly blocked pending safe-method review. Do not connect based on this draft. The mandatory stop rule was followed: **NO LIVE STAGING ACCESS PERFORMED**. All live state, including every M12 gate, remains UNKNOWN. M147-E.2b is NO-GO / NOT AUTHORIZED. See [preflight evidence](m14-7-e2a-staging-preflight-evidence.md).

## M147-E.1 Level 2 authorization boundary — 2026-10-18

The current Owner authorization permits **offline planning and documentation only** for staging scanner readiness: source/docs/tests review, architecture mapping, local synthetic tests only if genuinely necessary, and a planning PR. It explicitly prohibits staging SSH/API access, production/customer access, live M12 reads or changes, live feed/scanner execution, secrets, backup/restore, queue execution, incident creation, deployment, and M147-E.2/F/M14.8 work. No such prohibited activity occurred in this planning pass.

The E.1 deliverables are [readiness plan](m14-7-e1-readiness-plan.md), [proposed staging runbook](m14-7-e1-staging-runbook.md), [side-effect risk matrix](m14-7-e1-risk-matrix.md), and [Owner approval checklist](m14-7-e1-owner-approval-checklist.md). M147-E.2 is **NOT EXECUTED — OWNER APPROVAL REQUIRED** and operational M147-E remains **NOT STARTED**. All six live M12 gate values remain **UNKNOWN / NOT READ**. Approval of this planning work is not Level 3 authorization.

Offline review found that `SecurityScanService::finish()` invokes `IncidentService::openForDetection()`. `config/security.php` defaults both incident auto-open and auto-backup to true; a qualifying suspicious-file result can create incident/timeline rows and request a full backup. This effect is documented for E.2 preflight and keeps M147-F04 open. No scan, incident, backup, or notification was triggered.

This register records the Owner's decisions for M14.7. M147-A, M147-B, and M147-C each have separate, bounded Level 2 approval records below. This is not blanket authorization for later M14.7 stages, staging, live feeds, or operational side effects. All work remains subject to the three authorization levels below.

## Owner decisions

| ID | Decision | Authorized scope | Deferred, separately approved, or prohibited scope | Status |
|---|---|---|---|---|
| M147-OA-01 — Synthetic WordPress target | Approve isolated local testing only. | Design and prepare a dedicated, disposable synthetic WordPress fixture. It must have an identifiable synthetic tenant and website, reproducible setup and cleanup, and evidence that it is disposable. | Customer websites, production data or credentials, shared production storage, and customer Connector connections are prohibited. The existing staging environment is **not authorized** for scanner execution. Any future staging scan requires separate Owner approval of the exact target identity, Connector pairing, permitted operations, and cleanup procedure. | Isolated local target/testing **APPROVED** within the Level 2 execution gate; staging scan **NOT AUTHORIZED**. |
| M147-OA-02 — Wordfence vulnerability feed | Approve local fake-feed validation; defer live feed configuration. | Deterministic parser, matching, freshness, error-handling, and failure-mode tests with synthetic feed fixtures. Missing, stale, or inaccessible feeds must be reported as unavailable, never clean. | No real feed credentials, unauthorized external downloads, production configuration changes, or live-coverage claims based on fake tests. A real feed requires separate review of licensing, access method, credentials, supported API/feed format, and operational configuration. | Fake-feed test design is **APPROVED**; execution is **NOT AUTHORIZED by the M147-A-only approval** and requires separate Level 2 approval. Live feed **DEFERRED / REQUIRES SEPARATE APPROVAL**. |
| M147-OA-03 — Scanner side effects | Approve isolated fake side effects only. | Exercise incident auto-open and backup-request behavior with isolated test doubles, fake queues, and synthetic database records; prove effects stay scoped to synthetic fixtures. | Actual backup creation, external storage writes, and real staging incident operations are not authorized. Security remediation and plugin deactivation remain disabled. No quarantine/file repair, restore, provider deletion, destructive retention, customer notifications, external AI calls, or unintended outbound requests. | Fake-side-effect test design is **APPROVED**; execution is **NOT AUTHORIZED by the M147-A-only approval** and requires separate Level 2 approval. Real effects are **NOT AUTHORIZED**. |
| M147-OA-04 — Independent security review | Request independent review where available. | Seek a separate qualified reviewer for the work and risk dispositions. | Do not claim independent review unless it actually occurs. If unavailable, document the limitation and carry the residual to the appropriate later milestone. Its absence does not block isolated test development. | **REQUESTED**; reviewer availability and review completion **UNVERIFIED / NOT CLAIMED**. |
| M147-OA-05 — Browser testing | Approve isolated local browser-test planning and harness preparation. | Plan/prep security-relevant Owner and localization flows, plus Connector browser tests using the dedicated disposable local fixture and synthetic users/data. | Stripe-dependent browser tests are deferred until a separate isolated TEST environment is approved. No staging or production, real payments, live webhook configuration, customer emails, or browser-test execution is authorized by this task. | Planning/harness preparation **APPROVED**; execution is **NOT AUTHORIZED by the M147-A-only approval** and requires separate Level 2 approval; Stripe flows **DEFERRED**. |

OA-02/OA-03's status cells describe the original planning/M147-A-only authorization. The separate M147-C record below subsequently approves **only** local fake-feed/parser tests and test-isolated incident/backup behavior within M147-C. It does not approve a live Wordfence feed, external network, real backup/incident operation, or browser execution.

## Mandatory M12 gate precondition

Before any future staging scanner validation, an authorized operator must perform a fresh, secret-safe, read-only verification of all six M12 gates. All six must be confirmed false. An unknown, unreadable, or unexpectedly enabled value stops the operation. Do not change gate values to make validation proceed. This fresh verification **has not yet occurred**. No current gate value is asserted by this register.

## M146-F08 log-integrity testing decision

The Owner's 2026-10-18 M147-B authorization separately approved Level 2 source/workflow analysis, disposable local Git fixtures, synthetic adversarial filename tests, regression tests, narrowly scoped fixes for confirmed repository defects, documentation/evidence updates, and a dedicated implementation PR. Permitted synthetic names included newlines, control characters, GitHub Actions annotation-like strings, output-command-like strings, and secret-masking-command-like strings. No real secrets were used and no protected workflow change was needed. PR #121 merged at `0699374432afeb26e036a87477c9a0cad5c5518b`; merged-primary CI run `39110282933` passed all seven mandatory jobs. M146-F08 is closed only for the audited tracked secret-path checker output path. Live runner command parsing and all other workflow output paths were not exhaustively tested. No staging/production, customer-site, live-feed/scan, secrets, privileged workflow, or deployment activity was authorized or performed.

## Authorization levels

| Level | Authorization | Examples in M14.7 |
|---|---|---|
| 1 — Approved now | Documentation updates, local fixture design, synthetic test planning, non-destructive source analysis, and preparation of implementation-stage acceptance criteria. | Planning and source baseline work. |
| 2 — Separately approved by stage | M147-A implementation/fixture validation, M147-B source/workflow analysis and disposable local adversarial filename tests, and M147-C local synthetic security-engine validation were separately approved by stage. M147-A PR #120 and M147-B PR #121 are merged with exact merged-primary CI green. M147-C authorization covers only the task's synthetic local scope and dedicated PR; it does not authorize M147-D/E/F or browser, staging, live-feed, production, customer, privileged, or destructive operations. |
| 3 — Requires separate operational authorization | Staging access/scanning, real Wordfence feed configuration, credentials/secrets access, real backup or incident side effects, external Stripe TEST infrastructure, production access, customer-site access, and destructive operations. | Any staging scan or live operational integration. |

Approval of Level 2 never implies approval of Level 3. M147-A, M147-B, and M147-C have separate, bounded Level 2 approvals. M147-C authorizes only local synthetic scanner/feed code-path tests with mocked external HTTP and isolated test databases/storage/queues; it does not authorize live feed access, any external service call during test execution, browser tests, deployment, or operations against staging, production, or customer systems. M147-D and later stages remain unauthorized until separately approved. Items expressly approved above remain limited to their stated scope and do not override the operational gates.

## M147-C execution authorization — 2026-10-18

The Owner separately authorized M147-C Level 2 local synthetic security-engine validation. Approved work includes source inspection, inert synthetic vulnerable/patched component and file fixtures, fake vulnerability-feed responses, disposable local WordPress and test databases, mocked incident/backup dependencies, regression tests, in-scope safety fixes, documentation, and a dedicated PR with exact-head CI. Security-engine tests must make no live network calls. Dependency acquisition and GitHub CI use only their normal approved development paths.

Explicitly not authorized: staging/production/customer access; live Wordfence or other feeds; live scanning services; real secrets or API keys; external backup storage; live incident, backup, restore, quarantine, remediation, or notification actions; Stripe/AI calls; browser testing; infrastructure or M12 gate changes; deployment; and M147-D/E/F or M14.8. The six live M12 gate values remain unverified. No scanner test result may be described as production coverage or a live-feed validation.

The separate M147-C authorization does not extend OA-05 browser-test permission to browser execution and does not convert OA-02's live feed deferral into approval. It permits only the synthetic local scanner/feed/side-effect behavior expressly enumerated above.

## M147-A execution record

Under the narrow M147-A Level 2 approval, the local-only synthetic fixture was installed, bootstrapped and health-validated, then actually cleaned up. The WordPress HTTP block and PHP process-level network restrictions were verified. The Connector was discovered but remained inactive; no Customer Connector was paired. The local fixture did not test the vulnerability feed, execute a scanner, exercise application incident/backup effects, or run browser tests. The Node fake queue/storage unit tests remain test doubles only. These results do not extend OA-02/OA-03/OA-05 execution authorization.

The locked-package fixture is not proof of OS-level network isolation. No app server was started. No Wordfence feed, production/staging system, customer site, real secret, external storage, or external provider was accessed. The six M12 gate values have not been read. Independent review remains unclaimed. See [M147-A execution evidence](m14-7-a-execution-evidence.md). AS-16 remains open. M146-F08 is closed only for the audited tracked secret-path checker output path based on the reviewed M147-B evidence; live runner-command parsing and other workflow output paths are not claimed exhaustively tested.

## M14-15 / AS-16 scope

These decisions constrain execution of the existing M14-15 / AS-16 security-validation scope; they do not narrow or silently close it. Unavailable feeds remain unavailable rather than clean, scanner coverage remains unverified until evidenced, and no decision authorizes remediation or destructive action.

## M147-C closure authorization boundary — 2026-10-18

The separately granted M147-C Level 2 scope was used for local source review, synthetic feeds/responses, disposable tests, scoped fixes and documentation. PR #123 is merged at `5ec7ac4d2449d4eb95d98b108210607c40c847ce`; exact-head CI 39117328446 and merged-primary CI 39118627595 each passed 7/7. This closure does not authorize M147-D/E/F, M14.8, browser execution, staging/production, live feed, customer access, real side effects, or any Level 3 action. Six live M12 gates remain UNKNOWN. See [M147-C closure report](m14-7-c-closure-report.md).

## M147-D execution authorization — 2026-10-18

The Owner explicitly authorized M147-D Level 2 local synthetic attack-path validation. Permitted for this stage: source review, disposable Laravel/WordPress fixtures and databases, synthetic tenant and role identities, mocked Connector/Stripe/fake HTTP, local fake queues/storage, regression tests, safe in-scope corrections, documentation and an unmerged PR with exact-head CI. No test may make live external-service requests.

Not authorized: staging/production/customer access; real credentials or secrets; live Wordfence/other feeds; Stripe TEST/live requests; remote backup storage; real payment, incident, backup, restore, quarantine, remediation or notification; M12 gate changes; deployment; M147-E/F; M14.8. Browser tests are not expressly authorized in this stage and will not be run. The six live M12 values remain UNKNOWN and are not read or changed. The M147-D worktree baseline is `b392c5c3f4a934886b574e59ceb67d1dfe04c894`; planning-first attack paths are in [M147-D execution evidence](m14-7-d-execution-evidence.md).

## M147-E.2a RO-00–RO-04 authorization outcome — 2026-10-18

Owner authorization covered RO-00–RO-04 for 18:00–18:30 Europe/Budapest. The unauthenticated RO-00 keyscan failed during local KEX negotiation; no host key was retrieved or compared. No authentication or remote command occurred, so RO-01–RO-04 were not executed. This is a client/tooling blocker, not evidence of a target vulnerability or unsafe server state. All live M12 gate values remain UNKNOWN and M147-E.2b remains NO-GO / NOT AUTHORIZED. No RO-05–RO-09 operation was attempted. See the [preflight evidence](m14-7-e2a-staging-preflight-evidence.md).

## M14.7 full-closure authorization state — 2026-10-18

Local/GitHub review and documentation reconciliation are authorized. Staging authentication and remote RO-01–RO-04 commands still require a new complete, time-bounded Owner record. The current draft leaves execution window, account, credential-use mechanism, exact command scope and evidence/stop details unapproved. Non-root deploy is a candidate only until explicitly authorized. Public RSA fingerprint correspondence and independently Owner-confirmed ED25519 host fingerprint do not authorize authentication. RO-05–RO-09, M12 gate reads, scanner execution, M147-E.2b and external side effects remain unauthorized.

## Owner-reported read-only staging evidence — 2026-10-19

The Owner reports that RO-01/RO-02/RO-03/RO-04 identity, checkout, PHP and capacity observations were collected through the Rackforest console under the previously authorized non-root `deploy` session, and that a separately authorized `artisan config:show privacy` read showed all six M12 gates false at the reported staging revision. This is evidence supplied by the Owner, not work performed by Codex. The complete runner JSON, audit ID and exact UTC timestamp are not archived. The gate values apply only to that observed configuration; any scanner execution requires a fresh pre-scan read after any release, plus a separate operation-specific Level 3 authorization that bounds incidents, backup, notifications, queue work, and all other side effects. This update does not authorize scanner execution, configuration changes, deployment, or M147-E.2b.
