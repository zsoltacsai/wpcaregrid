# M14.2 Findings and Evidence Register

**Review date:** 2026-10-16. **Source baseline:** primary 18ed2da5b0862b701ba19da6ef40b183ed6c44fb; staging executable e4659f30140669c9e229c8e583d35e6bb377a979. Bounded source/test/report review only; no exploit execution or runtime change.

## Confirmed findings

| Severity | Count | Disposition |
|---|---:|---|
| Critical | 0 | No confirmed Critical issue in this bounded planning review |
| High | 0 | No confirmed exploitable High issue established |
| Medium | 0 | No exploit-level Medium finding established |

## Validation needs, not vulnerability claims

| ID | Observation / attack path | Existing control | Required evidence |
|---|---|---|---|
| V01 | No standalone credential rotation/overlap API; response-loss or re-pair race may strand a site | one-time locked code, old credential revoked, local encrypted store | retry-safe state machine and old-token revocation test |
| V02 | Nested job result/error data arrays have no explicit deep/member/aggregate byte bounds visible in controller | field rules and inventory/health validators | evidence-based schema bounds and zero effect on rejection |
| V03 | Finalize requires manifest array before canonical service validation | verified object records and manifest builder | huge/deep/unknown manifest tests before state/dispatch |
| V04 | TarReader has path/type/checksum/truncation checks but no visible archive-wide entry/expanded-byte/ratio ceilings | streaming and staging | define limits; adversarial resource fixtures; cleanup |
| V05 | TOCTOU/staging-root integrity not proven by static review | containment helpers, staged/preflight/apply design | symlink race tests and immediate pre-write containment proof |
| V06 | Outbound calls have differing destinations, redirects and response caps | API client HTTPS/TLS/no redirects; fixed checksum hosts | full source-to-sink egress tests; no SSRF confirmed |
| V07 | Stripe timestamp, rotation and all duplicate worker boundaries need focused proof | SDK signature, mode guard, unique encrypted inbox, lease and event unique key | timestamp, cutover, body cap and exactly-once business effect tests |

Existing tests cover Connector pairing, credentials, package upgrade, backup, API, jobs, admin access and Stripe inbox/lifecycle/concurrency. Their presence does not establish coverage for every case above. AS-16 remains M14.7_CARRY_FORWARD.

## Implementation validation — local evidence, 2026-10-16

The findings above were validation needs at planning time. The following code and regression work now addresses those needs in the implementation candidate; it does not yet establish release closure.

| ID | Implemented / tested evidence | Current disposition |
|---|---|---|
| V01 | Existing one-time pairing and old-token revocation tests retained; new Connector integration test simulates a lost response, verifies local credential preservation, then verifies fresh-code re-pair updates the credential only after a valid response. | Local SQLite/MySQL suites pass; exact CI and staging synthetic pairing remain pending. No standalone overlap/rotation API is claimed. |
| V02 | Shared `PayloadBounds` checks raw body bytes, depth, member count and aggregate scalar bytes on Connector pair/heartbeat/inventory/result paths; Content-Length is rejected before body access. Tests prove nested and declared-oversize results fail before job mutation. | Focused Laravel tests pass; MySQL/process CI pending. |
| V03 | Finalize validates bounded manifest ingress before service state transition/dispatch; oversized manifest regression asserts no finalize side effect. | Focused Laravel test passes; exact CI pending. |
| V04 | Shared Connector archive ceilings bound entries, encoded archive, expanded bytes, member size, depth, path work and PAX metadata for writer and reader; partial archive is removed on build failure. Adversarial fixtures cover entry/member/encoded/PAX/depth bounds and exact/case/file-child collisions. | WordPress SQLite/MySQL suites pass; two opt-in hard-kill recovery tests remain explicitly skipped because they wait for the 900-second lock TTL. |
| V05 | Restore staging uses component-wise directory creation, exclusive file creation, symlink/containment checks and immediate destination checks before rename. A changed-parent symlink-after-preflight regression proves no outside write. | WordPress SQLite/MySQL suites pass; broader hosting-specific race testing remains outside this local fixture. |
| V06 | Connector client/health/checksum/remediation egress responses are bounded; Connector API and probes do not follow redirects; TLS verification is explicit on remote trust paths. Fake-HTTP tests assert client caps and probe redirect/body policy. Server `WebsiteProbe` already uses public-origin and safe-redirect address controls. | Source and fake-transport tests pass. No live DNS rebinding or public-to-private network probe was run; staging and exact CI remain pending. |
| V07 | Stripe webhook service/controller cap payload size and use explicit 300-second signature freshness. Regressions cover oversized declared/raw bodies, stale timestamp and single-secret cutover (old rejected, new accepted). Existing inbox uniqueness, idempotency and concurrency coverage remains in place. | Focused/full local SQLite passes; MySQL process/concurrency and Stripe TEST staging validation remain pending. |

### Local validation totals

- Laravel full SQLite suite: 1,323 tests, 19,460 assertions; 0 failures, 6 skips, 1,302 existing fixture warnings.
- Connector full WordPress SQLite: 209 tests, 1,446 assertions; 0 failures, 24 SQLite/environment skips. A first run omitted CI's generated release artifact; after building the exact local `0.4.6` package, the strict suite passed.
- Connector full WordPress MySQL 8.4: 209 tests, 1,820 assertions; 0 failures, 3 documented skips (one SQLite-only branch and two opt-in 900-second worker-kill recovery tests).
- Focused Laravel Connector/backup/webhook set: 136 tests, 847 assertions, 0 failures (displayed as warnings by the repository's existing fixture-read diagnostics).
- Pint: 10 changed Laravel PHP files pass. PHP lint: Connector PHP source/tests and modified Laravel PHP paths pass. Composer validation and both Composer audits pass. npm contract tests: 9/9; npm audit: 0 findings; Vite production build passes. `git diff --check` passes.

The full Laravel MySQL suite and required MySQL process/concurrency gate, exact-head GitHub CI, staging deploy/isolated synthetic validation, post-deploy health, six M12 gate readback and verified cleanup are still required before any closure disposition. No real provider deletion, live Stripe mutation, customer data mutation, or production deployment was performed.

## Final validation supersession — 2026-10-16

The pending items above were subsequently completed as recorded in [M14.2 closure report](m14-2-closure-report.md). PR #90 candidate `2b31a9945d5c5ebbef615dc6345558f27c63f855` passed exact-head GitHub Actions run `38961580730` (five mandatory jobs); merge and deployed staging SHA are `c5e0edabd8bd1661f72eb20330c5ca2f4375b9c5`. Server SQLite passed 1,323 / 19,499 / 19 skips; Server MySQL passed 1,323 / 19,697 / no reported skips; MySQL process/concurrency passed 15 / 161; Connector WordPress SQLite passed 209 / 1,446 / 24 documented skips; Connector WordPress MySQL passed 209 / 1,820 / 3 documented skips.

The isolated exact-source staging runtime passed two synthetic batches, 98 tests / 808 assertions total. It and its database/user were verified removed. Post-cleanup staging remained healthy and all six M12 gates remained false. The deployed synthetic M8 paired WordPress fixture still reports Connector 0.4.5; it was not re-paired because no safe reversible WP admin path was available. Connector 0.4.6 was exercised in both real-WordPress CI jobs. This is a documented validation limitation, not evidence of a production defect. No new Critical/High finding was identified. AS-16 remains `M14.7_CARRY_FORWARD` and its warning remains visible.
