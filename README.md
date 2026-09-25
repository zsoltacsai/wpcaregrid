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

Tests: `php artisan test`

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

## Demo content

The dashboard, license and activity-log previews use fictional data from `app/Content/DemoDashboard.php` and always show a "Demo data" / "Example" label. They must not be presented as real customer statistics.
