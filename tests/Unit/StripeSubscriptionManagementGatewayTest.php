<?php

namespace Tests\Unit;

use App\Billing\StripeClientFactory;
use App\Billing\StripeSubscriptionManagementGateway;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\TestCase;

class StripeSubscriptionManagementGatewayTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient);
        parent::tearDown();
    }

    public function test_deferred_price_change_creates_and_configures_a_schedule_without_updating_subscription_now(): void
    {
        $periodStart = Carbon::parse('2026-10-13 12:00:00 UTC')->timestamp;
        $periodEnd = Carbon::parse('2027-10-13 12:00:00 UTC')->timestamp;
        $http = new RecordingStripeHttpClient([
            [
                'id' => 'sub_test_m11', 'object' => 'subscription', 'customer' => 'cus_test_m11',
                'current_period_start' => $periodStart, 'schedule' => null,
                'metadata' => ['checkout_intent' => 'intent_test'],
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_test_m11', 'quantity' => 1, 'current_period_end' => $periodEnd,
                    'price' => ['id' => 'price_annual', 'object' => 'price', 'currency' => 'huf'],
                    'tax_rates' => ['txr_test'],
                ]]],
            ],
            ['id' => 'price_monthly', 'object' => 'price', 'active' => true, 'currency' => 'huf', 'recurring' => ['interval' => 'month', 'interval_count' => 1]],
            [
                'id' => 'sub_sched_test_m11', 'object' => 'subscription_schedule', 'status' => 'active',
                'metadata' => ['caregrid_operation_uuid' => 'operation-test', 'caregrid_target_price_id' => 'price_monthly'],
                'phases' => [[
                    'object' => 'subscription_schedule_phase', 'start_date' => $periodStart, 'end_date' => $periodEnd,
                    'items' => [[
                        'price' => ['id' => 'price_annual', 'object' => 'price'], 'quantity' => 1, 'tax_rates' => ['txr_test'],
                    ]],
                    'metadata' => ['checkout_intent' => 'intent_test'],
                    'proration_behavior' => 'none',
                ]],
            ],
            ['id' => 'sub_sched_test_m11', 'object' => 'subscription_schedule', 'status' => 'active'],
        ]);
        ApiRequestor::setHttpClient($http);
        $subscription = new Subscription([
            'stripe_subscription_id' => 'sub_test_m11', 'stripe_price_id' => 'price_annual',
            'current_period_end' => Carbon::createFromTimestampUTC($periodEnd),
        ]);
        $gateway = new StripeSubscriptionManagementGateway('sk_test_m11', false, StripeClientFactory::make('sk_test_m11'));

        $result = $gateway->changePrice($subscription, 'price_monthly', false, 'operation-test', 'idempotency-key');

        self::assertSame('sub_sched_test_m11', $result['schedule_id']);
        self::assertNull($result['invoice_id']);
        self::assertSame(['GET', 'GET', 'POST', 'POST'], array_column($http->requests, 'method'));
        self::assertStringEndsWith('/v1/prices/price_monthly', $http->requests[1]['url']);
        self::assertStringEndsWith('/v1/subscription_schedules', $http->requests[2]['url']);
        self::assertSame('sub_test_m11', $http->requests[2]['params']['from_subscription']);
        self::assertSame(['from_subscription' => 'sub_test_m11'], $http->requests[2]['params']);
        self::assertStringEndsWith('/v1/subscription_schedules/sub_sched_test_m11', $http->requests[3]['url']);
        self::assertSame($periodEnd, $http->requests[3]['params']['phases'][0]['end_date']);
        self::assertSame('price_annual', $http->requests[3]['params']['phases'][0]['items'][0]['price']);
        self::assertSame('price_monthly', $http->requests[3]['params']['phases'][1]['items'][0]['price']);
        self::assertSame($periodEnd, $http->requests[3]['params']['phases'][1]['start_date']);
        self::assertSame(['interval' => 'month', 'interval_count' => 1], $http->requests[3]['params']['phases'][1]['duration']);
        self::assertSame('none', $http->requests[3]['params']['phases'][1]['proration_behavior']);
        self::assertSame(['txr_test'], $http->requests[3]['params']['phases'][1]['items'][0]['tax_rates']);
        self::assertSame('operation-test', $http->requests[3]['params']['phases'][1]['metadata']['caregrid_operation_uuid']);
    }

    public function test_deferred_change_fails_closed_when_live_provider_price_differs_from_local_state(): void
    {
        $periodEnd = Carbon::parse('2027-10-13 12:00:00 UTC')->timestamp;
        $http = new RecordingStripeHttpClient([[
            'id' => 'sub_test_m11', 'object' => 'subscription', 'current_period_end' => $periodEnd, 'schedule' => null,
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_test_m11', 'quantity' => 1, 'price' => ['id' => 'price_monthly', 'object' => 'price'],
            ]]],
        ]]);
        ApiRequestor::setHttpClient($http);
        $subscription = new Subscription([
            'stripe_subscription_id' => 'sub_test_m11', 'stripe_price_id' => 'price_annual',
            'current_period_end' => Carbon::createFromTimestampUTC($periodEnd),
        ]);
        $gateway = new StripeSubscriptionManagementGateway('sk_test_m11', false, StripeClientFactory::make('sk_test_m11'));

        try {
            $gateway->changePrice($subscription, 'price_monthly', false, 'operation-test', 'idempotency-key');
            self::fail('A provider/local mismatch must not be hidden by scheduling another update.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('reconciliation is required', $exception->getMessage());
        }

        self::assertCount(1, $http->requests, 'No schedule or price mutation should follow a failed state preflight.');
    }

    public function test_immediate_price_change_preserves_the_billing_cycle_anchor_under_endive(): void
    {
        $periodEnd = Carbon::parse('2027-10-13 12:00:00 UTC')->timestamp;
        $http = new RecordingStripeHttpClient([
            [
                'id' => 'sub_test_m11', 'object' => 'subscription',
                'metadata' => ['existing' => 'value'],
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_test_m11', 'quantity' => 2,
                    'price' => ['id' => 'price_annual', 'object' => 'price'],
                ]]],
            ],
            [
                'id' => 'sub_test_m11', 'object' => 'subscription',
                'latest_invoice' => 'in_test_m11',
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_test_m11', 'quantity' => 2,
                    'price' => ['id' => 'price_monthly', 'object' => 'price'],
                ]]],
            ],
        ]);
        ApiRequestor::setHttpClient($http);
        $subscription = new Subscription([
            'stripe_subscription_id' => 'sub_test_m11', 'stripe_price_id' => 'price_annual',
            'current_period_end' => Carbon::createFromTimestampUTC($periodEnd),
        ]);
        $gateway = new StripeSubscriptionManagementGateway('sk_test_m11', false, StripeClientFactory::make('sk_test_m11'));

        $result = $gateway->changePrice($subscription, 'price_monthly', true, 'operation-test', 'idempotency-key');

        self::assertSame('in_test_m11', $result['invoice_id']);
        self::assertSame(['GET', 'POST'], array_column($http->requests, 'method'));
        self::assertSame(['type' => 'unchanged'], $http->requests[1]['params']['billing_cycle_anchor']);
        self::assertSame('always_invoice', $http->requests[1]['params']['proration_behavior']);
        self::assertSame('pending_if_incomplete', $http->requests[1]['params']['payment_behavior']);
        self::assertSame('price_monthly', $http->requests[1]['params']['items'][0]['price']);
        self::assertSame(2, $http->requests[1]['params']['items'][0]['quantity']);
        self::assertSame('value', $http->requests[1]['params']['metadata']['existing']);
        self::assertSame('2026-09-30.endive', $this->headerValue($http->requests[1]['headers'], 'Stripe-Version'));
        self::assertSame('idempotency-key', $this->headerValue($http->requests[1]['headers'], 'Idempotency-Key'));
    }

    public function test_stripe_client_factory_pins_the_configured_api_version_on_requests(): void
    {
        self::assertSame('2026-09-30.endive', config('services.stripe.api_version'));
        $http = new RecordingStripeHttpClient([['id' => 'cus_test_m11', 'object' => 'customer']]);
        ApiRequestor::setHttpClient($http);

        StripeClientFactory::make('sk_test_m11')->customers->retrieve('cus_test_m11', []);

        self::assertSame('2026-09-30.endive', $this->headerValue($http->requests[0]['headers'], 'Stripe-Version'));
    }

    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return (string) $value;
            }
            if (is_string($value) && str_starts_with(strtolower($value), strtolower($name).':')) {
                return trim(substr($value, strlen($name) + 1));
            }
        }

        return null;
    }
}

final class RecordingStripeHttpClient implements ClientInterface
{
    /** @var list<array{method:string,url:string,params:array<string,mixed>,headers:array<mixed>}> */
    public array $requests = [];

    /** @param list<array<string, mixed>> $responses */
    public function __construct(private array $responses) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => strtoupper((string) $method), 'url' => (string) $absUrl, 'params' => (array) $params, 'headers' => (array) $headers];
        $response = array_shift($this->responses);
        if (! is_array($response)) {
            throw new \RuntimeException('Unexpected Stripe API request in test.');
        }

        return [json_encode($response, JSON_THROW_ON_ERROR), 200, ['Request-Id' => 'req_test_schedule']];
    }
}
