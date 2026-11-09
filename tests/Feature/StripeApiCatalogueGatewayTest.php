<?php

namespace Tests\Feature;

use App\Billing\StripeClientFactory;
use App\Pricing\StripeCatalogue\CatalogueGatewayException;
use App\Pricing\StripeCatalogue\StripeApiCatalogueGateway;
use App\Pricing\StripeCatalogue\StripeMode;
use Illuminate\Support\Facades\Log;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\TestCase;

/** The real Stripe gateway, against a scripted HTTP layer: paging, rate limits, errors, mode, secrets. */
class StripeApiCatalogueGatewayTest extends TestCase
{
    private const SECRET = 'sk_test_gatewaysecret1234567890';

    /** @var list<int> */
    private array $pauses = [];

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient);
        parent::tearDown();
    }

    private function gateway(ScriptedStripeHttp $http, StripeMode $mode = StripeMode::Test): StripeApiCatalogueGateway
    {
        ApiRequestor::setHttpClient($http);

        return new StripeApiCatalogueGateway(self::SECRET, $mode, StripeClientFactory::make(self::SECRET), function (int $microseconds) {
            $this->pauses[] = $microseconds;
        });
    }

    private static function product(string $id, bool $live = false, bool $active = true): array
    {
        return ['id' => $id, 'object' => 'product', 'name' => 'Product '.$id, 'active' => $active, 'livemode' => $live, 'metadata' => ['wpcg_product_type' => 'wordpress']];
    }

    private static function price(string $id, bool $live = false, bool $active = true): array
    {
        return [
            'id' => $id, 'object' => 'price', 'product' => 'prod_1', 'currency' => 'huf', 'unit_amount' => 899000, 'active' => $active, 'livemode' => $live,
            'type' => 'recurring', 'billing_scheme' => 'per_unit', 'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed'],
            'metadata' => ['wpcg_price_key' => 'wordpress.basic.monthly.hu'], 'transform_quantity' => null, 'tiers_mode' => null,
        ];
    }

    private static function list(array $data, bool $more = false): array
    {
        return [200, ['object' => 'list', 'data' => $data, 'has_more' => $more, 'url' => '/v1/x']];
    }

    public function test_lists_are_read_page_by_page_including_archived_objects(): void
    {
        $http = new ScriptedStripeHttp([
            self::list([self::product('prod_1'), self::product('prod_2', active: false)], true),
            self::list([self::product('prod_3')]),
        ]);

        $products = $this->gateway($http)->products();

        $this->assertSame(['prod_1', 'prod_2', 'prod_3'], array_map(fn ($p) => $p->id, $products));
        $this->assertFalse($products[1]->active);
        $this->assertSame('wordpress', $products[0]->metadata['wpcg_product_type']);
        $this->assertSame(['limit' => 100], $http->requests[0]['params']);
        $this->assertSame(['limit' => 100, 'starting_after' => 'prod_2'], $http->requests[1]['params']);
        $this->assertSame(['GET', 'GET'], array_column($http->requests, 'method'));
        $this->assertArrayNotHasKey('active', $http->requests[0]['params'], 'archived objects are listed too');
    }

    public function test_prices_are_read_with_their_billing_attributes(): void
    {
        $price = $this->gateway(new ScriptedStripeHttp([self::list([self::price('price_1')])]))->prices()[0];

        $this->assertSame(['price_1', 'prod_1', 'HUF', 899000, 'month', 1, true], [$price->id, $price->productId, $price->currency, $price->unitAmount, $price->interval, $price->intervalCount, $price->isSimpleRecurring()]);
    }

    public function test_a_rate_limit_is_retried_with_growing_pauses_and_then_succeeds(): void
    {
        $limited = [429, ['error' => ['type' => 'rate_limit_error', 'message' => 'Too many requests with '.self::SECRET]]];
        $http = new ScriptedStripeHttp([$limited, $limited, self::list([self::product('prod_1')])]);

        $products = $this->gateway($http)->products();

        $this->assertCount(1, $products);
        $this->assertSame([250_000, 500_000], $this->pauses);
        $this->assertCount(3, $http->requests);
    }

    public function test_a_persistent_rate_limit_becomes_a_fixed_code_without_the_stripe_message(): void
    {
        $limited = [429, ['error' => ['type' => 'rate_limit_error', 'message' => 'Too many requests with '.self::SECRET]]];
        Log::spy();

        try {
            $this->gateway(new ScriptedStripeHttp([$limited, $limited, $limited, $limited]))->products();
            $this->fail('expected a rate limit failure');
        } catch (CatalogueGatewayException $e) {
            $this->assertSame('rate_limited', $e->reason);
            $this->assertStringNotContainsString('sk_test_', $e->getMessage());
            $this->assertTrue($e->stopsTheRun());
        }

        $this->assertCount(3, $this->pauses);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => ! str_contains(json_encode($context), 'sk_test_') && ! str_contains($message, 'sk_test_'));
    }

    public function test_api_errors_map_to_fixed_codes(): void
    {
        $cases = [
            [401, 'authentication_error', 'authentication', true],
            [403, 'invalid_request_error', 'permission', true],
            [400, 'invalid_request_error', 'invalid_request', false],
            [500, 'api_error', 'api_error', false],
        ];
        foreach ($cases as [$status, $type, $code, $stops]) {
            try {
                $this->gateway(new ScriptedStripeHttp([[$status, ['error' => ['type' => $type, 'message' => 'bad key '.self::SECRET]]]]))->prices();
                $this->fail('expected '.$code);
            } catch (CatalogueGatewayException $e) {
                $this->assertSame($code, $e->reason, (string) $status);
                $this->assertSame($stops, $e->stopsTheRun(), $code);
                $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            }
        }
    }

    public function test_an_object_of_the_other_mode_stops_everything(): void
    {
        $this->expectException(CatalogueGatewayException::class);
        $this->expectExceptionMessage('mode_mismatch');

        $this->gateway(new ScriptedStripeHttp([self::list([self::price('price_live', live: true)])]), StripeMode::Test)->prices();
    }

    public function test_a_live_gateway_rejects_test_mode_objects_too(): void
    {
        $this->expectExceptionMessage('mode_mismatch');

        $this->gateway(new ScriptedStripeHttp([self::list([self::product('prod_1', live: false)])]), StripeMode::Live)->products();
    }

    public function test_creating_a_price_sends_one_post_with_an_idempotency_key_and_our_metadata(): void
    {
        $http = new ScriptedStripeHttp([[200, self::price('price_new')]]);

        $price = $this->gateway($http)->createPrice([
            'product' => 'prod_1', 'currency' => 'HUF', 'unit_amount' => 899000, 'interval' => 'month', 'nickname' => 'WordPress Basic',
            'metadata' => ['wpcg_price_key' => 'wordpress.basic.monthly.hu'],
        ], 'wpcg-cat-test-price-wordpress.basic.monthly.hu-HUF-899000-month');

        $request = $http->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertStringEndsWith('/v1/prices', $request['url']);
        $this->assertSame('huf', $request['params']['currency']);
        $this->assertSame(899000, $request['params']['unit_amount']);
        $this->assertSame(['interval' => 'month', 'interval_count' => 1], $request['params']['recurring']);
        $this->assertSame('wordpress.basic.monthly.hu', $request['params']['metadata']['wpcg_price_key']);
        $this->assertContains('Idempotency-Key: wpcg-cat-test-price-wordpress.basic.monthly.hu-HUF-899000-month', $request['headers']);
        $this->assertSame('price_new', $price->id);
    }

    public function test_creating_a_product_sends_one_post_with_an_idempotency_key(): void
    {
        $http = new ScriptedStripeHttp([[200, self::product('prod_new')]]);

        $this->gateway($http)->createProduct(['name' => 'WP CareGrid WordPress maintenance', 'metadata' => ['wpcg_product_type' => 'wordpress']], 'wpcg-cat-test-product-wordpress');

        $this->assertSame('POST', $http->requests[0]['method']);
        $this->assertStringEndsWith('/v1/products', $http->requests[0]['url']);
        $this->assertContains('Idempotency-Key: wpcg-cat-test-product-wordpress', $http->requests[0]['headers']);
    }

    public function test_the_gateway_has_no_way_to_change_or_remove_anything(): void
    {
        $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass(StripeApiCatalogueGateway::class))->getMethods(\ReflectionMethod::IS_PUBLIC));

        $this->assertEqualsCanonicalizing(['__construct', 'mode', 'products', 'prices', 'createProduct', 'createPrice'], $methods);
    }
}

/** Plays back scripted [status, body] responses and records every request. */
final class ScriptedStripeHttp implements ClientInterface
{
    /** @var list<array{method: string, url: string, params: array<string, mixed>, headers: array<mixed>}> */
    public array $requests = [];

    /** @param list<array{0: int, 1: array<string, mixed>}> $responses */
    public function __construct(private array $responses) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => strtoupper((string) $method), 'url' => (string) $absUrl, 'params' => (array) $params, 'headers' => (array) $headers];
        $response = array_shift($this->responses);
        if (! is_array($response)) {
            throw new \RuntimeException('Unexpected Stripe API request in test.');
        }

        return [json_encode($response[1], JSON_THROW_ON_ERROR), $response[0], ['Request-Id' => 'req_test']];
    }
}
