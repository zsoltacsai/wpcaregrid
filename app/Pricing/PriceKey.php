<?php

namespace App\Pricing;

/**
 * Which price: product type × tier × interval × market, e.g.
 * "wordpress.basic.monthly.hu". Only combinations of known enum values and
 * configured markets exist.
 */
final readonly class PriceKey
{
    public function __construct(
        public ProductType $productType,
        public PlanTier $tier,
        public BillingInterval $interval,
        public string $market,
    ) {}

    public static function tryFrom(string $value): ?self
    {
        $parts = explode('.', $value);
        if (count($parts) !== 4 || ! array_key_exists($parts[3], (array) config('pricing.markets'))) {
            return null;
        }

        $product = ProductType::tryFrom($parts[0]);
        $tier = PlanTier::tryFrom($parts[1]);
        $interval = BillingInterval::tryFrom($parts[2]);

        return $product && $tier && $interval ? new self($product, $tier, $interval, $parts[3]) : null;
    }

    public function value(): string
    {
        return "{$this->productType->value}.{$this->tier->value}.{$this->interval->value}.{$this->market}";
    }

    /** The currency this price must be in: its market's, never a free choice. */
    public function currency(): string
    {
        return (string) config("pricing.markets.{$this->market}.currency");
    }

    /** @return array{product_type: string, tier: string, interval: string, market: string} */
    public function attributes(): array
    {
        return [
            'product_type' => $this->productType->value,
            'tier' => $this->tier->value,
            'interval' => $this->interval->value,
            'market' => $this->market,
        ];
    }

    /** The matching row of config/pricing.php, if any. */
    public function configRow(): ?array
    {
        foreach ((array) config('pricing.prices') as $row) {
            if ($row['product_type'] === $this->productType->value && $row['plan'] === $this->tier->value
                && $row['interval'] === $this->interval->value && $row['market'] === $this->market) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<self> Every possible price in a market. */
    public static function forMarket(string $market): array
    {
        $keys = [];
        foreach (ProductType::cases() as $product) {
            foreach (PlanTier::cases() as $tier) {
                foreach (BillingInterval::cases() as $interval) {
                    $keys[] = new self($product, $tier, $interval, $market);
                }
            }
        }

        return $keys;
    }
}
