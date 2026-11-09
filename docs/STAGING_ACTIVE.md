# Staging-next: the `staging_active` profile

**Decision (Owner, 2026-11-09):** `staging-next.wpcaregrid.com` is a full-function development, integration and demonstration environment. The earlier `marketing_only` restriction was a temporary deployment state. The final staging application profile is `staging_active`, usable only on the isolated staging-next stack. Production is unchanged and keeps `marketing_only`.

This document describes the source behaviour. It does not authorise any host operation: every VPS change, worker/scheduler start and external-service activation needs its own Owner GO.

## What the profile is (and is not)

* It lifts the **release restriction** (`MarketingOnlyReleaseGate`), nothing else. It is not an authorisation: every route keeps its own guards, roles, CSRF protection and throttling. No role-check was changed.
* It is a **profile of one environment**, not a feature flag for other environments: it cannot be enabled on production by any configuration.
* Any doubt is an empty `404` (the same answer the marketing profile gives), never a partial opening.

## Required configuration

The profile activates only when **all** of these hold at configuration load (`config/marketing_release.php`); otherwise the effective profile silently falls back to `marketing_only`:

```dotenv
APP_ENV=staging
CAREGRID_DEPLOYMENT_ENV=staging_next
APP_URL=https://staging-next.wpcaregrid.com
APP_DEBUG=false
MARKETING_RELEASE_PROFILE=staging_active
# MARKETING_RELEASE_ENFORCEMENT_MODE must be 'enforced' (the default)
DB_CONNECTION=mysql
DB_DATABASE=wpcg_staging_next
DB_HOST=127.0.0.1
DB_PORT=3306
STRIPE_ALLOW_LIVE_MODE=false        # unset is also false
```

Optional, all closed by default:

```dotenv
STAGING_MAIL_ALLOWLIST=owner@example.test,@testmail.example   # addresses or @domains; see Mail
STAGING_ACTIVE_CONNECTOR_API=false      # opens /api/v1/connector/*
STAGING_ACTIVE_EXTERNAL_SERVICES=false  # opens Cloudflare DNS, platform backups, Szamlazz, VIES, AI health checks
STAGING_ACTIVE_DB_UUID_SHA256=          # recommended pin: SHA-256 of the MySQL server_uuid (also guards against a DB_URL/socket pointing at another server)
```

`STAGING_ADMIN_QA_*` and the `staging_admin_qa` profile are unchanged and unrelated.

## What the gate checks on every request

1. **Environment identity** (config): `app.env`, `app.deployment_environment`, exact `app.url`, `app.debug === false`, enforcement `enforced`, database driver `mysql`, database name `wpcg_staging_next`, database host `127.0.0.1` or `localhost`, `services.stripe.allow_live_mode === false`, and the config-time eligibility flag.
2. **Request origin:** HTTPS, `Host` exactly `staging-next.wpcaregrid.com` (no port, no sub-domain, no IP), no `X-Forwarded-Host` / `Forwarded`, no override headers, no ambiguous path (`//`, `..`, `%`, `;`).
3. **Live database identity:** `select database()` on the actual connection must be `wpcg_staging_next` (an unreadable connection is not an identity). If `STAGING_ACTIVE_DB_UUID_SHA256` is set, `sha2(@@server_uuid, 256)` must match.
4. **Isolation group** of the route (below).

Nothing in these checks logs or returns a value; only fixed reason codes are used (`system:check`).

## Isolation groups

