# Language and currency (display)

How the public site, the customer login and the customer portal are shown in English or Hungarian, and how a currency is displayed. **Presentation only:** the language and the displayed currency never decide what a customer is charged. Public *content* (owner-editable sections) is a separate concept: [CONTENT_MANAGEMENT.md](CONTENT_MANAGEMENT.md).

## Supported languages

`config/localization.php`: `hu` (Magyar) and `en` (English), in the order the selector shows them. Only these codes are ever accepted. `APP_LOCALE` is the default for a visitor who has not chosen (`localization.default`, read once from `APP_LOCALE`: Laravel rewrites `config('app.locale')` while a request runs, which must never leak into the next request of a worker).

| Where | Languages |
| --- | --- |
| Public site: home, pricing, checkout, order pages | Magyar, English |
| Customer login: log in, activation link, forgot / reset password, logout, errors (validation, authentication, 404, 403, 419, 429) | Magyar, English |
| Customer portal: dashboard, websites and onboarding, connection, backups and restore, WordPress admin access, security, subscription, billing, profile | Magyar, English |
| Owner area, operator console, staff invitations | English only, always (`english_only` paths) |
| Emails | Recovery offers: English and Hungarian (the customer's saved language). Activation, welcome, payment, subscription: English only, see below |

## How a language is chosen

For each web request (`App\Http\Middleware\SetLocale`, `App\Localization\Locales::resolve`):

1. owner / operator / staff paths: English;
2. the choice remembered on this device, the cookie `caregrid_locale` (not sensitive; one year; encrypted; validated against the supported list). It also carries a choice made **before** signing in into the customer's session;
3. a signed-in customer's saved preference, `users.locale` (it follows them to other devices when no choice was made on the device);
4. the default.

A tampered cookie, a saved value that is not supported or a language in the address (`?lang=hu`) is ignored. Nothing in a request can name a user: the selector posts one field, a supported code, to `POST /locale` (CSRF-protected, rate-limited). An unsupported code is refused with a validation error and changes nothing; the error never echoes the value.

The selector (header and mobile menu; the customer sidebar) is a server-side form: it works without JavaScript. The language is saved on the signed-in customer's **own** login (`users.locale`, nullable, a small migration) and the cookie is set; the customer's choice changes nobody else. When the cookie and the saved preference differ for a signed-in customer, the choice made on this device wins and is saved. The owner and operator logins never store a language.

After the switch the visitor returns to the page they were on (only an address on this site). On pages with a form (login, activation, reset, checkout, profile, add website) the selector carries what was typed into the next page (JavaScript), except passwords, file fields and tokens; without JavaScript the page reloads empty.

`<html lang>` follows the language, `Content-Language` is sent, titles and descriptions come from the content system. There is one address per page (the language is not in the URL), so there is no `hreflang`; the canonical address is the page itself. Unknown addresses (404) take the language from the cookie too (`bootstrap/app.php`).

## Currency

`App\Pricing\DisplayCurrency`, `PricingCatalog::sellableCurrencies()`:

- **Hungarian: always HUF.** Nothing else can be selected, whatever prices exist.
- **English: the currencies that have real prices** in the catalogue (config/pricing.php, owner-published prices). A market without prices contributes nothing. **Today only HUF has prices** (the EU, GB and international markets have none), so English shows HUF as a plain label with a note, and no dropdown. A dropdown appears by itself the day a second market gets prices; nothing needs to change in the code.
- There are **no exchange rates, no conversion, no IP-based choice** and no invented amounts. Choosing English returns the display to the default currency of the display market; choosing a currency is stored in the session only.
- The displayed currency selects which market's price list the pricing and checkout pages **show**. It is never read when ordering: `StartCheckout` resolves the price on the server from the **billing country** (country → market → price), the Stripe Price ID always comes from the catalogue, and browser-submitted amounts, currencies, Price IDs and markets are ignored (CheckoutTest, CustomerLocalizationTest).
- Existing subscriptions are untouched: their amount and currency are stored; only the number format changes with the language (18,990 Ft / 18 990 Ft).

## Translation files

Laravel's own structure (no package): `lang/en`, `lang/hu`.

| Files | What |
| --- | --- |
| `home`, `pricing`, `checkout`, `dashboard`, `status`, `countries` | public site, pricing table, order pages, demo dashboard, status labels, billing countries |
| `account`, `backups`, `admin_access`, `security`, `site_health`, `connector`, `offers` | customer login and portal (`offers`: recovery offers and their emails) |
| `validation`, `auth`, `passwords`, `pagination`, `hu.json` | framework messages: validation errors with Hungarian field names, authentication, password reset, the default error pages |

`HungarianContentTest` fails on a missing key, an untranslated or empty text, or a lost `:placeholder`; `CustomerLocalizationTest` renders every portal page in both languages and fails when an English sentence of `lang/en` is shown on a Hungarian page. Brand and product names (WP CareGrid, WordPress, WooCommerce, Stripe, PHP) and the plan names Basic, Business, Secure are not translated.

## Emails

The **recovery offer** emails ([RECOVERY_OFFERS.md](RECOVERY_OFFERS.md): offer sent, payment received) are sent in the customer's saved language (`Customer::mailLocale()`: `users.locale`, else the default), from `lang/{en,hu}/offers.php`. They are sent from the owner area and from a webhook for an offer that already has a customer, so a language is known.

The older customer emails (activation, welcome, payment, subscription) are **English only**: they use `emails.php`, which has no Hungarian file. They are sent by Stripe webhooks, where no request and so no language exists; sending them in the customer's language needs the language stored at checkout (`checkout_intents`, `customers`) and `->locale()` on the mailables. That is a separate, small milestone; the login and activation pages themselves are fully bilingual.

## Adding a language or a currency

- Language: add it to `config/localization.php` and `config/content.php`, add `lang/<code>/` with the files above (the tests list what is missing), add `lang/<code>.json`.
- Currency: add prices for a market in config/pricing.php with a Stripe Price per price (STRIPE_TEST_MODE.md); the selector appears for English. Hungarian stays HUF.
- An owner or operator language is a different task: those areas are English by design.

## Tests

`tests/Feature/CustomerLocalizationTest.php` (35), `HungarianContentTest`, `tests/e2e/localization.spec.ts` (Playwright, local app, no Stripe: `E2E_BASE_URL=http://127.0.0.1:8000 E2E_CUSTOMER_EMAIL=… E2E_CUSTOMER_PASSWORD=… npx playwright test localization`; the customer is a throw-away account of the local database).
