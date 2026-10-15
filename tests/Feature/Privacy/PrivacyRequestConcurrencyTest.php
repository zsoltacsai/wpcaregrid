<?php

namespace Tests\Feature\Privacy;

use App\Models\Customer;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real process-level duplicate privacy-case creation against dedicated MySQL test DB. */
class PrivacyRequestConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

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
            || $databaseUrl !== '') {
            $this->markTestSkipped('Requires an explicitly named local MySQL test database; migrations were not run.');
        }

        parent::setUp();
    }

    public function test_simultaneous_duplicate_request_creation_produces_one_case_and_one_event(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Requires process support for independent database connections.');
        }

        $customer = Customer::factory()->create();
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-privacy-request-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $worker = $directory.DIRECTORY_SEPARATOR.'worker.php';
        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => (string) config('database.connections.mysql.database'),
            'DB_USERNAME' => (string) config('database.connections.mysql.username'),
            'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'PHPRC' => (string) getenv('PHPRC'),
        ];
        file_put_contents($worker, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$directory = $argv[2];
$index = (int) $argv[3];
file_put_contents($directory.DIRECTORY_SEPARATOR.'ready-'.$index, 'ready', LOCK_EX);
$deadline = microtime(true) + 20;
while (! is_file($directory.DIRECTORY_SEPARATOR.'start') && microtime(true) < $deadline) { usleep(10000); }
if (! is_file($directory.DIRECTORY_SEPARATOR.'start')) { fwrite(STDERR, 'Privacy request race barrier timed out.'); exit(2); }
try {
    Illuminate\Support\Facades\DB::purge('mysql');
    $customer = App\Models\Customer::query()->findOrFail((int) $argv[4]);
    $request = app(App\Privacy\PrivacyRequestService::class)->create(
        $customer,
        App\Privacy\PrivacyRequestType::Access,
        App\Privacy\PrivacyRequestSource::CustomerPortal,
        'privacy-request-concurrent-key-0001',
    );
    file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, $request->uuid, LOCK_EX);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
PHP);
        $processes = [];

        try {
            foreach ([0, 1] as $index) {
                $process = new Process([PHP_BINARY, $worker, base_path(), $directory, (string) $index, (string) $customer->id], base_path(), $environment);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 20;
            while ((! is_file($directory.DIRECTORY_SEPARATOR.'ready-0') || ! is_file($directory.DIRECTORY_SEPARATOR.'ready-1')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($directory.DIRECTORY_SEPARATOR.'ready-0');
            $this->assertFileExists($directory.DIRECTORY_SEPARATOR.'ready-1');
            file_put_contents($directory.DIRECTORY_SEPARATOR.'start', 'go', LOCK_EX);

            foreach ($processes as $process) {
                $process->wait();
            }
            $this->assertSame([true, true], array_map(fn (Process $process) => $process->isSuccessful(), $processes), implode(' | ', array_map(fn (Process $process) => trim($process->getErrorOutput()), $processes)));

            DB::purge('mysql');
            $this->assertSame(1, PrivacyRequest::query()->where('customer_id', $customer->id)->count());
            $this->assertSame(1, PrivacyRequestEvent::query()->count());
            $this->assertSame(file_get_contents($directory.DIRECTORY_SEPARATOR.'result-0'), file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1'));
        } finally {
            file_put_contents($directory.DIRECTORY_SEPARATOR.'start', 'stop', LOCK_EX);
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            DB::purge('mysql');
            $requestId = DB::table('privacy_requests')
                ->where('idempotency_hash', hash('sha256', 'privacy-request-concurrent-key-0001'))
                ->value('id');
            if ($requestId !== null) {
                DB::table('privacy_provider_tasks')->where('privacy_request_id', $requestId)->delete();
                DB::table('privacy_request_events')->where('privacy_request_id', $requestId)->delete();
                DB::table('privacy_requests')->where('id', $requestId)->delete();
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }
}
