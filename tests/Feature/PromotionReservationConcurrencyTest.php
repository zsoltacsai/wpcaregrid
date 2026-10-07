<?php

namespace Tests\Feature;

use App\Checkout\CheckoutStatus;
use App\Checkout\PromotionRejected;
use App\Checkout\PromotionReservations;
use App\Models\CheckoutIntent;
use App\Models\Promotion;
use App\Models\PromotionReservation;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use App\Pricing\Price;
use App\Pricing\PriceSelection;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('billing-concurrency')]
class PromotionReservationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (config('pricing.prices') as $index => $price) {
            config(["pricing.prices.{$index}.stripe_price_id" => "price_{$price['product_type']}_{$price['plan']}_{$price['interval']}_{$price['currency']}"]);
        }
    }

    public function test_two_mysql_processes_cannot_reserve_the_same_final_coupon_slot(): void
    {
        $this->assertConcurrentCapacity(maxRedemptions: 1, contenders: 2);
    }

    public function test_two_mysql_processes_respect_a_two_redemption_limit(): void
    {
        $this->assertConcurrentCapacity(maxRedemptions: 2, contenders: 3);
    }

    public function test_two_mysql_processes_include_consumed_usage_in_capacity(): void
    {
        $this->assertConcurrentCapacity(maxRedemptions: 2, contenders: 2, consumed: 1);
    }

    public function test_capacity_uses_a_current_read_after_waiting_for_the_promotion_lock(): void
    {
        if (DB::getDefaultConnection() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for real process-level contention.');
        }

        [$promotion, $intents, $price] = $this->case(maxRedemptions: 1, contenders: 2);
        $connection = DB::getDefaultConnection();
        $directory = $this->barrierDirectory();
        $children = [];

        try {
            $children[] = $this->fork(function () use ($connection, $directory, $promotion, $intents, $price) {
                DB::purge($connection);

                DB::transaction(function () use ($directory, $promotion, $intents, $price) {
                    Promotion::query()->whereKey($promotion->id)->lockForUpdate()->firstOrFail();
                    file_put_contents($directory.DIRECTORY_SEPARATOR.'locked', '1', LOCK_EX);
                    $this->waitFor($directory.DIRECTORY_SEPARATOR.'snapshot', $directory);
                    app(PromotionReservations::class)->reserve($promotion, $intents[0], $price, $intents[0]->email, null);
                });

                file_put_contents($directory.DIRECTORY_SEPARATOR.'committed', '1', LOCK_EX);
                exit(0);
            });

            $children[] = $this->fork(function () use ($connection, $directory, $promotion, $intents, $price) {
                DB::purge($connection);

                try {
                    DB::transaction(function () use ($directory, $promotion, $intents, $price) {
                        // Establish a REPEATABLE READ view before the other process commits.
                        PromotionReservation::query()->where('promotion_id', $promotion->id)->count();
                        file_put_contents($directory.DIRECTORY_SEPARATOR.'snapshot', '1', LOCK_EX);
                        $this->waitFor($directory.DIRECTORY_SEPARATOR.'committed', $directory);
                        app(PromotionReservations::class)->reserve($promotion, $intents[1], $price, $intents[1]->email, null);
                    });
                    $result = 'reserved';
                } catch (PromotionRejected) {
                    $result = 'rejected';
                } catch (\Throwable $exception) {
                    $result = 'error:'.get_class($exception);
                }

                file_put_contents($directory.DIRECTORY_SEPARATOR.'result-1', $result, LOCK_EX);
                exit(str_starts_with($result, 'error:') ? 1 : 0);
            });

            $this->waitFor($directory.DIRECTORY_SEPARATOR.'locked', $directory);
            $this->waitFor($directory.DIRECTORY_SEPARATOR.'result-1', $directory);

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status), 'A contender did not exit cleanly.');
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $this->assertSame('rejected', file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1'));
            $this->assertSame(1, PromotionReservation::query()->where('promotion_id', $promotion->id)->where('status', 'reserved')->count());
        } finally {
            $this->stopChildren($children);
            $this->removeBarrierDirectory($directory);
        }
    }

    /** @return array{Promotion, list<CheckoutIntent>, Price} */
    private function case(int $maxRedemptions, int $contenders): array
    {
        foreach (config('pricing.prices') as $index => $price) {
            config(["pricing.prices.{$index}.stripe_price_id" => "price_{$price['product_type']}_{$price['plan']}_{$price['interval']}_{$price['currency']}"]);
        }

        $promotion = Promotion::create([
            'code' => 'RACE-'.bin2hex(random_bytes(4)),
            'stripe_promotion_code_id' => 'promo_race_'.bin2hex(random_bytes(4)),
            'discount_type' => 'percent',
            'percent_off' => '10.00',
            'max_redemptions' => $maxRedemptions,
        ]);
        $intents = [];

        for ($index = 0; $index < $contenders; $index++) {
            $intents[] = CheckoutIntent::create([
                'status' => CheckoutStatus::CheckoutCreated,
                'promotion_id' => $promotion->id,
                'promotion_code' => $promotion->code,
                'email' => "race-{$index}-".bin2hex(random_bytes(4)).'@example.test',
                'first_name' => 'Race',
                'last_name' => 'Buyer',
                'country' => 'HU',
                'product_type' => 'wordpress',
                'plan' => 'basic',
                'interval' => 'monthly',
                'market' => 'HU',
                'currency' => 'HUF',
                'amount' => 8990,
                'tax_treatment' => 'final_price_no_vat_added',
                'stripe_price_id' => 'price_wordpress_basic_monthly_HUF',
            ]);
        }

        $price = app(PricingCatalog::class)->resolve(
            new PriceSelection(ProductType::WordPress, PlanTier::Basic, BillingInterval::Monthly),
            'HU',
            'HUF',
        );

        return [$promotion, $intents, $price];
    }

    private function assertConcurrentCapacity(int $maxRedemptions, int $contenders, int $consumed = 0): void
    {
        if (DB::getDefaultConnection() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for real process-level contention.');
        }

        [$promotion, $intents, $price] = $this->case($maxRedemptions, $contenders + $consumed);
        $reservations = app(PromotionReservations::class);

        for ($index = 0; $index < $consumed; $index++) {
            $intent = $intents[$index];
            $reservations->reserve($promotion, $intent, $price, $intent->email, null);
            $intent->update(['status' => CheckoutStatus::Completed]);
            $this->assertTrue($reservations->consume($intent));
        }

        $intents = array_slice($intents, $consumed);
        $connection = DB::getDefaultConnection();
        $directory = $this->barrierDirectory();
        $children = [];

        try {
            foreach ($intents as $index => $intent) {
                $children[] = $this->fork(function () use ($connection, $directory, $index, $promotion, $intent, $price) {
                    DB::purge($connection);
                    file_put_contents($directory.DIRECTORY_SEPARATOR."ready-{$index}", '1', LOCK_EX);
                    $this->waitFor($directory.DIRECTORY_SEPARATOR.'go', $directory);

                    try {
                        app(PromotionReservations::class)->reserve($promotion, $intent, $price, $intent->email, null);
                        $result = 'reserved';
                    } catch (PromotionRejected) {
                        $result = 'rejected';
                    } catch (\Throwable $exception) {
                        $result = 'error:'.get_class($exception);
                    }

                    file_put_contents($directory.DIRECTORY_SEPARATOR."result-{$index}", $result, LOCK_EX);
                    exit(str_starts_with($result, 'error:') ? 1 : 0);
                });
            }

            foreach (array_keys($intents) as $index) {
                $this->waitFor($directory.DIRECTORY_SEPARATOR."ready-{$index}", $directory);
            }
            file_put_contents($directory.DIRECTORY_SEPARATOR.'go', '1', LOCK_EX);

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status), 'A contender did not exit cleanly.');
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $results = [];
            foreach (array_keys($intents) as $index) {
                $results[] = file_get_contents($directory.DIRECTORY_SEPARATOR."result-{$index}");
            }

            $expectedReservations = min($contenders, max(0, $maxRedemptions - $consumed));
            $this->assertSame($expectedReservations, count(array_filter($results, fn (string $result) => $result === 'reserved')));
            $this->assertSame($contenders - $expectedReservations, count(array_filter($results, fn (string $result) => $result === 'rejected')));
            $this->assertSame($expectedReservations, PromotionReservation::query()->where('promotion_id', $promotion->id)->where('status', 'reserved')->count());
            $this->assertLessThanOrEqual($maxRedemptions, $promotion->refresh()->times_redeemed + $expectedReservations);
        } finally {
            $this->stopChildren($children);
            $this->removeBarrierDirectory($directory);
        }
    }

    private function fork(callable $callback): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Unable to fork a reservation contender.');
        }
        if ($pid === 0) {
            $callback();
            exit(0);
        }

        return $pid;
    }

    private function barrierDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-promotion-race-'.bin2hex(random_bytes(8));
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