| Group | Routes | Opens when |
| --- | --- | --- |
| Stripe | `/checkout*`, `/stripe/webhook`, `/account/billing*`, `/account/subscriptions/*`, `/account/offers/*/accept` and `/pay`, `/account/websites/purchase`, `/owner/pricing/verify-stripe`, and the writes of `/owner/coupons*`, `/owner/offers*` and price save/publish (`/owner/pricing/prices/{price}`, `…/publish`; they verify against Stripe) and the writes of the Stripe Catalogue Manager (`/owner/pricing/stripe-catalogue/preview`, `…/products/{type}`, `…/{operation}/apply`; its pages stay readable so the refusal reason is visible) | `STRIPE_SECRET` is `sk_test_…`/`rk_test_…`, any publishable key is `pk_test_…`, live mode is off and `STRIPE_MODE` is `test` (the webhook also needs `STRIPE_WEBHOOK_SECRET`). A live key closes the group. |
| Connector API | `/api/v1/connector/*` | `STAGING_ACTIVE_CONNECTOR_API=true` (Owner decision, e.g. for a non-production test site) |
| External services | `/owner/dns/cloudflare*`, `/owner/infrastructure/platform-backups*`, `/owner/billing/synthetic-szamlazz-test*`, `/owner/billing/m11-lifecycle-validation*`, invoice-intent `reconcile`, `/owner/integrations/ai/*`, VAT (VIES) verification | `STAGING_ACTIVE_EXTERNAL_SERVICES=true` |
| Everything else | Owner, Customer, Operator, login, activation, content, SEO, pricing management, onboarding | always (after the checks above) |

Closing one group never closes the rest, and opening one never opens another.

## Mail

On any deployment that identifies itself as staging-next (also when the profile was refused), `App\Release\StagingMailGuard` cancels a message before it leaves the application unless the default mailer is a sink (`log`, `array`) or **every** To, Cc and Bcc recipient is on `STAGING_MAIL_ALLOWLIST` (exact address or `@domain`). It runs in the web process and in queue workers. With no allowlist and a real transport, all mail is blocked. The log records only `reason` and a recipient count, never an address, subject or body. Real customers can therefore not be notified from staging.

`owner:user` prints the invitation link on the console, so the Owner can be activated even while mail is blocked.

## Owner activation

