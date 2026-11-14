# Production release controller V3 — development proposal

**Status:** isolated source prototype; not installed, not wired to the current
production controller, and not an authorization to deploy. Production remains
NO-GO.

## Compatibility boundary

The current production controller and privileged helper are version 2.0.0 and
remain unchanged. They retain their existing V2 evidence enforcement. The V3
policy requires a versioned helper successor; this source prototype does not
replace or modify 2.0.0. It only performs a read-only admission check and
returns PASS/HOLD. It does not create a package, call sudo, stage files, invoke
a helper, or modify an installation. A future helper successor must independently
consume the validated result and preserve all existing target, path, privilege,
artifact, database, rollback, and runtime checks.

## Exact release subject

The operator supplies four immutable identities to the verifier:

```text
/usr/bin/python3 -I -B scripts/production_release_controller_v3.py \
  verify-release <candidate-merge-sha> <merged-pr-number> <reviewed-pr-head-sha> <merge-base-sha>
```

The controller and its two verifier modules must remain together in the same
root-owned, hash-verified source directory. The isolated entry point imports
only sibling verifier files from that directory; it does not depend on the
current working directory, `PYTHONPATH`, user site packages, or an installed
application package. Verify each file against its reviewed release manifest
before invocation.

The candidate SHA is an immutable release identity supplied from the reviewed
release manifest; it does not change when later documentation or unrelated
commits advance primary. The verifier requires that exact commit to be the
merged PR's merge commit and compares `candidate...current-primary` through
GitHub's compare API. It accepts only `identical` or `ahead` with the candidate
as the merge base and zero commits behind, proving the candidate remains in
the protected primary history. Divergence or an unavailable comparison returns
HOLD. The controller reads the protected branch again after collecting all
other snapshots and returns HOLD if the branch moved during the read. The
privileged successor must repeat the identity/admission check immediately
before its first mutation. It fetches the merged PR and merge commit and checks that the PR number,
primary target branch, exact reviewed head and first-parent base all match the
supplied identities. This explicit base/head binding avoids inferring an old
base from a moving branch. A protected-branch or identity mismatch returns
HOLD before any deployment code is reachable.

## V3 record and report artifact binding

The existing `WPCG_RELEASE_REVIEW_V3` comment remains the decision record. A
separate Owner-authored #174 comment carries the complete report bytes in a
bounded Base64 envelope, so a later verifier can recompute rather than merely
trust the declared report digest:

```text
WPCG_RELEASE_REVIEW_ARTIFACT_V3
role=integration_qa|appsec
pr=<number>
head_sha=<40 lowercase hex>
base_sha=<40 lowercase hex>
agent=<agent label>
report_sha256=<64 lowercase hex>
report_base64=<complete UTF-8 report bytes encoded as Base64>
```

The envelope must be an `OWNER` association by the repository owner, match the
exact PR/head/base/role/agent in the latest V3 decision record and verified PR
merge metadata, contain a strict Base64 UTF-8 payload of 1–45 KiB, and hash to
the decision record's `evidence_sha256`. The report must be complete; a link or
summary is not an artifact. The envelope binds the exact base even though the
legacy V3 decision grammar has no base field. Older ledger records without a
verifiable artifact remain insufficient for this successor. Malformed Owner
V3 records fail closed, following the current policy verifier.

The two role labels must differ and the latest exact-head decision for each
role must be PASS. A later BLOCKED or CHANGES_REQUIRED record supersedes an
earlier PASS.

## CI verification

Three exact workflow snapshots are required:

1. `pull_request` CI on the exact reviewed PR head, bound to the PR number and
   protected primary base.
2. `push` CI on the immutable candidate merge SHA, captured when that commit
   was on primary.
3. `push` CI on the current protected primary SHA. A later documentation
   merge therefore does not silently replace the candidate, while the current
   protected branch still has to pass its own checks.

The `.github/workflows/ci.yml` run must be completed/success, with each of the
seven required job names present exactly once and completed/success. Missing,
duplicate, stale, cancelled, pending, failed, truncated, or malformed evidence
returns HOLD. API failure also returns HOLD. No older green run is used if the
latest matching run failed. Job pagination uses the selected run's explicit
attempt endpoint; each job's run ID, attempt and head SHA must match, and the
run attempt plus latest eligible run are re-read before acceptance. Every job
must be complete without a failed conclusion, in addition to all seven
required jobs passing. The release manifest must pin the candidate SHA; an
operator must not substitute the latest primary SHA after review.

The complete #174 comment snapshot is fetched again after policy evaluation;
any identity, body, association, creation/update timestamp or pagination
difference returns HOLD. The protected primary is also reread. A change after
the final read cannot be ruled out by this read-only prototype, so a privileged
successor must rerun admission immediately before its first mutation.

## Fail-closed and rollback contract

The verifier is read-only. It never alters a release tree. Any refusal occurs
before the existing controller or privileged helper is called. The deployment
successor must preserve this invariant: verify all evidence and immutable
artifact hashes before creating a candidate; retain the current installation
until the candidate passes complete post-stage verification; use a no-replace
promotion; preserve the prior release for rollback; and stop without changing
the current installation if any evidence, hash, target, or preflight check fails.
A helper-version successor and installation still require separate review and
Owner host authorization under the existing release policy.

## Regression coverage

`production-release-admission-v3-test.py` exercises a fully bound passing case
where primary has advanced after the immutable candidate, and rejects a
candidate outside primary ancestry, a moving primary snapshot, wrong PR/head/base, missing roles,
non-PASS or same-agent reviews, malformed V3 records, missing/modified report
artifacts, stale or failed CI, missing/duplicate/failed required jobs, and
incorrect PR binding. The test is deterministic and uses synthetic snapshots;
it is not staging or production evidence.

## Known integration blockers

- The current 2.0.0 helper still enforces V2 and is intentionally untouched.
- The integrated V3 policy permits an append-only correction only for the two
  pinned malformed PR #377 records. Until their Owner-authorized invalidations
  are published and the complete live ledger passes the parser, admission must
  HOLD. Every unrelated malformed Owner record remains blocking.
- Existing V3 report artifacts are not yet encoded in the artifact envelope
  above, so the successor must HOLD until the evidence publication path adopts
  and verifies this format.
- There is no production installation, live execution, staging test, or
  deployment authorization in this proposal.
