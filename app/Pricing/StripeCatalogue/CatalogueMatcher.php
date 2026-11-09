<?php

namespace App\Pricing\StripeCatalogue;

/**
 * Matches the WP CareGrid catalogue against a Stripe catalogue. Pure: no Stripe call, no database.
 *
 * A Price matches only when ALL of these hold, and exactly one Stripe Price qualifies:
 * the Stripe Product of its product type, the currency, the amount in minor units (Stripe's own unit,
 * which is also the catalogue's), a simple recurring price with a monthly or yearly interval of one,
 * and being active. The stable internal key in the Price metadata (wpcg_price_key) decides first; name
 * and amount alone never do. Anything unclear is "ambiguous" or "conflict" and is never changed.
 *
 * States of a price: exact, missing, ambiguous, conflict, blocked (cannot be judged yet), skipped.
 * Existing Stripe objects are only ever read; the plan only proposes to link or create.
 */
final class CatalogueMatcher
{
    public const PRICE_KEY_METADATA = 'wpcg_price_key';

    public const PRODUCT_TYPE_METADATA = 'wpcg_product_type';

    /** Currencies Stripe accepts only in whole units, although it counts them in hundredths. */
    private const WHOLE_UNIT_CURRENCIES = ['HUF', 'TWD', 'ISK', 'UGX'];

    private const INTERVALS = ['monthly' => 'month', 'annual' => 'year'];

