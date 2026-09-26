# WP CareGrid

Marketing site for [wpcaregrid.com](https://wpcaregrid.com), a WordPress and WooCommerce maintenance service.

Stack: Laravel 13, Blade components, Tailwind CSS 4, Alpine.js, IBM Plex Sans/Mono (self-hosted via `@fontsource`).

## Local setup

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite && php artisan migrate
npm run build        # or `npm run dev` while working on the front end
php artisan serve
```

Tests: `php artisan test` (server; S3 and SFTP providers are tested against moto and OpenSSH when installed) and `cd wordpress && composer install && vendor/bin/phpunit` (connector plugin in a real WordPress; set `WP_CAREGRID_TEST_MYSQL=user:password@host/database` to include the database backup and restore tests).

## Brand

The approved brand pack lives in `design/` (guidelines in `design/brand/BRAND.md`, tokens in `design/brand/DESIGN_TOKENS.css`, logos and icons as SVG/PNG). Read it before changing the UI.

- Logos are inlined from `design/brand/logo/*.svg` by `App\Support\BrandAsset` via `<x-brand.logo>` / `<x-brand.icon>` — never redraw them. The header drops the optional "WORDPRESS MAINTENANCE" descriptor, which is unreadable at that size (BRAND.md allows this).
- Primary actions use the accent green. Buttons use `action` (#4F7D65), a slightly deeper shade of #5B8C72, because white text on #5B8C72 is only 3.9:1 (below WCAG AA).
- IBM Plex Mono is only for timestamps, logs, version numbers, IDs and technical status metadata.

## Where things live

| What | Where |
| --- | --- |
| Homepage route and controller | `routes/web.php`, `app/Http/Controllers/HomeController.php` |
| Page composition (section order) | `resources/views/pages/home.blade.php` |
| One component per page section | `resources/views/components/sections/` |
| Shared UI (button, status badge, icon, section, service card…) | `resources/views/components/ui/` |
| Header, footer | `resources/views/components/site/` |
| Pricing table and pricing card | `resources/views/components/pricing/`, `resources/js/components/pricing-table.js` |
| Dashboard previews, process step, activity row, FAQ item | `resources/views/components/{dashboard,process,activity,faq}/` |
| Page structure (order, icons, links) | `app/Content/HomepageContent.php` |
| Demo dashboard data | `app/Content/DemoDashboard.php` |
| Statuses (label + colour tone) | `app/Enums/Status.php` |
| Design tokens (colours, radii, fonts, container) | `resources/css/app.css` (mirrors `design/brand/DESIGN_TOKENS.css`) |
| Brand logo and icon components | `resources/views/components/brand/`, `app/Support/BrandAsset.php` |
| CTA link targets | `config/marketing.php` |
| Checkout, Stripe gateway, webhook handling | `app/Checkout/`, `app/Http/Controllers/CheckoutController.php`, `StripeWebhookController.php` |
| Checkout pages | `resources/views/pages/checkout/`, `resources/views/pages/pricing.blade.php` |
| Operator console | `routes/operator.php`, `app/Http/Controllers/Operator/`, `app/Operator/`, `resources/views/operator/` |

## Languages

The homepage is English. All wording lives in translation files, so a language is added without touching components:

| File | Contents |
| --- | --- |
| `lang/en/home.php` | Homepage copy, SEO title and description, navigation, FAQ |
| `lang/en/pricing.php` | Plan names, descriptions and features; pricing table text |
| `lang/en/dashboard.php` | Dashboard preview text (reused by the future customer dashboard) |
| `lang/en/status.php` | Status labels (Healthy, Attention, Critical…) |
| `lang/en/checkout.php`, `countries.php` | Checkout pages, error messages, billing countries |

To add Hungarian, copy `lang/en` to `lang/hu`, translate the values and set the locale (for example per route prefix). Page structure (order, icons, links) stays in `app/Content/HomepageContent.php`; demo data in `app/Content/DemoDashboard.php` holds only keys and numbers. A test fails if a translation key ever shows up as text on the page.

## Pricing

One source of truth: `config/pricing.php`, read through `App\Pricing\PricingCatalog`. The homepage pricing cards, `/pricing` and the checkout all use it.

| Concept | Where | Notes |
| --- | --- | --- |
| Plan (product type × tier) | `plans` | Backup and security-check frequency, and the **complete** feature list per plan. Cards derive "Everything in …" from these lists. |
| Market | `markets` | Billing-country rule (ISO codes, `*` = any), currency and `tax_treatment`. |
| Price | `prices` | Product type, plan, interval, market, currency, amount in minor units (8 990 Ft = `899000`), `stripe_price_id`, `active`. |
| Wording | `lang/en/pricing.php` | Plan names, descriptions, feature labels. |

- Annual prices are stored explicitly (10 × monthly = 2 months free). The UI never calculates them.
- Only the Hungarian market (`HU` → HUF) has prices. EU (EUR), GB (GBP) and the rest-of-world fallback (USD) are defined without prices: no exchange-rate conversion, nothing invented. Checkout for those countries shows "not available yet".
- `tax_treatment` is a recorded business setting (`final_price_no_vat_added` for Hungary, `undetermined` elsewhere), stored on every checkout. No tax is calculated in the app.
- `php artisan pricing:verify-stripe` compares every configured Stripe Price ID with Stripe (exists, active, amount, currency, interval).

## Checkout

`/pricing` → `/checkout?product=…&plan=…&interval=…` → `POST /checkout` → Stripe Checkout → `/checkout/success` or `/checkout/cancel/{intent}`.

- The browser only sends a logical selection (product, plan, interval) plus email, name and billing country. The server resolves the price for the billing country (`PricingCatalog::resolve`); a submitted amount or Stripe Price ID is ignored.
- A `checkout_intents` row is written before the Stripe redirect: selection, market, currency, amount, tax treatment, Stripe Price ID, promotion, status (`pending` → `checkout_created` → `completed` / `cancelled` / `expired` / `failed`).
- Only a signed Stripe webhook (`POST /stripe/webhook`) marks an intent `completed`. The success page changes nothing.
- A repeated submit from the same browser reuses the open Stripe session; `POST /checkout` is rate limited; only the browser that started a checkout can cancel it (the Stripe session is expired too).
- Promotion codes are entered in our form, checked against `promotions` rules (dates, usage limit, product types, plans, intervals, markets, currency), then applied by Stripe via the linked `promo_…` ID. Stripe's own code field is off.
- Stripe runs in test mode: keys come from `.env` (`STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`); a live key is refused unless `STRIPE_ALLOW_LIVE_MODE=true`.

## Customers, subscriptions and accounts

| Model | Purpose |
| --- | --- |
| `User` | Login only (email, password, verification). |
| `Customer` | Commercial entity: contact and billing details, `stripe_customer_id`. One login per customer for now (`customers.user_id`); multi-user/agency access can move to a pivot later. |
| `Subscription` | Mirror of a Stripe subscription: plan, price, status, billing period, cancellation. Status comes only from Stripe events. |
| `Website` | A site covered by one subscription: connection status, health, inventory. |
| `Backup`, `BackupObject` | One backup of one website and its stored objects (checksums, verification). |
| `BackupRestore` | A confirmed restore of one verified backup. |
| `WebsiteEvent` | Audit log of backup and restore events. |
| `CheckoutIntent` | What was bought, linked to the customer and subscription once payment is confirmed. |
| `StripeEvent` | IDs of processed Stripe events (idempotency). |

### Payment → account

1. `checkout.session.completed` (paid) or `async_payment_succeeded` → intent `completed` → customer resolved (logged-in buyer, then Stripe customer ID, then normalised email; an existing profile is never overwritten) → login created if none exists, with a 7-day activation link (`activations` password broker). Passwords are never emailed.
2. `customer.subscription.created/updated/deleted` → status, periods and cancellation synced. Older events never overwrite newer state (`stripe_synced_at`). An active/trialing subscription also confirms payment, so any delivery order works.
3. `invoice.paid` / `invoice.payment_failed` → last payment recorded and emailed; access only changes through the subscription status.
4. The checkout success page only reports: "Your payment is being confirmed." until the webhook has completed the intent.

Every event is recorded in `stripe_events` in the same transaction as its effects: a redelivered event is skipped; a failed one rolls back and Stripe retries.

### Subscription status mapping

| Stripe | Local | Access |
| --- | --- | --- |
| (not received yet) | `pending` | no |
| `incomplete` / `incomplete_expired` | same | no |
| `trialing` / `active` | same | **yes** |
| `past_due` / `unpaid` / `paused` | same | no (payment problem) |
| `canceled` | `canceled` | no |

Unknown future statuses are logged and ignored rather than guessed.

### Customer area

`/login`, `/forgot-password`, `/account/activate/{token}`, and under `/account` (auth + linked, verified customer): dashboard, websites (+ onboarding), subscription, billing, profile. Records are addressed by UUID and every one is checked by a policy (`WebsitePolicy`, `SubscriptionPolicy`, `CustomerPolicy`); another customer's record returns 403. Adding a website needs an active subscription with no website yet (one website per plan).

Emails (`app/Mail`, queued after the database transaction commits): account activation, welcome, payment confirmation, payment failed, subscription activated, subscription canceled. Run a queue worker (`php artisan queue:work`) in production.

## WP CareGrid Connector

The WordPress plugin lives in `wordpress/wp-caregrid-connector`; the API it talks to is `/api/v1/connector` (`routes/api.php`). Architecture, pairing, security model, job types and the data it sends: **[docs/CONNECTOR.md](docs/CONNECTOR.md)**.

Install locally:

1. `php artisan connector:package` builds the ZIP that customers download from their website page.
2. In a WordPress site, set `WP_CAREGRID_API_URL` (and, for plain-HTTP localhost only, `WP_CAREGRID_ALLOW_INSECURE_LOCALHOST`) in `wp-config.php`.
3. Plugins → Add New → Upload Plugin, activate.
4. In the account, open the website and generate a pairing code; enter it under Settings → WP CareGrid.

Operators queue jobs with `php artisan connector:queue-job <website-uuid> <TYPE> --payload='{…}'`. Run the scheduler (`php artisan schedule:work` locally) so connection presence is updated.

## Backups

Files, database and full backups through the connector, stored on S3-compatible storage or SFTP and verified (size and SHA-256) before they count; retention per plan; a verified backup before updates; restores behind a verified safety backup. Architecture, format, verification, retention and restore safety: **[docs/BACKUP_ENGINE.md](docs/BACKUP_ENGINE.md)**.

- Configure storage with `BACKUP_STORAGE` and `BACKUP_S3_*` or `BACKUP_SFTP_*` (see `.env.example`). Without it, backups are unavailable and nothing is queued.
- Retention values are a commercial decision and have no defaults: until `BACKUP_RETENTION_*` are set, nothing is deleted.
- Live restores are off until `BACKUP_RESTORE_ENABLED=true`.
- Operators: `php artisan backup:admin {list|show|run|retry|verify|delete} {uuid}`.
- Needs the scheduler (`backup:schedule`, `backup:monitor`, `backup:retention`) and a queue worker (`TransferBackup`).

## Admin access

Customers open their WordPress admin from the website page without a WordPress password: a one-time token (120 s, single use, stored hashed) is carried by a POST form to the site, the connector claims it and creates a real WordPress session for a managed account. **Open Admin** gives maintenance access (restricted role `wp_caregrid_maintenance`, 30 min); temporary full administrator access (15 min) needs an explicit confirmation. Every step is audited; access can be revoked from the account or from WordPress. Architecture, capabilities and security model: **[docs/ADMIN_ACCESS.md](docs/ADMIN_ACCESS.md)**.

- Durations and limits: `ADMIN_ACCESS_*` (see `.env.example`); levels per plan in `config/admin_access.php`.
- Needs the scheduler (`admin-access:expire`) and connector 0.3.0 on the site.

## Operator console

The internal console for the service team lives at `/operator`, with its own login and guard (customers never get in). Operators are logins with the `operator` role and explicit permissions (`websites.view`, `backups.restore`, `admin_access.full`…), managed only from the command line. The console shows real data only (dashboard, website list with search/filters, website detail, backups, activity). Its actions go through the existing services: `BackupService`, `RestoreService`, `JobQueue`, `AdminAccessService`. Security findings are a placeholder until the Security/Malware milestone. Details: **[docs/OPERATOR_CONSOLE.md](docs/OPERATOR_CONSOLE.md)**.

- Create an operator: `php artisan operator:user you@wpcaregrid.com --standard` (everything except full admin; `--grant=`, `--revoke=`, `--disable`).
- Operators get temporary full WordPress admin only with `admin_access.full` and `ADMIN_ACCESS_OPERATOR_FULL_ADMIN=true` (off by default).

## Demo content

The dashboard, license and activity-log previews use fictional data from `app/Content/DemoDashboard.php` and always show a "Demo data" / "Example" label. They must not be presented as real customer statistics.
