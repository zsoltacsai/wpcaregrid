<?php

namespace Tests\Feature;

use App\Billing\SzamlazzBillingSettings;
use App\Invoicing\SyntheticSzamlazzTestWorkflow;
use App\Models\SzamlazzSyntheticTestRun;
use App\Models\User;
use App\Owner\IntegrationCredentials;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

#[Group('billing-concurrency')]
final class SyntheticSzamlazzTestConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');
        parent::tearDown();
    }

    public function test_two_mysql_processes_cannot_reserve_more_than_the_single_synthetic_invoice_slot(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for independent-process contention.');
        }
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://staging.wpcaregrid.com', 'szamlazz.synthetic_test.enabled' => true, 'szamlazz.agent_api_key' => 'synthetic-test-fixture']);
        app(IntegrationCredentials::class)->store('szamlazz_agent', 'synthetic-test-fixture');
        $owner = User::factory()->owner()->create();
        app(SzamlazzBillingSettings::class)->publish([
            'seller_profile_id' => null, 'invoice_language' => 'hu', 'default_payment_method' => 'bank_transfer',
            'payment_deadline_policy' => 'same_day', 'fulfillment_date_policy' => 'invoice_date', 'invoice_prefix' => 'A',
            'notify_customer' => '0', 'currency_policy' => 'block_foreign_without_fx', 'tax_configuration_mode' => 'manual_review',
            'default_tax_code' => null, 'effective_at' => now()->toDateString(),
        ], $owner);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-synthetic-test-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $pids = [];
        $input = ['account_test_mode_confirmed' => '1', 'test_seller_confirmed' => '1', 'tax_code' => 'AAM', 'confirmation_phrase' => 'SYNTHETIC TEST ONLY'];

        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Unable to fork synthetic invoice test contenders.');
                }
                if ($pid === 0) {
                    DB::purge(DB::getDefaultConnection());
                    DB::connection()->getPdo();
                    file_put_contents($directory.DIRECTORY_SEPARATOR.'ready-'.$index, 'ready', LOCK_EX);
                    $deadline = microtime(true) + 20;
                    while (! is_file($directory.DIRECTORY_SEPARATOR.'release')) {
                        if (microtime(true) >= $deadline) {
                            exit(2);
                        }
                        usleep(10000);
                    }
                    try {
                        $run = app(SyntheticSzamlazzTestWorkflow::class)->prepare($input, User::query()->findOrFail($owner->id), 'staging.wpcaregrid.com');
                        file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, 'prepared:'.$run->id, LOCK_EX);
                        exit(0);
                    } catch (ValidationException) {
                        file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, 'rejected', LOCK_EX);
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($directory.DIRECTORY_SEPARATOR.'result-'.$index, 'unexpected:'.get_class($exception), LOCK_EX);
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }

            $deadline = microtime(true) + 20;
            while (! is_file($directory.DIRECTORY_SEPARATOR.'ready-0') || ! is_file($directory.DIRECTORY_SEPARATOR.'ready-1')) {
                if (microtime(true) >= $deadline) {
                    $this->fail('Synthetic invoice contenders did not reach the start barrier.');
                }
                usleep(10000);
            }
            file_put_contents($directory.DIRECTORY_SEPARATOR.'release', 'go', LOCK_EX);
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            DB::purge(DB::getDefaultConnection());
            $results = [file_get_contents($directory.DIRECTORY_SEPARATOR.'result-0'), file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1')];
            sort($results);
            $this->assertSame(1, count(array_filter($results, fn ($value) => str_starts_with($value, 'prepared:'))));
            $this->assertSame(1, count(array_filter($results, fn ($value) => $value === 'rejected')));
            $this->assertSame(1, SzamlazzSyntheticTestRun::query()->count());
        } finally {
            foreach ($pids as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGTERM);
                    pcntl_waitpid($pid, $status);
                }
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($directory);
        }
    }
}
