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
| Page copy | `app/Content/HomepageContent.php` |
| Demo dashboard data | `app/Content/DemoDashboard.php` |
| Statuses (label + colour tone) | `app/Enums/Status.php` |
| Design tokens (colours, radii, fonts, container) | `resources/css/app.css` (mirrors `design/brand/DESIGN_TOKENS.css`) |
| Brand logo and icon components | `resources/views/components/brand/`, `app/Support/BrandAsset.php` |
| CTA link targets | `config/marketing.php` |

## Pricing

Prices live in `config/pricing.php` and are read through `App\Pricing\PricingCatalog`, never hardcoded in Blade.

- Each plan has a price per currency and billing interval, and an optional Stripe Price ID (from `.env`).
- To add a currency, add it under `currencies` and add a matching key under each plan's `prices`. The catalogue is built for one currency at a time (`PricingCatalog::fromConfig($config, 'EUR')`).
- The "2 months free" message is calculated from the prices, so it stays correct if prices change.
- Plan buttons link to `MARKETING_SIGNUP_URL?plan=<product-line>-<plan>&billing=<monthly|annual>`, ready to hand to a checkout endpoint.

## Demo content

The dashboard, license and activity-log previews use fictional data from `app/Content/DemoDashboard.php` and always show a "Demo data" / "Example" label. They must not be presented as real customer statistics.
