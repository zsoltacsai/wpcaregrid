# Stripe test mode

How WP CareGrid is connected to Stripe in **test mode** (a Stripe sandbox), how the configuration is checked, and how the real end-to-end purchase is tested. Live mode is a separate, later decision (`STRIPE_ALLOW_LIVE_MODE` stays `false`). Server setup in general: [DEPLOYMENT.md](DEPLOYMENT.md). No keys, signing secrets or customer data belong in this file or in the repository.

## What Stripe holds and what the application holds

- **Application** (`config/pricing.php`, owner-published prices): plans, amounts, currency, intervals, markets and the tax treatment. It is the source of truth; the browser never sends an amount, currency or Price ID.
- **Stripe**: two Products and twelve recurring HUF Prices that must match the catalogue exactly, the Checkout page, payment, the subscription and its invoices.
- **Webhook**: the only way a payment becomes a customer, a login and a subscription. The success page after Checkout changes nothing.

## Configuration

1. In the Stripe Dashboard switch to the test sandbox (top-left account switcher → Sandboxes, or `https://dashboard.stripe.com/test/apikeys`). Developers → API keys: put the secret key (`sk_test_…`) into `STRIPE_SECRET` and the publishable key (`pk_test_…`) into `STRIPE_KEY`. Keep `STRIPE_ALLOW_LIVE_MODE=false`; a `_live_` key is refused at checkout.
2. Create two Products, **WordPress** and **WooCommerce**, and under them the twelve Prices below: currency HUF, recurring, interval count 1, amounts exactly as listed (Stripe's HUF amounts have two decimals: 8 990 Ft = `899000`). Do not set a tax behaviour. Reuse existing matching Products and Prices instead of creating duplicates; give each Price the lookup key shown so it can be found again.
3. Put the Product IDs into `STRIPE_PRODUCT_WORDPRESS` / `STRIPE_PRODUCT_WOOCOMMERCE` and each Price ID into its variable.
4. Add a webhook endpoint (Developers → Webhooks) at `<APP_URL>/stripe/webhook` with exactly the nine events below. Put its signing secret (`whsec_…`) into `STRIPE_WEBHOOK_SECRET`.
5. `php artisan config:cache` (if caches are used), then `php artisan pricing:verify-stripe`. Every row must say `ok`: the Price exists, is active, recurring on the right interval, in HUF, with the catalogue amount, and belongs to the configured Product. Any mismatch: **stop** and fix Stripe (create a new Price); never change the catalogue to make the check pass. The same check (`StripePriceVerifier::verify()`) runs when an owner publishes a price and for "Check prices in Stripe" in the owner area.
6. `php artisan system:check`: Stripe and Mail must be OK.

### Environment variables

| Variable | Value |
| --- | --- |
| `STRIPE_SECRET` | `sk_test_…` (never committed, never logged) |
| `STRIPE_KEY` | `pk_test_…` |
| `STRIPE_WEBHOOK_SECRET` | `whsec_…` of the endpoint that serves this `APP_URL` |
| `STRIPE_ALLOW_LIVE_MODE` | `false` |
| `STRIPE_PRODUCT_WORDPRESS`, `STRIPE_PRODUCT_WOOCOMMERCE` | `prod_…` |
| `STRIPE_PRICE_<PRODUCT>_<PLAN>_<INTERVAL>_HUF` (12) | `price_…` |
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

Annual Prices are explicit (10 × monthly, "2 months free"); nothing calculates them. Only the Hungarian market has Prices: EU, GB and international checkouts are refused with "Pricing for your billing country is not available yet." Do not create EUR/USD/GBP Prices until those amounts are decided.

### Webhook events

`checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, `invoice.payment_failed`.

Every event is signature-checked (`STRIPE_WEBHOOK_SECRET`), recorded by event ID in the same transaction as its effects (a redelivery is skipped; a failure rolls everything back and Stripe retries), and handled without assuming delivery order. Emails from webhooks are queued after the transaction commits.

### Tax (test mode)

Hungarian business rule: the listed price is the final customer price, no VAT added. In Stripe: Stripe Tax is not activated, there are no Tax Rates, the Prices have no tax behaviour and Checkout runs without automatic tax, so the customer pays exactly the listed amount (`amount_tax` = 0). Every checkout records `tax_treatment = final_price_no_vat_added`. Nothing beyond this Hungarian scenario is claimed; other markets have no prices and their tax treatment is `undetermined`. Invoicing (Billingo / Számlázz.hu) is separate and not part of this setup.

### Promotion codes

A code works only when it exists in **both** places: a Stripe Coupon + Promotion Code (Stripe applies the discount), and a local `promotions` row with the same code, its `stripe_promotion_code_id` and the local rules (product types, plans, intervals, markets, dates, `max_redemptions`). The server checks the local rules before creating the Checkout Session; Stripe's own code field is off, so the browser cannot bypass them. `times_redeemed` counts completed, paid checkouts.

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

## Last validation

2026-10-04, local installation through a temporary Cloudflare tunnel, Stripe test sandbox, Mailpit: see docs/PROJECT_STATUS.md ("Stripe test mode") for the results.
