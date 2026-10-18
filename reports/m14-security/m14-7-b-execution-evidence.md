# M147-B Execution Evidence — Log Integrity and Supply-Chain Attack Paths

## Scope and authorization

- **Approved scope:** M147-B Level 2 only, separately approved by the Owner on 2026-10-18.
- **Baseline primary SHA:** `a84d03146ac3ba0ba8c47301f61be41052903905`.
- **Baseline verification:** authoritative branch tip and PR #120 merge were read from GitHub; merged-primary CI run `39105703168` completed successfully with 7/7 required jobs. Its Server MySQL `Required MySQL process-concurrency gate` step passed.
- **Implementation branch:** `codex/m147-b-log-integrity`, created from that exact primary SHA in a clean Git worktree. Uncommitted changes in the user's separate primary checkout were left untouched.
- **Environment:** local Windows developer host; synthetic repository placed under an automatically removed temporary directory. The full newline/control-character corpus is delegated to the Linux exact-head CI test because Windows filenames cannot contain several of those characters.
- **Operational boundary:** no staging, production, customer site, real secrets, M12 gate values, live feed, scanner, privileged workflow, or deployment was accessed or run. AS-16 remains open; the scanner campaign has not run.

## Source trace and current behavior

The M14.6 finding described `git ls-files`-derived path output as coming from `grep`. At the verified M147-B baseline, the actual implementation is `scripts/tests/repository-secret-path-policy.py`:

1. It invokes `git ls-files -z` and captures stdout as bytes.
2. It splits records on NUL, so newlines, carriage returns, tabs, control bytes, Unicode, and leading dashes stay inside one filename record.
3. It evaluates byte patterns for sensitive tracked paths.
4. On a match it emits only the fixed message `Local or secret-like tracked paths found: N (paths redacted).` and exits 1. It never formats or prints a path.
5. It does not write to `GITHUB_ENV`, `GITHUB_OUTPUT`, `GITHUB_PATH`, or `GITHUB_STEP_SUMMARY`, and it has no workflow-command or annotation formatter.
6. The CI invokes the checker as a normal Quality step. Its token permission is `contents: read`; no deployment job exists in the workflow.

The report's former claim that this checker prints matching filenames was stale for this primary. Source history shows the current NUL-delimited/redacted implementation predates M147-B. This stage adds integration regression coverage rather than changing runtime/product behavior.

## Synthetic filename integration test

The focused test creates and indexes synthetic files in a fresh temporary Git repository, then invokes the exact production policy-check script as a subprocess. It checks the checker's return code and captured output, plus fake runner command-file paths supplied through environment variables.

| Attack class | Windows local run | Linux CI corpus |
|---|---|---|
| Annotation, warning, notice and masking-like strings | Marker strings tested without colon/control characters where the Windows filesystem forbids them | Full `::error::`, `::warning::`, `::notice::`, `::add-mask::` and newline forms included |
| Newline, CR, tab, unit-separator | **NOT TESTED locally**; filesystem rejects these names | Included in test corpus; exact-head CI result must confirm execution |
| Output/environment-command-like filename | Marker path tested; no command file was created | Newline and `GITHUB_ENV`-like case included; fake command files must remain absent |
| Leading dash and whitespace path component | PASS | Included |
| Unicode line separator and long valid component | PASS | Included |
| Sensitive path detection | 5/5 synthetic indexed names rejected; only a fixed redacted count was emitted | 10/10 expected by test; exact-head CI pending |
| Benign annotation-like untracked-name exclusion | PASS after sensitive files were removed from the index | Included; no benign filename is printed |
| Environment/output file mutation | Four temporary fake command-file paths remained absent | Same assertion runs on Linux CI |

Local command executed:

```text
python scripts/tests/repository-secret-path-policy-test.py
```

Observed local result: `PASS (5 synthetic paths; names redacted)`. The five-name local subset is a Windows filesystem limitation, not a skipped test result hidden as full coverage. The test reports why and keeps the complete ten-name corpus active on Linux CI.

### Interpretation limits

- This is an actual local subprocess/integration test of the checker against a disposable Git index, not only an in-memory matcher test.
- Test code captures child output to assert its exact bytes. It does **not** make GitHub Actions parse hostile output as live workflow commands, and no claim about runner command parsing is made.
- Since the checker output is fixed text and excludes all untrusted path bytes, the specific forged-annotation/output-file path is not reachable through this checker regardless of filename contents. Other arbitrary workflow output sources were source-reviewed for the reported `git ls-files` path but are not exhaustively covered by this focused regression.
- No change to the CI workflow or command parser is proposed because the audited path has no reproduced defect.

