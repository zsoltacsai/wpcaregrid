<?php

namespace Tests\Feature\Platform;

use App\Platform\DisasterRecovery\PlatformDatabaseBackupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformDatabaseBackupMysqlTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_dump_is_imported_into_a_generated_disposable_schema_and_sanity_checked(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the isolated MySQL 8.4 CI service.');
        }
        if (! filled(getenv('M9_MYSQL_VERIFY_PASSWORD'))) {
            $this->markTestSkipped('Requires the CI-only MySQL root credential for disposable import verification.');
        }

        $mysql = config('database.connections.mysql');
        config([
            'database.connections.m9_platform_source' => $mysql,
            'database.connections.m9_platform_verify' => array_merge($mysql, ['username' => 'root', 'password' => getenv('M9_MYSQL_VERIFY_PASSWORD')]),
            'platform_backup.enabled' => true,
            'platform_backup.source_connection' => 'm9_platform_source',
            'platform_backup.verification_connection' => 'm9_platform_verify',
            'platform_backup.verification_database_prefix' => 'wpcg_m9_dr_ci_',
            'platform_backup.verification_isolated' => true,
            'platform_backup.critical_tables' => array_merge(config('platform_backup.critical_tables'), ['m9_recovery_probe']),
            'platform_backup.encryption.current_key_id' => 'test-v1',
            'platform_backup.encryption.keys' => ['test-v1' => base64_encode(str_repeat('k', 32))],
            'platform_backup.disk' => 'platform_backups',
            'filesystems.disks.platform_backups.driver' => 'local',
            'filesystems.disks.platform_backups.root' => sys_get_temp_dir().'/wpcg-m9-mysql-objects-'.bin2hex(random_bytes(6)),
        ]);
        DB::purge('m9_platform_source');
        DB::purge('m9_platform_verify');
        Storage::forgetDisk('platform_backups');

        $probe = 'm9_recovery_probe';
        Schema::connection('m9_platform_source')->dropIfExists($probe);
        Schema::connection('m9_platform_source')->create($probe, function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('record_key')->unique();
        });
        DB::connection('m9_platform_source')->table($probe)->insert([
            ['id' => 1, 'record_key' => 'site-snapshot'],
            ['id' => 2, 'record_key' => 'audit-snapshot'],
        ]);

        try {
            $service = app(PlatformDatabaseBackupService::class);
            $backup = $service->create('daily');
            $this->assertTrue($service->verify($backup), 'Verification failed: '.($backup->fresh()?->failure_code ?? 'unknown'));
            $this->assertSame('verified', $backup->fresh()->status);
            $this->assertSame('stored-object-sha256-gzip-and-disposable-mysql-import', $backup->fresh()->verification_method);
            $this->assertContains($probe, $backup->manifest['table_manifest']['tables']);
            $this->assertSame(2, $backup->manifest['table_manifest']['critical_counts'][$probe]);
        } finally {
            Schema::connection('m9_platform_source')->dropIfExists($probe);
            DB::purge('m9_platform_source');
            DB::purge('m9_platform_verify');
            $root = config('filesystems.disks.platform_backups.root');
            if (is_dir($root)) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
                }
            }
            @rmdir($root);
        }
    }
}
