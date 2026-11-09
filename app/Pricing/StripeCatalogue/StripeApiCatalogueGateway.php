<?php

namespace App\Pricing\StripeCatalogue;

use App\Billing\StripeClientFactory;
use Closure;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

/**
 * Stripe API implementation. Lists are paged (100 per page, bounded), rate limits are retried with a
 * growing pause, and every other failure becomes a fixed code. Every returned object must belong to the
 * mode of the key; an object from the other mode aborts the run. Nothing secret is ever logged.
 */
final class StripeApiCatalogueGateway implements StripeCatalogueGateway
{
    private const MAX_PAGES = 50;

    private const MAX_ATTEMPTS = 4;

    private ?StripeClient $client;

    /** @var Closure(int): void */
    private Closure $pause;

    /** @param Closure(int): void|null $pause microseconds to wait after a rate limit; tests pass a no-op */
    public function __construct(
        private readonly string $secret,
        private readonly StripeMode $mode,
        ?StripeClient $client = null,
        ?Closure $pause = null,
    ) {
        $this->client = $client;
        $this->pause = $pause ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function mode(): StripeMode
    {
        return $this->mode;
    }

    public function products(): array
    {
        $products = [];
        foreach ($this->paginate(fn (array $params) => $this->client()->products->all($params)) as $product) {
            $this->assertMode($product);
            $products[] = new RemoteProduct(
                id: (string) $product->id,
                name: (string) ($product->name ?? ''),
                active: (bool) $product->active,
                metadata: $this->metadata($product),
            );
        }

        return $products;
    }

    public function prices(): array
    {
        $prices = [];
        foreach ($this->paginate(fn (array $params) => $this->client()->prices->all($params)) as $price) {
            $this->assertMode($price);
            $prices[] = $this->price($price);
        }

        return $prices;
    }

    public function createProduct(array $params, string $idempotencyKey): RemoteProduct
    {
        $product = $this->call(fn () => $this->client()->products->create($params, ['idempotency_key' => $idempotencyKey]));
        $this->assertMode($product);

        return new RemoteProduct((string) $product->id, (string) ($product->name ?? ''), (bool) $product->active, $this->metadata($product));
    }

    public function createPrice(array $params, string $idempotencyKey): RemotePrice
    {
        $price = $this->call(fn () => $this->client()->prices->create([
            'product' => $params['product'],
            'currency' => strtolower($params['currency']),
            'unit_amount' => $params['unit_amount'],
            'recurring' => ['interval' => $params['interval'], 'interval_count' => 1],
            'nickname' => $params['nickname'],
            'metadata' => $params['metadata'],
        ], ['idempotency_key' => $idempotencyKey]));
        $this->assertMode($price);

        return $this->price($price);
    }

    private function price(object $price): RemotePrice
    {
        $recurring = $price->recurring ?? null;

        return new RemotePrice(
            id: (string) $price->id,
            productId: is_string($price->product ?? null) ? $price->product : (string) ($price->product->id ?? ''),
            currency: strtoupper((string) $price->currency),
            unitAmount: isset($price->unit_amount) ? (int) $price->unit_amount : null,
            active: (bool) $price->active,
            type: (string) ($price->type ?? 'one_time'),
            interval: $recurring->interval ?? null,
            intervalCount: (int) ($recurring->interval_count ?? 1),
            usageType: (string) ($recurring->usage_type ?? 'licensed'),
            billingScheme: (string) ($price->billing_scheme ?? 'per_unit'),
            hasTiersOrTransform: ($price->transform_quantity ?? null) !== null || ($price->tiers_mode ?? null) !== null,
            metadata: $this->metadata($price),
        );
    }

    /** @return array<string, string> */
    private function metadata(object $object): array
    {
        $metadata = $object->metadata ?? null;
        $metadata = is_object($metadata) && method_exists($metadata, 'toArray') ? $metadata->toArray() : (array) $metadata;

        return array_map(static fn ($value) => (string) $value, array_filter($metadata, static fn ($value) => is_scalar($value)));
    }

    private function assertMode(object $object): void
    {
        if ((bool) ($object->livemode ?? false) !== ($this->mode === StripeMode::Live)) {
            throw new CatalogueGatewayException('mode_mismatch');
        }
    }

    /**
     * @param  Closure(array<string, mixed>): object  $fetch
     * @return list<object>
     */
    private function paginate(Closure $fetch): array
    {
        $items = [];
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $params = ['limit' => 100] + ($after === null ? [] : ['starting_after' => $after]);
            $result = $this->call(fn () => $fetch($params));
            $data = (array) ($result->data ?? []);
            foreach ($data as $item) {
                $items[] = $item;
            }
            if (! ($result->has_more ?? false) || $data === []) {
                return $items;
            }
            $after = (string) end($data)->id;
        }

        throw new CatalogueGatewayException('catalogue_too_large');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $request
     * @return T
     */
    private function call(Closure $request): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $request();
            } catch (RateLimitException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    $this->log($e);

                    throw new CatalogueGatewayException('rate_limited');
                }
                ($this->pause)(250_000 * (2 ** ($attempt - 1)));
            } catch (ApiErrorException $e) {
                $this->log($e);

                throw new CatalogueGatewayException(match (true) {
                    $e instanceof AuthenticationException => 'authentication',
                    $e instanceof PermissionException => 'permission',
                    $e instanceof IdempotencyException => 'idempotency_conflict',
                    $e instanceof InvalidRequestException => 'invalid_request',
                    $e instanceof ApiConnectionException => 'api_connection',
                    default => 'api_error',
                });
            }
        }
    }

    /** Code, type and status only: the message can quote part of a key. */
    private function log(ApiErrorException $e): void
    {
        Log::warning('Stripe catalogue API call failed.', [
            'exception' => $e::class,
            'stripe_code' => $e->getStripeCode(),
            'http_status' => $e->getHttpStatus(),
        ]);
    }

    private function client(): StripeClient
    {
        return $this->client ??= StripeClientFactory::make($this->secret);
    }
}
