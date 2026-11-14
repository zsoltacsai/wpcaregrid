# Release review policy

**Decision `DEC-2026-11-09-SOLO-OWNER-REVIEW-001`, policy version V3, effective 2026-11-09.**
Recorded by the Owner. Historical audit reports and the V2 documents are not edited;
this file supersedes them for the rules below.

## Decision

WP CareGrid is run by one Owner with AI development and review agents. A second
natural person is **not required** to merge source or to prepare a staging release.
The rule that two distinct non-author GitHub users submit `APPROVED` reviews is retired.

## What stays mandatory for a source merge

* Pull-request based work on the protected primary branch (the ruleset requires zero
  approving reviews and resolved review threads; it is not changed by this policy).
* All required CI checks green on the exact PR head, and exact merge-SHA CI after merge.
* Exact-head discipline: any new commit invalidates all earlier review evidence.
* Critical security findings closed before merge.
* Two **separately recorded AI-agent verdicts** bound to the exact head SHA:
  `integration_qa` (functional/integration) and `appsec` (security), each with its own
  agent identity, `PASS` / `CHANGES_REQUIRED` / `BLOCKED`, and a SHA-256 of its report.

Agent verdicts are internal QA/AppSec evidence. They are never described as a human review.

## Record format (`WPCG_RELEASE_REVIEW_V3`)

Posted as a comment on the release ledger (issue #174) through the repository Owner's
account, verified by `scripts/release_review_policy_v3.py`:

```
WPCG_RELEASE_REVIEW_V3
role=integration_qa|appsec
pr=<number>
head_sha=<40 hex>
agent=<identifiable agent label>
reviewer_kind=ai_agent
decision=PASS|CHANGES_REQUIRED|BLOCKED
evidence_sha256=<64 hex>
```

Rules enforced by the verifier: only the repository owner's records with `OWNER`
association count; malformed owner records fail closed; the latest record per role for
the exact PR and head decides; a record for another head or PR is never reused; both
roles must be `PASS` and use different agent labels; V2 records do not satisfy V3; no
GitHub review object is read. A missing or invalid check is never a PASS.

## Append-only invalidation for the two PR #377 legacy records

The #174 ledger contains two Owner-authored `WPCG_RELEASE_REVIEW_V3` comments for PR
#377 whose `agent` values do not satisfy the V3 identifier grammar:

| Comment ID | Role | Agent text | Parser result | Body SHA-256 |
|---|---|---|---|---|
| `6834079554` | `integration_qa` | `Hermione` | `review_record_invalid` | `5be3d840822759a7815917851e2f73707890173979074b8bd7b752f5bc82e94e` |
| `6834080385` | `appsec` | `Mad-Eye Moody` | `review_record_invalid` | `1f77060825e45c86fe693ce5290d96dd4e14877f8854fa378006c399353608fb` |

The complete reports matching their declared `evidence_sha256` values were not found in
the ledger comments or the PR #377 comments/reviews at investigation time. Their report
content and reviewer provenance therefore cannot be independently authenticated from
available records. These entries must not be normalized into new agent IDs or counted as
PASS. Preserve both original comments unchanged.

This proposed V3.1-compatible parser supports only an explicit Owner-authored,
append-only **invalidation** record for a recognizable legacy record whose sole schema
defect is an invalid `agent` token:

```
WPCG_RELEASE_CORRECTION_V3
target_comment_id=<original GitHub comment ID>
target_body_sha256=<SHA-256 of the exact original comment body>
target_pr=<original PR number>
target_head_sha=<original 40-hex head SHA>
target_role=integration_qa|appsec
disposition=INVALIDATED
reason_code=legacy_agent_identifier_schema
```

The verifier accepts a correction only when the Owner identity and GitHub `OWNER`
association are present, the correction's comment ID and creation time are later than
the target's, the target comment exists and is still malformed for exactly that
legacy-agent reason, the body digest and subject match byte-for-byte, and there is only
one correction for the target. A correction invalidates the old claim; it cannot
create, repair, or supersede it with PASS. Fresh review evidence is still required for
any later use. Malformed, ambiguous, forged, stale-subject, duplicate, or non-Owner
corrections do not suppress the original fail-closed error. No historical comment is
deleted, rewritten, whitelisted, or silently ignored.

The parser change is a proposal pending independent QA/AppSec review and separate Owner
authorization before integration. It does not post correction comments automatically.

## Not changed by this policy

* **Owner-only decisions:** VPS configuration, privileged helper installation, staging or
  production deployment, database initialisation, restore, service start, anything with
  live data. An agent `PASS` never authorises these.
* CI, hash, target, path, permission and runtime-isolation checks.
* Legal, accounting/tax and contractual obligations that require an external expert, and
  any production security audit or penetration test, which are assessed separately.

## Inventory of the retired human-review requirements (as of primary `bb7e20e`)

| Where | Requirement | Status |
|---|---|---|
| `scripts/deployment_backend_v2.py` (`_verify_independent_release_reviews`, `RELEASE_REVIEW_RECORD_RE`) | at least two non-author `APPROVED` reviews on the exact head plus V2 ledger records with review IDs; used by production helper 2.0.0 through `wpcaregrid-deploy-production-priv.py` | Pinned in `deploy/release-manifests/production-deployment-helper-2.0.0.json`; **not edited**. Superseded by a new helper version (below). |
| `scripts/staging_next_deploy_backend.py`, `staging_next_deploy_backend_1_4_6.py` | same two-reviewer + V2 requirement | Pinned historical helper versions; **not edited**. |
| `scripts/staging_next_deploy_backend_1_4_7.py`, `_1_4_8.py` | already do not require `APPROVED` reviews or V2 records (`wpcg_release_review_v2_ledger_required: false` in the manifests) | No change. They also do not verify V3 evidence. |
| `docs/STAGING_NEXT_HELPER_1_4_5_INSTALL.md` | describes the two-review + V2 contract | Historical; stays. |
| `docs/STAGING_NEXT_HELPER_1_4_8_INSTALL.md` | agents must PASS the exact head; helper does not enforce it | Consistent with V3 as a process rule. |
| `docs/PRODUCTION_HELPER_INSTALL_2_0_0.md` | administrator verifies "CI/review" evidence before running | Historical; V3 applies to later helper versions. |
| GitHub ruleset "Authoritative primary release controls" | `required_approving_review_count = 0` | Already solo-compatible; unchanged. |

## New helper versions (not part of this change)

Installed helper bytes and manifests are never edited in place. V3 takes effect in a
helper only through a new version and a separate, Owner-approved host installation:

* production helper successor to 2.0.0 calling `verify_agent_release_reviews` in place of
  the V2 check (all other authorisation steps unchanged);
* the next staging-next helper, combined with the target-binding integration, optionally
  enforcing V3 evidence before release authorisation.

## Effect on PR #329 and PR #330

None on their content. They need green required CI and an agent QA/AppSec verdict bound to
their current head; no second GitHub user. Because of exact-head binding, pushing a commit
to either PR requires fresh verdicts for the new head.
