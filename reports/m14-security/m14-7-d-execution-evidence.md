# M147-D Attack-Path Security Validation — Execution Evidence

**Status:** Local synthetic execution is recorded below; the dedicated PR and exact-head CI remain pending. The initial source-grounded inventory appears before the execution results.

## Baseline and authorization

- Repository: `zsoltacsai/wpcaregrid`.
- Authoritative branch: `claude/ecstatic-wozniak-oemyuf`.
- Verified primary/baseline SHA: `b392c5c3f4a934886b574e59ceb67d1dfe04c894`.
- Work branch: `codex/m147-d-attack-path-validation`.
- Scope: Owner-authorized Level 2 local synthetic attack-path validation, focused tests, narrow fixes, documentation and an unmerged PR.
- Forbidden in this run: staging/production/customer access; live credentials, feeds, Stripe, AI or other external service calls; real incident/backup/restore/quarantine/remediation; infrastructure or M12 changes; deployment; M147-E/F and M14.8.
- Environment: isolated local repository test process with synthetic database records and test doubles. Tests that require third-party destinations are to use fakes. No M12 live values are read or claimed.

## Planning-first attack-path inventory

This inventory was prepared from the source at the baseline SHA before executing M147-D tests. It names only implemented entry points and boundaries.

| ID | Entry point / trust boundary | Attacker and target | Existing control / planned test evidence | Side effects and isolation | Exploit severity if broken |
|---|---|---|---|---|---|
| D-AUTH-01 | Account, Operator and Owner web guards | Guest/customer/operator attempting another console or privileged route | Guard and ability middleware; `OwnerAccessTest`, `OperatorSecurityTest`, route-boundary tests | RefreshDatabase; no external service | High |
| D-TENANT-01 | Account website and nested backup routes | Tenant A addressing tenant B website/backup | Website policy and nested scope binding; `BackupSecurityTest` foreign-site action/state matrix | Synthetic accounts/websites and isolated DB/queue | High |
| D-OP-01 | Operator website, incident, finding, backup and restore routes | Operator without relevant ability or with foreign child IDs | Explicit `can:operator.*`, `scopeBindings`; `OperatorSecurityTest`, security permission tests | Synthetic Operator and records; fake queues/storage | High |
| D-CONN-01 | `/api/v1/connector/*` bearer authentication | Missing, malformed, revoked or wrong-site connector credential | Hashed connector credential and website UUID binding; `ConnectorApiTest`, pairing and backup security tests | Synthetic pairings, local requests only | High |
| D-CONN-02 | Connector job/result and backup transfer endpoints | Valid connector replaying/duplicating/substituting identifiers | Per-connector job scoping, state validation and backup/site binding; `JobsTest`, `BackupFlowTest`, `BackupSecurityTest` | Test database and fake local storage | High |
| D-STRIPE-01 | Stripe webhook HTTP endpoint | Forged or replayed synthetic event | Signature/mode checks and webhook inbox/event uniqueness; webhook feature tests | Synthetic HMAC secret, no Stripe SDK network request | High |
| D-BACKUP-01 | Account/Operator backup and restore workflows | Cross-tenant or insufficiently authorized actor | Policy, service preconditions, confirmation, connector freshness, idempotency/concurrency tests | Fake queue/storage and disposable DB; no real restore | Critical |
| D-INCIDENT-01 | Scanner-result ingestion and incident/remediation workflows | Malformed or unauthorized report/action | Connector auth/payload bounds, scan service, incident services and remediation gates; security lifecycle tests | Isolated DB and mocked queue/storage; destructive controls unchanged | High |
| D-SSRF-01 | Configured vulnerability feed and site URL onboarding | Hostile URL/input reaching outbound HTTP | Environment-owned feed destination and public-site URL validation; `VulnerabilityAndPostureTest`, `AccountConnectorTest` | Fake HTTP transport only; no destination contacted | High |
| D-XSS-01 | Operator/customer security findings and diagnostic content | Hostile text rendered into a console | Plain-text normalization and Blade escaping; security visibility tests | Inert synthetic strings; HTTP response assertions | Medium/High |
| D-CSRF-01 | Browser state-changing routes | Cross-site unauthenticated mutation | Laravel CSRF/session middleware and route audit; `RuntimeRouteBoundaryTest`, Owner/Operator CSRF tests | Local request tests only | High |
| D-AI-01 | Owner AI provider configuration and health boundary | Customer/operator attempting access or feature execution | Owner-only configuration; fixed health-only service; AI provider tests | No AI provider call; synthetic transport only | High |
| D-OPS-01 | Web routes and command/control surface | Remote web actor attempting deployment/config/gate changes | Route inventory and operational-control route boundary audit | Source inspection only; no host/SSH/deploy interaction | Critical |

