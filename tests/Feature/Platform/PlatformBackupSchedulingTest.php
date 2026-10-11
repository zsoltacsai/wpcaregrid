<?php

namespace Tests\Feature\Platform;

use App\Owner\OwnerSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PlatformBackupSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/wpcg-m9-schedule-'.bin2hex(random_bytes(5));
        mkdir($this->root, 0700, true);
        $this->source = $this->root.'/source.sqlite';
        touch($this->source);
        config([
            'platform_backup.source_connection' => 'm9_schedule_source',
            'platform_backup.disk' => 'platform_backups',
            'platform_backup.storage_driver' => 'local',
            'platform_backup.encryption.current_key_id' => 'test-v1',
            'platform_backup.encryption.keys' => ['test-v1' => base64_encode(str_repeat('s', 32))],
            'platform_backup.critical_tables' => ['schedule_probe'],
            'filesystems.disks.platform_backups.driver' => 'local',
            'filesystems.disks.platform_backups.root' => $this->root.'/objects',
            'database.connections.m9_schedule_source' => ['driver' => 'sqlite', 'database' => $this->source, 'prefix' => '', 'foreign_key_constraints' => true],
        ]);
        DB::purge('m9_schedule_source');
        Schema::connection('m9_schedule_source')->create('schedule_probe', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('value');
        });
        DB::connection('m9_schedule_source')->table('schedule_probe')->insert(['id' => 1, 'value' => 'synthetic']);
        Storage::forgetDisk('platform_backups');
    }

    protected function tearDown(): void
    {
        DB::purge('m9_schedule_source');
        Storage::forgetDisk('platform_backups');
        foreach (glob($this->root.'/*') ?: [] as $path) {
            if (is_dir($path)) {
                foreach (glob($path.'/*') ?: [] as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
                @rmdir($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_scheduler_creates_one_backup_for_current_utc_slot_and_does_not_flood_catch_up(): void
    {
        app(OwnerSettings::class)->updateGroup('backups', [
            'backups.primary.enabled' => true,
            'backups.primary.frequency' => '6h',
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ], null);
        $this->travelTo(now('UTC')->startOfDay()->addHours(7));

        $this->artisan('platform:database-backup-schedule')->assertSuccessful();
        $this->artisan('platform:database-backup-schedule')->assertSuccessful();

        $this->assertDatabaseCount('platform_backup_schedule_slots', 1);
        $this->assertDatabaseCount('platform_database_backups', 1);
        $this->assertDatabaseHas('platform_backup_schedule_slots', ['status' => 'completed', 'frequency' => '6h', 'attempts' => 1]);
    }

    public function test_schedule_is_disabled_by_default_and_does_not_create_an_artifact(): void
    {
        config(['platform_backup.enabled' => false]);

        $this->artisan('platform:database-backup-schedule')->assertSuccessful();

        $this->assertDatabaseCount('platform_backup_schedule_slots', 0);
        $this->assertDatabaseCount('platform_database_backups', 0);
    }
}
