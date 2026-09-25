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

## Languages

The homepage is English. All wording lives in translation files, so a language is added without touching components:

| File | Contents |
| --- | --- |
| `lang/en/home.php` | Homepage copy, SEO title and description, navigation, FAQ |
| `lang/en/pricing.php` | Plan names, descriptions and features; pricing table text |
| `lang/en/dashboard.php` | Dashboard preview text (reused by the future customer dashboard) |
| `lang/en/status.php` | Status labels (Healthy, Attention, Critical…) |

To add Hungarian, copy `lang/en` to `lang/hu`, translate the values and set the locale (for example per route prefix). Page structure (order, icons, links) stays in `app/Content/HomepageContent.php`; demo data in `app/Content/DemoDashboard.php` holds only keys and numbers. A test fails if a translation key ever shows up as text on the page.

## Pricing

Prices live in `config/pricing.php` and are read through `App\Pricing\PricingCatalog`, never hardcoded in Blade.

- Each plan has a list of price sets: `currency`, optional `locale`, and `monthly` / `annual` entries with `amount` and `stripe_price_id` (from `.env`).
- HUF prices are final customer prices; no VAT is added. They display as "8,990 Ft" in English and "8 990 Ft" in Hungarian (`currencies.*.formats`).
- EUR, USD and GBP have display formats but no prices; there are no converted amounts. To offer one, add a price set per plan and map a locale to it in `locale_currencies`. Asking for a currency without prices throws rather than guessing.
- The catalogue is built per request for the current locale (`PricingCatalog::fromConfig($config, currency, locale)`).
- The "2 months free" message is calculated from the prices, so it stays correct if prices change.
- Plan buttons link to `MARKETING_SIGNUP_URL?plan=<product-line>-<plan>&billing=<monthly|annual>`, ready to hand to a checkout endpoint.

## Demo content

The dashboard, license and activity-log previews use fictional data from `app/Content/DemoDashboard.php` and always show a "Demo data" / "Example" label. They must not be presented as real customer statistics.
