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

## Where things live

| What | Where |
| --- | --- |
| Homepage route and controller | `routes/web.php`, `app/Http/Controllers/HomeController.php` |
| Page composition (section order) | `resources/views/pages/home.blade.php` |
| One component per page section | `resources/views/components/sections/` |
| Shared UI (button, badge, icon, section, feature card…) | `resources/views/components/ui/` |
| Header, footer, logo | `resources/views/components/site/` |
| Pricing table and plan card | `resources/views/components/pricing/`, `resources/js/components/pricing-table.js` |
| Dashboard previews, activity row, FAQ item | `resources/views/components/{dashboard,activity,faq}/` |
| Page copy | `app/Content/HomepageContent.php` |
| Demo dashboard data | `app/Content/DemoDashboard.php` |
| Statuses (label + colour tone) | `app/Enums/Status.php` |
| Design tokens (colours, radii, fonts, container) | `resources/css/app.css` |
| CTA link targets | `config/marketing.php` |

## Pricing

Prices live in `config/pricing.php` and are read through `App\Pricing\PricingCatalog`, never hardcoded in Blade.

- Each plan has a price per currency and billing interval, and an optional Stripe Price ID (from `.env`).
- To add a currency, add it under `currencies` and add a matching key under each plan's `prices`. The catalogue is built for one currency at a time (`PricingCatalog::fromConfig($config, 'EUR')`).
- The "2 months free" message is calculated from the prices, so it stays correct if prices change.
- Plan buttons link to `MARKETING_SIGNUP_URL?plan=<product-line>-<plan>&billing=<monthly|annual>`, ready to hand to a checkout endpoint.

## Demo content

The dashboard, license and activity-log previews use fictional data from `app/Content/DemoDashboard.php` and always show a "Demo data" / "Example" label. They must not be presented as real customer statistics.
