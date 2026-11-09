# Stripe Catalogue Manager

Owner → Pricing → Stripe Catalogue. It matches the WP CareGrid price catalogue (`PricingCatalog`, 48 prices on sale: 2 product types × 3 tiers × monthly/annual × HUF, EUR, USD, GBP) with the Products and Prices of the Stripe account, links what already exists, and creates what is missing **after the Owner approves a stored preview**.

It is not part of checkout. It never touches customers, subscriptions, invoices, coupons or the webhook.

## Safety rules (all enforced in code and tested)

| Rule | Where |
| --- | --- |
| The Owner-managed (encrypted) Stripe secret is used; it is never copied into `.env`, configuration, plans, audit, logs or any response. | `CatalogueGuard::resolve()` → `IntegrationCredentials::resolve('stripe_secret')` |
| The mode comes from the key prefix (`sk_/rk_` + `test_/live_`) and must equal `STRIPE_MODE`. Unknown prefix: refused. | `StripeMode::fromKey`, `CatalogueGuard` |
| A staging stack (`staging_active` profile, `APP_ENV=staging` or `CAREGRID_DEPLOYMENT_ENV=staging*`) can only use a TEST key. A LIVE key is refused even to read, and no Stripe client is built. | `CatalogueGuard::isStaging()` |
| LIVE outside staging needs `STRIPE_ALLOW_LIVE_MODE=true`; **changing** LIVE additionally needs `STRIPE_CATALOGUE_LIVE_APPLY_APPROVED=true` (a separate production approval) and typing `APPLY LIVE`. | `CatalogueGuard::assertCanWrite()` |
| Nothing is **created** unless `STRIPE_CATALOGUE_APPLY_ENABLED=true` (default off). Loading the catalogue is a pure dry run, and linking existing objects is local only and does not need the switch. | `CatalogueGuard::assertCanWrite()` / `assertCanLink()` |
| Every object returned by Stripe must belong to the key's mode (`livemode`); an object of the other mode aborts the run. | `StripeApiCatalogueGateway::assertMode()` |
| Existing Stripe Products and Prices are only read. The gateway has no update/archive/delete method (a test enforces the method list). | `StripeCatalogueGateway` |
| Subscriptions are never read or written. An existing catalogue Price ID is never replaced; a different one is a conflict. | `CatalogueMatcher`, `CatalogueManager::linkPrice()` |
| Unclear is never chosen: two fitting Prices, a Price that differs, a Price shared by two catalogue entries, a Product that cannot be found are `ambiguous` / `conflict` / `blocked` and have no action. | `CatalogueMatcher` |
| The browser sends only an operation and the item ids it approves out of the plan stored on the server. Amount, currency, Price ID and mode are never read from a request. | `StripeCatalogueController::apply()` |
| Stripe error text is never kept or shown (it can quote a key); only a fixed code. | `CatalogueGatewayException` |

## Matching

A Stripe Price matches a catalogue price only when **all** hold and exactly one qualifies:

- it belongs to the Stripe Product of the price's product type;
- same currency; same amount in minor units (HUF 8 990 Ft is `899000`; Stripe counts HUF in hundredths but accepts whole forints only, so an amount that is not a multiple of 100 is a conflict, not a create);
- a plain licensed per-unit recurring Price, interval `month` (monthly) or `year` (annual), interval count 1;
- active (archived Prices are ignored).

The stable key in the Price metadata (`wpcg_price_key`, e.g. `wordpress.basic.monthly.hu`) is decided first. If a Price carries our key but differs, that is a **conflict** (never silently re-matched). Without our key, a Price in the same Product, currency and period but with another amount is also a conflict (no second active Price in the same slot is proposed). Name similarity is never used for prices; for Products it is only a hint ("similar existing Products") and the Owner must link such a Product explicitly.

A Product is known by configuration (`STRIPE_PRODUCT_WORDPRESS` / `STRIPE_PRODUCT_WOOCOMMERCE`), an earlier link (`stripe_catalogue_products`, per mode) or the metadata `wpcg_product_type`.

## Two separate permissions

| | A. Link existing objects | B. Create Stripe objects |
| --- | --- | --- |
| What | Link an existing Stripe Product to a product type, an existing Stripe Price to a catalogue price | Create a Product or Price in Stripe |
| Effect | **Local mapping only**: nothing is created or changed in Stripe, no subscription, customer or invoice is touched | Writes to Stripe (create only; never update, archive or delete) |
| Needs `STRIPE_CATALOGUE_APPLY_ENABLED` | **No** | Yes (default off) |
| Staging | TEST key only (a LIVE key is refused before any client is built) | TEST key only |
| LIVE (never on staging) | `STRIPE_ALLOW_LIVE_MODE` + `STRIPE_CATALOGUE_LIVE_APPLY_APPROVED` | same |
| Owner confirmation | tick the confirmation and, for a run, type `LINK TEST` / `LINK LIVE` | tick the confirmation and type `APPLY TEST` / `APPLY LIVE` |
| UI | `Link existing Product`, `Link existing Price` | `Create Product`, `Create Price` (disabled while creation is off) |