    /**
     * @param  list<array{key: string, product_type: string, tier: string, interval: string, market: string, currency: string, amount: int, current_id: ?string, draft: bool, label: string}>  $expected
     * @param  array<string, array{id: string, source: string}>  $mappedProducts  product type => known Stripe Product
     * @param  list<RemoteProduct>  $products
     * @param  list<RemotePrice>  $prices
     * @return array{mode: string, products: array<string, array<string, mixed>>, prices: array<string, array<string, mixed>>, actions: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function plan(StripeMode $mode, array $expected, array $mappedProducts, array $products, array $prices): array
    {
        $productsById = [];
        foreach ($products as $product) {
            $productsById[$product->id] = $product;
        }
        $pricesById = [];
        foreach ($prices as $price) {
            $pricesById[$price->id] = $price;
        }

        $types = array_values(array_unique(array_column($expected, 'product_type')));
        $productStates = [];
        foreach ($types as $type) {
            $productStates[$type] = $this->product($type, $mappedProducts[$type] ?? null, $products, $productsById);
        }

        $results = [];
        foreach ($expected as $entry) {
            $results[$entry['key']] = $this->price($entry, $productStates[$entry['product_type']], $prices, $pricesById);
        }

        // One Stripe Price can stand for only one catalogue price.
        $shared = [];
        foreach ($results as $key => $result) {
            if ($result['state'] === 'exact') {
                $shared[$result['stripe_price_id']][] = $key;
            }
        }
        foreach ($shared as $keys) {
            if (count($keys) > 1) {
                foreach ($keys as $key) {
                    $results[$key] = [...$results[$key], 'state' => 'conflict', 'reason' => 'price_shared_by_several', 'stripe_price_id' => null, 'already_mapped' => false];
                }
            }
        }

        $actions = [];
        foreach ($productStates as $type => $state) {
            if ($state['action'] === 'link') {
                $actions[] = ['id' => 'link_product:'.$type, 'kind' => 'link_product', 'product_type' => $type, 'stripe_product_id' => $state['stripe_product_id']];
            } elseif ($state['action'] === 'create') {
                $actions[] = ['id' => 'create_product:'.$type, 'kind' => 'create_product', 'product_type' => $type];
            }
        }
        foreach ($results as $key => $result) {
            $planned = match ($productStates[$result['product_type']]['action']) {
                'create' => 'create_product:'.$result['product_type'],
                'link' => 'link_product:'.$result['product_type'],
                default => null,
            };
            if ($result['state'] === 'exact' && ! $result['already_mapped'] && $result['draft']) {
                $results[$key]['reason'] = 'draft_pending';
            }
            if ($result['state'] === 'exact' && ! $result['already_mapped'] && ! $result['draft']) {
                $actions[] = ['id' => 'link_price:'.$key, 'kind' => 'link_price', 'key' => $key, 'stripe_price_id' => $result['stripe_price_id'], 'depends_on' => $planned];
            } elseif ($result['state'] === 'missing' && $result['reason'] === null) {
                $actions[] = ['id' => 'create_price:'.$key, 'kind' => 'create_price', 'key' => $key, 'depends_on' => $planned];
            }
            $results[$key]['action'] = match (true) {
                $result['state'] === 'exact' && ! $result['already_mapped'] && ! $result['draft'] => 'link',
                $result['state'] === 'missing' && $result['reason'] === null => 'create',
                default => null,
            };
        }

        return [
            'mode' => $mode->value,
            'products' => $productStates,
            'prices' => $results,
            'actions' => $actions,
            'summary' => $this->summary($productStates, $results, $actions),
        ];
    }

    /**
     * @param  array{id: string, source: string}|null  $mapped
     * @param  list<RemoteProduct>  $products
     * @param  array<string, RemoteProduct>  $byId
     * @return array<string, mixed>
     */
    private function product(string $type, ?array $mapped, array $products, array $byId): array
    {
        $state = ['product_type' => $type, 'state' => 'missing', 'stripe_product_id' => null, 'source' => null, 'candidates' => [], 'suggestions' => [], 'action' => null, 'reason' => null];

        if ($mapped !== null) {
            $remote = $byId[$mapped['id']] ?? null;
            if ($remote === null || ! $remote->active) {
                return [...$state, 'state' => 'conflict', 'stripe_product_id' => $mapped['id'], 'source' => $mapped['source'], 'reason' => $remote === null ? 'mapped_product_not_found' : 'mapped_product_inactive'];
            }

            return [...$state, 'state' => 'exact', 'stripe_product_id' => $remote->id, 'source' => $mapped['source']];
        }

        $tagged = array_values(array_filter($products, static fn (RemoteProduct $p) => $p->active && ($p->metadata[self::PRODUCT_TYPE_METADATA] ?? null) === $type));
        if (count($tagged) === 1) {
            return [...$state, 'state' => 'exact', 'stripe_product_id' => $tagged[0]->id, 'source' => 'metadata', 'candidates' => [$tagged[0]->id], 'action' => 'link'];
        }
        if (count($tagged) > 1) {
            return [...$state, 'state' => 'ambiguous', 'candidates' => array_map(static fn (RemoteProduct $p) => $p->id, $tagged), 'reason' => 'several_tagged_products'];
        }

        // Name similarity is only a hint for the Owner (who can link a Product explicitly); it never links.
        $needle = strtolower($type);
        $suggestions = [];
        foreach ($products as $product) {
            if ($product->active && str_contains(strtolower($product->name), $needle)) {
                $suggestions[] = ['id' => $product->id, 'name' => $product->name];
            }
        }

        return [...$state, 'suggestions' => $suggestions, 'action' => 'create'];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $product
     * @param  list<RemotePrice>  $prices
     * @param  array<string, RemotePrice>  $byId
     * @return array<string, mixed>
     */
    private function price(array $entry, array $product, array $prices, array $byId): array
    {
        $result = [
            'key' => $entry['key'], 'label' => $entry['label'], 'product_type' => $entry['product_type'], 'currency' => $entry['currency'],
            'amount' => $entry['amount'], 'interval' => $entry['interval'], 'state' => 'missing', 'via' => null, 'stripe_price_id' => null,
            'reason' => null, 'details' => [], 'candidates' => [], 'already_mapped' => false, 'draft' => $entry['draft'], 'action' => null,
        ];

        if (in_array($entry['currency'], self::WHOLE_UNIT_CURRENCIES, true) && $entry['amount'] % 100 !== 0) {
            return [...$result, 'state' => 'conflict', 'reason' => 'amount_not_supported_by_stripe'];
        }

        // The product this price belongs to: known, to be linked, to be created, or not decidable yet.
        $productId = in_array($product['state'], ['exact'], true) ? $product['stripe_product_id'] : null;
        $productBlocked = ! in_array($product['state'], ['exact', 'missing'], true);

        $claimed = array_values(array_filter($prices, static fn (RemotePrice $p) => ($p->metadata[self::PRICE_KEY_METADATA] ?? null) === $entry['key']));
        $activeClaimed = array_values(array_filter($claimed, static fn (RemotePrice $p) => $p->active));

        if ($entry['current_id'] !== null) {
            $remote = $byId[$entry['current_id']] ?? null;
            if ($remote === null) {
                return [...$result, 'state' => 'conflict', 'reason' => 'mapped_price_not_found', 'stripe_price_id' => $entry['current_id']];
            }
            if (! $remote->active) {
                return [...$result, 'state' => 'conflict', 'reason' => 'mapped_price_inactive', 'stripe_price_id' => $remote->id];
            }
            $problems = $this->differences($remote, $entry, $productBlocked ? null : $productId);
            if ($problems !== []) {
                return [...$result, 'state' => 'conflict', 'reason' => 'mapped_price_differs', 'details' => $problems, 'stripe_price_id' => $remote->id];
            }
            foreach ($activeClaimed as $other) {
                if ($other->id !== $remote->id) {
                    return [...$result, 'state' => 'conflict', 'reason' => 'metadata_points_elsewhere', 'stripe_price_id' => $remote->id, 'candidates' => [$other->id]];
                }
            }

            return [...$result, 'state' => 'exact', 'via' => 'existing', 'stripe_price_id' => $remote->id, 'already_mapped' => true];
        }

        if (count($activeClaimed) > 1) {
            return [...$result, 'state' => 'ambiguous', 'reason' => 'duplicate_metadata', 'candidates' => array_map(static fn (RemotePrice $p) => $p->id, $activeClaimed)];
        }
        if (count($activeClaimed) === 1) {
            $remote = $activeClaimed[0];
            $problems = $this->differences($remote, $entry, $productBlocked ? null : $productId);

            return $problems === []
                ? [...$result, 'state' => 'exact', 'via' => 'metadata', 'stripe_price_id' => $remote->id, 'candidates' => [$remote->id]]
                : [...$result, 'state' => 'conflict', 'reason' => 'metadata_price_differs', 'details' => $problems, 'stripe_price_id' => $remote->id, 'candidates' => [$remote->id]];
        }

        if ($productBlocked) {
            return [...$result, 'state' => 'blocked', 'reason' => 'product_unresolved'];
        }
        if ($productId === null) {
            // The Product does not exist yet: no existing Price can belong to it.
            return [...$result, 'reason' => $entry['draft'] ? 'draft_pending' : null];
        }

        $sameProduct = array_filter($prices, static fn (RemotePrice $p) => $p->active && $p->productId === $productId && $p->isSimpleRecurring()
            && strtoupper($p->currency) === $entry['currency'] && ! isset($p->metadata[self::PRICE_KEY_METADATA]));
        $matching = array_values(array_filter($sameProduct, fn (RemotePrice $p) => $this->differences($p, $entry, $productId) === []));
        if (count($matching) === 1) {
            return [...$result, 'state' => 'exact', 'via' => 'attributes', 'stripe_price_id' => $matching[0]->id, 'candidates' => [$matching[0]->id]];
        }
        if (count($matching) > 1) {
            return [...$result, 'state' => 'ambiguous', 'reason' => 'several_matching_prices', 'candidates' => array_values(array_map(static fn (RemotePrice $p) => $p->id, $matching))];
        }

        // Same Product, currency and period but another amount: an Owner decision, never a second active price.
        $interval = self::INTERVALS[$entry['interval']];
        $sameSlot = array_values(array_filter($sameProduct, static fn (RemotePrice $p) => $p->interval === $interval && $p->intervalCount === 1));
        if ($sameSlot !== []) {
            return [...$result, 'state' => 'conflict', 'reason' => 'amount_differs', 'candidates' => array_values(array_map(static fn (RemotePrice $p) => $p->id, $sameSlot)),
                'details' => array_values(array_map(static fn (RemotePrice $p) => $p->id.':'.$p->unitAmount, $sameSlot))];
        }

        return [...$result, 'reason' => $entry['draft'] ? 'draft_pending' : null];
    }

    /**
     * What differs between a Stripe Price and the catalogue entry.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    public function differences(RemotePrice $remote, array $entry, ?string $productId): array
    {
        $problems = [];
        if (! $remote->isSimpleRecurring()) {
            $problems[] = 'not_simple_recurring';
        }
        if (strtoupper($remote->currency) !== $entry['currency']) {
            $problems[] = 'currency';
        }
        if ($remote->unitAmount !== $entry['amount']) {
            $problems[] = 'amount';
        }
        if ($remote->interval !== self::INTERVALS[$entry['interval']] || $remote->intervalCount !== 1) {
            $problems[] = 'interval';
        }
        // No known Product (still to be created, or undecidable) means an existing Price cannot belong to it.
        if ($productId === null || $remote->productId !== $productId) {
            $problems[] = 'product';
        }

        return $problems;
    }

    /**
     * @param  array<string, array<string, mixed>>  $products
     * @param  array<string, array<string, mixed>>  $prices
     * @param  list<array<string, mixed>>  $actions
     * @return array<string, mixed>
     */
    private function summary(array $products, array $prices, array $actions): array
    {
        $count = static function (array $rows, string $field): array {
            $counts = [];
            foreach ($rows as $row) {
                $counts[$row[$field]] = ($counts[$row[$field]] ?? 0) + 1;
            }
            ksort($counts);

            return $counts;
        };

        return [
            'products' => $count($products, 'state'),
            'prices' => $count($prices, 'state'),
            'actions' => $count($actions, 'kind'),
            'total_prices' => count($prices),
        ];
    }
}
