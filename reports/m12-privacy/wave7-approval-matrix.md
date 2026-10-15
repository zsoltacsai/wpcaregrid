# M12 Wave 7 — Approval and Evidence Matrix

No row below is currently authorized for destructive execution. The classification “engineering-ready” means only that a safe plan or blocked-state representation can be implemented. Owner decisions W7-OP-01…05 and W7-SEC-01 were accepted at recommended defaults on 2026-10-15; that does not clear legal, accounting, provider, or safety gates.

| Action / source | Technical preparation | Owner product approval | Legal | Accounting/tax | Provider fact | Security | Current execution disposition |
|---|---|---|---|---|---|---|---|
| Close account / restrict access | Existing reversible Wave5 path | ER-01/08 approved; per-case operation follows existing controls | Existing review for notice/rights remains | Check active billing before restriction | N/A | Existing Owner authorization | Current reversible closure path only; not erasure |
| Anonymize user/customer current profile | Preview exists; field-level executor not implemented | W7-OP-01/02/04 `OWNER_APPROVED` | W7-L-01, L-02, L-03 plus existing legal handoff | W7-A-01/02 | Not applicable to local profile fields | Re-auth, authorization, session/credential revocation review | BLOCKED by qualified reviews; no mutation |
| Release original email for re-registration | RET-03 approved technical target; uniqueness/FK graph requires implementation | Per-subject plan | Legal approval of identifier removal and residual linkage | Check billing identity/snapshot links | N/A | Prove no reversible mapping or message path | BLOCKED pending legal/accounting/security; no email change |
| Revoke local session/reset/pairing/admin credentials | Existing session/pairing patterns require source-specific audit | Per-subject plan | Scope/rights review | Ensure no billing operator access dependency | Credential-holder contract/scope if external | Credential inventory, idempotency, lockout check | BLOCKED except existing user-request/security controls |
| Delete generated privacy export | Wave2 expiry/revoke exists; cleanup path can be inspected | Existing approved 24h link/7d artifact policy | Final export workflow/scope review | N/A | Verify local storage state | Verify revoked links cannot resurrect via restore | Existing export lifecycle only; Wave7 may not run cleanup |
| Delete customer WordPress backup objects | M8 per-object deletion and safety predicates; Wave6 reconciliation | RET-04/22/23/24/33 and W7-OP-03/04 `OWNER_APPROVED` | W7-L-01/02/03 and customer authority | W7-A-01/02 where billing history applies | W7-P-01, per bucket/version/replica | Journal, restore/update/final-recovery guards | BLOCKED; no provider call, no physical-deletion claim |
| Delete customer website/site content or remote Connector data | No generalized erasure executor; separate site offboarding needed | W7-OP-03 `OWNER_APPROVED` — excluded from ordinary account erasure | Customer authority/contract scope | Check any service record | Provider/remote API facts | Verify ownership, credentials, quarantine and no cross-tenant targeting | OUT OF ACCOUNT ERASURE; separate explicit authorization required |
| Delete platform backup artifact | M9 platform backup management exists; no Wave7 execution | Separate Owner policy and recovery-copy approval | Legal retention/holds | Accounting/incident relevance | S3 version/lifecycle/object lock facts | Minimum recovery points, key/journal recovery | WAIT_LEGAL / WAIT_FACT / WAIT_SECURITY; no action |
| Retain/remove billing identity/history | Read-only impact graph and restrictive FKs | Cannot override compliance-locked policy | Determine erasable current fields and roles | W7-A-01/02 plus accounting handoff | Stripe/Számlázz behavior where relevant | Access/minimization review | WAIT_ACCOUNTING + WAIT_LEGAL; snapshots immutable |
| Delete Stripe webhook raw payload | Encrypted raw inbox + minimized event table identified | RET-07/08 technical defaults accepted | Purpose/retention validation | Billing reconciliation/idempotency schedule | Verify Stripe event semantics, no delete API assumption | Preserve replay safety; no secrets in logs | WAIT_LEGAL / WAIT_ACCOUNTING / WAIT_SECURITY; no purge |
| Delete rendered email body / retain delivery metadata | Outbox and JSON payload identified | RET-09/10 technical defaults accepted | Purpose and recipient linkage review | Invoice evidence boundary | SMTP provider facts where external copy exists | Pending delivery and replay suppression | WAIT_LEGAL / WAIT_ACCOUNTING / WAIT_FACT; no cleanup |
| Delete app logs / audit/security data | Only observed sources may be planned; source coverage partial | RET-11/12 technical defaults accepted | Retention, rights, hold | Accounting/audit relevance | External logging providers not yet fully verified | Incident evidence and integrity rules | WAIT_LEGAL / WAIT_SECURITY / WAIT_FACT; no purge |
| Delete staging/test records | Require trustworthy synthetic tag and environment proof | RET-13 accepted | Legal scope only if production data present | No real financial evidence | TEST provider scope | Isolation/no production links | Synthetic-only, scoped future test; unknown records blocked |
| Provider deletion task dispatch | Task metadata service exists; no provider-delete client in privacy task path | W7-OP-02/04/05 `OWNER_APPROVED` | Applicable scope and hold review | Financial holds | Verified per-provider capability and absence semantics | Least privilege, credentials, retry/idempotency | `WAIT_FACT` and category approvals; no API call |
| Tombstone write/replay | Wave6 independent object journal/replay exists | Wave7 action-specific plan | W7-L-03 | Ensure no accounting payload | Storage durability/keys remain factual gate | Integrity, key recovery, quarantine | Recovery suppression foundation exists; future per-action integration must be tested; no Wave7 mutation |

