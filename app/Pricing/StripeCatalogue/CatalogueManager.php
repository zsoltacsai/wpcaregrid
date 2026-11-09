<?php

namespace App\Pricing\StripeCatalogue;

use App\Models\PriceDefinition;
use App\Models\StripeCatalogueOperation;
use App\Models\StripeCatalogueProduct;
use App\Models\User;
use App\Owner\AdminAudit;
use App\Pricing\PriceEditor;
use App\Pricing\PriceKey;
use App\Pricing\PricingCatalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stripe Catalogue Manager (docs/STRIPE_CATALOGUE.md).
 *
 * preview() is a dry run: it reads the Stripe catalogue (never writes), stores the plan and nothing else.
 * apply() runs only items the Owner approved out of that stored plan, and only when the situation still
 * calls for them; it creates Products and Prices (never updates or deletes one), then links the Stripe IDs
 * into the catalogue through the normal price editor (draft, Stripe check, publish, audit).
 *
 * Duplicates are prevented three ways: every create carries a deterministic Stripe idempotency key; every
 * run starts by re-reading Stripe, where an object carrying our stable key (wpcg_price_key,
 * wpcg_product_type) is adopted instead of created again, whatever happened to the local database after a
 * previous run; and one run at a time holds a lock.
 */
final class CatalogueManager
{
    private const MANAGED_BY = 'wp-caregrid-catalogue-manager';

    private const PRODUCT_NAMES = [
        'wordpress' => 'WP CareGrid WordPress maintenance',
        'woocommerce' => 'WP CareGrid WooCommerce maintenance',
    ];

    public function __construct(
        private readonly CatalogueGuard $guard,
        private readonly CatalogueMatcher $matcher,
        private readonly PriceEditor $editor,
    ) {}

    /**
     * Dry run. Reads Stripe, writes only the preview record.
     *
     * @throws CatalogueRefused
     * @throws CatalogueGatewayException
     */
    public function preview(User $owner): StripeCatalogueOperation
    {
        $gateway = $this->guard->gateway();
        $plan = $this->plan($gateway);
        $plan['generated_at'] = now()->toIso8601String();

        $operation = StripeCatalogueOperation::create([
            'mode' => $gateway->mode()->value,
            'status' => StripeCatalogueOperation::PREVIEWED,
            'plan' => $plan,
            'plan_hash' => hash('sha256', json_encode([$plan['mode'], $plan['actions'], array_map(static fn (array $p) => [$p['state'], $p['stripe_price_id']], $plan['prices'])], JSON_THROW_ON_ERROR)),
            'created_by' => $owner->id,
            'expires_at' => now()->addMinutes((int) config('services.stripe.catalogue.plan_ttl_minutes', 30)),
        ]);
        AdminAudit::record($owner, 'stripe_catalogue.previewed', 'stripe_catalogue', $operation->id, [
            'mode' => $plan['mode'],
            'prices' => $plan['summary']['total_prices'],
            'actions' => count($plan['actions']),
        ]);

        return $operation;
    }

    /**
     * Run the approved items of a previewed plan.
     *
     * @param  list<string>  $approved  item ids out of the stored plan
     *
     * @throws CatalogueRefused nothing was changed
     */
    public function apply(StripeCatalogueOperation $operation, array $approved, User $owner): StripeCatalogueOperation
    {
        $mode = StripeMode::from($operation->mode);
        $this->guard->assertCanWrite($mode);

        $lock = Cache::lock('stripe-catalogue-apply', 600);
        if (! $lock->get()) {
            throw new CatalogueRefused('apply_in_progress');
        }

        try {
            $stored = $this->claim($operation, $approved, $owner);
            AdminAudit::record($owner, 'stripe_catalogue.apply_started', 'stripe_catalogue', $stored->id, ['mode' => $mode->value, 'items' => count($approved)]);

            return $this->execute($stored, $approved, $owner);
        } finally {
            $lock->release();
        }
    }

