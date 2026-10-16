# M14.1 Rate Limit and Abuse Matrix

Limiter definitions are in `app/Providers/AppServiceProvider.php`; route attachment is in `routes/web.php`, `routes/owner.php`, `routes/operator.php`, and `routes/api.php`. Values below are source-defined limits. Verify actual shared storage and response behavior before calling a control effective.

| Traffic class | Source limiter / observed limits | Key dimensions | Required proof / gap |
|---|---|---|---|
| Login / reset email | `login`: 5/min normalized email+IP and 20/min IP; `password-email`: 3/min email, 10/min IP | identity and source | enumeration-neutral response, distributed 429, reset-token delivery abuse |
| Activation | `login` | email+IP/IP | token brute-force, resend boundary and retry test |
| Checkout / promotion | `checkout`: 10/min +30/hour IP; `checkout-preview`: 30/min +120/hour IP | source IP | idempotent duplicate checkout, bot/load and proxy IP trust review |
| Connector pairing/API/transfer | pair 5/min,20/hour IP; API 30/min connector; transfer 600/min connector; admin access 20/min connector | connector/IP | payload/bytes/concurrency limits are separate; high transfer rate justified by chunk protocol and must have body limits |
| Customer actions | `account`: 20/min,200/hour user; several inline per-route limits; VIES 5/min user,15/hour IP | authenticated user/IP | map every write route and all expensive external calls; normalize inline policies where needed |
| Owner sensitive/external | sensitive and external per Owner; platform backup 2/hour; S3 probe 2/min; mail/coupon/staff invitation dedicated limits | owner ID | test all external calls and multi-node cache sharing; no bypass under alternate guard |
| Operator operations | backup 6/min +10/hour/site; update 10/min +30/hour/site; restore 3/min +5/hour/site; security 10/min +30/hour/site; remediate 3/min +10/hour/site; Connector 5/min +10/hour/site | operator ID and website | concurrent requests, cross-site key collision, queue retries, resource budget |
| System/job/commands | not HTTP-throttled; queue uniqueness/locks vary | job ID/domain key | bounded batch/page/body/time, concurrency and retry amplification; separate from request throttling |

`config/cache.php` defaults to database cache, but the staging/environment cache selection and multi-instance shared-store behavior are deployment facts. Test key isolation across Owner/Operator/customer and verify limits return a useful 429 without revealing account existence. Apply per-route coverage rather than adding one blanket limiter to all reads.

## Current runtime attachment reconciliation — 2026-10-16

Fresh runtime route-list output from staging SHA `e4659f30140669c9e229c8e583d35e6bb377a979` confirms **141** state-changing route entries and **31 distinct `throttle:*` route middleware strings**. The 141 divide into 130 browser, 10 Connector API, and one signed Stripe webhook entry. All except four have an attached throttle: the three logout endpoints (session teardown only) and `stripe.webhook` (provider signature validation plus event idempotency). All 10 Connector API mutations have a dedicated connector/API limiter; `/pair` uses IP limits before a connector exists, and the other nine use connector authentication before per-connector limits.

This closes the **source attachment inventory** portion of AS-08. It does not close limiter effectiveness, fairness, enumeration-neutral response, alternate-path bypass, cost/body limits, or cross-worker scope for every limiter. The one shared-store process test remains evidence for only the exercised limiter. AS-08 therefore remains `PARTIAL` pending the behavioral abuse matrix.

## Final M14.1 rate-limit reconciliation — 2026-10-16

The preceding status is historical and superseded for this M14.1 disposition. Runtime reconciliation maps 141 state-changing routes to 27 named limiter definitions and 31 attached middleware forms. The only unthrottled browser actions are three logout/session-teardown routes; the Stripe webhook is protected by signature verification and idempotency. Connector API mutations have explicit per-IP pairing or authenticated per-connector limits.

Bounded behavioral probes demonstrate 429 behavior for 12 limiter classes: login, password-reset email, Connector pairing/API, admin access, Owner sensitive/coupon, Operator backup/restore/Connector, VIES verification and checkout. Password-reset probes additionally cover irrelevant parameter changes, multiple source IPs for an account, two legitimate synthetic identities behind one IP, and boundary/fairness behavior. Shared-store behavior was verified across independent processes for the exercised counter. No unsafe load test was run.

Fifteen specialized aliases have route/source mapping but lack an individual 429 and fairness probe: `connector-transfer`, `connector-admin-access`, `admin-access-full`, `owner-platform-backup`, `owner-platform-s3-probe`, `owner-external`, `owner-settings-mail`, `staff-invitation`, `operator-action`, `operator-update`, `operator-security`, `operator-remediate`, `account`, `owner-vies`, and `checkout-preview`. Alternate-route equivalence and namespace/key fairness are not exhaustively behavior-probed for each alias. This is a documented **medium-only residual**, not an unclassified or missing route control.

**AS-08: PASS_WITH_MEDIUM_RESIDUAL for M14.1.** Reusable behavioral tests establish limiter functionality for the principal high-risk classes and every mutator is mapped. Broader per-alias abuse/fairness testing remains appropriate for later M14 hardening. Do not interpret this as a completed load test or proof of production multi-node topology beyond the observed staging shared-store check.
