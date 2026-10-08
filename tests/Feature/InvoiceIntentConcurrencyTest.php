<?php

namespace Tests\Feature;

use App\Invoicing\InvoiceIntentWorkflow;
use App\Models\BillingInvoice;
use App\Models\InvoiceIntent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('billing-concurrency')]
class InvoiceIntentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_independent_mysql_processes_create_only_one_intent_for_the_same_paid_invoice(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for independent-process contention.');
        }

        config(['queue.default' => 'null']);
        $invoice = BillingInvoice::query()->create([
            'stripe_invoice_id' => 'in_concurrent_'.Str::random(18),
            'invoice_status' => 'paid',
            'total' => 80100,
            'currency' => 'EUR',
        ]);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-invoice-intent-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $pids = [];

        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Unable to fork invoice-intent contender.');
                }
                if ($pid === 0) {
                    DB::purge(DB::getDefaultConnection());
                    DB::connection()->getPdo();
                    file_put_contents($directory.DIRECTORY_SEPARATOR."ready-{$index}", 'ready', LOCK_EX);
                    $deadline = microtime(true) + 20;
                    while (! is_file($directory.DIRECTORY_SEPARATOR.'release')) {
                        if (microtime(true) >= $deadline) {
                            exit(2);
                        }
                        usleep(10000);
                    }

                    try {
                        $freshInvoice = BillingInvoice::query()->findOrFail($invoice->id);
                        $intent = app(InvoiceIntentWorkflow::class)->recordPaidInvoice($freshInvoice, 'evt_concurrent_'.$index);
                        file_put_contents($directory.DIRECTORY_SEPARATOR."result-{$index}", (string) $intent?->id, LOCK_EX);
                        exit($intent ? 0 : 3);
                    } catch (\Throwable $exception) {
                        file_put_contents($directory.DIRECTORY_SEPARATOR."result-{$index}", get_class($exception), LOCK_EX);
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }

            $deadline = microtime(true) + 20;
            while (! is_file($directory.DIRECTORY_SEPARATOR.'ready-0') || ! is_file($directory.DIRECTORY_SEPARATOR.'ready-1')) {
                if (microtime(true) >= $deadline) {
                    $this->fail('Invoice-intent contenders did not reach the start barrier.');
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
            $this->assertSame(1, InvoiceIntent::query()->where('stripe_invoice_id', $invoice->stripe_invoice_id)->count());
            $this->assertSame(
                file_get_contents($directory.DIRECTORY_SEPARATOR.'result-0'),
                file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1'),
            );
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
