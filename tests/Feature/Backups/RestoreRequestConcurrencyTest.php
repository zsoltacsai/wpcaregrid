<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupType;
use App\Models\BackupRestore;
use App\Models\Website;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesBackups;
use Tests\TestCase;

/** Independent OS processes contend through the supported restore request path. */
class RestoreRequestConcurrencyTest extends TestCase
{
    use CreatesBackups, DatabaseMigrations;

    protected function setUp(): void
    {
        $connection = (string) (getenv('DB_CONNECTION') ?: 'sqlite');
        $database = (string) (getenv('DB_DATABASE') ?: ':memory:');
        $host = strtolower((string) (getenv('DB_HOST') ?: '127.0.0.1'));
        $databaseUrl = (string) (getenv('DB_URL') ?: '');
        $isDedicatedTestDatabase = preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database) === 1;
        $isApprovedCiServiceHost = strtolower((string) (getenv('CI') ?: '')) === 'true'
            && strtolower((string) (getenv('CAREGRID_CI_MYSQL_SERVICE_HOST') ?: '')) === $host
            && $database === 'caregrid_test';

        if (strtolower((string) (getenv('APP_ENV') ?: 'testing')) !== 'testing'
            || $connection !== 'mysql'
            || ! $isDedicatedTestDatabase
            || (! in_array($host, ['localhost', '127.0.0.1', '::1'], true) && ! $isApprovedCiServiceHost)
            || $databaseUrl !== ''
            || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires an explicitly named local MySQL test database and PCNTL.');
        }

        parent::setUp();
        $this->setUpBackups(['pre_update_backup' => true]);
        config(['backup.restore.enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (isset($this->storageRoot)) {
            $this->tearDownBackups();
        }
        parent::tearDown();
    }

    public function test_two_processes_request_the_same_backup_for_one_site(): void
    {
        $this->assertContendersSerializeOnWebsiteLock(false);
    }

    public function test_two_processes_request_different_backups_for_one_site(): void
    {
        $this->assertContendersSerializeOnWebsiteLock(true);
    }

    private function assertContendersSerializeOnWebsiteLock(bool $differentBackups): void
    {
        $site = $this->backupReadySite();
        $first = $this->verifiedBackup($site['website'], BackupType::Full);
        $second = $differentBackups ? $this->verifiedBackup($site['website'], BackupType::Full) : $first;
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-restore-process-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $pids = [];

        try {
            // Hold the same row lock both requests use. Each child starts from
            // an independent Laravel process and blocks in RestoreService::request.
            DB::beginTransaction();
            Website::query()->whereKey($site['website']->id)->lockForUpdate()->firstOrFail();

            foreach ([$first, $second] as $index => $backup) {
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/restore-request-worker.php'),
                    base_path(),
                    $directory,
                    (string) $index,
                    (string) $site['website']->id,
                    (string) $backup->id,
                ], base_path(), $this->childEnvironment());
                $process->setTimeout(40);
                $process->start();
                $pids[] = $process;
            }

            $this->awaitFiles($directory, ['ready-0', 'ready-1']);
            file_put_contents($directory.DIRECTORY_SEPARATOR.'start', 'go', LOCK_EX);
            $this->awaitFiles($directory, ['attempting-0', 'attempting-1']);
            DB::commit();

            foreach ($pids as $process) {
                $process->wait();
            }
            $this->assertSame([true, true], array_map(static fn (Process $process) => $process->isSuccessful(), $pids), implode("\n", array_map(static fn (Process $process) => $process->getErrorOutput(), $pids)));

            $attempts = [
                json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-0'), true, 16, JSON_THROW_ON_ERROR),
                json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1'), true, 16, JSON_THROW_ON_ERROR),
            ];
            $this->assertNotSame($attempts[0]['pid'], $attempts[1]['pid'], 'Contenders are independent OS processes.');
            $this->assertNotSame($attempts[0]['attempt_id'], $attempts[1]['attempt_id'], 'Each contender has an independent request correlation ID.');
            $outcomes = array_column($attempts, 'outcome');
            sort($outcomes);
            $this->assertSame(['accepted', 'refused:RESTORE_IN_PROGRESS'], $outcomes);
            $this->assertSame(1, BackupRestore::query()->where('website_id', $site['website']->id)->count());
            $active = BackupRestore::query()->where('website_id', $site['website']->id)->whereIn('status', ['awaiting_safety_backup', 'queued', 'restoring', 'recovery_queued', 'recovering'])->sole();
            $accepted = collect($attempts)->firstWhere('outcome', 'accepted');
            $this->assertSame($active->uuid, $accepted['restore_uuid']);
            $evidenceDirectory = getenv('WP_CAREGRID_M8_EVIDENCE_DIR');
            if (is_string($evidenceDirectory) && $evidenceDirectory !== '') {
                $realEvidenceDirectory = realpath($evidenceDirectory);
                $this->assertIsString($realEvidenceDirectory);
                $this->assertDirectoryExists($realEvidenceDirectory);
                $evidence = ['variant' => $differentBackups ? 'different_backups_same_site' : 'same_backup_same_site', 'website_uuid' => $site['website']->uuid, 'attempts' => $attempts, 'active_restore_uuid' => $active->uuid, 'active_restore_status' => $active->status->value, 'website_row_lock_released_after_request_transaction' => true];
                $evidencePath = $realEvidenceDirectory.DIRECTORY_SEPARATOR.'laravel-concurrency-'.($differentBackups ? 'different' : 'duplicate').'.json';
                file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
                chmod($evidencePath, 0600);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($pids as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }

    private function childEnvironment(): array
    {
        $mysql = config('database.connections.mysql');

        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $mysql['host'],
            'DB_PORT' => (string) $mysql['port'],
            'DB_DATABASE' => (string) $mysql['database'],
            'DB_USERNAME' => (string) $mysql['username'],
            'DB_PASSWORD' => (string) $mysql['password'],
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'BACKUP_RESTORE_ENABLED' => 'true',
            'COMPATIBILITY_RESTORE_ISOLATION_PROVIDER_IDS' => implode(',', (array) config('compatibility.restore_isolation_provider_ids', [])),
            'BACKUP_STORAGE' => 'local',
            'BACKUP_LOCAL_ROOT' => (string) config('backup.providers.local.root'),
            'BACKUP_STAGING_PATH' => (string) config('backup.upload.staging_path'),
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

        $this->fail('Restore request processes did not reach the deterministic barrier.');
    }
}
