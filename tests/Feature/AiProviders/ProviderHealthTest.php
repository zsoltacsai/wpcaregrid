<?php

namespace Tests\Feature\AiProviders;

use App\AiProviders\BoundedResponseStream;
use App\AiProviders\GuzzleProviderHealthTransport;
use App\AiProviders\ProviderCredentialState;
use App\AiProviders\ProviderHealthChecker;
use App\AiProviders\ProviderHealthStatus;
use App\AiProviders\ProviderHealthTransport;
use App\AiProviders\ProviderProbeResponse;
use App\AiProviders\ProviderRegistry;
use App\AiProviders\ProviderTransportException;
use App\AiProviders\ResponseLimitExceeded;
use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\User;
use App\Operator\Permission;
use App\Owner\IntegrationCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

final class ProviderHealthTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_transport_uses_only_fixed_bodyless_provider_requests_and_correct_auth_headers(): void
    {
        $cases = [
            'openai' => ['https://api.openai.com/v1/models', 'Authorization', 'fake-openai-key'],
            'anthropic' => ['https://api.anthropic.com/v1/models?limit=1', 'x-api-key', 'fake-anthropic-key'],
            'google_gemini' => ['https://generativelanguage.googleapis.com/v1beta/models?pageSize=1', 'x-goog-api-key', 'fake-gemini-key'],
        ];

        foreach ($cases as $providerId => [$expectedUri, $authHeader, $expectedCredential]) {
            $history = [];
            $requestOptions = [];
            $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"data":[]}')]));
            $stack->push(Middleware::history($history));
            $stack->push(function (callable $handler) use (&$requestOptions): callable {
                return function ($request, array $options) use ($handler, &$requestOptions) {
                    $requestOptions = $options;

                    return $handler($request, $options);
                };
            });
            $transport = new GuzzleProviderHealthTransport(new Client(['handler' => $stack]), app(ProviderRegistry::class));

            $transport->get($providerId, $expectedCredential);

            $this->assertCount(1, $history);
            $request = $history[0]['request'];
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame($expectedUri, (string) $request->getUri());
            $this->assertSame('', (string) $request->getBody());
            $expectedAuthValue = $authHeader === 'Authorization' ? 'Bearer '.$expectedCredential : $expectedCredential;
            $this->assertSame($expectedAuthValue, $request->getHeaderLine($authHeader));
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));
            $this->assertSame('identity', $request->getHeaderLine('Accept-Encoding'));
            $this->assertSame(false, $requestOptions['allow_redirects']);
            $this->assertSame(3, $requestOptions['connect_timeout']);
            $this->assertSame(8, $requestOptions['timeout']);
            $this->assertSame(false, $requestOptions['stream']);
            $this->assertInstanceOf(BoundedResponseStream::class, $requestOptions['sink']);
            $this->assertSame(true, $requestOptions['verify']);
            $this->assertSame('', $requestOptions['proxy']);
            foreach ($cases as $otherProviderId => [, $otherAuthHeader]) {
                if ($otherProviderId !== $providerId && $otherAuthHeader !== $authHeader) {
                    $this->assertSame('', $request->getHeaderLine($otherAuthHeader));
                }
            }
        }
    }

    public function test_anthropic_probe_uses_required_version_and_does_not_supply_workspace_id(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"data":[]}')]));
        $stack->push(Middleware::history($history));
        $transport = new GuzzleProviderHealthTransport(new Client(['handler' => $stack]), app(ProviderRegistry::class));

        $transport->get('anthropic', 'fake-anthropic-key');

        $request = $history[0]['request'];
        $this->assertSame('2023-06-01', $request->getHeaderLine('anthropic-version'));
        $this->assertSame('', $request->getHeaderLine('anthropic-workspace-id'));
    }

    public function test_streamed_response_cap_is_enforced_before_reading_unbounded_body(): void
    {
        $bytesRead = 0;
        $remainingBytes = GuzzleProviderHealthTransport::MAX_RESPONSE_BYTES + 8192;
        $body = new PumpStream(function (int $length) use (&$bytesRead, &$remainingBytes): ?string {
            if ($remainingBytes === 0) {
                return null;
            }
            $chunkLength = min($length, $remainingBytes);
            $remainingBytes -= $chunkLength;
            $bytesRead += $chunkLength;

            return str_repeat('x', $chunkLength);
        });
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], $body)]));
        $transport = new GuzzleProviderHealthTransport(new Client(['handler' => $stack]), app(ProviderRegistry::class));

        try {
            $transport->get('openai', 'fake-openai-key');
            $this->fail('An oversized response must be stopped while reading the stream.');
        } catch (ProviderTransportException $exception) {
            $this->assertSame('RESPONSE_TOO_LARGE', $exception->errorCategory);
            // Transport handlers may hand cURL one final fixed-size write chunk;
            // the sink itself refuses that chunk before buffering any of it.
            $this->assertLessThanOrEqual(GuzzleProviderHealthTransport::MAX_RESPONSE_BYTES + 8192, $bytesRead);
        }
    }

    public function test_declared_oversized_response_is_rejected_without_reading_body(): void
    {
        $bytesRead = 0;
        $body = new PumpStream(function (int $length) use (&$bytesRead): string {
            $bytesRead += $length;

            return str_repeat('x', $length);
        });
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Length' => '65537'], $body)]));
        $transport = new GuzzleProviderHealthTransport(new Client(['handler' => $stack]), app(ProviderRegistry::class));

        try {
            $transport->get('openai', 'fake-openai-key');
            $this->fail('Declared response size above the cap must be rejected.');
        } catch (ProviderTransportException $exception) {
            $this->assertSame('RESPONSE_TOO_LARGE', $exception->errorCategory);
            $this->assertSame(0, $bytesRead);
        }
    }

    public function test_guzzle_sink_rejects_oversized_body_during_transport_write(): void
    {
        $sink = new BoundedResponseStream(GuzzleProviderHealthTransport::MAX_RESPONSE_BYTES);
        $this->assertSame(65536, $sink->write(str_repeat('x', 65536)));

        try {
            $sink->write('x');
            $this->fail('The sink must reject bytes beyond the response cap.');
        } catch (ResponseLimitExceeded) {
            $this->assertSame(65536, $sink->getSize());
        }
    }

    public function test_explicit_probe_normalizes_provider_statuses_and_keeps_configuration_independent(): void
    {
        $this->asOwner();
        $cases = [
            [200, '{"data":[]}', ProviderHealthStatus::Healthy, null],
            [401, '{"error":"discard this raw response"}', ProviderHealthStatus::AuthFailed, 'AUTH_REJECTED'],
            [403, '{"error":"account detail not persisted"}', ProviderHealthStatus::Misconfigured, 'PERMISSION_OR_CONFIGURATION'],
            [429, '{}', ProviderHealthStatus::RateLimited, 'RATE_LIMITED'],
            [503, '{}', ProviderHealthStatus::Unavailable, 'PROVIDER_UNAVAILABLE'],
            [302, '{}', ProviderHealthStatus::Misconfigured, 'REDIRECT_REJECTED'],
            [200, '<html>not json</html>', ProviderHealthStatus::Error, 'UNEXPECTED_RESPONSE'],
            [200, '{"unexpected":[]}', ProviderHealthStatus::Error, 'UNEXPECTED_RESPONSE'],
        ];

        foreach ($cases as [$httpStatus, $body, $expectedStatus, $expectedError]) {
            $this->storeCredential('openai', 'fake-openai-health-key');
            $fake = new FakeProviderHealthTransport(fn () => new ProviderProbeResponse($httpStatus, $body, 21));
            $this->app->instance(ProviderHealthTransport::class, $fake);

            $result = app(ProviderHealthChecker::class)->check('openai');

            $this->assertSame($expectedStatus, $result->status);
            $this->assertSame($expectedError, $result->errorCategory);
            $this->assertTrue($result->persisted);
            $this->assertTrue(app(ProviderCredentialState::class)->state('openai')['configured']);
            $stored = DB::table('ai_provider_statuses')->where('provider_id', 'openai')->sole();
            $this->assertSame($expectedStatus->value, $stored->health_status);
            $this->assertSame($result->generation, $stored->health_generation);
            $this->assertSame(1000, (int) $stored->latency_bucket_ms);
            $this->assertStringNotContainsString('discard this raw response', json_encode($stored));
            DB::table('integration_credentials')->where('id', 'ai_openai_api_key')->delete();
            DB::table('ai_provider_statuses')->where('provider_id', 'openai')->delete();
        }
    }

    public function test_gemini_response_envelope_is_provider_specific_and_no_model_data_is_stored(): void
    {
        $this->asOwner();
        $this->storeCredential('google_gemini', 'fake-gemini-health-key');
        $fake = new FakeProviderHealthTransport(fn () => new ProviderProbeResponse(200, '{"models":[{"name":"model-sentinel"}]}', 4));
        $this->app->instance(ProviderHealthTransport::class, $fake);

        $result = app(ProviderHealthChecker::class)->check('google_gemini');

        $this->assertSame(ProviderHealthStatus::Healthy, $result->status);
        $this->assertStringNotContainsString('model-sentinel', json_encode(DB::table('ai_provider_statuses')->where('provider_id', 'google_gemini')->sole()));
    }

    public function test_zero_customer_data_egress_and_cross_provider_credential_isolation(): void
    {
        $this->asOwner();
        $sentinels = [
            'customer-id-sentinel', 'customer-email-sentinel@example.test', 'website-id-sentinel',
            'website-uuid-sentinel', 'domain-sentinel.example.test', 'subscription-id-sentinel',
            'backup-id-sentinel', 'security-finding-sentinel', 'plugin-name-sentinel', 'content-marker-sentinel',
        ];
        $credentials = [
            'openai' => 'fake-openai-only-key',
            'anthropic' => 'fake-anthropic-only-key',
            'google_gemini' => 'fake-gemini-only-key',
        ];
        foreach ($credentials as $providerId => $secret) {
            $this->storeCredential($providerId, $secret);
        }

        foreach ($credentials as $selectedProvider => $selectedSecret) {
            $fake = new FakeProviderHealthTransport(function (string $providerId, string $credential) use ($sentinels, $selectedSecret): ProviderProbeResponse {
                $this->assertSame($selectedSecret, $credential);
                foreach ($sentinels as $sentinel) {
                    $this->assertStringNotContainsString($sentinel, $providerId.$credential);
                }

                return new ProviderProbeResponse(200, $providerId === 'google_gemini' ? '{"models":[]}' : '{"data":[]}', 1);
            });
            $this->app->instance(ProviderHealthTransport::class, $fake);

            $result = app(ProviderHealthChecker::class)->check($selectedProvider);
            $this->assertTrue($result->persisted);
            $this->assertSame([[$selectedProvider, $selectedSecret]], $fake->calls);
        }
    }

    public function test_generation_cas_discards_late_health_result_after_replace_and_remove(): void
    {
        $this->asOwner();
        $state = app(ProviderCredentialState::class);
        $credentials = app(IntegrationCredentials::class);
        $this->storeCredential('openai', 'fake-generation-a-key');
        $initialGeneration = $state->generation('openai');

        $fake = new FakeProviderHealthTransport(function () use ($state, $credentials): ProviderProbeResponse {
            DB::transaction(function () use ($state, $credentials): void {
                $credentials->store('ai_openai_api_key', 'fake-generation-b-key');
                $state->rotate('openai', true);
            });

            return new ProviderProbeResponse(200, '{"data":[]}', 2);
        });
        $this->app->instance(ProviderHealthTransport::class, $fake);
        $late = app(ProviderHealthChecker::class)->check('openai');
        $this->assertFalse($late->persisted);
        $this->assertSame(ProviderHealthStatus::Unknown, $late->status);
        $this->assertNotSame($initialGeneration, $state->generation('openai'));
        $this->assertSame(ProviderHealthStatus::Unknown->value, $state->state('openai')['health_status']);

        $generationB = $state->generation('openai');
        $removeRace = new FakeProviderHealthTransport(function () use ($state, $credentials): ProviderProbeResponse {
            DB::transaction(function () use ($state, $credentials): void {
                $credentials->clear('ai_openai_api_key');
                $state->rotate('openai', false);
            });

            return new ProviderProbeResponse(200, '{"data":[]}', 2);
        });
        $this->app->instance(ProviderHealthTransport::class, $removeRace);
        $lateAfterRemove = app(ProviderHealthChecker::class)->check('openai');
        $this->assertFalse($lateAfterRemove->persisted);
        $this->assertNotSame($generationB, $state->generation('openai'));
        $this->assertFalse($state->state('openai')['configured']);
        $this->assertSame(ProviderHealthStatus::NotConfigured->value, $state->state('openai')['health_status']);
    }

    public function test_decryption_failure_fails_closed_without_provider_request_or_secret_exposure(): void
    {
        $this->asOwner();
        $state = app(ProviderCredentialState::class);
        DB::transaction(function () use ($state): void {
            DB::table('integration_credentials')->insert(['id' => 'ai_openai_api_key', 'value' => 'ciphertext-sentinel-unreadable', 'created_at' => now(), 'updated_at' => now()]);
            $state->rotate('openai', true);
        });
        $fake = new FakeProviderHealthTransport(fn () => new ProviderProbeResponse(200, '{"data":[]}', 1));
        $this->app->instance(ProviderHealthTransport::class, $fake);
        Log::spy();

        $result = app(ProviderHealthChecker::class)->check('openai');

        $this->assertSame(ProviderHealthStatus::Error, $result->status);
        $this->assertSame('CREDENTIAL_UNAVAILABLE', $result->errorCategory);
        $this->assertSame([], $fake->calls);
        $this->assertDatabaseHas('integration_credentials', ['id' => 'ai_openai_api_key', 'value' => 'ciphertext-sentinel-unreadable']);
        $this->assertStringNotContainsString('ciphertext-sentinel-unreadable', json_encode($result));
        Log::shouldNotHaveReceived('info');
    }

    public function test_explicit_health_route_is_owner_only_and_uses_safe_audit(): void
    {
        $owner = $this->asOwner();
        $this->storeCredential('openai', 'fake-owner-only-key');
        $fake = new FakeProviderHealthTransport(fn () => new ProviderProbeResponse(200, '{"data":[]}', 5));
        $this->app->instance(ProviderHealthTransport::class, $fake);

        $this->post(route('owner.integrations.ai.health', 'openai'))->assertRedirect(route('owner.integrations.index'));
        $this->assertCount(1, $fake->calls);
        $audit = AdminAuditEvent::where('event', 'ai_provider.health_checked')->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame('openai', $audit->data['provider_id']);
        $this->assertSame('HEALTHY', $audit->data['status']);
        $this->assertSame(app(ProviderCredentialState::class)->generation('openai'), $audit->data['generation']);
        $this->assertStringNotContainsString('fake-owner-only-key', json_encode($audit->toArray()));
    }

    public function test_customer_operator_guest_and_unknown_provider_cannot_probe(): void
    {
        $this->storeCredential('openai', 'fake-denied-key');
        $fake = new FakeProviderHealthTransport(fn () => new ProviderProbeResponse(200, '{"data":[]}', 1));
        $this->app->instance(ProviderHealthTransport::class, $fake);
        $customer = Customer::factory()->withUser()->create()->user;
        $operator = User::factory()->operator(Permission::cases())->create();

        foreach ([$customer, $operator] as $user) {
            $this->actingAs($user, 'owner');
            $this->post('/owner/integrations/ai/openai/health')->assertRedirect(route('owner.login'));
            auth('owner')->logout();
        }
        $this->post('/owner/integrations/ai/openai/health')->assertRedirect(route('owner.login'));
        $this->asOwner();
        $this->post('/owner/integrations/ai/custom/health')->assertNotFound();

        $this->assertSame([], $fake->calls);
        $this->assertDatabaseMissing('admin_audit_events', ['event' => 'ai_provider.health_checked']);
    }

    public function test_health_route_is_csrf_protected_and_rate_limited(): void
    {
        $this->asOwner();
        $this->storeCredential('openai', 'fake-rate-limit-key');
        $fake = new FakeProviderHealthTransport(fn () => new ProviderProbeResponse(200, '{"data":[]}', 1));
        $this->app->instance(ProviderHealthTransport::class, $fake);

        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/owner/integrations/ai/openai/health')->assertStatus(419);
        $this->assertSame([], $fake->calls);

        $this->withSession(['_token' => 'csrf-test'])->withHeader('X-CSRF-TOKEN', 'csrf-test');
        $this->post('/owner/integrations/ai/openai/health')->assertRedirect();
        $this->post('/owner/integrations/ai/openai/health')->assertRedirect();
        $this->post('/owner/integrations/ai/openai/health')->assertStatus(429);
        $this->assertCount(2, $fake->calls);
    }

    public function test_health_becomes_informationally_stale_after_seven_days_without_auto_probe(): void
    {
        $this->storeCredential('openai', 'fake-stale-health-key');
        $state = app(ProviderCredentialState::class);
        $generation = $state->generation('openai');
        $this->assertTrue($state->recordHealth('openai', $generation, ProviderHealthStatus::Healthy, null, 25));
        $this->travel(8)->days();

        $current = $state->state('openai');

        $this->assertSame(ProviderHealthStatus::Unknown->value, $current['health_status']);
        $this->assertNotNull($current['checked_at']);
        $this->assertDatabaseHas('ai_provider_statuses', ['provider_id' => 'openai', 'health_status' => 'HEALTHY']);
    }

    public function test_status_domain_contains_no_enablement_default_or_feature_authority(): void
    {
        $registry = app(ProviderRegistry::class);
        $this->assertSame(['openai', 'anthropic', 'google_gemini'], array_keys($registry->all()));
        $this->assertNull($registry->defaultProvider());
        $columns = Schema::getColumnListing('ai_provider_statuses');
        foreach (['enabled', 'default_provider', 'authorized_for_feature', 'secret_fingerprint', 'model_list', 'account_email'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
        $this->assertSame(0, DB::table('ai_provider_statuses')->count());
    }

    private function storeCredential(string $providerId, string $secret): void
    {
        $credentialId = app(ProviderRegistry::class)->get($providerId)['credential_id'];
        $state = app(ProviderCredentialState::class);
        DB::transaction(function () use ($credentialId, $providerId, $secret, $state): void {
            app(IntegrationCredentials::class)->store($credentialId, $secret);
            $state->rotate($providerId, true);
        });
    }
}

final class FakeProviderHealthTransport implements ProviderHealthTransport
{
    public array $calls = [];

    public function __construct(private readonly \Closure $response) {}

    public function get(string $providerId, string $credential): ProviderProbeResponse
    {
        $this->calls[] = [$providerId, $credential];

        return ($this->response)($providerId, $credential);
    }
}
