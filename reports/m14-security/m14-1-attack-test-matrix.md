# M14.1 Adversarial Test Matrix

| ID | Attack | Preconditions | Expected control | Evidence required |
|---|---|---|---|---|
| AT-01 | Valid foreign UUID/nested-ID substitution | Two synthetic tenants | 403/404 before side effects | response plus DB/storage/queue assertions |
| AT-02 | Role crossover / stale role | Customer, Operator, Owner accounts | separate guard and live role authorization | all route groups and direct service calls |
| AT-03 | Password reset while attacker session remains | establish second session before token reset | all guard sessions invalidated or explicitly justified | multi-session test on DB session store |
| AT-04 | Concurrent reset-token use | same valid token, two workers | at most one successful reset; deterministic audit | MySQL process gate and token/session assertions |
| AT-05 | Re-auth replay/stale confirmation | sensitive action, expired/other session proof | fail closed | exact preview/action binding tests |
| AT-06 | CSRF on state-changing browser route | authenticated session without valid token | framework rejection | enumerate all POST/PUT/PATCH/DELETE; signed Stripe exception only |
| AT-07 | Limiter bypass or cache split | alternate guard/IP/instance | correct scoped 429 and shared counters | effective staging config + concurrent tests |
| AT-08 | Queue object swapped/deleted/reparented | job queued then source changes | re-resolve and deny/reconcile safely | fake side effects + MySQL stale-parent test |
| AT-09 | Oversized/high-rate transfer and expensive calls | authenticated Connector/Owner | bounded body, calls and concurrency | request-size/config/test probes |
| AT-10 | Output injection through Owner/provider/site data | untrusted field rendered | escaped or allowlisted sanitized output | HTML/attribute/JSON sink assertions; no raw script execution |
| AT-11 | Sensitive data leak in logs/errors/audit | force exception and validation failures | no password/token/PII/secrets | log capture, response snapshots and monitoring payload checks |
| AT-12 | Kill-switch / synthetic-mode bypass | attempt prod context, missing config, forged marker | explicit fail-closed | service-level tests for every action gate |
| AT-13 | Recovery/quarantine race | restore begins during sensitive job | no normal mutation before replay readiness | concurrent recovery lock tests |
| AT-14 | ID enumeration via errors/timing | known/unknown email and object IDs | non-disclosing equivalent behavior | response equivalence and bounded timing diagnostic |

No destructive provider call, ordinary staging customer mutation, or M12 gate change is part of this plan.