Linking is done in two ways: **per object** (a Product from the "similar Products" hints, a Price chosen among the candidates of an ambiguous price) or **in bulk** by approving the `link_price` items of a previewed plan. A run is link-only exactly when every approved item is a link; one create item makes the whole run a creation run with the stricter rules.

Server-side checks for linking a Price (all from a fresh read of Stripe, none from the browser): the key must be a catalogue price on sale; the Stripe Price must exist in this account and mode, be active and a plain recurring Price of the **linked Product** with exactly the catalogue's currency, amount and interval (1); it must not be tagged (`wpcg_price_key`) for another catalogue price and not be linked to one already; the catalogue price must have **no Price ID yet and no pending draft** (a published ID is never replaced). Then the existing price editor publishes it (Stripe verification, version, audit). Linking a Product needs it to be an active Product of the account and the product type not to be set by configuration or an earlier link. Parallel linking is serialised by one lock. Audit: `stripe_catalogue.product_linked`, `price_linked`, `apply_refused` (with a fixed code).

After linking the Products, start a new dry run: all 48 on-sale prices are classified again as exact, missing, ambiguous, conflict or blocked; missing prices are never created unless creation was switched on separately and approved.

## Dry run → approval → execution

1. **Load Stripe catalogue (dry run)** reads Products and Prices (paged, rate limits retried with growing pauses) and stores a plan (`stripe_catalogue_operations`, valid 30 minutes). It writes nothing else.
2. The preview lists every price as `exact`, `missing`, `ambiguous`, `conflict` (or `blocked`/`skipped`), the Stripe IDs, and the proposed items: create Product, link Product, create Price, link Price.
3. To approve, the Owner selects items, ticks the confirmation and types `APPLY TEST` (or `APPLY LIVE`). Items that depend on others (a Price on a Product to create) must be selected together.
4. The run takes one lock, claims the operation (a preview can be run once), **re-reads Stripe**, and executes only approved items that the fresh state still calls for. An item that is no longer needed (someone created it meanwhile) is skipped, never repeated.
5. Each created Price is checked against what was asked for, then linked through the normal price editor (draft → Stripe check → publish → version → audit), so checkout starts to use it. A Price ID is only linked when the catalogue has none; an unpublished draft is never overwritten.
6. The result lists every item (`done`, `failed`, `skipped`, `not_run`) with its code and Stripe ID, and the audit log records `stripe_catalogue.previewed`, `apply_started`, `object_created`, `product_linked`, `price_linked`, `applied`, `apply_refused`, `preview_failed`.

Creating Stripe objects does not change existing subscriptions, and published prices affect future checkouts only (see PRICING_ADMIN.md).

## Idempotency and recovery

- Every create carries a deterministic Stripe idempotency key (`wpcg-cat-<mode>-price-<key>-<currency>-<amount>-<interval>`, `…-product-<type>`), so a repeated request replays the first result instead of creating another object.
- Every created object carries our stable metadata. Before anything is created, a run reads Stripe; an object that already carries the key is **adopted** (linked), not created again. So if Stripe succeeded and the local database failed (`created_but_not_linked`), the next preview shows `exact` via metadata with a `link` action only.
- A failure of one item (`invalid_request`) does not stop the others. A failure that would repeat for every request (`authentication`, `permission`, `rate_limited`, `api_connection`, `mode_mismatch`) stops the run and marks the rest `not_run`. Run a new preview to continue; finished work is not repeated.
- A run interrupted in the middle (process killed) leaves its operation `applying`; it is never resumed. A new preview adopts whatever was created.

## Configuration

| Variable | Default | Meaning |
| --- | --- | --- |
| `STRIPE_CATALOGUE_APPLY_ENABLED` | `false` | Allow **creating** Stripe Products and Prices. Off: dry run and linking of existing objects only. |
| `STRIPE_CATALOGUE_LIVE_APPLY_APPROVED` | `false` | Separate approval to change the LIVE catalogue (never effective on staging). |

The Stripe secret key itself is not an environment variable of this feature: it is the Owner-managed credential (Owner → Integrations).

## Release and rollout

- Merge needs the V3 review policy (`RELEASE_REVIEW_POLICY.md`).
- Staging: deploy normally. The Stripe Sandbox is **read only**: existing Products and Prices can be linked without any switch; creating objects needs `STRIPE_CATALOGUE_APPLY_ENABLED=true`, only after a separate Owner approval.
- Production: this feature is not enabled there by this change; LIVE needs its own Owner approval.
- `php artisan migrate` creates `stripe_catalogue_products` and `stripe_catalogue_operations` (additive).
