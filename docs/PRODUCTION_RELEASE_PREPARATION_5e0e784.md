# Production release preparation — proposed candidate `5e0e784`

**Decision:** preparation only; production deployment is **NO-GO**.

- **Protected primary:** `claude/ecstatic-wozniak-oemyuf`
- **Proposed candidate:** `5e0e784c69bcd5cfdcaa3f03a4734cb62ed40bd8`
- **Exact-SHA CI:** [run 43386058267](https://github.com/zsoltacsai/wpcaregrid/actions/runs/43386058267), `completed/success`, 38/38 check runs successful.
**Machine-readable manifest:** [`PRODUCTION_RELEASE_CANDIDATE_5e0e784.json`](PRODUCTION_RELEASE_CANDIDATE_5e0e784.json)

The candidate SHA is a verified source baseline, not a final release tag, deployment
record, or Owner activation authorization. Repository CI is not runtime acceptance.
No production or staging host was contacted for this preparation.

## Intended release scope

The only proposed first-release scope is the public Hungarian/English marketing
surface. Explicitly keep unavailable:

- customer registration, account creation, and operational authentication flows;
- checkout and all live payment processing;
- provisioning, subscription activation, and paid SaaS operations;
- Connector onboarding and customer-facing operational features that have not passed
  their own release gates.

Do not create a customer, order, payment, service, or Connector record during negative
acceptance tests. `STRIPE_ALLOW_LIVE_MODE` and payment credentials/configuration are
not to be changed as part of this preparation. The current production and staging
runtime state is **UNKNOWN** until freshly observed on the exact candidate.

## Independent QA and AppSec evidence

Both independent reviews inspected exact SHA
`5e0e784c69bcd5cfdcaa3f03a4734cb62ed40bd8`:

| Role | Decision | Agent/provider/model | Evidence artifact | SHA-256 |
| --- | --- | --- | --- | --- |
| Integration QA | PASS — source-level marketing-only route isolation only | T3 Code / Codex / `gpt-6-luna` | [`QA report`](release-evidence/qa-5e0e784.md) | `82d6b3ebef14fdde464fa173fbf5a91e11c496577647f4977ac8d3272a17dca4` |
| AppSec | BLOCKED — production acceptance pending fresh runtime proof of worker/scheduler fences | T3 Code / Codex / `gpt-6-luna` | [`AppSec report`](release-evidence/appsec-5e0e784.md) | `c58201104e2170cbc7f73db9da80f02ff596d515ce614eba8bb2e7a4357f6409` |

The reviewers did not access hosts, database credentials, customer data, or
secrets. Their reports are static-source assessments, not staging or production
acceptance. **Combined review gate: HOLD.** Do not reuse another commit's review.

The release-review V3 record contract in
[`RELEASE_REVIEW_POLICY.md`](RELEASE_REVIEW_POLICY.md) requires a real PR number and
that PR's exact HEAD SHA. The candidate here is protected-primary merge baseline
`5e0e784…`, not the head of an open release PR. No V3 records have been posted;
do not misassociate either report with PR #382 or the documentation PR. Obtain
records only when the release candidate has a matching PR/head identity accepted by
the policy, then verify the published #174 comments.

## Staging acceptance procedure — planning only

Run only after the applicable Owner authorization and all backup/restore and
migration prerequisites are independently satisfied. Bind every observation to the
deployed SHA `5e0e784c69bcd5cfdcaa3f03a4734cb62ed40bd8`; preserve sanitized output and
do not export secrets, customer information, or raw environment files.

1. **Identity and source:** verify the staging host through its approved channel;
   record a non-secret host identity, deployed commit, clean release state, and the
   release manifest. Stop on any SHA mismatch.
2. **Boot and configuration:** verify application boot and `system:check` without
   printing secret values. Confirm production-like safety settings, HTTPS base URL,
   secure cookies, headers, trusted-proxy behavior, and error logging. Do not change
   values during this acceptance run.
3. **Database compatibility:** record server version, target migration inventory,
   migration status, and the approved privilege route. Run no migrations until the
   isolated rehearsal, backup, restore, and separate deployment gates pass.
4. **Public HU/EN pages:** in a real browser, check both language landing pages,
   navigation/language switching, text and assets, canonical links, redirects,
   informational pricing, responsive desktop/mobile layout, console errors, and
   network failures.
5. **HTTPS/security:** verify HTTP-to-HTTPS behavior, certificate validity,
   HSTS and other required security headers, secure cookie attributes, and no
   mixed-content requests.
6. **Negative feature checks:** confirm registration/account creation, customer
   authentication routes, checkout, payment/webhook activation, provisioning,
   Connector onboarding, and other unfinished customer/service routes are
   inaccessible. Confirm the tests created zero customer/order/payment/service
   records and did not enqueue operational work.
7. **Evidence:** record timestamp, exact SHA, browser/runtime versions, each check's
   PASS/FAIL/UNKNOWN, sanitized screenshots or test reports, and report hashes.
   A missing result remains UNKNOWN; any unexpected enabled customer/payment route
   is FAIL and stops acceptance.

No staging deployment, migration, setting change, customer transaction, or production
operation is authorized by this plan.

## Database migration and restore readiness

At this candidate, the repository contains 75 migration files. The trigger-creating
migrations are:

- `2026_11_02_000002_create_transition_v2_requests_table.php`
- `2026_11_02_000003_create_transition_history_events_table.php`

The open [PR #380](https://github.com/zsoltacsai/wpcaregrid/pull/380), exact test head
`eae459a79bc335a032e68d645f961963704d8eac`, ran a disposable MySQL 8.4.11 qualification
with binary logging ON, ROW format, and
`log_bin_trust_function_creators=OFF`. Its successful workflow
[43321682559](https://github.com/zsoltacsai/wpcaregrid/actions/runs/43321682559)
reports `least_privilege_path=UNRESOLVED_SUPER_ONLY`: the application account without
SUPER fails trigger installation with error 1419; only the tested SUPER-assisted
path created the triggers. This is a synthetic test result, not proof of current
production DB settings or privileges.

### Required procedure before any migration-bearing release

1. On an isolated MySQL 8.4 clone, restore a fresh verified backup associated with
   the application/configuration and the exact release SHA. Prove the restore before
   using it for migration rehearsal.
2. Inventory all 75 migrations from the exact candidate tree, identify trigger DDL,
   and rehearse the full sequence on a separate restored clone with binary logging ON
   and trust-function-creators OFF. Capture partial-DDL state and recovery behavior.
3. Do not grant global SUPER to the application account and do not turn on
   `log_bin_trust_function_creators` as a workaround. No least-privilege trigger
   route is yet verified. A DBA-assisted operation must be designed and independently
   reviewed before use, with exact account/grant scope, approval, temporary grant and
   revoke evidence, trigger DEFINER identity, durable least-privilege definer grants,
   and checks that application writes continue to work after the DBA session ends.
4. Stop on any migration failure, unexpected ledger, partial DDL outside the rehearsed
   state, changed global MySQL setting, unrecognized trigger definer, or cleanup
   discrepancy. Preserve the failed database state for recovery analysis; do not run
   destructive `down()` migrations as rollback.
5. Recover data only by restoring the verified backup into the isolated target using
   the approved restore procedure. Re-deploying old application code does not reverse
   schema changes.

The exact grants, definer lifecycle, and production applicability remain **UNKNOWN**
until approved evidence exists. No production database or privilege change is
authorized here.

## Deployment-controller compatibility

The current [`PRODUCTION_MARKETING_RELEASE_RUNBOOK.md`](PRODUCTION_MARKETING_RELEASE_RUNBOOK.md)
still pins its production-helper source baseline to
`cf2a944c27a2a24dbdf1097bf0ba766e6edd48fe` and helper version 2.0.0. The current
[`RELEASE_REVIEW_POLICY.md`](RELEASE_REVIEW_POLICY.md) states V3 is not yet enforced
by that helper version. This is a documentation/policy compatibility gap: do not
assume the old helper manifest, hash set, review contract, or source identity is
valid for candidate `5e0e784…`. Reconcile and independently review the exact
controller/helper contract before any production authorization. No helper or host
configuration was changed by this preparation.

### Recovery objectives and rollback

Required evidence includes a fresh, integrity-verified application/config/database
backup bound to the exact SHA and timestamp; protected recovery for `.env` and
`APP_KEY`; a successful isolated restore; structural/data checks; and completed
release migrations on a separate restored clone. Keep all secret values out of
reports and artifacts.

The application rollback is an exact-SHA code/configuration rollback through the
approved controller, with the public HTTP gate held closed on error. Database schema
recovery is the verified isolated backup restore path, not a code rollback. The
Owner has not set the maximum acceptable recovery point (RPO/data loss) or recovery
time (RTO); both are **UNKNOWN** and must be decided before activation. Backup
retention and availability are also not established by repository CI.

## GO-6.4 diagnostic helper is separate

GO-6.4 is an optional host-diagnostics workstream, not a required application release
artifact or release-controller dependency. Its installation remains **HOLD** while
the Rackforest root-console identity/preflight is Owner-only; its first invocation
needs a separate exact-scope Owner GO. The helper itself reports DB privilege and
trigger facts as UNKNOWN and restore proof as NOT_PROVEN. It cannot satisfy the
database, backup, or restore gates above.

## Stop conditions and final activation gate

Keep production **NO-GO** if any of the following is missing, failed, stale, or
unverifiable: exact candidate CI; exact-SHA QA/AppSec; exact-SHA staging acceptance;
complete migration rehearsal; accepted trigger/DEFINER procedure; fresh backup and
actual isolated restore proof; rollback decision and RPO/RTO; current feature/payment
isolation; monitoring and error-response plan; or the final Owner release GO.

The final Owner GO must identify the exact application SHA, target host, authorized
window, backup/restore evidence IDs, migration and DBA procedure, smoke-test evidence,
stop/rollback triggers, RPO/RTO, and the marketing-only scope with all excluded
customer/payment features disabled. This document grants no such authorization.