### Test execution method

Use the existing feature suites before adding duplicate tests. Candidate suites include Owner/Operator access and security, connector pairing/API/jobs, backup security and account flows, Stripe webhook inbox/signature tests, security scan/incident/remediation and route-boundary tests, plus AI provider health authorization. Review each test's setup and fakes before running. A test result is evidence only for the code path and assertions actually exercised.

No live scan, external feed, browser suite, staging check, production check, or real side effect is part of this stage. Static/source findings and test gaps remain distinct from vulnerabilities.

## Execution results

The initial planning-first inventory was recorded before execution; final local test outcomes are recorded in the section below.

### Local execution results — 2026-10-18

Environment: Windows local worktree; PHP 8.4.26 CLI; `composer install --no-scripts` against committed `composer.lock`, followed by `php artisan package:discover --ansi`; no manifest or lockfile changed. Laravel tests use PHPUnit configuration (SQLite `:memory:`, array session/cache, synthetic/blank Stripe settings). Node 22 used `npm ci --ignore-scripts` and `npm run build` (Vite 8.3.4) to supply the local Blade Vite manifest. Package registry access was used only for locked development dependencies; test code used local requests and test fakes, without live Stripe, AI, Wordfence, customer-site or remote backup calls. There was no full WordPress app server or browser session in this M147-D run.

| Command / suite | Result | Assertions / skips |
|---|---:|---:|
| `php vendor/bin/phpunit tests/Feature/Security --no-progress` | 102 passed / 102 | 1,778 assertions |
| `php vendor/bin/phpunit tests/Feature/Connector --no-progress` | 73 passed / 73 | 424 assertions |
| `php vendor/bin/phpunit tests/Feature/Backups --no-progress` | 112 passed / 121 selected | 1,085 assertions; 9 skipped |
| `php vendor/bin/phpunit tests/Feature --filter='OwnerAccessTest|OperatorSecurityTest' --no-progress` | 25 passed / 25 | 1,176 assertions |
| `php vendor/bin/phpunit tests/Feature/StripeWebhookInboxTest.php tests/Feature/StripeTestIsolationTest.php tests/Feature/StripeSubscriptionReconciliationTest.php tests/Feature/StripeSubscriptionConcurrencyTest.php tests/Feature/SubscriptionLifecycleTest.php tests/Feature/CheckoutTest.php --no-progress` | 116 passed / 119 selected | 707 assertions; 3 skipped |
| `php vendor/bin/phpunit tests/Feature/AiProviders tests/Feature/Owner/AiProviderCredentialTest.php --no-progress` | 21 passed / 21 | 280 assertions |
| `php vendor/bin/phpunit tests/Feature/BillingSnapshotTest.php --no-progress` | 22 passed / 22 | 185 assertions |
| **Focused total** | **471 passed / 483 selected** | **5,635 assertions; 12 skipped; 0 failed after prerequisites were prepared** |

Backup-suite skips include explicitly MySQL/PCNTL-only restore process concurrency and platform/service-dependent cases; this Windows SQLite run does not prove those concurrent cases. The Stripe-focused group also had three skipped tests under this local platform/database setup; no additional claim is made for skipped cases. The mandatory Server MySQL CI gate is still required on the exact PR head.

The first parallel local run occurred before the Vite build and had view failures due the absent `public/build/manifest.json`; concurrent test processes also contended on Laravel's compiled view cache. After `npm ci --ignore-scripts` and `npm run build`, the relevant suites were rerun serially and passed as recorded above. A separate full-project PHPUnit attempt did not complete on Windows: storage-service discovery calls the Unix `command -v` utility and the PHP process terminated at a storage-provider case. That run is not counted as a pass. Focused suite results above are independently completed runs. CI is responsible for the required Linux/database matrix.

### Coverage classification and limits

