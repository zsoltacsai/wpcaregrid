<?php

namespace Tests\Feature\Monitoring;

use App\Connector\HealthStatus;
use App\Models\MonitoringResult;
use App\Models\PlatformBackupDelivery;
use App\Models\PlatformDatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
