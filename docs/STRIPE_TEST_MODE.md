# Stripe test mode

How WP CareGrid is connected to Stripe in **test mode** (a Stripe sandbox), how the configuration is checked, and how the real end-to-end purchase is tested. Live mode is a separate, later decision (`STRIPE_ALLOW_LIVE_MODE` stays `false`). Server setup in general: [DEPLOYMENT.md](DEPLOYMENT.md). No keys, signing secrets or customer data belong in this file or in the repository.

## What Stripe holds and what the application holds

- **Application** (`config/pricing.php`, owner-published prices): plans, amounts, currency, intervals, markets and the tax treatment. It is the source of truth; the browser never sends an amount, currency or Price ID.
- **Stripe**: two Products and 48 recurring Prices (6 plans × monthly/yearly × HUF/EUR/USD/GBP) that must match the catalogue exactly, the Checkout page, payment, and subscription records.
- **Webhook**: the only way a payment becomes a customer, a login and a subscription. The success page after Checkout changes nothing.

## Configuration

1. In the Stripe Dashboard switch to the test sandbox (top-left account switcher → Sandboxes, or `https://dashboard.stripe.com/test/apikeys`). Developers → API keys: put the secret key (`sk_test_…`) into `STRIPE_SECRET` and the publishable key (`pk_test_…`) into `STRIPE_KEY`. Keep `STRIPE_ALLOW_LIVE_MODE=false`; a `_live_` key is refused at checkout.
2. Reuse/create two Products, **WordPress** and **WooCommerce**, with monthly and annual recurring Prices for every plan and currency below. Each interval count is 1. Keep HUF's existing Stripe amount convention (8 990 Ft = `899000`); EUR/USD/GBP use cents/pence (e.g. €49 = `4900`). Reuse matching active TEST Prices rather than creating duplicates. Do not set tax behavior or enable Stripe Tax in this milestone.
3. Put Product IDs into `STRIPE_PRODUCT_WORDPRESS` / `STRIPE_PRODUCT_WOOCOMMERCE` and each Price ID into its generated variable `STRIPE_PRICE_<PRODUCT>_<PLAN>_<INTERVAL>_<CURRENCY>` (for example `STRIPE_PRICE_WORDPRESS_BASIC_MONTHLY_EUR`).
4. Add a webhook endpoint (Developers → Webhooks) at `<APP_URL>/stripe/webhook` with exactly the nine events below. Put its signing secret (`whsec_…`) into `STRIPE_WEBHOOK_SECRET`.
5. `php artisan config:cache` (if caches are used), then `php artisan pricing:verify-stripe`. All 48 rows must say `ok`: the Price exists, is active in TEST mode, recurring on the right interval, in the configured currency and amount, and belongs to the configured Product. Any mismatch: **stop** and fix Stripe (create a new Price); never change the catalogue to make the check pass. The same check (`StripePriceVerifier::verify()`) runs when an owner publishes a price and for "Check prices in Stripe" in the Owner area.
6. `php artisan system:check`: Stripe and Mail must be OK.

### Environment variables

| Variable | Value |
| --- | --- |
| `STRIPE_SECRET` | `sk_test_…` (never committed, never logged) |
| `STRIPE_KEY` | `pk_test_…` |
| `STRIPE_WEBHOOK_SECRET` | `whsec_…` of the endpoint that serves this `APP_URL` |
| `STRIPE_ALLOW_LIVE_MODE` | `false` |
| `STRIPE_PRODUCT_WORDPRESS`, `STRIPE_PRODUCT_WOOCOMMERCE` | `prod_…` |
| `STRIPE_PRICE_<PRODUCT>_<PLAN>_<INTERVAL>_<CURRENCY>` (48) | `price_…`; currency is HUF, EUR, USD, or GBP |
| `APP_URL` | the public `https://` address: Stripe redirects and email links use it |
| `MAIL_*` | a real mailer; the activation email is the customer's only way in |

### Price mapping

