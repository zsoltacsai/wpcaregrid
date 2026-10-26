<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupType;
use App\Models\BackupDownloadGrant;
use App\Models\Website;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesBackups;
use Tests\TestCase;

/** Independent MySQL processes prove grant creation serializes on website scope. */
class CustomerBackupDownloadConcurrencyTest extends TestCase
{
    use CreatesBackups, DatabaseMigrations;

    protected function setUp(): void
    {
        $connection = (string) (getenv('DB_CONNECTION') ?: 'sqlite');
        $database = (string) (getenv('DB_DATABASE') ?: ':memory:');
        $host = strtolower((string) (getenv('DB_HOST') ?: '127.0.0.1'));
        $isDedicated = preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database) === 1;
        $isCiHost = strtolower((string) (getenv('CI') ?: '')) === 'true'
            && strtolower((string) (getenv('CAREGRID_CI_MYSQL_SERVICE_HOST') ?: '')) === $host
            && $database === 'caregrid_test';
        if (strtolower((string) (getenv('APP_ENV') ?: 'testing')) !== 'testing' || $connection !== 'mysql' || ! $isDedicated
            || (! in_array($host, ['localhost', '127.0.0.1', '::1'], true) && ! $isCiHost)
            || (string) (getenv('DB_URL') ?: '') !== '' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires an explicitly named local MySQL test database and PCNTL.');
        }

        parent::setUp();
        $this->setUpBackups();
        config([
            'backup.downloads.enabled' => true,
            'backup.providers.sftp' => ['driver' => 'sftp', 'host' => 'fixture.invalid', 'username' => 'fixture', 'password' => 'fixture', 'host_fingerprint' => 'SHA256:fixture', 'root' => '/fixture'],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->storageRoot)) {
            $this->tearDownBackups();
        }
        parent::tearDown();
    }

    public function test_concurrent_requests_for_same_object_reuse_one_live_grant(): void
    {
        $site = $this->backupReadySite();
        $backup = $this->verifiedBackup($site['website'], BackupType::Full);
        $backup->forceFill(['storage_provider' => 'sftp'])->save();
        $object = $backup->objects()->where('name', 'files')->firstOrFail();
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-backup-download-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $processes = [];

        try {
            DB::beginTransaction();
            Website::query()->whereKey($site['website']->id)->lockForUpdate()->firstOrFail();
            foreach ([0, 1] as $index) {
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/customer-backup-grant-worker.php'),
                    base_path(), $directory, (string) $index,
                    (string) $site['website']->customer->user_id,
                    (string) $site['website']->id,
                    (string) $backup->id,
                    (string) $object->id,
                ], base_path(), $this->childEnvironment());
                $process->setTimeout(40);
                $process->start();
                $processes[] = $process;
            }
            $this->awaitFiles($directory, ['ready-0', 'ready-1']);
            file_put_contents($directory.DIRECTORY_SEPARATOR.'start', 'go', LOCK_EX);
            $this->awaitFiles($directory, ['attempting-0', 'attempting-1']);
            DB::commit();
            foreach ($processes as $process) {
                $process->wait();
            }

            $this->assertSame([true, true], array_map(static fn (Process $process) => $process->isSuccessful(), $processes), implode("\n", array_map(static fn (Process $process) => $process->getErrorOutput(), $processes)));
            $results = [
                json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-0'), true, 8, JSON_THROW_ON_ERROR),
                json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1'), true, 8, JSON_THROW_ON_ERROR),
            ];
            $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
            $this->assertSame($results[0]['grant_id'], $results[1]['grant_id']);
            $this->assertSame(1, BackupDownloadGrant::query()->where('backup_object_id', $object->id)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function childEnvironment(): array
    {
        $mysql = config('database.connections.mysql');
        return [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) $mysql['host'], 'DB_PORT' => (string) $mysql['port'],
            'DB_DATABASE' => (string) $mysql['database'], 'DB_USERNAME' => (string) $mysql['username'], 'DB_PASSWORD' => (string) $mysql['password'], 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'BACKUP_CUSTOMER_DOWNLOADS_ENABLED' => 'true', 'BACKUP_SFTP_HOST' => 'fixture.invalid', 'BACKUP_SFTP_USERNAME' => 'fixture',
            'BACKUP_SFTP_PASSWORD' => 'fixture', 'BACKUP_SFTP_HOST_FINGERPRINT' => 'SHA256:fixture', 'BACKUP_SFTP_ROOT' => '/fixture',
            'BACKUP_STORAGE' => 'sftp', 'BACKUP_STAGING_PATH' => (string) config('backup.upload.staging_path'),
        ];
    }

    private function awaitFiles(string $directory, array $names): void
    {
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            if (count(array_filter($names, static fn (string $name) => is_file($directory.DIRECTORY_SEPARATOR.$name))) === count($names)) {
                return;
            }
            usleep(1000);
        }
        $this->fail('Customer download grant processes did not reach the deterministic barrier.');
    }
}
