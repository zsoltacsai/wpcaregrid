<?php

namespace Tests\Feature;

use App\Billing\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Website;
use App\Pricing\ProductType;
use App\Websites\AssignWebsiteToSubscription;
use App\Websites\WebsitePurchaseTarget;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('billing-concurrency')]
class WebsiteSlotAssignmentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_independent_mysql_processes_cannot_assign_one_subscription_to_two_websites(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for independent-process contention.');
        }

        $customer = Customer::factory()->withUser()->create();
        $subscription = Subscription::factory()->for($customer)->status(SubscriptionStatus::Active)->create([
            'product_type' => 'wordpress',
        ]);
        $connection = DB::getDefaultConnection();
        $directory = $this->barrierDirectory();
        $children = [];

        try {
            foreach (['first', 'second'] as $name) {
                $url = "https://{$name}.race.example";
                $children[] = $this->fork(function () use ($connection, $directory, $name, $url, $customer, $subscription) {
                    DB::purge($connection);
                    file_put_contents($directory.DIRECTORY_SEPARATOR."ready-{$name}", '1', LOCK_EX);
                    $this->waitFor($directory.DIRECTORY_SEPARATOR.'go', $directory);

                    try {
                        $website = (app(AssignWebsiteToSubscription::class))(
                            Customer::query()->findOrFail($customer->id),
                            $subscription->uuid,
                            new WebsitePurchaseTarget($url, ProductType::WordPress),
                        );
                        file_put_contents($directory.DIRECTORY_SEPARATOR."result-{$name}", 'assigned:'.$website->id, LOCK_EX);
                        exit(0);
                    } catch (ValidationException) {
                        file_put_contents($directory.DIRECTORY_SEPARATOR."result-{$name}", 'slot_unavailable', LOCK_EX);
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents($directory.DIRECTORY_SEPARATOR."result-{$name}", 'error:'.$exception::class, LOCK_EX);
                        exit(1);
                    }
                });
            }

            $this->waitFor($directory.DIRECTORY_SEPARATOR.'ready-first', $directory);
            $this->waitFor($directory.DIRECTORY_SEPARATOR.'ready-second', $directory);
            file_put_contents($directory.DIRECTORY_SEPARATOR.'go', '1', LOCK_EX);

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status), 'A website assignment contender did not exit cleanly.');
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $results = [
                file_get_contents($directory.DIRECTORY_SEPARATOR.'result-first'),
                file_get_contents($directory.DIRECTORY_SEPARATOR.'result-second'),
            ];
            $this->assertSame(1, count(array_filter($results, fn (string $result) => str_starts_with($result, 'assigned:'))));
            $this->assertSame(1, count(array_filter($results, fn (string $result) => $result === 'slot_unavailable')));
            $this->assertSame(1, Website::query()->count());
            $this->assertSame($subscription->id, Website::query()->sole()->subscription_id);
        } finally {
            $this->stopChildren($children);
            $this->removeBarrierDirectory($directory);
        }
    }

    private function fork(callable $callback): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Unable to fork a website assignment contender.');
        }
        if ($pid === 0) {
            $callback();
            exit(0);
        }

        return $pid;
    }

    private function barrierDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-website-slot-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        return $directory;
    }

    private function waitFor(string $file, string $directory): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($file)) {
            if (microtime(true) >= $deadline) {
                file_put_contents($directory.DIRECTORY_SEPARATOR.'timeout', '1', LOCK_EX);
                exit(2);
            }
            usleep(10000);
        }
    }

    /** @param  list<int>  $children */
    private function stopChildren(array $children): void
    {
        foreach ($children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }
    }

    private function removeBarrierDirectory(string $directory): void
    {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
