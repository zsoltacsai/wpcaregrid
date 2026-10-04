<?php

namespace Tests\Feature\Owner;

use App\Connector\HealthStatus;
use App\Owner\SystemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

/** The system page reports states and never secret values. */
class SystemStatusTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    /** @return array<string, HealthStatus> */
    private function states(): array
    {
        return collect(app(SystemStatus::class)->checks())->mapWithKeys(fn ($check) => [$check->key => $check->status])->all();
    }

    public function test_every_part_is_reported_with_a_state(): void
    {
        $this->asOwner();

        $response = $this->get(route('owner.system'))->assertOk();
        foreach (['Application', 'Database', 'Queue', 'Scheduler', 'Connector API', 'Stripe', 'Backup storage', 'Security scanner', 'Mail'] as $name) {
            $response->assertSee($name);
        }
        $this->assertCount(10, $this->states());
    }

    public function test_secret_values_are_never_shown(): void
    {
        $secrets = [
            'services.stripe.secret' => 'sk_test_SECRET_STRIPE_KEY_123',
            'services.stripe.key' => 'pk_test_PUBLISHABLE_456',
            'services.stripe.webhook_secret' => 'whsec_WEBHOOK_SECRET_789',
            'backup.providers.s3.key' => 'AKIA_STORAGE_KEY',
            'backup.providers.s3.secret' => 'STORAGE_SECRET_VALUE',
            'backup.providers.s3.bucket' => 'private-bucket-name',
            'mail.mailers.smtp.password' => 'SMTP_PASSWORD_VALUE',
            'security.vulnerabilities.feed_key' => 'FEED_KEY_VALUE',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        ];
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