    /**
     * Link an existing Stripe Product to a product type, on the Owner's explicit word. Local change only.
     *
     * @throws CatalogueRefused
     * @throws CatalogueGatewayException
     */
    public function mapProduct(string $productType, string $stripeProductId, User $owner): void
    {
        if (! array_key_exists($productType, self::PRODUCT_NAMES)) {
            throw new CatalogueRefused('product_type_unknown');
        }
        $gateway = $this->guard->gateway();
        $this->guard->assertCanWrite($gateway->mode());

        $product = collect($gateway->products())->first(fn (RemoteProduct $p) => $p->id === $stripeProductId);
        if ($product === null || ! $product->active) {
            throw new CatalogueRefused('product_not_found');
        }
        if (filled(config("pricing.stripe_products.{$productType}"))) {
            throw new CatalogueRefused('product_configured');
        }

        $this->saveProductLink($gateway->mode(), $productType, $stripeProductId, 'owner', $owner);
    }

    /** @return array<string, mixed> The matcher's plan for the current catalogue and a fresh read of Stripe. */
    private function plan(StripeCatalogueGateway $gateway): array
    {
        $plan = $this->matcher->plan(
            $gateway->mode(),
            $this->expected(),
            $this->mappedProducts($gateway->mode()),
            $gateway->products(),
            $gateway->prices(),
        );
        $plan['not_on_sale'] = count(array_filter(app(PricingCatalog::class)->allPrices(), static fn ($price) => ! $price->active));

        return $plan;
    }

    /**
     * Every price on sale in the WP CareGrid catalogue.
     *
     * @return list<array{key: string, product_type: string, tier: string, interval: string, market: string, currency: string, amount: int, current_id: ?string, draft: bool, label: string}>
     */
    private function expected(): array
    {
        $entries = [];
        foreach (app(PricingCatalog::class)->allPrices() as $price) {
            if (! $price->active) {
                continue;
            }
            $key = new PriceKey($price->productType, $price->tier, $price->interval, $price->market);
            $entries[] = [
                'key' => $key->value(),
                'product_type' => $price->productType->value,
                'tier' => $price->tier->value,
                'interval' => $price->interval->value,
                'market' => $price->market,
                'currency' => $price->money->currency->code,
                'amount' => $price->money->amount,
                'current_id' => $price->hasStripePrice() ? $price->stripePriceId : null,
                'draft' => PriceDefinition::forKey($key)->whereNotNull('draft')->exists(),
                'label' => ucfirst($price->productType->value).' '.ucfirst($price->tier->value).' · '.$price->interval->value.' · '.$price->money->currency->code,
            ];
        }
        usort($entries, static fn (array $a, array $b) => strcmp($a['key'], $b['key']));

        return $entries;
    }

    /** @return array<string, array{id: string, source: string}> */
    private function mappedProducts(StripeMode $mode): array
    {
        $mapped = [];
        foreach (StripeCatalogueProduct::query()->where('mode', $mode->value)->get() as $link) {
            $mapped[$link->product_type] = ['id' => $link->stripe_product_id, 'source' => 'link'];
        }
        foreach (array_keys(self::PRODUCT_NAMES) as $type) {
            $configured = config("pricing.stripe_products.{$type}");
            if (is_string($configured) && $configured !== '') {
                $mapped[$type] = ['id' => $configured, 'source' => 'config'];
            }
        }

        return $mapped;
    }

    /**
     * Mark the operation as applying, atomically: only a fresh, previewed plan, only its own items.
     *
     * @param  list<string>  $approved
     *
     * @throws CatalogueRefused
     */
    private function claim(StripeCatalogueOperation $operation, array $approved, User $owner): StripeCatalogueOperation
    {
        $stored = DB::transaction(function () use ($operation, $approved, $owner) {
            $locked = StripeCatalogueOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->status !== StripeCatalogueOperation::PREVIEWED) {
                throw new CatalogueRefused('operation_not_previewed');
            }
            if ($locked->expires_at->isPast()) {
                $locked->forceFill(['status' => StripeCatalogueOperation::STALE])->save();

                return $locked;
            }

            $planned = collect($locked->plan['actions'])->keyBy('id');
            $approved = array_values(array_unique($approved));
            if ($approved === []) {
                throw new CatalogueRefused('nothing_approved');
            }
            foreach ($approved as $id) {
                $item = $planned->get($id);
                if ($item === null) {
                    throw new CatalogueRefused('item_not_in_plan');
                }
                if (($item['depends_on'] ?? null) !== null && ! in_array($item['depends_on'], $approved, true)) {
                    throw new CatalogueRefused('dependency_not_approved');
                }
            }

            $locked->forceFill([
                'status' => StripeCatalogueOperation::APPLYING,
                'approved_items' => $approved,
                'approved_by' => $owner->id,
                'approved_at' => now(),
            ])->save();

            return $locked;
        });

