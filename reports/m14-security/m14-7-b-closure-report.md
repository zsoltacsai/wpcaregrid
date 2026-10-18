# M147-B Closure Report — Log Integrity and Supply-Chain Attack Paths

**Disposition:** M147-B closed after protected merge and exact merged-primary CI.

## Merge and exact-SHA evidence

| Evidence | Result |
|---|---|
| Implementation PR | [#121](https://github.com/zsoltacsa/wpcaregrid/pull/121), merged through the active protected ruleset |
| Final PR head | `e10695f1ff5cd505ae6883e1a5e5678bdd7f31bd` |
| Exact PR-head CI | Run `39109648655`, 7/7 PASS |
| Merge / authoritative primary SHA | `0699374432afeb26e036a87477c9a0cad5c5518b` |
| Exact merged-primary CI | Run `39110282933`, 7/7 PASS |
| Server MySQL process/concurrency gate | PASS in both final PR-head and merged-primary runs |
| Authoritative primary readback | `claude/ecstatic-wozniak-oemyuf` points to the merge SHA; branch protected |

The protected ruleset `25300175` applied to the authoritative branch, required the PR path and all seven stable status contexts, enforced conversation resolution, blocked deletion and non-fast-forward updates, and had no bypass actors. Required approval count is zero under the documented single-maintainer constraint. This is a review-independence residual; an independent reviewer is not claimed.

The final PR-head run covered:

1. Quality (lint, style, dependencies, build, secrets) — PASS.
2. Server tests (PHP 8.4, sqlite) — PASS.
3. Server tests (PHP 8.4, mysql) — PASS; process/concurrency gate PASS.
4. Server tests (PHP 8.4, mariadb) — PASS.
5. Connector tests in WordPress (PHP 8.4, sqlite) — PASS.
6. Connector tests in WordPress (PHP 8.4, mysql) — PASS.
7. Connector tests in WordPress (PHP 8.4, mariadb) — PASS.

The exact merged-primary run passed the same seven checks. The initial test/source commit `a3080a7af802f0dd96f1f6b548233b555f989399` was run `39107517613`, which exercised the full ten-case Linux hostile-filename corpus. The later final PR-head run `39109648655` tested commit `e10695f1ff5cd505ae6883e1a5e5678bdd7f31bd`; run `39108485648` was an earlier successful intermediate PR head and is retained only as historical evidence. No run is attributed to a different SHA.

## M146-F08 scoped disposition

**CLOSED — NOT REPRODUCIBLE IN THE AUDITED OUTPUT PATH; REGRESSION COVERAGE ADDED.** Closure is limited to the tracked secret-path checker `scripts/tests/repository-secret-path-policy.py` and its handling of tracked filenames.

Source review verified that the checker uses `git ls-files -z`, processes NUL-delimited byte records, prints only a fixed redacted count when matching sensitive paths, and exits nonzero. The regression creates a disposable Git repository and invokes the actual checker subprocess. It asserts exact output bytes, nonzero status for sensitive matches, benign-path behavior, and no writes to fake `GITHUB_ENV`, `GITHUB_OUTPUT`, `GITHUB_PATH`, or `GITHUB_STEP_SUMMARY` files.

Five filesystem-compatible hostile cases passed locally on Windows. All ten newline/control/annotation/output/masking-like cases passed in Linux CI run `39107517613`. The checker did not emit attacker-controlled filename bytes. The live GitHub Actions command parser was not invoked or simulated; other workflow output sources were not exhaustively tested. M146-F08 was a validation gap, not a confirmed exploited vulnerability. The scoped closure does not imply exhaustive workflow-command security coverage.

## M147-B scope and validation

- Re-audited the secret-path checker and relevant workflow/supply-chain policy context.
- Added an end-to-end synthetic filename integration test; no product runtime or workflow change was needed.
- Local Windows subset: 5 hostile filename cases passed. Linux CI: all 10 hostile cases passed.
- Locally executed existing repository regressions: CI supply-chain policy, deployment privilege policy, exact-SHA release CI verifier, deployment receipt provenance, Gitleaks archive verifier, M147-A harness tests (11/11), and `npm test` (20/20). These were synthetic/local tests, not live deployment or host tests.
- Quality job on final PR-head and merge runs passed the tracked secret-path test, full-history Gitleaks, frontend/dependency checks, and existing policy tests.
- No scanner, feed, browser test, staging or production access, customer-site access, secret access, M12 live-gate read, backup, incident, or destructive operation occurred.

## M14.7 stage status and progress

| Stage | Status |
|---|---|
| M147-A — Baseline and safe evidence harness | **CLOSED — merged and locally validated**; PR #120 merge SHA `a84d03146ac3ba0ba8c47301f61be41052903905`, merged CI run `39105703168` passed 7/7. |
| M147-B — Log integrity and supply-chain attack paths | **CLOSED — merged and validated**; PR #121 merge SHA and CI above. |
| M147-C — Security engine fixture validation | **NOT STARTED**; requires separate Level 2 implementation/test approval. |
| M147-D — Attack-path regression campaign | **NOT STARTED**; requires separate Level 2 approval before committed or executed test work. |
| M147-E — Staging detection-only scan | **NOT STARTED / OPERATIONAL AUTHORIZATION REQUIRED**; requires separate approval of exact target, Connector pairing, allowed operations and cleanup, a fresh secret-safe read-only check of all six M12 gates, and feed authorization if live vulnerability coverage is claimed. |
| M147-F — Findings, retest and closure | **NOT STARTED**; follows evidence from later stages and independent-review disposition. |

M147-B completion: **100%**. Overall M14.7 implementation: **approximately 30%**, a planning estimate reflecting completion of the first two of six staged work packages while the scanner, feed, integrated attack-path, and closure work remains. This is not an M14-wide completion estimate.

**M14.8 readiness:** Not ready. AS-16 scanner coverage remains open; actual scanner/feed validation has not occurred; fresh M12 values are unknown; M147-C through M147-F remain; independent review remains requested but unclaimed.

## Open findings, constraints, and handoff

- **AS-16 / M147-F01:** open; no scanner campaign or completed scan evidence.
- **M147-F02:** open; six M12 live gate values are unverified. Historical values are not current evidence.
- **M147-F03:** open; live feed configuration, source authorization and freshness were not inspected or tested.
- **M147-F04:** open; fake scanner side-effect validation did not run; all real backup/incident effects remain unauthorized.
- **M147-F05:** open; browser tests were not executed.
- **M147-F06 / M147-F08:** independent qualified security review has not been claimed; preserve this residual.
- **M147-F07:** targeted feed-download/parser audit item remains unresolved and is not classified as a confirmed vulnerability.
- M14.6 single-maintainer review and broader log-integrity parsing limitations remain bounded residuals. M146-F08's scoped tracked-checker concern is closed.
- The six M12 runtime gates were not checked or changed. No customer backup deletion, provider deletion, destructive retention, AI egress, or autonomous action was exercised.

Owner decisions before future work: separately authorize M147-C implementation and local test execution; separately authorize M147-D test implementation/execution; and, before M147-E, approve the precise staging target, synthetic identity, Connector pairing, permitted operations, cleanup and feed source, then obtain a fresh read-only all-false check of the six M12 gates. Any independent review must be performed by a separate qualified reviewer before it is reported as complete.

Recommended next actions:

1. Obtain explicit Level 2 approval for M147-C, limited to local synthetic fixture validation.
2. Resolve M147-F07 through source review and fake-transport tests under that approval; preserve unavailable feed state as unavailable.
3. Obtain separate Level 2 approval for M147-D; defer M147-E until separate Level 3 operational approval and fresh M12 gate verification.

No Level 3 activity occurred. M147-C has not started. No deployment occurred as part of M147-B closure.