| Variable | Lookup key | Amount (HUF minor units) | Interval |
| --- | --- | ---: | --- |
| `STRIPE_PRICE_WORDPRESS_BASIC_MONTHLY_HUF` | `caregrid_wordpress_basic_monthly_huf` | 899000 | month |
| `STRIPE_PRICE_WORDPRESS_BASIC_ANNUAL_HUF` | `caregrid_wordpress_basic_annual_huf` | 8990000 | year |
| `STRIPE_PRICE_WORDPRESS_BUSINESS_MONTHLY_HUF` | `caregrid_wordpress_business_monthly_huf` | 1899000 | month |
| `STRIPE_PRICE_WORDPRESS_BUSINESS_ANNUAL_HUF` | `caregrid_wordpress_business_annual_huf` | 18990000 | year |
| `STRIPE_PRICE_WORDPRESS_SECURE_MONTHLY_HUF` | `caregrid_wordpress_secure_monthly_huf` | 2899000 | month |
| `STRIPE_PRICE_WORDPRESS_SECURE_ANNUAL_HUF` | `caregrid_wordpress_secure_annual_huf` | 28990000 | year |
| `STRIPE_PRICE_WOOCOMMERCE_BASIC_MONTHLY_HUF` | `caregrid_woocommerce_basic_monthly_huf` | 1490000 | month |
| `STRIPE_PRICE_WOOCOMMERCE_BASIC_ANNUAL_HUF` | `caregrid_woocommerce_basic_annual_huf` | 14900000 | year |
| `STRIPE_PRICE_WOOCOMMERCE_BUSINESS_MONTHLY_HUF` | `caregrid_woocommerce_business_monthly_huf` | 2490000 | month |
| `STRIPE_PRICE_WOOCOMMERCE_BUSINESS_ANNUAL_HUF` | `caregrid_woocommerce_business_annual_huf` | 24900000 | year |
| `STRIPE_PRICE_WOOCOMMERCE_SECURE_MONTHLY_HUF` | `caregrid_woocommerce_secure_monthly_huf` | 3990000 | month |
| `STRIPE_PRICE_WOOCOMMERCE_SECURE_ANNUAL_HUF` | `caregrid_woocommerce_secure_annual_huf` | 39900000 | year |

## Fixed price matrix

| Plan | HUF monthly / annual | EUR monthly / annual | USD monthly / annual | GBP monthly / annual |
| --- | --- | --- | --- | --- |
| WordPress Basic | 8 990 / 89 900 Ft | €49 / €490 | $49 / $490 | £49 / £490 |
| WordPress Business | 18 990 / 189 900 Ft | €89 / €890 | $89 / $890 | £89 / £890 |
| WordPress Secure | 28 990 / 289 900 Ft | €149 / €1 490 | $149 / $1 490 | £149 / £1 490 |
| WooCommerce Basic | 14 900 / 149 000 Ft | €69 / €690 | $69 / $690 | £69 / £690 |
| WooCommerce Business | 24 900 / 249 000 Ft | €99 / €990 | $99 / $990 | £99 / £990 |
| WooCommerce Secure | 39 900 / 399 000 Ft | €159 / €1 590 | $159 / $1 590 | £159 / £1 590 |

Every annual amount is exactly 10× its monthly amount (“2 months free”); no FX conversion is used. `config/pricing.php` generates the 48 env/config keys from this fixed matrix. The Owner Pricing & Currencies screen displays each row and verification state without exposing Stripe credentials. HU billing is server-enforced as HUF; all non-HU billing may choose EUR/USD/GBP. Non-HU tax treatment remains undetermined and VAT/tax logic is outside this milestone.

### Webhook events

`checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, `invoice.payment_failed`.

Every event is signature-checked (`STRIPE_WEBHOOK_SECRET`), recorded by event ID in the same transaction as its effects (a redelivery is skipped; a failure rolls everything back and Stripe retries), and handled without assuming delivery order. Emails from webhooks are queued after the transaction commits.

### Tax (test mode)

Hungarian business rule: the listed HUF price is the final customer price, no VAT added. Stripe Tax is not activated, there are no Tax Rates, the Prices have no tax behaviour and Checkout runs without automatic tax, so the customer pays exactly the listed amount (`amount_tax` = 0). Every checkout records the configured tax-treatment label. International tax treatment remains `undetermined`; this milestone does not calculate VAT or tax. Invoicing (Billingo / Számlázz.hu) is separate.

### Promotion codes

Coupons are created in the owner area (Owner → Coupons, [COUPONS.md](COUPONS.md)), which creates the Stripe coupon and promotion code through the API and the local row together; creating them by hand is no longer needed. A code works only when it exists in **both** places: a Stripe Coupon + Promotion Code (Stripe applies the discount), and a local `promotions` row with the same code, its `stripe_promotion_code_id` and the local rules (product types, plans, intervals, markets, dates, `max_redemptions`). The server checks the local rules before creating the Checkout Session; Stripe's own code field is off, so the browser cannot bypass them. `times_redeemed` counts completed, paid checkouts.

## Testing a real purchase locally

Stripe must reach the webhook over HTTPS, so a local test needs a temporary public address:

1. Start Mailpit (`mailpit --smtp 127.0.0.1:1025 --listen 127.0.0.1:8025`) and set `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`.
2. Start a temporary tunnel, e.g. `cloudflared tunnel --url http://127.0.0.1:8000`; set `APP_URL` to its `https://…` address and `TRUSTED_PROXIES=127.0.0.1`.
3. `php artisan serve --port=8000`, `php artisan queue:work` (activation and payment emails are queued), `npm run build`.
4. Create the webhook endpoint for `<tunnel>/stripe/webhook` (step 4 above) and set its secret.
5. Run the browser E2E (Playwright, real Stripe Checkout, Stripe's published test cards only):

   ```
   E2E_BASE_URL=https://<tunnel> MAILPIT_URL=http://127.0.0.1:8025 npx playwright test
   ```

   `tests/e2e/stripe-purchase.spec.ts` covers: pricing → plan → checkout form → Stripe Checkout (`4242 4242 4242 4242`) → success page → webhook → customer, login and subscription → activation email with an `APP_URL` link → password set → dashboard → subscription page → log out / log in → first website in onboarding; the promotion code (unknown and not-applicable codes refused locally, `PILOT10` charged 10 % off by Stripe); a declined card (`4000 0000 0000 0002`: nothing created, no email); an abandoned checkout; a refused international billing country; WooCommerce Business annual. The delayed-webhook test runs only with `E2E_WEBHOOK_PAUSED=1` while the endpoint is disabled in Stripe; deliver the missed events afterwards (Dashboard → event → Resend).
6. Afterwards: stop the tunnel and disable or delete its endpoint in Stripe (otherwise Stripe keeps retrying a dead URL and emails the account owner), and restore `APP_URL`.

It is not part of CI: it needs Stripe keys and a public address.

### Other scenarios (Stripe Dashboard or API, test mode)

- **Failed payment**: attach Stripe's `pm_card_chargeCustomerFail` test method as the customer's default and let an invoice be charged (e.g. a plan change billed immediately). Expected: `invoice.payment_failed`, local status `past_due`, "Your payment did not go through" email, dashboard shows the payment problem and pauses onboarding.
- **Cancellation**: set "cancel at period end" (local status stays active, "Does not renew"), then cancel immediately (local `canceled`, "Your subscription has been canceled" email, "This subscription has ended").
- **Replay**: Dashboard → Developers → Events → an event → Resend. Expected: 2xx, no new rows, no new email.

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| Checkout says it is unavailable | `STRIPE_SECRET` empty, a live key without `STRIPE_ALLOW_LIVE_MODE=true`, or the Price ID missing (logged with the Stripe error code, never the key) |
| `pricing:verify-stripe` shows `amount …`, `currency …`, `wrong interval`, `inactive in Stripe` | the Stripe Price differs from the catalogue: create a correct Price, update the variable |
| `product prod_…` / `product not configured` | the Price belongs to another Product, or `STRIPE_PRODUCT_*` is not set |
| Paid in Stripe but no account | the webhook did not arrive: endpoint URL ≠ `APP_URL`, wrong `STRIPE_WEBHOOK_SECRET` (endpoint answers 400), or the endpoint is disabled. Dashboard → Webhooks → the endpoint shows each attempt; fix and press Resend |
| Webhook answers 500 "not configured" | `STRIPE_WEBHOOK_SECRET` is empty |
| No activation email | no queue worker running, or the mailer is `log` |
| Activation link points to localhost | `APP_URL` is not the public address (or the config cache is stale) |

## Replacing the webhook secret

Roll it in the Dashboard (Webhooks → endpoint → Signing secret → Roll; Stripe can keep the old secret valid for a while) or create a new endpoint. Put the new `whsec_…` into `STRIPE_WEBHOOK_SECRET`, run `php artisan config:cache` if caches are used, and check: a resent event is answered 2xx, a request without a valid signature 400. Never put a signing secret into the repository, logs or tickets.

## Coupons, the 100% case and recovery offers (2026-10-05)

Validated against real Stripe test mode (API level: `StripePromotionGateway`, `StripeCheckoutGateway` and `StripeOfferPaymentGateway` against the live test API; browser level: `tests/e2e/owner-commercial.spec.ts` through a temporary Cloudflare tunnel, a registered test webhook endpoint with the same nine events, Mailpit). No new webhook events are needed: offers use `checkout.session.completed` and `async_payment_succeeded`.

- Coupons created by the owner area exist in Stripe as the requested percentage and duration, with an active promotion code; deactivating and re-activating in the owner area switches the Stripe promotion code; a duplicate code is refused before a second Stripe call.
- 10% once on Business monthly: Checkout total 17,091 Ft (discount 1,899 Ft); paid with the test card; the signed webhook created the customer once and the coupon's use count became 1.
- 100% **forever**: Checkout shows "Start for free", the email only, "100% off", "Total due today HUF 0.00" and **no card fields**; completed without a payment method; the signed webhook provisioned exactly one customer, one login and one subscription (one activation email, no "payment received" email for the $0 invoice); the coupon's use count became 1.
- 100% **once**: total 0 today, payment method still collected (checked at API level). 12.5% for 3 months: total 87.5%.
- Recovery offer: a one-time `payment` Checkout Session of 45,000 Ft built from the stored offer (inline line item, no Stripe Price); paid with the test card in English and in Hungarian; the offer stayed *accepted* until the webhook arrived and then became *paid* once; emails (offer sent, payment received) in the customer's language; accepting again reused the open session; cancelling expired it in Stripe.
- Live mode stays refused (`STRIPE_ALLOW_LIVE_MODE=false`); the same key checks apply to the new gateways.

## Last validation

2026-10-04, local installation through a temporary Cloudflare tunnel, Stripe test sandbox, Mailpit: see docs/PROJECT_STATUS.md ("Stripe test mode") for the results.
