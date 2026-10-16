# M14.2 Connector, Payload, Filesystem and Webhook Security Plan

**Disposition:** planning/security review only. No runtime code, configuration, credentials, or staging state changed. **Baseline:** primary branch claude/ecstatic-wozniak-oemyuf, SHA 18ed2da5b0862b701ba19da6ef40b183ed6c44fb; clean isolated planning branch codex/m14-2-connector-security-plan. **Staging executable SHA:** e4659f30140669c9e229c8e583d35e6bb377a979, from read-only strict-host-key check. Source review is not a penetration test.

## Scope and boundary

M14.2 covers M14-04 Connector credentials, M14-05 payload trust, M14-06 filesystem/archive safety, M14-07 Stripe webhook and related Connector admin-access protocol, plus AS-06 outbound request safety, AS-09/10/11, restore subset of AS-12, Connector/webhook credential handling in AS-13, and reachable AS-14 execution gates. M14.3 owns compatibility/preflight UI and general hosting compatibility. Multisite and Bedrock/custom roots remain excluded for initial launch; unknown mutation/restore capability fails closed.

M14.2 does not implement runtime changes, deploy, run the M14.7 scanner campaign, implement GFS entitlements or AI provider configuration, certify production DR, authorize M15, or enable destructive execution. All six M12 privacy/destructive gates remain false. No new Owner decision is required.

## Verified baseline

- Current remote primary is 18ed2da5b0862b701ba19da6ef40b183ed6c44fb, merge of M14.1 closure PR #88. M14.1 records 236 routes and closure evidence; AS-08 retains its medium residual, AS-16 remains M14.7 carry-forward.
- Staging remains on clean executable SHA e4659f30140669c9e229c8e583d35e6bb377a979. Strict SSH host-key verification matched the approved Ed25519 fingerprint. Read-only system check was healthy for app, DB, queue/scheduler, Connector, Stripe TEST, backups, mail, privacy/recovery; warning “No completed security scan yet” remains. All six M12 gates read false. Deploy-user access did not independently expose Supervisor status; do not claim that direct verification.
- Connector release.json and plugin header identify 0.4.5; server route prefix is /api/v1/connector (v1). Plugin API requests use TLS verification and redirects disabled; production server configuration requires HTTPS by default and binds bearer token to exact X-CareGrid-Website UUID.
- Current source has 13 Connector API declarations: 10 state-changing and 3 reads. This is source route count, not a re-derived runtime route count.
- No confirmed Critical or High M14.2 defect was established in this bounded review. Open items below are validation needs, not vulnerabilities.

## Ordered implementation architecture

1. M14.2-A credentials/pairing: define retry-safe issue/replace/revoke/re-pair/disconnect semantics, in-flight handling and redaction. Preserve opaque random bearer plus server hash-at-rest unless evidence shows retrieval is required. No periodic expiry by default.
2. M14.2-B request/payload boundaries: endpoint schema, size/depth/member limits; revalidate website, Connector, job, backup and capability at effect time; prove idempotent results and bounded polling/upload.
3. M14.2-C admin access: one-time claim/token/expiry/replay, connector revocation, ended callback, browser handoff and session termination under concurrency.
4. M14.2-D filesystem/archive: disposable WordPress fixtures for traversal, symlink/race, collisions, parser corruption, entry/expanded-size ceilings, permissions/disk-full and cleanup.
5. M14.2-E egress/SSRF: map every URL source and consumer; prove redirects/DNS/IP policy and destination allowlisting for values influenced by site/job/Owner input.
6. M14.2-F Stripe webhook: signature timestamp, mode, secret rotation, replay/idempotency races, encrypted inbox retention, redrive and failure-category behavior. Stripe TEST only.
7. M14.2-G integrated validation/release: full relevant CI, isolated synthetic staging, package/version fixture update, audit refresh and exact-SHA closure.

Expected delivery: 4–6 focused implementation PRs plus an evidence closure PR; exact split depends on findings and migrations.

## Key source observations and validation needs

Current controls include one-time pairing code locking/use, 48-character cgc_ credential, SHA-256 server lookup storage, libsodium plugin-option encryption, exact website UUID binding, auth throttles, job/site scope checks, byte-count/checksum upload, lexical path validation, regular-file-only tar parsing, and Stripe event-ID uniqueness with encrypted inbox and retry lease.

Validation targets: no standalone rotate API/explicit overlap contract beyond re-pair; response loss between server issuance and plugin persistence; nested arbitrary job-result/error fields; no explicit route-level JSON request cap visible in controllers; finalize validates manifest is an array before service validation; archive entry/expanded-byte/ratio ceilings are not visible in inspected TarReader; race/failure cleanup needs fixtures; URL-bearing health/source flows need source-to-sink egress tests; Stripe SDK timestamp tolerance and secret rotation require focused proof.

## External and future dependencies

- No external action blocks safe engineering work. Stripe TEST endpoint access may be needed for rotation rehearsal. Live secret rotation is a separate operational action.
- M14.3 handoff: compatibility capability/preflight UX, host matrix and blocked diagnostics.
- M14.7 handoff: completed detect-only security scan and independent adversarial scan. Preserve current warning.
- AS-08 medium limiter residual remains in its existing disposition; do not reopen M14.1.

## Release and staging safety

Future implementation: focused tests; full Server/Connector suites and MySQL/process tests; exact-head GitHub five-job CI; merge; guarded staging deploy of exact executable SHA if runtime changed; disposable synthetic WordPress/site and Stripe TEST validation; cleanup. Never use customer sites, live Stripe, destructive cloud operations or production credentials. Keep every M12 destructive gate false. Unknown capability, provider response, payload schema or filesystem boundary blocks the affected mutation.

## Supporting documents

See m14-2-connector-trust-boundary.md, m14-2-connector-security-matrix.md, m14-2-credential-lifecycle-plan.md, m14-2-payload-matrix.md, m14-2-admin-access-protocol-review.md, m14-2-egress-ssrf-matrix.md, m14-2-filesystem-archive-matrix.md, m14-2-webhook-security-matrix.md, m14-2-adversarial-test-plan.md, m14-2-findings.md, m14-2-implementation-backlog.md and m14-2-closure-criteria.md.