| Scenario | Classification | Evidence / remaining gap |
|---|---|---|
| Guest/customer/operator attempts at Owner-only routes; Owner/operator guard boundaries and Owner CSRF | TESTED — PASS | `OwnerAccessTest`; 25-test role command. Not every route/method or session-revocation transition is covered by this run. |
| Operator route abilities, cross-site backup/admin-access/security object references and CSRF | TESTED — PASS | `OperatorSecurityTest`, `SecurityVisibilityAndPermissionsTest`; denied foreign objects and CSRF cases are asserted. Broader operator-permission combinations remain untested. |
| Customer access to another tenant's website and nested backup operations; rejection leaves backup/restore/job state unchanged | TESTED — PASS | `BackupSecurityTest` action matrix and `AccountBackupsTest`; test DB and fake queue/mail/storage. Does not cover all exports, all billing surfaces, or every pagination/cache variant. |
| Cross-site Connector credential/header substitution; invalid/revoked credentials; foreign job/backup IDs | TESTED — PASS | `ConnectorApiTest`, pairing/jobs and `BackupSecurityTest`. Requests are local Laravel test-client calls. |
| Connector duplicate result, undelivered/unknown job, bounds and state transitions | TESTED — PASS | `JobsTest` assertions include result-once/repeat acknowledgement and unauthorized job refusal. Protocol has no request MAC/nonce scheme; replay resistance is per-operation behavior, not a blanket guarantee. Timestamp/reordering across every Connector operation is NOT TESTED. |
| Signed webhook invalid/wrong/stale signature, oversized body, mode checks, duplicate delivery, event ordering, foreign billing identity and state preservation | TESTED — PASS (covered cases) | Synthetic HMAC webhook tests in `SubscriptionLifecycleTest`, `CheckoutTest`, `BillingSnapshotTest`; no Stripe API request. Not every event type/ordering conflict is covered. |
| State-changing browser route CSRF inventory and throttles | TESTED — PASS (route policy assertions) | `RuntimeRouteBoundaryTest`, Owner/Operator security tests. No real browser/cross-origin browser execution. |
| Security finding text escaping | TESTED — PASS (narrow scope) | `SecurityVisibilityAndPermissionsTest::test_finding_titles_are_rendered_as_escaped_text`. Attribute/JS/DOM contexts, notifications, marketing and all diagnostic surfaces are NOT TESTED. |
| Backup authorization, cross-website binding, restore preconditions and no unauthorized job/state mutation | TESTED — PASS (selected flows) | `BackupSecurityTest`, backup/restore suites, fake local storage/queues. Real restore deliberately not run; 9 backup cases skipped in this local environment and process concurrency is not locally proven. |
| Scanner incident opening and synthetic verified-backup request; incident lifecycle and remediation capability/gates | PARTIALLY TESTED | Existing `IncidentLifecycleTest`, `SecurityScanTest`, `RemediationTest` within 102 security tests exercise selected synthetic paths. The full combinations of retries, race/replay, notifications, all queues, quarantine and failure-path side effects are NOT TESTED; M147-F04 remains open. |
| SSRF / outbound URL guard | PARTIALLY TESTED | Site onboarding rejects internal URLs in `AccountConnectorTest`; feed tests use fake HTTP and cover fixed configured HTTPS, redirects, size and malformed inputs in `VulnerabilityAndPostureTest`. Encoded IP variants, IPv6/link-local/cloud-metadata patterns, DNS rebinding and comprehensive redirect-to-private host validation are NOT TESTED; M147-F03 remains open. No real destination was contacted. |
| AI boundary | PARTIALLY TESTED / inference NOT APPLICABLE to implemented M14.5 surface | Owner credential authorization/write-only and synthetic health transport tests passed. The inspected product surface is configuration/health groundwork; no inference client/tool action route was identified in the audited path. Cross-tenant prompt/tool isolation is NOT APPLICABLE to this implementation, and no live AI provider was called. |
| Deployment, environment, shell and operational gate controls through web routes | PARTIALLY TESTED — SOURCE REVIEW | Application route/controller inventory found deployment/host controls remain in scripts/console operation rather than customer-facing route handlers. No staging SSH or server inspection was performed; operational host state is NOT TESTED. |
| Password reset/activation token abuse, mass assignment across all roles, stale sessions, every export/download/pagination/cache path | NOT TESTED as an exhaustive campaign | Existing focused tests cover selected related behavior only. Carry remaining paths into later attack review as appropriate; no blanket security conclusion. |
| Local WordPress browser/Connector E2E, Stripe-dependent browser flows | NOT TESTED | No browser tooling or WordPress service was started in this stage; external/payment flows are outside the validated suites. |

