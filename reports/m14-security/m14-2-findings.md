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