## M14.6 controls and local tests

| Attack path | Evidence and result | Boundary |
|---|---|---|
| Protected primary / required CI bypass | Read-only GitHub API readback found active branch ruleset `25300175` on `refs/heads/claude/ecstatic-wozniak-oemyuf`, PR-only updates, all seven required checks, strict up-to-date checks, conversation resolution, force-push/deletion blocks, and no bypass actors. Required approval count is 0 under the documented single-maintainer constraint. | Current GitHub settings observed; no direct-push attempt made. Independent review remains a residual. |
| Workflow token / untrusted PR | `.github/workflows/ci.yml` grants `contents: read`, uses `pull_request`, has no `pull_request_target`, has no deployment job, and does not upload/download deployable artifacts. The repository-owned CI policy regression passed locally. | Source and policy fixtures; no GitHub token was accessed and no secret-bearing workflow was run. |
| Manual input / shell boundaries | The release-base input uses an exact full-SHA grammar and is passed as a quoted environment value/argument. The CI policy regression passed; malformed input fixtures are part of the existing policy. | Static/source fixture evidence only. |
| Exact-SHA release authorization | Existing verifier tests require the exact authoritative primary push run, seven unique successful checks, successful MySQL process gate, complete API results and a valid full lowercase SHA. Running, failed, canceled, PR-only, parent/sibling, missing, duplicate and incomplete evidence fixtures are rejected. | Local fake API fixtures; no staging deployment or live target authorization check run. |
| Deployment privilege / target code | Existing deploy privilege test passed and asserts the fixed helper allowlist, safe argument handling, and absence of generic sudo calls. | Repository regression only; no host privilege or deployment operation was attempted. |
| Receipt / Connector provenance | Existing receipt test passed, covering separate Laravel target and immutable Connector source provenance. | Synthetic receipt fixture; no receipt was generated or inspected on staging. |
| Downloaded tool archive | Existing Gitleaks archive test passed checksum/member/traversal failure fixtures. | Local synthetic archive fixtures; no tool download was performed for M147-B. |

The following existing repository regression scripts were executed locally and exited successfully:

| Check | Result | Evidence scope |
|---|---|---|
| `scripts/tests/ci-supply-chain-policy-test.py` | PASS | Existing immutable Action refs, permission/job/workflow policy regression fixtures |
| `scripts/tests/deploy-privilege-policy-test.py` | PASS | Fixed privileged-helper allowlist and rejection of generic sudo authority |
| `scripts/tests/verify-release-ci-test.py` | PASS | Exact target SHA, exact authoritative push, all seven check contexts, MySQL gate and failure/cancel/missing/duplicate evidence cases using fake API responses |
| `scripts/tests/deployment-receipt-test.py` | PASS | Receipt source/runtime provenance fixtures, not a live deployment |
| `scripts/tests/gitleaks-archive-test.py` | PASS | Archive checksum/member/traversal verifier fixtures |
| M147-A harness tests | 11 passed, 0 failed, 0 skipped | Synthetic harness unit tests; not scanner execution |
| `npm test` | 20 passed, 0 failed, 0 skipped | Existing JavaScript tests plus M147-A harness tests |

These local fixtures do not establish live GitHub ruleset settings, GitHub runner command parsing, current staging host state, scanner coverage, or M12 live gate values. Exact PR-head CI and review remain pending.

## Finding disposition

**M146-F08 — NOT REPRODUCIBLE for the audited tracked secret-path checker; disposition pending exact Linux CI and review.** No path output, forged annotation, environment/output modification, false success, or secret-mask manipulation was reproduced. The source check and Windows local corpus support a narrowed conclusion. The complete control/newline corpus is required to pass on exact PR-head Linux CI before recommending closure of this scoped validation gap.

No new Critical or High finding was confirmed. M146-F08 must not be described as a confirmed exploit. Any residual concerns outside this exact path-policy output flow remain separately bounded and unverified. Independent review remains unclaimed. M12 live gates remain unverified.

## Exact-head CI and PR evidence

Pending: the dedicated M147-B PR must remain open and unmerged. Record the final PR-head SHA, exact Actions run ID, each of the seven mandatory job results, and the Server MySQL process/concurrency step here after CI finishes. A result from an earlier SHA is not evidence for a changed head.