### Findings and disposition

No new confirmed Critical or High vulnerability was reproduced in the completed focused suites. No runtime code or security policy change was needed, so this branch contains test evidence/documentation only. This is not a claim that the untested paths are secure. Existing findings remain: AS-16 / M147-F01 open; M147-F02 six live M12 gates unknown; M147-F03 live feed and additional SSRF/provenance unknown; M147-F04 side-effect combinations partially tested/open; M147-F05 browser/integration coverage open; M147-F06 retained at its authoritative definition/status; M147-F07 closed only for the previously validated bounded feed-handling sub-scope; M147-C-02 closed; M146-F08 closed only for its audited tracked checker output path. No independent penetration test or production scanner coverage is claimed.

### Authorization and external-service evidence

Performed: local source review; synthetic Laravel feature tests against in-memory/disposable data; test-local HTTP/queue/storage fakes; local dependency installation from committed lockfiles; local frontend asset build needed by rendered feature tests. No WordPress/customer Connector pairing, live vulnerability feed, external payment/AI service, remote backup destination, staging/production host, real secret, backup/restore, incident, quarantine, remediation, notification or deployment was accessed or executed. No M12 gate value was read or changed. OS-level outbound firewall isolation was not separately measured; the tested integration paths used local test clients and fake transports as configured in the referenced suites.

**PR and exact-head CI:** pending; this document will be finalized after the dedicated branch is pushed and GitHub reports all seven required checks on its exact head.

### Source-grounded implementation map

- **Web authentication and authorization:** account routes in `routes/web.php`, Owner routes in `routes/owner.php`, Operator routes in `routes/operator.php`; guard middleware `EnsureCustomerAccount`, `EnsureOwner`, `EnsureOperator`; customer ownership in `WebsitePolicy`; explicit Operator abilities are attached at route declarations. Tests exercise selected web requests through Laravel's test client.
- **Connector identity and jobs:** `routes/api.php`, `AuthenticateConnector`, `ConnectorController`, `Api\V1\BackupController`, `Connector\JobQueue`, `Connector\RecordJobResult`, pairing/revocation services. The API is bearer-authenticated and bound to a Connector's website UUID. The tests establish selected credential/site isolation and operation idempotency, not a general signed-message nonce protocol.
- **Stripe:** `StripeWebhookController` → `AcceptStripeWebhook` (payload cap, signature/time tolerance, mode checks) → durable webhook inbox → `StripeWebhookHandler` and billing sync. Tests produce locally signed synthetic events with `SendsStripeWebhooks`; the Stripe API client is not called.
- **Backup/restore:** account and Operator controllers delegate to `BackupService` / `RestoreService`; Connector upload and download use authenticated Connector/site binding; restore requests have serialized/idempotent service paths. Relevant tests use temporary local storage, fake queues and disposable records. MySQL multi-process restore contention is not proven in this Windows SQLite run.
- **Security engine and side effects:** Operator routes call `SecurityScanService`, incident/finding/remediation services; Connector reports are accepted via the authenticated API. `IncidentLifecycleTest`, `SecurityScanTest`, and `RemediationTest` cover selected synthetic paths. High-confidence detection can open an incident and request a verified backup under configuration; remediation and plugin disabling are separately gated. F04 remains open for combinations not tested.
- **Outbound HTTP:** onboarding URL checks and the configured Wordfence feed sync are the identified URL-related paths. Feed tests use `Http::fake`; site URL validators are exercised with synthetic URLs, without resolving or contacting those destinations. No general webhook callback or customer-controlled AI endpoint was identified in the audited route map.
- **AI:** M14.5 exposes Owner credential configuration and fixed health probes; the inspected code contains no arbitrary inference client/action consumer. Synthetic provider transport tests do not execute an AI feature.
- **Operational controls:** deploy/scheduler scripts and console scheduler/commands are not web-facing route handlers. Host privilege, SSH and deployment state were intentionally outside scope and remain operationally unverified.
