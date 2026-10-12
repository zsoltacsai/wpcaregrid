<?php

namespace Tests\Feature\Platform;

use App\Owner\OwnerSettings;
use App\Platform\DisasterRecovery\PlatformBackupCryptography;
use App\Platform\DisasterRecovery\PlatformDatabaseBackupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TestCase;

class PlatformDatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $sourcePath;

    private string $testRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testRoot = sys_get_temp_dir().'/wpcg-m9-backup-tests-'.bin2hex(random_bytes(6));
        mkdir($this->testRoot, 0700, true);
        $this->sourcePath = $this->testRoot.'/source.sqlite';
        touch($this->sourcePath);
        config([
            'platform_backup.enabled' => true,
            'platform_backup.source_connection' => 'm9_source',
            'platform_backup.disk' => 'platform_backups',
            'platform_backup.storage_driver' => 'local',
            'platform_backup.critical_tables' => array_merge(config('platform_backup.critical_tables'), ['recovery_probe']),
            'platform_backup.encryption.current_key_id' => 'test-v1',
            'platform_backup.encryption.keys' => ['test-v1' => base64_encode(str_repeat('k', 32))],
            'filesystems.disks.platform_backups.driver' => 'local',
            'filesystems.disks.platform_backups.root' => $this->testRoot.'/objects',
            'database.connections.m9_source' => [
                'driver' => 'sqlite', 'database' => $this->sourcePath, 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('m9_source');
        Schema::connection('m9_source')->create('recovery_probe', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('record_type');
            $table->string('record_key')->unique();
        });
        DB::connection('m9_source')->table('recovery_probe')->insert([
            ['id' => 1, 'record_type' => 'site', 'record_key' => 'synthetic-site'],
            ['id' => 2, 'record_type' => 'backup', 'record_key' => 'verified-backup'],
        ]);
        Storage::forgetDisk('platform_backups');
    }

    protected function tearDown(): void
    {
        DB::purge('m9_source');
        DB::purge('m9_recovered');
        foreach (glob($this->testRoot.'/*') ?: [] as $path) {
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
        @rmdir($this->testRoot);
        parent::tearDown();
    }

    public function test_platform_database_backup_is_restored_into_a_clean_disposable_database_and_application_connection_reads_it(): void
    {
        $service = app(PlatformDatabaseBackupService::class);
        $backup = $service->create('daily');

        $this->assertSame('stored', $backup->status);
        $this->assertSame('sqlite', $backup->database_driver);
        $this->assertStringEndsWith('.sqlite.gz.enc', $backup->object_key);
        $this->assertGreaterThan(0, $backup->size_bytes);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $backup->sha256);
        $this->assertSame(['recovery_probe'], $backup->manifest['table_manifest']['tables']);
        $externalManifest = json_decode(Storage::disk('platform_backups')->get($backup->manifest_object_key), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($backup->uuid, $externalManifest['backup_uuid']);
        $this->assertSame($backup->sha256, $externalManifest['sha256']);
        $this->assertSame('libsodium-secretstream-xchacha20poly1305', $externalManifest['encryption']['algorithm']);
        $this->assertNotSame('verified', $backup->status);
        $this->assertTrue($service->verify($backup), 'Verification failed: '.($backup->fresh()?->failure_code ?? 'unknown'));
        $this->assertSame('verified', $backup->fresh()->status);
        $this->assertSame('stored-object-sha256-gzip-and-sqlite-quick-check', $backup->fresh()->verification_method);

        $restorePath = $this->sourcePath.'.recovered';
        $stream = Storage::disk('platform_backups')->readStream($backup->object_key);
        $ciphertext = tempnam(sys_get_temp_dir(), 'm9-dr-enc-');
        $target = fopen($ciphertext, 'wb');
        stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);
        $compressed = tempnam(sys_get_temp_dir(), 'm9-dr-gz-');
        app(PlatformBackupCryptography::class)->decryptFile($ciphertext, $compressed, $externalManifest['encryption']);
        $gzip = gzopen($compressed, 'rb');
        $recovered = fopen($restorePath, 'wb');
        while (! gzeof($gzip)) {
            fwrite($recovered, gzread($gzip, 1024 * 1024));
        }
        gzclose($gzip);
        fclose($recovered);
        chmod($restorePath, 0600);
        touch($restorePath);

        config(['database.connections.m9_recovered' => [
            'driver' => 'sqlite', 'database' => $restorePath, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('m9_recovered');
        $this->assertSame(2, DB::connection('m9_recovered')->table('recovery_probe')->count());
        $this->assertSame('synthetic-site', DB::connection('m9_recovered')->table('recovery_probe')->where('id', 1)->value('record_key'));
        $this->assertSame('site', (new PDO('sqlite:'.$restorePath))->query('SELECT record_type FROM recovery_probe WHERE id=1')->fetchColumn());

        DB::purge('m9_source');
        DB::purge('m9_recovered');
        @unlink($restorePath);
        @unlink($compressed);
        @unlink($ciphertext);
    }

    public function test_manual_backup_can_run_while_recurring_schedule_is_disabled(): void
    {
        config(['platform_backup.enabled' => false]);
        app(OwnerSettings::class)->updateGroup('backups', [
            'backups.primary.enabled' => false,
            'backups.primary.frequency' => '12h',
            'backups.retention.daily' => 7,
            'backups.retention.weekly' => 4,
            'backups.retention.monthly' => 12,
            'backups.secondary.enabled' => false,
            'backups.secondary.frequency' => 'daily',
            'backups.app_key_escrow_attested' => false,
        ], null);

        $backup = app(PlatformDatabaseBackupService::class)->createManual('daily');

        $this->assertSame('stored', $backup->status);
        $this->assertFalse(app(OwnerSettings::class)->get('backups.primary.enabled'));
    }

    public function test_automatic_backup_creation_still_refuses_while_recurring_schedule_is_disabled(): void
    {
        config(['platform_backup.enabled' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Platform database backups are disabled.');

        app(PlatformDatabaseBackupService::class)->create('daily');
    }

    public function test_platform_backup_verification_rejects_missing_object_and_hash_mismatch(): void
    {
        $service = app(PlatformDatabaseBackupService::class);
        $missing = $service->create();
        Storage::disk('platform_backups')->delete($missing->object_key);
        $this->assertFalse($service->verify($missing));
        $this->assertSame('verification_failed', $missing->fresh()->status);

        $mismatch = $service->create();
        Storage::disk('platform_backups')->put($mismatch->object_key, 'not-the-original-object');
        $this->assertFalse($service->verify($mismatch));
        $this->assertSame('SIZE_OR_HASH_MISMATCH', $mismatch->fresh()->failure_code);
    }

    public function test_gzip_valid_but_invalid_database_image_is_not_marked_verified(): void
    {
        $service = app(PlatformDatabaseBackupService::class);
        $backup = $service->create();
        $invalidGzip = gzencode('not a sqlite database');
        $plain = tempnam(sys_get_temp_dir(), 'm9-invalid-gz-');
        $cipher = tempnam(sys_get_temp_dir(), 'm9-invalid-enc-');
        file_put_contents($plain, $invalidGzip);
        $encryption = app(PlatformBackupCryptography::class)->encryptFile($plain, $cipher);
        $invalid = file_get_contents($cipher);
        @unlink($plain);
        @unlink($cipher);
        Storage::disk('platform_backups')->put($backup->object_key, $invalid);
        $sha = hash('sha256', $invalid);
        $manifest = json_decode(Storage::disk('platform_backups')->get($backup->manifest_object_key), true, flags: JSON_THROW_ON_ERROR);
        $manifest['size_bytes'] = strlen($invalid);
        $manifest['sha256'] = $sha;
        $manifest['encryption'] = $encryption;
        unset($manifest['manifest_hmac']);
        $manifest['manifest_hmac'] = app(PlatformBackupCryptography::class)->signManifest($manifest, 'test-v1');
        Storage::disk('platform_backups')->put($backup->manifest_object_key, json_encode($manifest, JSON_THROW_ON_ERROR));
        $backup->forceFill(['size_bytes' => strlen($invalid), 'sha256' => $sha, 'manifest' => array_merge($backup->manifest, ['size_bytes' => strlen($invalid), 'sha256' => $sha, 'encryption' => $encryption, 'manifest_hmac' => $manifest['manifest_hmac']])])->save();

        $this->assertFalse($service->verify($backup));
        $this->assertSame('DATABASE_IMPORT_OR_SANITY_FAILED', $backup->fresh()->failure_code);
        $this->assertNotSame('verified', $backup->fresh()->status);
    }

    public function test_encrypted_backup_fails_closed_when_manifest_metadata_is_tampered(): void
    {
        $service = app(PlatformDatabaseBackupService::class);
        $backup = $service->create();
        $manifest = json_decode(Storage::disk('platform_backups')->get($backup->manifest_object_key), true, flags: JSON_THROW_ON_ERROR);
        $manifest['release_sha'] = 'attacker-substituted';
        Storage::disk('platform_backups')->put($backup->manifest_object_key, json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->assertFalse($service->verify($backup));
        $this->assertSame('MANIFEST_AUTHENTICATION_FAILED', $backup->fresh()->failure_code);
        $this->assertNotSame('verified', $backup->fresh()->status);
    }

    public function test_platform_backup_crypto_round_trips_streamed_artifacts(): void
    {
        $source = $this->testRoot.'/crypto-source';
        $encrypted = $this->testRoot.'/crypto-encrypted';
        $decrypted = $this->testRoot.'/crypto-decrypted';
        file_put_contents($source, random_bytes(1024 * 1024 + 17));
        $crypto = app(PlatformBackupCryptography::class);
        $metadata = $crypto->encryptFile($source, $encrypted);
        $crypto->decryptFile($encrypted, $decrypted, $metadata);

        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $decrypted));
        $this->assertSame(0600, fileperms($encrypted) & 0777);
        $this->assertSame(0600, fileperms($decrypted) & 0777);
    }

    public function test_production_local_platform_backup_is_refused(): void
    {
        config([
            'platform.deployment_environment' => 'production',
            'platform_backup.disk' => 'platform_backups',
            'platform_backup.storage_driver' => 'local',
            'filesystems.disks.platform_backups.driver' => 'local',
        ]);

        $this->assertSame('testing', app()->environment(), 'The test must retain the test runtime while simulating a production deployment identity.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('independently managed remote storage');
        app(PlatformDatabaseBackupService::class)->create();
    }

    public function test_staging_deployment_with_production_app_env_refuses_same_failure_domain_storage(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config([
            'platform.deployment_environment' => 'staging',
            'platform_backup.storage_driver' => 'local',
            'filesystems.disks.platform_backups.driver' => 'local',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Deployed platform backups must use an independently managed remote storage disk.');
        app(PlatformDatabaseBackupService::class)->create();
    }

    public function test_s3_platform_backup_refuses_without_dedicated_credentials(): void
    {
        config([
            'platform_backup.storage_driver' => 's3',
            'filesystems.disks.platform_backups.driver' => 's3',
            'filesystems.disks.platform_backups.endpoint' => 'https://fsn1.your-objectstorage.com',
            'filesystems.disks.platform_backups.region' => 'fsn1',
            'filesystems.disks.platform_backups.bucket' => 'platform-private-bucket',
            'filesystems.disks.platform_backups.key' => null,
            'filesystems.disks.platform_backups.secret' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dedicated platform backup S3 credentials are not configured.');
        app(PlatformDatabaseBackupService::class)->create();
    }

    public function test_missing_external_manifest_prevents_verification(): void
    {
        $service = app(PlatformDatabaseBackupService::class);
        $backup = $service->create();
        Storage::disk('platform_backups')->delete($backup->manifest_object_key);

        $this->assertFalse($service->verify($backup));
        $this->assertSame('MANIFEST_OBJECT_MISSING', $backup->fresh()->failure_code);
    }

    public function test_platform_backup_reconciliation_reports_healthy_metadata_and_objects(): void
    {
        $backup = app(PlatformDatabaseBackupService::class)->create();
        $this->assertTrue(app(PlatformDatabaseBackupService::class)->verify($backup), 'Verification failed: '.($backup->fresh()?->failure_code ?? 'unknown'));

        $this->artisan('platform:database-backup-reconcile')
            ->expectsOutputToContain('"state": "healthy"')
            ->assertSuccessful();
    }

    public function test_platform_backup_reconciliation_reports_missing_object_and_orphan_without_deleting(): void
    {
        $backup = app(PlatformDatabaseBackupService::class)->create();
        $this->assertTrue(app(PlatformDatabaseBackupService::class)->verify($backup), 'Verification failed: '.($backup->fresh()?->failure_code ?? 'unknown'));
        Storage::disk('platform_backups')->delete($backup->object_key);
        $orphan = dirname($backup->object_key).'/orphan.sql.gz';
        Storage::disk('platform_backups')->put($orphan, 'orphan evidence');
        $this->assertContains($orphan, Storage::disk('platform_backups')->allFiles(dirname($backup->object_key)));

        $exitCode = Artisan::call('platform:database-backup-reconcile');
        $output = Artisan::output();
        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('metadata_missing_object', $output, $output);
        $this->assertStringContainsString('orphan_object', $output, $output);

        $this->assertTrue(Storage::disk('platform_backups')->exists($orphan));
        $this->assertSame('verified', $backup->fresh()->status);
    }

    public function test_platform_backup_reconciliation_finds_orphans_when_all_database_metadata_is_missing(): void
    {
        $orphan = 'platform-database/2099/01/01/orphan.sql.gz';
        Storage::disk('platform_backups')->put($orphan, 'orphan evidence');

        $exitCode = Artisan::call('platform:database-backup-reconcile');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('orphan_object', $output, $output);
        $this->assertTrue(Storage::disk('platform_backups')->exists($orphan));
    }
}
