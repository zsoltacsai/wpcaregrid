# Pricing administration

How the owner manages prices without breaking checkout. The pricing engine itself (plans, markets, currencies, checkout resolution) is described in config/pricing.php and the README; the owner area in OWNER_ADMIN.md.

## Two kinds of pricing data

| Kind | What | Where it is edited | Where it is stored |
| --- | --- | --- | --- |
| Marketing | plan name, description, badge, button text, feature list, per locale | Pricing → Plan wording (content sections `pricing.{product}.{tier}`) | `content_entries` (CONTENT_MANAGEMENT.md) |
| Technical | product type, tier, market, currency, interval, amount, Stripe Price ID, on sale | Pricing → Prices | `price_definitions` |

Plans, markets, currencies, tax treatment and what each plan includes technically (backup and security check frequency) stay in `config/pricing.php`. Wording changes never change what a plan does.

## Technical prices

`config/pricing.php` remains the baseline. A **published** price definition replaces the config row for the same product, tier, interval and market, or adds a price for a market that had none (e.g. the EU market, in EUR). `PricingOverrides` applies them when the catalogue is built; homepage, pricing page, checkout and webhooks all read the same `PricingCatalog`.

A definition has published columns (what checkout uses) and a draft (never used). The owner:

1. edits a price and **saves a draft**: validated, stored, nothing else changes;
2. **publishes** the draft after ticking a confirmation that states how many current subscriptions use this plan;
3. or **discards** the draft.

### Validation

On saving and again on publishing:

- product type, tier and interval: the enum values only; market: a market defined in config (the URL key is validated, anything else is a 404);
- currency: one of the configured currencies **and** the market's own currency; free text and other currencies are refused;
- amount: entered in the main unit ("18990"), digits only with at most as many decimals as customers are shown (`display_decimals`; HUF: none), stored in minor units, above zero and at most 99 999 999 minor units (Stripe's limit);
- Stripe Price ID: `price_…` format; required when the price is on sale; not used (now or before) by another price;
- when Stripe is configured (`STRIPE_SECRET`), publishing an on-sale price first checks the Price ID in Stripe (`StripePriceVerifier::verify()`, read-only): it must exist, be active, recurring on the same interval, in the same currency and amount, and belong to the Stripe Product of the price's product type (`STRIPE_PRODUCT_WORDPRESS` / `STRIPE_PRODUCT_WOOCOMMERCE`). The product type comes from the price being edited, the expected Product from configuration; nothing in the form can name either. A mismatch, or a missing `STRIPE_PRODUCT_*`, blocks publishing and keeps the draft. A price taken off sale is published without a Stripe check. Without Stripe configured the price can be published, recorded as not verified.

### Effect on checkout and subscriptions

- Publishing affects **future checkouts only**. Checkout resolves the price on the server from the catalogue; amounts, currencies or Price IDs submitted by a browser are ignored (tested).
- Existing Stripe subscriptions are **not** modified: they keep billing at their Stripe price. There is no migration flow for moving existing subscriptions to a new price; that is done in Stripe, deliberately and separately.
- A Price ID a price used before (the config one or an earlier published one) is kept in `retired_stripe_price_ids` and still recognised by `PricingCatalog::priceByStripeId()`, so webhooks for existing subscriptions keep mapping to their plan, product type, interval and market. Retired IDs cannot be given to another price.
- Taking a price off sale (`active` off) stops new checkouts for it (`PriceInactive`); existing subscriptions continue.
- A published definition that no longer fits config (market removed, market currency changed, plan removed) is ignored with a warning, never half applied.
- The catalogue cache (`pricing.published`) is cleared on publish, so the next request uses the new price.

### Stripe configuration

Stripe keys and webhook secret stay in the environment; the owner area never shows, creates or rotates them. "Check prices in Stripe" runs the same check (`verify()`, including the Product) as `php artisan pricing:verify-stripe` for every price on sale and keeps only the outcome (time, number of problems, which prices); the system page shows the last result.

## Plan wording

Per plan and locale: name, description, badge (empty: no badge), button text (empty: "Choose {plan}"), features (a list; empty: the built-in list from config and the translation files). Only published wording is used; until then the built-in wording applies. Plan names also appear in the customer portal and emails (`Subscription::planName()` reads the catalogue). The pricing card lists a higher plan's features as "Everything in {lower plan}" plus what differs, compared by text, so a custom list should repeat shared lines word for word.

## Audit

`price.created`, `price.updated` (draft saved), `price.published` (amount, currency, on sale, whether Stripe verified it, whether the Price ID changed), `price.draft_discarded`, `stripe.verified`; wording changes are content events.

## Deployment

1. `php artisan migrate` (creates `price_definitions`).
2. Nothing changes until an owner publishes a price: config/pricing.php and the `STRIPE_PRICE_*` variables keep working.
3. After publishing prices, run "Check prices in Stripe" (or `php artisan pricing:verify-stripe`).
