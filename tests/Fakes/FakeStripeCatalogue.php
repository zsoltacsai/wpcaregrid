<?php

namespace Tests\Fakes;

use App\Pricing\StripeCatalogue\CatalogueGatewayException;
use App\Pricing\StripeCatalogue\RemotePrice;
use App\Pricing\StripeCatalogue\RemoteProduct;
use App\Pricing\StripeCatalogue\StripeCatalogueGateway;
use App\Pricing\StripeCatalogue\StripeMode;

/**
 * An in-memory Stripe account for the catalogue manager: Products and Prices, idempotency keys that
 * replay the first result, and switchable failures. It has no method that updates or deletes anything,
 * mirroring the gateway interface, so a test can prove existing objects stay byte-for-byte unchanged.
 *
 * Product IDs are "prod_<product type>" and Price IDs "price_<product type>_<n>", which is also what
 * FakeStripePriceVerifier derives a Price's Product from.
 */
final class FakeStripeCatalogue implements StripeCatalogueGateway
{
    /** @var array<string, RemoteProduct> */
    public array $products = [];

    /** @var array<string, RemotePrice> */
    public array $prices = [];

    /** @var list<string> every gateway call, in order */
    public array $calls = [];

    /** @var array<string, string> price key (wpcg_price_key) => code of the failure to raise when creating it */
    public array $failPrice = [];

    /** Raised by every list call when set. */
    public ?string $failList = null;

    /** @var array<string, RemoteProduct|RemotePrice> idempotency key => the object it created */
    private array $replays = [];

    private int $counter = 0;

    public function __construct(public StripeMode $mode = StripeMode::Test) {}

    public function mode(): StripeMode
    {
        return $this->mode;
    }

    public function products(): array
    {
        $this->calls[] = 'products';
        $this->maybeFailList();

        return array_values($this->products);
    }

    public function prices(): array
    {
        $this->calls[] = 'prices';
        $this->maybeFailList();

        return array_values($this->prices);
    }

    public function createProduct(array $params, string $idempotencyKey): RemoteProduct
    {
        $this->calls[] = 'createProduct';
        if (isset($this->replays[$idempotencyKey])) {
            return $this->replays[$idempotencyKey];
        }

        $type = $params['metadata']['wpcg_product_type'] ?? 'other';
        $product = new RemoteProduct('prod_'.$type, $params['name'], true, $params['metadata']);
        $this->products[$product->id] = $product;

        return $this->replays[$idempotencyKey] = $product;
    }

    public function createPrice(array $params, string $idempotencyKey): RemotePrice
    {
        $this->calls[] = 'createPrice';
        if (isset($this->replays[$idempotencyKey])) {
            return $this->replays[$idempotencyKey];
        }
        $key = $params['metadata']['wpcg_price_key'] ?? '';
        if (isset($this->failPrice[$key])) {
            throw new CatalogueGatewayException($this->failPrice[$key]);
        }

        $type = str_replace('prod_', '', $params['product']);
        $price = new RemotePrice(
            id: 'price_'.$type.'_'.(++$this->counter),
            productId: $params['product'],
            currency: strtoupper($params['currency']),
            unitAmount: $params['unit_amount'],
            active: true,
            type: 'recurring',
            interval: $params['interval'],
            intervalCount: 1,
            metadata: $params['metadata'],
        );
        $this->prices[$price->id] = $price;

        return $this->replays[$idempotencyKey] = $price;
    }

    /** @param array<string, string> $metadata */
    public function addProduct(string $id, string $name = 'Product', array $metadata = [], bool $active = true): RemoteProduct
    {
        return $this->products[$id] = new RemoteProduct($id, $name, $active, $metadata);
    }

    /** @param array<string, string> $metadata */
    public function addPrice(string $id, string $productId, string $currency, int $amount, string $interval = 'month', array $metadata = [], bool $active = true, string $type = 'recurring'): RemotePrice
    {
        return $this->prices[$id] = new RemotePrice($id, $productId, strtoupper($currency), $amount, $active, $type, $type === 'recurring' ? $interval : null, 1, metadata: $metadata);
    }

    public function created(string $method): int
    {
        return count(array_filter($this->calls, static fn (string $call) => $call === $method));
    }

    private function maybeFailList(): void
    {
        if ($this->failList !== null) {
            throw new CatalogueGatewayException($this->failList);
        }
    }
}