        if ($stored->status === StripeCatalogueOperation::STALE) {
            throw new CatalogueRefused('plan_expired');
        }

        return $stored;
    }

    /** @param  list<string>  $approved */
    private function execute(StripeCatalogueOperation $operation, array $approved, User $owner): StripeCatalogueOperation
    {
        $mode = StripeMode::from($operation->mode);
        $items = [];

        try {
            $gateway = $this->guard->gateway();
            $current = $this->plan($gateway);
            $fresh = collect($current['actions'])->keyBy('id');
            $expected = collect($this->expected())->keyBy('key');
            $products = collect($current['products']);
        } catch (CatalogueRefused|CatalogueGatewayException $e) {
            return $this->finish($operation, StripeCatalogueOperation::FAILED, ['error' => $e->reason, 'items' => []], $owner);
        }

        $created = [];
        $stop = null;
        foreach ($operation->plan['actions'] as $action) {
            $id = $action['id'];
            if (! in_array($id, $approved, true)) {
                continue;
            }
            if ($stop !== null) {
                $items[$id] = ['status' => 'not_run', 'code' => $stop];
            } elseif (($action['depends_on'] ?? null) !== null && ! $this->satisfied($items[$action['depends_on']] ?? null)) {
                $items[$id] = ['status' => 'skipped', 'code' => 'dependency_failed'];
            } elseif (! $fresh->has($id)) {
                // Stripe or the catalogue changed since the preview: this item is no longer what was approved.
                $items[$id] = ['status' => 'skipped', 'code' => 'no_longer_needed'];
            } elseif (! $this->sameTarget($operation, $action, $fresh->get($id), $expected)) {
                // The approval was for a specific Stripe object / amount; something else fits now.
                $items[$id] = ['status' => 'skipped', 'code' => 'plan_changed'];
            } else {
                try {
                    $items[$id] = $this->perform($fresh->get($id), $mode, $gateway, $expected, $products, $created, $owner);
                } catch (CatalogueGatewayException $e) {
                    $items[$id] = ['status' => 'failed', 'code' => $e->reason];
                    if ($e->stopsTheRun()) {
                        $stop = 'aborted_after_'.$e->reason;
                    }
                } catch (CatalogueRefused $e) {
                    $items[$id] = ['status' => 'failed', 'code' => $e->reason] + (isset($created[$id]) ? ['stripe_id' => $created[$id]] : []);
                } catch (Throwable $e) {
                    report($e);
                    $items[$id] = ['status' => 'failed', 'code' => 'local_error'] + (isset($created[$id]) ? ['stripe_id' => $created[$id]] : []);
                }
            }
            $this->journal($operation, $items);
        }

        $failed = count(array_filter($items, static fn (array $item) => in_array($item['status'], ['failed', 'not_run'], true)));
        $done = count(array_filter($items, static fn (array $item) => $item['status'] === 'done'));
        $status = match (true) {
            $failed === 0 => StripeCatalogueOperation::COMPLETED,
            $done > 0 => StripeCatalogueOperation::PARTIAL,
            default => StripeCatalogueOperation::FAILED,
        };

        return $this->finish($operation, $status, ['items' => $items, 'done' => $done, 'failed' => $failed], $owner);
    }

    /**
     * The Owner approved a specific target: the Stripe Product or Price to link, or the amount and currency to
     * create. If the fresh state points anywhere else, nothing is done and a new preview is needed.
     *
     * @param  array<string, mixed>  $approved  the action as previewed
     * @param  array<string, mixed>  $fresh  the action as it is now
     * @param  Collection<string, array<string, mixed>>  $expected
     */
    private function sameTarget(StripeCatalogueOperation $operation, array $approved, array $fresh, $expected): bool
    {
        if (($approved['stripe_price_id'] ?? null) !== ($fresh['stripe_price_id'] ?? null)
            || ($approved['stripe_product_id'] ?? null) !== ($fresh['stripe_product_id'] ?? null)) {
            return false;
        }
        if ($approved['kind'] === 'create_price') {
            $planned = $operation->plan['prices'][$approved['key']] ?? null;
            $now = $expected->get($approved['key']);

            return $planned !== null && $now !== null && $planned['amount'] === $now['amount'] && $planned['currency'] === $now['currency'] && $planned['interval'] === $now['interval'];
        }

        return true;
    }

    /** A dependency is met when it was done, or was found to be unnecessary because Stripe already has it. */
    private function satisfied(?array $item): bool
    {
        return $item !== null && ($item['status'] === 'done' || ($item['status'] === 'skipped' && $item['code'] === 'no_longer_needed'));
    }

    /**
     * @param  array<string, mixed>  $action  the fresh action (current Stripe state)
     * @param  Collection<string, array<string, mixed>>  $expected
     * @param  Collection<string, array<string, mixed>>  $products
     * @param  array<string, string>  $created  Stripe IDs created in this run by item id
     * @return array<string, mixed>
     */
    private function perform(array $action, StripeMode $mode, StripeCatalogueGateway $gateway, $expected, $products, array &$created, User $owner): array
    {
        switch ($action['kind']) {
            case 'link_product':
                $this->saveProductLink($mode, $action['product_type'], $action['stripe_product_id'], 'metadata', $owner);

                return ['status' => 'done', 'code' => 'linked', 'stripe_id' => $action['stripe_product_id']];

            case 'create_product':
                $type = $action['product_type'];
                $product = $gateway->createProduct([
                    'name' => self::PRODUCT_NAMES[$type],
                    'metadata' => [CatalogueMatcher::PRODUCT_TYPE_METADATA => $type, 'wpcg_managed_by' => self::MANAGED_BY],
                ], "wpcg-cat-{$mode->value}-product-{$type}");
                $created[$action['id']] = $product->id;
                AdminAudit::record($owner, 'stripe_catalogue.object_created', 'stripe_product', $product->id, ['mode' => $mode->value, 'product_type' => $type]);
                $this->saveProductLink($mode, $type, $product->id, 'created', $owner);

                return ['status' => 'done', 'code' => 'created', 'stripe_id' => $product->id];

            case 'link_price':
                $this->linkPrice($action['key'], $action['stripe_price_id'], $owner);

                return ['status' => 'done', 'code' => 'linked', 'stripe_id' => $action['stripe_price_id']];

            case 'create_price':
                $entry = $expected->get($action['key']);
                if ($entry === null) {
                    return ['status' => 'skipped', 'code' => 'no_longer_needed'];
                }
                $productId = $created['create_product:'.$entry['product_type']] ?? $products->get($entry['product_type'])['stripe_product_id'] ?? null;
                if ($productId === null) {
                    return ['status' => 'skipped', 'code' => 'dependency_failed'];
                }
                $interval = $entry['interval'] === 'annual' ? 'year' : 'month';
                $price = $gateway->createPrice([
                    'product' => $productId,
                    'currency' => $entry['currency'],
                    'unit_amount' => $entry['amount'],
                    'interval' => $interval,
                    'nickname' => $entry['label'],
                    'metadata' => [
                        CatalogueMatcher::PRICE_KEY_METADATA => $entry['key'],
                        'wpcg_market' => $entry['market'],
                        'wpcg_tier' => $entry['tier'],
                        'wpcg_managed_by' => self::MANAGED_BY,
                    ],
                ], "wpcg-cat-{$mode->value}-price-{$entry['key']}-{$entry['currency']}-{$entry['amount']}-{$interval}");
                $created[$action['id']] = $price->id;
                AdminAudit::record($owner, 'stripe_catalogue.object_created', 'stripe_price', $price->id, ['mode' => $mode->value, 'price' => $entry['key']]);

                // What Stripe returned must be what was asked for before it is linked anywhere.
                if ($price->unitAmount !== $entry['amount'] || strtoupper($price->currency) !== $entry['currency'] || $price->interval !== $interval || $price->productId !== $productId) {
                    return ['status' => 'failed', 'code' => 'created_price_mismatch', 'stripe_id' => $price->id];
                }
                try {
                    $this->linkPrice($entry['key'], $price->id, $owner);
                } catch (Throwable $e) {
                    // The Price exists in Stripe and carries our key: the next preview adopts it, it is not created again.
                    return ['status' => 'failed', 'code' => 'created_but_not_linked', 'stripe_id' => $price->id, 'detail' => $this->detail($e)];
                }

                return ['status' => 'done', 'code' => 'created', 'stripe_id' => $price->id];
        }

        return ['status' => 'skipped', 'code' => 'unknown_item'];
    }

    /**
     * Save the Stripe Price ID of a catalogue price through the price editor (draft, Stripe check, publish).
     * An existing draft or a different Price ID is never replaced.
     *
     * @throws Throwable
     */
    private function linkPrice(string $key, string $stripePriceId, User $owner): void
    {
        $priceKey = PriceKey::tryFrom($key) ?? throw new CatalogueRefused('price_unknown');
        app()->forgetInstance(PricingCatalog::class);
        $catalog = app(PricingCatalog::class);
        $price = $catalog->price($priceKey->productType, $priceKey->tier, $priceKey->interval, $catalog->market($priceKey->market));
        if ($price === null || ! $price->active) {
            throw new CatalogueRefused('price_not_on_sale');
        }
        if ($price->hasStripePrice()) {
            if ($price->stripePriceId === $stripePriceId) {
                return;
            }
            throw new CatalogueRefused('price_already_mapped');
        }
        if (PriceDefinition::forKey($priceKey)->whereNotNull('draft')->exists()) {
            throw new CatalogueRefused('draft_pending');
        }

        $amount = $this->editor->major($price->money->amount, $price->money->currency->code);
        if ($this->editor->minor($amount, $price->money->currency->code) !== $price->money->amount) {
            throw new CatalogueRefused('amount_not_editable');
        }

        $this->editor->saveDraft($priceKey, ['currency' => $price->money->currency->code, 'amount' => $amount, 'stripe_price_id' => $stripePriceId, 'active' => '1'], $owner);
        try {
            $this->editor->publish($priceKey, $owner);
        } catch (Throwable $e) {
            $this->editor->discardDraft($priceKey, $owner);

            throw $e;
        }
        AdminAudit::record($owner, 'stripe_catalogue.price_linked', 'price', $key, ['price' => $key, 'stripe_price' => $stripePriceId]);
        app()->forgetInstance(PricingCatalog::class);
    }

    private function saveProductLink(StripeMode $mode, string $type, string $productId, string $source, User $owner): void
    {
        $existing = StripeCatalogueProduct::query()->where(['mode' => $mode->value, 'product_type' => $type])->first();
        if ($existing !== null) {
            if ($existing->stripe_product_id === $productId) {
                return;
            }
            throw new CatalogueRefused('product_already_mapped');
        }

        try {
            StripeCatalogueProduct::create(['mode' => $mode->value, 'product_type' => $type, 'stripe_product_id' => $productId, 'source' => $source, 'created_by' => $owner->id]);
        } catch (UniqueConstraintViolationException) {
            throw new CatalogueRefused('product_already_mapped');
        }
        AdminAudit::record($owner, 'stripe_catalogue.product_linked', 'stripe_product', $productId, ['mode' => $mode->value, 'product_type' => $type, 'source' => $source]);
    }

    /** @param  array<string, array<string, mixed>>  $items */
    private function journal(StripeCatalogueOperation $operation, array $items): void
    {
        try {
            $operation->forceFill(['result' => ['items' => $items, 'in_progress' => true]])->save();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param  array<string, mixed>  $result */
    private function finish(StripeCatalogueOperation $operation, string $status, array $result, User $owner): StripeCatalogueOperation
    {
        $operation->forceFill(['status' => $status, 'result' => $result, 'finished_at' => now()])->save();
        AdminAudit::record($owner, 'stripe_catalogue.applied', 'stripe_catalogue', $operation->id, [
            'mode' => $operation->mode,
            'status' => $status,
            'done' => (int) ($result['done'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
        ]);

        return $operation;
    }

    private function detail(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return mb_substr((string) collect($e->errors())->flatten()->first(), 0, 200);
        }

        return $e instanceof CatalogueRefused ? $e->reason : class_basename($e);
    }
}
