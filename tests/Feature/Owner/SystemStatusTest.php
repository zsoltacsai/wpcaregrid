<?php

namespace Tests\Feature\Owner;

use App\Connector\HealthStatus;
use App\Owner\SystemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

/** The system page reports states and never secret values. */
class SystemStatusTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cloudflare.api_token' => null]);
    }

    /** @return array<string, HealthStatus> */
    private function states(): array
    {
        return collect(app(SystemStatus::class)->checks())->mapWithKeys(fn ($check) => [$check->key => $check->status])->all();
    }

    public function test_every_part_is_reported_with_a_state(): void
    {
        $this->asOwner();

        $response = $this->get(route('owner.system'))->assertOk();
        foreach (['Application', 'Database', 'Queue', 'Scheduler', 'Connector API', 'Stripe', 'Cloudflare DNS', 'Backup storage', 'Security scanner', 'Mail', 'Privacy restore safety'] as $name) {
            $response->assertSee($name);
        }
        $this->assertCount(14, $this->states());
        $this->assertSame(HealthStatus::Healthy, $this->states()['privacy_retention']);
        $this->assertSame(HealthStatus::Healthy, $this->states()['privacy_exports']);
    }

    public function test_disabled_privacy_retention_gate_is_visible_as_safe_and_enabled_value_requires_attention(): void
    {
        config()->set('privacy.destructive_retention_enabled', null);
        $this->asOwner();
        $this->get(route('owner.system'))->assertOk()
            ->assertSee('Privacy retention safety')
            ->assertSee('SAFE / DISABLED BY POLICY');
        $this->assertSame(HealthStatus::Healthy, $this->states()['privacy_retention']);

        config(['privacy.destructive_retention_enabled' => true]);
        $this->get(route('owner.system'))->assertOk()->assertSee('The destructive retention gate is enabled.');
        $this->assertSame(HealthStatus::Attention, $this->states()['privacy_retention']);

        config(['privacy.destructive_retention_enabled' => 'true']);
        $this->assertSame(HealthStatus::Healthy, $this->states()['privacy_retention']);
    }

    public function test_secret_values_are_never_shown(): void
    {
        $secrets = [
            'services.stripe.secret' => 'sk_test_SECRET_STRIPE_KEY_123',
            'services.stripe.key' => 'pk_test_PUBLISHABLE_456',
            'services.stripe.webhook_secret' => 'whsec_WEBHOOK_SECRET_789',
            'services.cloudflare.api_token' => 'cloudflare_secret_token_123',
            'backup.providers.s3.key' => 'AKIA_STORAGE_KEY',
            'backup.providers.s3.secret' => 'STORAGE_SECRET_VALUE',
            'backup.providers.s3.bucket' => 'private-bucket-name',
            'mail.mailers.smtp.password' => 'SMTP_PASSWORD_VALUE',
            'security.vulnerabilities.feed_key' => 'FEED_KEY_VALUE',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        ];
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/user/tokens/verify')) {
                return Http::response(['success' => true, 'result' => ['status' => 'active']]);
            }
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => '0123456789abcdef0123456789abcdef', 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }

            return Http::response(['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]]);
        });
        config($secrets + ['backup.storage' => 's3', 'mail.default' => 'smtp']);
        $this->asOwner();

        $response = $this->get(route('owner.system'))->assertOk()->assertSee('Configured')->assertSee('Test');
        foreach ($secrets as $value) {
            $response->assertDontSee($value, false);
        }
        $response->assertDontSee('sk_test_', false)->assertDontSee('whsec_', false);
    }

    public function test_missing_configuration_is_not_reported_as_healthy(): void
    {
        config([
            'services.stripe.secret' => null,
            'services.stripe.webhook_secret' => null,
            'backup.storage' => null,
            'mail.default' => 'log',
        ]);

        $states = $this->states();

        $this->assertSame(HealthStatus::Critical, $states['stripe']);
        $this->assertSame(HealthStatus::Critical, $states['backup_storage']);
        $this->assertSame(HealthStatus::Attention, $states['mail']);
        $this->assertSame(HealthStatus::Unknown, $states['scheduler'], 'Never seen running: unknown, not healthy.');
        $this->assertSame(HealthStatus::Unknown, $states['connector_api']);
        $this->assertSame(HealthStatus::Unknown, $states['security_scanner']);
        $this->assertSame(HealthStatus::Healthy, $states['database']);
        $this->assertSame(HealthStatus::Unknown, $states['cloudflare']);
        $cloudflare = collect(app(SystemStatus::class)->checks())->firstWhere('key', 'cloudflare');
        $this->assertSame('NOT CONFIGURED', $cloudflare->level());
        $this->assertTrue($cloudflare->optional);
    }

    public function test_cloudflare_status_checks_are_read_only_and_report_failures_as_warning(): void
    {
        config(['services.cloudflare.api_token' => 'cloudflare-test-token', 'cloudflare.allowed_zones' => ['wpcaregrid.com']]);
        $statusCode = 200;
        Http::fake(function ($request) use (&$statusCode) {
            if ($statusCode === 403) {
                return Http::response(['success' => false], 403);
            }
            if (str_ends_with($request->url(), '/user/tokens/verify')) {
                return Http::response(['success' => true, 'result' => ['status' => 'active']]);
            }
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => '0123456789abcdef0123456789abcdef', 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }

            return Http::response(['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]]);
        });

        $this->assertSame(HealthStatus::Healthy, $this->states()['cloudflare']);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');

        $statusCode = 403;
        $this->assertSame(HealthStatus::Attention, $this->states()['cloudflare']);
    }

    public function test_the_scheduler_heartbeat_decides_the_scheduler_state(): void
    {
        Cache::forever(SystemStatus::SCHEDULER_HEARTBEAT, now()->subMinute()->toIso8601String());
        $this->assertSame(HealthStatus::Healthy, $this->states()['scheduler']);

        Cache::forever(SystemStatus::SCHEDULER_HEARTBEAT, now()->subMinutes(10)->toIso8601String());
        $this->assertSame(HealthStatus::Critical, $this->states()['scheduler']);
    }

    public function test_the_scheduler_records_its_heartbeat(): void
    {
        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertNotNull(Cache::get(SystemStatus::SCHEDULER_HEARTBEAT));
    }

    public function test_debug_mode_in_production_is_critical(): void
    {
        config(['app.debug' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertSame(HealthStatus::Critical, $this->states()['application']);
    }
}
