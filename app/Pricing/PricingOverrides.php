<?php

namespace App\Pricing;

use App\Models\PriceDefinition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Lays prices published in the owner area over config/pricing.php: a
 * published definition replaces the config row for the same product, tier,
 * interval and market, or adds one for a market that had none. Plans,
 * markets and currencies stay in config.
 *
 * Stripe Price IDs a price used before (the config one, earlier published
 * ones) stay recognised, so webhooks for existing subscriptions still map
 * to their plan: a price change affects future checkouts only.
 */
final class PricingOverrides
{
    public const CACHE_KEY = 'pricing.published';

    /**
     * @param  array<string, mixed>  $config  config('pricing')
     * @return array<string, mixed>
     */
    public function apply(array $config): array
    {
        foreach ($this->published() as $definition) {
            $market = $config['markets'][$definition['market']] ?? null;

            // A definition config no longer supports (market removed, currency changed) is ignored, never half-applied.
            if (! $market || $market['currency'] !== $definition['currency'] || ! isset($config['plans'][$definition['product_type']][$definition['tier']])) {
                Log::warning('Published price ignored: it no longer matches config/pricing.php.', ['price' => implode('.', [$definition['product_type'], $definition['tier'], $definition['interval'], $definition['market']])]);

                continue;
            }

            $row = [
                'product_type' => $definition['product_type'],
                'plan' => $definition['tier'],
                'interval' => $definition['interval'],
                'market' => $definition['market'],
                'currency' => $definition['currency'],
                'amount' => (int) $definition['amount'],
                'stripe_price_id' => $definition['stripe_price_id'],
                'active' => (bool) $definition['active'],
                'retired_stripe_price_ids' => (array) ($definition['retired_stripe_price_ids'] ?? []),
                'pricing_version' => (string) ($definition['pricing_version'] ?? ''),
            ];

            $index = $this->find($config['prices'], $row);
            if ($index === null) {
                $config['prices'][] = $row;

                continue;
            }

            $previous = $config['prices'][$index]['stripe_price_id'] ?? null;
            if (filled($previous) && $previous !== $row['stripe_price_id']) {
                $row['retired_stripe_price_ids'][] = $previous;
            }
            $config['prices'][$index] = $row;
        }

        return $config;
    }

    /** Published prices changed: the next catalogue is built from them. */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        app()->forgetInstance(PricingCatalog::class);
    }

    /** @return list<array<string, mixed>> */
    private function published(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => PriceDefinition::published()
                ->orderBy('id')
                ->get(['id', 'product_type', 'tier', 'interval', 'market', 'currency', 'amount', 'stripe_price_id', 'active', 'retired_stripe_price_ids', 'current_version_id'])
                ->map(fn (PriceDefinition $definition) => [
                    ...$definition->only(['product_type', 'tier', 'interval', 'market', 'currency', 'amount', 'stripe_price_id', 'active', 'retired_stripe_price_ids']),
                    'pricing_version' => $definition->current_version_id ? 'published:'.$definition->current_version_id : 'published:'.$definition->id,
                ])
                ->all());
        } catch (QueryException $e) {
            Log::warning('Published prices could not be read; config/pricing.php is used.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @param  list<array<string, mixed>>  $prices */
    private function find(array $prices, array $row): ?int
    {
        foreach ($prices as $index => $price) {
            if ($price['product_type'] === $row['product_type'] && $price['plan'] === $row['plan']
                && $price['interval'] === $row['interval'] && $price['market'] === $row['market']) {
                return $index;
            }
        }

        return null;
    }
}