## Required approval evidence record

For every qualified review, record only safe metadata: `review_type`, approved category/scope, status, effective UTC date, reviewer role/organization, non-secret reference ID, policy version, and any floor/exception. Do not store the full confidential advice by default. A review state is separate from Owner product acceptance and technical configuration.

## Current gate summary

- Owner product/security decisions W7-OP-01…05 and W7-SEC-01: `OWNER_APPROVED` on 2026-10-15. This does not authorize any action or clear a qualified review.
- Legal waits: W7-L-01…03 and existing Wave7-relevant items in `legal-review-handoff.md`.
- Accounting waits: W7-A-01…02 and existing itemized retention schedule in `accounting-review-handoff.md`.
- Provider facts: W7-P-01, including bucket versioning/Object Lock/lifecycle/version-delete behavior and external copy scope.
- Safety lock: `M12_DESTRUCTIVE_RETENTION_ENABLED=false`; no generic Owner setting may override it in Wave7.
- No deletion, anonymization, provider deletion, or customer-site mutation has been performed.

## Safe implementation candidate update — 2026-10-16

| Area | Candidate evidence | Current state |
|---|---|---|
| Execution plan and actions | Immutable versioned records, deterministic preview hash, current-source fingerprint, action blockers and idempotency key | IMPLEMENTED; exact-head CI pending |
| Owner approval | Owner-only routes, password re-authentication, typed exact-hash confirmation, stale-source invalidation, pre-execution cancellation | IMPLEMENTED; staging validation pending |
| Profile anonymization | Field allowlist, non-deliverable email replacement, auth/session/token and Connector/pairing revocation | Synthetic isolated test path only; all real execution gates OFF |
| Journal/replay | Minimized profile suppression event appended/read back/integrity checked before mutation; actual T0 SQLite restore then replay; repeated replay idempotent | LOCAL SYNTHETIC TEST PASS; staging proof pending |
| Accounting evidence | Invoice snapshot content hash unchanged in synthetic test | PROTECTED IN LOCAL TEST; WAIT_ACCOUNTING remains |
| Backups/providers | Reconciliation preparation only; no destructive adapter/call | WAIT_FACT; no provider deletion |
| Release | Local focused tests pass; related suite has zero failures; full local suite has one unrelated WSL gitdir packaging failure | EXACT-HEAD CI, merge, guarded deploy and isolated staging E2E pending |

No evidence in this update resolves W7-L-01…03 (`WAIT_LEGAL`), W7-A-01…02 (`WAIT_ACCOUNTING`), or W7-P-01 (`WAIT_FACT`). `M12_DESTRUCTIVE_RETENTION_ENABLED=false` and all action gates remain mandatory.

## Release evidence update — 2026-10-16

PR #69 implementation head b9865b011d2a1360ebba9569043069278801142b passed all five required jobs in GitHub Actions run 38815372947 and merged as 342f50cd94345dddcb5e5449d22040f3fad187d2. Exact primary is deployed to staging; health and false global/action gates were verified. Local synthetic anonymization and actual T0 restore/replay passed. Isolated staging synthetic E2E remains NOT RUN, so this matrix does not mark Wave 7 release/closure complete. Legal W7-L-01…03 remain WAIT_LEGAL, accounting W7-A-01…02 remain WAIT_ACCOUNTING, and provider W7-P-01 remains WAIT_FACT. No provider deletion or production destructive execution is authorized.
