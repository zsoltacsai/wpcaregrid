<?php

namespace Tests\Feature\Monitoring;

use App\Connector\HealthStatus;
use App\Models\MonitoringAlertDelivery;
use App\Models\MonitoringIncident;
use App\Models\MonitoringResult;
use App\Models\MonitoringTransition;
use App\Models\PlatformBackupDelivery;
use App\Models\PlatformDatabaseBackup;
use App\Models\StripeWebhookInbox;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class MonitoringInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_recovery_health_reflects_primary_verification_and_reconciliation_state(): void
    {
        Queue::fake();
        $backup = PlatformDatabaseBackup::query()->create([
            'source_environment' => 'testing',
            'database_identity_sha256' => str_repeat('b', 64),
            'database_driver' => 'sqlite',
            'retention_class' => 'daily',
            'status' => 'verified',
            'storage_disk' => 'platform-backups',
            'object_key' => 'platform/test/backup.enc',
            'manifest_object_key' => 'platform/test/manifest.json',
            'created_for_backup_at' => now(),
            'verified_at' => now(),
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
        ]);
        PlatformBackupDelivery::query()->create([
            'platform_database_backup_id' => $backup->id,
            'destination_key' => 'primary',
            'destination_type' => 's3',
            'storage_disk' => 'platform-backups',
            'object_key' => 'platform/test/backup.enc',
            'manifest_object_key' => 'platform/test/manifest.json',
            'upload_status' => 'uploaded',
            'verification_status' => 'verified',
            'reconciliation_status' => 'reconciliation_required',
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'uploaded_at' => now(),
            'verified_at' => now(),
        ]);

        $this->artisan('monitoring:run')->assertSuccessful();

        $result = MonitoringResult::query()->where('check_id', 'platform.recovery')->firstOrFail();
        $this->assertSame(HealthStatus::Attention, $result->state);
        $this->assertTrue($result->evidence['primary_verified']);
        $this->assertTrue($result->evidence['reconciliation_required']);
    }

    public function test_stale_monitoring_pipeline_opens_once_and_recovers_after_confirmed_fresh_probes(): void
    {
        Queue::fake();
        User::factory()->create(['role' => User::ROLE_OWNER, 'email_verified_at' => now()]);
        Website::factory()->create();
        Cache::put('monitoring.last_probe_completed_at', now()->subMinutes(20)->toIso8601String(), now()->addHour());

        $this->artisan('monitoring:run')->assertSuccessful();
        $this->artisan('monitoring:run')->assertSuccessful();

        $incident = MonitoringIncident::query()
            ->where('check_id', 'monitoring.pipeline')
            ->where('subject_id', 'probe-worker')
            ->firstOrFail();
        $this->assertSame('open', $incident->status);
        $this->assertSame(1, MonitoringTransition::query()->where('incident_id', $incident->id)->where('event_type', 'opened')->count());
        $this->assertSame(1, MonitoringAlertDelivery::query()->where('incident_id', $incident->id)->where('channel', 'in_app')->count());

        Cache::put('monitoring.last_probe_completed_at', now()->toIso8601String(), now()->addHour());
        $this->artisan('monitoring:run')->assertSuccessful();
        $this->artisan('monitoring:run')->assertSuccessful();

        $this->assertSame('resolved', $incident->refresh()->status);
        $this->assertSame(2, MonitoringTransition::query()->where('incident_id', $incident->id)->count());
        $this->assertSame(2, MonitoringAlertDelivery::query()->where('incident_id', $incident->id)->where('channel', 'in_app')->count());
    }

    public function test_normal_customer_payment_failure_is_not_reported_as_platform_billing_outage(): void
    {
        Queue::fake();
        Subscription::factory()->create(['status' => 'past_due', 'grace_started_at' => now(), 'grace_ends_at' => now()->addDays(7)]);

        $this->artisan('monitoring:run')->assertSuccessful();

        $result = MonitoringResult::query()->where('check_id', 'billing.lifecycle')->firstOrFail();
        $this->assertSame(HealthStatus::Healthy, $result->state);
        $this->assertSame(0, $result->evidence['failed_webhooks']);
    }

    public function test_failed_billing_webhook_is_visible_to_m10_monitoring(): void
    {
        Queue::fake();
        StripeWebhookInbox::query()->create([
            'stripe_event_id' => 'evt_billing_monitor_failed',
            'type' => 'invoice.paid',
            'livemode' => false,
            'payload' => json_encode(['id' => 'evt_billing_monitor_failed'], JSON_THROW_ON_ERROR),
            'status' => 'failed',
            'attempts' => 2,
            'last_error_category' => 'ProviderTimeout',
        ]);

        $this->artisan('monitoring:run')->assertSuccessful();

        $result = MonitoringResult::query()->where('check_id', 'billing.lifecycle')->firstOrFail();
        $this->assertSame(HealthStatus::Attention, $result->state);
        $this->assertSame('billing.webhook_failed', $result->reason_code);
        $this->assertSame(1, $result->evidence['failed_webhooks']);
    }
}
