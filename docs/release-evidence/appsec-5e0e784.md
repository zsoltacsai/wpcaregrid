# Independent AppSec Review — Proposed WPCG Release Candidate

- **Repository:** `zsoltacsai/wpcaregrid`
- **Exact reviewed commit:** `5e0e784c69bcd5cfdcaa3f03a4734cb62ed40bd8`
- **Scope:** static source review for the proposed HU/EN marketing-only release boundary, including customer authentication/registration, checkout/payment/webhooks, provisioning, Connector/API paths, secrets/configuration handling, and background-work isolation.
- **Provider/model/runtime:** OpenAI Codex harness in T3 Code; `gpt-6-luna`, medium reasoning.
- **Verdict:** **BLOCKED for production AppSec acceptance pending fresh runtime evidence.** Static HTTP fencing is strong in the reviewed source; it does not prove the production runtime has workers and scheduler safely fenced.
- **Review method:** read-only `git show`/`git grep` at the exact commit. No host access, helper execution, secrets access, test execution, source changes, PR comments, merge, or ledger update.

## Evidence and assessment

### HTTP and route boundary — static PASS

- `bootstrap/app.php:37-40` appends `MarketingOnlyReleaseGate` as global middleware, before route-specific handling. The exact tree can be cited at [bootstrap/app.php](https://github.com/zsoltacsai/wpcaregrid/blob/5e0e784c69bcd5cfdcaa3f03a4734cb62ed40bd8/bootstrap/app.php#L37-L40).
- In the default `marketing_only` profile, `MarketingOnlyReleaseGate.php:85-99` permits only the constrained public surface and turns `/up` into a bodyless response. Its allowlist (`:44-58`, `:208-227`, `:344-367`) permits public marketing/legal routes, built assets, and presentation-only locale/currency updates. Other route paths and methods are denied with an empty 404.
- `routes/web.php:55-65` defines checkout and Stripe webhook routes, but these paths are outside the marketing allowlist. The customer area at `routes/web.php:86-141` is behind `auth` and is likewise outside that allowlist. `routes/api.php:12-46` Connector endpoints are also outside it.
- `config/marketing_release.php:3-27,32-53` defaults to `marketing_only`; the `staging_admin_qa` and `staging_active` profiles are admitted only when their exact staging identity/configuration predicates pass. Any unadmitted profile falls back to `marketing_only`. `staging_active` explicitly requires the staging-next app URL/environment and `STRIPE_ALLOW_LIVE_MODE=false`.
- Public registration routes are not present in `routes/web.php`; customer activation requires a token path and is not in the marketing allowlist. Login, password reset, account, checkout, Connector/API, Stripe webhook, and provisioning paths are not reachable through the marketing-only HTTP fence.

### Configuration and payment safety — static PASS, runtime UNKNOWN

The source default for Stripe live mode is false in `config/marketing_release.php:19-27`, and marketing-only route fencing blocks checkout/webhook HTTP paths. This is source evidence only. The effective production environment, cached config, Stripe credentials/mode, and any Owner-managed settings were not inspected; they remain **UNKNOWN**.

### Blocking release condition — background work is not fenced by the marketing-only HTTP profile

- `routes/console.php:70-77` attaches `StagingSchedulePolicy` to every scheduled event.
- `StagingSchedulePolicy.php:78-82,103-107` applies its restriction only when it recognizes the `staging-next` environment; outside that environment, `denial()` returns no restriction. Its class comment at `:9-16` explicitly says production and legacy staging retain their prior schedule.
- The schedule contains external/customer-related work, including Connector, Stripe, billing email, offers, backups, and updates (`routes/console.php:20-66`; tasks are also enumerated in `StagingSchedulePolicy.php:39-62`).

Thus the marketing-only global HTTP middleware alone does not stop queued jobs or scheduled commands. The release checklist already requires persistent worker/scheduler fences, but no current runtime evidence verifies them for this candidate. Before AppSec PASS, record an exact-release production runtime inspection proving the scheduler and all relevant workers are disabled or held under the approved marketing-only fence, with no customer/payment/provisioning work runnable. No source change is asserted necessary if this independent runtime gate is met.

## Findings

1. **BLOCKER — production background-work isolation is not proven.** Source-level marketing-only mode fences HTTP requests, while scheduler and queue behavior outside staging-next remains the prior schedule. Obtain fresh, sanitized exact-release evidence for persistent worker/scheduler fences, pending jobs retained/held, and no active Stripe/Connector/payment/provisioning task path. This is a runtime acceptance blocker, not a claim that current host configuration is unsafe; the host was not inspected.
2. **No static HTTP-fence bypass found in the reviewed paths.** The default profile and route allowlist fail closed for non-marketing HTTP paths. This does not establish that production actually loaded the intended profile or configuration.

## Required runtime evidence for final AppSec PASS

- Exact deployed release SHA equals the reviewed candidate.
- Effective profile is `marketing_only`, enforcement is active, `APP_ENV=production`, `APP_DEBUG=false`; no configuration/cache drift.
- Runtime negative requests for login, password reset, customer activation, checkout, payment/webhook, Connector/API and account/provisioning paths are denied; public HU/EN pages and assets work.
- Stripe live mode remains disabled; no live credentials are asserted or printed.
- Scheduler and queue workers are persistently fenced; no relevant job is running or dispatchable. Verify without changing state.
- Production HTTPS/security headers and error handling are checked through a fresh staging/production-like browser acceptance record.

## Limitations

This is static source review, not an exact-SHA staging or production runtime review. No tests or CI were run by this reviewer. No current host, effective environment, queue, scheduler, Stripe account, customer data, DB, backup, restore, or release deployment state was accessed. Therefore the proposed release is **not approved for production** by this report.
