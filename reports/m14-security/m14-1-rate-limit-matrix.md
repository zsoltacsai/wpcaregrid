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
