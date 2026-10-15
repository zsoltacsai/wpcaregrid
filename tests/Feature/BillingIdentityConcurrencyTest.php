<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real two-process row-lock test against an explicitly named local MySQL test DB. */
class BillingIdentityConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        // DatabaseMigrations runs during parent::setUp(), so reject anything
        // except an explicitly named local test DB before Laravel can migrate.
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

    public function test_two_simultaneous_processes_allocate_distinct_monotonic_versions(): void
    {
        $customer = Customer::factory()->create();
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-tax-identity-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $start = $directory.DIRECTORY_SEPARATOR.'start';
        $worker = $directory.DIRECTORY_SEPARATOR.'worker.php';
        $root = base_path();
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
        $workerSource = <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$directory = $argv[2];
$index = (int) $argv[3];
file_put_contents($directory.DIRECTORY_SEPARATOR.'ready-'.$index, '1');
$deadline = microtime(true) + 10;
while (! file_exists($directory.DIRECTORY_SEPARATOR.'start') && microtime(true) < $deadline) {
    usleep(1000);
}
if (! file_exists($directory.DIRECTORY_SEPARATOR.'start')) {
    fwrite(STDERR, 'Concurrency barrier timed out.');
    exit(1);
}
try {
    $customer = App\Models\Customer::query()->findOrFail((int) $argv[4]);
    app(App\Tax\CustomerBillingIdentityService::class)->snapshotProfile($customer, [
        'customer_type' => 'individual',
        'legal_name' => 'Parallel Test Customer '.$index,
        'country_code' => 'HU',
        'tax_id' => null,
        'billing_address' => null,
    ]);
    file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, 'ok');
} catch (Throwable $exception) {
    file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, get_class($exception));
    fwrite(STDERR, get_class($exception));
    exit(1);
}
PHP;
        file_put_contents($worker, $workerSource);
        $processes = [];

        try {
            for ($index = 0; $index < 2; $index++) {
                $process = new Process([
                    PHP_BINARY,
                    $worker,
                    $root,
                    $directory,
                    (string) $index,
                    (string) $customer->id,
                ], $root, $environment);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 10;
            while ((! file_exists($directory.DIRECTORY_SEPARATOR.'ready-0') || ! file_exists($directory.DIRECTORY_SEPARATOR.'ready-1'))
                && microtime(true) < $deadline) {
                usleep(1000);
            }
            if (! file_exists($directory.DIRECTORY_SEPARATOR.'ready-0') || ! file_exists($directory.DIRECTORY_SEPARATOR.'ready-1')) {
                $details = array_map(fn (Process $process) => trim($process->getErrorOutput().' '.$process->getOutput()), $processes);
                $this->fail('Concurrency workers failed to reach the barrier: '.implode(' | ', $details));
            }
            $this->assertFileExists($directory.DIRECTORY_SEPARATOR.'ready-0');
            $this->assertFileExists($directory.DIRECTORY_SEPARATOR.'ready-1');
            file_put_contents($start, 'go');

            foreach ($processes as $process) {
                $process->wait();
            }

            $this->assertSame([true, true], array_map(fn (Process $process) => $process->isSuccessful(), $processes));
            $this->assertSame(['ok', 'ok'], [
                file_get_contents($directory.DIRECTORY_SEPARATOR.'result-0'),
                file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1'),
            ]);
            $this->assertSame([1, 2], $customer->billingIdentities()->reorder('version')->pluck('version')->all());
            $this->assertSame(2, $customer->billingIdentities()->count());
        } finally {
            // Release any worker if setup failed before removing its barriers.
            file_put_contents($start, 'stop');
            foreach ($processes as $process) {
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
}