* The existing Owner account is kept. `php artisan owner:user <owner-email>` on an unactivated owner **re-issues** a new single-use token (the previous token stops working) and prints the link once; it never creates a second account **when given the exact existing Owner email** (a different or mistyped email creates a new unactivated Owner and prints "Owner … created"; check the first output line). The invitation is also queued as mail: it waits while workers are fenced and is later sent (if allowlisted) or dropped by the mail guard, by which time the token is superseded or used. On an already activated owner it only says so (use "Forgot password" — that mail is blocked unless the Owner's address or domain is on `STAGING_MAIL_ALLOWLIST`, and with Mailpit as the mailer it is captured there). Run `--remove` only to remove owner access — never as part of this procedure.
* `/staff/activate/{token}` is served under `staging_active` (it is an ordinary route). The Owner sets the password; nobody else sees it.
* The token is in the URL path, so it appears in the web server access log until it is used. It is single-use and expires; do not paste it into tickets, CI output or reports.

## `system:check`

On a staging-next deployment `php artisan system:check --json` reports a `staging_isolation` check: `ERROR` if the profile is requested but the identity, live database or Stripe keys are not what staging requires; `WARNING` while Stripe is not yet in test mode or mail has no allowlist (those features stay closed); `OK` when all is proven. **Workers, the scheduler and any external service are started only after this reads `OK` (or a documented `WARNING` the Owner accepts)** and only with the Owner's GO. No secret, address or hostname is printed.

## Scheduled and queued paths

Console commands and queue workers do not pass through the HTTP gate, so the paths that contact something outside the stack are closed at their own entry while `CAREGRID_DEPLOYMENT_ENV=staging_next` and external services are not opened: `monitoring:run` queues no website probe and an already queued `RunWebsiteProbe` makes no request (the existing synthetic-fixture rule for legacy staging is applied to staging-next, so no customer website is ever probed), `security:vulnerabilities:sync` and `platform:database-backup-schedule` are not scheduled. `STAGING_ACTIVE_EXTERNAL_SERVICES=true` lifts all of these together with the matching routes. The scheduled Stripe commands (`stripe:webhooks:drain`, `stripe:subscriptions:reconcile`, `offers:expire`) are held back on staging-next until the Stripe test-mode keys are proven, and again if a live key appears.

The mail guard, the closed monitoring probes and the `staging_isolation` check apply to **every** deployment that identifies itself as `staging_next`, including one still running the `staging_admin_qa` profile, not only to `staging_active`.

## Behaviour to know

* **Proxy/TLS:** the gate requires `isSecure()` and rejects `X-Forwarded-Host`/`Forwarded`. With Cloudflare in front, use Full (strict) to the origin (or set `TRUSTED_PROXIES` for the proxy). A "Flexible" origin leg makes every request an empty `404`, by design.
* **Database down:** the live-identity query runs per request; if the database is unreachable the whole site answers `404` (including `/up`) rather than serving anything unproven.
* **Blocked mail is dropped, not failed:** the sending job completes, the application logs `Staging mail blocked.` with a reason and recipient count, and outbox-style records count the message as handled. Nothing is retried or sent later.
* **A refused profile is loud, not silent:** if `MARKETING_RELEASE_PROFILE=staging_active` is set where the identity does not hold (for example by mistake on production), the site stays on `marketing_only`, the mail guard blocks non-allowlisted mail and `system:check` reports `ERROR` for `staging_isolation`. Never set that variable outside staging-next.
* **Stripe defence in depth:** the Stripe gateways already refuse a live key unless `STRIPE_ALLOW_LIVE_MODE=true`, and `staging_active` is refused when that is true.

## Not covered by source (host/Owner checks)

* **Cloudflare / origin:** the Cloudflare restriction is an infrastructure control. Verify that the origin refuses direct access (see the deployment plan) — the application only checks the `Host` header.
* **Stripe webhook secret** of the staging endpoint (`whsec_…`) and the test-mode keys must be set by the Owner; they are never in the repository.
* **Workers/scheduler:** fenced until the isolation check is accepted. A connector pointed at staging must be a non-production site.
* The deployment helper (`1.4.9`) probes the marketing identity only for the `production` profile; no helper change is needed for staging-next.

## Deployment plan (needs Owner GO; nothing here was executed)

Preconditions (read-only): CHECK1 passes with the new expectations; the installed SHA is `f5e9c562b4d896d944104f375008e04b1a7ebc21`; this PR is merged with exact-merge-SHA CI green; no migrations are included (`php artisan migrate:status` shows nothing pending).

1. **Backups first.** Copy `.env` to a root-only directory with its hash and owner/mode; take a `mysqldump --single-transaction` of `wpcg_staging_next` through the approved DBA socket route and verify it with `gzip -t`. Record the currently installed SHA.
2. **Close** the staging-next maintenance gate; keep workers and the scheduler fenced.
3. **Code:** check out the exact merged SHA (detached), `composer install --no-dev --optimize-autoloader`. No asset build is needed (no frontend change).
4. **`.env`:** set the keys under *Required configuration* (including `DB_HOST=127.0.0.1`, `DB_PORT=3306`) with the atomic, backed-up edit used for the earlier `.env` correction; leave Stripe and mail values to the Owner.
5. **Reload PHP-FPM** (opcache). Rebuild the config cache only if one is in use.
6. **Verify (read-only):** `system:check --json` → `staging_isolation` is not `ERROR`; `/up` 200; `/owner/login` 200; `/checkout` is 404 until Stripe test keys are set; `/api/v1/connector/pair` is 404; direct origin access refused.
7. **Open the gate** (the Cloudflare IP restriction continues to limit who can reach staging-next). The Owner activation link cannot be used while the maintenance gate blocks `/staff/activate/*`.
8. **Owner activation:** `owner:user <owner-email>` as the application user, hand the link to the Owner over a private channel, the Owner sets the password; verify `/owner/login` and `/owner`. If the link is not used, the unused single-use token simply expires; re-run the command for a new one.

**Rollback:** close the gate; `git checkout f5e9c562b4d896d944104f375008e04b1a7ebc21`, `composer install --no-dev --optimize-autoloader`; restore the backed-up `.env` (verify its hash); reload PHP-FPM; reopen the gate. The database is untouched by this release (no migrations), so no restore is needed; the dump exists only as a precaution.

Origin bypass check (read-only, from a host outside Cloudflare's ranges): `curl -sk -o /dev/null -w '%{http_code}\n' --resolve staging-next.wpcaregrid.com:443:<origin-ip> https://staging-next.wpcaregrid.com/up` must not return `200`.

## Scheduler (2026-11-09)

- Staging-next scheduler tasks are governed by one central fail-closed allowlist (`App\Release\StagingSchedulePolicy`); audit, rules and the root activation procedure: [STAGING_SCHEDULER.md](STAGING_SCHEDULER.md). Production and legacy staging schedules are unchanged.
