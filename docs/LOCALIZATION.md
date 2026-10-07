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

`App\Pricing\DisplayCurrency`, `CurrencySuggestion`, and `PricingCatalog`:

- Public display offers the fixed HUF, EUR, USD, and GBP catalogues in both UI languages. There are no exchange rates or IP-based currency choices. Each price is an integer amount in Stripe-compatible minor units; annual prices are explicitly fixed at 10 × monthly.
- Initial browser-language suggestions are hints: `hu*` → HUF, `en-US` → USD, `en-GB` → GBP, recognized Euro/EU locales → EUR, otherwise EUR. Language and currency preferences remain independent; a manual currency choice persists in the session.
- Billing country is authoritative at checkout. HU requires HUF; every non-HU country may choose EUR, USD, or GBP. The server resolves the Stripe Price ID from the product, plan, interval, billing country, and selected currency. Amounts, Price IDs, and market values from the browser are not trusted.
- Tax/VAT behavior for non-HU markets remains undetermined and is not implemented by currency selection. No tax calculation is enabled here.
- Existing subscriptions show the amount, currency, and Stripe Price stored for that subscription; changing current catalogue prices or display preferences does not alter them.

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
