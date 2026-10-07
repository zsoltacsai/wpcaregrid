<?php

namespace Tests\Feature;

use App\Checkout\CheckoutStatus;
use App\Checkout\PromotionRejected;
use App\Checkout\PromotionReservations;
use App\Models\CheckoutIntent;
use App\Models\Promotion;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
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

    public function test_two_mysql_processes_cannot_reserve_the_same_final_coupon_slot(): void
    {
        if (DB::getDefaultConnection() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for real process-level contention.');
        }

        foreach (config('pricing.prices') as $index => $price) {
            config(["pricing.prices.{$index}.stripe_price_id" => "price_{$price['product_type']}_{$price['plan']}_{$price['interval']}_{$price['currency']}"]);
        }

        $promotion = Promotion::create([
            'code' => 'RACE-ONE',
            'stripe_promotion_code_id' => 'promo_race_one',
            'discount_type' => 'percent',
            'percent_off' => '10.00',
            'max_redemptions' => 1,
        ]);
        $intents = collect(['race-one@example.test', 'race-two@example.test'])->map(fn (string $email) => CheckoutIntent::create([
            'status' => CheckoutStatus::CheckoutCreated,
            'promotion_id' => $promotion->id,
            'promotion_code' => 'RACE-ONE',
            'email' => $email,
            'first_name' => 'Race',
            'last_name' => 'Buyer',
            'country' => 'HU',
            'product_type' => 'wordpress',
            'plan' => 'basic',
            'interval' => 'monthly',
            'market' => 'HU',
            'currency' => 'HUF',
            'amount' => 899000,
            'tax_treatment' => 'final_price_no_vat_added',
            'stripe_price_id' => 'price_wordpress_basic_monthly_HUF',
        ]));
        $price = app(PricingCatalog::class)->resolve(
            new PriceSelection(ProductType::WordPress, PlanTier::Basic, BillingInterval::Monthly),
            'HU',
            'HUF',
        );
        $connection = DB::getDefaultConnection();
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpcg-promotion-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];

        try {
            foreach ($intents as $index => $intent) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Unable to fork a reservation contender.');
                }
                if ($pid === 0) {
                    DB::purge($connection);
                    file_put_contents($directory.DIRECTORY_SEPARATOR."ready-{$index}", 'ready', LOCK_EX);
                    $deadline = microtime(true) + 15;
                    while (! is_file($directory.DIRECTORY_SEPARATOR.'go')) {
                        if (microtime(true) >= $deadline) {
                            file_put_contents($directory.DIRECTORY_SEPARATOR."result-{$index}", 'timeout', LOCK_EX);
                            exit(2);
                        }
                        usleep(10000);
                    }

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
                }
                $children[] = $pid;
            }

            $deadline = microtime(true) + 15;
            while (! is_file($directory.DIRECTORY_SEPARATOR.'ready-0') || ! is_file($directory.DIRECTORY_SEPARATOR.'ready-1')) {
                if (microtime(true) >= $deadline) {
                    $this->fail('Reservation contenders did not reach the barrier.');
                }
                usleep(10000);
            }
            file_put_contents($directory.DIRECTORY_SEPARATOR.'go', 'go', LOCK_EX);

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status), 'A contender did not exit cleanly.');
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $results = [
                file_get_contents($directory.DIRECTORY_SEPARATOR.'result-0'),
                file_get_contents($directory.DIRECTORY_SEPARATOR.'result-1'),
            ];
            sort($results);
            $this->assertSame(['rejected', 'reserved'], $results);
            $this->assertSame(1, DB::table('promotion_reservations')->where('promotion_id', $promotion->id)->where('status', 'reserved')->count());
        } finally {
            foreach ($children as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGTERM);
                    pcntl_waitpid($pid, $status);
                }
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
