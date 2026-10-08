<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Tax\CustomerBillingIdentityService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Two simultaneous worker processes must share one pending case per identity. */
class ViesReviewConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        $connection = (string) (getenv('DB_CONNECTION') ?: 'sqlite');
        $database = (string) (getenv('DB_DATABASE') ?: ':memory:');
        $host = strtolower((string) (getenv('DB_HOST') ?: '127.0.0.1'));
        $databaseUrl = (string) (getenv('DB_URL') ?: '');
        $isDedicatedTestDatabase = preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database) === 1;

        if (strtolower((string) (getenv('APP_ENV') ?: 'testing')) !== 'testing'
            || $connection !== 'mysql'
            || ! $isDedicatedTestDatabase
            || ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || $databaseUrl !== '') {
            $this->markTestSkipped('Requires an explicitly named local MySQL test database; migrations were not run.');
        }

        parent::setUp();
    }

    public function test_two_simultaneous_vies_failures_create_one_pending_review_case(): void
    {
        $customer = Customer::factory()->create(['customer_type' => 'company', 'company_name' => 'Concurrency Example Ltd']);
        $identity = app(CustomerBillingIdentityService::class)->snapshotProfile($customer, [
            'customer_type' => 'company',
            'company_name' => $customer->company_name,
            'legal_name' => $customer->company_name,
            'country_code' => 'HU',
            'tax_id' => 'HU12345678',
            'billing_address' => null,
        ]);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-vies-review-'.bin2hex(random_bytes(8));
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
while (! file_exists($directory.DIRECTORY_SEPARATOR.'start') && microtime(true) < $deadline) { usleep(1000); }
if (! file_exists($directory.DIRECTORY_SEPARATOR.'start')) { fwrite(STDERR, 'barrier timeout'); exit(1); }
try {
    $identity = App\Models\CustomerBillingIdentity::query()->findOrFail((int) $argv[4]);
    $case = app(App\Tax\TaxVerificationWorkflow::class)->open($identity, App\Tax\VerificationStatus::PendingVerification, 'vies_unavailable');
    file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, (string) $case->id);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()); exit(1);
}
PHP;
        file_put_contents($worker, $workerSource);
        $processes = [];

        try {
            for ($index = 0; $index < 2; $index++) {
                $process = new Process([PHP_BINARY, $worker, $root, $directory, (string) $index, (string) $identity->id], $root, $environment);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 10;
            while ((! file_exists($directory.DIRECTORY_SEPARATOR.'ready-0') || ! file_exists($directory.DIRECTORY_SEPARATOR.'ready-1')) && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFileExists($directory.DIRECTORY_SEPARATOR.'ready-0');
            $this->assertFileExists($directory.DIRECTORY_SEPARATOR.'ready-1');
            file_put_contents($start, 'go');
            foreach ($processes as $process) {
                $process->wait();
            }

            $this->assertSame([true, true], array_map(fn (Process $process) => $process->isSuccessful(), $processes));
            $this->assertSame(file_get_contents($directory.DIRECTORY_SEPARATOR.'result-0'), file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1'));
            $this->assertSame(1, $identity->verifications()->whereNull('resolved_at')->count());
        } finally {
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
