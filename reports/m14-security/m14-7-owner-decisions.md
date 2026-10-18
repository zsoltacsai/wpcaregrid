# M14.7 Owner Decisions and Execution Authorization

This register records the Owner's decisions for M14.7. The Owner has separately approved Level 2 implementation and isolated local safety testing for M147-A only. This is not blanket authorization for later M14.7 stages, scanner/browser execution, staging, live feeds, or operational side effects. All work remains subject to the three authorization levels below.

## Owner decisions

| ID | Decision | Authorized scope | Deferred, separately approved, or prohibited scope | Status |
|---|---|---|---|---|
| M147-OA-01 — Synthetic WordPress target | Approve isolated local testing only. | Design and prepare a dedicated, disposable synthetic WordPress fixture. It must have an identifiable synthetic tenant and website, reproducible setup and cleanup, and evidence that it is disposable. | Customer websites, production data or credentials, shared production storage, and customer Connector connections are prohibited. The existing staging environment is **not authorized** for scanner execution. Any future staging scan requires separate Owner approval of the exact target identity, Connector pairing, permitted operations, and cleanup procedure. | Isolated local target/testing **APPROVED** within the Level 2 execution gate; staging scan **NOT AUTHORIZED**. |
| M147-OA-02 — Wordfence vulnerability feed | Approve local fake-feed validation; defer live feed configuration. | Deterministic parser, matching, freshness, error-handling, and failure-mode tests with synthetic feed fixtures. Missing, stale, or inaccessible feeds must be reported as unavailable, never clean. | No real feed credentials, unauthorized external downloads, production configuration changes, or live-coverage claims based on fake tests. A real feed requires separate review of licensing, access method, credentials, supported API/feed format, and operational configuration. | Fake-feed test design is **APPROVED**; execution is **NOT AUTHORIZED by the M147-A-only approval** and requires separate Level 2 approval. Live feed **DEFERRED / REQUIRES SEPARATE APPROVAL**. |
| M147-OA-03 — Scanner side effects | Approve isolated fake side effects only. | Exercise incident auto-open and backup-request behavior with isolated test doubles, fake queues, and synthetic database records; prove effects stay scoped to synthetic fixtures. | Actual backup creation, external storage writes, and real staging incident operations are not authorized. Security remediation and plugin deactivation remain disabled. No quarantine/file repair, restore, provider deletion, destructive retention, customer notifications, external AI calls, or unintended outbound requests. | Fake-side-effect test design is **APPROVED**; execution is **NOT AUTHORIZED by the M147-A-only approval** and requires separate Level 2 approval. Real effects are **NOT AUTHORIZED**. |
| M147-OA-04 — Independent security review | Request independent review where available. | Seek a separate qualified reviewer for the work and risk dispositions. | Do not claim independent review unless it actually occurs. If unavailable, document the limitation and carry the residual to the appropriate later milestone. Its absence does not block isolated test development. | **REQUESTED**; reviewer availability and review completion **UNVERIFIED / NOT CLAIMED**. |
| M147-OA-05 — Browser testing | Approve isolated local browser-test planning and harness preparation. | Plan/prep security-relevant Owner and localization flows, plus Connector browser tests using the dedicated disposable local fixture and synthetic users/data. | Stripe-dependent browser tests are deferred until a separate isolated TEST environment is approved. No staging or production, real payments, live webhook configuration, customer emails, or browser-test execution is authorized by this task. | Planning/harness preparation **APPROVED**; execution is **NOT AUTHORIZED by the M147-A-only approval** and requires separate Level 2 approval; Stripe flows **DEFERRED**. |

## Mandatory M12 gate precondition

Before any future staging scanner validation, an authorized operator must perform a fresh, secret-safe, read-only verification of all six M12 gates. All six must be confirmed false. An unknown, unreadable, or unexpectedly enabled value stops the operation. Do not change gate values to make validation proceed. This fresh verification **has not yet occurred**. No current gate value is asserted by this register.

## M146-F08 log-integrity testing decision

Future adversarial test design and use of a disposable local Git worktree are approved in principle. The tests use only synthetic filenames containing newlines, control characters, GitHub Actions annotation-like strings, output-command-like strings, and secret-masking-command-like strings. Execution is part of M14.7's separately approved Level 2 implementation/test stage; this planning task did not execute tests. Do not expose real secrets or modify protected workflows. A confirmed defect receives a narrowly scoped fix and regression tests through the normal protected PR workflow. M146-F08 remains open pending evidence.

## Authorization levels

| Level | Authorization | Examples in M14.7 |
|---|---|---|
| 1 — Approved now | Documentation updates, local fixture design, synthetic test planning, non-destructive source analysis, and preparation of implementation-stage acceptance criteria. | Planning and source baseline work. |
| 2 — Approved narrowly for M147-A only | M147-A baseline/harness implementation and its focused isolated local safety tests. All other M14.7 implementation or test execution requires separate approval. | Dedicated M147-A PR only. This does not authorize M147-B/C/D, scanner or browser execution, workflow changes outside its approved scope, or Level 3 operations. |
| 3 — Requires separate operational authorization | Staging access/scanning, real Wordfence feed configuration, credentials/secrets access, real backup or incident side effects, external Stripe TEST infrastructure, production access, customer-site access, and destructive operations. | Any staging scan or live operational integration. |

Approval of Level 2 never implies approval of Level 3. The current approval is limited to M147-A; it does not authorize M147-B or later stages. Items expressly approved above remain limited to their stated design or future-test scope and do not override the separate execution gates.

## M147-A execution record

Under the narrow M147-A Level 2 approval, the local-only synthetic fixture was installed, bootstrapped and health-validated, then actually cleaned up. The WordPress HTTP block and PHP process-level network restrictions were verified. The Connector was discovered but remained inactive; no Customer Connector was paired. The local fixture did not test the vulnerability feed, execute a scanner, exercise application incident/backup effects, or run browser tests. The Node fake queue/storage unit tests remain test doubles only. These results do not extend OA-02/OA-03/OA-05 execution authorization.

The locked-package fixture is not proof of OS-level network isolation. No app server was started. No Wordfence feed, production/staging system, customer site, real secret, external storage, or external provider was accessed. The six M12 gate values have not been read. Independent review remains unclaimed. See [M147-A execution evidence](m14-7-a-execution-evidence.md). AS-16 and M146-F08 remain open.

## M14-15 / AS-16 scope

These decisions constrain execution of the existing M14-15 / AS-16 security-validation scope; they do not narrow or silently close it. Unavailable feeds remain unavailable rather than clean, scanner coverage remains unverified until evidenced, and no decision authorizes remediation or destructive action.
