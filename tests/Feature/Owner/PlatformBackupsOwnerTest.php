<?php

namespace Tests\Feature\Owner;

use App\Jobs\PlatformDatabaseBackupJob;
use App\Models\PlatformDatabaseBackup;
use App\Owner\OwnerSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

final class PlatformBackupsOwnerTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    public function test_owner_backup_overview_reports_configuration_without_exposing_secrets(): void
    {
        config([
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.bucket' => 'platform-private-bucket',
            'filesystems.disks.platform_backups.key' => 'PLATFORM_ACCESS_SECRET_MARKER',
            'filesystems.disks.platform_backups.secret' => 'PLATFORM_SECRET_SECRET_MARKER',
            'platform_backup.encryption.current_key_id' => 'test-v1',
            'platform_backup.encryption.keys.test-v1' => base64_encode(str_repeat('x', 32)),
        ]);
        $this->asOwner();

        $response = $this->get(route('owner.platform-backups.index'))->assertOk()
            ->assertSee('Platform Backups')->assertSee('Stale');

        $response->assertDontSee('PLATFORM_ACCESS_SECRET_MARKER', false)
            ->assertDontSee('PLATFORM_SECRET_SECRET_MARKER', false)
            ->assertDontSee(base64_encode(str_repeat('x', 32)), false);
    }

    public function test_non_owner_cannot_open_platform_backup_management(): void
    {
        $this->customerWithWebsite();

        $this->get(route('owner.platform-backups.index'))->assertRedirect(route('owner.login'));
    }

    public function test_manual_backup_requires_configuration_confirmation_and_uses_unique_queued_job(): void
    {
        Queue::fake();
        config([
            // Simulate a worker/web process whose cached config is stale.
            'platform_backup.enabled' => false,
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.bucket' => 'platform-private-bucket',
            'filesystems.disks.platform_backups.key' => 'test-key',
            'filesystems.disks.platform_backups.secret' => 'test-secret',
            'platform_backup.encryption.current_key_id' => 'test-v1',
            'platform_backup.encryption.keys.test-v1' => base64_encode(str_repeat('x', 32)),
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.queue' => 'default',
        ]);
        $this->asOwner();
        app(OwnerSettings::class)->updateGroup('backups', [
            'backups.primary.enabled' => true,
            'backups.primary.frequency' => '12h',
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ], auth('owner')->user());

        $this->post(route('owner.platform-backups.run'), ['confirmation' => 'wrong'])->assertStatus(422);
        $this->post(route('owner.platform-backups.run'), ['confirmation' => 'RUN PLATFORM BACKUP'])->assertRedirect(route('owner.platform-backups.index'));

        Queue::assertPushed(PlatformDatabaseBackupJob::class, 1);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'platform_backup.manual_requested']);
    }

    public function test_manual_backup_refuses_when_persisted_owner_setting_is_disabled_even_if_config_is_stale_enabled(): void
    {
        Queue::fake();
        config([
            'platform_backup.enabled' => true,
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.bucket' => 'platform-private-bucket',
            'filesystems.disks.platform_backups.key' => 'test-key',
            'filesystems.disks.platform_backups.secret' => 'test-secret',
            'platform_backup.encryption.current_key_id' => 'test-v1',
            'platform_backup.encryption.keys.test-v1' => base64_encode(str_repeat('x', 32)),
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.queue' => 'default',
        ]);
        $this->asOwner();
        app(OwnerSettings::class)->updateGroup('backups', [
            'backups.primary.enabled' => false,
            'backups.primary.frequency' => '12h',
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ], auth('owner')->user());
        $this->assertFalse(app(OwnerSettings::class)->get('backups.primary.enabled'));

        $this->post(route('owner.platform-backups.run'), ['confirmation' => 'RUN PLATFORM BACKUP'])->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_disabling_primary_schedule_requires_confirmation_and_keeps_recovery_points(): void
    {
        $this->asOwner();
        app(OwnerSettings::class)->updateGroup('backups', [
            'backups.primary.enabled' => true,
            'backups.primary.frequency' => '12h',
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ], auth('owner')->user());
        $backup = PlatformDatabaseBackup::query()->create([
            'uuid' => (string) str()->uuid(), 'source_environment' => 'testing', 'database_identity_sha256' => str_repeat('a', 64),
            'database_driver' => 'sqlite', 'retention_class' => 'daily', 'status' => 'verified', 'verification_status' => 'verified',
            'storage_disk' => 'platform_backups', 'object_key' => 'platform-database/test.sqlite.gz.enc',
            'manifest_object_key' => 'platform-database/test.sqlite.gz.enc.manifest.json', 'size_bytes' => 100, 'sha256' => str_repeat('b', 64),
            'created_for_backup_at' => now(), 'verified_at' => now(),
        ]);
        $data = ['settings' => [
            'backups.primary.enabled' => '0', 'backups.primary.frequency' => '12h',
            'backups.retention.daily' => '7', 'backups.retention.weekly' => '4', 'backups.retention.monthly' => '12',
            'backups.secondary.enabled' => '0', 'backups.secondary.frequency' => 'daily', 'backups.app_key_escrow_attested' => '0',
        ]];

        $this->put(route('owner.platform-backups.settings.update'), $data)->assertStatus(422);
        $data['confirmation'] = 'DISABLE PLATFORM BACKUPS';
        $data['warning_ack'] = '1';
        $this->put(route('owner.platform-backups.settings.update'), $data)->assertRedirect(route('owner.platform-backups.index'));

        $this->assertSame('verified', $backup->fresh()->status);
        $this->assertDatabaseHas('admin_audit_events', ['event' => 'platform_backup.disabled']);
    }
}
