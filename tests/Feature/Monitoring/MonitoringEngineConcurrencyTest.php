<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoringAlertDelivery;
use App\Models\MonitoringIncident;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real separate-process row-lock test against an explicitly named local MySQL test DB. */
final class MonitoringEngineConcurrencyTest extends TestCase
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

    public function test_two_simultaneous_first_failures_create_one_incident_and_one_owner_alert(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER, 'email_verified_at' => now()]);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-monitor-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $start = $directory.DIRECTORY_SEPARATOR.'start';
        $worker = $directory.DIRECTORY_SEPARATOR.'worker.php';
        $root = base_path();
        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'APP_BASE_PATH' => $root,
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
        ];
        $workerSource = <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$directory = $argv[2];
$index = (int) $argv[3];
file_put_contents($directory.DIRECTORY_SEPARATOR.'ready-'.$index, '1');
$deadline = microtime(true) + 15;
while (! file_exists($directory.DIRECTORY_SEPARATOR.'start') && microtime(true) < $deadline) {
    usleep(1000);
}
if (! file_exists($directory.DIRECTORY_SEPARATOR.'start')) {
    fwrite(STDERR, 'Monitoring concurrency barrier timed out.');
    exit(1);
}
try {
    $observation = new App\Monitoring\MonitoringObservation(
        'website.http', App\Monitoring\MonitoringScope::Website, 'website', 'synthetic-race-site',
        App\Connector\HealthStatus::Critical, App\Monitoring\MonitoringSeverity::Critical,
        App\Monitoring\MonitoringReason::HTTP_UNREACHABLE, 'active_probe', ['error_category' => 'http.unreachable'],
        now(), 300, 1, 2,
    );
    app(App\Monitoring\MonitoringEngine::class)->record($observation);
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
                $process = new Process([PHP_BINARY, $worker, $root, $directory, (string) $index], $root, $environment);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 15;
            while ((! file_exists($directory.DIRECTORY_SEPARATOR.'ready-0') || ! file_exists($directory.DIRECTORY_SEPARATOR.'ready-1'))
                && microtime(true) < $deadline) {
                usleep(1000);
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
            $this->assertSame(1, MonitoringIncident::query()->where('status', 'open')->count());
            $this->assertSame(1, MonitoringAlertDelivery::query()->where('channel', 'email')->count());
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
